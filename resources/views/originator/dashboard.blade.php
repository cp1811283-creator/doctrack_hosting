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

            {{-- data-post-max-bytes: PHP's own currently-configured
                 post_max_size (see DocumentController::dashboard()'s
                 $postMaxBytes), not hardcoded — so this check always
                 matches whatever the real ceiling actually is in THIS
                 environment, instead of silently drifting out of sync
                 with it. 15MB/file x 10 files = 150MB total is
                 deliberate, not a coincidence — the per-file and
                 file-count rules below can never multiply out to more
                 than the real total ceiling this reads, unlike the
                 numbers this page used before (20MB x 20 files = 400MB,
                 which could pass both those rules individually and still
                 get rejected at the infrastructure layer with no
                 app-level rule explaining why — confirmed real gap). --}}
            <form method="POST" action="{{ route('originator.documents.store') }}" enctype="multipart/form-data" id="upload-form"
                data-post-max-bytes="{{ $postMaxBytes }}">
                @csrf
                <label for="file-input" id="dropzone"
                    class="flex flex-col items-center justify-center gap-2 border-2 border-dashed border-surface-300 rounded-xl py-10 px-4 text-center cursor-pointer transition-all hover:border-primary-400 hover:bg-primary-50/50">
                    <span class="w-12 h-12 rounded-full bg-primary-50 flex items-center justify-center">
                        <svg class="w-6 h-6 text-primary-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                        </svg>
                    </span>
                    <span class="text-sm font-medium text-surface-700">Drag & drop your document(s) here</span>
                    <span class="text-xs text-surface-400">or click to browse — PDF, DOCX, DOC, TXT, PNG, JPG (max 15MB each, up to 10 files, 150MB total)</span>
                    <span id="file-name" class="text-xs font-medium text-primary-700 mt-1"></span>
                    <input id="file-input" type="file" name="files[]" class="sr-only" multiple required>
                </label>
                {{-- Shown by checkFileSizes() in the pushed scripts block
                     further down this file the instant an over-15MB file
                     is picked or dropped — before Submit is even clicked,
                     instead of only being caught after a full round trip
                     to the server (the previous behavior: pick an
                     oversized file, hit Submit, the whole page reloads
                     with a flashed "failed to upload" banner — confirmed
                     real gap). --}}
                <p id="file-size-error" class="hidden mt-1 text-xs text-rejected-700"></p>
                {{-- Shown by the total-batch-size check in the pushed
                     scripts block further down this file — see its own
                     comment for why this exists alongside the per-file
                     15MB rule above. --}}
                <p id="batch-size-error" class="hidden mt-1 text-xs text-rejected-700"></p>

                <div class="mt-4">
                    <label for="due_date" class="block text-xs font-medium text-surface-700 mb-1">Due date &amp; time <span class="text-rejected-700">*</span></label>
                    <p class="text-[11px] text-surface-400 mb-1">Approvers' review window is 25% of the time left until this deadline (a flat 15 minutes if due within the next hour). Must fall within working hours (9 AM–5 PM, Mon–Sat).</p>
                    <x-due-date-picker id="due_date" name="due_date" />
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
    document.getElementById('upload-form').addEventListener('submit', function (e) {
        // Real required-field check for the due-date picker — see
        // validateDueDatePicker()'s own docblock for why this can't just
        // be the `required` attribute (it has no effect on the hidden
        // input this component submits through). Confirmed real bug:
        // without this, an empty pick reached the server, which rejected
        // it and reloaded the whole page — the modal, the file already
        // chosen, everything — just to say one field was empty.
        const picker = this.querySelector('[data-due-date-picker]');
        if (picker && !validateDueDatePicker(picker)) {
            e.preventDefault();

            return;
        }

        // Re-checked here too (not just on file-select/drop above) —
        // checkFileSizes() and checkBatchSize() are defined in the pushed
        // scripts block further down this file, which has already run by
        // the time a real submit can fire, so this is safe regardless of
        // script block ordering. See each function's own comment for why
        // the check exists.
        if (typeof checkFileSizes === 'function' && !checkFileSizes()) {
            e.preventDefault();
            document.getElementById('file-size-error').scrollIntoView({ block: 'center' });

            return;
        }

        if (typeof checkBatchSize === 'function' && !checkBatchSize()) {
            e.preventDefault();
            document.getElementById('batch-size-error').scrollIntoView({ block: 'center' });

            return;
        }

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

    // Confirmed real bug, caught live (not just in theory): a bare,
    // top-level call here ran BEFORE app.js's deferred `type="module"`
    // script had executed — module scripts are guaranteed by the HTML
    // spec to run only after the page finishes parsing, which is
    // strictly AFTER this plain inline <script> (every classic script
    // blocks parsing the instant it's reached). Threw "initDueDatePicker
    // is not defined" every single time, not as an occasional race —
    // reproduced directly via a real authenticated page load. Moved
    // inside the same DOMContentLoaded wrapper initFittedPagination
    // already uses several lines down, for the exact same reason (see
    // that wrapper's own comment) — which means that pre-existing call
    // had this identical latent bug the whole time too, just never
    // surfaced as loudly.
    //
    // Live-updates the submissions table without a full page reload —
    // instant via Reverb (see startLiveChannel in resources/js/app.js)
    // the moment one of this originator's documents changes status
    // (processing -> approved, say) or a new one is routed; the slow poll
    // behind it is only a fallback in case the WebSocket connection is down.
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-due-date-picker]').forEach(initDueDatePicker);

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

        // Confirmed real gap: the instant client-side filter above
        // (applySubmissionFilter()) only ever sees rows already loaded on
        // the CURRENT page, so searching a tracking code for a document
        // sitting on a different page came back empty with a "press Enter
        // to search every page" prompt — defeating the whole point of a
        // short reference code meant to jump straight to one document.
        // Debounced (350ms after typing stops, not every keystroke) real
        // search across every page: reuses the exact same fragment-swap
        // applyLiveRefresh() already uses for live status-change
        // updates — opts.preserveQueryString reads window.location.search
        // at call time, so updating the URL first (replaceState, no new
        // history entry per keystroke) is what actually changes what gets
        // searched. DocumentController::documentsQuery() already matches
        // tracking_code server-side (see its own docblock) — this was
        // purely a "how do I trigger that search live" gap, not a missing
        // backend capability.
        let searchDebounceTimer = null;
        const searchInput = document.getElementById('document-search');
        searchInput?.addEventListener('input', () => {
            clearTimeout(searchDebounceTimer);
            searchDebounceTimer = setTimeout(() => {
                const url = new URL(window.location.href);
                if (searchInput.value) {
                    url.searchParams.set('document', searchInput.value);
                } else {
                    url.searchParams.delete('document');
                }
                history.replaceState({}, '', url.pathname + url.search);
                applyLiveRefresh(opts);
            }, 350);
        });
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

    // Confirmed real bug: a batch can clear Laravel's own per-file 15MB
    // rule (every file individually under the limit) while still
    // exceeding PHP's post_max_size — the ceiling on the WHOLE request
    // body, every file combined — which PHP enforces BEFORE Laravel's
    // validator ever runs. That surfaced as a bare, ungraceful
    // PostTooLargeException (reported as the page just hanging), not a
    // normal validation error. Checked here, client-side, against the
    // form's own data-post-max-bytes (DocumentController::dashboard()'s
    // $postMaxBytes — read live from this environment's actual php.ini,
    // never a guessed number that could drift out of sync with it), so
    // an over-limit batch never even leaves the browser. (DocumentController::store()
    // ALSO now enforces this same 150MB total as a real Laravel validation
    // rule, not just an inference from PHP's own ini — this client-side
    // check is purely so the originator finds out before the request
    // even leaves the browser, not the only thing enforcing it.)
    //
    // A fixed 2KB allowance covers everything else riding in the same
    // multipart body alongside the files themselves (the CSRF token, the
    // due-date field, multipart boundary strings per part) — small and
    // fixed regardless of file count, so a flat subtraction is enough
    // rather than trying to model the exact multipart overhead.
    const MULTIPART_OVERHEAD_BYTES = 2048;

    // Keep in sync with DocumentController::store()'s 'files.*' =>
    // 'max:15360' rule (15MB) — same number, just caught the instant a
    // file is picked/dropped instead of after a full round trip to the
    // server. Confirmed real gap: previously a too-big single file only
    // surfaced after Submit, as a flashed "failed to upload" banner on a
    // freshly-reloaded page, the same category of bug the total-size
    // check above already fixed for the whole-batch case.
    const MAX_FILE_BYTES = 15 * 1024 * 1024;

    function checkFileSizes() {
        const errorEl = document.getElementById('file-size-error');
        if (!errorEl) return true;

        const oversized = Array.from(input.files).filter(f => f.size > MAX_FILE_BYTES);
        if (oversized.length) {
            errorEl.textContent = oversized.length === 1
                ? `"${oversized[0].name}" is over the 15MB per-file limit — choose a smaller file.`
                : `These files are over the 15MB per-file limit: ${oversized.map(f => f.name).join(', ')}.`;
            errorEl.classList.remove('hidden');

            return false;
        }

        errorEl.classList.add('hidden');

        return true;
    }

    function checkBatchSize() {
        const errorEl = document.getElementById('batch-size-error');
        const postMaxBytes = parseInt(document.getElementById('upload-form').dataset.postMaxBytes || '0', 10);
        if (!errorEl || !postMaxBytes) return true; // fail open — never wrongly block on missing config

        const totalBytes = Array.from(input.files).reduce((sum, f) => sum + f.size, 0);
        const limit = postMaxBytes - MULTIPART_OVERHEAD_BYTES;

        if (totalBytes > limit) {
            const totalMb = (totalBytes / (1024 * 1024)).toFixed(1);
            const limitMb = (limit / (1024 * 1024)).toFixed(0);
            errorEl.textContent = `These files add up to ${totalMb} MB, which is over this server's ${limitMb} MB total-upload limit — try fewer files or a smaller batch.`;
            errorEl.classList.remove('hidden');

            return false;
        }

        errorEl.classList.add('hidden');

        return true;
    }

    // Greys out Submit until the form is actually valid, instead of
    // letting it be clicked and catching the problem afterward (the
    // submit handler above still does that too, as a final guard — this
    // is purely so the originator sees the problem before they even
    // try). Same disable-don't-error treatment already used for the
    // calendar's day grid and the due-date time field.
    function updateSubmitButtonState() {
        const submitBtn = document.getElementById('upload-submit-btn');
        const dueDateInput = document.getElementById('due_date');
        if (!submitBtn) return;

        const hasFiles = input.files.length > 0;
        const hasDueDate = !!(dueDateInput && dueDateInput.value);
        // Both always run (not short-circuited) so each one's own error
        // message stays in sync with what's currently selected, even
        // when the other check already failed.
        const filesOk = checkFileSizes();
        const batchOk = checkBatchSize();

        submitBtn.disabled = !(hasFiles && hasDueDate && filesOk && batchOk);
    }

    input.addEventListener('change', () => {
        fileName.textContent = describeFiles(input.files);
        updateSubmitButtonState();
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
            updateSubmitButtonState();
        }
    });

    // The due-date picker's hidden input doesn't live in this script's
    // own markup, but applyTime() (resources/js/app.js) dispatches a
    // plain "change" event on it the instant a date/time is set —
    // picked up here so Submit re-enables the moment the picker is
    // filled in, without this script needing any direct reference to
    // the picker component itself.
    document.getElementById('due_date')?.addEventListener('change', updateSubmitButtonState);

    // Starting state: nothing is picked yet when the modal first opens,
    // so Submit starts disabled rather than waiting for a first
    // file-select/drop event to disable it.
    updateSubmitButtonState();
</script>
@endpush
