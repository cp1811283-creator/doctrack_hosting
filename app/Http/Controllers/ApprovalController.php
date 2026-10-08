<?php

namespace App\Http\Controllers;

use App\Models\DocumentAnnotation;
use App\Models\DocumentAssignment;
use App\Models\DocumentReviewSession;
use App\Models\SystemSetting;
use App\Services\BusinessHoursService;
use App\Services\SlaService;
use App\Services\WorkflowService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class ApprovalController extends Controller
{
    /**
     * Same pseudo-category as ArchiveController::OTHER_FOLDER — Decision
     * History's own category filter needs the identical special case
     * (see historyResults()), so it's redeclared here rather than reusing
     * that one, which is private to a different controller.
     */
    private const OTHER_CATEGORY = 'Other';

    public function __construct(
        private WorkflowService $workflow,
        private SlaService $sla,
        private BusinessHoursService $businessHours,
    ) {}

    /**
     * Feature: an approver can be blocked from deciding outside business
     * hours (SystemSetting::enforce_business_hours_decisions, Admin
     * toggle on Workflow Config — off by default so existing installs and
     * the test suite aren't suddenly gated). The point isn't the SLA
     * clock — that's already business-hours-aware and unaffected either
     * way — it's accountability: without this, an approver could let an
     * assignment sit untouched through the entire paid workday and then
     * decide it that same evening, off the clock, always safely before
     * the deadline (which itself always lands inside a future business
     * window), leaving zero record of ever acting during business hours.
     */
    private function requireBusinessHoursIfEnforced(): void
    {
        if (SystemSetting::current()->enforce_business_hours_decisions
            && ! $this->businessHours->isWithinWorkingWindow(now())) {
            abort(403, 'Decisions can only be made during business hours (9 AM–5 PM, Mon–Sat).');
        }
    }

    /**
     * Escalates any of this approver's own assignments whose SLA window
     * has already lapsed but haven't been picked up by the periodic
     * `workflow:check-parallel-slas` sweep yet. Called on-demand (page
     * load, decide attempt) so a stale cron interval can never leave an
     * expired assignment sitting in the approver's actionable queue —
     * the Clock-Stop Mechanism (Section 4) needs to take effect the
     * moment the deadline passes, not on whatever the next tick happens
     * to be.
     */
    private function autoApproveExpiredFor(int $userId): void
    {
        DocumentAssignment::where('user_id', $userId)
            ->where('individual_status', 'pending')
            ->where('sla_expires_at', '<', now())
            ->with(['stage', 'document', 'approver'])
            ->get()
            ->each(fn (DocumentAssignment $a) => $this->sla->autoApproveMissedDeadline($a));
    }

    /**
     * The set of priority labels ("Urgent"/"Normal"/"Low"/"Expired")
     * present across a container's document(s) — mirrors the exact same
     * per-document computation the Blade view uses for its badge (see
     * resources/views/approver/dashboard.blade.php), so the priority
     * filter matches what's actually displayed.
     */
    private function containerPriorityLabels($container): Collection
    {
        return $container->documents->map(function ($stageAssignments) {
            // Only a still-pending seat gets a label — a document that's
            // only here because it's waiting on OTHER approvers (see
            // buildQueue()) has nothing left for THIS approver to act on,
            // so it shouldn't match any specific urgency filter anymore.
            $active = $stageAssignments->where('individual_status', 'pending')
                ->sortBy(fn (DocumentAssignment $a) => $a->stage->sequence_order)->first();

            return $active?->urgencyLabel();
        })->filter()->unique()->values();
    }

    /**
     * Queue ordering key (Feature: Urgent-first sorting) — the most urgent
     * rank (DocumentAssignment::urgencyRank(), based on real remaining
     * business time before each seat's own SLA deadline — see that
     * method's docblock) among this container's still-actionable
     * documents, so the list position always agrees with the priority
     * badge shown per row instead of only being sorted by due_date. Lower
     * sorts first: Urgent=1, Normal=2, Low=3, Expired=4 — Expired sits
     * last among "real" priorities since those seats are already past
     * their deadline and about to be (or already being) auto-approved,
     * nothing worth acting on. A container where
     * every document is already decided by this approver (just waiting on
     * co-approvers — see resolvedButInFlightQueryFor()) has no active seat
     * at all and sorts last of all (5), since there's nothing actionable
     * in it either.
     */
    private function containerPriorityRank($container): int
    {
        $ranks = $container->documents->map(function ($stageAssignments) {
            $active = $stageAssignments->where('individual_status', 'pending')
                ->sortBy(fn (DocumentAssignment $a) => $a->stage->sequence_order)->first();

            return $active?->urgencyRank();
        })->filter(fn ($r) => $r !== null);

        return $ranks->isEmpty() ? 5 : $ranks->min();
    }

    /**
     * Approver dashboard: action-oriented review queue with SLA countdowns.
     *
     * Requests are rendered as nested containers, two levels deep:
     *   - Outer: the SubmissionBatch a document arrived in (Feature:
     *     grouped approval requests) — documents an Originator uploaded
     *     together stay visually together, with the shared due date shown
     *     once at the container level. A document with no batch (legacy
     *     data, or a lone single-file submission) becomes a container of
     *     its own.
     *   - Inner: each document's own pending stage assignment(s) — since
     *     every configured stage is routed up front (not gated behind the
     *     prior stage's decision), a document can have more than one stage
     *     pending at once, and those still nest under that one document
     *     card rather than duplicating it.
     */
    /**
     * Every seat this approver still has to decide. A seat that misses its
     * deadline is auto-approved by the system (individual_status stops being
     * 'pending'), so it falls out of this query at that moment — the
     * approver's own misses are visible in their Decision History instead.
     * Shared by dashboard() (full data)
     * and poll() (just a count) so both always agree on what "pending"
     * means.
     */
    private function pendingQueryFor(int $userId)
    {
        return DocumentAssignment::where('user_id', $userId)
            ->where('individual_status', 'pending');
    }

    /**
     * Builds the paginated, filtered container list both dashboard() (full
     * page) and refresh() (AJAX fragment for the live-polling swap) render
     * — kept in exactly one place so a live-swapped queue can never drift
     * from what a normal page load would have shown for the same filters.
     */
    /**
     * A document stays visible in this approver's queue even after they've
     * decided every seat they hold on it, as long as the document itself
     * isn't finalized yet — e.g. a co-approver on the same stage, or a
     * later stage, still hasn't decided. It disappears only once the whole
     * document reaches a terminal global_status (approved/auto_approved/
     * rejected), not the moment this approver's own part is done. Excludes
     * any document already covered by $pending (still has an actionable
     * seat) to avoid a duplicate container for the same document.
     */
    private function resolvedButInFlightQueryFor(int $userId, Collection $pendingDocumentIds)
    {
        return DocumentAssignment::where('user_id', $userId)
            ->whereIn('individual_status', ['approved', 'rejected', 'auto_approved'])
            ->whereNotIn('document_id', $pendingDocumentIds)
            ->whereHas('document', fn ($q) => $q->whereNotIn('global_status', ['approved', 'auto_approved', 'rejected']));
    }

    /**
     * The full, unfiltered, correctly-ordered container list for one
     * approver's queue — everything buildQueue() below does BEFORE its
     * priority/document search filters and pagination get applied.
     * Extracted so pageForDocument() (Feature: notification click scrolls
     * straight to the document instead of dumping the approver at the top
     * of their queue — see NotificationController::markRead()) can find a
     * document's position using the exact same ordering the queue itself
     * renders with, rather than a second, potentially-drifting query.
     */
    private function unfilteredContainers(int $userId): Collection
    {
        $pending = $this->pendingQueryFor($userId)
            ->with(['document.batch', 'document.originator', 'document.assignments.approver', 'stage'])
            ->orderBy('priority_rank')
            ->orderBy('sla_expires_at')
            ->get();

        $resolvedInFlight = $this->resolvedButInFlightQueryFor($userId, $pending->pluck('document_id')->unique())
            ->with(['document.batch', 'document.originator', 'document.assignments.approver', 'stage'])
            ->get();

        $relevant = $pending->concat($resolvedInFlight);

        return $relevant
            ->groupBy(fn (DocumentAssignment $a) => $a->document->batch_id ? 'batch-'.$a->document->batch_id : 'doc-'.$a->document_id)
            ->map(function ($groupAssignments) {
                $first = $groupAssignments->first();
                $batch = $first->document->batch;

                return (object) [
                    'is_batch' => (bool) $batch,
                    'batch' => $batch,
                    'due_date' => $batch->due_date ?? $first->document->due_date,
                    'originator' => $first->document->originator,
                    'documents' => $groupAssignments->groupBy('document_id'),
                ];
            })
            // Sorted least-significant-key-first: due_date breaks ties
            // within the same priority rank, then the priority-rank sort
            // (stable, per PHP 8+) preserves that due_date ordering within
            // each rank bucket — Urgent containers all listed first (soonest
            // due first among them), then Normal, then Low, then Expired,
            // then anything with nothing left to act on.
            ->sortBy(fn ($c) => $c->due_date)
            ->sortBy(fn ($c) => $this->containerPriorityRank($c))
            ->values();
    }

    /**
     * Which page (at the queue's own 10/page — see buildQueue()) a given
     * document currently sits on in this approver's OWN, unfiltered queue
     * — null if it isn't in there at all (already fully resolved and no
     * longer in-flight, or never assigned to this approver). Deliberately
     * ignores any priority/document filter a viewer might currently have
     * active — a notification link should always find the document
     * regardless of what the approver happened to have typed into the
     * search box last.
     */
    public function pageForDocument(int $userId, int $documentId, int $perPage = 10): ?int
    {
        $containers = $this->unfilteredContainers($userId);

        $index = $containers->search(fn ($c) => $c->documents->has($documentId));

        return $index === false ? null : intdiv($index, $perPage) + 1;
    }

    private function buildQueue(Request $request, int $userId): array
    {
        $containers = $this->unfilteredContainers($userId);

        // Raw assignment count (same unit poll() returns), not the number
        // of grouped containers below — passed to the view as the polling
        // JS's starting baseline so "N new" comparisons are apples-to-apples.
        // Deliberately excludes the resolved-but-in-flight rows merged in
        // below — this counts only what still needs THIS approver's own
        // action, not everything visible in the queue.
        $initialPendingCount = $this->pendingQueryFor($userId)->count();

        if ($request->filled('priority')) {
            $wanted = $request->string('priority');
            $containers = $containers->filter(fn ($c) => $this->containerPriorityLabels($c)->contains($wanted))->values();
        }

        if ($request->filled('document')) {
            $term = mb_strtolower($request->string('document'));
            $containers = $containers->filter(function ($c) use ($term) {
                foreach ($c->documents as $stageAssignments) {
                    if (str_contains(mb_strtolower($stageAssignments->first()->document->title), $term)) {
                        return true;
                    }
                }

                return false;
            })->values();
        }

        $perPage = 10;
        $page = (int) $request->input('page', 1);

        $containers = new LengthAwarePaginator(
            $containers->forPage($page, $perPage)->values(),
            $containers->count(),
            $perPage,
            $page,
            // Real page route, not $request->url() — this is also built
            // from within refresh() (the live-poll fragment route); a
            // path derived from that request would bake the bare-fragment
            // URL into Next/Previous whenever a live swap happens to be
            // what rendered this page.
            ['path' => route('approver.dashboard'), 'query' => $request->query()]
        );

        return [$containers, $initialPendingCount];
    }

    /**
     * Business-hours-gate state for the queue view (Feature: Approve/Reject
     * disabled outside business hours when an Admin has opted into the
     * toggle) — shared by dashboard() and refresh() so a live-swapped
     * fragment can never disagree with a full page load about whether the
     * gate is currently active.
     */
    private function businessHoursGateData(): array
    {
        return [
            'businessHoursEnforced' => SystemSetting::current()->enforce_business_hours_decisions,
            'isWithinBusinessHours' => $this->businessHours->isWithinWorkingWindow(now()),
        ];
    }

    public function dashboard(Request $request)
    {
        $this->autoApproveExpiredFor($request->user()->user_id);

        [$containers, $initialPendingCount] = $this->buildQueue($request, $request->user()->user_id);

        return view('approver.dashboard', [
            ...compact('containers', 'initialPendingCount'),
            ...$this->businessHoursGateData(),
        ]);
    }

    /**
     * Renders just the queue fragment (resources/views/approver/partials/queue.blade.php)
     * for the dashboard's polling JS to swap into the page in place — see
     * dashboard.blade.php for why this is a smoother, less jarring update
     * than reloading the whole page. Respects the same priority/document
     * filters as a normal page load (the JS forwards the current query
     * string), so a live update never silently drops an active filter.
     */
    public function refresh(Request $request)
    {
        $this->autoApproveExpiredFor($request->user()->user_id);

        [$containers, $initialPendingCount] = $this->buildQueue($request, $request->user()->user_id);

        return view('approver.partials.queue', [
            ...compact('containers', 'initialPendingCount'),
            ...$this->businessHoursGateData(),
        ]);
    }

    /**
     * Lightweight JSON endpoint the dashboard's JS polls every ~5-10s
     * (resources/views/approver/dashboard.blade.php) so a newly routed
     * document shows up without the approver having to manually refresh.
     * Deliberately just a count, not the full nested container payload
     * dashboard()/refresh() build — cheap enough to hit repeatedly; the
     * heavier refresh() fetch only happens when this actually detects a
     * change.
     */
    public function poll(Request $request)
    {
        $count = $this->pendingQueryFor($request->user()->user_id)->count();

        return response()->json(['pending_count' => $count]);
    }

    public function decide(Request $request, DocumentAssignment $assignment)
    {
        $this->authorize('decide', $assignment);

        $validated = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            // Required on rejection specifically — a reject with no
            // explanation gives the originator nothing to act on when
            // they resubmit. Approving needs no justification.
            'comments' => [Rule::requiredIf(fn () => $request->input('decision') === 'rejected'), 'nullable', 'string', 'max:1000'],
        ]);

        abort_if($assignment->individual_status !== 'pending', 409, 'This assignment has already been actioned.');

        // Escalate right now if the deadline passed since this page was
        // loaded, rather than trust that the periodic sweep already caught
        // it — closes the window where a stale cron interval would let a
        // late decision through.
        if ($assignment->sla_expires_at && now()->greaterThan($assignment->sla_expires_at)) {
            $this->sla->autoApproveMissedDeadline($assignment);
            $assignment->refresh(); // autoApproveMissedDeadline() worked on its own locked copy — re-read what it did
        }
        abort_if($assignment->individual_status !== 'pending', 409, 'This assignment\'s SLA deadline has passed — the system auto-approved it, so it can no longer be decided here.');

        $this->requireBusinessHoursIfEnforced();

        // Checked BEFORE closeFor() — closing the session would make "time
        // elapsed on the still-open session" impossible to recover, and
        // that time is exactly what this is judging.
        $secondsReviewed = DocumentReviewSession::secondsSpentSoFar($assignment->document_id, $request->user()->user_id);
        $minSeconds = config('review.min_review_seconds', 10);
        abort_if($secondsReviewed < $minSeconds, 422,
            "You need to view the document for at least {$minSeconds} seconds before deciding — {$secondsReviewed}s recorded so far.");

        DocumentReviewSession::closeFor($assignment->document, $request->user());
        $this->workflow->decide($assignment, $request->user(), $validated['decision'], $validated['comments'] ?? null);

        $status = 'Decision recorded: '.ucfirst($validated['decision']).'.';

        // AJAX path (Feature: no full-page redirect on Approve/Reject) —
        // the queue JS swaps the fragment itself via applyLiveRefresh()
        // instead of relying on a redirect + session-flashed banner, which
        // is what was resetting scroll position to the top of the page on
        // every decision. Non-JS/no-JS clients still get the old redirect.
        if ($request->wantsJson()) {
            return response()->json(['status' => $status]);
        }

        return redirect()->route('approver.dashboard')->with('status', $status);
    }

    /**
     * "Review & Comment" panel (Feature: the plain extracted text of the
     * document, with every OPEN annotation on it already highlighted —
     * from any approver on any stage, not just this one — see the
     * "Request Revision" feature this supports). Fetched by the shared
     * modal from the Approver Queue, kept separate from the raw-file
     * "View original file" viewer, which is unaffected by any of this.
     */
    public function annotationsPanel(Request $request, DocumentAssignment $assignment)
    {
        $this->authorize('decide', $assignment);

        $document = $assignment->document;

        // Feature parity with "View original file": opening Review &
        // Comment now also counts as reviewing the document — the same
        // tracked session that drives the minimum-review-time gate and
        // the "who's reviewing now" presence icon, since in practice this
        // is the popup approvers actually use to make their decision.
        // openForIfNotAlreadyOpen(), NOT openFor() — this same URL is also
        // what the queue's own live-refresh re-fetches while the popup
        // stays open, and openFor() unconditionally would reopen (and
        // rebroadcast) on every one of those refetches — see that
        // method's docblock for the self-sustaining loop that caused.
        if (DocumentReviewSession::countsAsActiveReviewer($document, $request->user())) {
            DocumentReviewSession::openForIfNotAlreadyOpen($document, $request->user());
        }

        $annotations = DocumentAnnotation::where('document_id', $document->document_id)
            ->whereNull('resolved_at')
            ->with('raisedBy')
            ->orderBy('start_offset')
            ->get();

        return view('approver.partials.annotations-panel', compact('assignment', 'document', 'annotations'));
    }

    /**
     * Request Revision (Feature: flag a specific passage of the
     * document's text with a comment, instead of rejecting the whole
     * document outright — see WorkflowService's majority-vote reject
     * redesign, which this is the non-destructive alternative to).
     * Deliberately does NOT touch individual_status or call decide() —
     * this approver hasn't made their Approve/Reject decision yet, they
     * can still do either later (or raise more than one revision
     * request); the document and every other approver's own review
     * continue completely unaffected.
     */
    public function requestRevision(Request $request, DocumentAssignment $assignment)
    {
        $this->authorize('decide', $assignment);

        $validated = $request->validate([
            'start_offset' => ['required', 'integer', 'min:0'],
            'end_offset' => ['required', 'integer', 'gt:start_offset'],
            'selected_text' => ['required', 'string', 'max:5000'],
            'comment' => ['required', 'string', 'max:1000'],
        ]);

        abort_if($assignment->individual_status !== 'pending', 409, 'This assignment has already been actioned.');

        $secondsReviewed = DocumentReviewSession::secondsSpentSoFar($assignment->document_id, $request->user()->user_id);
        $minSeconds = config('review.min_review_seconds', 10);
        abort_if($secondsReviewed < $minSeconds, 422,
            "You need to view the document for at least {$minSeconds} seconds before flagging a revision — {$secondsReviewed}s recorded so far.");

        $this->workflow->requestRevision($assignment, $request->user(), $validated);

        $status = 'Revision request sent to the originator.';

        if ($request->wantsJson()) {
            return response()->json(['status' => $status]);
        }

        return redirect()->route('approver.dashboard')->with('status', $status);
    }

    /**
     * Withdraw a flag this approver themselves raised, before the
     * originator has addressed it (see DocumentAnnotationPolicy::withdraw()
     * — someone else's flag, or one already resolved, is refused).
     */
    public function withdrawAnnotation(Request $request, DocumentAnnotation $annotation)
    {
        $this->authorize('withdraw', $annotation);

        $this->workflow->withdrawAnnotation($annotation, $request->user());

        $status = 'Revision request withdrawn.';

        if ($request->wantsJson()) {
            return response()->json(['status' => $status]);
        }

        return redirect()->route('approver.dashboard')->with('status', $status);
    }

    /**
     * Decides ALL of this approver's pending stages for one document in a
     * single action (Feature: one Approve/Reject button set per document,
     * not one per stage). Since every configured stage is routed up front,
     * the same approver can end up holding more than one stage for the
     * same document at once (e.g. stages 1 and 3, if they're the eligible
     * pick for both) — previously each showed its own Approve/Reject row,
     * which looked like duplicated buttons for what the approver sees as
     * one decision on one document. Rejecting any one stage already
     * cascades to close every other pending stage for the document (see
     * WorkflowService::completeStage()), so the loop below simply skips
     * any assignment that's no longer pending by the time it's reached.
     */
    public function decideBatch(Request $request)
    {
        $validated = $request->validate([
            'assignment_ids' => ['required', 'array', 'min:1'],
            'assignment_ids.*' => ['integer', 'exists:document_assignments,assignment_id'],
            'decision' => ['required', 'in:approved,rejected'],
            'comments' => [Rule::requiredIf(fn () => $request->input('decision') === 'rejected'), 'nullable', 'string', 'max:1000'],
        ]);

        $assignments = DocumentAssignment::whereIn('assignment_id', $validated['assignment_ids'])
            ->where('user_id', $request->user()->user_id)
            ->where('individual_status', 'pending')
            ->with(['stage', 'document', 'approver'])
            ->get();

        abort_if($assignments->isEmpty(), 409, 'These assignments have already been actioned.');

        // Once for the whole batch, not per assignment — it's the same
        // "is it currently business hours" answer for every row.
        $this->requireBusinessHoursIfEnforced();

        $minSeconds = config('review.min_review_seconds', 10);
        $skippedExpired = 0;
        $skippedUnreviewed = 0;

        foreach ($assignments as $assignment) {
            $assignment->refresh();
            if ($assignment->individual_status !== 'pending') {
                continue; // already closed as a side effect of an earlier iteration (e.g. rejection cascade)
            }

            // Same on-demand escalation guard as decide() — don't let a
            // stale cron interval allow a late decision through.
            if ($assignment->sla_expires_at && now()->greaterThan($assignment->sla_expires_at)) {
                $this->sla->autoApproveMissedDeadline($assignment);
                $assignment->refresh(); // autoApproveMissedDeadline() worked on its own locked copy — re-read what it did
            }
            if ($assignment->individual_status !== 'pending') {
                $skippedExpired++;

                continue;
            }

            // Checked per assignment (not once for the whole batch) since
            // nothing here actually guarantees every id in the request
            // belongs to the same document — see decide()'s matching
            // comment for why this runs before closeFor().
            if (DocumentReviewSession::secondsSpentSoFar($assignment->document_id, $request->user()->user_id) < $minSeconds) {
                $skippedUnreviewed++;

                continue;
            }

            DocumentReviewSession::closeFor($assignment->document, $request->user());
            $this->workflow->decide($assignment, $request->user(), $validated['decision'], $validated['comments'] ?? null);
        }

        $status = 'Decision recorded: '.ucfirst($validated['decision']).'.';
        if ($skippedExpired > 0) {
            $status .= " {$skippedExpired} assignment(s) had already missed their SLA deadline and were auto-approved by the system instead.";
        }
        if ($skippedUnreviewed > 0) {
            $status .= " {$skippedUnreviewed} assignment(s) were skipped — you need to view the document for at least {$minSeconds} seconds before deciding.";
        }

        if ($request->wantsJson()) {
            return response()->json(['status' => $status]);
        }

        return redirect()->route('approver.dashboard')->with('status', $status);
    }

    // ---------------------------------------------------------------
    // Decision History — every decision THIS approver has personally
    // made (approved, rejected, or auto-approved on their behalf),
    // across every category they've ever been eligible for. Deliberately
    // assignment-scoped rather than document-scoped like the Archive:
    // Archive only ever shows approved/auto_approved DOCUMENTS, which
    // structurally can never include a rejection — an approver's own
    // rejections would be invisible there. This scopes to THEIR seat's
    // own individual_status instead, so a reject they issued shows up
    // even if a co-approver's later reject killed the document via a
    // completely different stage (that cascade-closed sibling seat still
    // shows up here too, but clearly attributed to whoever actually
    // rejected it — see cascade_closed_by and the view).
    //
    // Grouped by document, one row per document (not one row per
    // decision) — same "collapse to one row, expand for the rest"
    // pattern the Audit Logs/SLA Queue pages already
    // use, since one approver can hold more than one stage on the same
    // document and a flat per-decision list repeated the title for each.
    // ---------------------------------------------------------------

    /** Shared by history() and historyRefresh() — one place, can't drift. */
    private function historyResults(Request $request, int $approverId): LengthAwarePaginator
    {
        $query = DocumentAssignment::where('user_id', $approverId)
            ->whereIn('individual_status', ['approved', 'rejected', 'auto_approved'])
            // document.assignments.approver (every seat on the document,
            // not just this approver's own, plus who holds each one) is
            // what the view uses to name every co-approver on a
            // multi-seat stage for attribution purposes — see
            // history-results.blade.php's $attributionFor.
            ->with(['document.originator', 'document.assignments.approver', 'stage', 'cascadeClosedBy', 'adminOverrideBy']);

        if ($request->filled('keyword')) {
            $keyword = $request->string('keyword');
            $query->whereHas('document', fn ($q) => $q->where('title', 'like', "%{$keyword}%"));
        }

        if ($request->filled('category')) {
            $category = $request->string('category');
            // 'Other' is a pseudo-category, not a real one the classifier
            // ever assigns — same reasoning as ArchiveController's own
            // OTHER_FOLDER: the classifier is closed-set, so ml_category
            // is always one of the real known categories even for a
            // document the originator flagged as not belonging to any of
            // them, which is why this has to check desired_routing
            // instead of ml_category for that one case.
            $category->toString() === self::OTHER_CATEGORY
                ? $query->whereHas('document', fn ($q) => $q->where('desired_routing', 'unrelated'))
                : $query->whereHas('document', fn ($q) => $q->where('ml_category', $category));
        }

        // 'admin_override' isn't a real individual_status value — an admin
        // deciding on this approver's behalf (SLA Queue / Workflow Config)
        // still writes 'approved'/'rejected' to individual_
        // status, just with admin_override_by also set. Filtering on that
        // column instead is what actually answers "show me the ones an
        // admin decided for me," which individual_status alone can't.
        if ($request->string('decision')->toString() === 'admin_override') {
            $query->whereNotNull('admin_override_by');
        } elseif ($request->filled('decision')) {
            $query->where('individual_status', $request->string('decision'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('acted_at', '>=', $request->date('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('acted_at', '<=', $request->date('date_to'));
        }

        $decisions = $query->get();

        $containers = $decisions
            ->groupBy('document_id')
            ->map(fn ($group) => (object) [
                'document' => $group->first()->document,
                'decisions' => $group->sortByDesc('acted_at')->values(),
                'latest_acted_at' => $group->max('acted_at'),
            ])
            ->values();

        $containers = $request->string('sort')->toString() === 'oldest'
            ? $containers->sortBy('latest_acted_at')->values()
            : $containers->sortByDesc('latest_acted_at')->values();

        $perPage = 10;
        $page = (int) $request->input('page', 1);

        return new LengthAwarePaginator(
            $containers->forPage($page, $perPage)->values(),
            $containers->count(),
            $perPage,
            $page,
            // Real page route, not $request->url() — also built from
            // within historyRefresh() (the live-poll fragment route); see
            // buildQueue()'s matching comment above for the full reasoning.
            ['path' => route('approver.history'), 'query' => $request->query()]
        );
    }

    public function history(Request $request)
    {
        $decisions = $this->historyResults($request, $request->user()->user_id);
        // Only categories this approver could ever actually have a
        // decision in — see User::eligibleCategories()'s own docblock.
        // Previously offered all 3 categories to every approver, so a
        // Staff approver picked for only one category saw two filter
        // options that could never return a single result. 'Other' is
        // always appended regardless of eligibleCategories() — ANY
        // approver can still be hand-picked for a document flagged
        // "doesn't belong to any category" (see DocumentController::
        // selectApprovers()'s 'unrelated' branch, which has no category/
        // department restriction on who can be picked), same reasoning
        // as the Archive's own Other folder always being shown.
        $categories = [...$request->user()->eligibleCategories(), self::OTHER_CATEGORY];

        return view('approver.history', compact('decisions', 'categories'));
    }

    /** Live search (Feature: instant results as you type) — same query as history(), just the results fragment. */
    public function historyRefresh(Request $request)
    {
        $decisions = $this->historyResults($request, $request->user()->user_id);

        return view('approver.partials.history-results', compact('decisions'));
    }

    /** Cheap change-signal for the live-poll fallback — same pattern as ApprovalController::poll(). */
    public function historyPoll(Request $request)
    {
        return response()->json([
            'count' => DocumentAssignment::where('user_id', $request->user()->user_id)
                ->whereIn('individual_status', ['approved', 'rejected', 'auto_approved'])->count(),
        ]);
    }
}
