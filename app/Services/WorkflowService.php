<?php

namespace App\Services;

use App\Events\AssignmentRouted;
use App\Events\DocumentStatusChanged;
use App\Jobs\CheckAutoTrainDue;
use App\Jobs\EscalateAssignmentJob;
use App\Jobs\RetrainApprovalTimeModel;
use App\Models\AuditLog;
use App\Models\DocumentAnnotation;
use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\DocumentRevision;
use App\Models\NotificationRecord;
use App\Models\User;
use App\Models\WorkflowStage;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * WorkflowService
 * ----------------
 * Orchestrates the full document lifecycle state machine described in
 * Section 5:
 *
 *   processing -> classified_validated -> approved   (or auto_approved)
 *                                       -> rejected
 *
 * ALL-ELIGIBLE-APPROVERS, UNANIMOUS APPROVAL MODEL: every configured stage
 * for a document's category is routed the moment the document is uploaded
 * (see routeToWorkflow()) — not one at a time. Each stage is routed to
 * EVERY eligible approver simultaneously (see assignStage()), one
 * DocumentAssignment row each, all sharing the same stage_id. A stage only
 * completes once every one of those rows is non-pending (see
 * completeStage()); a single rejection from ANY of them immediately kills
 * the whole document, same as before. There is no more load-balanced
 * "pick one" step for normal routing — every eligible approver gets a
 * seat regardless (the manual busy/away flag this used to skip was
 * removed entirely; see User::isOnline()/isAvailable() for its
 * replacement, a display-only online signal, not a routing gate).
 * The old one-winner ranking (rankApprovers()) still
 * exists and is still used, but only by findReplacementApprover() for the
 * deactivation-handoff case, which is a genuinely different operation:
 * replacing one lost seat, not routing a stage.
 *
 * Because every stage is assigned up front, a document can have more than
 * one stage pending at once (e.g. Stage 2 assigned to a specialist while
 * Stage 1 is still awaiting a different approver's decision). Stages
 * resolve independently of each other and of sequence order; the document
 * only finalizes once every stage's every seat has been resolved (see
 * completeStage()).
 *
 * ELIGIBILITY: an approver is eligible for a stage if (a) their
 * assigned_category matches the document, and (b) either they have no
 * specific stage restrictions at all (eligible for every stage in their
 * category by default) or this stage is explicitly among their assigned
 * stages (User::workflowStages()). Every eligible approver gets a seat —
 * there is no ranking/selection among them for normal routing.
 *
 * Each assignment's SLA window is 25% of the minutes remaining until the
 * document's own absolute due_date, computed at minute granularity so it
 * scales smoothly rather than in coarse hour jumps. Due dates at or under
 * 1 hour away skip the percentage and get a flat 15-minute window instead.
 * Either way, the window never extends past the due_date itself. Every
 * seat on a stage shares the identical window (computed once per stage,
 * not once per approver), and each seat auto-approves
 * completely independently of its siblings — an Admin override or
 * auto-approval on one seat fills only that seat, not the whole stage.
 */
class WorkflowService
{
    /** Portion of the document's remaining time allotted to each stage's approvers, once past the short-due threshold. */
    private const APPROVER_SLA_FRACTION = 0.25;

    /**
     * Due dates at or under this many minutes away skip the percentage
     * calculation entirely and get a flat SLA window instead (see
     * FIXED_SHORT_DUE_SLA_MINUTES) — 25% of anything that short leaves an
     * approver with only a few minutes, which isn't a workable review
     * window in practice.
     */
    private const SHORT_DUE_THRESHOLD_MINUTES = 60;

    /** Flat SLA window used for due dates at or under the short-due threshold above. */
    private const FIXED_SHORT_DUE_SLA_MINUTES = 15;

    /** Tier 2 upper cap: 25% of remaining time never allots more than this many minutes. */
    private const MAX_APPROVER_SLA_MINUTES = 360;

    /** Below this many extracted characters, treat it as an extraction failure, not "short content". */
    private const MIN_EXTRACTED_CHARS = 40;

    public function __construct(
        private TextExtractionService $extractor,
        private ClassificationService $classifier,
        private ValidationService $validator,
        private BusinessHoursService $businessHours,
        private MalwareScanService $malwareScanner,
        private DocxRichContentService $docxRichContent,
    ) {}

    /**
     * Section 1 (extended): a submitted due date has to fall inside an
     * actual working window (working day, between start/end time) —
     * otherwise the whole approve/auto-approve chain built on top of it
     * (see DocumentAssignment::adminGraceExpiresAt()) inherits a deadline
     * nobody's ever actually working during. Used at submission time
     * (DocumentController::store()/resubmit()) to REJECT an invalid pick
     * outright rather than silently moving it — an in-flight document's
     * due_date can still legitimately shift later via
     * syncDueDatesWithCalendar() below (an admin retroactively closing a
     * day has no "reject" option for something already submitted), but a
     * brand-new submission always has the option of picking a valid date
     * instead.
     */
    public function isDueDateWithinWorkingHours(string $dueDate): bool
    {
        return $this->businessHours->isWithinWorkingWindow(Carbon::parse($dueDate));
    }

    /**
     * Section 1 (extended): when an Admin's calendar edit makes a
     * previously-working day non-working (a new holiday, or unchecking a
     * working-day box), any in-flight document already using that day as
     * its due date needs its deadline pushed forward too — otherwise the
     * document (and its approvers) stay bound to a hard commitment that
     * lands on a day nobody's actually working. Only touches documents
     * still in the pipeline and their still-pending
     * assignments; SLA windows are then re-synced against the (possibly
     * new) due dates via recalculatePendingSlaDeadlines(). Call after
     * AdminController::storeHoliday() — never needed for destroyHoliday(),
     * since removing a holiday only ever frees up days, it never
     * invalidates an existing due date. The working window itself
     * (start/end time, working days) is a fixed config value now, not
     * admin-editable, so nothing else can trigger this anymore.
     *
     * @return array{documents_shifted: int, assignments_recalculated: int}
     */
    public function syncDueDatesWithCalendar(): array
    {
        $shifted = 0;

        DocumentRepository::whereIn('global_status', ['processing', 'classified_validated'])
            ->whereNotNull('due_date')
            ->get()
            ->each(function (DocumentRepository $document) use (&$shifted) {
                $old = Carbon::parse($document->due_date);
                $adjusted = $this->businessHours->nextWorkingDueDate($old);

                if ($adjusted->equalTo($old)) {
                    return;
                }

                $document->due_date = $adjusted;
                $document->save();
                $shifted++;

                DocumentAssignment::where('document_id', $document->document_id)
                    ->where('individual_status', 'pending')
                    ->update(['due_date' => $adjusted]);

                AuditLog::record(null, $document->document_id, 'due_date_adjusted',
                    "Due date {$old->toDayDateTimeString()} now falls on a non-working day after a calendar update; ".
                    "automatically moved to {$adjusted->toDayDateTimeString()}.");

                NotificationRecord::send($document->originator_id, $document->document_id,
                    "The due date for your document '{$document->title}' was moved to {$adjusted->format('M j, Y g:i A')} ".
                    'because the original date became a non-working day.');
            });

        return [
            'documents_shifted' => $shifted,
            'assignments_recalculated' => $this->recalculatePendingSlaDeadlines(),
            // SlaService::recalculatePendingReviewDeadlines() — the
            // review-side counterpart, same reasoning as the pending-SLA
            // sweep just above, just covering assignments already
            // auto-approved and awaiting Admin's own review instead of
            // ones still awaiting an approver's decision. Resolved via
            // app(), not constructor injection: SlaService itself depends
            // on WorkflowService, so a constructor dependency here would
            // be circular (same pattern already used by
            // autoApproveNoEligibleApprover() below).
            'reviews_recalculated' => app(SlaService::class)->recalculatePendingReviewDeadlines(),
        ];
    }

