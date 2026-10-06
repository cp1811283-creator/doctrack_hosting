@extends('layouts.app')
@section('title', 'Archive')
@section('page-title', 'Document Archive & Repository')

@section('content')
<div class="space-y-6">

    @if($showFolders)
        {{-- Folders only — no search bar, no Import Legacy panel here.
             Both only make sense once you're inside a specific category
             (see the else branch below); showing them here duplicated the
             same controls twice and added noise to what should be a plain
             "pick a category" screen. --}}
        {{-- Feature: the folder tiles themselves shrink/grow to fit whatever
             vertical space is actually left on screen (see sizeFolderGrid()
             below) — not an internal scrollbar, and not a static h-64 guess
             that breaks the instant the grid needs more room than that
             (more categories, or a larger font size growing the heading/
             sidebar above it). h-64 below is only the pre-JS fallback. --}}
        <div id="archive-folder-card">
            <h2 class="text-sm font-semibold text-surface-900 mb-3">
                Browse by Category
                @if($isOwnSubmissionsView)
                    <span class="text-xs font-normal text-surface-400">— your own approved submissions</span>
                @endif
            </h2>
            {{-- Fixed 2-column grid (not a responsive 2/3/4-column one) so
                 each tile gets a whole half-width column to grow into. --}}
            <div class="folder-grid grid grid-cols-2 gap-8">
                @foreach($folders as $folder)
                    <a href="{{ url()->current() }}?category={{ urlencode($folder->category) }}" class="group block">
                        {{-- Two rounded pieces (tab + body), not a clip-path
                             polygon — clip-path only does straight-line corners,
                             which read as "pointy" rather than a real folder.
                             Gradients on both pieces give it depth instead of a
                             flat fill. Same blue gradient as the sidebar's "D"
                             logo badge (layouts/app.blade.php) — from-primary-400
                             to-primary-600 — for brand consistency. --}}
                        <div class="folder-tile-tab w-40 h-10 ml-8 rounded-t-lg bg-gradient-to-br from-primary-300 to-primary-500 group-hover:from-primary-400 group-hover:to-primary-600 transition-colors"></div>
                        <div class="folder-tile-body -mt-px h-64 rounded-b-xl rounded-tr-xl bg-gradient-to-br from-primary-400 to-primary-600 group-hover:from-primary-500 group-hover:to-primary-700 shadow-lg group-hover:shadow-xl group-hover:-translate-y-0.5 transition-all flex flex-col items-center justify-center text-center px-4">
                            <h3 class="text-xl font-semibold text-white drop-shadow-sm">{{ $folder->category }}</h3>
                            <p class="text-sm text-primary-100 mt-1">{{ $folder->total }} document{{ $folder->total === 1 ? '' : 's' }}</p>
                            @if($folder->disputed > 0 || $folder->auto_approved > 0)
                                <div class="flex flex-wrap justify-center gap-1.5 mt-3">
                                    @if($folder->disputed > 0)
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-white text-processing-700">{{ $folder->disputed }} disputed</span>
                                    @endif
                                    @if($folder->auto_approved > 0)
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-white text-approved-700">{{ $folder->auto_approved }} auto-approved</span>
                                    @endif
                                </div>
                            @endif
                        </div>
                    </a>
                @endforeach
            </div>
        </div>

    @else

    {{-- Single column, full width, always — the Approved Documents table
         needs the whole page (6 data columns including three full
         date+time columns, plus a 3-button action group per row) to avoid
         its own internal horizontal scroll. Import Legacy Document used to
         sit permanently beside it (then below it, as a collapsible bar);
         it's a "+ Import Legacy Document" button in the table's own
         header now (see archive/partials/results.blade.php), opening a
         popup — an occasional admin action doesn't need permanent real
         estate the way the always-relevant list does. --}}
    <div class="space-y-6">

            {{-- Search / filter bar — inputs are live (see script below):
                 typing/changing any of these fetches fresh results from the
                 server and swaps them in without a page reload. The <form>
                 and Search/Clear buttons remain a working no-JS fallback. --}}
            <div class="bg-white rounded-xl shadow-card border border-surface-200 p-5">
                @unless(auth()->user()->isApprover() && auth()->user()->isStaffLevel())
                    {{-- Same pill styling as the "All Categories" back links
                         on SLA Violation Reports and Document Tracking —
                         light tint, ring, rounded-full — instead of a bare
                         underlined text link, for visual consistency across
                         every "back to the folder grid" control in the app. --}}
                    <a href="{{ url()->current() }}" class="inline-flex items-center gap-1 text-xs font-medium text-primary-700 bg-primary-50 hover:bg-primary-100 ring-1 ring-inset ring-primary-500/20 rounded-full px-3 py-1.5 transition-colors mb-3">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                        All Categories
                    </a>
                @endunless
                <form method="GET" id="archive-filter-form" class="flex flex-wrap gap-3 items-end">
                    <div class="flex-1 min-w-[140px]">
                        <label class="block text-xs font-medium text-surface-700 mb-1">Search</label>
                        <input type="text" id="archive-keyword" name="keyword" value="{{ request('keyword') }}" placeholder="Title or content…" autocomplete="off"
                            class="w-full rounded-lg border-surface-300 text-sm px-3 py-2 focus:border-primary-500 focus:ring-primary-500">
                    </div>

                    @php
                        // Always a static label now, never a dropdown —
                        // for any role, not just Approvers (who already
                        // had the read-only treatment via $restrictedCategory).
                        // Switching categories only happens through the
                        // folder grid ("All Categories" above) — a second,
                        // dropdown-based way to do the same thing made the
                        // folders themselves feel pointless. $restrictedCategory
                        // (role-locked) takes priority when set; otherwise
                        // this reflects whatever ?category= is currently
                        // active, or "All Categories" if none is (e.g. a
                        // keyword-only search spanning every category).
                        $currentCategory = $restrictedCategory ?? request('category') ?: null;
                    @endphp
                    <div>
                        <label class="block text-xs font-medium text-surface-700 mb-1">Category</label>
                        @if($currentCategory)
                            <input type="hidden" name="category" value="{{ $currentCategory }}">
                        @endif
                        <span class="inline-flex items-center px-3 py-2 rounded-lg bg-surface-100 text-sm font-medium text-surface-700">{{ $currentCategory ?? 'All Categories' }}</span>
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
                        <select name="sort" id="archive-sort" class="rounded-lg border-surface-300 text-sm px-3 py-2 focus:border-primary-500 focus:ring-primary-500">
                            <option value="newest" @selected(request('sort', 'newest') === 'newest')>Newest first</option>
                            <option value="oldest" @selected(request('sort') === 'oldest')>Oldest first</option>
                            <option value="originator" @selected(request('sort') === 'originator')>Originator (A–Z)</option>
                        </select>
                    </div>

                    <button class="bg-primary-700 hover:bg-primary-800 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors">Search</button>
                    <a href="{{ url()->current() }}{{ $restrictedCategory ? '?category=' . urlencode($restrictedCategory) : '' }}"
                        class="inline-flex items-center text-xs font-medium text-surface-700 bg-surface-100 hover:bg-surface-200 border border-surface-300 px-4 py-2 rounded-lg transition-colors">
                        Clear
                    </a>
                </form>
            </div>

            {{-- Capped to the device's own viewport height (Feature:
                 pagination, not scrolling, absorbs a long archive). Height
                 is set by JS (see resources/js/app.js's sizeCappedCard())
                 — a stable wrapper a live swap never replaces (only
                 #archive-results inside it is), so it's measured once on
                 load, not per-swap. The filter bar sits outside this
                 wrapper (unlike Audit Logs, where it's inside one unified
                 card) — sizeCappedCard() only cares about ITS OWN top
                 position, so that's fine either way. --}}
            <div id="archive-card" class="flex flex-col">
                <div id="archive-results" class="flex-1 min-h-0 flex flex-col" data-refresh-url="{{ route('archive.refresh') }}" data-user-id="{{ auth()->id() }}">
                    @include('archive.partials.results')
                </div>
            </div>
    </div>
    @endif
</div>

<script>
    // Live search (Feature: instant results as you type, no page reload) —
    // debounced fetch to ArchiveController::refresh(), which returns just
    // the results-table fragment (archive/partials/results.blade.php) to
    // swap into #archive-results. Keyword is debounced since it fires on
    // every keystroke; category/date/sort fire immediately since they're
    // discrete choices, not continuous typing. The surrounding <form> and
    // Search/Clear buttons keep working as a plain full-page-reload
    // fallback if JS is unavailable — nothing here is required for the
    // page to function.
    //
    // Deferred to DOMContentLoaded (verified 2026-10-01, fixing a real bug:
    // sizeCappedCard/initFittedPagination/the live keyword search were all
    // silently dead) — app.js's sizeCappedCard()/initFittedPagination()
    // live on window, set by app.js's own deferred module script, which
    // hasn't necessarily run yet if this IIFE fires immediately on parse.
    // Calling sizeCappedCard() too early threw a bare ReferenceError that
    // killed the rest of this function — including the keyword input's
    // listener a few lines further down, which is why typing silently
    // fell back to pressing Enter instead of searching live. Same
    // reasoning as the inner DOMContentLoaded wrapper this replaces
    // further down (that one only existed for window.Echo; redundant now
    // that the whole function waits for the same event).
    document.addEventListener('DOMContentLoaded', function () {
        // Folder grid screen (the "pick a category" folder tiles) — see
        // sizeFolderGrid()'s docblock in app.js. Separate from the results-card sizing below
        // since the two screens are mutually exclusive, picked further up
        // this same template based on whether a category is active;
        // guarded because only one of the two ever exists on a given load.
        const folderGrid = document.querySelector('#archive-folder-card .folder-grid');
        if (folderGrid) {
            sizeFolderGrid(folderGrid);
            window.addEventListener('resize', () => sizeFolderGrid(folderGrid));
        }

        const resultsEl = document.getElementById('archive-results');
        if (!resultsEl) return;

        // See resources/js/app.js's sizeCappedCard() docblock.
        const archiveCard = document.getElementById('archive-card');
        sizeCappedCard(archiveCard);

        // Fitted pagination — see resources/js/app.js's
        // initFittedPagination() for the full mechanism (shared with
        // Document Tracking/User Accounts/Audit Logs/Your Submissions).
        // Replaces real server-side pagination (DocumentRepository used to
        // be ->paginate()'d at a fixed 5/10 per page regardless of actual
        // screen size) — the whole matching list is sent in one response
        // now, and the browser works out each page's real boundary itself.
        const fittedPagination = initFittedPagination('archive-results', '.archive-row');

        window.addEventListener('resize', () => {
            sizeCappedCard(archiveCard);
            fittedPagination.refit();
        });

        const form = document.getElementById('archive-filter-form');
        const keywordInput = document.getElementById('archive-keyword');
        const refreshUrl = resultsEl.dataset.refreshUrl;
        let debounceTimer = null;
        let currentRequest = null;

        const runSearch = () => {
            const params = new URLSearchParams(new FormData(form));
            // Drop empty params instead of sending "keyword=" etc. — keeps
            // the pushed URL clean and matches how a normal GET submit behaves.
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
                    fittedPagination.refit();
                    const query = params.toString();
                    history.replaceState(null, '', query ? `${window.location.pathname}?${query}` : window.location.pathname);
                })
                .catch(() => {}); // aborted/failed — leave the last good results showing
        };

        if (keywordInput) {
            keywordInput.addEventListener('input', () => {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(runSearch, 300);
            });
        }

        form.querySelectorAll('select[name="category"], input[name="date_from"], input[name="date_to"], select[name="sort"]')
            .forEach((el) => el.addEventListener('change', runSearch));

        // The old pagination-link click-interception block that used to
        // live here is gone — fitted pagination has no real <a href> page
        // links to intercept anymore (see initFittedPagination()'s own
        // hand-built prev/page-number/forward buttons), so that whole
        // handler became dead code once server-side ->paginate() was
        // removed from ArchiveController::searchResults().

        // Realtime: a document newly reaching Archive (approved, or
        // auto-approved) anywhere in the system re-runs the CURRENT
        // search/filter/page automatically — reuses runSearch() itself
        // (not a generic fragment swap) so it never disturbs whatever
        // keyword/category/date/sort/page the admin, approver, or
        // originator currently has active. Primary path is instant via
        // Reverb; the interval below is only a fallback in case that
        // connection is down, mirroring this app's SLA-check jobs (instant
        // dispatch + a slow periodic sweep behind it).
        //
        // No separate DOMContentLoaded wrapper needed here — the whole
        // function already waits for that same event now (see the
        // docblock at the top of this script), which is what guarantees
        // window.Echo is defined by this point too.
        if (window.Echo) {
            @if(auth()->user()->isAdmin())
                window.Echo.private('admin-dashboard').listen('.document.status-changed', runSearch);
            @elseif(auth()->user()->isOriginator())
                window.Echo.private(`originator.${resultsEl.dataset.userId}`).listen('.document.status-changed', runSearch);
            @elseif(auth()->user()->isApprover())
                window.Echo.private('approvers').listen('.document.status-changed', runSearch);
            @endif
        }
        setInterval(runSearch, (45 + Math.random() * 30) * 1000);
    });
</script>
@endsection
