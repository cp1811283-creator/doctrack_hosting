<?php

namespace App\Services;

use App\Models\AdminViolation;
use App\Models\AuditLog;
use App\Models\DocumentAssignment;
use App\Models\NotificationRecord;
use App\Models\SlaOutageWindow;
use App\Models\SlaViolation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * SlaService
 * -----------
 * A missed deadline always resolves the same way now, regardless of WHO
 * was responsible for it — auto-approve immediately, log the violation
 * against whoever actually held the seat, let Admin review it afterward
 * rather than before:
 *
 *   - a real approver missed their own window -> autoApproveApproverMiss()
 *     -> SlaViolation logged against them.
 *   - a stage had no eligible approver at all -> autoApproveNoEligibleApprover()
 *     at routing time -> AdminViolation (missed_approval), opened already
 *     resolved.
 *
 * Either way, the resulting auto-approval owes Admin a review within
 * ADMIN_REVIEW_WINDOW_HOURS — unless a Head Approver's Final Approval still
 * follows, whose sign-off covers it (see WorkflowService::awaitingHeadSignOff()).
 * trackLateReviews() below follows up on a review that goes unreviewed.
 *
 * A missed deadline is normally caught the instant it happens by a delayed
 * queue job (EscalateAssignmentJob); the scheduler's sweeps and the
 * approver's own next action are backups (see bootstrap/app.php).
 */
class SlaService
{
    /**
     * Below this many minutes, a gap in the heartbeat is treated as ordinary
     * scheduler jitter, not a real outage. Must sit comfortably ABOVE the
     * scheduler's own tick interval (sla:check runs every 5 minutes, so a
     * perfectly healthy gap is 5:00-5:02) — at 5 it flagged every single
     * tick as an outage and kept pushing every at-risk deadline forward.
     * Two missed ticks is the smallest gap that actually means something
     * was down.
     */
    public const OUTAGE_DETECTION_FLOOR_MINUTES = 10;

    private const HEARTBEAT_CACHE_KEY = 'sla_heartbeat_last_seen';

    public function __construct(private WorkflowService $workflow, private BusinessHoursService $businessHours) {}

    /**
     * Called from sla:check, which already runs every 5 minutes — stamps
     * "the scheduler ran, as of now" every time, and compares against the
     * PREVIOUS stamp. If that previous stamp is more than
     * OUTAGE_DETECTION_FLOOR_MINUTES old, the system (or the scheduler
     * driving it) wasn't running for that whole gap — inferred proof of
     * an outage, not a guess. Cache, not a DB column, since this is pure
     * liveness bookkeeping with no need to survive a cache flush; a lost
     * heartbeat just means the next tick establishes a fresh baseline
     * instead of (wrongly) reporting years of "downtime".
     */
    public function detectOutage(): ?SlaOutageWindow
    {
        $now = now();
        $lastSeen = Cache::get(self::HEARTBEAT_CACHE_KEY);
        Cache::forever(self::HEARTBEAT_CACHE_KEY, $now->toIso8601String());

        if (! $lastSeen) {
            return null; // first tick ever (or cache was cleared) — nothing to compare against yet
        }

        $lastSeen = Carbon::parse($lastSeen);
        if ($lastSeen->diffInMinutes($now) < self::OUTAGE_DETECTION_FLOOR_MINUTES) {
            return null;
        }

        $minutesLost = $this->businessHours->businessMinutesLostToOutage($lastSeen, $now);
        if ($minutesLost <= 0) {
            return null; // the whole gap fell outside working hours — nobody actually lost review time
        }

        return SlaOutageWindow::create([
            'started_at' => $lastSeen,
            'ended_at' => $now,
            'business_minutes_lost' => $minutesLost,
        ]);
    }

    /** Fairness floor (see compensateForOutage()) — never less than this much usable time after recovery, no matter how small the strict calculation comes out to. */
    private const OUTAGE_COMPENSATION_FLOOR_MINUTES = 30;

    /**
     * The detect+compensate pair, as one step — called from two places:
     * the periodic sweep() below (a backstop), and CheckForSlaOutage
     * middleware (the real, fast path — see that class' docblock for why
     * "the first thing that runs after recovery" beats waiting on any
     * schedule). Both call sites get identical behavior for free, since
     * this is the one place the pairing is defined.
     *
     * @return array{outage: ?SlaOutageWindow, compensated: int}
     */
    public function checkForOutageRecovery(): array
    {
        $outage = $this->detectOutage();
        $compensated = $outage ? $this->compensateForOutage($outage) : 0;

        return ['outage' => $outage, 'compensated' => $compensated];
    }