    /**
     * Handles Staff (Originator) document submission end-to-end:
     * Process 3.1 -> 3.2 -> 3.3 -> 3.4 -> 4.0 in one pass.
     *
     * @param  int|null  $batchId  Links this document to the SubmissionBatch
     *                             it was uploaded alongside (Feature: grouped
     *                             approval requests), so the Approver and
     *                             Admin SLA dashboards can nest documents
     *                             submitted together under one container.
     */
    /**
     * @param  DocumentRepository|null  $revisionOf  When set, this upload is
     *                                               a resubmission revising a previously REJECTED document (see
     *                                               DocumentController::resubmit()) rather than a brand new,
     *                                               unrelated submission — links the two into a version chain
     *                                               instead of leaving the rejection as a dead end.
     * @param  string  $routingMode  Feature: originator-directed routing —
     *                               'auto' (default, unchanged): the automatic, ML-category-
     *                               driven pipeline. 'custom': a real, known category, but the
     *                               originator wants to hand-pick the approver(s) instead of the
     *                               full pipeline — classification and validation still run and
     *                               matter exactly as for 'auto', only ROUTING changes (see
     *                               routeOrAwaitApproverSelection()). 'unrelated': the
     *                               originator says this document doesn't belong to any of the
     *                               trained categories — classification still runs (ml_category
     *                               keeps the classifier's best guess, for reference only), but
     *                               it stops gating validation (validateGeneric() applies
     *                               instead of the category template) or driving routing.
     */
    public function ingest(UploadedFile $file, User $originator, string $dueDate, ?int $batchId = null, ?DocumentRepository $revisionOf = null, bool $requiresPrinting = false, string $routingMode = 'auto'): DocumentRepository
    {
        $document = DB::transaction(function () use ($file, $originator, $dueDate, $batchId, $revisionOf, $requiresPrinting, $routingMode) {
            // Default disk (config('filesystems.default')), not hardcoded
            // 'local' — respects FILESYSTEM_DISK, so uploads actually land
            // wherever that's configured (S3-compatible object storage in
            // production, e.g. Cloudflare R2, since local disk doesn't
            // survive a Railway redeploy). Hardcoding 'local' here silently
            // defeated switching FILESYSTEM_DISK entirely — every upload
            // kept landing on local disk regardless of that setting.
            $storedPath = $file->store('documents');

            $document = DocumentRepository::create([
                'originator_id' => $originator->user_id,
                'batch_id' => $batchId,
                'title' => $file->getClientOriginalName(),
                'file_path' => $storedPath,
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'due_date' => $dueDate,
                'global_status' => 'processing',
                'desired_routing' => $routingMode,
                'previous_version_id' => $revisionOf?->document_id,
                'version_number' => $revisionOf ? $revisionOf->version_number + 1 : 1,
                // Purely the originator's own explicit checkbox at upload
                // — see DocumentController::store().
                'requires_printing' => $requiresPrinting,
            ]);

            AuditLog::record($originator->user_id, $document->document_id, 'upload', "Document '{$document->title}' submitted.");

            if ($revisionOf) {
                AuditLog::record($originator->user_id, $document->document_id, 'resubmit',
                    "Resubmitted as version {$document->version_number}, revising rejected document #{$revisionOf->document_id} ('{$revisionOf->title}').");
            }

            // Security check — deliberately first, before extraction/
            // classification ever touch the file's contents. See
            // MalwareScanService's docblock for exactly what this does
            // and doesn't catch (a targeted macro check, not general
            // antivirus).
            $scanResult = $this->malwareScanner->scan($file);
            if (! $scanResult['clean']) {
                return $this->blockForSecurity($document, $originator, $scanResult['reason']);
            }

            // 3.1 + 3.2 — extraction & preprocessing.
            //
            // Deliberately try/catch, not left to bubble up: $file->store()
            // above already wrote the physical file to disk BEFORE this
            // point, and storage writes are not part of this DB::transaction
            // — an uncaught exception here would roll back the
            // DocumentRepository row (and its audit log) while leaving the
            // uploaded file orphaned on disk with no record pointing to it,
            // and hand the originator a raw 500 instead of an explanation.
            // Converging on the exact same persisted "extraction_failed"
            // path already used for genuinely-too-short text below keeps
            // there being exactly one way this failure mode is handled.
            try {
                $extraction = $this->extractor->extract($file);
            } catch (Throwable $e) {
                Log::error('Text extraction threw for document upload', [
                    'originator_id' => $originator->user_id,
                    'file' => $document->title,
                    'mime' => $document->mime_type,
                    'exception' => $e->getMessage(),
                ]);

                return $this->failExtraction($document, $originator, null, $file->getClientOriginalExtension());
            }

            $document->ocr_text = $extraction['text'];
            $document->used_ocr_fallback = $extraction['used_ocr_fallback'];

            // If extraction genuinely failed to produce readable text (as
            // opposed to the document just being short), say so plainly
            // instead of running it through category validation and
            // surfacing a confusing "0 words; minimum 30" message.
            if (mb_strlen(trim($extraction['text'])) < self::MIN_EXTRACTED_CHARS) {
                return $this->failExtraction($document, $originator, $extraction['failure_reason'] ?? null, $file->getClientOriginalExtension());
            }

            // 3.3 — classification. Same reasoning as the extraction
            // try/catch above: a thrown exception here (e.g. a missing or
            // corrupted model file) must not silently roll back a document
            // row that already has a real file sitting in storage.
            try {
                $result = $this->classifier->classify($extraction['text']);
            } catch (Throwable $e) {
                Log::error('Classification threw for document upload', [
                    'originator_id' => $originator->user_id,
                    'file' => $document->title,
                    'exception' => $e->getMessage(),
                ]);

                return $this->failExtraction($document, $originator, null);
            }

            $document->ml_category = $result['category'];
            $document->ml_confidence = $result['confidence'];
            $document->ml_margin = $result['margin'];
            $document->model_id = $result['model_id'];

            // Fully automatic (no manual admin review step exists for
            // classification or readability anymore — see git history for
            // the admin-review-queue version this replaced). The only
            // thing that still stops a document on the classifier's say-so
            // is confidence below the random-chance floor for however many
            // categories are trained (1/N — with 3 categories, ~33.3%):
            // below that, the model's pick carries no more information
            // than guessing, so there's genuinely nothing to route on.
            // Above it, even a low-but-real lead is trusted and routed —
            // margin and readability are recorded for insight but never
            // block anything (see ValidationService::validate()'s
            // docblock for why: both get suppressed by the exact
            // vocabulary-hasn't-been-learned-yet documents that most need
            // to reach training, so gating on either would defeat the
            // point of the automatic retraining loop below).
            //
            // Never applies to an 'unrelated' document (Feature:
            // originator-directed routing) — ml_category there is only
            // ever the classifier's best guess for reference, never
            // authoritative, so there's no confidence bar to clear in the
            // first place.
            $chanceFloor = round(100 / max(1, count(ValidationService::knownCategories())), 2);
            $belowChanceFloor = $routingMode !== 'unrelated' && $result['confidence'] < $chanceFloor;

            AuditLog::record(null, $document->document_id, 'classify',
                "Classified as '{$result['category']}' (confidence {$result['confidence']}%)".
                ($extraction['used_ocr_fallback'] ? ' [OCR fallback used]' : ''));

            // 3.4 — validation. An 'unrelated' document has no real
            // category to validate against (required_sections/readability
            // are both defined per category) — see ValidationService::
            // validateGeneric()'s docblock for the bare sanity check that
            // applies instead. It still gets a real readability score
            // against the classifier's best guess, purely for display —
            // see readabilityAgainst()'s docblock — proving classification
            // and readability genuinely run on every document, not just
            // ones routed through a real category.
            if ($routingMode === 'unrelated') {
                $validation = $this->validator->validateGeneric($extraction['text']);
                $readability = $this->validator->readabilityAgainst($result['category'], $extraction['text']);
                $validation['readability_score'] = $readability['score'];
                $validation['readability_note'] = $readability['note'];
            } else {
                $validation = $this->validator->validate($result['category'], $extraction['text']);
            }
            $document->is_validated = $validation['is_valid'];
            $document->validation_errors = $validation['errors'];
            $document->readability_score = $validation['readability_score'];

            // A confidence guess below the chance floor is rejected
            // outright, regardless of what validation found — there's no
            // real category to route on. The originator resubmits and
            // chooses how it should be routed instead — see
            // DocumentController::resubmit()'s routing_mode option.
            $canRoute = ! $belowChanceFloor && $validation['is_valid'];

            $document->global_status = match (true) {
                $belowChanceFloor => 'rejected',
                $canRoute => 'classified_validated',
                default => 'processing',
            };
            $document->save();

            AuditLog::record(null, $document->document_id, 'validate',
                $validation['is_valid'] ? 'Validation passed.' : 'Validation failed: '.implode('; ', $validation['errors']));

            if ($belowChanceFloor) {
                AuditLog::record(null, $document->document_id, 'classification_ambiguous',
                    "Couldn't confidently classify this document — best guess was '{$result['category']}' at ".
                    "{$result['confidence']}%, too low to trust. Rejected pending the originator's resubmission.");

                NotificationRecord::send($originator->user_id, $document->document_id,
                    "Your document '{$document->title}' doesn't clearly match any of our trained categories ".
                    "(best guess: '{$result['category']}' at {$result['confidence']}%, not confident enough to route ".
                    "automatically). Please resubmit and choose how you'd like it routed.");

                // DocumentRepository::booted() only broadcasts on an UPDATE
                // to global_status/disputed_at — this is a brand new row,
                // so that hook never fires here. Without this, the
                // originator's own tracking page wouldn't pick up this
                // rejection live.
                event(new DocumentStatusChanged($document));
            } elseif ($canRoute) {
                $this->routeOrAwaitApproverSelection($document);
            } else {
                NotificationRecord::send($originator->user_id, $document->document_id,
                    "Your document '{$document->title}' failed validation: ".implode('; ', $validation['errors']));
            }

            return $document->fresh();
        });

        // Dispatched after the transaction commits, not from inside it —
        // this document's own routing outcome doesn't actually matter (the
        // job re-checks overall eligibility across every document, not just
        // this one), but dispatching from inside a transaction that then
        // rolled back would fire for a change that never really happened.
        // See CheckAutoTrainDue's own docblock for why this exists
        // alongside (not instead of) the scheduled sweep.
        CheckAutoTrainDue::dispatch();

        return $document;
    }

    /**
     * Every other "needs an Admin's attention" moment in this app (SLA
     * escalation, a stage auto-approved with no eligible approver) already
     * notifies every active admin — a document held for ML/readability
     * review was the one spot that only ever told the originator, leaving admins with no way
     * to know a new item landed short of manually checking the ML Training
     * page. Deliberately normal priority, not the urgent/red flag those
     * other cases use — nothing about a freshly-held document is on a
     * burning clock the instant it lands, this is the same tone as the
     * routine "new document assigned" notification an approver gets.
     */
    private function notifyAdminsDocumentNeedsReview(DocumentRepository $document, string $message): void
    {
        foreach (User::where('role', 'admin')->where('is_active', true)->get() as $admin) {
            NotificationRecord::send($admin->user_id, $document->document_id, $message);
        }
    }

    /**
     * The single persisted-failure path shared by ingest(): reached either
     * when extraction produced too little text to be usable, or when
     * extraction/classification threw outright (see the try/catch blocks
     * above). Either way, the document row is saved as 'processing' with a
     * clear validation_errors message rather than left to roll back —
     * critically, $document already has a real file in storage by this
     * point (see ingest()'s docblock on the try/catch), so the row must
     * survive to keep pointing at it.
     */
    private function failExtraction(DocumentRepository $document, User $originator, ?string $reason, ?string $extension = null): DocumentRepository
    {
        $document->ml_category = null;
        $document->ml_confidence = 0;
        $document->is_validated = false;
        $document->validation_errors = [$this->extractionFailureMessage($reason, $extension ? strtolower($extension) : null)];
        $document->global_status = 'processing';
        $document->save();

        AuditLog::record(null, $document->document_id, 'extraction_failed',
            'Could not read any usable content from this file, so classification and validation were skipped.');

        NotificationRecord::send($originator->user_id, $document->document_id,
            "Your document '{$document->title}' could not be read by the system. ".$document->validation_errors[0]);

        return $document->fresh();
    }

    /**
     * Reuses the existing 'rejected' global_status (rather than a new enum
     * value — see the is_security_blocked migration's docblock) so the
     * originator's tracker/resubmit flow both already work unmodified;
     * is_security_blocked is what lets the UI show a distinct message and
     * hide the "view original file" option for this document specifically
     * (see DocumentRepositoryPolicy::viewFile()). Never routed — this
     * returns before classification/validation/routeToWorkflow() ever run.
     *
     * The originator only ever sees a generic, non-alarming message —
     * $reason (what was actually detected) is deliberately kept out of
     * both the audit trail (shared with the originator via the Document
     * Tracker) and their notification, and goes only to Admins, who are
     * the ones who might actually need to investigate or judge a false
     * positive.
     */
    private function blockForSecurity(DocumentRepository $document, User $originator, string $reason): DocumentRepository
    {
        $document->global_status = 'rejected';
        $document->is_security_blocked = true;
        $document->save();

        AuditLog::record(null, $document->document_id, 'security_blocked',
            'Blocked by an automated security scan before it reached classification or review.');

        NotificationRecord::send($originator->user_id, $document->document_id,
            "Your document '{$document->title}' could not be accepted — it failed an automatic security scan and was blocked ".
            'before reaching any reviewer. If you believe this is a mistake, you can upload a corrected version.');

        $this->notifyAdminsDocumentNeedsReview($document,
            "'{$document->title}' (uploaded by {$originator->full_name}) was blocked by the security scan: {$reason}.");

        return $document->fresh();
    }

