{{--
    Performance Insights results — split out from performance_insights.blade.php
    so the same markup can be rendered two ways: a normal full page load, and
    a fragment returned by AdminController::performanceInsightsRefresh() for
    the live-poll JS to swap in place, without a full page reload.

    Three plain historical rankings, side by side — see
    PerformanceInsightsService's docblock for why none of this is ML.

    Feature: a Fastest/Slowest toggle — both directions are rendered here
    (one hidden via the `hidden` class), swapped by the plain JS toggle in
    performance_insights.blade.php. Rendering both server-side rather than
    fetching "slowest" on click keeps the toggle instant (no request) and
    keeps this fragment self-contained for the live-poll swap.
--}}
@php
    // nameLabel drives the small column-header row added above each
    // list — differs per panel type since Departments/Categories aren't
    // person-level ("Name" would be wrong there).
    $modes = [
        'fastest' => [
            ['title' => 'Fastest Approvers', 'subtitle' => 'Ranked by average time from assignment to decision.', 'rows' => $fastestApprovers, 'empty' => 'Not enough decision history yet to rank approvers.', 'nameLabel' => 'Name'],
            ['title' => 'Fastest Departments', 'subtitle' => 'Same ranking, grouped by department.', 'rows' => $fastestDepartments, 'empty' => 'Not enough decision history yet to rank departments.', 'nameLabel' => 'Department'],
            ['title' => 'Fastest Categories', 'subtitle' => 'Which document types move through approval quickest.', 'rows' => $fastestCategories, 'empty' => 'Not enough decision history yet to rank categories.', 'nameLabel' => 'Category'],
        ],
        'slowest' => [
            ['title' => 'Slowest Approvers', 'subtitle' => 'Ranked by average time from assignment to decision.', 'rows' => $slowestApprovers, 'empty' => 'Not enough decision history yet to rank approvers.', 'nameLabel' => 'Name'],
            ['title' => 'Slowest Departments', 'subtitle' => 'Same ranking, grouped by department.', 'rows' => $slowestDepartments, 'empty' => 'Not enough decision history yet to rank departments.', 'nameLabel' => 'Department'],
            ['title' => 'Slowest Categories', 'subtitle' => 'Which document types move through approval slowest.', 'rows' => $slowestCategories, 'empty' => 'Not enough decision history yet to rank categories.', 'nameLabel' => 'Category'],
        ],
    ];
@endphp

{{-- The toggle itself — a segmented pill, not two plain buttons, so the
     active side is unmistakable at a glance (solid fill + white text vs
     a plain hover state), matching the "must have a design to know it's
     clicked" ask. Delegated click handling lives in
     performance_insights.blade.php's script, since this whole fragment
     (toggle included) gets replaced wholesale on every live swap. --}}
<div class="flex justify-center mb-4">
    <div class="inline-flex items-center gap-1 rounded-full border border-surface-200 bg-white shadow-card p-1">
        <button type="button" data-perf-mode-btn="fastest" aria-pressed="true"
            class="px-5 py-2 rounded-full text-xs font-semibold transition-colors bg-primary-700 text-white">
            Fastest
        </button>
        <button type="button" data-perf-mode-btn="slowest" aria-pressed="false"
            class="px-5 py-2 rounded-full text-xs font-semibold transition-colors text-surface-600 hover:bg-surface-100">
            Slowest
        </button>
    </div>
</div>

@foreach($modes as $mode => $panels)
{{-- transition-opacity (Feature: smooth Fastest/Slowest toggle) — the
     click handler in performance_insights.blade.php fades opacity to 0,
     swaps which panel has `hidden`, then fades the new one in, driven by
     this CSS transition rather than the View Transitions API (see that
     script's own comment for why the two don't mix well here). --}}
<div data-perf-mode-panel="{{ $mode }}" class="grid grid-cols-1 lg:grid-cols-3 gap-4 transition-opacity duration-150 {{ $mode === 'fastest' ? '' : 'hidden' }}">
    @foreach($panels as $panel)
        <div class="bg-white rounded-xl shadow-card border border-surface-200 overflow-hidden">
            <div class="px-5 py-3 border-b border-surface-200">
                <h2 class="text-sm font-semibold text-surface-900 tracking-tight">{{ $panel['title'] }}</h2>
                <p class="text-xs text-surface-400 mt-0.5">{{ $panel['subtitle'] }}</p>
            </div>
            @if($panel['rows']->isNotEmpty())
                {{-- Column header row — no leading spacer, so the name
                     label starts flush with the rank circle's own left
                     edge below it, not indented to match the name text.
                     Darker than a typical muted label (surface-600, not
                     -400) specifically because this row's job is to label
                     the data, not recede like ambient chrome. --}}
                <div class="px-5 pt-2.5 pb-1 flex items-center gap-3 text-xs font-semibold text-surface-600 uppercase tracking-wide">
                    <span class="flex-1">{{ $panel['nameLabel'] }}</span>
                    <span class="shrink-0">Avg. Decision Time</span>
                </div>
            @endif
            <ul class="divide-y divide-surface-100">
                @forelse($panel['rows'] as $i => $row)
                    <li class="px-5 py-3 flex items-center gap-3">
                        <span class="w-5 h-5 shrink-0 rounded-full flex items-center justify-center text-[11px] font-bold {{ $i === 0 ? 'bg-approved-500 text-white' : 'bg-surface-100 text-surface-500' }}">{{ $i + 1 }}</span>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-surface-800 truncate">
                                {{ $row['label'] }}
                                {{-- Only set for the Approvers panels (see
                                     PerformanceInsightsService::approverRoleLabel())
                                     — answers "is this a Head or Staff
                                     Approver" directly in the ranking
                                     instead of leaving the two mixed
                                     together with no way to tell them apart. --}}
                                @if($row['role'])
                                    <span class="text-xs font-normal text-surface-400">({{ $row['role'] }})</span>
                                @endif
                            </p>
                            <p class="text-xs text-surface-400">{{ $row['decisions_count'] }} decision{{ $row['decisions_count'] === 1 ? '' : 's' }}</p>
                        </div>
                        <span class="shrink-0 text-xs font-semibold text-surface-700 tabular-nums">{{ \Carbon\CarbonInterval::seconds($row['avg_seconds'])->cascade()->forHumans(['short' => true]) }}</span>
                    </li>
                @empty
                    <li class="px-5 py-8 text-center text-xs text-surface-400">{{ $panel['empty'] }}</li>
                @endforelse
            </ul>
        </div>
    @endforeach
</div>
@endforeach
