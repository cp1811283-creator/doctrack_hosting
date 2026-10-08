@extends('layouts.app')
@section('title', 'Control Center')
@section('page-title', 'Admin Control Center')

@section('content')
<div class="space-y-6">
    <div id="admin-overview" data-poll-url="{{ route('admin.dashboard.poll') }}" data-refresh-url="{{ route('admin.dashboard.refresh') }}">
        @include('admin.partials.overview')
    </div>
</div>

{{-- Shared KPI card tooltip — ONE element, positioned via JS with
     position:fixed (not CSS absolute) so it can render above the card
     without being clipped by <main>'s overflow-y-auto (a fixed-position
     element is relative to the viewport, immune to any ancestor's
     overflow/scroll clipping — see openKpiTooltip() below). Lives outside
     #admin-overview so it survives every live-refresh swap of that
     wrapper; the buttons inside it just carry a data-kpi-tooltip
     attribute the delegated listener below reads fresh each time. --}}
<div id="kpi-tooltip" class="hidden fixed z-50 rounded-lg bg-surface-900 px-3 py-2 text-xs leading-snug text-white shadow-lg pointer-events-none"></div>

<script>
    (function () {
        const tooltip = document.getElementById('kpi-tooltip');

        function openKpiTooltip(card) {
            const description = card.dataset.kpiTooltip;
            if (!description || !tooltip) return;

            tooltip.textContent = description;
            tooltip.classList.remove('hidden');

            const rect = card.getBoundingClientRect();
            tooltip.style.left = rect.left + 'px';
            tooltip.style.width = rect.width + 'px';
            // Above the card — measure the tooltip's own height AFTER it
            // has content/is visible, so this works regardless of how many
            // lines the description wraps to.
            tooltip.style.top = (rect.top - tooltip.getBoundingClientRect().height - 8) + 'px';
        }

        function closeKpiTooltip() {
            if (tooltip) tooltip.classList.add('hidden');
        }

        // Delegated on <body> (not #admin-overview) so it keeps working
        // across every live-refresh swap without needing to be rebound —
        // mouseover/mouseout bubble (unlike mouseenter/mouseleave), so
        // .closest() below is what limits this to actually entering/
        // leaving a KPI card rather than firing on every pixel of movement.
        document.body.addEventListener('mouseover', function (e) {
            const card = e.target.closest('[data-kpi-tooltip]');
            if (card) openKpiTooltip(card);
        });
        document.body.addEventListener('mouseout', function (e) {
            const card = e.target.closest('[data-kpi-tooltip]');
            if (card && !card.contains(e.relatedTarget)) closeKpiTooltip();
        });
        document.body.addEventListener('focusin', function (e) {
            const card = e.target.closest('[data-kpi-tooltip]');
            if (card) openKpiTooltip(card);
        });
        document.body.addEventListener('focusout', function (e) {
            const card = e.target.closest('[data-kpi-tooltip]');
            if (card) closeKpiTooltip();
        });
    })();
</script>