    /**
     * Builds a specific, actionable message for why extraction produced no
     * usable text — reflecting the actual diagnosed cause from
     * TextExtractionService rather than a generic hedge ("may not be
     * installed") when the real cause is already known.
     */
    private function extractionFailureMessage(?string $reason, ?string $extension = null): string
    {
        // Legacy .doc was never given a real digital-extraction path (see
        // TextExtractionService::extract()) — it always falls through to
        // OCR, which fails because OCR reads images, not a Word binary.
        // That's a format .doc was never going to succeed at, not a
        // corrupted/blank/image-format problem, so it gets its own message
        // rather than reusing the OCR-failure wording below.
        if ($extension === 'doc') {
            return 'Legacy .doc files are not supported — please re-save this file as .docx in Word (File > Save As > '.
                'Word Document) and re-upload it.';
        }

        return match ($reason) {
            'ocr_binary_missing' => 'This file needs OCR to read (it looks like a scanned image or non-searchable PDF), but the '.
                'OCR engine is not installed on the server yet — an Administrator needs to install the system '.
                '"tesseract-ocr" package. In the meantime, try re-uploading as a plain text (.txt) or Word (.docx) file instead.',
            'ocr_package_missing' => 'This file needs OCR to read (it looks like a scanned image or non-searchable PDF), but OCR '.
                'support is not installed on this system at all — contact your Administrator, or try re-uploading as '.
                'a plain text (.txt) or Word (.docx) file instead.',
            'ocr_error' => 'OCR was attempted on this file but failed — it may be corrupted, blank, or in an unsupported '.
                'image format. Try re-uploading as a plain text (.txt) or Word (.docx) file instead, or contact your Administrator.',
            default => 'Could not extract readable text from this file. Try re-uploading as a plain text (.txt) or '.
                'Word (.docx) file instead, or contact your Administrator.',
        };
    }

    /**
     * The single decision point for "this document just became ready to
     * route" — called from ingest() once validation/classification first
     * clear, and from AdminController's classification/readability
     * review confirmations once whichever hold THEY were covering
     * clears. A document routed the normal way (desired_routing ===
     * 'auto', the default) goes straight into routeToWorkflow() exactly
     * as before this feature existed. One originator-directed
     * (desired_routing 'custom' or 'unrelated' — see DocumentController::
     * store()) instead waits here for the originator to actually pick
     * approver(s) themselves (see routeToCustomApprovers()) rather than
     * being auto-routed — pending_custom_routing_at records that it's
     * waiting.
     */
    public function routeOrAwaitApproverSelection(DocumentRepository $document): void
    {
        if ($document->desired_routing === 'auto') {
            $this->routeToWorkflow($document);

            return;
        }

        $document->pending_custom_routing_at = now();
        $document->save();

        NotificationRecord::send($document->originator_id, $document->document_id,
            "'{$document->title}' is ready — select the approver(s) you'd like to route it to directly.");

        event(new DocumentStatusChanged($document));
    }

    /**
     * Process 4.0 — Workflow Routing.
     *
     * Every configured stage is assigned to its own single, load-balanced
     * approver immediately at upload time (Feature: all stages routed up
     * front, not one at a time). Stages are processed in sequence_order so
     * that workload counts accumulate correctly within this same routing
     * pass — e.g. if the same approver is picked for stage 1, that pending
     * assignment already counts against them when stage 2 is routed a
     * moment later, so a second eligible approver with less on their plate
     * (or one dedicated to just that stage) can take it instead.
     *
     * A document can therefore have more than one stage pending at once;
     * see completeStage() for how out-of-order resolution and final
     * document approval are handled.
     */
    public function routeToWorkflow(DocumentRepository $document): void
    {
        $this->extendDueDateIfReviewQueueAteTheBuffer($document);

        $stages = WorkflowStage::configured()->forCategory($document->ml_category)->where('is_archived', false)->get();

        if ($stages->isEmpty()) {
            // No configured pipeline for this category — create a single generic stage.
            // document_id explicitly in the search half, not just the create
            // half — without it, this could match (and wrongly reuse) some
            // OTHER document's one-off custom stage that happens to share
            // this same guessed category (see routeToCustomApprovers()).
            $stages = collect([WorkflowStage::firstOrCreate(
                ['document_category' => $document->ml_category, 'sequence_order' => 1, 'document_id' => null],
                ['stage_name' => 'General Review']
            )]);
        }

        // Computed ONCE for the whole document, not once per stage — every
        // stage is routed together in the loop below (see class docblock),
        // so every approver across every stage of this document shares
        // this exact same deadline, guaranteed, rather than each stage
        // separately calling now() a few milliseconds apart.
        $slaExpiresAt = $this->computeApproverSlaExpiry($document);

        // Final Approval is the last word, so it is NOT opened alongside the
        // review stages — it opens only once every one of them is approved
        // (see openFinalApprovalIfReady(), called from completeStage()), so
        // the Head Approver's sign-off really is the final check. A category
        // whose only stage is Final Approval has nothing to wait for and is
        // routed immediately, like any other stage.
        $reviewStages = $stages->reject(fn (WorkflowStage $stage) => $this->isFinalApprovalStage($stage));
        if ($reviewStages->isNotEmpty()) {
            $stages = $reviewStages->values();
        }

        // Deferred (autoApproveImmediately: false) — every stage needs its
        // own assignment row created FIRST, so completeStage()'s "is
        // anything else still pending" check (run when the no-approver
        // resolution below completes it) sees the real, whole picture
        // instead of just whichever stages this loop had reached so far.
        // See assignStage()'s own docblock.
        $noApproverAssignments = collect();
        foreach ($stages as $stage) {
            $assignment = $this->assignStage($document, $stage, $slaExpiresAt, autoApproveImmediately: false);
            if ($assignment) {
                $noApproverAssignments->push($assignment);
            }
        }

        foreach ($noApproverAssignments as $assignment) {
            $this->autoApproveNoEligibleApprover($assignment, 'nobody is currently assigned to this category/stage.');
        }
    }

    /**
     * A document flagged for originator-directed routing ('custom' or
     * 'unrelated' — see ingest()'s $routingMode docblock) can sit waiting
     * on the originator's own approver pick for an unpredictable amount of
     * time before ever reaching routeToWorkflow(). due_date was only ever
     * validated against the ORIGINAL upload moment (see
     * DocumentController::store()'s business-hours check), so if that wait
     * ate into the runway, the approver about to be assigned could inherit
     * a deadline that's already unrealistically close — or even already
     * passed — through no fault of their own or the originator's. Restores
     * the exact same minimum buffer upload itself guarantees, rather than
     * silently handing the approver a broken countdown. A no-op for the
     * fully automatic path, since routeToWorkflow() runs there within the
     * same request as the already-validated upload — there's never a
     * realistic gap to close in that case.
     */
    private function extendDueDateIfReviewQueueAteTheBuffer(DocumentRepository $document): void
    {
        if (! $document->due_date) {
            return;
        }

        $minBuffer = config('sla.min_due_date_buffer_minutes', 60);
        $realMinutesLeft = $this->businessHours->businessSecondsRemaining(now(), Carbon::parse($document->due_date)) / 60;

        if ($realMinutesLeft >= $minBuffer) {
            return;
        }

        $oldDueDate = Carbon::parse($document->due_date);
        $newDueDate = $this->businessHours->addBusinessMinutes(now(), $minBuffer);
        $document->due_date = $newDueDate;
        $document->save();

        AuditLog::record(null, $document->document_id, 'due_date_extended',
            "Due date extended from {$oldDueDate->toDayDateTimeString()} to {$newDueDate->toDayDateTimeString()} ".
            '— review-queue processing time had left an approver with less than the minimum realistic window to act.');

        NotificationRecord::send($document->originator_id, $document->document_id,
            "Your document '{$document->title}' had its due date extended to {$newDueDate->format('M j, Y g:i A')} ".
            'because admin review processing left too little time for an approver to realistically act on it.');
    }

    /**
     * Every eligible approver for a specific stage: matching category,
     * active account, department alignment (see below), and either
     * unrestricted (no specific stage picks — eligible for every stage in
     * their category by default) or explicitly assigned to this stage.
     * assignStage() gives every one of these a seat; only
     * findReplacementApprover() further narrows this pool down to a single
     * winner (via rankApprovers()).
     *
     * Department check: a stage with no WorkflowStageDepartment rows at all
     * is unrestricted by department (same backward-compatible convention as
     * the stage-restriction check below) — this only ever narrows an
     * already-explicit stage assignment, never silently blocks a stage that
     * hasn't been tagged with department ownership yet. Where a stage DOES
     * have department rows, an approver's own department must be among
     * them — this is deliberately redundant with the account-creation-time
     * validation in AdminController (only stages belonging to the chosen
     * department are ever offered/accepted there); this exists as a
     * second, independent guarantee at the point routing actually happens,
     * not because the first check is expected to fail.
     *
     * Public (not just used internally by eligibleApproversForCategory()
     * above) — DocumentController::selectApprovers() also calls this
     * directly, once per configured stage, to build the Category -> Stage
     * -> Approver picker for originator-directed "custom" routing, instead
     * of the deduped, stage-agnostic union eligibleApproversForCategory()
     * returns.
     *
     * Head-only on a stage literally named "Final Approval" (Feature: only
     * a department head signs off on final approval — see User::LEVELS'
     * docblock: "head = sits on a category's Final Approval stage," which
     * this now actually enforces instead of just describing). Matched by
     * name, deliberately NOT "whichever stage happens to be last in the
     * category's sequence" — that broader position-based reading was
     * tried first and measurably wrong: plenty of test (and potentially
     * real) categories have exactly one stage for an unrelated reason,
     * which would make that one stage "final" by construction and
     * silently require a head for it too, well beyond what was actually
     * being asked for. Matching the real, literal stage name every
     * category's true final stage already carries today stays narrowly
     * scoped to the actual concern. Every other stage is unaffected;
     * level has never gated those.
     *
     * Final Approval is ALSO category-agnostic for heads (confirmed bug,
     * fixed 2026-10-03: every category's Final Approval stage requires a
     * head, but assigned_category + per-stage picks together meant a head
     * set up for Job Order was silently ineligible for every other
     * category's Final Approval — those documents auto-approved with
     * nobody actually reviewing them). A department head's authority isn't
     * scoped to one category's pipeline the way a staff approver's is, so
     * BOTH the assigned_category query filter and the explicit-stage-picks
     * check below are skipped for Final Approval — heads are matched on
     * level + department alone, full stop, same person for every category.
     */
    public function eligibleApproversForStage(string $category, WorkflowStage $stage): Collection
    {
        $stageDepartments = $stage->departmentNames();
        $isFinalApprovalStage = $stage->stage_name === 'Final Approval';

        $query = User::where('role', 'approver')->where('is_active', true);

        if (! $isFinalApprovalStage) {
            $query->where('assigned_category', $category);
        }

        return $query->get()
            ->filter(function (User $approver) use ($stage, $stageDepartments, $isFinalApprovalStage) {
                if ($isFinalApprovalStage && $approver->level !== 'head') {
                    return false;
                }

                if ($stageDepartments !== [] && ! in_array($approver->department, $stageDepartments, true)) {
                    return false;
                }

                if ($isFinalApprovalStage) {
                    return true;
                }

                $assignedStageIds = $approver->workflowStages()->pluck('workflow_stages.stage_id');

                // No explicit stage picks -> eligible for every stage in their category (default).
                return $assignedStageIds->isEmpty() || $assignedStageIds->contains($stage->stage_id);
            })
            ->values();
    }