    public function sweep(): array
    {
        $outageCheck = $this->checkForOutageRecovery();

        return [
            'outage_detected' => $outageCheck['outage'] !== null,
            'deadlines_compensated' => $outageCheck['compensated'],
            'late_review_reminders_sent' => $this->trackLateReviews(),
            'urgent_approver_reminders_sent' => $this->remindStillUrgentApprovers(),
        ];
    }

    /**
     * Only touches assignments that were actually AT RISK when the outage
     * hit — Urgent, Normal, or already Expired (urgencyRank() 1, 2, or 4)
     * — a document with days of slack left (rank 3, "Low") was never
     * going to be affected by a short outage, so leaving it alone avoids
     * both pointless deadline churn and a notification nobody needed.
     * Expired is deliberately included, not just Urgent/Normal: this
     * sweep runs AFTER the outage has already ended, so an assignment
     * that was Urgent right as the outage hit will usually have already
     * ticked over to "Expired" (0 seconds remaining) by the time this
     * runs — excluding Expired would miss exactly the case this feature
     * exists for, and let it fall through to an uncompensated auto-
     * approval instead.
     *
     * The new deadline is whichever is LATER: the strict business-hours-
     * aware calculation (push the current deadline forward by exactly how
     * many working minutes the outage cost, via addBusinessMinutes — see
     * its docblock for why this isn't a naive "add the raw outage
     * length"), or a flat OUTAGE_COMPENSATION_FLOOR_MINUTES after the
     * outage actually ended. Without the floor, a short overlap right at
     * the edge of closing time could compensate someone with a window so
     * thin (a few minutes at the start of the next working day) that it's
     * not a fair chance to act, even though it's technically accurate.
     * Either way, never pushed past the assignment's own due date — an
     * outage extends the approver's working budget, not what the
     * Originator was promised.
     */
    private function compensateForOutage(SlaOutageWindow $outage): int
    {
        $count = 0;

        DocumentAssignment::query()
            ->where('individual_status', 'pending')
            ->whereNotNull('sla_expires_at')
            ->with(['document', 'stage'])
            ->get()
            ->each(function (DocumentAssignment $assignment) use (&$count, $outage) {
                if (! in_array($assignment->urgencyRank(), [1, 2, 4], true)) {
                    return;
                }

                $strict = $this->businessHours->addBusinessMinutes($assignment->sla_expires_at, $outage->business_minutes_lost);
                $floor = $this->businessHours->addBusinessMinutes($outage->ended_at, self::OUTAGE_COMPENSATION_FLOOR_MINUTES);
                $newExpiry = $strict->greaterThan($floor) ? $strict : $floor;
                $newExpiry = $this->clampToDueDate($newExpiry, $assignment->due_date);

                if ($newExpiry->lessThanOrEqualTo($assignment->sla_expires_at)) {
                    return; // due-date clamp already left nothing to compensate
                }

                $assignment->sla_expires_at = $newExpiry;
                $assignment->save();

                NotificationRecord::send($assignment->user_id, $assignment->document_id,
                    "Your review deadline for '{$assignment->document->title}' (stage '{$assignment->stage->stage_name}') was extended ".
                    'to account for a recent system outage — you were not penalized for time the system itself was unreachable.');

                $count++;
            });

        if ($count > 0) {
            AuditLog::record(null, null, 'sla_outage_compensation',
                "Detected a system outage from {$outage->started_at->toDayDateTimeString()} to {$outage->ended_at->toDayDateTimeString()} ".
                "({$outage->business_minutes_lost} working minute(s) lost) — {$count} approver deadline(s) extended to compensate.");
        }

        $outage->compensated_at = now();
        $outage->save();

        return $count;
    }

