<?php

namespace App\Http\Controllers;

use App\Models\DocumentRepository;
use App\Models\DocumentReviewSession;
use App\Models\DocumentRevision;
use App\Models\SubmissionBatch;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Rules\ReliableMimeType;
use App\Services\DocxRichContentService;
use App\Services\TextDiffService;
use App\Services\ValidationService;
use App\Services\WorkflowService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Throwable;

class DocumentController extends Controller
{
    public function __construct(private WorkflowService $workflow) {}

    /**
     * The originator's filtered/paginated submissions query — shared by
     * dashboard() (full page), refresh() (the AJAX fragment the live-poll
     * JS swaps in), and poll() (the cheap "did anything change" signal),
     * so all three always agree on what's currently visible for a given
     * set of filters.
     */
    private function documentsQuery(Request $request, int $userId)
    {
        $query = DocumentRepository::forOriginator($userId)
            ->with(['currentAssignment.stage', 'assignments.stage', 'batch']);

        if ($request->filled('document')) {
            $query->where('title', 'like', '%'.$request->string('document').'%');
        }
        if ($request->filled('status')) {
            $query->where('global_status', $request->string('status'));
        }
        if ($request->filled('category')) {
            $query->where('ml_category', $request->string('category'));
        }

        return $query;
    }

    /** Originator dashboard: drag-drop upload + live tracking list (DFD 3.1-3.4, 4.0). */
    public function dashboard(Request $request)
    {
        // Feature: client-side row fitting — see resources/js/app.js's
        // initFittedPagination(). The server sends every matching document
        // in one response (never truncated/batched) so the browser — the
        // only thing that knows its own real pixel height — can measure
        // the whole list up front and work out every page's exact real
        // boundary itself, including which page a direct page-number click
        // lands on. A server-side page size, even a generous one, can only
        // ever know one page ahead, which is what forced the pagination
        // component to lose its numbered page links in an earlier version
        // of this feature. Fine at this app's real scale (one originator's
        // own documents); would need revisiting only if that list ever
        // grew into the thousands.
        $documents = $this->documentsQuery($request, $request->user()->user_id)
            ->latest('upload_date')->get();

        $categories = ValidationService::knownCategories();

        return view('originator.dashboard', compact('documents', 'categories'));
    }

    /**
     * Renders just the submissions fragment (originator/partials/submissions.blade.php)
     * for the dashboard's live-poll JS to swap in place — see
     * resources/js/app.js's startLivePoll() and dashboard.blade.php for why
     * this beats a full page reload. Respects the same filters as a normal
     * page load (the JS forwards the current query string).
     */
    public function refresh(Request $request)
    {
        $documents = $this->documentsQuery($request, $request->user()->user_id)
            ->latest('upload_date')->get();

        return view('originator.partials.submissions', compact('documents'));
    }

    /**
     * Lightweight JSON endpoint the dashboard's JS polls every ~5-10s. A
     * plain row *count* alone would miss an existing document's status
     * changing (processing -> approved, say) without any row being
     * added/removed, so this also reports the newest `updated_at` across
     * the filtered set — either changing is enough to trigger a live swap.
     */
    public function poll(Request $request)
    {
        $query = $this->documentsQuery($request, $request->user()->user_id);

        return response()->json([
            'count' => (clone $query)->count(),
            'latest_update' => $query->max('updated_at'),
        ]);
    }