    /**
     * Workload-balanced ranking, used only by findReplacementApprover()
     * (the deactivation-handoff case) — normal stage routing no longer
     * picks a single winner at all (see assignStage()), so this only ever
     * ranks a candidate pool that's already been filtered down to one lost
     * seat's replacement options.
     *
     * Ties in workload are broken by fairness, not by an arbitrary ID:
     * whichever tied approver's most recent assignment (of any status)
     * happened longest ago gets this one; an approver who has never
     * received an assignment is treated as having waited the longest and
     * wins the tie outright.
     *
     * @param  Collection<int, User>  $candidates
     * @return Collection<int, User> ranked best-first; empty if $candidates was empty
     */
    private function rankApprovers(Collection $candidates): Collection
    {
        if ($candidates->isEmpty()) {
            return collect();
        }

        $userIds = $candidates->pluck('user_id');

        $workloads = DocumentAssignment::whereIn('user_id', $userIds)
            ->where('individual_status', 'pending')
            ->selectRaw('user_id, count(*) as active_count')
            ->groupBy('user_id')
            ->pluck('active_count', 'user_id');

        $lastAssignedAt = DocumentAssignment::whereIn('user_id', $userIds)
            ->selectRaw('user_id, MAX(created_at) as last_assigned_at')
            ->groupBy('user_id')
            ->pluck('last_assigned_at', 'user_id');

        $ranked = $candidates->values()->all();
        usort($ranked, function (User $a, User $b) use ($workloads, $lastAssignedAt) {
            $countA = (int) ($workloads[$a->user_id] ?? 0);
            $countB = (int) ($workloads[$b->user_id] ?? 0);
            if ($countA !== $countB) {
                return $countA <=> $countB;
            }

            // Tie on workload: fairness tie-break by who's waited longest
            // since their last assignment (never-assigned sorts first).
            $lastA = $lastAssignedAt[$a->user_id] ?? null;
            $lastB = $lastAssignedAt[$b->user_id] ?? null;

            if ($lastA === null && $lastB === null) {
                return $a->user_id <=> $b->user_id; // final deterministic fallback
            }
            if ($lastA === null) {
                return -1;
            }
            if ($lastB === null) {
                return 1;
            }

            return strcmp($lastA, $lastB);
        });

        return collect($ranked);
    }

    /**
     * True when $assignment is an earlier (non-Final) stage of a document
     * whose Final Approval has not been decided by a human yet — either not
     * opened at all, or opened with a seat still pending. An auto-approval at
     * such a stage does not need its own Admin review: the Head Approver who
     * signs off on Final Approval checks the whole document, so that
     * sign-off IS the review. See settleDeferredAutoApprovalReviews() for
     * how the deferred review is later covered or reinstated.
     */
    public function awaitingHeadSignOff(DocumentAssignment $assignment): bool
    {
        $stage = $assignment->stage;

        // Custom-routed documents use a one-off stage and never reach the
        // category's Final Approval — nobody signs off after them.
        if ($stage->document_id || $this->isFinalApprovalStage($stage)) {
            return false;
        }

        $document = $assignment->document;
        $finalSeats = DocumentAssignment::where('document_id', $document->document_id)
            ->whereHas('stage', fn ($query) => $query->where('stage_name', 'Final Approval'))
            ->get();

        if ($finalSeats->isNotEmpty()) {
            return $finalSeats->contains(fn (DocumentAssignment $seat) => $seat->individual_status === 'pending');
        }

        return WorkflowStage::configured()
            ->forCategory($document->ml_category)
            ->where('is_archived', false)
            ->where('stage_name', 'Final Approval')
            ->exists();
    }

    /**
     * Runs when the Final Approval stage resolves. Earlier stages the system
     * auto-approved were spared their own Admin review (see
     * awaitingHeadSignOff()); now it's known whether a human actually
     * checked the document:
     *
     *  - Every Final Approval seat was decided by a Head Approver
     *    (approved or rejected): that sign-off covers the earlier
     *    auto-approvals, so they are closed out as reviewed — no Admin
     *    review, no late-review violation.
     *  - Any Final Approval seat was itself auto-approved: nobody human
     *    checked anything after the earlier stages, so the Admin review the
     *    earlier auto-approvals were spared is reinstated (with the normal
     *    review window), alongside the review the Final seat already owes.
     *
     * The SLA Violation logged against whichever approver missed their own
     * window is untouched either way — only the Admin's follow-up changes.
     */
    private function settleDeferredAutoApprovalReviews(DocumentRepository $document): void
    {
        $finalSeats = DocumentAssignment::where('document_id', $document->document_id)
            ->whereHas('stage', fn ($query) => $query->where('stage_name', 'Final Approval'))
            ->get();

        if ($finalSeats->isEmpty() || $finalSeats->contains(fn (DocumentAssignment $seat) => $seat->individual_status === 'pending')) {
            return;
        }

        $deferred = DocumentAssignment::where('document_id', $document->document_id)
            ->where('auto_approved', true)
            ->whereNull('admin_reviewed_at')
            ->whereNull('review_due_at')
            ->whereHas('stage', fn ($query) => $query->where('stage_name', '!=', 'Final Approval'))
            ->with('stage')
            ->get();

        if ($deferred->isEmpty()) {
            return;
        }

        if ($finalSeats->contains(fn (DocumentAssignment $seat) => $seat->auto_approved)) {
            $sla = app(SlaService::class);
            $deferred->each(function (DocumentAssignment $assignment) use ($sla) {
                $assignment->review_due_at = $sla->reviewDeadlineFor($assignment);
                $assignment->save();
            });

            return;
        }

        $deferred->each(function (DocumentAssignment $assignment) {
            $assignment->admin_reviewed_at = now();
            $assignment->admin_review_outcome = 'covered';
            $assignment->admin_review_note = 'Covered by the Head Approver\'s sign-off on Final Approval.';
            $assignment->save();
        });

        AuditLog::record(null, $document->document_id, 'review_covered',
            "Auto-approved stage(s) '{$deferred->pluck('stage.stage_name')->implode("', '")}' covered by the Head Approver's ".
            'sign-off on Final Approval — no separate Admin review needed.');
    }

    /** Final Approval is matched by name, same as the Head-only rule in eligibleApproversForStage(). */
    private function isFinalApprovalStage(WorkflowStage $stage): bool
    {
        return $stage->stage_name === 'Final Approval';
    }

    /**
     * Opens the category's Final Approval stage once every other stage of the
     * document has been resolved. Called from completeStage() each time a
     * stage completes; a no-op until the last review seat is decided (or
     * auto-approved), and a no-op if the stage was already opened — which is
     * also why a document routed before this rule existed, whose Final
     * Approval seats were created up front, is unaffected.
     *
     * Its SLA window is computed at THIS moment from the working time still
     * left before the due date (see tieredApproverSlaMinutes()), not reused
     * from routing time, and never shorter than FIXED_SHORT_DUE_SLA_MINUTES
     * working minutes — if earlier stages ran late, the Head still gets a
     * real chance to decide rather than the seat auto-approving on the
     * spot, which would let the last human check be bypassed by lateness.
     */
    private function openFinalApprovalIfReady(DocumentRepository $document, WorkflowStage $completedStage): void
    {
        // A one-off, document-scoped stage (originator-directed custom routing)
        // IS that document's whole pipeline — it never goes through the
        // category's own Final Approval stage.
        if ($completedStage->document_id || $document->global_status === 'rejected') {
            return;
        }

        $anyPending = DocumentAssignment::where('document_id', $document->document_id)
            ->where('individual_status', 'pending')
            ->exists();

        if ($anyPending) {
            return;
        }

        $finalStages = WorkflowStage::configured()
            ->forCategory($document->ml_category)
            ->where('is_archived', false)
            ->get()
            ->filter(fn (WorkflowStage $stage) => $this->isFinalApprovalStage($stage));

        foreach ($finalStages as $finalStage) {
            $alreadyOpened = DocumentAssignment::where('document_id', $document->document_id)
                ->where('stage_id', $finalStage->stage_id)
                ->exists();

            if ($alreadyOpened) {
                continue;
            }

            $this->assignStage($document, $finalStage, $this->finalApprovalSlaExpiry($document, now()));
        }
    }

    /**
     * The 25% Rule, with a short-due exception: due dates more than 1 WORKING
     * hour away get 25% of the remaining working time as the approvers' SLA
     * window (computed in minutes, not whole hours, so it stays proportional
     * rather than collapsing to a flat value for anything under ~10
     * hours). Due dates at or under 1 working hour away skip the percentage
     * entirely and get a flat 15-minute window instead — 25% of a due date
     * that close would only be a few minutes, not a workable review
     * window. Either way, the window is still clamped to never extend past
     * the document's own absolute due date.
     */
    /**
     * The tiered-percentage formula, factored out from computeApproverSlaExpiry()
     * so recalculateAssignmentSlaExpiry() below can reproduce the exact same
     * budget from a fixed historical anchor instead of "now".
     *
     * Tier 1 (<=60min remaining): flat 15-minute window. Tier 2 (>60min
     * remaining): 25% of remaining, capped at 6 hours — SLA = min(max(calculated,
     * 15m), 6h). The max(...,15) is a no-op in Tier 2 since 25% of >60min is
     * always >15min already; it's kept to match the formula literally.
     */
    private function tieredApproverSlaMinutes(Carbon $anchor, Carbon $dueDate): int
    {
        // WORKING minutes left, not wall-clock minutes: the window this feeds is
        // itself consumed only during working hours (see addBusinessMinutes()), so
        // measuring the remaining time on the same clock is what keeps a chain of
        // windows (parallel stages, then Final Approval) inside the time that
        // actually exists before the due date. A wall-clock diff counts the night
        // as available time and can hand the first group a window that runs
        // right up to the due date, leaving nothing for the stage after it.
        // 0 once the due date has passed, which lands in the flat short-due tier.
        $totalMinutesLeft = intdiv($this->businessHours->businessSecondsRemaining($anchor, $dueDate), 60);

        return $totalMinutesLeft <= self::SHORT_DUE_THRESHOLD_MINUTES
            ? self::FIXED_SHORT_DUE_SLA_MINUTES
            : min(self::MAX_APPROVER_SLA_MINUTES, max(self::FIXED_SHORT_DUE_SLA_MINUTES, (int) round($totalMinutesLeft * self::APPROVER_SLA_FRACTION)));
    }

    /**
     * The window Final Approval gets when it opens at $openedAt: the same tiered
     * window as any other stage, but never shorter than the flat 15 working
     * minutes — the Head needs a workable review window even when the earlier
     * stages ate most of the time before the due date. Public so the
     * "Est. Approval by" forecast can project the window of a Final Approval that
     * has not opened yet using the exact rule that will apply when it does.
     */
    public function finalApprovalSlaExpiry(DocumentRepository $document, Carbon $openedAt): Carbon
    {
        $expiry = $this->computeApproverSlaExpiry($document, $openedAt);
        $minimum = $this->businessHours->addBusinessMinutes($openedAt, self::FIXED_SHORT_DUE_SLA_MINUTES);

        return $expiry->lessThan($minimum) ? $minimum : $expiry;
    }