    /**
     * The "born urgent" notification (WorkflowService::assignStage()) fires
     * exactly once, the instant an assignment is created with an already-
     * short window. If the approver isn't looking right then, nothing
     * nudges them again before it actually auto-approves — this sends ONE
     * follow-up, right as the same Urgent window is about to run out,
     * guarded by urgent_reminder_sent_at.
     */
    private function remindStillUrgentApprovers(): int
    {
        $count = 0;

        DocumentAssignment::query()
            ->where('individual_status', 'pending')
            ->whereNull('urgent_reminder_sent_at')
            ->whereNotNull('sla_expires_at')
            ->with(['document', 'stage'])
            ->get()
            ->each(function (DocumentAssignment $assignment) use (&$count) {
                try {
                    if ($assignment->urgencyRank() !== 1) {
                        return;
                    }

                    $assignment->urgent_reminder_sent_at = now();
                    $assignment->save();

                    NotificationRecord::send($assignment->user_id, $assignment->document_id,
                        "FINAL CALL: '{$assignment->document->title}' (stage '{$assignment->stage->stage_name}') is about to breach its SLA window ".
                        '— please act now before it auto-approves.',
                        'high');

                    $count++;
                } catch (\Throwable $e) {
                    report($e); // one seat's failure must not stop the reminders for the rest
                }
            });

        return $count;
    }

    /**
     * How often a follow-up fires, and for how long — a flat window capped
     * at the document's own due date, same shape as ADMIN_REVIEW_WINDOW_
     * HOURS/review_due_at below: if 12 hours of hourly nagging from when
     * the violation opened would run past the due date, the window
     * shortens to whatever time is actually left instead, so reminders
     * never outlast the document's own deadline. Once the window closes,
     * nudging stops (the violation keeps accruing silently after that —
     * see AdminViolation::hoursOverdue()).
     */
    private const LATE_REVIEW_NOTIFICATION_INTERVAL_HOURS = 1;

    private const LATE_REVIEW_NOTIFICATION_WINDOW_HOURS = 12;

    /**
     * Closes the gap between "the system auto-approved this" and "an admin
     * actually looked at it" — autoApproveOne() already sends an immediate
     * in-app notification the moment auto-approval happens, but nothing
     * previously followed up if that sat unreviewed. Triggered off each
     * assignment's own review_due_at (set at auto-approval time), NOT the
     * document's due date: a document can have days of runway left while
     * still needing a prompt review.
     *
     * ONE AdminViolation row per incident (not one per hour) — created the
     * moment the review window first lapses, updated in place as
     * notifications go out, resolved once AdminController::
     * reviewAutoApproval() actually happens. Notifications repeat hourly
     * for up to LATE_REVIEW_NOTIFICATION_WINDOW_HOURS (capped at the
     * document's own due date — see the constant's docblock), so an admin
     * away for a while doesn't come back to endless identical pings — the
     * violation itself keeps accruing (see hoursOverdue()) even after the
     * nudging stops.
     */
    private function trackLateReviews(): int
    {
        $count = 0;

        DocumentAssignment::query()
            ->where('auto_approved', true)
            ->whereNull('admin_reviewed_at')
            ->whereNotNull('review_due_at')
            ->where('review_due_at', '<=', now())
            ->with(['document', 'stage'])
            ->get()
            ->each(function (DocumentAssignment $assignment) use (&$count) {
                try {
                    $violation = AdminViolation::firstOrCreate(
                        ['assignment_id' => $assignment->assignment_id, 'violation_type' => 'late_review', 'resolved_at' => null],
                        [
                            'document_id' => $assignment->document_id,
                            'stage_name' => $assignment->stage->stage_name,
                            'first_violated_at' => $assignment->review_due_at,
                            'notification_count' => 0,
                        ]
                    );

                    // Same flat-window-capped-at-due-date shape as
                    // autoApproveOne()'s review_due_at below — nagging never
                    // outlasts the document's own deadline. A side effect:
                    // when review_due_at was itself already clamped to
                    // due_date (see autoApproveOne()), that leaves ~zero room
                    // here too, so this incident gets few or no reminders at
                    // all — there's no runway left to nag within by the time
                    // it opens.
                    $flatDeadline = $violation->first_violated_at->copy()->addHours(self::LATE_REVIEW_NOTIFICATION_WINDOW_HOURS);
                    $notificationDeadline = $this->clampToDueDate($flatDeadline, $assignment->document->due_date);

                    if (now()->greaterThanOrEqualTo($notificationDeadline)) {
                        return;
                    }
                    // abs(): Carbon 3's diffInHours() returns a signed float
                    // depending on direction, not always positive even though
                    // $now is always later here — same gotcha as
                    // autoApproveApproverMiss()'s duration_overdue calculation.
                    if ($violation->last_notified_at && abs(now()->diffInHours($violation->last_notified_at)) < self::LATE_REVIEW_NOTIFICATION_INTERVAL_HOURS) {
                        return;
                    }

                    $violation->last_notified_at = now();
                    $violation->notification_count++;
                    $violation->save();

                    foreach (User::where('role', 'admin')->where('is_active', true)->get() as $admin) {
                        NotificationRecord::send($admin->user_id, $assignment->document_id,
                            "URGENT: '{$assignment->document->title}' (stage '{$assignment->stage->stage_name}') was auto-approved ".
                            "and still hasn't been reviewed — it's now {$violation->hoursOverdue()}h past its review window. Please confirm or dispute it now.",
                            'high');
                    }

                    $count++;
                } catch (\Throwable $e) {
                    report($e); // one seat's failure must not stop the reminders for the rest
                }
            });

        return $count;
    }

