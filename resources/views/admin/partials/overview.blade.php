{{--
    KPI cards (5 — Active ML Model removed; it's still front-and-center on
    the ML Training page, one click away via the sidebar, so nothing here
    was lost) + a two-column row below: Recent Activity/Analytics on the
    left, Auto-Approval Alerts/Unassigned Documents on the right —
    reorganized (Feature: fit the whole Control Center without scrolling
    the page itself) so nothing sits in one long stacked column. Split out
    from dashboard.blade.php so the same markup can be rendered two ways:
    a normal full page load, and a fragment returned by
    AdminController::overviewRefresh() for the live-poll JS to swap in
    place (see dashboard.blade.php) without a full page reload.
--}}
<div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
    @foreach([
        ['Total Documents', $stats['total_documents'], 'text-surface-900', 'bg-surface-100 text-surface-600', 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z', 'total', 'All documents ever submitted to the system, regardless of status.'],
        ['In Progress', $stats['pending'], 'text-processing-700', 'bg-processing-50 text-processing-600', 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z', 'pending', 'Still being classified or validated, or auto-approved but still awaiting your review.'],
        ['Approved', $stats['approved'], 'text-approved-700', 'bg-approved-50 text-approved-600', 'M9 12.75 11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z', 'approved', 'Fully settled — approved by a person, or auto-approved and already reviewed by you.'],
        ['Rejected', $stats['rejected'], 'text-rejected-700', 'bg-rejected-50 text-rejected-600', 'M6 18L18 6M6 6l12 12', 'rejected', 'Rejected during validation or by an approver.'],
        ['All Users', $stats['active_users'], 'text-primary-700', 'bg-primary-50 text-primary-600', 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-8a4 4 0 11-8 0 4 4 0 018 0zm6 3a4 4 0 11-8 0 4 4 0 018 0z', 'users', 'Every account in the system — active and deactivated alike.'],
    ] as [$label, $value, $color, $iconClasses, $iconPath, $type, $description])
        <button type="button"
            onclick="openKpiDrilldown('{{ $type }}', '{{ $label }}', '{{ route('admin.dashboard.drilldown', $type) }}')"
            data-kpi-tooltip="{{ $description }}"
            class="text-left w-full bg-white rounded-xl shadow-card hover:shadow-card-hover border border-surface-200 p-4 transition-shadow cursor-pointer focus:outline-none focus:ring-2 focus:ring-primary-500">
            <div class="w-8 h-8 rounded-lg flex items-center justify-center mb-2 {{ $iconClasses }}">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $iconPath }}"/></svg>
            </div>
            <p class="text-xs text-surface-500 mb-0.5 font-medium">{{ $label }}</p>
            <p class="text-xl font-bold {{ $color }} tabular-nums">{{ $value }}</p>
        </button>
    @endforeach
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mt-4">
    {{--
        Left column (2/3 width): Analytics alone. Recent Activity moved out
        to its own full-width row below this whole grid — see the bottom
        of this file — rather than stacked in here, per the explicit ask
        to give it the full page width instead of sharing this column.
    --}}
    <div class="lg:col-span-2">
        {{--
            Analytics — ONE reusable panel (see
            AdminController::analyticsPanelData()) whose content is
            fetched via AJAX whenever the Day/Week/Month/Year tab or the
            date filter changes — never four pre-rendered panels toggled
            by CSS. The wrapper below carries the persistent id + refresh
            URL and survives every swap; only what's inside it is
            replaced (see dashboard.blade.php's script). $panel is only
            passed in on the initial dashboard() load — overviewRefresh()'s
            periodic live-swap deliberately omits it (see
            dashboardExtras()'s docblock), so the script re-fetches the
            currently-selected state right after any such swap. Chart
            height itself is compacted in analytics-panel.blade.php (both
            the viewBox and rendered height, kept proportional so nothing
            renders squashed) rather than clipped here — clipping a chart
            mid-plot would just hide data, not actually shrink it.
        --}}
        <div id="admin-analytics-card" class="bg-white rounded-xl shadow-card border border-surface-200 overflow-hidden">
            <div class="px-5 py-3 border-b border-surface-200 flex items-center justify-between flex-wrap gap-3">
                <h2 class="text-sm font-semibold text-surface-900 tracking-tight">Analytics</h2>
                <div class="flex items-center gap-2 flex-wrap">
                    <input type="date" id="analytics-date-filter" value="{{ $panel['as_of'] ?? now()->toDateString() }}"
                        max="{{ now()->toDateString() }}"
                        class="text-xs rounded-lg border border-surface-200 px-2 py-1.5 text-surface-600 focus:outline-none focus:ring-1 focus:ring-primary-500" />
                    <div class="inline-flex rounded-lg border border-surface-200 overflow-hidden text-xs font-medium">
                        @foreach(['day' => 'Day', 'week' => 'Week', 'month' => 'Month', 'year' => 'Year'] as $key => $label)
                            <button type="button" data-analytics-tab="{{ $key }}"
                                class="analytics-tab-btn px-3 py-1.5 transition-colors {{ ($panel['granularity'] ?? 'day') === $key ? 'bg-primary-700 text-white' : 'text-surface-600 hover:bg-surface-50' }}">
                                {{ $label }}
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="px-5 py-1.5 border-b border-surface-100 flex flex-wrap gap-x-6 gap-y-1 text-xs text-surface-500">
                <span>Peak upload day: <span class="font-semibold text-surface-700">{{ $analytics['peak_day'] ?? '—' }}</span></span>
                <span>Peak upload hour: <span class="font-semibold text-surface-700">{{ $analytics['peak_hour'] ?? '—' }}</span></span>
                <span>Currently in progress: <span class="font-semibold text-surface-700">{{ $analytics['backlog_count'] }}</span> document{{ $analytics['backlog_count'] === 1 ? '' : 's' }}</span>
            </div>

            <div id="analytics-panel" data-refresh-url="{{ route('admin.dashboard.analyticsPanel') }}">
                @isset($panel)
                    @include('admin.partials.analytics-panel', ['panel' => $panel])
                @endisset
            </div>
        </div>
    </div>

    {{--
        Right column (1/3 width) — Auto-Approval Alerts (ML Model moved up
        into the KPI row as its own clickable card; Category Volume/
        Performance Insights/Unassigned Documents removed from this column
        entirely — a stage with no eligible approver auto-approves
        immediately now instead of sitting in its own queue, see
        WorkflowService::assignStage()). This column's actual pixel height
        is explicitly measured off the Analytics card and set via JS
        (sizeAlertColumn() in dashboard.blade.php, same technique as
        sizeRecentActivity() below) rather than trusted to pure CSS
        stretch, so it matches Analytics exactly instead of CSS grid's
        default row-stretch leaving dead space under a shorter column.
    --}}
    <div id="admin-alerts-column" class="flex flex-col gap-4">
        <div class="flex-1 min-h-0 flex flex-col bg-white rounded-xl shadow-card border border-surface-200 overflow-hidden">
            <div class="px-5 py-3 border-b border-surface-200 flex items-center justify-between gap-2 shrink-0">
                <div class="flex items-center gap-2 min-w-0">
                    <h2 class="text-sm font-semibold text-surface-900 tracking-tight flex items-center gap-2 shrink-0">
                        <span class="relative flex w-2 h-2 shrink-0">
                            <span class="absolute inline-flex h-full w-full rounded-full bg-processing-400 opacity-75 animate-ping"></span>
                            <span class="relative inline-flex rounded-full h-2 w-2 bg-processing-500"></span>
                        </span>
                        Auto-Approval Alerts
                    </h2>
                    @if($reviewCount > 0)
                        <a href="{{ route('admin.sla.queue') }}" class="shrink-0 text-xs bg-processing-50 text-processing-700 px-2 py-0.5 rounded-full font-medium ring-1 ring-inset ring-processing-500/20 hover:bg-processing-100 transition-colors">{{ $reviewCount }} awaiting review</a>
                    @endif
                </div>
                <a href="{{ route('admin.sla.queue') }}" class="shrink-0 text-xs text-primary-700 hover:underline font-medium">View all &rarr;</a>
            </div>
            <ul class="flex-1 overflow-y-auto min-h-[80px] divide-y divide-surface-100 flex flex-col">
                @forelse($autoApprovalAlerts as $row)
                    <li class="px-5 py-2.5 hover:bg-surface-50/60 transition-colors">
                        <div class="flex items-center justify-between gap-3">
                            <p class="font-medium text-surface-800 truncate">{{ $row->document->title }}</p>
                            <a href="{{ route('admin.sla.queue') }}" class="shrink-0 text-xs bg-primary-700 text-white px-2.5 py-1 rounded-lg font-medium hover:bg-primary-800 shadow-sm transition-colors">Review</a>
                        </div>
                        <p class="text-xs text-surface-400 mt-0.5">
                            {{ $row->stage_count }} stage{{ $row->stage_count === 1 ? '' : 's' }} auto-approved &middot;
                            <span data-live-time="{{ optional($row->acted_at)->timestamp }}">{{ optional($row->acted_at)->diffForHumans() }}</span>
                        </p>
                    </li>
                @empty
                    <li class="flex-1 flex items-center justify-center px-5 py-6 text-center text-sm text-surface-400">No documents are currently awaiting review — everything is on schedule.</li>
                @endforelse
            </ul>
        </div>
    </div>
</div>

{{--
    Recent activity feed — full width, its own row below everything
    above. Literally the Audit Trail's own row partial (admin/partials/
    audit-row.blade.php), not just similarly styled — same columns, same
    badges, same document-grouped expand-to-view-movements interaction,
    so this reads as a compact preview of that exact table rather than a
    separately-built lookalike. Bounded to the last 5 merged document/
    system rows (see AdminController::recentActivityRows()), but its
    scroll area's height is NOT a fixed guess (a flat max-height here
    plus everything above it didn't reliably fit every screen) — it's
    computed live by sizeRecentActivity() in dashboard.blade.php, the
    exact same technique already proven on the Document Tracker page:
    measure the real remaining space down to <main>'s own bottom edge and
    fill exactly that, so this is the one thing that scrolls internally
    and the PAGE itself never has to.
--}}
<div class="bg-white rounded-xl shadow-card border border-surface-200 overflow-hidden mt-4 flex flex-col">
    <div class="px-5 py-2.5 border-b border-surface-200 flex items-center justify-between shrink-0">
        <h2 class="text-sm font-semibold text-surface-900 tracking-tight">Recent Activity</h2>
        <a href="{{ route('admin.audit.logs') }}" class="text-xs text-primary-700 hover:underline font-medium">View all &rarr;</a>
    </div>
    @php
        $actionCategories = \App\Services\DocumentMovementTimeline::ACTION_CATEGORIES;
        $categoryClasses = \App\Services\DocumentMovementTimeline::CATEGORY_CLASSES;
        $actionLabels = \App\Services\DocumentMovementTimeline::ACTION_LABELS;
    @endphp
    <div id="admin-recent-activity-scroll" class="overflow-x-auto overflow-y-auto">
    <table class="w-full min-w-[720px] text-sm">
        <thead class="sticky top-0 bg-surface-50 text-surface-500 text-xs uppercase tracking-wide">
            <tr>
                <th class="text-left px-6 py-2 font-medium">Timestamp</th>
                <th class="text-left px-6 py-2 font-medium">Document Title</th>
                <th class="text-left px-6 py-2 font-medium">Actor</th>
                <th class="text-left px-6 py-2 font-medium">Action</th>
                <th class="text-left px-6 py-2 font-medium">Track</th>
                <th class="text-left px-6 py-2 font-medium">Description</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-surface-100">
            @forelse($recentActivity as $row)
                @include('admin.partials.audit-row', ['row' => $row])
            @empty
                <tr><td colspan="6" class="px-6 py-6 text-center text-sm text-surface-400">No recent activity.</td></tr>
            @endforelse
        </tbody>
    </table>
    </div>
</div>