    /**
     * Accepts one or more files in a single submission. Every file in the
     * request is linked to one new SubmissionBatch and shares the same
     * due date, so Approvers and Admins see them nested together as one
     * approval request instead of as unrelated flat rows (Feature: grouped
     * dashboards).
     */
    public function store(Request $request)
    {
        // Explicit validation on every document metadata input (Section 3).
        // Content-based MIME verification is layered on top of mimes:, but
        // deliberately only for the formats that sniff reliably across
        // OS/Office versions (pdf/png/jpg/txt) — legacy .doc (OLE2) and
        // .docx (zip) sniff inconsistently enough that a strict mimetype
        // check would false-reject legitimate Word files, so those stay
        // protected by the mimes: extension-mapping rule only (with
        // WorkflowService::ingest()'s extraction_failed handling as a
        // downstream backstop for garbage content that slips through).
        // "files.0", "files.1", etc. are meaningless to a user picking 20
        // files at once — swap in each file's own original name so a
        // failure reads "'weird_scan.heic' must be a file of type: ..."
        // instead of "The files.0 field must be...", which gives no way to
        // tell which of the selected files actually failed.
        $fileAttributeNames = [];
        foreach ($request->file('files', []) as $index => $file) {
            if ($file) {
                $fileAttributeNames["files.{$index}"] = "'".$file->getClientOriginalName()."'";
            }
        }

        $validated = $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:20'],
            'files.*' => [
                'file',
                'mimes:pdf,docx,doc,txt,png,jpg,jpeg',
                new ReliableMimeType,
                'max:20480',
            ],
            'due_date' => ['required', 'date', function ($attribute, $value, $fail) {
                $buffer = config('sla.min_due_date_buffer_minutes', 15);
                if (Carbon::parse($value)->lt(now()->addMinutes($buffer))) {
                    $fail("The due date must be at least {$buffer} minutes from now.");

                    return;
                }
                // Section 1 (extended): reject a due date outside working
                // hours/days outright rather than silently moving it — see
                // WorkflowService::isDueDateWithinWorkingHours()'s docblock.
                if (! $this->workflow->isDueDateWithinWorkingHours($value)) {
                    $fail('The due date must fall within working hours (9 AM–5 PM, Mon–Sat, excluding holidays). Please pick a valid date and time.');
                }
            }],
            'requires_printing' => ['sometimes', 'boolean'],
            // Feature: originator-directed routing — see WorkflowService::
            // ingest()'s $routingMode docblock. Applies to every file in
            // this batch alike, same as due_date/requires_printing above.
            'routing_mode' => ['sometimes', 'in:auto,custom,unrelated'],
        ], [], $fileAttributeNames);

        $effectiveDueDate = Carbon::parse($validated['due_date']);
        // Unchecked means "no explicit override" — WorkflowService::ingest()
        // still applies the category's own default once classification
        // determines it, this only ever adds the requirement, never
        // removes one the category itself calls for.
        $requiresPrinting = $request->boolean('requires_printing');
        $routingMode = $validated['routing_mode'] ?? 'auto';

        $batch = SubmissionBatch::create([
            'originator_id' => $request->user()->user_id,
            'due_date' => $effectiveDueDate,
        ]);

        // Per-file try/catch, not a single .map() — each ingest() call
        // already has its own DB::transaction and commits independently, so
        // a genuinely unexpected failure (not the known extraction/
        // classification failure modes, which ingest() now handles
        // gracefully on its own) on file 3 of 5 must not take down the
        // request and silently leave the user wondering why only 2 of their
        // 5 files show up, with no explanation and a raw 500 page.
        $documents = collect();
        $failedFiles = [];
        $duplicateFiles = [];

        foreach ($validated['files'] as $file) {
            // Server-side backstop (confirmed 2026-10-01 in production —
            // two separate documents for the same file, 3 seconds apart)
            // behind the client-side double-click guard on the Submit
            // button (see dashboard.blade.php) — catches whatever that
            // guard can't, e.g. a replayed request. Same originator, same
            // original filename and byte size, uploaded moments ago: that's
            // the same physical upload landing twice, not two genuinely
            // different documents the originator meant to submit.
            if ($this->isRecentDuplicateSubmission($request->user()->user_id, $file)) {
                $duplicateFiles[] = $file->getClientOriginalName();

                continue;
            }

            try {
                $documents->push($this->workflow->ingest(
                    $file, $request->user(), $effectiveDueDate->toDateTimeString(), $batch->batch_id, null, $requiresPrinting, $routingMode
                ));
            } catch (Throwable $e) {
                Log::error('Unexpected failure ingesting an uploaded document', [
                    'originator_id' => $request->user()->user_id,
                    'file' => $file->getClientOriginalName(),
                    'exception' => $e->getMessage(),
                ]);
                $failedFiles[] = $file->getClientOriginalName();
            }
        }

        $status = $this->buildSubmissionStatusMessage($documents, $failedFiles, $duplicateFiles);

        return redirect()
            ->route('originator.dashboard')
            ->with('status', $status);
    }

    /**
     * @param  UploadedFile  $file
     */
    private function isRecentDuplicateSubmission(int $originatorId, $file): bool
    {
        $recentMatch = DocumentRepository::where('originator_id', $originatorId)
            ->where('original_filename', $file->getClientOriginalName())
            ->where('upload_date', '>=', now()->subSeconds(10))
            ->latest('upload_date')
            ->first();

        if (! $recentMatch || ! Storage::exists($recentMatch->file_path)) {
            return false;
        }

        return Storage::size($recentMatch->file_path) === $file->getSize();
    }

    private function buildSubmissionStatusMessage($documents, array $failedFiles = [], array $duplicateFiles = []): string
    {
        $failureNote = $failedFiles
            ? ' '.count($failedFiles).' file(s) hit an unexpected error and were not uploaded ('.
                implode(', ', $failedFiles).') — please try re-uploading just those.'
            : '';
        $duplicateNote = $duplicateFiles
            ? ' '.count($duplicateFiles).' file(s) matched a submission from moments ago and were skipped as a likely duplicate ('.
                implode(', ', $duplicateFiles).').'
            : '';

        if ($documents->isEmpty()) {
            if ($duplicateFiles && ! $failedFiles) {
                return 'This looks like a duplicate of your last submission — nothing new was uploaded.'.$duplicateNote;
            }

            return 'Your upload could not be processed due to an unexpected error. Please try again.'.$failureNote.$duplicateNote;
        }

        if ($documents->count() === 1) {
            $document = $documents->first();
            if (! $document->is_validated) {
                return "'{$document->title}' uploaded but failed validation — see details below.".$failureNote.$duplicateNote;
            }
            // "classified as X" reads fine for a real category, but odd
            // for a document marked Other ("classified as Other") — see
            // DocumentRepository::display_category's docblock for why the
            // category label itself already differs by desired_routing;
            // the verb needs to follow suit too.
            $categoryPhrase = $document->desired_routing === 'unrelated'
                ? 'marked as Other'
                : "classified as '{$document->display_category}'";

            // Awaiting the originator's own approver pick (Feature:
            // originator-directed routing) rather than already routed —
            // pending_custom_routing_at is only ever set once validation/
            // classification actually cleared, so is_validated is already
            // known true here.
            return ($document->pending_custom_routing_at
                ? "'{$document->title}' uploaded and {$categoryPhrase} — select the approver(s) you'd like to route it to."
                : "'{$document->title}' uploaded, {$categoryPhrase}, and routed for approval.").$failureNote.$duplicateNote;
        }

        $failedValidation = $documents->reject(fn ($d) => $d->is_validated)->count();
        $awaitingSelection = $documents->filter(fn ($d) => $d->pending_custom_routing_at)->count();

        return "{$documents->count()} documents uploaded together.".
            ($awaitingSelection > 0 ? " {$awaitingSelection} need you to select approver(s) before routing." : ' Routed as one approval request.').
            ($failedValidation > 0 ? " {$failedValidation} failed validation — see details below." : '').$failureNote.$duplicateNote;
    }

    public function show(Request $request, DocumentRepository $document)
    {
        $this->authorize('viewTracking', $document);

        $document->load(['assignments.stage', 'assignments.approver', 'auditLogs.user', 'previousVersion', 'nextVersion', 'openAnnotations.raisedBy']);

        return view('originator.tracking', compact('document'));
    }

    /**
     * Feature: the shared Document Tracker popup (see routes/web.php's
     * documents.trackerModal) — fetched into components/kpi-drilldown-
     * modal.blade.php by Audit Logs, Approver Decision History, and
     * Archive. Same viewTracking authorization as show() above; this just
     * skips loading everything show() needs for the full page (approval
     * stages, revision annotations, ...) since the modal only ever
     * renders <x-document-tracker>.
     */
    public function trackerModal(DocumentRepository $document)
    {
        $this->authorize('viewTracking', $document);

        $document->load('auditLogs.user');

        return view('components.document-tracker-modal-body', compact('document'));
    }

    /**
     * Feature: Revision History (Google-Docs-style — see DocumentRevision).
     * Newest save first; the view itself pins the document's current live
     * text above all of them as a non-diffable "present" entry. Same
     * viewTracking population as the rest of the tracker: originator, any
     * assigned approver, Admin.
     */
    public function revisionHistory(DocumentRepository $document)
    {
        $this->authorize('viewTracking', $document);

        $revisions = DocumentRevision::where('document_id', $document->document_id)
            ->with(['revisedBy', 'annotations.raisedBy'])
            ->orderByDesc('created_at')
            ->get();

        return view('documents.partials.revision-history-list', compact('document', 'revisions'));
    }

    /** One revision's before/after comparison — see TextDiffService for the word-diff approach. */
    public function revisionCompare(DocumentRepository $document, DocumentRevision $revision, TextDiffService $diffService)
    {
        $this->authorize('viewTracking', $document);
        abort_unless($revision->document_id === $document->document_id, 404);

        $revision->loadMissing(['revisedBy', 'annotations.raisedBy']);
        $diff = $diffService->diff($revision->previous_text ?? '', $revision->new_text ?? '');

        return view('documents.partials.revision-diff', compact('document', 'revision', 'diff'));
    }

    /**
     * Feature: originator-directed routing — the "pick approver(s)"
     * follow-up step for a document uploaded with routing_mode 'custom' or
     * 'unrelated' (see WorkflowService::ingest()), shown once
     * classification/validation have actually cleared and
     * pending_custom_routing_at is set.
     *
     * The eligible pool AND its layout differ by which mode was chosen:
     *   - 'custom' (a known category): scoped to that category's actually
     *     eligible approvers, grouped Category -> Stage -> Approver (Feature:
     *     originator sees exactly who covers which stage, not just a flat
     *     list) — built from WorkflowService::eligibleApproversForStage(),
     *     called once per configured stage rather than the deduped, stage-
     *     agnostic union eligibleApproversForCategory() normally returns.
     *   - 'unrelated' (no real category to scope by): every active
     *     approver, grouped by department only.
     *
     * Renders a fragment, not a full page — fetched into the shared
     * openKpiDrilldown() modal (see components/kpi-drilldown-modal.
     * blade.php) from the "Select Approver(s)" link on both the
     * submissions table and the tracking page, same pattern the Approver
     * Queue's "Review & Comment" panel already uses.
     */
    public function selectApprovers(Request $request, DocumentRepository $document)
    {
        $this->authorize('routeCustom', $document);

        abort_unless($document->pending_custom_routing_at !== null, 404);

        if ($document->desired_routing === 'unrelated') {
            $groupedApprovers = $this->groupApproversByDepartment(
                User::where('role', 'approver')->where('is_active', true)->orderBy('full_name')->get()
            );

            return view('originator.partials.select-approvers-panel', [
                'document' => $document,
                'groupedApprovers' => $groupedApprovers,
                'stageGroups' => null,
            ]);
        }

        $stageGroups = WorkflowStage::configured()->forCategory($document->ml_category)
            ->where('is_archived', false)->orderBy('sequence_order')->get()
            ->map(fn (WorkflowStage $stage) => (object) [
                'stage' => $stage,
                'approvers' => $this->workflow->eligibleApproversForStage($document->ml_category, $stage)
                    ->sortBy(fn (User $a) => ($a->level === 'head' ? '0_' : '1_').$a->full_name)->values(),
            ])
            // A stage nobody is currently eligible for has nothing to pick
            // here — omitted rather than shown as an empty, unpickable group.
            ->filter(fn ($group) => $group->approvers->isNotEmpty())
            ->values();

        return view('originator.partials.select-approvers-panel', [
            'document' => $document,
            'groupedApprovers' => null,
            'stageGroups' => $stageGroups,
        ]);
    }

    /**
     * Grouped by department, head(s) sorted before staff within each
     * (Feature: originator can tell at a glance who to route a "just needs
     * the head's sign-off" document to — see User::LEVELS' docblock:
     * 'head' specifically means "sits on this category's Final Approval
     * stage," exactly that person). Used by selectApprovers()'s
     * 'unrelated' branch.
     *
     * @param  Collection<int, User>  $approvers
     */
    private function groupApproversByDepartment($approvers): Collection
    {
        $approvers->each(fn (User $approver) => $approver->stages_label = $this->stagesLabelFor($approver));

        return $approvers
            ->groupBy(fn (User $approver) => $approver->department ?: 'No Department')
            ->map(fn ($group) => $group->sortBy(fn (User $a) => ($a->level === 'head' ? '0_' : '1_').$a->full_name)->values())
            ->sortKeys();
    }

    /**
     * "Job Order — Technical Review, Final Approval" — or, for an
     * approver with no explicit stage picks at all, every configured
     * stage in their own category, since that's exactly what "no picks"
     * already means for real routing eligibility (see WorkflowService::
     * eligibleApproversForStage()'s docblock: "no explicit stage picks
     * -> eligible for every stage in their category"). Showing nothing
     * for that case would misleadingly read as "handles no stages"
     * instead of "handles all of them."
     *
     * The category prefix matters most for an 'unrelated' document's
     * picker, which offers every active approver rather than ones
     * scoped to one category (see selectApprovers() above) — without
     * it, two approvers from different categories who happen to hold a
     * similarly-named stage ("Final Approval") were indistinguishable.
     */
    private function stagesLabelFor(User $approver): string
    {
        if (! $approver->assigned_category) {
            return 'No category assigned';
        }

        $picked = $approver->workflowStages()->orderBy('sequence_order')->pluck('stage_name');

        if ($picked->isNotEmpty()) {
            return "{$approver->assigned_category} — {$picked->implode(', ')}";
        }

        $allInCategory = WorkflowStage::configured()
            ->forCategory($approver->assigned_category)
            ->where('is_archived', false)
            ->pluck('stage_name');

        return $allInCategory->isNotEmpty()
            ? "{$approver->assigned_category} — {$allInCategory->implode(', ')} (all stages)"
            : "{$approver->assigned_category} — no stages configured yet";
    }

    public function routeCustom(Request $request, DocumentRepository $document)
    {
        $this->authorize('routeCustom', $document);

        abort_unless($document->pending_custom_routing_at !== null, 409, 'This document is not awaiting an approver selection.');

        $eligibleIds = $document->desired_routing === 'unrelated'
            ? User::where('role', 'approver')->where('is_active', true)->pluck('user_id')->all()
            : $this->workflow->eligibleApproversForCategory($document->ml_category)->pluck('user_id')->all();

        $validated = $request->validate([
            'approver_ids' => ['required', 'array', 'min:1'],
            'approver_ids.*' => ['integer', Rule::in($eligibleIds)],
        ]);

        $this->workflow->routeToCustomApprovers($document, $validated['approver_ids'], $request->user());

        return redirect()->route('originator.documents.show', $document)
            ->with('status', "'{$document->title}' routed to your selected approver(s).");
    }

    /**
     * Renders just the tracking page body (originator/partials/tracking-content.blade.php)
     * for that page's live-poll JS to swap in place — see
     * resources/js/app.js's startLiveChannel()/startLivePoll() and
     * tracking.blade.php for why this beats a full page reload. Reacts to
     * ANY stage on this document being decided, not just the document's
     * overall global_status finalizing — see DocumentAssignment::booted().
     */
    public function trackingRefresh(Request $request, DocumentRepository $document)
    {
        $this->authorize('viewTracking', $document);

        $document->load(['assignments.stage', 'assignments.approver', 'auditLogs.user', 'previousVersion', 'nextVersion', 'openAnnotations.raisedBy']);

        return view('originator.partials.tracking-content', compact('document'));
    }

    /**
     * Lightweight JSON endpoint this document's tracking page polls every
     * ~5-10s as a fallback if the WebSocket connection is down. Reports
     * global_status, the newest audit-log timestamp, and a hash of every
     * assignment's individual_status — any of the three changing (a stage
     * being decided mid-pipeline doesn't necessarily change global_status
     * or add an audit-log row alone) is enough to trigger a live swap.
     */
    public function trackingPoll(Request $request, DocumentRepository $document)
    {
        $this->authorize('viewTracking', $document);

        return response()->json([
            'status' => $document->global_status,
            'latest_audit' => $document->auditLogs()->max('timestamp'),
            'assignment_statuses' => $document->assignments()->pluck('individual_status', 'assignment_id'),
        ]);
    }

    /**
     * Section 5 (extended): a rejected document was previously a dead end —
     * the only way to try again was uploading an entirely new, unrelated
     * document. This re-runs the same ingest pipeline (extract, classify,
     * validate, route) on a revised file, but links the result back to the
     * rejected document as the next entry in its version chain instead.
     */
    public function resubmit(Request $request, DocumentRepository $document)
    {
        $this->authorize('resubmit', $document);
        // 'processing' is never a transient "still working" state once a
        // document is visible here — WorkflowService::ingest() only ever
        // leaves it there on a genuine dead end (validation or extraction
        // failure, see failExtraction()'s docblock), same as 'rejected'.
        // A disputed auto-approval is a third stuck state — see
        // DocumentRepository::isResubmittable()'s own docblock. All three
        // are cases an originator needs a real way out of.
        abort_unless($document->isResubmittable(), 409, 'Only a rejected, failed-validation, or disputed document can be resubmitted.');

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,docx,doc,txt,png,jpg,jpeg', new ReliableMimeType, 'max:20480'],
            'due_date' => ['required', 'date', function ($attribute, $value, $fail) {
                $buffer = config('sla.min_due_date_buffer_minutes', 15);
                if (Carbon::parse($value)->lt(now()->addMinutes($buffer))) {
                    $fail("The due date must be at least {$buffer} minutes from now.");

                    return;
                }
                if (! $this->workflow->isDueDateWithinWorkingHours($value)) {
                    $fail('The due date must fall within working hours (9 AM–5 PM, Mon–Sat, excluding holidays). Please pick a valid date and time.');
                }
            }],
            // Feature: originator-directed routing — same option store()
            // offers on a fresh upload (see WorkflowService::ingest()'s
            // $routingMode docblock). Matters most here for a document
            // rejected for confidence below the random-chance floor (see
            // ingest()'s $belowChanceFloor branch): the originator can
            // pick a category themselves via 'custom', rather than
            // leaving the classifier to guess again and land below the
            // floor a second time.
            'routing_mode' => ['sometimes', 'in:auto,custom,unrelated'],
        ]);

        $effectiveDueDate = Carbon::parse($validated['due_date']);

        try {
            $newDocument = $this->workflow->ingest(
                $validated['file'],
                $request->user(),
                $effectiveDueDate->toDateTimeString(),
                null, // resubmissions stand alone, not re-attached to the original's (possibly already-resolved) batch
                $document,
                $document->requires_printing, // carries forward rather than asking again on every resubmission
                $validated['routing_mode'] ?? 'auto',
            );
        } catch (Throwable $e) {
            Log::error('Unexpected failure ingesting a resubmitted document', [
                'originator_id' => $request->user()->user_id,
                'original_document_id' => $document->document_id,
                'file' => $validated['file']->getClientOriginalName(),
                'exception' => $e->getMessage(),
            ]);

            return redirect()->route('originator.documents.show', $document)
                ->with('status', 'Your resubmission could not be processed due to an unexpected error. Please try again.');
        }

        $status = $newDocument->is_validated
            ? "Resubmitted as version {$newDocument->version_number}, classified as '{$newDocument->ml_category}', and routed for approval."
            : "Resubmitted as version {$newDocument->version_number}, but failed validation — see details below.";

        return redirect()->route('originator.documents.show', $newDocument)->with('status', $status);
    }

    /**
     * The originator edits the document's plain extracted text directly
     * (Feature: "editable document" — see WorkflowService::
     * requestRevision()'s docblock) and, in the same save, marks which
     * open Request Revision annotations this edit addresses. Only the
     * approver(s) behind THOSE specific annotations get notified to
     * re-review — anyone whose flag isn't checked stays open, and the
     * originator can save again later once it is.
     */
    public function saveRevision(Request $request, DocumentRepository $document)
    {
        $this->authorize('editText', $document);

        // A real .docx is still edited "anywhere, text only" — same shape
        // as the plain-textarea path below (edit the text, check off
        // which flags this addresses) — but the editable surface is the
        // real rendered document instead of a plain textarea, and the
        // save is surgical (see WorkflowService::saveDocxRevision() /
        // DocxRichContentService::applyTextEdits()) so images, tables,
        // and formatting are never touched, only text content. See
        // originator/partials/tracking-content.blade.php for the matching
        // view.
        if ($document->isRichDocx()) {
            $validated = $request->validate([
                // One JSON-encoded field, not segment_texts[] — see
                // tracking.blade.php's submit listener for why: the
                // default TrimStrings middleware would otherwise trim
                // each segment's own meaningful leading/trailing space.
                'segment_texts_json' => ['required', 'string'],
                'resolved_annotation_ids' => ['nullable', 'array'],
                'resolved_annotation_ids.*' => ['integer'],
            ]);

            $segmentTexts = json_decode($validated['segment_texts_json'], true);
            if (! is_array($segmentTexts) || array_is_list($segmentTexts) === false) {
                return back()->withErrors(['segment_texts_json' => 'Could not read the edited document — please try again.']);
            }

            try {
                $this->workflow->saveDocxRevision(
                    $document, $request->user(), $segmentTexts, $validated['resolved_annotation_ids'] ?? []
                );
            } catch (\RuntimeException $e) {
                return back()->withErrors(['segment_texts_json' => $e->getMessage()]);
            }

            return back()->with('status', 'Revision saved.');
        }

        $validated = $request->validate([
            'text' => ['required', 'string', 'max:100000'],
            'resolved_annotation_ids' => ['nullable', 'array'],
            'resolved_annotation_ids.*' => ['integer'],
        ]);

        $this->workflow->saveDocumentRevision($document, $request->user(), $validated['text'], $validated['resolved_annotation_ids'] ?? []);

        return back()->with('status', 'Revision saved.');
    }

    /**
     * Streams the exact original uploaded file inline (not force-download)
     * for the embedded viewer on the Approver dashboard. Distinct from
     * ArchiveController::download(), which forces a "Save As" download of
     * already-approved documents; this is for reviewing a file still in
     * progress, unaltered from what the originator submitted.
     */
    public function viewFile(Request $request, DocumentRepository $document)
    {
        $user = $request->user();

        $this->authorize('viewFile', $document);

        // A review session opens for anyone with a legitimate reviewing
        // stake — any approver or admin — while the document as a whole
        // hasn't been judged yet, not narrowly gated to "is it THIS
        // person's specific seat pending right now." That narrower rule
        // used to mean a second approver whose seat wasn't the currently-
        // active one, or an admin just looking without the document being
        // escalated to them specifically, never got tracked at all — so
        // the "currently reviewing" presence cluster only ever showed one
        // of several simultaneous viewers. See countsAsActiveReviewer()
        // for the exact rule; presence()/presenceLeave() below apply the
        // identical condition so opening and closing stay symmetric — an
        // Originator (never an approver or admin) still never gets a
        // session, and once the document IS judged, nobody accumulates a
        // fresh one just from revisiting it in Archive/Decision History.
        if ($this->countsAsActiveReviewer($document, $user)) {
            DocumentReviewSession::openFor($document, $user);
        }

        // Default disk (config('filesystems.default')), not hardcoded
        // 'local' — respects FILESYSTEM_DISK. Storage::response() (unlike
        // response()->file(), which needs an actual local filesystem path
        // and therefore ONLY ever worked against local disk) streams
        // correctly from any configured disk driver, including an
        // S3-compatible one like Cloudflare R2.
        abort_unless(Storage::exists($document->file_path), 404, 'File not found.');

        // A real .docx is kept surgically in sync with any approved
        // revision (see WorkflowService::saveDocxRevision()) — the raw
        // file itself genuinely IS the current document, so it's
        // streamed as-is for mammoth.js (document-viewer-modal.blade.php)
        // to convert. Every other type (.pdf, images, legacy .doc, even
        // .txt) only ever had its PLAIN TEXT revised — the raw file on
        // disk is frozen at whatever was originally uploaded, so serving
        // it here would silently show a stale, un-revised document. See
        // this feature's own plan: for these types, the extracted text
        // (ocr_text) IS the document going forward.
        if ($document->isRichDocx() && $request->query('format') === 'html') {
            $html = app(DocxRichContentService::class)
                ->renderStoredFile($document->file_path)['html'];

            return response($html, 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store, must-revalidate',
            ]);
        }

        if (! $document->isRichDocx()) {
            return response($document->ocr_text ?? '', 200, [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Content-Disposition' => 'inline',
                'Cache-Control' => 'no-store, must-revalidate',
            ]);
        }

        $mime = $document->mime_type ?: 'application/octet-stream';

        $response = Storage::response($document->file_path, $document->original_filename ?? $document->title, [
            'Content-Type' => $mime,
        ]);

        // no-store, not whatever the disk driver's response() defaults to:
        // a browser silently serving a second open from cache skips this
        // whole method entirely, which means openFor() never runs — no
        // review session gets created for that visit, so it never shows up
        // as "reviewing", never gets a realtime push, and its time never
        // gets counted. Every open of this popup needs to genuinely hit the
        // server, not just the first one.
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->addCacheControlDirective('must-revalidate');
        $response->headers->removeCacheControlDirective('public');

        return $response;
    }

    /**
     * Polled every few seconds by the document viewer modal while it's
     * open — returns who's currently reviewing this document (any
     * approver or admin with a legitimate stake, see
     * countsAsActiveReviewer()) and, if the requester is one of them,
     * heartbeats their own session so it stays inside the live() window.
     * Stopping the poll (closing the modal, navigating away, a dead tab)
     * means no more heartbeats, so the icon disappears for everyone else
     * within ~40s without needing an explicit "I closed the viewer"
     * signal.
     */
    public function presence(Request $request, DocumentRepository $document)
    {
        $user = $request->user();

        $this->authorize('viewFile', $document);

        if ($this->countsAsActiveReviewer($document, $user)) {
            DocumentReviewSession::heartbeat($document, $user);
        }

        $viewers = DocumentReviewSession::live()
            ->where('document_id', $document->document_id)
            ->with('user')
            ->get()
            ->unique('user_id')
            ->values()
            ->map(fn ($session) => [
                'name' => $session->user->full_name ?? 'Unknown',
                'role' => ucfirst($session->user->role ?? ''),
                'category' => $session->user->assigned_category,
            ]);

        return response()->json(['viewers' => $viewers]);
    }

    /**
     * Explicit "I closed the viewer" beacon — fired once on modal close
     * or tab unload (see document-viewer-modal.blade.php). Reuses the
     * same closeFor() a real decision uses (not a separate presence-only
     * mechanism): this both makes the presence icon disappear for other
     * viewers instantly (stillOpen() fails the moment closed_at is set,
     * so it drops out of live() for free) AND correctly records
     * duration_seconds for this review pass, so closing the popup without
     * deciding no longer silently loses that time from the running total
     * — reopening later now counts as a genuine second review pass
     * instead.
     */
    public function presenceLeave(Request $request, DocumentRepository $document)
    {
        $user = $request->user();

        // Previously had no authorization check at all — any authenticated
        // approver/admin could fire this beacon against any document,
        // closing a review session they had no real access to. Same
        // 'viewFile' ability as viewFile()/presence() above, kept symmetric
        // with them per this method's own docblock.
        $this->authorize('viewFile', $document);

        if ($this->countsAsActiveReviewer($document, $user)) {
            DocumentReviewSession::closeFor($document, $user);
        }

        return response()->noContent();
    }

    /**
     * Whether $user opening $document right now counts as an active
     * review session (drives DocumentReviewSession::openFor()/heartbeat()/
     * closeFor() in viewFile()/presence()/presenceLeave() above). Moved
     * onto DocumentReviewSession itself so ApprovalController::
     * annotationsPanel() (the "Review & Comment" popup) can share the
     * exact same rule instead of drifting from a second copy.
     */
    private function countsAsActiveReviewer(DocumentRepository $document, $user): bool
    {
        return DocumentReviewSession::countsAsActiveReviewer($document, $user);
    }
}