    // trackLateMlReviews() (the classification-review counterpart to
    // trackLateReviews() above, keyed on the now-retired ml_review_status/
    // ml_review_due_at) was removed along with the manual classification
    // and readability review queues — see WorkflowService::ingest()'s
    // $belowChanceFloor docblock. Both are fully automatic now: nothing
    // sets ml_review_status or readability_review_status to 'pending'
    // anymore, so there was never anything left for this sweep to find.

    /**
     * A real approver missed their own SLA window — auto-approves
     * immediately, Admin reviews after. A stage with no eligible approver
     * no longer reaches this at all: it's auto-approved right at routing
     * time instead (see WorkflowService::assignStage()/
     * autoApproveNoEligibleApprover()), so there's nothing left here to
     * dispatch between two cases.
     */
    public function autoApproveMissedDeadline(DocumentAssignment $assignment): void
    {
        $this->autoApproveApproverMiss($assignment);
    }

    /**
     * A real approver had their own fair window and missed it. Auto-
     * approves right away (see autoApproveOne(), which sets a
     * review_due_at so Admin still reviews it afterward, just not
     * before). The SLA violation itself is still logged — the approver
     * genuinely did miss their window, and that stays a real,
     * accountable fact regardless of what happens next.
     *
     * Feature: race-condition-safe. autoApproveMissedDeadline() can legitimately be
     * triggered from more than one place for the same overdue assignment
     * at nearly the same instant — the event-driven job, the periodic
     * safety-net sweep, and ApprovalController's on-demand call can all
     * land within moments of each other. Without a lock, two of them
     * could both read individual_status='pending' before either one's
     * auto-approval actually commits, and both log a violation — this is
     * exactly what happened in production (confirmed via several
     * assignment_ids each logging 2-3 SlaViolation rows within 1-2
     * seconds of each other, and two specific assignments logging
     * hundreds of rows over multiple days because their auto-approval
     * kept losing that race). lockForUpdate() + re-checking pending
     * status AFTER acquiring the lock means only the first caller to
     * actually win the row lock ever creates a violation; every other
     * concurrent caller finds it already resolved and no-ops.
     */
    private function autoApproveApproverMiss(DocumentAssignment $assignment): void
    {
        DB::transaction(function () use ($assignment) {
            $locked = DocumentAssignment::where('assignment_id', $assignment->assignment_id)->lockForUpdate()->first();

            // individual_status is the only real signal here.
            if (! $locked || $locked->individual_status !== 'pending') {
                return; // already resolved by a concurrent escalation
            }

            // abs()+round(): Carbon 3's diffInMinutes() returns a signed float
            // even with the default $absolute param, so the sign and
            // fractional part both need normalizing before this hits an
            // unsignedInteger column.
            SlaViolation::create([
                'document_id' => $locked->document_id,
                'assignment_id' => $locked->assignment_id,
                'approver_id' => $locked->user_id,
                'violation_timestamp' => now(),
                'duration_overdue' => (int) round(abs(now()->diffInMinutes($locked->sla_expires_at))),
                'stage_name' => $locked->stage->stage_name,
            ]);

            $this->autoApproveOne($locked);
        });
    }

