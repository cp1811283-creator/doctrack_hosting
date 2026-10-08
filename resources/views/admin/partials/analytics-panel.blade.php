{{--
    The ONE reusable Analytics chart panel — KPI tiles + line chart for
    whichever single granularity/date combination the admin currently has
    selected. Rendered two ways: inline on the full dashboard load
    (AdminController::dashboard()) and as a fragment returned by
    analyticsPanelRefresh() for the Day/Week/Month/Year tabs and the date
    filter to swap in place — same template either way, so there's
    exactly one implementation, never a per-tab copy.

    Expects: $panel = ['granularity', 'label', 'as_of', 'title', 'peak', 'chart_rows', 'kpi'].
--}}
@php
    $chartRows = $panel['chart_rows'];
    $kpi = $panel['kpi'];
    $label = $panel['label'];

    // Fixed internal coordinate space (not real pixels) — paired with
    // preserveAspectRatio="none" and width="100%" on the <svg> below, this
    // is what makes the chart stretch to fill the panel's full width no
    // matter how many periods are plotted, instead of a fixed-pixel chart
    // sitting in a corner with dead space beside it.
    // RIGHT_PAD keeps the very last point off the literal right edge — with
    // no margin there, the last period's curve/marker sat flush against the
    // boundary and read as if it had been sliced off mid-rise rather than
    // completing naturally (most noticeable on the Day tab, where the last
    // point is the day's final hour).
    // VBH (and the rendered height="" on the <svg> below) trimmed from the
    // original 280/220 together, proportionally — Feature: compact the
    // Control Center to fit without scrolling. Kept proportional
    // deliberately: shrinking only the rendered height while leaving VBH
    // alone would make preserveAspectRatio="none" squash Y harder than X,
    // visibly distorting the 9px axis labels/gridlines instead of just
    // shrinking the whole chart uniformly.
    $VBW = 1000; $VBH = 170; $AXIS_W = 34; $RIGHT_PAD = 16; $TOP_PAD = 8; $BOTTOM_PAD = 8;
    $chartH = $VBH - $TOP_PAD - $BOTTOM_PAD;
    $chartMax = collect($chartRows)->flatMap(fn ($r) => [$r->uploaded, $r->human_approved, $r->auto_approved, $r->rejected])->max() ?: 1;
    $stepX = count($chartRows) > 1 ? ($VBW - $AXIS_W - $RIGHT_PAD) / (count($chartRows) - 1) : 0;

    // Point coordinates per series, reused for the smoothed path, the area
    // fill under it, and the hover payload — computed once so the curve
    // and the crosshair it drives can never drift apart.
    $pointsFor = function (string $field) use ($chartRows, $chartMax, $chartH, $TOP_PAD, $AXIS_W, $stepX) {
        return collect($chartRows)->map(function ($row, $i) use ($field, $chartMax, $chartH, $TOP_PAD, $AXIS_W, $stepX) {
            $x = round($AXIS_W + $i * $stepX, 2);
            $y = round($TOP_PAD + $chartH - ($row->{$field} / $chartMax) * $chartH, 2);
            return ['x' => $x, 'y' => $y, 'value' => $row->{$field}, 'bucket' => $row->bucket];
        })->all();
    };
    $uploadedPoints = $pointsFor('uploaded');
    // Human-decided approvals only — the combined 'approved' field
    // (human + auto) still exists on $chartRows for anything else that
    // wants total approved volume, but plotting it here would double-count
    // against the separate Auto Approved line below.
    $approvedPoints = $pointsFor('human_approved');
    $autoApprovedPoints = $pointsFor('auto_approved');
    $rejectedPoints = $pointsFor('rejected');

    // Catmull-Rom-to-Bezier: turns the straight-segment point list into a
    // smooth curved path — matches how a real analytics tool's line chart
    // reads, instead of sharp angles between periods.
    $smoothPath = function (array $pts): string {
        $n = count($pts);
        if ($n === 0) return '';
        if ($n === 1) return "M {$pts[0]['x']},{$pts[0]['y']}";
        $d = "M {$pts[0]['x']},{$pts[0]['y']} ";
        for ($i = 0; $i < $n - 1; $i++) {
            $p0 = $pts[$i - 1] ?? $pts[$i];
            $p1 = $pts[$i];
            $p2 = $pts[$i + 1];
            $p3 = $pts[$i + 2] ?? $p2;
            $c1x = round($p1['x'] + ($p2['x'] - $p0['x']) / 6, 2);
            $c1y = round($p1['y'] + ($p2['y'] - $p0['y']) / 6, 2);
            $c2x = round($p2['x'] - ($p3['x'] - $p1['x']) / 6, 2);
            $c2y = round($p2['y'] - ($p3['y'] - $p1['y']) / 6, 2);
            $d .= "C {$c1x},{$c1y} {$c2x},{$c2y} {$p2['x']},{$p2['y']} ";
        }
        return trim($d);
    };
    $baselineY = $TOP_PAD + $chartH;
    $areaPath = function (array $pts) use ($smoothPath, $baselineY): string {
        if (count($pts) === 0) return '';
        $first = $pts[0]; $last = $pts[count($pts) - 1];
        return $smoothPath($pts)." L {$last['x']},{$baselineY} L {$first['x']},{$baselineY} Z";
    };

    // "Now" marker — only meaningful on the Day tab (an hour-of-day axis)
    // and only when the selected date is actually today; a past/future
    // date has no "now" position on it. Snapped to the nearest 15-minute
    // mark (matching __docTrackUpdateAnalyticsNowLine in layouts/app.blade.php,
    // which takes over ticking it forward after this first paint) — every
    // minute of movement was only a fraction of a pixel on this axis and
    // read as barely moving; stepping in quarters makes each move visible.
    //
    // Positioned as a FRACTIONAL INDEX into the same point sequence the
    // 24 hour-points above use (x = AXIS_W + index * stepX, 23 gaps
    // across 24 points) — not an independent minutes-into-day/1440
    // fraction. Those two scales don't match (1/24 per hour vs 1/23),
    // which drifted the line away from where the actual hour points sit,
    // worst by noon/end-of-day. Reusing $stepX directly guarantees this
    // always lines up with the real point positions, by construction.
    $nowLineX = null;
    $nowLabel = null;
    if (($panel['granularity'] ?? null) === 'day' && $panel['as_of'] === now()->toDateString() && count($chartRows) > 1) {
        $snappedMinute = intdiv(now()->minute, 15) * 15;
        $fractionalIndex = now()->hour + ($snappedMinute / 60);
        $nowLineX = round($AXIS_W + $fractionalIndex * $stepX, 2);
        $nowLabel = now()->copy()->setTime(now()->hour, $snappedMinute)->format('g:i A');
    }

    // The single reusable hover payload — one entry per period, all four
    // series' values + precomputed y-coordinates, so the client-side
    // crosshair (see dashboard.blade.php) can move the markers and update
    // the one-date readout without any further server round-trip.
    $jsPoints = collect($chartRows)->values()->map(fn ($row, $i) => [
        'x' => $uploadedPoints[$i]['x'],
        'bucket' => $row->bucket,
        'uploaded' => $row->uploaded, 'uploadedY' => $uploadedPoints[$i]['y'],
        'approved' => $row->human_approved, 'approvedY' => $approvedPoints[$i]['y'],
        'autoApproved' => $row->auto_approved, 'autoApprovedY' => $autoApprovedPoints[$i]['y'],
        'rejected' => $row->rejected, 'rejectedY' => $rejectedPoints[$i]['y'],
    ])->all();
    $latestPoint = $jsPoints[count($jsPoints) - 1] ?? null;

    // KPI tile definitions — one small config array instead of repeating
    // near-identical markup 5 times. goodDirection reuses this app's
    // existing approved/rejected status colors (green/red already mean
    // "good"/"bad" everywhere else in this app) for the trend arrow,
    // rather than inventing new color semantics just for this panel; null
    // means "no inherent good/bad direction" (e.g. auto-approval rate is
    // informative, not a target to chase up or down), so its arrow stays neutral.
    $tiles = [
        ['label' => 'Uploaded', 'value' => $kpi['current']['uploaded'] ?? null, 'trend' => $kpi['trend']['uploaded'] ?? null, 'format' => 'count', 'good' => 'up', 'description' => 'How many documents were submitted during this period.'],
        ['label' => 'Approval Rate', 'value' => $kpi['current']['approval_rate'] ?? null, 'trend' => $kpi['trend']['approval_rate'] ?? null, 'format' => 'percent', 'good' => 'up', 'description' => 'Share of decided documents approved by a person — excludes auto-approvals, see Auto-Approval Rate for those.'],
        ['label' => 'Auto-Approval Rate', 'value' => $kpi['current']['auto_approval_rate'] ?? null, 'trend' => $kpi['trend']['auto_approval_rate'] ?? null, 'format' => 'percent', 'good' => null, 'description' => 'Share of approvals the system made automatically because nobody acted in time.'],
        ['label' => 'Rejection Rate', 'value' => $kpi['current']['rejection_rate'] ?? null, 'trend' => $kpi['trend']['rejection_rate'] ?? null, 'format' => 'percent', 'good' => 'down', 'description' => 'Share of decided documents that were rejected. Together, Approval Rate + Auto-Approval Rate + Rejection Rate add up to 100% of decided documents.'],
        ['label' => 'Avg. Time to Decide', 'value' => $kpi['current']['avg_minutes'] ?? null, 'trend' => $kpi['trend']['avg_minutes'] ?? null, 'format' => 'duration', 'good' => 'down', 'description' => 'Average time from upload to a final decision in this period.'],
        ['label' => 'SLA Violation Rate', 'value' => $kpi['current']['sla_violation_rate'] ?? null, 'trend' => $kpi['trend']['sla_violation_rate'] ?? null, 'format' => 'percent', 'good' => 'down', 'description' => 'Share of decisions that missed their SLA deadline.'],
    ];