    /**
     * The window the review stages get when routed at $routedAt. Public for the
     * same reason as finalApprovalSlaExpiry(): the forecast projects it for a
     * document that has not been routed yet.
     */
    public function reviewSlaExpiry(DocumentRepository $document, Carbon $routedAt): Carbon
    {
        return $this->computeApproverSlaExpiry($document, $routedAt);
    }

    private function computeApproverSlaExpiry(DocumentRepository $document, ?Carbon $anchor = null): Carbon
    {
        $anchor ??= now();
        $dueDate = Carbon::parse($document->due_date);
        $approverSlaMinutes = $this->tieredApproverSlaMinutes($anchor, $dueDate);

        // Business-hours-aware: the window is consumed only during
        // configured working hours/days, skipping holidays — see
        // BusinessHoursService.
        $slaExpiresAt = $this->businessHours->addBusinessMinutes($anchor, $approverSlaMinutes);

        // Safety guard: never let the approver's window extend past the
        // document's own absolute due date.
        if ($slaExpiresAt->greaterThan($dueDate)) {
            $slaExpiresAt = $dueDate->copy();
        }

        return $slaExpiresAt;
    }

    /**
     * Section 1: recomputes ONE pending assignment's SLA deadline against
     * the CURRENT business-hours/holiday calendar, holding its originally
     * granted minute budget and grant time (created_at) fixed. Without
     * this, sla_expires_at is "computed once, stored statically" (by
     * design — see class docblock) and an Admin marking a day off *after*
     * a document was already routed would silently leave every affected
     * deadline stale until the next document happens to be uploaded.
     */
    public function recalculateAssignmentSlaExpiry(DocumentAssignment $assignment): Carbon
    {
        $dueDate = Carbon::parse($assignment->due_date);
        $anchor = $assignment->created_at->copy();
        $minutes = $this->tieredApproverSlaMinutes($anchor, $dueDate);
        $expiresAt = $this->businessHours->addBusinessMinutes($anchor, $minutes);

        if ($expiresAt->greaterThan($dueDate)) {
            $expiresAt = $dueDate->copy();
        }

        return $expiresAt;
    }

    /**
     * Re-syncs every still-pending assignment's SLA deadline against the
     * current calendar. Call after any SlaHoliday change — see
     * AdminController::storeHoliday()/destroyHoliday(). Already-decided
     * (including auto-approved) assignments are left alone — recalculating
     * a deadline that no longer applies would be meaningless.
     * If recalculation pushes a deadline into the past, it's simply
     * overdue already; the next workflow:check-parallel-slas sweep will
     * auto-approve it exactly as it would any other lapsed assignment —
     * this method never auto-approves directly.
     *
     * @return int number of assignments whose deadline actually changed
     */
    public function recalculatePendingSlaDeadlines(): int
    {
        $changed = 0;

        DocumentAssignment::where('individual_status', 'pending')
            ->with(['stage', 'document'])
            ->get()
            ->each(function (DocumentAssignment $assignment) use (&$changed) {
                $newExpiry = $this->recalculateAssignmentSlaExpiry($assignment);

                if ($newExpiry->equalTo($assignment->sla_expires_at)) {
                    return;
                }

                $old = $assignment->sla_expires_at;
                $assignment->sla_expires_at = $newExpiry;
                $assignment->save();
                $changed++;

                // Re-dispatch for the new deadline — the job scheduled for
                // the old deadline will still fire at its original time,
                // but its staleness guard will see this new sla_expires_at
                // and no-op instead of escalating early/wrongly.
                EscalateAssignmentJob::dispatch($assignment->assignment_id, $newExpiry)->delay($newExpiry);

                AuditLog::record(null, $assignment->document_id, 'sla_recalculated',
                    "Stage '{$assignment->stage->stage_name}' SLA deadline recalculated from ".
                    "{$old->toDayDateTimeString()} to {$newExpiry->toDayDateTimeString()} after a business-hours/holiday calendar update.");

                NotificationRecord::send($assignment->user_id, $assignment->document_id,
                    "The SLA deadline for '{$assignment->document->title}' (stage '{$assignment->stage->stage_name}') ".
                    "changed to {$newExpiry->format('M j, Y g:i A')} after an update to the business-hours calendar.");
            });

        return $changed;
    }

    /**
     * Creates one DocumentAssignment PER eligible approver for this stage
     * (Feature: unanimous approval — every eligible approver must sign off
     * before the stage completes; see completeStage()). $slaExpiresAt is
     * supplied by the caller rather than computed here, so that when every
     * stage of a document is routed together (the normal case — see
     * routeToWorkflow()), every approver on every stage shares the exact
     * same deadline as a guarantee, not as an accident of how fast the
     * routing loop happens to run. Every eligible approver gets a seat
     * regardless — there is no busy/away flag to skip anymore.
     */
    /**
     * $autoApproveImmediately=false defers resolving a "nobody eligible"
     * stage back to the caller (returned, not auto-approved here) — used
     * by routeToWorkflow()'s own loop, which calls this once per
     * configured stage. Resolving immediately from inside that loop would
     * let completeStage()'s "is anything else still pending" check see
     * only whichever stages had been created SO FAR in the loop, and
     * wrongly conclude the whole document was done after just the first
     * stage, before later stages even got their own assignment rows.
     * completeStage()'s own "safety net" call site (a stage discovered
     * after the fact, one at a time, never part of a batch) keeps the
     * default of resolving right away, since that hazard doesn't apply
     * there.
     */
    private function assignStage(DocumentRepository $document, WorkflowStage $stage, Carbon $slaExpiresAt, bool $autoApproveImmediately = true): ?DocumentAssignment
    {
        $approvers = $this->eligibleApproversForStage($document->ml_category, $stage);

        if ($approvers->isEmpty()) {
            // Nobody is currently eligible for this stage — auto-approve
            // it rather than parking it in a queue waiting for an admin
            // or an SLA deadline (see autoApproveNoEligibleApprover()).
            $assignment = DocumentAssignment::create([
                'document_id' => $document->document_id,
                'stage_id' => $stage->stage_id,
                'user_id' => null,
                'due_date' => $document->due_date,
                'priority_rank' => $this->computePriority($document->due_date),
                'individual_status' => 'pending',
                'sla_expires_at' => $slaExpiresAt,
            ]);

            if ($autoApproveImmediately) {
                $this->autoApproveNoEligibleApprover($assignment,
                    'nobody is currently assigned to this category/stage.');
            }

            return $assignment;
        }

        $this->createAssignmentsForApprovers($document, $stage, $approvers, $slaExpiresAt,
            "Stage '{$stage->stage_name}' assigned to {$approvers->count()} eligible approver(s) ".
            "({$document->ml_category}) — {$approvers->pluck('full_name')->implode(', ')}. ".
            "Each must respond by {$slaExpiresAt->toDayDateTimeString()}.");

        return null;
    }

    /**
     * Auto-approves $assignment immediately because nobody is (or was)
     * eligible for it — shared by assignStage()'s "no eligible approver
     * at all" branch and autoApproveDeactivatedSeat()'s "approver
     * deactivated with no replacement" branch. Reuses SlaService::
     * autoApproveNoEligibleApprover() — same underlying autoApproveOne()
     * mechanism a real approver who misses their own deadline gets (so
     * the same post-hoc admin review notification/queue already fires),
     * plus the matching AdminViolation entry for the SLA Violations
     * report. Resolved via app(), not constructor injection: SlaService
     * itself depends on WorkflowService, so a constructor dependency here
     * would be circular.
     */
    private function autoApproveNoEligibleApprover(DocumentAssignment $assignment, string $reason): void
    {
        AuditLog::record(null, $assignment->document_id, 'auto_approve_no_approver',
            "Stage '{$assignment->stage->stage_name}' has no eligible approver — ".
            "{$reason} Auto-approved immediately; an Admin will still review it.");

        app(SlaService::class)->autoApproveNoEligibleApprover($assignment);
    }

    /**
     * The actual per-seat work shared by every way a stage gets its
     * approvers — automatic, eligibility-driven routing (assignStage()
     * above) and an originator's own hand-picked selection
     * (routeToCustomApprovers() below) both end up here, since from this
     * point on a seat is a seat regardless of how its holder was chosen.
     *
     * Wrapped in its own transaction — this creates one DocumentAssignment
     * row per approver in a loop; a failure partway through (e.g. seat 2
     * of 3) would otherwise leave a stage only PARTIALLY routed, with no
     * error surfaced to explain why some approvers never got a seat. A
     * nested transaction is safe here regardless of whether the caller
     * already has one open — Laravel uses a savepoint for the inner one.
     *
     * @param  Collection<int, User>  $approvers
     */
    private function createAssignmentsForApprovers(DocumentRepository $document, WorkflowStage $stage, Collection $approvers, Carbon $slaExpiresAt, string $auditMessage): void
    {
        $priorityRank = $this->computePriority($document->due_date);

        DB::transaction(function () use ($document, $stage, $slaExpiresAt, $approvers, $priorityRank, $auditMessage) {
            foreach ($approvers as $approver) {
                $assignment = DocumentAssignment::create([
                    'document_id' => $document->document_id,
                    'user_id' => $approver->user_id,
                    'stage_id' => $stage->stage_id,
                    'due_date' => $document->due_date,
                    'priority_rank' => $priorityRank,
                    'individual_status' => 'pending',
                    'sla_expires_at' => $slaExpiresAt,
                ]);

                // True event-driven escalation (Section 4/5): fires at the exact
                // deadline instant instead of waiting for the next periodic sweep —
                // see EscalateAssignmentJob's docblock for the staleness guard that
                // makes this safe across later recalculation. Each seat auto-approves
                // completely independently of its siblings.
                EscalateAssignmentJob::dispatch($assignment->assignment_id, $slaExpiresAt)->delay($slaExpiresAt);

                NotificationRecord::send($approver->user_id, $document->document_id,
                    "New document assigned for '{$stage->stage_name}': {$document->title}.");

                // A SEPARATE, extra-urgent notification for assignments born
                // with an already-tight window — reuses the exact "Urgent"
                // threshold (urgencyRank() === 1, 30 minutes or less of real
                // remaining time) already shown as a badge everywhere this
                // assignment appears, rather than a new, separately-invented
                // number. A short-due-date document's flat 15-minute SLA
                // window (see tieredApproverSlaMinutes()) always qualifies —
                // this makes sure the approver is actively told the instant it
                // happens instead of only seeing a badge if they happen to
                // check their queue in time.
                if ($assignment->urgencyRank() === 1) {
                    NotificationRecord::send($approver->user_id, $document->document_id,
                        "URGENT: '{$document->title}' (stage '{$stage->stage_name}') has a very short window to act — ".
                        'please review it now.',
                        'high');
                }
            }

            AuditLog::record(null, $document->document_id, 'route', $auditMessage);
        });
    }