    /**
     * A stage had no eligible approver — auto-approved immediately (see
     * WorkflowService::assignStage()/autoApproveDeactivatedSeat(), which
     * call this instead of parking the seat in a queue), same mechanism
     * as an approver's own missed deadline. Still logs an AdminViolation
     * against the Admin role rather than a real approver (there isn't
     * one), so this still shows up in the SLA Violations report — just
     * always created already resolved, since the auto-approval IS the
     * resolution, happening in the very same instant rather than after a
     * separate fallback window later passed (contrast
     * trackLateReviews()'s late_review violations, which stay open until
     * actually reviewed). Public: called directly from WorkflowService,
     * not reached through autoApproveMissedDeadline() anymore — a stage with no eligible
     * approver never sits pending long enough to have a deadline to miss.
     */
    public function autoApproveNoEligibleApprover(DocumentAssignment $assignment): void
    {
        $now = now();

        AdminViolation::create([
            'document_id' => $assignment->document_id,
            'assignment_id' => $assignment->assignment_id,
            'violation_type' => 'missed_approval',
            'stage_name' => $assignment->stage->stage_name,
            'first_violated_at' => $now,
            'resolved_at' => $now,
        ]);

        $this->autoApproveOne($assignment);
    }

    /** How long Admin has to review an auto-approved stage before a late review gets logged (see reviewDueAt() below) — matches the old admin_grace_hours default, not a separately invented number. */
    private const ADMIN_REVIEW_WINDOW_HOURS = 6;

    /**
     * Shared by both auto-approval paths above — same outcome either way,
     * just triggered by a different missed deadline.
     */
    /**
     * The Admin's review deadline for an auto-approved stage: 6 real
     * WORKING hours from now (business-hours-aware, same engine every
     * other SLA deadline in this app already uses — see
     * WorkflowService::recalculateAssignmentSlaExpiry()'s identical
     * pattern), never past the document's due date. Confirmed real bug
     * fixed here: this used to be a flat now()->addHours(6) with no
     * business-hours awareness at all — the one deadline in the whole app
     * that didn't pause overnight/Sundays, which with a single Admin
     * account meant "overdue" was lit almost permanently for anything
     * auto-approved outside business hours, not a meaningful signal.
     */
    public function reviewDeadlineFor(DocumentAssignment $assignment): Carbon
    {
        return $this->reviewDeadlineFrom(now(), $assignment->due_date);
    }

    /**
     * The actual 6-working-hour-from-$anchor computation, split out from
     * reviewDeadlineFor() so recalculatePendingReviewDeadlines() below can
     * re-run it anchored to the auto-approval's own real moment (acted_at)
     * instead of "now" — recalculating from "now" would hand Admin a
     * brand new 6-hour window every time a holiday changes, rather than
     * correctly re-deriving the SAME original window under the new
     * calendar, exactly the distinction WorkflowService::
     * recalculateAssignmentSlaExpiry() already makes for the approver side
     * (anchored to the assignment's own created_at, not "now").
     */
    private function reviewDeadlineFrom(Carbon $anchor, $dueDate): Carbon
    {
        $deadline = $this->businessHours->addBusinessMinutes($anchor, self::ADMIN_REVIEW_WINDOW_HOURS * 60);

        return $this->clampToDueDate($deadline, $dueDate);
    }

    /**
     * Re-syncs every assignment currently awaiting Admin's own review
     * (auto-approved, not yet confirmed/disputed) against the current
     * calendar — the review-side counterpart to WorkflowService::
     * recalculatePendingSlaDeadlines(), called from the exact same two
     * places (see AdminController::storeHoliday()/destroyHoliday()).
     * Anchored to each assignment's own acted_at (the real moment it
     * auto-approved — see autoApproveOne() below, which sets both
     * acted_at and the original review_due_at in the same save), not
     * "now", for the reason reviewDeadlineFrom() explains.
     *
     * Deliberately excludes any assignment still awaitingHeadSignOff()
     * (review_due_at is null for those — see autoApproveOne()'s own logic
     * below; there's nothing to recalculate until settleDeferredAutoApprovalReviews()
     * either covers or reinstates it).
     *
     * @return int number of assignments whose review deadline actually changed
     */
    public function recalculatePendingReviewDeadlines(): int
    {
        $changed = 0;

        DocumentAssignment::where('auto_approved', true)
            ->whereNull('admin_reviewed_at')
            ->whereNotNull('review_due_at')
            ->with(['stage', 'document'])
            ->get()
            ->each(function (DocumentAssignment $assignment) use (&$changed) {
                $newDeadline = $this->reviewDeadlineFrom($assignment->acted_at, $assignment->due_date);

                if ($newDeadline->equalTo($assignment->review_due_at)) {
                    return;
                }

                $old = $assignment->review_due_at;
                $assignment->review_due_at = $newDeadline;
                $assignment->save();
                $changed++;

                AuditLog::record(null, $assignment->document_id, 'review_deadline_recalculated',
                    "Admin review deadline for stage '{$assignment->stage->stage_name}' recalculated from ".
                    "{$old->toDayDateTimeString()} to {$newDeadline->toDayDateTimeString()} after a business-hours/holiday calendar update.");

                foreach (User::where('role', 'admin')->where('is_active', true)->get() as $admin) {
                    NotificationRecord::send($admin->user_id, $assignment->document_id,
                        "The review deadline for '{$assignment->document->title}' (stage '{$assignment->stage->stage_name}') ".
                        "changed to {$newDeadline->format('M j, Y g:i A')} after an update to the business-hours calendar.");
                }
            });

        return $changed;
    }

