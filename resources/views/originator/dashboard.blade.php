@extends('layouts.app')
@section('title', 'Upload & Track')
@section('page-title', 'Upload & Track Documents')

@section('content')

{{-- Feature: capped to the device's own viewport height — never taller,
     so the card itself is never what forces the page to scroll. flex
     flex-col + the list area below as the only flex-1 child is what lets
     the header/search/filter take their natural height while the list
     absorbs (or is absorbed into) whatever's left, instead of the whole
     card just growing with its content.

     Height is set by JS (sizeCappedCard() in resources/js/app.js), not a
     static h-[calc(100vh-Xrem)] — a flash message or validation-error
     banner (see layouts/app.blade.php) can also sit above this card, and
     a fixed calc() has no way to know that happened; live-measuring the
     real remaining space instead means this always fits no matter what
     else rendered above it, without ever forcing <main> into the very
     overflow this feature exists to prevent. --}}
<div id="submissions-card" class="bg-white rounded-xl shadow-card border border-surface-200 overflow-hidden flex flex-col">
    <div class="px-6 py-4 border-b border-surface-200 flex items-center justify-between flex-shrink-0">
        <div class="flex items-baseline gap-2">
            <h2 class="text-sm font-semibold text-surface-900 tracking-tight">Your Submissions</h2>
            <span id="submissions-total" class="text-xs text-surface-400 tabular-nums">{{ $documents->count() }} total</span>
        </div>
        <button type="button" id="new-submission-open"
            class="inline-flex items-center gap-2 bg-gradient-to-b from-primary-600 to-primary-700 hover:from-primary-700 hover:to-primary-800 text-white text-sm font-medium px-4 py-2.5 rounded-lg shadow-sm transition-all">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
            </svg>
            New Submission
        </button>
    </div>

    <form method="GET" class="px-6 py-4 border-b border-surface-200 space-y-3 flex-shrink-0">
        <div class="relative">
            <svg class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-surface-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/>
            </svg>
            <input type="text" id="document-search" name="document" value="{{ request('document') }}"
                placeholder="Search document" autocomplete="off"
                class="w-full rounded-lg border-surface-300 text-sm pl-9 pr-3 py-2 focus:border-primary-500 focus:ring-primary-500">
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <select name="status" onchange="this.form.submit()" class="rounded-lg border-surface-300 text-xs pl-3 pr-8 py-2">
                <option value="">All Statuses</option>
                @foreach(['processing' => 'Processing', 'classified_validated' => 'Awaiting Approval', 'approved' => 'Approved', 'auto_approved' => 'Auto-Approved', 'rejected' => 'Rejected'] as $value => $label)
                    <option value="{{ $value }}" {{ request('status') === $value ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>
            <select name="category" onchange="this.form.submit()" class="rounded-lg border-surface-300 text-xs pl-3 pr-8 py-2">
                <option value="">All Categories</option>
                @foreach($categories as $c)
                    <option value="{{ $c }}" {{ request('category') === $c ? 'selected' : '' }}>{{ $c }}</option>
                @endforeach
            </select>
            <button class="text-xs font-medium bg-primary-700 hover:bg-primary-800 text-white px-4 py-2 rounded-lg shadow-sm transition-colors">Filter</button>
            @if(request('document') || request('status') || request('category'))
                <a href="{{ route('originator.dashboard') }}" class="text-xs font-medium text-surface-500 hover:underline">Clear</a>
            @endif
        </div>
    </form>

    <div id="submissions-list" class="flex-1 min-h-0 overflow-hidden flex flex-col"
        data-user-id="{{ auth()->id() }}" data-poll-url="{{ route('originator.documents.poll') }}"
        data-refresh-url="{{ route('originator.documents.refresh') }}">
        @include('originator.partials.submissions')
    </div>
</div>

{{-- New Submission popup (Feature: the upload form no longer sits permanently
     on the page — opened on demand via the button above, closed via X,
     Escape, or clicking outside it). Statically rendered (just hidden by
     default), not fetched — the existing drag-drop/file-input script below
     needs these exact elements to exist in the DOM from page load. --}}
<div id="new-submission-overlay" class="hidden fixed inset-0 z-50 bg-surface-900/70 flex items-center justify-center p-4" onclick="if(event.target === this) closeNewSubmissionModal()">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-lg max-h-[90vh] flex flex-col overflow-hidden" onclick="event.stopPropagation()">
        <div class="flex items-center justify-between px-6 py-4 border-b border-surface-200 flex-shrink-0">
            <h2 class="text-sm font-semibold text-surface-900 tracking-tight">New Submission</h2>
            <button type="button" onclick="closeNewSubmissionModal()" class="text-surface-400 hover:text-surface-700" aria-label="Close">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <div class="p-6 overflow-y-auto">
            <p class="text-xs text-surface-500 mb-4">The system will classify, validate, and route your document(s) automatically. Select more than one file to submit them together as a single grouped approval request.</p>

            <form method="POST" action="{{ route('originator.documents.store') }}" enctype="multipart/form-data" id="upload-form">
                @csrf
                <label for="file-input" id="dropzone"
                    class="flex flex-col items-center justify-center gap-2 border-2 border-dashed border-surface-300 rounded-xl py-10 px-4 text-center cursor-pointer transition-all hover:border-primary-400 hover:bg-primary-50/50">
                    <span class="w-12 h-12 rounded-full bg-primary-50 flex items-center justify-center">
                        <svg class="w-6 h-6 text-primary-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                        </svg>
                    </span>
                    <span class="text-sm font-medium text-surface-700">Drag & drop your document(s) here</span>
                    <span class="text-xs text-surface-400">or click to browse — PDF, DOCX, DOC, TXT, PNG, JPG (max 20MB each, up to 20 files)</span>
                    <span id="file-name" class="text-xs font-medium text-primary-700 mt-1"></span>
                    <input id="file-input" type="file" name="files[]" class="sr-only" multiple required>
                </label>

                <div class="mt-4">
                    <label for="due_date" class="block text-xs font-medium text-surface-700 mb-1">Due date &amp; time <span class="text-rejected-700">*</span></label>
                    <p class="text-[11px] text-surface-400 mb-1">Approvers' review window is 25% of the time left until this deadline (a flat 15 minutes if due within the next hour). Must fall within working hours (9 AM–5 PM, Mon–Sat).</p>
                    <input type="datetime-local" id="due_date" name="due_date" required min="{{ now()->addMinutes(config('sla.min_due_date_buffer_minutes', 15))->format('Y-m-d\TH:i') }}"
                        class="w-full rounded-lg border-surface-300 focus:border-primary-500 focus:ring-primary-500 text-sm px-3 py-2">
                    {{-- Client-side heads-up only — not authoritative. The
                         server (WorkflowService::isDueDateWithinWorkingHours())
                         is what actually enforces this; this just lets the
                         originator notice and fix it before submitting
                         instead of finding out after. --}}
                    <p id="due-date-warning" class="hidden mt-1 text-[11px] text-rejected-700">This falls outside working hours (9 AM–5 PM, Mon–Sat) or on a holiday — pick a different date/time.</p>
                </div>

                <label class="mt-3 flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="requires_printing" value="1" class="rounded border-surface-300 text-primary-600 focus:ring-primary-500">
                    <span class="text-xs text-surface-600">Requires a printed copy</span>
                </label>

                {{-- Originator-directed routing (Feature: bypass the standard
                     pipeline and pick the approver(s) yourself — see
                     WorkflowService::routeToCustomApprovers()). Collapsed by
                     default — the standard process is what almost every
                     upload should use; this is an opt-in exception, not
                     something to make more prominent than the normal path.
                     Styled as a real button (bg/border) rather than plain
                     underlined text, so it reads as clearly clickable.
                     Picking 'custom' or 'unrelated' here does NOT open any
                     picker — approver selection for both only ever happens
                     AFTER the document is uploaded and classified, via the
                     "Select Approver(s)" link on the submissions table
                     (DocumentController::selectApprovers()). --}}
                <details class="mt-3 group">
                    <summary class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-surface-100 hover:bg-surface-200 text-xs font-medium text-surface-700 cursor-pointer select-none transition-colors">
                        Need a different approval process?
                    </summary>
                    <div class="mt-2 space-y-2 pl-1">
                        <label class="flex items-start gap-2 cursor-pointer">
                            <input type="radio" name="routing_mode" value="auto" checked class="mt-0.5 border-surface-300 text-primary-600 focus:ring-primary-500">
                            <span class="text-xs text-surface-600"><span class="font-medium text-surface-800">Standard process</span> — classified and routed automatically.</span>
                        </label>
                        <label class="flex items-start gap-2 cursor-pointer">
                            <input type="radio" name="routing_mode" value="custom" class="mt-0.5 border-surface-300 text-primary-600 focus:ring-primary-500">
                            <span class="text-xs text-surface-600"><span class="font-medium text-surface-800">Choose the approver(s) yourself</span> — skip the standard pipeline, pick exactly who reviews this.</span>
                        </label>
                        <label class="flex items-start gap-2 cursor-pointer">
                            <input type="radio" name="routing_mode" value="unrelated" class="mt-0.5 border-surface-300 text-primary-600 focus:ring-primary-500">
                            <span class="text-xs text-surface-600"><span class="font-medium text-surface-800">This doesn't belong to any of our categories</span> — not a Job Order, Purchase Requisition, etc. — pick who reviews it.</span>
                        </label>
                    </div>
                </details>

                <button type="submit" id="upload-submit-btn"
                    class="mt-4 w-full bg-gradient-to-b from-primary-600 to-primary-700 hover:from-primary-700 hover:to-primary-800 text-white text-sm font-medium py-2.5 rounded-lg shadow-sm transition-all disabled:opacity-60 disabled:cursor-not-allowed">
                    Submit Document(s)
                </button>
            </form>
        </div>
    </div>
</div>

<script>
    // Bug fix (confirmed 2026-10-01 in production — two separate
    // DocumentRepository rows for the same file, 3 seconds apart): nothing
    // visibly happens the instant Submit is clicked (the browser is busy
    // uploading), so a second click before that registers fires a second,
    // fully independent form submission — each one creates its own
    // SubmissionBatch + document. Disabling the button the moment the
    // form's submit event actually fires (not on click — click could be
    // cancelled by the browser's own required-field validation, which
    // would leave the button stuck disabled with no submission in flight)
    // closes that window; the page navigates away on success, so there's
    // nothing to re-enable it for.
    document.getElementById('upload-form').addEventListener('submit', function () {
        document.getElementById('upload-submit-btn').disabled = true;
    });

    // New Submission popup open/close.
    document.getElementById('new-submission-open').addEventListener('click', function () {
        document.getElementById('new-submission-overlay').classList.remove('hidden');
    });
    function closeNewSubmissionModal() {
        document.getElementById('new-submission-overlay').classList.add('hidden');
    }
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeNewSubmissionModal();
    });

    // Real-time, client-side filter over the rows already rendered on this
    // page — instant, no round trip. Pressing Enter still submits the
    // surrounding <form> normally, running the "document" filter
    // server-side (see DocumentController::dashboard()) across every page.
    // A plain function (not an IIFE) so it can be re-run after every live
    // swap below — otherwise a swap would silently undo an active search.
    function applySubmissionFilter() {
        const input = document.getElementById('document-search');
        const rows = Array.from(document.querySelectorAll('.submission-row'));
        const noMatches = document.getElementById('submission-no-matches');
        const noMatchesTerm = document.getElementById('submission-no-matches-term');
        if (!input || rows.length === 0 || !noMatches) return;

        const term = input.value.trim().toLowerCase();
        let visibleCount = 0;

        rows.forEach((row) => {
            // A row pagination has hidden on another page (see
            // resources/js/app.js's showPage()) stays exactly as
            // pagination left it — this filter only ever narrows the
            // CURRENT page's rows further, never reaches across pages
            // (that's what "Press Enter to search every page" below is
            // for).
            if (row.dataset.fittedOffPage === '1') return;
            const matches = term === '' || row.dataset.documentTitle.includes(term);
            row.classList.toggle('hidden', !matches);
            if (matches) visibleCount++;
        });

        const showNoMatches = term !== '' && visibleCount === 0;
        noMatches.classList.toggle('hidden', !showNoMatches);
        if (showNoMatches) noMatchesTerm.textContent = input.value.trim();
    }

    document.getElementById('document-search')?.addEventListener('input', applySubmissionFilter);

    // Heads-up only, not authoritative (see the <p> this toggles) — reuses
    // the same [name="business-hours"] meta tag the live SLA countdown
    // ticker in layouts/app.blade.php already reads, rather than a
    // separate config source. datetime-local's value has no timezone
    // attached (it's plain wall-clock numbers as typed), so this reads it
    // with plain Date getters (getDay/getHours/...), NOT toISOString() —
    // that would incorrectly convert through the browser's own local
    // timezone, which has nothing to do with the number the user actually
    // typed.
    (function () {
        const input = document.getElementById('due_date');
        const warning = document.getElementById('due-date-warning');
        if (!input || !warning) return;

        const config = JSON.parse(document.querySelector('meta[name="business-hours"]')?.content || 'null');

        function isWithinWorkingHours(date) {
            if (!config || isNaN(date.getTime())) return true; // fail open — never block on missing/invalid data

            if (!config.workingDays.includes(date.getDay())) return false;

            const y = date.getFullYear();
            const m = String(date.getMonth() + 1).padStart(2, '0');
            const d = String(date.getDate()).padStart(2, '0');
            if (config.holidays.includes(`${y}-${m}-${d}`)) return false;

            const minutes = date.getHours() * 60 + date.getMinutes();
            return minutes >= config.startMinutes && minutes < config.endMinutes;
        }

        input.addEventListener('change', function () {
            const valid = !input.value || isWithinWorkingHours(new Date(input.value));
            warning.classList.toggle('hidden', valid);
        });
    })();

    // Live-updates the submissions table without a full page reload —
    // instant via Reverb (see startLiveChannel in resources/js/app.js)
    // the moment one of this originator's documents changes status
    // (processing -> approved, say) or a new one is routed; the slow poll
    // behind it is only a fallback in case the WebSocket connection is down.
    //
    // Wrapped in DOMContentLoaded, not a bare IIFE — see the matching
    // comment in approver/dashboard.blade.php for why: this plain inline
    // script would otherwise run before app.js's deferred module script
    // has defined startLiveChannel/startLivePoll, throw immediately, and
    // silently never wire anything up.
    document.addEventListener('DOMContentLoaded', function () {
        const listEl = document.getElementById('submissions-list');
        if (!listEl) return;

        // See resources/js/app.js's sizeCappedCard() docblock — measured
        // once here (after the flash message above, if any, has already
        // rendered) and again on resize; nothing above this card changes
        // after that, so a live swap doesn't need to re-measure it.
        const submissionsCard = document.getElementById('submissions-card');
        sizeCappedCard(submissionsCard);

        // Fitted pagination — see resources/js/app.js's
        // initFittedPagination() for the full mechanism (shared with the
        // admin User Accounts page). Called from inside this same
        // DOMContentLoaded handler (not at the top level) for the same
        // reason startLiveChannel/startLivePoll below are — see this
        // block's own opening comment. refit() is re-run after a live
        // swap below, since that replaces this fragment's rows/pagination
        // container out from under the running instance.
        const fittedPagination = initFittedPagination('submissions-list', '.submission-row');

        // Card height AND row count re-measured together, in that order,
        // on every resize — including a text-size change (see app.js's
        // text-size control, which dispatches a synthetic resize event
        // for exactly this). fittedPagination is referenced here even
        // though it's declared just above, not before — fine, since this
        // callback only ever runs later, on an actual resize.
        window.addEventListener('resize', () => {
            sizeCappedCard(submissionsCard);
            fittedPagination.refit();
        });

        const opts = {
            refreshUrl: listEl.dataset.refreshUrl,
            target: listEl,
            preserveQueryString: true,
            onSwap: () => {
                const total = listEl.querySelector('[data-total-count]')?.dataset.totalCount;
                if (total !== undefined) {
                    document.getElementById('submissions-total').textContent = total + ' total';
                }
                // refit() first, THEN re-apply the search term — refit()
                // resets every row's page/hidden state from scratch, so
                // running it after the filter would just wipe out
                // whatever the filter had just done.
                fittedPagination.refit();
                applySubmissionFilter();
            },
        };

        startLiveChannel(`originator.${listEl.dataset.userId}`, '.document.status-changed', opts);
        startLivePoll({ ...opts, pollUrl: listEl.dataset.pollUrl });
    });
</script>
@endsection

@push('scripts')
<script>
    const input = document.getElementById('file-input');
    const dropzone = document.getElementById('dropzone');
    const fileName = document.getElementById('file-name');

    function describeFiles(fileList) {
        if (!fileList.length) return '';
        if (fileList.length === 1) return fileList[0].name;
        return fileList.length + ' files selected: ' + Array.from(fileList).map(f => f.name).join(', ');
    }

    input.addEventListener('change', () => {
        fileName.textContent = describeFiles(input.files);
    });

    ['dragover', 'dragenter'].forEach(evt =>
        dropzone.addEventListener(evt, e => { e.preventDefault(); dropzone.classList.add('border-primary-500', 'bg-primary-50'); })
    );
    ['dragleave', 'drop'].forEach(evt =>
        dropzone.addEventListener(evt, e => { e.preventDefault(); dropzone.classList.remove('border-primary-500', 'bg-primary-50'); })
    );
    dropzone.addEventListener('drop', e => {
        if (e.dataTransfer.files.length) {
            input.files = e.dataTransfer.files;
            fileName.textContent = describeFiles(input.files);
        }
    });
</script>
@endpush