    /**
     * Feature: originator-directed approval routing — the originator
     * hand-picks the approver(s) for THIS document instead of letting
     * the automatic, category-driven pipeline decide (see
     * routeToWorkflow()). Creates one document-scoped WorkflowStage
     * (document_id set — see that column's migration docblock) with
     * exactly the chosen approvers as its seats, then reuses the exact
     * same assignment/notification/SLA/escalation machinery every other
     * stage already goes through via createAssignmentsForApprovers() —
     * from DocumentAssignment/completeStage()'s point of view this is an
     * ordinary stage, it just happens to belong to one document rather
     * than a whole category. Approval still needs every seat to agree;
     * a rejection still needs a majority (DocumentAssignment::
     * stageRejectionStatus() already generalizes to any seat count) —
     * no separate voting logic needed for this path.
     *
     * $approverIds is trusted here — the caller (DocumentController::
     * routeCustom()) is responsible for validating each id is a real,
     * active approver actually eligible for this document (or, for a
     * document flagged desired_routing 'unrelated', any active approver at
     * all — see eligibleApproversForCategory()'s docblock).
     *
     * @param  array<int>  $approverIds
     */
    /**
     * What to call the one-off stage routeToCustomApprovers() creates —
     * the real stage(s) the picked approvers are actually tied to, not a
     * generic placeholder. A Head never has a specific stage pick of
     * their own (see eligibleApproversForStage()'s docblock — they're
     * eligible for Final Approval by LEVEL, not by being assigned to it,
     * same reason they're never offered a stage checklist on the Create
     * Account form), so that case is named directly rather than falling
     * through to the fallback below, which used to wrongly catch it (a
     * real reported bug: picking a Head showed "Direct Approval" instead
     * of "Final Approval"). A Staff approver with no stage restriction of
     * their own still contributes nothing here (they're eligible for a
     * category's whole pipeline, not one named stage), so this only ever
     * falls back to "Direct Approval" when NONE of the picked approvers
     * have a specific stage to point to AND none of them are a Head.
     */
    private function deriveCustomStageName(Collection $approvers): string
    {
        $stageNames = $approvers
            ->flatMap(fn (User $approver) => $approver->isHead()
                ? collect(['Final Approval'])
                : $approver->workflowStages()->pluck('stage_name'))
            ->unique()
            ->values();

        return $stageNames->isNotEmpty() ? $stageNames->implode(', ') : 'Direct Approval';
    }

    public function routeToCustomApprovers(DocumentRepository $document, array $approverIds, User $originator): void
    {
        $this->extendDueDateIfReviewQueueAteTheBuffer($document);

        $approvers = User::where('role', 'approver')->where('is_active', true)->whereIn('user_id', $approverIds)->get();

        $stage = WorkflowStage::create([
            'document_id' => $document->document_id,
            'document_category' => $document->ml_category,
            'stage_name' => $this->deriveCustomStageName($approvers),
            'sequence_order' => 1,
        ]);

        $slaExpiresAt = $this->computeApproverSlaExpiry($document);

        // Document title left out here (unlike a plain description on its
        // own) — everywhere this shows, the title is already visible right
        // next to it: the document's own header on its tracker page, or the
        // Document column of the global Admin Audit Trail row it's nested
        // under. Repeating it just added noise.
        $votingNote = $approvers->count() > 1
            ? ' Approval needs every one of them to agree; a rejection needs a majority.'
            : '';
        $this->createAssignmentsForApprovers($document, $stage, $approvers, $slaExpiresAt,
            "Routed directly by {$originator->full_name} to {$approvers->count()} selected ".
            "approver(s), skipping the standard approval steps — {$approvers->pluck('full_name')->implode(', ')}. ".
            "Must be approved by {$slaExpiresAt->toDayDateTimeString()}.{$votingNote}");

        $document->pending_custom_routing_at = null;
        $document->custom_routed = true;
        $document->save();

        event(new DocumentStatusChanged($document));
    }

    /**
     * Every approver eligible for AT LEAST ONE configured stage of
     * $category — the pool an originator picks from when routing a
     * known-category document directly (routeToCustomApprovers()). A
     * union across every stage rather than one specific stage's own
     * eligible list (eligibleApproversForStage()), since the originator
     * is choosing who handles the WHOLE document, not seating one
     * particular stage. Falls back to every active approver assigned to
     * the category at all if it has no configured pipeline yet (mirrors
     * routeToWorkflow()'s own "no configured pipeline" fallback).
     *
     * @return Collection<int, User>
     */
    public function eligibleApproversForCategory(string $category): Collection
    {
        $stages = WorkflowStage::configured()->forCategory($category)->where('is_archived', false)->get();

        if ($stages->isEmpty()) {
            return User::where('role', 'approver')->where('is_active', true)->where('assigned_category', $category)->get();
        }

        return $stages
            ->flatMap(fn (WorkflowStage $stage) => $this->eligibleApproversForStage($category, $stage))
            ->unique('user_id')
            ->values();
    }

    /**
     * Deactivation handoff (Feature) — the one place that still picks a
     * SINGLE replacement (rankApprovers()), because this is
     * replacing one lost seat, not routing a stage — normal routing now
     * assigns every eligible approver at once (see assignStage()), so this
     * candidate pool can legitimately include siblings who already hold
     * their OWN independent seat on this exact (document, stage) — newly
     * possible now that more than one approver can be assigned to a stage
     * at all. Excluding them keeps one person from ending up holding two
     * rows for the same stage. Returns null if nobody is eligible (e.g.
     * the deactivated approver was the only one for this stage) — the
     * caller (AdminController::toggleUser()) falls back to
     * SlaService::escalateForReassignmentFailure() in that case.
     */
    public function findReplacementApprover(DocumentAssignment $assignment): ?User
    {
        $alreadyHoldingASeat = DocumentAssignment::where('document_id', $assignment->document_id)
            ->where('stage_id', $assignment->stage_id)
            ->where('assignment_id', '!=', $assignment->assignment_id)
            ->pluck('user_id');

        $candidates = $this->eligibleApproversForStage($assignment->document->ml_category, $assignment->stage)
            ->reject(fn (User $approver) => $alreadyHoldingASeat->contains($approver->user_id))
            ->values();

        return $this->rankApprovers($candidates)->first();
    }

    /**
     * Whether $assignment has at least one sibling seat on the exact same
     * (document, stage) that still represents a REAL approver — every
     * OTHER eligible approver already got their own seat when the stage
     * was first routed (see assignStage()), which is why
     * findReplacementApprover() above almost never finds anyone: the only
     * people who'd qualify as a genuine replacement are approvers who
     * became eligible AFTER routing. AdminController::toggleUser() uses
     * this to decide, once no replacement was found, whether the vacated
     * seat can simply be withdrawn (a sibling already covers this stage —
     * see withdrawAssignment()) or truly needs an Admin (no sibling at
     * all — see SlaService::escalateForReassignmentFailure()).
     *
     * Excludes already-withdrawn siblings deliberately: if two approvers on
     * the same stage are deactivated back to back, the first one's seat
     * withdraws (covered by the second), but the second one's own
     * deactivation must NOT also see that withdrawn row and withdraw
     * itself too — that would leave the stage with zero real seats left,
     * yet completeStage() would still treat it as fully resolved and
     * silently finalize the document as "approved" with nobody having
     * actually decided anything. Only a still-pending/already-decided
     * sibling counts as real coverage; a withdrawn one does not.
     */
    public function hasSiblingSeat(DocumentAssignment $assignment): bool
    {
        return DocumentAssignment::where('document_id', $assignment->document_id)
            ->where('stage_id', $assignment->stage_id)
            ->where('assignment_id', '!=', $assignment->assignment_id)
            ->where('individual_status', '!=', 'withdrawn')
            ->exists();
    }

    /**
     * Withdraws a pending seat that has nothing left to decide — its holder
     * was deactivated, but at least one sibling approver already covers
     * this exact stage independently (see hasSiblingSeat()), so unlike
     * escalateForReassignmentFailure() this never involves Admin at all.
     * Re-runs completeStage()'s own stage/document-completion gate
     * immediately afterward ('approved', not auto — this is an
     * administrative housekeeping action, not an SLA timeout), in case this
     * withdrawal was the last pending seat blocking the stage or document.
     */
    public function withdrawAssignment(DocumentAssignment $assignment, User $oldApprover, ?string $reason = null): void
    {
        // Wrapped in a transaction — unlike decide(),
        // this was previously the one caller of completeStage() that ran
        // outside any transaction at all, so a failure partway through
        // completeStage()'s own downstream writes (the next-stage assignStage()
        // safety net, or the document-finalization save) could leave the
        // withdrawal itself committed but the rest half-done.
        DB::transaction(function () use ($assignment, $oldApprover, $reason) {
            $assignment->individual_status = 'withdrawn';
            $assignment->acted_at = now();
            $assignment->reassigned_at = now();
            $assignment->reassigned_from = $oldApprover->user_id;
            $assignment->reassignment_reason = $reason;
            $assignment->save();

            AuditLog::record(null, $assignment->document_id, 'assignment_withdrawn',
                "{$oldApprover->full_name}'s spot on stage '{$assignment->stage->stage_name}' was removed — ".
                'their account was deactivated and another approver already covers this stage.'.
                ($reason ? " Reason: \"{$reason}\"" : ''));

            $this->completeStage($assignment, 'approved');
        });
    }

    /**
     * A pending seat with genuinely nobody eligible to take it over — no
     * sibling seat exists on this stage (see hasSiblingSeat()) and
     * findReplacementApprover() found nobody either, using the exact same
     * category+stage eligibility rule normal routing uses (deliberately
     * NOT broadened for this case). This never blames the deactivated
     * approver — they didn't fail an SLA deadline, they were deactivated
     * with nothing else available — it's auto-approved immediately via
     * autoApproveNoEligibleApprover(), same as a stage that never had an
     * eligible approver in the first place (assignStage()). This used to
     * flag the seat needs_approver and park it in a separate Unassigned
     * Documents queue for an Admin to decide directly, or wait out its own
     * SLA deadline — removed in favor of the immediate auto-approve +
     * mandatory post-hoc review every other "nobody decided this" case
     * already gets, so there's one queue to check, not two.
     */
    public function autoApproveDeactivatedSeat(DocumentAssignment $assignment, User $oldApprover, ?string $reason = null): void
    {
        $assignment->reassigned_from = $oldApprover->user_id;
        $assignment->reassignment_reason = $reason;
        $assignment->save();

        $this->autoApproveNoEligibleApprover($assignment,
            "{$oldApprover->full_name}'s account was deactivated and nobody else qualifies for this category/stage.".
            ($reason ? " Reason: \"{$reason}\"" : ''));
    }