    public function autoApproveOne(DocumentAssignment $assignment): void
    {
        DB::transaction(function () use ($assignment) {
            $document = $assignment->document; // cached on the model now, so re-accessing it after the transaction below (for notifications) costs no extra query

            AuditLog::record(null, $document->document_id, 'auto_approve',
                "System auto-approved stage '{$assignment->stage->stage_name}' — no human decision was made in time.");

            // individual_status and auto_approved must be set here —
            // completeStage() only finalizes the DOCUMENT's
            // global_status; it never touches the assignment's own
            // status (that's the caller's job, same as decide() already
            // does). Without this, the assignment stays 'pending'
            // forever and would get caught — and re-notified on — every
            // subsequent sweep.
            $assignment->individual_status = 'approved';
            $assignment->auto_approved = true;
            $assignment->acted_at = now();
            // Admin's own soft review deadline — a flat window capped so
            // it never runs past the document's due date, checked by
            // trackLateReviews() above and AdminController::
            // reviewAutoApproval() to decide whether a review was late —
            // crossing it doesn't block or auto-trigger anything by
            // itself.
            //
            // No review window at all while a Head Approver's Final Approval
            // is still to come: that sign-off checks the whole document, so
            // it IS the review (see WorkflowService::awaitingHeadSignOff()).
            // If the Head then also fails to act, the window is started
            // afterwards by WorkflowService::settleDeferredAutoApprovalReviews().
            $assignment->review_due_at = $this->workflow->awaitingHeadSignOff($assignment)
                ? null
                : $this->reviewDeadlineFor($assignment);
            $assignment->save();

            $this->workflow->completeStage($assignment, 'approved', true);
        });

        // Notifications are side effects of the state change above, sent
        // only AFTER it has actually committed — deliberately outside the
        // transaction (and, when this is called from
        // autoApproveApproverMiss(), after that method's own row lock has
        // already been released). A concurrent escalation attempt on the
        // same assignment only needs individual_status to have already
        // flipped by the time it re-checks under its own lock, which
        // committing first guarantees regardless of how long this loop
        // over every admin/approver takes — so there's no reason for it
        // to hold that lock open too.
        $document = $assignment->document;

        // Deliberately NOT phrased as final/done — the Originator needs
        // to understand this hasn't actually been reviewed by a person
        // yet, only auto-approved because nobody acted in time. See
        // status-badge.blade.php for the matching visual treatment (not
        // the same green as a real approval).
        NotificationRecord::send($document->originator_id, $document->document_id,
            "Your document '{$document->title}' (stage '{$assignment->stage->stage_name}') was auto-approved because nobody ".
            'acted on it in time — an Admin will still give it a final check, and you\'ll be notified if anything changes.', 'high');

        foreach (User::whereIn('role', ['admin', 'approver'])->where('is_active', true)->get() as $u) {
            NotificationRecord::send($u->user_id, $document->document_id,
                "HIGH PRIORITY: '{$document->title}' had a stage auto-approved by the system without human sign-off. Please review.",
                'high');
        }
    }

    /**
     * Caps $deadline at $dueDate if it would otherwise run past it — the
     * shared shape behind every "never let a reminder/deadline outlast
     * the document's own due date" rule in this file (outage
     * compensation, late-review nudging, the auto-approval review
     * window). Centralized so a future change to this clamp only has to
     * happen once instead of drifting out of sync across copies.
     */
    private function clampToDueDate($deadline, $dueDate)
    {
        return ($dueDate && $deadline->greaterThan($dueDate)) ? $dueDate->copy() : $deadline;
    }
}
