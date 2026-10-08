@extends('layouts.app')
@section('title', 'Document Tracking')
@section('page-title', 'Document Tracking')

@section('content')
{{-- Capped to the device's own viewport height (Feature: pagination, not
     scrolling, absorbs a long document list). Height is set by JS (see
     resources/js/app.js's sizeCappedCard()), not a static class — this
     is a stable wrapper a live swap never replaces (only #doc-tracking-
     results inside it is), so it's measured once on load, not per-swap. --}}
<div id="doc-tracking-card" class="bg-white rounded-xl shadow-card border border-surface-200 overflow-hidden flex flex-col">
    <form method="GET" class="px-6 py-4 border-b border-surface-200 space-y-3 flex-shrink-0">
        <div class="relative">
            <svg class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-surface-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/>
            </svg>
            <input type="text" name="document" id="doc-tracking-search" value="{{ request('document') }}"
                placeholder="Search document — by title or #ID" autocomplete="off"
                class="w-full rounded-lg border-surface-300 text-sm pl-9 pr-3 py-2.5 focus:border-primary-500 focus:ring-primary-500">
        </div>
        <div class="flex flex-wrap items-end gap-3">
            <div>
                <label class="block text-[11px] font-medium text-surface-500 mb-1">Category</label>
                <select name="category" class="doc-tracking-auto-submit rounded-lg border-surface-300 text-xs pl-3 pr-8 py-2">
                    <option value="">All Categories</option>
                    @foreach($categories as $cat)
                        <option value="{{ $cat }}" {{ request('category') === $cat ? 'selected' : '' }}>{{ $cat }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-[11px] font-medium text-surface-500 mb-1">Status</label>
                <select name="status" class="doc-tracking-auto-submit rounded-lg border-surface-300 text-xs pl-3 pr-8 py-2">
                    <option value="">All Statuses</option>
                    <option value="processing" {{ request('status') === 'processing' ? 'selected' : '' }}>Processing</option>
                    <option value="classified_validated" {{ request('status') === 'classified_validated' ? 'selected' : '' }}>In Progress</option>
                    <option value="approved" {{ request('status') === 'approved' ? 'selected' : '' }}>Approved</option>
                    <option value="auto_approved" {{ request('status') === 'auto_approved' ? 'selected' : '' }}>Auto-Approved</option>
                    <option value="rejected" {{ request('status') === 'rejected' ? 'selected' : '' }}>Rejected</option>
                </select>
            </div>
            <div>
                <label class="block text-[11px] font-medium text-surface-500 mb-1">Originator</label>
                <select name="originator_id" class="doc-tracking-auto-submit rounded-lg border-surface-300 text-xs pl-3 pr-8 py-2">
                    <option value="">All Originators</option>
                    @foreach($originators as $originator)
                        <option value="{{ $originator->user_id }}" {{ (int) request('originator_id') === $originator->user_id ? 'selected' : '' }}>{{ $originator->full_name }}</option>
                    @endforeach
                </select>
            </div>
            <button class="text-xs font-medium bg-primary-700 hover:bg-primary-800 text-white px-4 py-2 rounded-lg">Filter</button>
            @if(request()->anyFilled(['document', 'category', 'status', 'originator_id']))
                <a href="{{ route('admin.documents.index') }}" class="text-xs font-medium text-surface-500 hover:underline pb-2.5">Clear</a>
            @endif
        </div>
    </form>

    <div id="doc-tracking-results" class="flex-1 min-h-0 overflow-hidden flex flex-col"
        data-poll-url="{{ route('admin.documents.index.poll') }}" data-refresh-url="{{ route('admin.documents.index.refresh') }}">
        @include('admin.partials.documents-results')
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        // Auto-submits the moment a filter changes — same reasoning as the
        // Audit Logs page: picking a value shouldn't require a separate
        // "Filter" click to actually take effect.
        document.querySelectorAll('.doc-tracking-auto-submit').forEach((el) => {
            el.addEventListener('change', () => el.form.submit());
        });

        const resultsEl = document.getElementById('doc-tracking-results');
        if (!resultsEl) return;

        // See resources/js/app.js's sizeCappedCard() docblock.
        const docTrackingCard = document.getElementById('doc-tracking-card');
        sizeCappedCard(docTrackingCard);

        // Fitted pagination — see resources/js/app.js's
        // initFittedPagination() for the full mechanism (shared with the
        // originator "Your Submissions" and Admin "User Accounts" pages).
        // refit() is re-run after both the debounced search below AND the
        // live-channel/poll swap further down, since either replaces this
        // fragment's rows/pagination container out from under the running
        // instance.
        const fittedPagination = initFittedPagination('doc-tracking-results', '.doc-tracking-row');

        // Card height AND row count re-measured together, in that order,
        // on every resize — including a text-size change (see app.js's
        // text-size control, which dispatches a synthetic resize event
        // for exactly this). fittedPagination is referenced here even
        // though it's declared just above, not before — fine, since this
        // callback only ever runs later, on an actual resize.
        window.addEventListener('resize', () => {
            sizeCappedCard(docTrackingCard);
            fittedPagination.refit();
        });

        // Debounced live search — the search box is the one control that
        // can't just "auto-submit on change" like the dropdowns, since a
        // full page navigation on every keystroke would be disruptive and
        // client-side-only filtering (like the Audit Logs page's search
        // box) can't reach documents sitting on a different page. Instead,
        // ~400ms after typing stops, it re-fetches the results fragment
        // with the current search term and swaps it in — same fragment
        // endpoint the live-refresh below already uses, just triggered by
        // typing instead of a filter change. Keeps the URL in sync via
        // replaceState so refreshing, sharing, or hitting Filter still
        // reflects exactly what's on screen.
        const searchInput = document.getElementById('doc-tracking-search');
        let searchDebounce = null;

        function fetchWithCurrentSearch() {
            const params = new URLSearchParams(window.location.search);
            const term = searchInput.value.trim();
            if (term) {
                params.set('document', term);
            } else {
                params.delete('document');
            }
            params.delete('page'); // a new search term starts back at page 1

            const query = params.toString();
            fetch(`${resultsEl.dataset.refreshUrl}${query ? '?' + query : ''}`, { headers: { Accept: 'text/html' } })
                .then((res) => (res.ok ? res.text() : Promise.reject(res)))
                .then((html) => { resultsEl.innerHTML = html; fittedPagination.refit(); })
                .catch(() => {});

            const newUrl = window.location.pathname + (query ? '?' + query : '');
            window.history.replaceState({}, '', newUrl);
        }

        searchInput?.addEventListener('input', () => {
            clearTimeout(searchDebounce);
            searchDebounce = setTimeout(fetchWithCurrentSearch, 400);
        });

        // Live-updates the list without a full page reload — instant via
        // Reverb the moment anything happens to any document (uploads,
        // decisions, reviews) — see AdminActivityLogged. The slow poll
        // behind it is only a fallback if the WebSocket connection is
        // down. Shares the 'admin-dashboard' channel — every admin sees
        // all documents. preserveQueryString means this also respects
        // whatever search term the debounced search above last set.
        const opts = {
            refreshUrl: resultsEl.dataset.refreshUrl,
            target: resultsEl,
            preserveQueryString: true,
            // Skip a live swap while the admin is actively typing a
            // search term — the debounced search above already owns the
            // fragment during that window, and a live swap mid-keystroke
            // would just get overwritten a moment later anyway.
            isBusy: () => document.activeElement === searchInput,
            onSwap: () => fittedPagination.refit(),
        };

        startLiveChannel('admin-dashboard', '.admin.activity-logged', opts);
        startLiveChannel('admin-dashboard', '.document.status-changed', opts);
        startLivePoll({ ...opts, pollUrl: resultsEl.dataset.pollUrl });
    });
</script>
@endsection
