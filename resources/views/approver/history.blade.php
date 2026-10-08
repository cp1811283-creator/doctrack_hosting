@extends('layouts.app')
@section('title', 'Decision History')
@section('page-title', 'Decision History')

@section('content')
<div class="space-y-6">
    <div class="bg-white rounded-xl shadow-card border border-surface-200 p-5">
        <form method="GET" id="history-filter-form" class="flex flex-wrap gap-3 items-end">
            <div class="flex-1 min-w-[140px]">
                <label class="block text-xs font-medium text-surface-700 mb-1">Keyword</label>
                <input type="text" id="history-keyword" name="keyword" value="{{ request('keyword') }}" placeholder="Document title…" autocomplete="off"
                    class="w-full rounded-lg border-surface-300 text-sm px-3 py-2 focus:border-primary-500 focus:ring-primary-500">
            </div>

            <div>
                <label class="block text-xs font-medium text-surface-700 mb-1">Category</label>
                {{-- pr-8: the native <select> arrow sits INSIDE the box,
                     not in reserved space of its own — without extra
                     right padding, a long enough label runs straight into
                     it instead of stopping short (confirmed: "All
                     Categories" collided with the arrow here while the
                     shorter "All Decisions" below didn't, at the exact
                     same classes). --}}
                <select name="category" id="history-category" class="rounded-lg border-surface-300 text-sm pl-3 pr-8 py-2 focus:border-primary-500 focus:ring-primary-500">
                    <option value="">All Categories</option>
                    @foreach($categories as $c)
                        <option value="{{ $c }}" @selected(request('category') === $c)>{{ $c }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-xs font-medium text-surface-700 mb-1">Decision</label>
                <select name="decision" id="history-decision" class="rounded-lg border-surface-300 text-sm pl-3 pr-8 py-2 focus:border-primary-500 focus:ring-primary-500">
                    <option value="">All Decisions</option>
                    <option value="approved" @selected(request('decision') === 'approved')>Approved</option>
                    <option value="rejected" @selected(request('decision') === 'rejected')>Rejected</option>
                    <option value="auto_approved" @selected(request('decision') === 'auto_approved')>Auto-Approved</option>
                    <option value="admin_override" @selected(request('decision') === 'admin_override')>Decided by Admin</option>
                </select>
            </div>

            <div>
                <label class="block text-xs font-medium text-surface-700 mb-1">From</label>
                <input type="date" name="date_from" value="{{ request('date_from') }}"
                    class="rounded-lg border-surface-300 text-sm px-3 py-2 focus:border-primary-500 focus:ring-primary-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-surface-700 mb-1">To</label>
                <input type="date" name="date_to" value="{{ request('date_to') }}"
                    class="rounded-lg border-surface-300 text-sm px-3 py-2 focus:border-primary-500 focus:ring-primary-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-surface-700 mb-1">Sort</label>
                <select name="sort" id="history-sort" class="rounded-lg border-surface-300 text-sm px-3 py-2 focus:border-primary-500 focus:ring-primary-500">
                    <option value="newest" @selected(request('sort', 'newest') === 'newest')>Newest first</option>
                    <option value="oldest" @selected(request('sort') === 'oldest')>Oldest first</option>
                </select>
            </div>

            <button class="bg-primary-700 hover:bg-primary-800 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors">Search</button>
            <a href="{{ url()->current() }}" class="inline-flex items-center text-xs font-medium text-surface-700 bg-surface-100 hover:bg-surface-200 border border-surface-300 px-4 py-2 rounded-lg transition-colors">
                Clear
            </a>
        </form>
    </div>

    <div id="history-results" data-refresh-url="{{ route('approver.history.refresh') }}" data-poll-url="{{ route('approver.history.poll') }}">
        @include('approver.partials.history-results')
    </div>
</div>

<script>
    // Live search — same debounced-fetch pattern as the Archive (see
    // resources/views/archive/index.blade.php for the full reasoning).
    // Live push on top — a decision made from another tab/device (or a
    // stage this approver holds getting auto-approved by the system)
    // needs to show up here without a manual reload.
    //
    // Wrapped in DOMContentLoaded, not a bare IIFE — this is a plain
    // inline script, which runs immediately as the browser parses this
    // point in the page, but app.js (which defines startLiveChannel/
    // startLivePoll) is loaded via the Vite-injected module script tag in
    // the layout's <head>, and module scripts are always deferred until
    // after the page finishes parsing. Without this wrapper, the
    // startLiveChannel/startLivePoll calls below throw a silent
    // ReferenceError before they ever run — which is exactly why every
    // fix to what's inside them never had any effect: this whole block
    // was dying before it got there. Everything ABOVE those calls (the
    // search/filter/pagination bindings) doesn't depend on app.js, so
    // it kept working and made the page look fully wired when it wasn't.
    document.addEventListener('DOMContentLoaded', function () {
        const resultsEl = document.getElementById('history-results');
        if (!resultsEl) return;

        const form = document.getElementById('history-filter-form');
        const keywordInput = document.getElementById('history-keyword');
        const refreshUrl = resultsEl.dataset.refreshUrl;
        let debounceTimer = null;
        let currentRequest = null;

        // Every innerHTML swap below (search, pagination, live push) always
        // re-renders every document row collapsed — with no tracking, a
        // live-refreshed movement panel you were actively looking at just
        // silently closes on you. Data underneath IS fresh; it just reads
        // as "nothing happened" if the one thing you're watching vanishes
        // instead of updating in place. Tracks which documents are
        // expanded so any swap can restore exactly that state afterward.
        const expandedDocs = new Set();

        window.toggleHistoryMovements = function (documentId, rowEl) {
            const panel = document.getElementById('history-movements-' + documentId);
            if (!panel) return;
            const icon = rowEl.querySelector('.history-expand-icon');
            const wasHidden = panel.classList.contains('hidden');
            panel.classList.toggle('hidden');
            if (icon) icon.classList.toggle('rotate-90');
            if (wasHidden) {
                expandedDocs.add(documentId);
            } else {
                expandedDocs.delete(documentId);
            }
        };

        function reapplyExpandedState() {
            expandedDocs.forEach((documentId) => {
                const panel = document.getElementById('history-movements-' + documentId);
                if (!panel) return;
                panel.classList.remove('hidden');
                const icon = panel.previousElementSibling?.querySelector('.history-expand-icon');
                if (icon) icon.classList.add('rotate-90');
            });
        }

        const runSearch = () => {
            const params = new URLSearchParams(new FormData(form));
            Array.from(params.keys()).forEach((key) => {
                if (params.get(key) === '') params.delete(key);
            });

            if (currentRequest) currentRequest.abort();
            currentRequest = new AbortController();

            fetch(`${refreshUrl}?${params.toString()}`, {
                headers: { Accept: 'text/html' },
                signal: currentRequest.signal,
            })
                .then((res) => (res.ok ? res.text() : Promise.reject(res)))
                .then((html) => {
                    resultsEl.innerHTML = html;
                    reapplyExpandedState();
                    const query = params.toString();
                    history.replaceState(null, '', query ? `${window.location.pathname}?${query}` : window.location.pathname);
                })
                .catch(() => {});
        };

        if (keywordInput) {
            keywordInput.addEventListener('input', () => {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(runSearch, 300);
            });
        }

        form.querySelectorAll('select[name="category"], select[name="decision"], input[name="date_from"], input[name="date_to"], select[name="sort"]')
            .forEach((el) => el.addEventListener('change', runSearch));

        resultsEl.addEventListener('click', (e) => {
            const link = e.target.closest('a[href]');
            if (!link || !resultsEl.contains(link)) return;
            const url = new URL(link.href, window.location.origin);
            if (url.pathname !== window.location.pathname) return;
            e.preventDefault();
            fetch(`${refreshUrl}?${url.searchParams.toString()}`, { headers: { Accept: 'text/html' } })
                .then((res) => (res.ok ? res.text() : Promise.reject(res)))
                .then((html) => {
                    resultsEl.innerHTML = html;
                    reapplyExpandedState();
                    history.replaceState(null, '', link.href);
                })
                .catch(() => {});
        });

        const opts = {
            refreshUrl,
            target: resultsEl,
            preserveQueryString: true,
            isBusy: () => document.activeElement === keywordInput,
            onSwap: reapplyExpandedState,
        };

        startLiveChannel('approver.' + {{ auth()->id() }}, '.assignment.routed', opts);
        // Was missing — the Approver Queue already listens for this, but
        // Decision History never did, so opening/closing the document
        // viewer (which fires this the instant a review session opens or
        // closes — see DocumentReviewSession::notifyStakeholders()) never
        // reached this page's movement timeline, unlike the Originator's
        // tracking page (which reacts to the same activity via
        // .document.status-changed, also fired from the same hook).
        startLiveChannel('approver.' + {{ auth()->id() }}, '.document.review-activity', opts);
        startLivePoll({ ...opts, pollUrl: resultsEl.dataset.pollUrl });
    });
</script>
@endsection
