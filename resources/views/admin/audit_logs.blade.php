@extends('layouts.app')
@section('title', 'Audit Logs')
@section('page-title', 'Audit Logs')

@section('content')
{{-- Capped to the device's own viewport height (Feature: pagination, not
     internal scrolling, absorbs a long audit trail). Height is set by JS
     (see resources/js/app.js's sizeCappedCard()) — a stable wrapper a
     live swap never replaces (only #audit-results inside it is), so it's
     measured once on load, not per-swap. --}}
<div id="audit-card" class="bg-white rounded-xl shadow-card border border-surface-200 overflow-hidden flex flex-col">
    <form method="GET" class="px-6 py-4 border-b border-surface-200 space-y-3 flex-shrink-0">
        <div class="relative">
            <svg class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-surface-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/>
            </svg>
            <input type="text" id="document-search" name="document" value="{{ request('document') }}"
                placeholder="Search document — by title or #ID" autocomplete="off"
                class="w-full rounded-lg border-surface-300 text-sm pl-9 pr-3 py-2.5 focus:border-primary-500 focus:ring-primary-500">
        </div>
        <div class="flex flex-wrap items-end gap-3">
            <div>
                <label class="block text-[11px] font-medium text-surface-500 mb-1">Action</label>
                <select name="action_type" class="audit-auto-submit rounded-lg border-surface-300 text-xs pl-3 pr-8 py-2">
                    <option value="">All Actions</option>
                    @foreach($actionTypes as $type => $label)
                        <option value="{{ $type }}" {{ request('action_type') === $type ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-[11px] font-medium text-surface-500 mb-1">Employees</label>
                <select name="actor_id" class="audit-auto-submit rounded-lg border-surface-300 text-xs pl-3 pr-8 py-2">
                    <option value="">All Employees</option>
                    @foreach($actors as $actor)
                        <option value="{{ $actor->user_id }}" {{ (int) request('actor_id') === $actor->user_id ? 'selected' : '' }}>{{ $actor->full_name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-[11px] font-medium text-surface-500 mb-1">From</label>
                <input type="date" name="date_from" value="{{ request('date_from') }}" class="audit-auto-submit rounded-lg border-surface-300 text-xs px-3 py-2">
            </div>
            <div>
                <label class="block text-[11px] font-medium text-surface-500 mb-1">To</label>
                <input type="date" name="date_to" value="{{ request('date_to') }}" class="audit-auto-submit rounded-lg border-surface-300 text-xs px-3 py-2">
            </div>
            <button class="text-xs font-medium bg-primary-700 hover:bg-primary-800 text-white px-4 py-2 rounded-lg">Filter</button>
            @if(request()->anyFilled(['document', 'action_type', 'actor_id', 'date_from', 'date_to']))
                <a href="{{ route('admin.audit.logs') }}" class="text-xs font-medium text-surface-500 hover:underline pb-2.5">Clear</a>
            @endif
        </div>
    </form>

    <div id="audit-results" class="flex-1 min-h-0 overflow-hidden flex flex-col"
        data-poll-url="{{ route('admin.audit.logs.poll') }}" data-refresh-url="{{ route('admin.audit.logs.refresh') }}">
        @include('admin.partials.audit-results')
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        // See resources/js/app.js's sizeCappedCard() docblock.
        const auditCard = document.getElementById('audit-card');
        sizeCappedCard(auditCard);

        // Fitted pagination — see resources/js/app.js's
        // initFittedPagination() for the full mechanism (shared with
        // Document Tracking/User Accounts/Your Submissions). Replaces the
        // old sizeAuditTable() internal-scroll-area approach, which — like
        // every other fixed-per-page-plus-scroll workaround in this app's
        // history — either scrolled a page that could have shown more, or
        // still didn't fit and needed the internal scrollbar anyway.
        const fittedPagination = initFittedPagination('audit-results', '.audit-row');

        // Card height AND row count re-measured together, in that order,
        // on every resize — including a text-size change (see app.js's
        // text-size control, which dispatches a synthetic resize event
        // for exactly this). fittedPagination is referenced here even
        // though it's declared just above, not before — fine, since this
        // callback only ever runs later, on an actual resize.
        window.addEventListener('resize', () => {
            sizeCappedCard(auditCard);
            fittedPagination.refit();
        });

        // Feature: return to the same page after clicking "View" into a
        // document's Tracking page and coming back — pagination here is
        // entirely client-side (no ?page= in the URL to restore from), so
        // the row's "View" link stashes the page it was on into
        // sessionStorage right before navigating away (see
        // admin/partials/audit-row.blade.php's "View" link); consumed
        // (read once, then cleared) here so
        // it only ever fires on a genuine return trip, not every ordinary
        // visit to this page. Best-effort — if the list changed in the
        // meantime, "page 10" may show slightly different rows than it did
        // before, which is an acceptable trade-off of not tracking a fixed
        // server-side page at all anymore.
        const returnPage = parseInt(sessionStorage.getItem('auditLogsReturnPage'), 10);
        if (returnPage) {
            sessionStorage.removeItem('auditLogsReturnPage');
            fittedPagination.goToPage(returnPage);
        }

        // Auto-submits the surrounding <form> the moment any of the four
        // server-side filters change (Action, Employees, From, To) —
        // without this, picking a date in "From" just stages the filter
        // until the "Filter" button is clicked, which reads as the page
        // not reacting at all. The button stays as a fallback (e.g. if JS
        // is disabled).
        document.querySelectorAll('.audit-auto-submit').forEach((el) => {
            el.addEventListener('change', () => el.form.submit());
        });

        // Real-time, client-side filter over whichever rows are currently
        // rendered — instant, no round trip. Re-run after every live swap
        // (see onSwap below), since a swap replaces the rows entirely and
        // any in-progress search term needs to re-apply to the new ones.
        // Pressing Enter still submits the surrounding <form> normally,
        // running the "document" filter server-side (see
        // AdminController::auditLogs()) across every page.
        const input = document.getElementById('document-search');

        function applyDocumentFilter() {
            const rows = Array.from(document.querySelectorAll('.audit-row'));
            const noMatches = document.getElementById('audit-no-matches');
            const noMatchesTerm = document.getElementById('audit-no-matches-term');
            if (!input || rows.length === 0) return;

            const term = input.value.trim().toLowerCase();
            const idTerm = term.replace(/^#/, '');
            let visibleCount = 0;

            rows.forEach((row) => {
                // A row pagination has hidden on another page (see
                // resources/js/app.js's showPage()) stays exactly as
                // pagination left it — same reasoning as originator/
                // dashboard.blade.php's applySubmissionFilter().
                if (row.dataset.fittedOffPage === '1') return;
                const matches = term === ''
                    || row.dataset.documentTitle.includes(term)
                    || (idTerm !== '' && row.dataset.documentId === idTerm);
                row.classList.toggle('hidden', !matches);
                if (matches) visibleCount++;
            });

            const showNoMatches = term !== '' && visibleCount === 0;
            if (noMatches) noMatches.classList.toggle('hidden', !showNoMatches);
            if (showNoMatches && noMatchesTerm) noMatchesTerm.textContent = input.value.trim();
        }

        input?.addEventListener('input', applyDocumentFilter);

        // Live-updates the results without a full page reload — instant via
        // Reverb (see startLiveChannel in resources/js/app.js) the moment
        // anything audit-worthy happens anywhere (uploads, decisions,
        // escalations, config changes — see AdminActivityLogged), not just
        // while this exact page is being watched. The slow poll behind it
        // is only a fallback in case the WebSocket connection is down.
        // Shares the 'admin-dashboard' channel with the Control Center —
        // every admin sees all documents, so this isn't scoped per-user.
        const resultsEl = document.getElementById('audit-results');
        if (!resultsEl) return;

        const opts = {
            refreshUrl: resultsEl.dataset.refreshUrl,
            target: resultsEl,
            preserveQueryString: true,
            onSwap: function (signalData) {
                // refit() first, THEN re-apply the search term — refit()
                // resets every row's page/hidden state from scratch, so
                // running it after the filter would just wipe out
                // whatever the filter had just done.
                fittedPagination.refit();
                applyDocumentFilter(signalData);
            },
        };

        startLiveChannel('admin-dashboard', '.admin.activity-logged', opts);
        startLivePoll({ ...opts, pollUrl: resultsEl.dataset.pollUrl });
    });
</script>
@endsection