    /**
     * Hands off a pending assignment to a new approver — used when the
     * original approver is deactivated. Deliberately does NOT touch
     * sla_expires_at: the deadline is the stage's actual time budget, not a
     * personal grace period, so the new approver inherits whatever time is
     * left rather than getting a fresh window. The EscalateAssignmentJob
     * already dispatched for this assignment still fires correctly at the
     * unchanged deadline regardless of who currently holds it (its
     * staleness guard only checks individual_status and sla_expires_at,
     * neither of which changes here).
     */
    public function reassignAssignment(DocumentAssignment $assignment, User $newApprover, User $oldApprover, ?string $reason = null): void
    {
        $assignment->user_id = $newApprover->user_id;
        $assignment->reassigned_at = now();
        $assignment->reassigned_from = $oldApprover->user_id;
        $assignment->reassignment_reason = $reason;
        $assignment->save();

        NotificationRecord::send($newApprover->user_id, $assignment->document_id,
            "A document was reassigned to you: '{$assignment->document->title}' (stage '{$assignment->stage->stage_name}'), ".
            "previously assigned to {$oldApprover->full_name}.".($reason ? " Reason: \"{$reason}\"" : ''));

        AuditLog::record(null, $assignment->document_id, 'assignment_reassigned',
            "Stage '{$assignment->stage->stage_name}' reassigned from {$oldApprover->full_name} to ".
            "{$newApprover->full_name} — their account was deactivated.".
            ($reason ? " Reason: \"{$reason}\"" : ''));

        // DocumentAssignment::booted()'s updated() hook only broadcasts on an
        // individual_status change, which this isn't — fire it explicitly so
        // the new approver's dashboard picks up the handoff instantly instead
        // of on their next poll cycle. Reuses the exact same event/channel the
        // approver dashboard already listens on for newly-routed assignments.
        event(new AssignmentRouted($assignment, $newApprover->user_id));
    }

    private function computePriority($dueDate): int
    {
        if (! $dueDate) {
            return 2;
        }
        $hoursLeft = now()->diffInHours($dueDate, false);
        if ($hoursLeft <= 24) {
            return 1;
        }   // Urgent
        if ($hoursLeft <= 72) {
            return 2;
        }   // Normal

        return 3;                        // Low
    }

    /** Process 5.0 — Approval Management. Approver decision on their assignment. */
    public function decide(DocumentAssignment $assignment, User $approver, string $decision, ?string $comments = null): void
    {
        DB::transaction(function () use ($assignment, $approver, $decision, $comments) {
            $assignment->individual_status = $decision; // 'approved' | 'rejected'
            $assignment->comments = $comments;
            $assignment->acted_at = now();
            $assignment->save();

            $stage = $assignment->stage;
            $document = $assignment->document;

            AuditLog::record($approver->user_id, $document->document_id, $decision,
                "Stage '{$stage->stage_name}' {$decision} by {$approver->full_name}.".($comments ? " Comments: {$comments}" : ''));

            // "N of M approvers have responded" — only meaningful on an
            // approval (a rejection gets its own unambiguous whole-document
            // message from completeStage() instead) once a stage has more
            // than one seat (see assignStage()); omitted for
            // single-approver stages to avoid noise on the common case.
            $progressNote = '';
            if ($decision === 'approved') {
                $stageSeats = DocumentAssignment::where('document_id', $document->document_id)
                    ->where('stage_id', $stage->stage_id)
                    ->get();
                if ($stageSeats->count() > 1) {
                    $approvedCount = $stageSeats->whereIn('individual_status', ['approved', 'auto_approved'])->count();
                    $progressNote = " ({$approvedCount} of {$stageSeats->count()} approvers have responded for this stage.)";
                }
            }

            // Section 3: Decision Alerts — per-stage notice to the
            // originator including the approver's comments, distinct from
            // completeStage()'s whole-document-outcome message below.
            NotificationRecord::send($document->originator_id, $document->document_id,
                "Stage '{$stage->stage_name}' of '{$document->title}' was {$decision} by {$approver->full_name}.".
                ($comments ? " Comments: \"{$comments}\"" : '').$progressNote);

            $this->completeStage($assignment, $decision);

            // Event-driven retraining for the Estimated Approval Time model
            // (ApprovalTimeMlService) — a real human approval is exactly
            // the new data point that model learns from (rejections don't
            // count, see that service's rows() docblock), so retrain THIS
            // one (category, department) pair right now instead of waiting
            // for the hourly sweep (TrainTimeEstimateModels) to notice.
            // ->afterCommit() defers the actual queue push until this
            // transaction commits, so the job never runs against a row it
            // can't see yet if the queue worker picks it up faster than
            // this transaction closes.
            if ($decision === 'approved' && $document->ml_category && $approver->department) {
                RetrainApprovalTimeModel::dispatch($document->ml_category, $approver->department)
                    ->afterCommit();
            }
        });
    }

    /**
     * Request Revision — creates the DocumentAnnotation and notifies the
     * originator with exactly what was flagged. Deliberately does not
     * touch $assignment's individual_status or call completeStage(): the
     * requesting approver hasn't decided yet (and may never need to
     * reject — that's the whole point), and nothing about the rest of
     * the document's review is affected.
     */
    public function requestRevision(DocumentAssignment $assignment, User $approver, array $data): DocumentAnnotation
    {
        return DB::transaction(function () use ($assignment, $approver, $data) {
            $document = $assignment->document;
            $stage = $assignment->stage;

            $annotation = DocumentAnnotation::create([
                'document_id' => $document->document_id,
                'assignment_id' => $assignment->assignment_id,
                'raised_by' => $approver->user_id,
                'start_offset' => $data['start_offset'],
                'end_offset' => $data['end_offset'],
                'selected_text' => $data['selected_text'],
                'comment' => $data['comment'],
            ]);

            AuditLog::record($approver->user_id, $document->document_id, 'revision_requested',
                "{$approver->full_name} flagged a passage for revision on stage '{$stage->stage_name}': ".
                "\"{$data['comment']}\"");

            NotificationRecord::send($document->originator_id, $document->document_id,
                "{$approver->full_name} flagged a passage of '{$document->title}' (stage '{$stage->stage_name}') needing ".
                "revision: \"{$data['comment']}\" — the flagged text: \"{$data['selected_text']}\"", 'high');

            // Reuses the same broadcast saveDocumentRevision() below
            // already fires, rather than a new event — without this, the
            // originator's Document Tracking page (and any approver with
            // "Review & Comment" already open on this document — see the
            // matching live-sync listener in approver/dashboard.blade.php)
            // wouldn't notice this new flag until their next background
            // poll instead of right away.
            event(new DocumentStatusChanged($document));

            return $annotation;
        });
    }

    /**
     * An approver retracts their own not-yet-addressed flag (Feature:
     * "I flagged the wrong thing" / "never mind" — the mirror image of
     * requestRevision() above). Deleted outright rather than soft-marked
     * resolved — a withdrawal was never actually addressed by the
     * originator, so it shouldn't read as one in the Document Tracker;
     * see DocumentMovementTimeline's 'revision_withdrawn' action_type,
     * which carries its own distinct audit trail entry regardless.
     *
     * Authorization (only the approver who raised it, and only while
     * it's still open) is the caller's responsibility — see
     * DocumentAnnotationPolicy::withdraw(), checked in
     * ApprovalController::withdrawAnnotation() before this is ever
     * called, same division of responsibility every other Workflow
     * Service method here already assumes.
     */
    public function withdrawAnnotation(DocumentAnnotation $annotation, User $approver): void
    {
        DB::transaction(function () use ($annotation, $approver) {
            $document = $annotation->document;
            $stage = $annotation->assignment->stage;
            $comment = $annotation->comment;

            $annotation->delete();

            AuditLog::record($approver->user_id, $document->document_id, 'revision_withdrawn',
                "{$approver->full_name} withdrew their revision request on stage '{$stage->stage_name}': ".
                "\"{$comment}\"");

            NotificationRecord::send($document->originator_id, $document->document_id,
                "{$approver->full_name} withdrew their revision request on '{$document->title}' (stage ".
                "'{$stage->stage_name}') — the flagged passage no longer needs addressing: \"{$comment}\"", 'normal');

            event(new DocumentStatusChanged($document));
        });
    }

    /**
     * The originator edits the document's plain text directly (Feature:
     * "editable document" — see requestRevision()'s docblock) and marks
     * which open annotations this save addresses. Only ids that are (a)
     * on THIS document and (b) still unresolved get closed — anything
     * else in $resolvedAnnotationIds (already resolved, or belonging to
     * a different document entirely) is silently ignored rather than
     * erroring, since the originator's own UI only ever offers the
     * annotations that are actually open on this document to begin with.
     */
    public function saveDocumentRevision(DocumentRepository $document, User $originator, string $text, array $resolvedAnnotationIds): void
    {
        DB::transaction(function () use ($document, $originator, $text, $resolvedAnnotationIds) {
            // Captured before the overwrite below — Feature: Revision
            // History (see DocumentRevision), which needs the FULL
            // before-text, not just the fact that a change happened.
            $previousText = $document->ocr_text;

            // A submitted <textarea> value can reintroduce \r\n depending
            // on the browser — see TextExtractionService::
            // normalizeLineEndings()'s docblock for why leaving that in
            // would throw off every future flagged-passage offset again.
            $newText = TextExtractionService::normalizeLineEndings($text);
            $document->ocr_text = $newText;
            $document->save();

            AuditLog::record($originator->user_id, $document->document_id, 'revision_saved',
                "{$originator->full_name} revised the text of '{$document->title}'.");

            $resolved = DocumentAnnotation::where('document_id', $document->document_id)
                ->whereNull('resolved_at')
                ->whereIn('annotation_id', $resolvedAnnotationIds)
                ->with(['raisedBy', 'assignment.stage'])
                ->get();

            foreach ($resolved as $annotation) {
                $annotation->resolved_at = now();
                $annotation->save();

                // Straight to the specific approver who raised THIS
                // annotation — not every approver on the document, and
                // not a blanket "document resubmitted" notice, since
                // that's exactly the noise this feature exists to avoid.
                NotificationRecord::send($annotation->raised_by, $document->document_id,
                    "'{$document->title}' (stage '{$annotation->assignment->stage->stage_name}') was revised to address ".
                    "your flagged concern: \"{$annotation->comment}\" — please re-review.", 'high');
            }

            // One immutable before/after snapshot per save, linked to
            // whichever flags this specific save addressed — see
            // DocumentRevision's docblock.
            $revision = DocumentRevision::create([
                'document_id' => $document->document_id,
                'revised_by' => $originator->user_id,
                'previous_text' => $previousText,
                'new_text' => $newText,
            ]);
            if ($resolved->isNotEmpty()) {
                $revision->annotations()->attach($resolved->pluck('annotation_id'));
            }

            event(new DocumentStatusChanged($document));
        });
    }