@endphp
{{-- No id here — the persistent id lives on the wrapper in overview.blade.php that never gets replaced; this root is the swap payload itself, identified instead by its data attributes so the dashboard script can read back the state it just rendered. --}}
{{-- data-title: read by printAnalyticsPanel() in dashboard.blade.php at
     print-click time — see that function's own docblock for why it's
     read fresh from here rather than tracked separately in JS. --}}
<div class="analytics-panel-content" data-granularity="{{ $panel['granularity'] }}" data-as-of="{{ $panel['as_of'] }}" data-title="{{ $panel['title'] }}">
    {{-- Which "peak" question is shown depends on the tab — see
         AdminController::analyticsPeak()'s own docblock for why Day only
         gets an hour, Week gets both a day and an hour, Month gets a
         specific date, and Year gets a month. Omitted entirely (not a
         "—" placeholder) when this window has no uploads at all, same
         reasoning as the chart's own "No data yet" message below. --}}
    @if(!empty($panel['peak']))
        <div class="px-5 pt-3 flex flex-wrap gap-x-6 gap-y-1 text-xs text-surface-500">
            @foreach($panel['peak'] as $peak)
                <span>{{ $peak['label'] }}: <span class="font-semibold text-surface-700">{{ $peak['value'] }}</span></span>
            @endforeach
        </div>
    @endif

    {{-- KPI tiles: this period's headline numbers, with a % trend against
         the immediately preceding period — the scannable summary a chart
         alone can't give you at a glance. --}}
    <div class="px-5 pt-3 grid grid-cols-3 sm:grid-cols-6 gap-2">
        @foreach($tiles as $tile)
            @php
                $trendUp = $tile['trend'] !== null && $tile['trend'] > 0;
                $trendDown = $tile['trend'] !== null && $tile['trend'] < 0;
                $isGoodTrend = $tile['good'] === null ? null : (($tile['good'] === 'up' && $trendUp) || ($tile['good'] === 'down' && $trendDown));
                $trendColor = $isGoodTrend === null ? 'text-surface-400' : ($isGoodTrend ? 'text-approved-600' : 'text-rejected-600');
                $displayValue = match ($tile['format']) {
                    'percent' => $tile['value'] !== null ? number_format($tile['value'], 1) . '%' : '—',
                    'duration' => $tile['value'] !== null ? \Carbon\CarbonInterval::minutes($tile['value'])->cascade()->forHumans(['short' => true]) : '—',
                    default => $tile['value'] !== null ? number_format($tile['value']) : '—',
                };
            @endphp
            {{-- data-kpi-tooltip reuses the exact same shared tooltip
                 element/listener the Control Center's 5 KPI cards already
                 use (see dashboard.blade.php) — no new JS needed, the
                 delegated listener there just picks up any element with
                 this attribute. --}}
            <div class="rounded-lg border border-surface-200 bg-surface-50/50 px-3 py-2" data-kpi-tooltip="{{ $tile['description'] }}">
                <p class="text-[11px] font-medium text-surface-500 uppercase tracking-wide truncate">{{ $tile['label'] }}</p>
                <div class="flex items-baseline gap-1.5 mt-0.5 flex-wrap">
                    <span class="text-lg font-semibold text-surface-900 tabular-nums">{{ $displayValue }}</span>
                    @if($tile['trend'] !== null)
                        <span class="text-xs font-medium {{ $trendColor }} inline-flex items-center gap-0.5" title="vs previous {{ $panel['trend_label'] }}">
                            @if($trendUp)
                                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 15l7-7 7 7"/></svg>
                            @elseif($trendDown)
                                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                            @endif
                            {{ number_format(abs($tile['trend']), 1) }}%
                        </span>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    {{-- Continuous line chart: uploaded / approved / rejected per period,
         every period in range plotted (zero-filled where nothing
         happened — see AdminController::analyticsBuckets()) so the curve
         reads as an actual trend instead of jumping between the only
         periods that had activity. Fills the panel's full width (fixed
         viewBox + preserveAspectRatio="none", not a fixed-pixel chart with
         dead space beside it) and tracks the cursor: hovering moves the
         crosshair to the nearest period and updates the single date/value
         readout below instead of a permanent row of x-axis dates.
         Delegated hover handling lives once in dashboard.blade.php's
         script, bound to the persistent #admin-overview wrapper — see its
         comment for why — so this partial only has to render, never wire
         up its own JS per swap. --}}
    <div class="px-5 pt-3 flex items-center justify-between flex-wrap gap-2">
        <div class="flex items-center gap-4 text-xs text-surface-500">
            <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-primary-400 inline-block"></span>Uploaded</span>
            <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-approved-500 inline-block"></span>Approved</span>
            {{-- Amber — not an arbitrary 4th chart color, this is the app's
                 existing convention for "auto-approved" everywhere else it
                 appears (see components/status-badge.blade.php's
                 auto_approved case). This chart's own "Now" marker already
                 uses the same color family, but as a dashed vertical
                 reference line with its own label, not a data series, so
                 the two don't read as the same thing. --}}
            <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-processing-500 inline-block"></span>Auto Approved</span>
            <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-rejected-500 inline-block"></span>Rejected</span>
        </div>
        @if($latestPoint)
            <div data-analytics-readout class="text-xs font-medium text-surface-700 tabular-nums">
                <span data-readout-date>{{ $latestPoint['bucket'] }}</span>
                <span class="text-surface-400 font-normal">·</span>
                Uploaded <span class="text-primary-700 font-semibold" data-readout-uploaded>{{ $latestPoint['uploaded'] }}</span>
                <span class="text-surface-400 font-normal">·</span>
                Approved <span class="text-approved-700 font-semibold" data-readout-approved>{{ $latestPoint['approved'] }}</span>
                <span class="text-surface-400 font-normal">·</span>
                Auto Approved <span class="text-processing-700 font-semibold" data-readout-autoapproved>{{ $latestPoint['autoApproved'] }}</span>
                <span class="text-surface-400 font-normal">·</span>
                Rejected <span class="text-rejected-700 font-semibold" data-readout-rejected>{{ $latestPoint['rejected'] }}</span>
            </div>
        @endif
    </div>
    <div class="px-5 pt-2">
        @if(count($chartRows) > 0)
            {{-- The crosshair + its 3 markers default to invisible (pure CSS,
                 via the :hover rule below) instead of resting at the latest
                 (rightmost) period — a marker parked on one edge by default
                 read as the chart being "stuck" there. They only appear
                 while the cursor is actually over the chart. Position
                 changes (cx/cy/x1/x2, set by JS on mousemove — see
                 dashboard.blade.php) transition smoothly instead of
                 teleporting between the fixed period positions, which is
                 what made snapping between dates feel laggy/abrupt before. --}}
            <style>
                .analytics-crosshair, .analytics-hover-dot-uploaded, .analytics-hover-dot-approved, .analytics-hover-dot-autoapproved, .analytics-hover-dot-rejected {
                    opacity: 0;
                    transition: cx 100ms ease-out, cy 100ms ease-out, x1 100ms ease-out, x2 100ms ease-out, opacity 120ms ease-out;
                }
                .analytics-chart-svg:hover .analytics-crosshair,
                .analytics-chart-svg:hover .analytics-hover-dot-uploaded,
                .analytics-chart-svg:hover .analytics-hover-dot-approved,
                .analytics-chart-svg:hover .analytics-hover-dot-autoapproved,
                .analytics-chart-svg:hover .analytics-hover-dot-rejected {
                    opacity: 1;
                }
            </style>
            <svg class="analytics-chart-svg block w-full cursor-crosshair" viewBox="0 0 {{ $VBW }} {{ $VBH }}" preserveAspectRatio="none" width="100%" height="135" data-points="{{ json_encode($jsPoints) }}">
                <defs>
                    <linearGradient id="analyticsUploadedFill" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="#5a8cc2" stop-opacity="0.28" />
                        <stop offset="100%" stop-color="#5a8cc2" stop-opacity="0" />
                    </linearGradient>
                </defs>

                @for ($g = 0; $g <= 3; $g++)
                    @php $gy = $TOP_PAD + $chartH - ($g * $chartH / 3); @endphp
                    <line x1="{{ $AXIS_W }}" y1="{{ $gy }}" x2="{{ $VBW }}" y2="{{ $gy }}" class="stroke-surface-100" stroke-width="1" vector-effect="non-scaling-stroke" />
                    <text x="{{ $AXIS_W - 6 }}" y="{{ $gy + 3 }}" text-anchor="end" class="fill-surface-400" style="font-size: 9px;">{{ (int) round($chartMax * $g / 3) }}</text>
                @endfor

                {{-- Uploaded gets the shaded area (the "total volume" series, matching the reference look); Approved/Auto Approved/Rejected stay clean lines so overlapping fills don't turn into visual mud. --}}
                <path d="{{ $areaPath($uploadedPoints) }}" fill="url(#analyticsUploadedFill)" stroke="none" />

                <path d="{{ $smoothPath($rejectedPoints) }}" fill="none" class="stroke-rejected-500" stroke-width="2" vector-effect="non-scaling-stroke" stroke-linejoin="round" stroke-linecap="round" />
                <path d="{{ $smoothPath($autoApprovedPoints) }}" fill="none" class="stroke-processing-500" stroke-width="2" vector-effect="non-scaling-stroke" stroke-linejoin="round" stroke-linecap="round" />
                <path d="{{ $smoothPath($approvedPoints) }}" fill="none" class="stroke-approved-500" stroke-width="2" vector-effect="non-scaling-stroke" stroke-linejoin="round" stroke-linecap="round" />
                <path d="{{ $smoothPath($uploadedPoints) }}" fill="none" class="stroke-primary-400" stroke-width="2.5" vector-effect="non-scaling-stroke" stroke-linejoin="round" stroke-linecap="round" />

                {{-- "Now" marker — where the current moment sits on today's
                     hourly timeline (Day tab only, and only when the
                     selected date is actually today). Solid, distinct
                     amber/processing color (not the gray hover crosshair,
                     not any of the 3 series colors) so it reads as a fixed
                     reference line, not part of the data.

                     data-plot-start/-step-x let the shared ticker in
                     layouts/app.blade.php (__docTrackUpdateAnalyticsNowLine)
                     recompute this marker's position and label from the
                     live clock every second, so it keeps walking forward
                     for as long as the tab stays open instead of freezing
                     at whatever moment the panel last rendered. data-step-x
                     is the EXACT same per-hour spacing the 24 chart points
                     above use ($stepX) — not a separately-derived width
                     fraction — so the line can only ever land exactly on
                     or between the real point positions, never drift from
                     them. The server-rendered x/label below are just the
                     correct starting position for the very first paint. --}}
                @if($nowLineX !== null)
                    <g class="analytics-now-marker" data-plot-start="{{ $AXIS_W }}" data-step-x="{{ $stepX }}">
                        <line class="analytics-now-line stroke-processing-500 pointer-events-none" x1="{{ $nowLineX }}" y1="{{ $TOP_PAD }}" x2="{{ $nowLineX }}" y2="{{ $baselineY }}" stroke-width="1.5" stroke-dasharray="4,3" vector-effect="non-scaling-stroke" />
                        <text class="analytics-now-label fill-processing-600 font-semibold pointer-events-none" x="{{ $nowLineX }}" y="{{ $TOP_PAD - 2 }}" text-anchor="middle" style="font-size: 9px;">{{ $nowLabel }}</text>
                    </g>
                @endif

                {{-- Crosshair + one hover marker per series — position kept in sync by JS on every mousemove; visibility is pure CSS (see the <style> above), never toggled in JS. pointer-events-none so hovering the marker itself can't interfere with the svg-level mousemove target. --}}
                <line class="analytics-crosshair stroke-surface-300 pointer-events-none" x1="{{ $latestPoint['x'] ?? 0 }}" y1="{{ $TOP_PAD }}" x2="{{ $latestPoint['x'] ?? 0 }}" y2="{{ $baselineY }}" stroke-width="1" stroke-dasharray="3,3" vector-effect="non-scaling-stroke" />
                <circle class="analytics-hover-dot-rejected fill-white stroke-rejected-500 pointer-events-none" cx="{{ $latestPoint['x'] ?? 0 }}" cy="{{ $latestPoint['rejectedY'] ?? $baselineY }}" r="4" stroke-width="2" vector-effect="non-scaling-stroke" />
                <circle class="analytics-hover-dot-autoapproved fill-white stroke-processing-500 pointer-events-none" cx="{{ $latestPoint['x'] ?? 0 }}" cy="{{ $latestPoint['autoApprovedY'] ?? $baselineY }}" r="4" stroke-width="2" vector-effect="non-scaling-stroke" />
                <circle class="analytics-hover-dot-approved fill-white stroke-approved-500 pointer-events-none" cx="{{ $latestPoint['x'] ?? 0 }}" cy="{{ $latestPoint['approvedY'] ?? $baselineY }}" r="4" stroke-width="2" vector-effect="non-scaling-stroke" />
                <circle class="analytics-hover-dot-uploaded fill-white stroke-primary-400 pointer-events-none" cx="{{ $latestPoint['x'] ?? 0 }}" cy="{{ $latestPoint['uploadedY'] ?? $baselineY }}" r="4.5" stroke-width="2.5" vector-effect="non-scaling-stroke" />
            </svg>
        @else
            <p class="text-xs text-surface-400 py-6 text-center">No data yet for this range.</p>
        @endif
    </div>
</div>
