{{--
    The whole tracking page body — split out from tracking.blade.php so the
    same markup can be rendered two ways: a normal full page load, and a
    fragment returned by DocumentController::trackingRefresh() for the
    live-poll JS to swap in place (see tracking.blade.php) without a full
    page reload.

    Layout (Feature: no more scrolling all the way down just to see the
    Document Tracker): a 2-column grid on wide screens — the document header +
    Document Tracker stacked in the left half (Document Tracker sized by JS
    to exactly fill the remaining space below the header card, see
    tracking.blade.php's sizeDocumentTracker() — a fixed CSS calc() can't do
    this correctly since the header card's own height varies with its
    content: version badge, resubmit form, Imported/Superseded notices,
    etc.), Approval Stages plus any open Revision Requests stacked in the
    right half. Stacks back to one column on narrow/mobile. Shared by both
    the Originator's own tracking
    page and Admin's Document Tracking module, since both route through
    DocumentController::show() to this same partial.

    Feature: a way back without relying on the sidebar or the browser's
    own back button — this page is always reached by clicking INTO
    something from a list (Your Submissions for an Originator; the
    Document Tracking module or Audit Logs for an Admin, who can inspect
    any document — see routes/web.php's documents.track comment), never
    from the sidebar directly, so it's the one place in the app that
    genuinely needs an explicit return link. Admin's two possible origins
    are disambiguated by a `from` query param the caller links with (see
    admin/partials/audit-row.blade.php); Document Tracking is the default
    since it's the more common path in. An Originator only ever has one
    possible origin, so no param needed there. Computed HERE rather than
    in tracking.blade.php — this partial is also what trackingRefresh()
    returns directly for the live-poll swap, which never touches
    tracking.blade.php at all, so the link has to keep resolving
    correctly on every refresh too, not just the first page load.
--}}
@php
    $backTarget = auth()->user()->isOriginator()
        ? ['label' => 'Your Submissions', 'url' => route('originator.dashboard')]
        : (request('from') === 'audit'
            ? ['label' => 'Audit Logs', 'url' => route('admin.audit.logs')]
            : ['label' => 'Document Tracking', 'url' => route('admin.documents.index')]);
@endphp
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
    <div class="space-y-6 flex flex-col">
        <div class="bg-white rounded-xl shadow-card border border-surface-200 p-6">
            {{-- Tucked inside the header card itself, right above the
                 title, instead of floating above the card as its own
                 element — that used to leave a slab of empty space at the
                 top of the page before any real content appeared. Styled
                 as the same light pill used for "All Categories" on
                 Archive/SLA Violation Reports, not a solid button — this
                 is the first thing on the page now, so it shouldn't read
                 as heavier than the content below it. --}}
            <a href="{{ $backTarget['url'] }}" class="inline-flex items-center gap-1 text-xs font-medium text-primary-700 bg-primary-50 hover:bg-primary-100 ring-1 ring-inset ring-primary-500/20 rounded-full px-3 py-1.5 transition-colors mb-3">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                Back to {{ $backTarget['label'] }}
            </a>
            <div class="flex items-start justify-between mb-6">
                <div>
                    <div class="flex items-center gap-2">
                        <h2 class="text-base font-semibold text-surface-900">{{ $document->title }}</h2>
                        @if($document->version_number > 1)
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-indigo-50 text-indigo-700">v{{ $document->version_number }}</span>
                        @endif
                        @if($document->is_legacy_import)
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-processing-50 text-processing-700">Imported</span>
                        @endif
                        {{-- Feature: originator-directed routing — a permanent
                             marker that this document skipped the standard
                             pipeline in favor of hand-picked approver(s), kept
                             after routing completes (see WorkflowService::
                             routeToCustomApprovers()). custom_routed alone
                             can't tell option 2 ("choose the approver(s)
                             yourself" — still a real matched category) apart
                             from option 3 ("doesn't belong to any of our
                             categories") — both set it the same way, so the
                             badge has to branch on desired_routing instead,
                             the field that actually distinguishes the two. --}}
                        @if($document->custom_routed && $document->desired_routing === 'unrelated')
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-indigo-50 text-indigo-700" title="Flagged as not belonging to any trained category, then routed directly to hand-picked approver(s)">Other &middot; Custom Routed</span>
                        @elseif($document->custom_routed)
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-indigo-50 text-indigo-700" title="Routed directly to hand-picked approver(s) instead of the standard pipeline">Custom Routed</span>
                        @endif
                        {{-- Feature: Revision History (Google-Docs-style —
                             see documents/partials/revision-history-list.
                             blade.php). Same viewTracking population as
                             this whole page: originator, any assigned
                             approver, Admin — so it's always offered here,
                             not gated behind open Revision Requests below
                             (a past revision can still be worth reviewing
                             after every flag on it is already resolved). --}}
                        <button type="button"
                            onclick="openKpiDrilldown('revision-history', 'Revision History', {{ Js::from(route('documents.revisions', $document)) }})"
                            class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold bg-surface-100 text-surface-600 hover:bg-surface-200 transition-colors cursor-pointer">
                            History
                        </button>
                        @if($document->requires_printing && in_array($document->global_status, ['approved', 'auto_approved']))
                            @if(auth()->user()->isOriginator())
                                <button type="button"
                                    onclick="openDocumentViewer('{{ route('documents.file', $document) }}', '{{ $document->mime_type }}', '{{ addslashes($document->original_filename ?? $document->title) }}', {{ $document->document_id }}, true)"
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-indigo-50 text-indigo-700 hover:bg-indigo-100 transition-colors cursor-pointer">
                                    🖨 Print Required
                                </button>
                            @else
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-indigo-50 text-indigo-700">🖨 Print Required</span>
                            @endif
                        @endif
                    </div>
                    @if($document->is_legacy_import)
                        <p class="text-sm text-processing-700 mt-1">
                            Imported directly by an administrator — not classified, validated, or peer-reviewed through the normal approval workflow.
                        </p>
                    @endif
                    @if($document->is_security_blocked)
                        <p class="text-sm text-rejected-700 mt-1">
                            This upload could not be accepted — it failed an automatic security scan and was blocked before reaching any reviewer.
                            If you believe this is a mistake, resubmit a corrected version below.
                        </p>
                    @endif
                    {{-- $disputeNote: the Admin's own note from whichever
                         auto-approved stage they disputed (admin_review_note
                         on the assignment, see AdminController::
                         reviewAutoApproval()) — shown so the originator
                         knows WHAT to fix, not just that something's wrong. --}}
                    @if($document->display_status === 'disputed' && !$document->nextVersion)
                        @php $disputeNote = $document->assignments->firstWhere('admin_review_outcome', 'disputed')?->admin_review_note; @endphp
                        <p class="text-sm text-processing-700 mt-1">
                            This was auto-approved, but an Admin disputed it{{ $disputeNote ? ': "'.$disputeNote.'"' : '' }}.
                            Please resubmit a corrected version below.
                        </p>
                    @endif
                    @if($document->pending_custom_routing_at && auth()->user()->isOriginator())
                        <p class="text-sm text-amber-700 mt-1">
                            Ready to route —
                            <button type="button"
                                onclick="openKpiDrilldown('select-approvers', 'Select Approver(s) — {{ addslashes($document->title) }}', '{{ route('originator.documents.selectApprovers', $document) }}')"
                                class="font-medium hover:underline">select the approver(s)</button>
                            you'd like this to go to.
                        </p>
                    @endif
                    @if($document->previousVersion)
                        <p class="text-sm text-surface-500 mt-1">
                            Resubmission of
                            <a href="{{ route('originator.documents.show', $document->previousVersion) }}" class="text-primary-700 hover:underline font-medium">
                                "{{ $document->previousVersion->title }}" (v{{ $document->previousVersion->version_number }})
                            </a>, which was rejected.
                        </p>
                    @endif
                    @if($document->nextVersion)
                        <p class="text-sm text-rejected-700 mt-1">
                            Superseded by
                            <a href="{{ route('originator.documents.show', $document->nextVersion) }}" class="hover:underline font-medium">
                                a resubmitted version (v{{ $document->nextVersion->version_number }})
                            </a> — that one reflects the current state of this request.
                        </p>
                    @endif
                    <p class="text-sm text-surface-500 mt-1">
                        Category: <span class="font-medium text-surface-700">{{ $document->display_category ?? 'Other' }}</span>
                        {{-- Classification, readability and validation genuinely
                             run on every document, "unrelated" ones included —
                             see WorkflowService::ingest() — so this shows the
                             classifier's guess either way. For an "unrelated"
                             document that guess is never authoritative (see
                             DocumentRepository::display_category), so it's
                             worded as a "best guess" here instead of a bare
                             "Confidence:" label, to avoid it reading as
                             contradicting the "Other" category shown above. --}}
                        @if($document->desired_routing === 'unrelated')
                            @if($document->ml_category && $document->ml_confidence !== null)
                                &middot; <span class="text-surface-400">Classifier's best guess: {{ $document->ml_category }} ({{ $document->ml_confidence }}%) — not used, since this was marked as not belonging to any category</span>
                            @endif
                        @elseif($document->ml_rechecked_at)
                            &middot; <span class="text-surface-400">Recheck Confidence: {{ $document->ml_confidence }}% &rarr; {{ $document->ml_recheck_confidence }}%</span>
                        @elseif($document->ml_confidence)
                            &middot; Confidence: {{ $document->ml_confidence }}%
                        @endif
                        @if($document->readability_score !== null)
                            @if($document->ml_recheck_readability_score !== null)
                                &middot; <span class="text-surface-400">Recheck Readability: {{ $document->readability_score }}% &rarr; {{ $document->ml_recheck_readability_score }}%</span>
                            @else
                                &middot; Readability: {{ $document->readability_score }}%
                                @if($document->readability_score < config('ml.min_real_word_ratio', 0.7) * 100)
                                    <span class="text-surface-400">(scored low — likely vocabulary the model hasn't learned yet, not an error)</span>
                                @endif
                            @endif
                        @endif
                        @if($document->used_ocr_fallback)
                            &middot; <span class="text-processing-700">OCR fallback used</span>
                        @endif
                        {{-- Never offered for a security-blocked upload —
                             see DocumentRepositoryPolicy::viewFile(), which
                             denies this file to everyone, even an Admin. --}}
                        @unless($document->is_security_blocked)
                            &middot;
                            <button type="button"
                                onclick="openDocumentViewer('{{ route('documents.file', $document) }}', '{{ $document->mime_type }}', '{{ addslashes($document->original_filename ?? $document->title) }}', {{ $document->document_id }})"
                                class="text-primary-700 hover:underline font-medium">View original file</button>
                        @endunless
                    </p>
                </div>
                <x-status-badge :status="$document->display_status" />
            </div>

            <x-lifecycle-stepper :document="$document" />

            {{-- See DocumentRepository::isResubmittable()'s docblock for
                 the three stuck states this covers (rejected, a failed
                 validation/extraction, or a disputed auto-approval) —
                 shared with DocumentController::resubmit()'s own gate so
                 the button is never shown somewhere the submit would
                 actually be rejected, or hidden somewhere it would work.
                 Bug fix (2026-10-08): that gate alone isn't enough — it
                 only checks the DOCUMENT's state, never who's looking at
                 it. Resubmitting is owner-only (see
                 DocumentRepositoryPolicy::resubmit()), but Admin can also
                 view this same tracker (viewTracking() allows it), so
                 Admin was seeing a working-looking form that would be
                 rejected (403) the moment they actually tried to use it.
                 This form is this document's own originator's action, not
                 a moderation tool — shown only to them. --}}
            @if($document->isResubmittable() && !$document->nextVersion && auth()->id() === $document->originator_id)
                <div class="mt-6 pt-6 border-t border-surface-200">
                    <details class="text-sm">
                        {{-- bg + padding, not just colored underlined text —
                             matches the "Need a different approval process?"
                             toggle below (and buttons elsewhere in the app)
                             so this reads as a clickable control at a
                             glance, not a plain caption. --}}
                        <summary class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-primary-50 hover:bg-primary-100 text-primary-700 font-medium cursor-pointer select-none transition-colors">Resubmit a revised version</summary>
                        <form method="POST" action="{{ route('originator.documents.resubmit', $document) }}" enctype="multipart/form-data" class="mt-3 space-y-3 max-w-sm">
                            @csrf
                            <div>
                                <label class="block text-sm font-medium text-surface-700 mb-1">Revised document</label>
                                <input type="file" name="file" required class="w-full text-sm">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-surface-700 mb-1">Due date &amp; time</label>
                                <p class="text-xs text-surface-400 mb-1">Must fall within working hours (9 AM–5 PM, Mon–Sat).</p>
                                <input type="datetime-local" id="resubmit-due-date" name="due_date" required min="{{ now()->addMinutes(config('sla.min_due_date_buffer_minutes', 15))->format('Y-m-d\TH:i') }}"
                                    class="w-full rounded-lg border-surface-300 text-sm px-3 py-2">
                                <p id="resubmit-due-date-warning" class="hidden mt-1 text-xs text-rejected-700">This falls outside working hours (9 AM–5 PM, Mon–Sat) or on a holiday — pick a different date/time.</p>
                            </div>
                            {{-- Same routing_mode choice as a fresh upload (see
                                 originator/dashboard.blade.php) — matters most
                                 right here: a document rejected for an
                                 ambiguous classification can be resubmitted
                                 with the originator picking the category/
                                 approver(s) themselves, instead of leaving the
                                 classifier to guess again. --}}
                            <details class="group">
                                <summary class="text-xs font-medium text-primary-700 hover:underline cursor-pointer select-none">Need a different approval process?</summary>
                                <div class="mt-2 space-y-2 pl-1">
                                    <label class="flex items-start gap-2 cursor-pointer">
                                        <input type="radio" name="routing_mode" value="auto" checked class="mt-0.5 border-surface-300 text-primary-600 focus:ring-primary-500">
                                        <span class="text-xs text-surface-600"><span class="font-medium text-surface-800">Standard process</span> — classified and routed through the full approval pipeline automatically.</span>
                                    </label>
                                    <label class="flex items-start gap-2 cursor-pointer">
                                        <input type="radio" name="routing_mode" value="custom" class="mt-0.5 border-surface-300 text-primary-600 focus:ring-primary-500">
                                        <span class="text-xs text-surface-600"><span class="font-medium text-surface-800">Choose the approver(s) yourself</span> — still classified and validated normally; you'll pick who reviews it right after this uploads.</span>
                                    </label>
                                    <label class="flex items-start gap-2 cursor-pointer">
                                        <input type="radio" name="routing_mode" value="unrelated" class="mt-0.5 border-surface-300 text-primary-600 focus:ring-primary-500">
                                        <span class="text-xs text-surface-600"><span class="font-medium text-surface-800">This doesn't belong to any of our categories</span> — skips category-specific validation; you'll pick who reviews it right after this uploads.</span>
                                    </label>
                                </div>
                            </details>
                            <button class="w-full bg-primary-700 hover:bg-primary-800 text-white text-sm font-medium py-2 rounded-lg">Resubmit</button>
                        </form>
                    </details>
                </div>
            @endif
        </div>

        {{-- flex-1 + min-h-0: lets this card's own inner scroll area (below)
             claim exactly the space left over after the header card above
             it, instead of growing past the viewport and forcing <main>
             (this app's real scroll container — see layouts/app.blade.php)
             to scroll the whole page. The precise height is set by JS
             (sizeDocumentTracker() in tracking.blade.php), recalculated on
             load/resize/live-refresh — a fixed CSS max-height can't do this
             correctly since the header card above varies in height. --}}
        {{-- flex-1 + min-h-0: lets <x-document-tracker>'s own inner scroll
             area claim exactly the space left over after the header card
             above it, instead of growing past the viewport and forcing
             <main> (this app's real scroll container — see
             layouts/app.blade.php) to scroll the whole page. The precise
             height is set by JS (sizeDocumentTracker() in
             tracking.blade.php), recalculated on load/resize/live-refresh
             — a fixed CSS max-height can't do this correctly since the
             header card above varies in height. --}}
        <div id="document-tracker-card" class="bg-white rounded-xl shadow-card border border-surface-200 overflow-hidden flex-1 min-h-0 flex flex-col">
            <x-document-tracker :document="$document" :fill="true" />
        </div>
    </div>

    {{-- overflow-y-auto + JS-computed max-height (sizeTrackingRightColumn()
         in tracking.blade.php), same trick #document-tracker-scroll on the
         left already uses. Approval Stages alone always fit — it was
         Revision Requests being added underneath it (Feature: editable
         document) that first made this column tall enough to occasionally
         outgrow the viewport, forcing <main> — the real page scroll
         container — to scroll the WHOLE page instead of just this one
         column scrolling internally. --}}
    <div id="tracking-right-column" class="space-y-6 overflow-y-auto">
        <div class="bg-white rounded-xl shadow-card border border-surface-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-surface-200">
                <h3 class="text-sm font-semibold text-surface-900">Approval Stages</h3>
            </div>
            <div class="p-6">
                <x-workflow-stage-list :document="$document" :business-hours="$businessHours ?? null" />
            </div>
        </div>

        {{-- "Editable document" (Feature: an approver flagged a specific
             passage — see WorkflowService::requestRevision() — the
             originator fixes it right here instead of re-uploading a
             whole new file). Only shown at all once there's something
             open; only the document's actual owner gets the edit
             controls (see DocumentRepositoryPolicy::editText()) — an
             Admin viewing someone else's tracking page sees the same
             flagged passages, read-only, for context. Sits under
             Approval Stages rather than in the left column — it's about
             those same per-approver decisions, not the document's raw
             activity log next to it on the left. --}}
        @if($document->openAnnotations->isNotEmpty())
            <div class="bg-white rounded-xl shadow-card border border-rejected-500/20 p-6">
                <h3 class="text-sm font-semibold text-surface-900 mb-1">Revision Requests</h3>
                <p class="text-sm text-surface-500 mb-4">{{ $document->openAnnotations->count() }} open — flagged by a reviewer, not yet addressed.</p>

                @can('editText', $document)
                    @if($document->isRichDocx())
                        {{-- Same shape as every other file type's revision
                             screen below (checklist of open flags, then one
                             editable area) — the only real difference is
                             what's editable: the actual rendered document
                             (images/tables/formatting shown exactly as
                             uploaded) instead of a plain textarea, and only
                             TEXT can change there — see WorkflowService::
                             saveDocxRevision() / DocxRichContentService::
                             applyTextEdits(), which rejects the whole save
                             rather than risk corrupting the file if
                             anything besides text was touched (an image or
                             table row added/removed). The submit listener
                             that reads #docx-revision-editor's current text
                             back out lives in tracking.blade.php, not here
                             — a <script> tag in a fragment this page swaps
                             via innerHTML on every live update never runs. --}}
                        <form method="POST" action="{{ route('originator.documents.saveRevision', $document) }}" id="docx-revision-form" class="space-y-4">
                            @csrf
                            <ul class="space-y-3">
                                @foreach($document->openAnnotations as $annotation)
                                    <li class="rounded-lg border border-surface-200 p-3">
                                        <label class="flex items-start gap-2 cursor-pointer">
                                            {{-- data-start/data-end (Feature: jump straight to the
                                                 flagged spot below — see the delegated 'change'
                                                 listener in tracking.blade.php, which locates the
                                                 matching text inside #docx-revision-editor the same
                                                 way it already does for the plain-textarea case). --}}
                                            <input type="checkbox" name="resolved_annotation_ids[]" value="{{ $annotation->annotation_id }}"
                                                data-start="{{ $annotation->start_offset }}" data-end="{{ $annotation->end_offset }}"
                                                class="mt-1 rounded border-surface-300">
                                            <span class="min-w-0">
                                                <span class="block text-sm text-surface-800 italic">&ldquo;{{ $annotation->selected_text }}&rdquo;</span>
                                                <span class="block text-sm text-surface-500 mt-1">{{ $annotation->raisedBy->full_name }}: {{ $annotation->comment }}</span>
                                            </span>
                                        </label>
                                    </li>
                                @endforeach
                            </ul>
                            <p class="text-xs text-surface-400">Check off whichever flags this edit addresses — only those reviewers are notified to re-review. Edit any text directly below, including inside a table — images, tables, and formatting themselves can't be changed here.</p>
                            @error('segment_texts_json')
                                <p class="text-sm text-rejected-700 font-medium">{{ $message }}</p>
                            @enderror
                            <div>
                                <label class="block text-sm font-medium text-surface-700 mb-1">Document text</label>
                                <div id="docx-revision-editor" contenteditable="true"
                                    class="w-full rounded-lg border border-surface-300 text-sm px-3 py-2 leading-relaxed max-h-96 overflow-y-auto bg-white"
                                    style="white-space: pre-wrap;">{!! app(\App\Services\DocxRichContentService::class)->renderStoredFile(
                                        $document->file_path, [], true
                                    )['html'] !!}</div>
                            </div>
                            {{-- Starts disabled — see the delegated listener in
                                 tracking.blade.php, which re-enables it only
                                 once a segment's text actually differs from
                                 what it started as, or a flag is checked. --}}
                            <button type="submit" id="docx-revision-submit" disabled class="bg-primary-700 hover:bg-primary-800 disabled:opacity-50 disabled:cursor-not-allowed text-white text-sm font-semibold px-4 py-2.5 rounded-lg">Save Revision</button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('originator.documents.saveRevision', $document) }}" class="space-y-4">
                            @csrf
                            <ul class="space-y-3">
                                @foreach($document->openAnnotations as $annotation)
                                    <li class="rounded-lg border border-surface-200 p-3">
                                        <label class="flex items-start gap-2 cursor-pointer">
                                            {{-- data-start/data-end (Feature: jump straight to the
                                                 flagged spot in the text below instead of leaving the
                                                 originator to hunt for it — see the delegated 'change'
                                                 listener in tracking.blade.php). Same character
                                                 positions stored against ocr_text when this was raised
                                                 — see DocumentAnnotation's docblock for the one caveat:
                                                 they can drift if an EARLIER partial save already
                                                 changed the surrounding text length while this
                                                 particular flag stayed unresolved. --}}
                                            <input type="checkbox" name="resolved_annotation_ids[]" value="{{ $annotation->annotation_id }}"
                                                data-start="{{ $annotation->start_offset }}" data-end="{{ $annotation->end_offset }}"
                                                class="mt-1 rounded border-surface-300">
                                            <span class="min-w-0">
                                                <span class="block text-sm text-surface-800 italic">&ldquo;{{ $annotation->selected_text }}&rdquo;</span>
                                                <span class="block text-sm text-surface-500 mt-1">{{ $annotation->raisedBy->full_name }}: {{ $annotation->comment }}</span>
                                            </span>
                                        </label>
                                    </li>
                                @endforeach
                            </ul>
                            <p class="text-xs text-surface-400">Check off whichever flags this edit addresses — only those reviewers are notified to re-review. Checking one selects the exact flagged passage below so it's not confused with similar-looking text elsewhere in the document.</p>
                            <div>
                                <label class="block text-sm font-medium text-surface-700 mb-1">Document text</label>
                                <textarea id="revision-text-editor" name="text" rows="10" required class="w-full rounded-lg border-surface-300 text-sm px-3 py-2 font-mono">{{ $document->ocr_text }}</textarea>
                            </div>
                            {{-- Starts disabled — see the delegated listener in
                                 tracking.blade.php, which re-enables it only
                                 once the text actually differs from what it
                                 started as, or a flag is checked. --}}
                            <button type="submit" id="revision-text-submit" disabled class="bg-primary-700 hover:bg-primary-800 disabled:opacity-50 disabled:cursor-not-allowed text-white text-sm font-semibold px-4 py-2.5 rounded-lg">Save Revision</button>
                        </form>
                    @endif
                @else
                    <ul class="space-y-3">
                        @foreach($document->openAnnotations as $annotation)
                            <li class="rounded-lg border border-surface-200 p-3">
                                <span class="block text-sm text-surface-800 italic">&ldquo;{{ $annotation->selected_text }}&rdquo;</span>
                                <span class="block text-sm text-surface-500 mt-1">{{ $annotation->raisedBy->full_name }}: {{ $annotation->comment }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endcan
            </div>
        @endif
    </div>
</div>