    /**
     * The .docx equivalent of saveDocumentRevision() above — same
     * "edit anywhere, mark off which flags this addresses" shape as
     * every other file type's revision screen, except the editable
     * surface is the real rendered document (see DocxRichContentService::
     * render()'s $tagSegments) instead of a plain textarea, and only text
     * content can change — images, tables, and formatting are never
     * touched. $segmentTexts is every real text run's CURRENT content, in
     * document order (exactly what the client reads back from each
     * [data-seg] element — see tracking-content.blade.php); applyTextEdits()
     * writes only the ones that actually differ straight into the real
     * file and REJECTS the whole save (bubbles up to the controller as a
     * 422) if the count no longer matches the file's own real text
     * segments — that only happens if something besides text changed
     * (an image/row/paragraph added or removed), which revision isn't
     * meant to do. $resolvedAnnotationIds is independent of what text
     * actually changed, same decoupling saveDocumentRevision() already
     * has — checking a flag off just means "this save addresses it,"
     * nothing more specific is required.
     *
     * @param  array<int, string>  $segmentTexts
     * @param  array<int, int>  $resolvedAnnotationIds
     */
    public function saveDocxRevision(DocumentRepository $document, User $originator, array $segmentTexts, array $resolvedAnnotationIds): void
    {
        DB::transaction(function () use ($document, $originator, $segmentTexts, $resolvedAnnotationIds) {
            $previousText = $document->ocr_text;

            $newText = $this->docxRichContent->applyStoredTextEdits($document->file_path, $segmentTexts);

            $document->ocr_text = TextExtractionService::normalizeLineEndings($newText);
            $document->save();

            AuditLog::record($originator->user_id, $document->document_id, 'revision_saved',
                "{$originator->full_name} revised the text of '{$document->title}'.");

            $resolved = DocumentAnnotation::where('document_id', $document->document_id)
                ->whereNull('resolved_at')
                ->whereIn('annotation_id', $resolvedAnnotationIds)
                ->with(['raisedBy', 'assignment.stage'])
                ->get();

            foreach ($resolved as $annotation) {
                $annotation->resolved_at = now();
                $annotation->save();

                NotificationRecord::send($annotation->raised_by, $document->document_id,
                    "'{$document->title}' (stage '{$annotation->assignment->stage->stage_name}') was revised to address ".
                    "your flagged concern: \"{$annotation->comment}\" — please re-review.", 'high');
            }

            $revision = DocumentRevision::create([
                'document_id' => $document->document_id,
                'revised_by' => $originator->user_id,
                'previous_text' => $previousText,
                'new_text' => $document->ocr_text,
            ]);
            if ($resolved->isNotEmpty()) {
                $revision->annotations()->attach($resolved->pluck('annotation_id'));
            }

            event(new DocumentStatusChanged($document));
        });
    }

    /**
     * Resolves a stage once one of its seats has been decided (by an
     * approver in decide(), or
     * automatically by SlaService::autoApproveOne()). Since a stage can now
     * have more than one seat (see assignStage()), a single decided seat
     * doesn't necessarily mean the STAGE is done — see the stage-scoped
     * gate below.
     *
     * @param  bool  $auto  true when this resolution came from the SLA
     *                      auto-approval safety net rather than a human
     *                      decision — used only to pick the correct
     *                      terminal global_status ('approved' vs 'auto_approved').
     */
    public function completeStage(DocumentAssignment $assignment, string $decision, bool $auto = false): void
    {
        $document = $assignment->document;
        $stage = $assignment->stage;

        if ($decision === 'rejected') {
            // Majority vote (Feature: one lone reject on a multi-approver
            // stage no longer terminates the document out from under
            // whoever else is still reviewing it — see
            // DocumentAssignment::stageRejectionStatus()'s docblock for
            // the exact threshold math). A single-approver stage is
            // unaffected: threshold is 1, so this decision already IS
            // the whole vote, same as always.
            $voteStatus = $assignment->stageRejectionStatus();

            if (! $voteStatus['majorityReached']) {
                // Not enough reject votes yet — record stays as this
                // seat's own decision (already saved by decide() before
                // calling here), but nothing cascades. The rest of this
                // stage (and the document) stays exactly as it was,
                // still able to go either way once the remaining seats
                // decide.
                NotificationRecord::send($assignment->user_id, $document->document_id,
                    "Your rejection of '{$document->title}' (stage '{$stage->stage_name}') needs support from other ".
                    "reviewers on this stage before it takes effect — {$voteStatus['rejected']} of {$voteStatus['threshold']} ".
                    'needed. If you have a specific problem, consider flagging it instead of rejecting outright.');

                return;
            }

            // Rejection terminates the WHOLE document — close every other
            // pending assignment across ALL stages (including any other
            // still-pending seat on THIS SAME stage — this query has no
            // stage_id filter, so it already correctly cascades to
            // same-stage siblings, not just other stages), since every
            // stage is routed up front and more than one can be pending at
            // once. Majority having been reached above, this now cascades
            // exactly like the old any-single-reject behavior did.
            DocumentAssignment::where('document_id', $document->document_id)
                ->where('individual_status', 'pending')
                ->where('assignment_id', '!=', $assignment->assignment_id)
                ->get()
                ->each(function (DocumentAssignment $other) use ($assignment) {
                    $other->individual_status = 'rejected';
                    // The ACTUAL rejection reason, not a generic system
                    // message — $assignment already has one (rejecting
                    // requires a comment, see the mandatory-comment rule
                    // in decide()/decideBatch()), so every reader of
                    // $other's comment (Decision History, workflow-stage-
                    // list, etc.) sees why it was really rejected instead
                    // of a placeholder that told them nothing. Whether
                    // this was a direct decision or a cascade-close is now
                    // recorded distinctly via cascade_closed_by below, not
                    // by sniffing the comment text.
                    $other->comments = $assignment->comments;
                    $other->acted_at = now();
                    // Records who ACTUALLY rejected it, not $other's own
                    // holder — $other never made this decision themselves,
                    // it was cascade-closed by $assignment's reject. Lets
                    // Decision History correctly attribute it to the real
                    // decider instead of implying $other's own approver
                    // personally rejected something they never reviewed.
                    $other->cascade_closed_by = $assignment->user_id;
                    $other->save();
                });

            $document->global_status = 'rejected';
            $document->save();
            NotificationRecord::send($document->originator_id, $document->document_id,
                "Your document '{$document->title}' was rejected at stage '{$stage->stage_name}'.");

            if ($this->isFinalApprovalStage($stage)) {
                $this->settleDeferredAutoApprovalReviews($document);
            }

            return;
        }

        // A fresh approval can strand an earlier minority reject on this
        // same stage — majority was never reached for it, and now
        // (thanks to THIS approval) it mathematically never can be
        // either. Rather than leave that seat sitting on a reject that
        // can no longer do anything — which would also permanently block
        // the unanimous-approval finalize check below, since a stranded
        // 'rejected' seat is neither pending nor approved — reset it back
        // to pending so its holder decides what to do next themselves:
        // approve it, or raise a real concern through Request Revision.
        if ($decision === 'approved') {
            $voteStatus = $assignment->stageRejectionStatus();

            if ($voteStatus['rejected'] > 0 && ! $voteStatus['rejectStillPossible']) {
                DocumentAssignment::where('document_id', $document->document_id)
                    ->where('stage_id', $stage->stage_id)
                    ->where('individual_status', 'rejected')
                    ->get()
                    ->each(function (DocumentAssignment $stranded) use ($document, $stage, $voteStatus) {
                        $stranded->individual_status = 'pending';
                        $stranded->comments = null;
                        $stranded->acted_at = null;
                        $stranded->save();

                        AuditLog::record(null, $document->document_id, 'reject_stranded',
                            "{$stranded->approver->full_name}'s rejection on stage '{$stage->stage_name}' no longer ".
                            "stands — {$voteStatus['approved']} other reviewer(s) already approved. ".
                            'Reset to pending so they can decide again.');

                        NotificationRecord::send($stranded->user_id, $document->document_id,
                            "Your rejection of '{$document->title}' (stage '{$stage->stage_name}') can no longer take effect — ".
                            "{$voteStatus['approved']} other reviewer(s) already approved it. You can approve it yourself, or ".
                            'use Request Revision if you still have a specific concern to flag.',
                            'high');
                    });
            }
        }

        // NEW gate: this STAGE isn't done until every seat on it (every
        // approver assigned to this exact document+stage) is non-pending —
        // not just this one. Until then, nothing else below has anything
        // to do yet, since neither stage-advancement nor document
        // finalization can be correct while a sibling seat on this same
        // stage might still reject.
        $stageStillPending = DocumentAssignment::where('document_id', $document->document_id)
            ->where('stage_id', $stage->stage_id)
            ->where('individual_status', 'pending')
            ->exists();

        if ($stageStillPending) {
            return;
        }

        // Everything below only runs once every seat on this stage is
        // resolved — i.e. this now means "the STAGE completed", not just
        // "one assignment completed".
        AuditLog::record(null, $document->document_id, 'stage_complete',
            "Stage '{$stage->stage_name}' is fully resolved — every assigned approver has responded.");

        // Safety net only: every stage is normally already assigned at
        // upload time (see routeToWorkflow()). This only fires if a stage
        // was added to the category's pipeline after this document was
        // already routed, so it still gets picked up. A one-off,
        // document-scoped stage (see routeToCustomApprovers()) never has
        // a "next" one — it IS the whole pipeline for that document —
        // and ->configured() below would incorrectly find a REAL next
        // stage sharing the same document_category otherwise.
        // Final Approval is excluded here on purpose: it opens only through
        // openFinalApprovalIfReady() below, once EVERY other stage is done —
        // not merely the one that happens to precede it in the list.
        $nextStage = $stage->document_id ? null : WorkflowStage::configured()
            ->where('document_category', $document->ml_category)
            ->where('is_archived', false)
            ->where('stage_name', '!=', 'Final Approval')
            ->where('sequence_order', '>', $stage->sequence_order)
            ->orderBy('sequence_order')
            ->first();

        if ($nextStage) {
            $alreadyAssigned = DocumentAssignment::where('document_id', $document->document_id)
                ->where('stage_id', $nextStage->stage_id)
                ->exists();

            if (! $alreadyAssigned) {
                // Computed fresh here, deliberately NOT reusing the
                // original routing-time deadline — this stage genuinely
                // didn't exist until later, so "now" for its own SLA
                // budget is this moment, not the document's original
                // upload time.
                $this->assignStage($document, $nextStage, $this->computeApproverSlaExpiry($document));
            }
        }

        if ($this->isFinalApprovalStage($stage)) {
            $this->settleDeferredAutoApprovalReviews($document);
        } else {
            $this->openFinalApprovalIfReady($document, $stage);
        }

        // Finalize only once NO stage anywhere for this document still has
        // a pending assignment — not merely "nothing comes after this
        // stage" — since stages can be completed out of sequence order.
        $anyPending = DocumentAssignment::where('document_id', $document->document_id)
            ->where('individual_status', 'pending')
            ->exists();

        $document->refresh();
        if (! $anyPending && ! in_array($document->global_status, ['approved', 'auto_approved'], true)) {
            $document->global_status = $auto ? 'auto_approved' : 'approved';
            $document->save();
            NotificationRecord::send($document->originator_id, $document->document_id,
                "Your document '{$document->title}' has been fully approved.".($auto ? ' (auto-approved by the system after an SLA timeout)' : ''));
            AuditLog::record(null, $document->document_id, 'finalize',
                'All stages approved — document archived to repository.'.($auto ? ' [Final stage was auto-approved after SLA timeout]' : ''));
        }
    }
}
