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

        // The ONE reusable Analytics chart/KPI/table panel: a from/to range
        // is the only real scope it understands now (see AdminController::
        // analyticsRangeData()) — Day/Week/Month/Year are presets that
        // fill these two in rather than their own separate mode, so there
        // is exactly one pair of dates to track, not three. analyticsTab
        // is purely a label (which preset, if any, this from/to happens to
        // match — null once the admin hand-edits either field) sent
        // alongside purely so the backend can echo back which tab should
        // look active; it never changes what gets computed. Seeded from
        // whatever the initial dashboard() load already rendered so
        // switching tabs immediately doesn't refetch the default view first.
        let analyticsTab = overviewEl.querySelector('.analytics-panel-content')?.dataset.granularity || 'day';
        let analyticsFrom = overviewEl.querySelector('.analytics-panel-content')?.dataset.from || overviewEl.querySelector('#analytics-from-filter')?.value || null;
        let analyticsTo = overviewEl.querySelector('.analytics-panel-content')?.dataset.to || overviewEl.querySelector('#analytics-to-filter')?.value || null;
        if (analyticsTab === 'custom') analyticsTab = null;

        // The Day/Week/Month/Year preset buttons' own from/to, anchored to
        // TODAY — a plain day-count back for Week/Month/Year (7/30/365
        // days) rather than exact calendar-month/year arithmetic, since
        // this only has to fill in a reasonable starting pair for the two
        // fields the admin can see and still freely adjust; the backend's
        // own presetRangeFor() is what actually computes the authoritative
        // version when a request arrives with no from/to at all (e.g. an
        // old bookmarked ?granularity=month link).
        function presetRangeFor(tab) {
            const to = new Date();
            const from = new Date(to);
            const daysBack = { day: 0, week: 6, month: 29, year: 364 }[tab] ?? 0;
            from.setDate(from.getDate() - daysBack);
            const iso = (d) => d.toISOString().slice(0, 10);
            return [iso(from), iso(to)];
        }

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
            if (analyticsFrom) url.searchParams.set('from', analyticsFrom);
            if (analyticsTo) url.searchParams.set('to', analyticsTo);
            if (analyticsTab) url.searchParams.set('granularity', analyticsTab);

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
        // php's own comment on this). Reading analyticsFrom/analyticsTo
        // from THIS closure instead — the one thing that actually stays
        // in sync with whatever the admin has selected, live refreshes
        // included — is what keeps the download matching what's on
        // screen. Exposed on window so the inline onclick="" on the
        // (replaceable) button can still reach this closure's own state.
        function downloadAnalyticsCsv() {
            const panelEl = overviewEl.querySelector('#analytics-panel');
            if (!panelEl) return;

            const url = new URL(panelEl.dataset.downloadUrl, window.location.origin);
            if (analyticsFrom) url.searchParams.set('from', analyticsFrom);
            if (analyticsTo) url.searchParams.set('to', analyticsTo);
            if (analyticsTab) url.searchParams.set('granularity', analyticsTab);
            window.location = url;
        }
        window.downloadAnalyticsCsv = downloadAnalyticsCsv;

        // Feature: a title naming the exact span on the printed page,
        // matching the one the CSV download gets (see AdminController::
        // analyticsRangeData()'s own $title). __printClone() only ever
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

            analyticsTab = btn.dataset.analyticsTab;
            overviewEl.querySelectorAll('.analytics-tab-btn').forEach((b) => {
                b.classList.toggle('bg-primary-700', b === btn);
                b.classList.toggle('text-white', b === btn);
                b.classList.toggle('text-surface-600', b !== btn);
            });

            // Fill the From/To fields with this preset's own range (see
            // presetRangeFor() above) — these two fields are the only real
            // scope the backend understands now, the tab is just a quick
            // way to set them.
            [analyticsFrom, analyticsTo] = presetRangeFor(analyticsTab);
            const fromEl = overviewEl.querySelector('#analytics-from-filter');
            const toEl = overviewEl.querySelector('#analytics-to-filter');
            if (fromEl) fromEl.value = analyticsFrom;
            if (toEl) toEl.value = analyticsTo;

            loadAnalyticsPanel();
        });

        overviewEl.addEventListener('change', function (e) {
            const fromInput = e.target.closest('#analytics-from-filter');
            const toInput = e.target.closest('#analytics-to-filter');
            if ((!fromInput && !toInput) || !overviewEl.contains(e.target)) return;

            // Keep "from" from ever being dragged past "to" and vice versa
            // — the backend already swaps a backwards pair (see
            // AdminController::analyticsRangeData()), but catching it here
            // keeps what's on screen from silently disagreeing with what
            // the fetched data actually covers.
            const fromEl = overviewEl.querySelector('#analytics-from-filter');
            const toEl = overviewEl.querySelector('#analytics-to-filter');
            if (fromInput && toEl.value && fromEl.value > toEl.value) toEl.value = fromEl.value;
            if (toInput && fromEl.value && toEl.value < fromEl.value) fromEl.value = toEl.value;

            analyticsFrom = fromEl.value || null;
            analyticsTo = toEl.value || null;

            // A hand edit to either field means this no longer necessarily
            // matches any preset — drop the tab hint and let the backend
            // label the result 'custom' (see analyticsRangeData()'s own
            // docblock); no tab stays highlighted once the admin has
            // adjusted the dates themselves.
            analyticsTab = null;
            overviewEl.querySelectorAll('.analytics-tab-btn').forEach((b) => {
                b.classList.remove('bg-primary-700', 'text-white');
                b.classList.add('text-surface-600');
            });

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

        // Formats one hovered point's raw counts into the same 6 metrics
        // the server computes for the aggregate (see AdminController::
        // analyticsKpis()) — mirrored here only for VALUES, since a single
        // point has no "previous period" of its own to diff against; the
        // trend row stays hidden while hovering for exactly that reason.
        function hoverKpiValues(point) {
            const decided = point.approved + point.autoApproved + point.rejected;
            const rate = (num) => (decided > 0 ? (num / decided * 100).toFixed(1) + '%' : '—');
            const minutes = point.avgMinutes;
            const h = Math.floor((minutes || 0) / 60), m = Math.round((minutes || 0) % 60);
            return {
                uploaded: String(point.uploaded),
                approval_rate: rate(point.approved),
                auto_approval_rate: rate(point.autoApproved),
                rejection_rate: rate(point.rejected),
                avg_minutes: minutes === null ? '—' : (h > 0 ? `${h}h ${m}m` : `${m}m`),
                sla_violation_rate: rate(point.violatedDocuments),
            };
        }

        // Swaps all 6 KPI tiles either to one hovered point's own values
        // (trend row hidden — a single point has nothing to diff against)
        // or back to the whole-window aggregate (trend row restored from
        // the exact pre-formatted strings PHP rendered — see
        // analytics-panel.blade.php's $kpiAggregate, so a revert can never
        // drift from what the server actually calculated).
        function applyKpiTiles(panelContent, point, isAggregate) {
            if (!panelContent) return;

            if (isAggregate) {
                const raw = panelContent.querySelector('[data-kpi-aggregate-json]')?.textContent;
                if (!raw) return;
                const aggregate = JSON.parse(raw);
                Object.keys(aggregate).forEach((key) => {
                    const tile = aggregate[key];
                    const valueEl = panelContent.querySelector(`[data-kpi-value="${key}"]`);
                    const trendEl = panelContent.querySelector(`[data-kpi-trend="${key}"]`);
                    if (valueEl) valueEl.textContent = tile.value;
                    if (!trendEl) return;
                    trendEl.style.display = tile.trend === null ? 'none' : '';
                    trendEl.className = trendEl.className.replace(/text-(approved|rejected|surface)-\d+/, tile.trendColor);
                    trendEl.querySelector('[data-kpi-trend-up]').style.display = tile.trendUp ? '' : 'none';
                    trendEl.querySelector('[data-kpi-trend-down]').style.display = tile.trendDown ? '' : 'none';
                    trendEl.querySelector('[data-kpi-trend-text]').textContent = tile.trend ?? '';
                });
                return;
            }

            const values = hoverKpiValues(point);
            Object.keys(values).forEach((key) => {
                const valueEl = panelContent.querySelector(`[data-kpi-value="${key}"]`);
                const trendEl = panelContent.querySelector(`[data-kpi-trend="${key}"]`);
                if (valueEl) valueEl.textContent = values[key];
                if (trendEl) trendEl.style.display = 'none';
            });
        }

        function applyAnalyticsPoint(svg, point, isAggregate) {
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

            const panelContent = svg.closest('.analytics-panel-content');
            const readout = panelContent?.querySelector('[data-analytics-readout]');
            if (readout) {
                // On mouseout this reverts to the whole-window totals (see
                // analytics-panel.blade.php's $aggregateReadout), not
                // whichever bucket happens to be last — e.g. a Day view's
                // final hour is almost always still empty while today is
                // in progress, which is the original bug this line started
                // from. Hovering still shows that one specific point's own
                // numbers, same as before.
                const readoutData = isAggregate
                    ? JSON.parse(panelContent.querySelector('[data-readout-aggregate-json]')?.textContent || '{}')
                    : point;
                readout.querySelector('[data-readout-date]').textContent = readoutData.bucket;
                readout.querySelector('[data-readout-uploaded]').textContent = readoutData.uploaded;
                readout.querySelector('[data-readout-approved]').textContent = readoutData.approved;
                readout.querySelector('[data-readout-autoapproved]').textContent = readoutData.autoApproved;
                readout.querySelector('[data-readout-rejected]').textContent = readoutData.rejected;
                readout.querySelector('[data-readout-violated]').textContent = readoutData.violatedDocuments;
            }

            applyKpiTiles(panelContent, point, isAggregate);
        }

        overviewEl.addEventListener('mousemove', function (e) {
            const svg = e.target.closest('.analytics-chart-svg');
            if (!svg) return;
            applyAnalyticsPoint(svg, analyticsPointAt(svg, e.clientX), false);
        });

        // mouseleave doesn't bubble, so delegation uses mouseout + a
        // relatedTarget check instead — resets once the cursor genuinely
        // leaves the chart (not just moving between child elements within
        // it). The readout line and the crosshair/dots rest on the most
        // recent period, same as always; the KPI tiles specifically revert
        // to the whole-window aggregate instead (isAggregate=true) — see
        // applyKpiTiles()'s own docblock for why that distinction matters.
        overviewEl.addEventListener('mouseout', function (e) {
            const svg = e.target.closest('.analytics-chart-svg');
            if (!svg || svg.contains(e.relatedTarget)) return;
            const points = JSON.parse(svg.dataset.points || '[]');
            applyAnalyticsPoint(svg, points[points.length - 1], true);
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