<script>
    // Live-updates the KPI cards + SLA alerts without a full page reload —
    // instant via Reverb (see startLiveChannel in resources/js/app.js) the
    // moment any document changes status anywhere in the system; the slow
    // poll behind it is only a fallback in case the WebSocket connection
    // is down. Not scoped to one user's channel — every admin shares the
    // same 'admin-dashboard' channel, since admins see all documents.
    //
    // Wrapped in DOMContentLoaded, not a bare IIFE — see the matching
    // comment in approver/dashboard.blade.php for why: this plain inline
    // script would otherwise run before app.js's deferred module script
    // has defined startLiveChannel/startLivePoll, throw immediately, and
    // silently never wire anything up.
    // Recent Activity (the last section on the page, now full width — see
    // overview.blade.php) is sized to exactly fill whatever space is left
    // below it, the same technique already proven on the Document Tracker
    // page: a fixed max-height guess didn't reliably fit this page (KPI
    // row + Analytics + SLA/Category above it varies in height), so this
    // measures live against <main>'s own bottom edge instead — the PAGE
    // never scrolls, only this one table does internally if it runs long.
    function sizeRecentActivity() {
        const scrollEl = document.getElementById('admin-recent-activity-scroll');
        const mainEl = document.querySelector('main');
        if (!scrollEl || !mainEl) return;

        const mainPaddingBottom = parseFloat(getComputedStyle(mainEl).paddingBottom) || 0;
        const available = mainEl.getBoundingClientRect().bottom - mainPaddingBottom - scrollEl.getBoundingClientRect().top;
        // No floor — a floor here previously forced the whole page to
        // overflow whenever real available space dropped below it (e.g.
        // at a larger font size, where the Analytics/SLA/Category row
        // above grows taller and leaves less room down here). A shorter-
        // than-ideal scroll area is still strictly better than that, so
        // this always yields to whatever space is actually left, down to
        // 0 — confirmed via direct measurement (larger font: 907px page
        // content vs 816px available dropped to 0px overflow once the
        // floor was removed).
        scrollEl.style.maxHeight = Math.max(available - 8, 0) + 'px';
    }

    // The Auto-Approval Alerts column (see overview.blade.php's matching
    // comment) needs to match the Analytics
    // card's height exactly, not just "whichever of the two naturally
    // needs more room" — CSS grid's default stretch behavior gives the
    // latter, which at wide viewports (Analytics needs less height there,
    // real per-document alert rows don't shrink) left dead space under
    // Analytics instead of matching it. Measuring and setting this
    // explicitly, same technique as sizeRecentActivity() above, is what
    // actually pins it to Analytics specifically regardless of viewport
    // width. Must run BEFORE sizeRecentActivity(), which measures against
    // wherever this column's bottom edge lands.
    function sizeAlertColumn() {
        const analyticsCard = document.getElementById('admin-analytics-card');
        const alertsColumn = document.getElementById('admin-alerts-column');
        if (!analyticsCard || !alertsColumn) return;

        alertsColumn.style.height = analyticsCard.getBoundingClientRect().height + 'px';
    }

    document.addEventListener('DOMContentLoaded', function () {
        const overviewEl = document.getElementById('admin-overview');
        if (!overviewEl) return;

        sizeAlertColumn();
        sizeRecentActivity();
        window.addEventListener('resize', function () {
            sizeAlertColumn();
            sizeRecentActivity();
        });

        const opts = {
            refreshUrl: overviewEl.dataset.refreshUrl,
            target: overviewEl,
        };

        startLiveChannel('admin-dashboard', '.document.status-changed', opts);
        // Covers everything else on this page — logins, uploads, decisions,
        // SLA escalations, approver availability toggles — that isn't a
        // document status change but still needs Recent Activity/Analytics/
        // etc. to update live (see AdminActivityLogged).
        startLiveChannel('admin-dashboard', '.admin.activity-logged', opts);

        // The ONE reusable Analytics chart/KPI/table panel: the admin's
        // currently-selected Day/Week/Month/Year granularity + date filter
        // is tracked here in JS and fetched from the server on demand —
        // never four pre-rendered panels toggled by CSS. Seeded from
        // whatever the initial dashboard() load already rendered so
        // switching tabs immediately doesn't refetch the default view first.
        let analyticsGranularity = overviewEl.querySelector('.analytics-panel-content')?.dataset.granularity || 'day';
        let analyticsAsOf = overviewEl.querySelector('.analytics-panel-content')?.dataset.asOf || null;

        // Bug fix (2026-10-08): nothing stopped Day/Week/Month/Year being
        // clicked faster than the previous fetch for this same panel had
        // even landed — each click fired its own request with no
        // debounce, which combined with this page's other background
        // polling (see startLiveChannel/startLivePoll calls below) was
        // enough to trip the shared 'polling' rate limit (30/min, see
        // AppServiceProvider) from a handful of fast clicks alone. A
        // click while a request is already in flight is simply ignored
        // here rather than queued — the admin can always click again
        // once the panel has actually updated.
        let analyticsRequestInFlight = false;

        function loadAnalyticsPanel() {
            if (analyticsRequestInFlight) return;

            const panelEl = overviewEl.querySelector('#analytics-panel');
            if (!panelEl) return;

            const url = new URL(panelEl.dataset.refreshUrl, window.location.origin);
            url.searchParams.set('granularity', analyticsGranularity);
            if (analyticsAsOf) url.searchParams.set('as_of', analyticsAsOf);

            analyticsRequestInFlight = true;

            fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then((r) => {
                    // fetch() only rejects on a network failure, never on
                    // a non-2xx status (a 429 from the rate limiter, a
                    // 500, …) — unchecked, that response's raw body
                    // (an exception page, a plain "Too Many Attempts")
                    // used to land straight in the panel's innerHTML
                    // below. Routed to the same friendly message the
                    // network-failure .catch() already shows instead.
                    if (!r.ok) throw new Error('Request failed: ' + r.status);
                    return r.text();
                })
                .then((html) => {
                    panelEl.innerHTML = html;
                    // The Analytics card's height can change once the
                    // fetched panel content actually lands (it renders
                    // empty until then) — re-measure against it now,
                    // not just at swap time, or the alerts column can be
                    // pinned to a too-short pre-fetch height.
                    sizeAlertColumn();
                    sizeRecentActivity();
                })
                .catch(() => {
                    panelEl.innerHTML = '<p class="text-sm text-rejected-700 p-4">Couldn\'t load this view — please try again.</p>';
                })
                .finally(() => { analyticsRequestInFlight = false; });
        }

        // Feature: download the Analytics panel as a CSV. A plain
        // server-rendered <a href> doesn't work here — the overview
        // fragment this button lives in gets replaced wholesale on every
        // live refresh (same reason the tab-click listener below is
        // delegated, not bound directly), and that fragment's own $panel
        // resets to today/Day on every such swap (see overview.blade.
        // php's own comment on this). Reading analyticsGranularity/
        // analyticsAsOf from THIS closure instead — the one thing that
        // actually stays in sync with whatever the admin has selected,
        // live refreshes included — is what keeps the download matching
        // what's on screen. Exposed on window so the inline onclick=""
        // on the (replaceable) button can still reach this closure's
        // own state.
        function downloadAnalyticsCsv() {
            const panelEl = overviewEl.querySelector('#analytics-panel');
            if (!panelEl) return;

            const url = new URL(panelEl.dataset.downloadUrl, window.location.origin);
            url.searchParams.set('granularity', analyticsGranularity);
            if (analyticsAsOf) url.searchParams.set('as_of', analyticsAsOf);
            window.location = url;
        }
        window.downloadAnalyticsCsv = downloadAnalyticsCsv;

        // Feature: a title naming the exact span on the printed page,
        // matching the one the CSV download gets (see AdminController::
        // analyticsPanelData()'s own $title). __printClone() only ever
        // clones whatever element it's handed, so the title is prepended
        // onto a throwaway wrapper around a COPY of the live panel here,
        // rather than needing a permanently-visible (and redundant, next
        // to the Day/Week/Month/Year tabs' own context) heading on the
        // panel itself. Read fresh from .analytics-panel-content's own
        // data-title at click time — never cached in JS — so it's always
        // whatever was last actually fetched, live refreshes included.
        function printAnalyticsPanel() {
            const panelEl = overviewEl.querySelector('#analytics-panel');
            const contentEl = overviewEl.querySelector('.analytics-panel-content');
            if (!panelEl) return;

            const wrapper = document.createElement('div');
            wrapper.className = panelEl.className;
            if (contentEl?.dataset.title) {
                const heading = document.createElement('h2');
                heading.style.cssText = 'font-size:16px;font-weight:600;margin:0 0 12px;';
                heading.textContent = contentEl.dataset.title;
                wrapper.appendChild(heading);
            }
            wrapper.appendChild(panelEl.cloneNode(true));
            __printClone(wrapper);
        }
        window.printAnalyticsPanel = printAnalyticsPanel;

        // Tab clicks — delegated on the stable #admin-overview wrapper
        // rather than bound directly to the tab buttons, because those
        // buttons live inside admin/partials/overview.blade.php, which gets
        // replaced wholesale on every live refresh below. A listener bound
        // to the old (now-removed) buttons would silently stop working
        // after the first live update; delegation on the wrapper that never
        // gets replaced keeps working across any number of swaps.
        overviewEl.addEventListener('click', function (e) {
            const btn = e.target.closest('.analytics-tab-btn');
            if (!btn || !overviewEl.contains(btn)) return;

            analyticsGranularity = btn.dataset.analyticsTab;
            overviewEl.querySelectorAll('.analytics-tab-btn').forEach((b) => {
                b.classList.toggle('bg-primary-700', b === btn);
                b.classList.toggle('text-white', b === btn);
                b.classList.toggle('text-surface-600', b !== btn);
            });
            loadAnalyticsPanel();
        });

        overviewEl.addEventListener('change', function (e) {
            const input = e.target.closest('#analytics-date-filter');
            if (!input || !overviewEl.contains(input)) return;

            analyticsAsOf = input.value || null;
            loadAnalyticsPanel();
        });

        // Chart hover: moves the crosshair + the three series' markers to
        // whichever period is nearest the cursor, and updates the single
        // date/value readout above the chart to match — replaces a
        // permanent row of x-axis dates with exactly one date shown at a
        // time. Delegated on the wrapper (not bound to the <svg> directly)
        // for the same reason as the tab-click listener above: the chart
        // itself gets replaced wholesale on every panel swap.
        function analyticsPointAt(svg, clientX) {
            const points = JSON.parse(svg.dataset.points || '[]');
            if (!points.length) return null;
            const rect = svg.getBoundingClientRect();
            const vbWidth = svg.viewBox.baseVal.width || rect.width;
            const relX = ((clientX - rect.left) / rect.width) * vbWidth;

            let nearest = points[0];
            let minDist = Math.abs(points[0].x - relX);
            for (const p of points) {
                const dist = Math.abs(p.x - relX);
                if (dist < minDist) { minDist = dist; nearest = p; }
            }
            return nearest;
        }

        function applyAnalyticsPoint(svg, point) {
            if (!point) return;

            const crosshair = svg.querySelector('.analytics-crosshair');
            if (crosshair) { crosshair.setAttribute('x1', point.x); crosshair.setAttribute('x2', point.x); }

            ['uploaded', 'approved', 'autoapproved', 'rejected'].forEach((key) => {
                const dot = svg.querySelector(`.analytics-hover-dot-${key}`);
                // 'autoapproved' (all-lowercase, matching the CSS class
                // naming convention the other 3 dots use) reads its value
                // off the camelCase 'autoApproved'/'autoApprovedY' keys in
                // the point payload (see analytics-panel.blade.php's
                // $jsPoints) — the other 3 keys already match their class
                // name exactly, only this one needs the explicit lookup.
                const dataKey = key === 'autoapproved' ? 'autoApproved' : key;
                if (dot) { dot.setAttribute('cx', point.x); dot.setAttribute('cy', point[`${dataKey}Y`]); }
            });

            const readout = svg.closest('.analytics-panel-content')?.querySelector('[data-analytics-readout]');
            if (!readout) return;
            readout.querySelector('[data-readout-date]').textContent = point.bucket;
            readout.querySelector('[data-readout-uploaded]').textContent = point.uploaded;
            readout.querySelector('[data-readout-approved]').textContent = point.approved;
            readout.querySelector('[data-readout-autoapproved]').textContent = point.autoApproved;
            readout.querySelector('[data-readout-rejected]').textContent = point.rejected;
        }

        overviewEl.addEventListener('mousemove', function (e) {
            const svg = e.target.closest('.analytics-chart-svg');
            if (!svg) return;
            applyAnalyticsPoint(svg, analyticsPointAt(svg, e.clientX));
        });

        // mouseleave doesn't bubble, so delegation uses mouseout + a
        // relatedTarget check instead — resets to the most recent period
        // once the cursor genuinely leaves the chart (not just moving
        // between child elements within it).
        overviewEl.addEventListener('mouseout', function (e) {
            const svg = e.target.closest('.analytics-chart-svg');
            if (!svg || svg.contains(e.relatedTarget)) return;
            const points = JSON.parse(svg.dataset.points || '[]');
            applyAnalyticsPoint(svg, points[points.length - 1]);
        });

        // Every live refresh above swaps #admin-overview's ENTIRE contents
        // wholesale, including the analytics panel — and overviewRefresh()
        // deliberately doesn't compute panel data (see dashboardExtras()'s
        // docblock), so right after any such swap the panel comes back
        // empty. Re-fetch it here with whatever granularity/date the admin
        // had selected, so a background live update never silently resets
        // their filter back to the default. Recent Activity's height also
        // needs recomputing after every such swap, for the same reason
        // Document Tracker's does — the new content above it can render at
        // a different height than before.
        opts.onSwap = function (signalData) {
            loadAnalyticsPanel(signalData);
            sizeAlertColumn();
            sizeRecentActivity();
        };

        startLivePoll({ ...opts, pollUrl: overviewEl.dataset.pollUrl });
    });
</script>
@endsection
