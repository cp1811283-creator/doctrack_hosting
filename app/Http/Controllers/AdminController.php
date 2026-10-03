<?php

namespace App\Http\Controllers;

use App\Events\AccountDeactivated;
use App\Events\DocumentStatusChanged;
use App\Events\SystemSettingsChanged;
use App\Models\AdminViolation;
use App\Models\AuditLog;
use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\MlModelRepository;
use App\Models\MlStagingSample;
use App\Models\MlTimeEstimateModel;
use App\Models\NotificationRecord;
use App\Models\SlaHoliday;
use App\Models\SlaViolation;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\ApprovalTimeMlService;
use App\Services\ClassificationService;
use App\Services\DocumentMovementTimeline;
use App\Services\PerformanceInsightsService;
use App\Services\SlaService;
use App\Services\TextExtractionService;
use App\Services\ValidationService;
use App\Services\WorkflowService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AdminController extends Controller
{
    public function __construct(
        private ClassificationService $classifier,
        private TextExtractionService $extractor,
        private SlaService $sla,
        private WorkflowService $workflow,
        private ValidationService $validator,
        private PerformanceInsightsService $performance,
        private ApprovalTimeMlService $timeMl,
    ) {}

    /**
     * The KPI stats + SLA alert list — shared by dashboard() (full page),
     * refresh() (the AJAX fragment the live-poll JS swaps in), and poll()
     * (which reuses the same cheap COUNT queries as its "did anything
     * change" signal, since they're already inexpensive).
     */
    private function overviewStats(): array
    {
        return [
            'total_documents' => DocumentRepository::count(),
            'pending' => DocumentRepository::where(function ($q) {
                $q->whereIn('global_status', ['processing', 'classified_validated'])
                    ->orWhere(fn ($q2) => $this->awaitingAdminReview($q2));
            })->count(),
            'approved' => DocumentRepository::where(function ($q) {
                $q->where('global_status', 'approved')
                    ->orWhere(function ($q2) {
                        $q2->where('global_status', 'auto_approved')->whereNull('disputed_at')
                            ->whereDoesntHave('assignments', fn ($a) => $a->awaitingAdminReview());
                    });
            })->count(),
            'rejected' => DocumentRepository::where('global_status', 'rejected')->count(),
            'active_users' => User::count(),
            'violations_count' => SlaViolation::count(),
        ];
    }

    /**
     * An auto-approved document that still owes someone's attention, so
     * the Control Center counts it as "In Progress", not "Approved," even
     * though there's no approver left waiting on it:
     *   - at least one auto-approved stage hasn't been reviewed yet, OR
     *   - it WAS reviewed, but disputed — confirming isn't the only real
     *     review outcome; a dispute means the Admin flagged a problem and
     *     the originator still owes a resubmission, so it's no more
     *     "done" than an unreviewed one (see AdminController::
     *     reviewAutoApproval(), which sets admin_reviewed_at either way —
     *     the outcome, not just whether a review happened, is what
     *     decides this).
     * Shared by overviewStats() and dashboardDrilldown() so the KPI count
     * and its click-through list can never disagree about which
     * documents belong in which bucket.
     */
    private function awaitingAdminReview($query)
    {
        return $query->where('global_status', 'auto_approved')
            ->where(function ($q) {
                $q->whereNotNull('disputed_at')
                    ->orWhereHas('assignments', fn ($a) => $a->awaitingAdminReview());
            });
    }

    /**
     * Dashboard preview list — real document names, not just counts.
     * Mirrors slaQueueData()'s own $reviewContainers query, but capped to
     * a top-5 preview instead of the full paginated list.
     */
    private function overviewData(): array
    {
        $stats = $this->overviewStats();

        // Grouped by document — a document can have more than one
        // auto-approved stage awaiting review at once (e.g. Budget Check
        // and Final Approval both firing), same reasoning as
        // slaQueueData()'s $reviewContainers.
        $autoApprovalAlerts = DocumentAssignment::awaitingAdminReview()
            ->with('document')
            ->get()
            ->groupBy('document_id')
            ->map(fn ($stageAssignments) => (object) [
                'document' => $stageAssignments->first()->document,
                'stage_count' => $stageAssignments->count(),
                'acted_at' => $stageAssignments->min('acted_at'),
            ])
            ->sortBy('acted_at')
            ->take(5)
            ->values();

        $reviewCount = DocumentAssignment::awaitingAdminReview()->count();

        return [$stats, $autoApprovalAlerts, $reviewCount];
    }

    /**
     * Heavier "module overview" data — analytics summary and a
     * recent-activity feed — split out from overviewData() so
     * overviewPoll() (fired every ~45-75s purely to detect change) stays
     * cheap; only the full-page dashboard() and its live-swap counterpart
     * overviewRefresh() need this.
     *
     * Deliberately does NOT include the analytics chart/KPI/table data —
     * that's a separately interactive sub-widget (one reusable panel, its
     * content swapped via analyticsPanelRefresh() when the admin changes
     * the Day/Week/Month/Year tab or the date filter — see
     * admin/partials/analytics-panel.blade.php). Bundling it in here would
     * mean this page's periodic live-refresh silently resets whatever
     * granularity/date the admin currently has selected back to the
     * default every ~45-75s.
     */
    private function dashboardExtras(): array
    {
        $recentActivity = $this->recentActivityRows();

        $analytics = $this->analyticsSummary();

        return [$recentActivity, $analytics];
    }

    /**
     * Recent Activity (Feature: match the Audit Trail's own row shape/
     * styling exactly — same $row->kind ('document'/'system') shape
     * buildAuditRows() produces, rendered through the same shared
     * admin.partials.audit-row partial) — bounded to the last $limit
     * DOCUMENT uploads and $limit SYSTEM log entries before merging/
     * sorting/trimming, unlike buildAuditRows() itself, which deliberately
     * pulls every document/log unfiltered since it expects to paginate a
     * full page. That's too heavy to run on every dashboard load/poll —
     * this stays cheap by bounding each side of the union before the merge.
     */
    private function recentActivityRows(int $limit = 5): Collection
    {
        $documentRows = DocumentRepository::with('originator')
            ->orderByDesc('upload_date')
            ->limit($limit)
            ->get()
            ->map(fn (DocumentRepository $doc) => (object) [
                'kind' => 'document',
                'sort_at' => $doc->upload_date,
                'document' => $doc,
            ]);

        $systemRows = AuditLog::with('user')
            ->whereNull('document_id')
            ->orderByDesc('timestamp')
            ->limit($limit)
            ->get()
            ->map(fn (AuditLog $log) => (object) [
                'kind' => 'system',
                'sort_at' => $log->timestamp,
                'log' => $log,
            ]);

        return $documentRows->concat($systemRows)->sortByDesc('sort_at')->take($limit)->values();
    }

    /**
     * The parts of the Analytics card that are NOT the interactive
     * chart panel: peak upload day/hour, category volume, and the current
     * backlog — all all-time/live snapshots, deliberately not scoped to
     * whatever Day/Week/Month/Year tab or date filter the admin currently
     * has the chart panel set to (see analyticsPanelData() below), since
     * "which categories are busiest overall" and "how much is in flight
     * right now" are more useful as a constant reference point than
     * something that resets depending on the chart's current filter.
     */
    private function analyticsSummary(): array
    {
        $uploadDates = DocumentRepository::pluck('upload_date');
        $peakDay = $uploadDates->countBy(fn ($d) => $d->format('l'))->sortDesc()->keys()->first();
        $peakHour = $uploadDates->countBy(fn ($d) => (int) $d->format('G'))->sortDesc()->keys()->first();

        $categoryVolume = DocumentRepository::whereNotNull('ml_category')
            ->selectRaw('ml_category, count(*) as cnt')
            ->groupBy('ml_category')
            ->orderByDesc('cnt')
            ->pluck('cnt', 'ml_category');

        $backlogCount = DocumentRepository::whereIn('global_status', ['processing', 'classified_validated'])->count();

        return [
            'peak_day' => $peakDay,
            'peak_hour' => $peakHour !== null ? sprintf('%02d:00–%02d:00', $peakHour, ($peakHour + 1) % 24) : null,
            'category_volume' => $categoryVolume,
            'backlog_count' => $backlogCount,
        ];
    }

    /**
     * Config per granularity: the Carbon unit to step by, the bucket-key
     * format, how many periods the rolling window covers, the label used
     * for the detail table's period column, and the label used for the
     * KPI tiles' "vs previous ___" trend tooltip.
     *
     * 'day' steps by HOUR across a single calendar day (00:00-23:59 of
     * whichever date is selected), not a multi-day rolling window like
     * the other three — a 14-day window meant most of the chart sat empty
     * with all the real activity crammed against the right edge (today).
     * A single day's hourly timeline doesn't have that skew: whatever
     * hours had activity are spread across the full width on their own
     * terms. label is "Hour" (each row IS an hour) but trend_label stays
     * "day" (see analyticsPanelData() — the KPI tiles trend today's
     * totals against yesterday's, not one hour against the last).
     */
    private const ANALYTICS_GRANULARITIES = [
        'day' => ['unit' => 'hour', 'format' => 'H:00', 'count' => 24, 'label' => 'Hour', 'trend_label' => 'day'],
        'week' => ['unit' => 'week', 'format' => 'o-\WW', 'count' => 12, 'label' => 'Week', 'trend_label' => 'week'],
        'month' => ['unit' => 'month', 'format' => 'Y-m', 'count' => 12, 'label' => 'Month', 'trend_label' => 'month'],
        'year' => ['unit' => 'year', 'format' => 'Y', 'count' => 5, 'label' => 'Year', 'trend_label' => 'year'],
    ];

    /**
     * The ONE reusable Analytics chart panel's data (KPI tiles + chart
     * rows) for a single granularity + reference date — this is the only
     * method that computes chart data; switching the Day/Week/Month/Year
     * tab or applying the date filter both just call this again with
     * different arguments (see analyticsPanelRefresh() and dashboard()
     * below), never a second, parallel computation.
     *
     * $asOf defaults to "now" — the live, un-filtered view — and the
     * window always ENDS at $asOf, not always at "today", so picking a
     * past date re-anchors the whole rolling window to look back from
     * that point instead.
     */
    private function analyticsPanelData(string $granularity, ?Carbon $asOf = null): array
    {
        $granularity = array_key_exists($granularity, self::ANALYTICS_GRANULARITIES) ? $granularity : 'day';
        $cfg = self::ANALYTICS_GRANULARITIES[$granularity];
        $asOf = ($asOf ?? now())->copy();

        $until = match ($cfg['unit']) {
            'hour' => $asOf->copy()->endOfDay(),
            'week' => $asOf->copy()->endOfWeek(),
            'month' => $asOf->copy()->endOfMonth(),
            'year' => $asOf->copy()->endOfYear(),
        };
        $since = match ($cfg['unit']) {
            'hour' => $asOf->copy()->startOfDay(),
            'week' => $asOf->copy()->subWeeks($cfg['count'] - 1)->startOfWeek(),
            'month' => $asOf->copy()->subMonths($cfg['count'] - 1)->startOfMonth(),
            'year' => $asOf->copy()->subYears($cfg['count'] - 1)->startOfYear(),
        };

        $chartRows = $this->analyticsBuckets($cfg['unit'], $cfg['format'], $since, $until);

        // Every granularity's KPI tiles summarize the WHOLE visible window
        // (all $cfg['count'] periods — e.g. the full trailing 12 weeks),
        // trended against an equal-length window immediately before it —
        // not just the single most-recent bucket trended against the one
        // before that. A single bucket can easily be entirely empty (e.g.
        // checking the dashboard right as a new week or month starts,
        // before anything has happened in it yet) even though the window
        // just before it was full of real activity, which would otherwise
        // show "—" on every tile despite plenty of recent data existing.
        // This generalizes what used to be Day-tab-only special-casing
        // (the comment this replaced explained it for "hour" alone) to
        // every tab, on the same reasoning.
        $current = $this->analyticsAggregateRow($chartRows);

        $previousSince = match ($cfg['unit']) {
            'hour' => $since->copy()->subDay(),
            'week' => $since->copy()->subWeeks($cfg['count']),
            'month' => $since->copy()->subMonths($cfg['count']),
            'year' => $since->copy()->subYears($cfg['count']),
        };
        $previousUntil = match ($cfg['unit']) {
            'hour' => $until->copy()->subDay(),
            'week' => $until->copy()->subWeeks($cfg['count']),
            'month' => $until->copy()->subMonths($cfg['count']),
            'year' => $until->copy()->subYears($cfg['count']),
        };
        $previous = $this->analyticsAggregateRow($this->analyticsBuckets($cfg['unit'], $cfg['format'], $previousSince, $previousUntil));

        if ($cfg['unit'] === 'hour') {
            // The raw "H:00" grouping key doesn't carry the actual calendar
            // date and reads in 24-hour time — confusing on its own once
            // you're looking at a specific chosen date rather than "today"
            // by default. The chart hover, readout, and detail table all
            // display $row->bucket directly, so rewriting it once here
            // (into e.g. "Aug 8, 2026, 11:00 PM") fixes all three at once.
            foreach ($chartRows as $row) {
                $hour = (int) explode(':', $row->bucket)[0];
                $row->bucket = $asOf->copy()->startOfDay()->addHours($hour)->format('M j, Y, g:i A');
            }
        } elseif ($cfg['unit'] === 'week') {
            // Same reasoning as the hour rewrite above, for the same
            // reason: the raw ISO grouping key ("2026-W37") is stable and
            // sortable, which is all it needs to be for grouping, but
            // it's not something anyone reads at a glance — nobody knows
            // offhand which calendar days "week 37" covers. Rewritten
            // into a real date span ("Sep 8–14, 2026") once here, same as
            // the hour rewrite, so the chart hover/readout/detail table
            // all pick it up without each needing their own conversion.
            foreach ($chartRows as $row) {
                [$isoYear, $isoWeek] = array_map('intval', explode('-W', $row->bucket));
                $weekStart = Carbon::now()->setISODate($isoYear, $isoWeek)->startOfWeek(Carbon::MONDAY);
                $weekEnd = $weekStart->copy()->addDays(6);
                $row->bucket = $weekStart->isSameMonth($weekEnd)
                    ? $weekStart->format('M j').'–'.$weekEnd->format('j, Y')
                    : $weekStart->format('M j').'–'.$weekEnd->format('M j, Y');
            }
        }

        return [
            'granularity' => $granularity,
            'label' => $cfg['label'],
            'trend_label' => $cfg['trend_label'],
            'as_of' => $asOf->toDateString(),
            'chart_rows' => $chartRows,
            'kpi' => $this->analyticsKpis($current, $previous),
        ];
    }

    /**
     * Sums a list of bucket rows (see analyticsBuckets()) into one
     * combined row of the same shape — used to roll the Day tab's 24
     * hourly buckets up into "today" (and, for the trend comparison,
     * "yesterday"). avg_minutes is weighted by each bucket's own decided
     * count rather than a flat average of per-hour averages, so an hour
     * with one decision doesn't count as much as an hour with ten.
     */
    private function analyticsAggregateRow(array $rows): object
    {
        // Weighted by each bucket's own HUMAN-decided count (approved -
        // auto_approved, + rejected) — matches avg_minutes itself now only
        // measuring human decisions (see analyticsBuckets()); weighting by
        // the old combined approved+rejected count would average against a
        // larger population than avg_minutes was actually computed from.
        $humanDecidedCount = fn ($r) => ($r->approved - $r->auto_approved) + $r->rejected;
        $decidedRows = array_filter($rows, fn ($r) => $r->avg_minutes !== null);
        $totalDecided = array_sum(array_map($humanDecidedCount, $decidedRows));

        return (object) [
            'bucket' => null,
            'uploaded' => array_sum(array_map(fn ($r) => $r->uploaded, $rows)),
            'approved' => array_sum(array_map(fn ($r) => $r->approved, $rows)),
            'rejected' => array_sum(array_map(fn ($r) => $r->rejected, $rows)),
            'auto_approved' => array_sum(array_map(fn ($r) => $r->auto_approved, $rows)),
            'avg_minutes' => $totalDecided > 0
                ? (int) round(array_sum(array_map(fn ($r) => $r->avg_minutes * $humanDecidedCount($r), $decidedRows)) / $totalDecided)
                : null,
            'violations' => array_sum(array_map(fn ($r) => $r->violations, $rows)),
            // Safe to sum across buckets — a document is decided exactly
            // once, so it's counted in exactly one bucket's
            // violated_documents, never double-counted across the sum.
            'violated_documents' => array_sum(array_map(fn ($r) => $r->violated_documents, $rows)),
        ];
    }

    /**
     * KPI tiles for one "current" bucket (or aggregate — see
     * analyticsAggregateRow()) plus a % trend against the "previous" one.
     * Rates (approval/auto-approval/SLA-violation) are computed against
     * decisions actually made in that period, not uploads, since a
     * document uploaded in one period can easily be decided in a later
     * one — approved/rejected counts are the meaningful denominator for
     * "how did decisions go this period," uploaded is a separate,
     * unrelated volume metric shown alongside it.
     */
    private function analyticsKpis($currentRow, $previousRow): array
    {
        $rate = fn (?int $num, int $den) => $den > 0 ? round($num / $den * 100, 1) : null;

        $summarize = function ($row) use ($rate) {
            if (! $row) {
                return null;
            }
            $decidedTotal = $row->approved + $row->rejected;
            // $row->approved is the COMBINED count (human + auto — see
            // analyticsBuckets()'s whereIn(['approved','auto_approved'])) —
            // kept that way since it's reused elsewhere (the chart line,
            // the summary text) for total approved volume. Subtracting
            // auto_approved here gives human-only approvals for the rate
            // below, without touching that shared combined count.
            $humanApproved = $row->approved - $row->auto_approved;

            return [
                'uploaded' => $row->uploaded,
                // Human-decided approvals only — previously included
                // auto-approved too, which double-counted against
                // auto_approval_rate below and made the two tiles look
                // like they should sum to something they didn't.
                'approval_rate' => $rate($humanApproved, $decidedTotal),
                'auto_approval_rate' => $rate($row->auto_approved, $decidedTotal),
                // Completes the three-way split of every decided document
                // (human-approved / auto-approved / rejected) — together
                // with the two rates above, these three now sum to 100%.
                'rejection_rate' => $rate($row->rejected, $decidedTotal),
                'avg_minutes' => $row->avg_minutes,
                // violated_documents (not the raw 'violations' event
                // count) — it's a subset of decidedTotal by construction
                // (see analyticsBuckets()), so this can never exceed 100%,
                // unlike dividing by a raw event count that can outnumber
                // the documents it happened on.
                'sla_violation_rate' => $rate($row->violated_documents, $decidedTotal),
            ];
        };

        $current = $summarize($currentRow);
        $previous = $summarize($previousRow);

        $trendOf = function (string $metric) use ($current, $previous) {
            if (! $current || ! $previous || $current[$metric] === null || $previous[$metric] === null || $previous[$metric] == 0) {
                return null;
            }

            return round((($current[$metric] - $previous[$metric]) / $previous[$metric]) * 100, 1);
        };

        return [
            'current' => $current,
            'trend' => $current ? [
                'uploaded' => $trendOf('uploaded'),
                'approval_rate' => $trendOf('approval_rate'),
                'auto_approval_rate' => $trendOf('auto_approval_rate'),
                'rejection_rate' => $trendOf('rejection_rate'),
                'avg_minutes' => $trendOf('avg_minutes'),
                'sla_violation_rate' => $trendOf('sla_violation_rate'),
            ] : null,
        ];
    }

    /**
     * One row per period bucket between $since and $until INCLUSIVE, one
     * row per period even when nothing happened that period (zero-filled)
     * — a continuous timeline is what makes the line chart actually read
     * as a trend; skipping empty periods would make it jump between
     * non-adjacent points as if they were consecutive. $unit is a Carbon
     * add*()-compatible unit name ('hour'/'week'/'month'/'year') used to
     * step from $since to $until.
     */
    private function analyticsBuckets(string $unit, string $carbonFormat, Carbon $since, Carbon $until): array
    {
        $uploadBuckets = DocumentRepository::whereBetween('upload_date', [$since, $until])
            ->pluck('upload_date')
            ->groupBy(fn ($d) => $d->format($carbonFormat));

        // A disputed auto-approval is excluded here the same way the
        // Control Center's own "Approved" KPI already excludes it (see
        // overviewStats()'s 'approved' => ...whereNull('disputed_at')) —
        // it isn't actually settled, an Admin has flagged it. Without this,
        // a dispute (which just sets disputed_at and saves the document —
        // see AdminController.php's dispute handler) would bump updated_at
        // to the DISPUTE moment and silently count the document as a
        // legitimate decided auto-approval in whatever period the dispute
        // happened in, not the period it was actually auto-approved in.
        $decidedBuckets = DocumentRepository::whereIn('global_status', ['approved', 'rejected', 'auto_approved'])
            ->where(function ($q) {
                $q->where('global_status', '!=', 'auto_approved')->orWhereNull('disputed_at');
            })
            ->whereBetween('updated_at', [$since, $until])
            ->get(['document_id', 'upload_date', 'updated_at', 'global_status'])
            ->groupBy(fn ($d) => $d->updated_at->format($carbonFormat));

        $violationBuckets = SlaViolation::whereBetween('violation_timestamp', [$since, $until])
            ->pluck('violation_timestamp')
            ->groupBy(fn ($v) => $v->format($carbonFormat));

        // Which of the documents decided in this whole window have EVER had
        // an SLA violation logged against them — deliberately not scoped to
        // violation_timestamp falling in the same window, since a
        // violation can predate its document's eventual decision by any
        // amount. Fetched once for the whole range (not per bucket) to
        // avoid an N+1 query per period; used below for
        // 'violated_documents', a document-count metric distinct from
        // 'violations' (a raw event count — one document can rack up more
        // than one violation now that a stage can have several approvers
        // in parallel, each independently escalating).
        $decidedDocIds = $decidedBuckets->flatten()->pluck('document_id');
        $violatedDocIds = SlaViolation::whereIn('document_id', $decidedDocIds)
            ->pluck('document_id')->unique()->flip();

        $bucketKeys = [];
        $cursor = $since->copy();
        while ($cursor->lte($until)) {
            $bucketKeys[] = $cursor->format($carbonFormat);
            $cursor = match ($unit) {
                'hour' => $cursor->addHour(),
                'week' => $cursor->addWeek(),
                'month' => $cursor->addMonth(),
                'year' => $cursor->addYear(),
            };
        }

        return collect($bucketKeys)->map(function ($bucket) use ($uploadBuckets, $decidedBuckets, $violationBuckets, $violatedDocIds) {
            $decided = $decidedBuckets->get($bucket, collect());
            $humanDecided = $decided->where('global_status', '!=', 'auto_approved');

            return (object) [
                'bucket' => $bucket,
                'uploaded' => $uploadBuckets->get($bucket, collect())->count(),
                // Combined (human + auto) — kept for anything that still
                // wants total approved volume. The chart's own "Approved"
                // line uses 'human_approved' below instead, so it doesn't
                // double-count against the separate "Auto Approved" line.
                'approved' => $decided->whereIn('global_status', ['approved', 'auto_approved'])->count(),
                'human_approved' => $decided->where('global_status', 'approved')->count(),
                'rejected' => $decided->where('global_status', 'rejected')->count(),
                'auto_approved' => $decided->where('global_status', 'auto_approved')->count(),
                // Human decisions only (approved OR rejected by an actual
                // person) — an auto-approved document's upload-to-decision
                // gap is either near-instant (no eligible approver) or the
                // full SLA window (a missed deadline), neither of which is
                // a real decision time; averaging it in with genuine human
                // review times produced a number that honestly answered
                // neither question. Same "subtract auto_approved out"
                // discipline analyticsKpis() already applies to the
                // Approval Rate tile, for the identical reason.
                'avg_minutes' => $humanDecided->isNotEmpty()
                    ? (int) round($humanDecided->avg(fn ($d) => $d->upload_date->diffInMinutes($d->updated_at)))
                    : null,
                // Raw violation-event count in this period — a distinct,
                // still-correct metric on its own (shown in the detail
                // table), NOT the basis for the SLA Violation Rate KPI
                // (see 'violated_documents' below and analyticsKpis()).
                'violations' => $violationBuckets->get($bucket, collect())->count(),
                // How many of THIS bucket's decided documents have ever had
                // a violation logged — a subset of $decided, so dividing
                // this by the decided count can never exceed 100%, unlike
                // the raw event count above.
                'violated_documents' => $decided->pluck('document_id')->unique()
                    ->filter(fn ($id) => $violatedDocIds->has($id))->count(),
            ];
        })->all();
    }

    /** Admin control-center overview. */
    /**
     * Reads the analytics panel's requested granularity/as-of date off the
     * request — shared by dashboard() (so a bookmarked/shared URL with
     * ?granularity=&as_of= renders that exact view on first load, not
     * always the default) and analyticsPanelRefresh() (the AJAX swap).
     * Invalid/missing granularity falls back to 'day'; invalid/missing
     * as_of falls back to null (analyticsPanelData() then defaults to now()).
     */
    private function analyticsPanelRequestArgs(Request $request): array
    {
        $granularity = $request->string('granularity')->toString();
        $granularity = array_key_exists($granularity, self::ANALYTICS_GRANULARITIES) ? $granularity : 'day';

        $asOf = null;
        if ($request->filled('as_of')) {
            try {
                $asOf = Carbon::parse($request->string('as_of')->toString());
            } catch (\Exception) {
                $asOf = null;
            }
        }

        return [$granularity, $asOf];
    }

    public function dashboard(Request $request)
    {
        [$stats, $autoApprovalAlerts, $reviewCount] = $this->overviewData();
        [$recentActivity, $analytics] = $this->dashboardExtras();
        $activeModel = MlModelRepository::active();
        $modelHistory = $this->modelHistory();

        [$granularity, $asOf] = $this->analyticsPanelRequestArgs($request);
        $panel = $this->analyticsPanelData($granularity, $asOf);

        return view('admin.dashboard', compact(
            'stats', 'autoApprovalAlerts', 'reviewCount', 'activeModel', 'modelHistory',
            'recentActivity', 'analytics', 'panel'
        ));
    }

    /**
     * The Active ML Model card's version history — the last few trained
     * versions (including the currently active one), newest first, so an
     * admin can see at a glance whether accuracy has been trending up or
     * down across retrains instead of only ever seeing the single active
     * snapshot. Same query shape already used by the ML Training page
     * (see mlTrainingData()'s $history) — reused here rather than
     * duplicated, just capped tighter since this is a sidebar card, not a
     * dedicated page.
     */
    private function modelHistory(int $limit = 4): Collection
    {
        return MlModelRepository::orderByDesc('last_trained')->limit($limit)->get();
    }

    /**
     * The ONE reusable Analytics chart panel's fragment — fetched via AJAX
     * whenever the admin changes the Day/Week/Month/Year tab or applies
     * the date filter, swapping in place instead of a page reload (see
     * the script in admin/partials/overview.blade.php). Same data method
     * as the initial page load (analyticsPanelData()), just returning the
     * panel fragment instead of the whole dashboard.
     */
    public function analyticsPanelRefresh(Request $request)
    {
        [$granularity, $asOf] = $this->analyticsPanelRequestArgs($request);
        $panel = $this->analyticsPanelData($granularity, $asOf);

        return view('admin.partials.analytics-panel', compact('panel'));
    }

    /**
     * Fragment listing the documents/users behind a clicked KPI card
     * (Feature: clickable dashboard cards) — reuses the exact same
     * global_status groupings as overviewData()'s stats, so the list
     * shown always matches what the card's own number counted.
     */
    public function dashboardDrilldown(string $type)
    {
        $labels = [
            'total' => 'All Documents',
            'pending' => 'In Progress',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            'users' => 'All Users',
            'ml_model' => 'Active ML Model',
        ];
        abort_unless(array_key_exists($type, $labels), 404);

        if ($type === 'users') {
            // Sorted in PHP, not SQL — "Available" depends on isAvailable()'s
            // live heartbeat check (is_active && isOnline()), not a plain
            // column, and this list is already capped at 100 rows, so
            // re-sorting the fetched collection is simplest. Role order is
            // fixed (Admin, Originator, Approver — not alphabetical, which
            // would put Approver before Originator); within Originator/
            // Approver, Available accounts surface before Not Available,
            // and a deactivated account (never truly "available" either)
            // sorts last of all. Name is the final tiebreaker.
            $users = User::orderBy('full_name')->limit(100)->get()
                ->sortBy(function (User $user) {
                    $roleRank = match ($user->role) {
                        'admin' => 0,
                        'originator' => 1,
                        'approver' => 2,
                        default => 3,
                    };
                    $statusRank = ! $user->is_active ? 2 : ($user->isAvailable() ? 0 : 1);

                    return sprintf('%d%d%s', $roleRank, $statusRank, $user->full_name);
                })
                ->values();

            return view('admin.partials.dashboard-drilldown-users', ['users' => $users, 'label' => $labels[$type]]);
        }

        // Same data the KPI card's tile itself already carries — moved to
        // its own drilldown (not shown inline on the dashboard anymore, see
        // admin/partials/overview.blade.php) purely to free up space in the
        // KPI row/right column, not because it needed a heavier query.
        if ($type === 'ml_model') {
            $activeModel = MlModelRepository::active();
            $modelHistory = $this->modelHistory();

            return view('admin.partials.dashboard-drilldown-ml-model', compact('activeModel', 'modelHistory'));
        }

        $showDecision = in_array($type, ['approved', 'rejected'], true);

        // 'assignments' is always eager-loaded (not just for $showDecision)
        // because $doc->display_status now needs it too, for every type
        // that could include an auto-approved document — an un-eager-loaded
        // access here would silently N+1 across the whole list.
        $query = DocumentRepository::with('originator')->orderByDesc('upload_date');
        $query->with($showDecision ? ['assignments.approver', 'assignments.adminOverrideBy', 'assignments.stage'] : ['assignments']);
        match ($type) {
            // Same bucketing as overviewStats() — an auto-approved document
            // still awaiting Admin review belongs in "In Progress," not
            // "Approved," so this drilldown's list matches the KPI count
            // it was clicked from.
            'pending' => $query->where(function ($q) {
                $q->whereIn('global_status', ['processing', 'classified_validated'])
                    ->orWhere(fn ($q2) => $this->awaitingAdminReview($q2));
            }),
            'approved' => $query->where(function ($q) {
                $q->where('global_status', 'approved')
                    ->orWhere(function ($q2) {
                        $q2->where('global_status', 'auto_approved')->whereNull('disputed_at')
                            ->whereDoesntHave('assignments', fn ($a) => $a->awaitingAdminReview());
                    });
            }),
            'rejected' => $query->where('global_status', 'rejected'),
            default => null, // 'total' — no filter
        };

        $total = $query->count();
        $documents = $query->limit(50)->get();

        $decisions = $showDecision
            ? $documents->mapWithKeys(fn (DocumentRepository $doc) => [$doc->document_id => $this->resolveDecision($doc)])
            : null;

        return view('admin.partials.dashboard-drilldown-documents', [
            'documents' => $documents, 'total' => $total, 'label' => $labels[$type], 'decisions' => $decisions,
        ]);
    }

    /**
     * Fragment listing every document uploaded on one calendar date
     * (Feature: click a date on the Admin Calendar, see what came in that
     * day) — reuses the same drill-down modal and document-list fragment
     * as dashboardDrilldown() above, just filtered by upload_date instead
     * of global_status. No decision context here — the calendar is about
     * volume/timing, not outcomes.
     */
    public function documentsOnDate(string $date)
    {
        $documents = DocumentRepository::with('originator')
            ->whereDate('upload_date', $date)
            ->orderByDesc('upload_date')
            ->get();

        return view('admin.partials.dashboard-drilldown-documents', [
            'documents' => $documents,
            'total' => $documents->count(),
            'label' => Carbon::parse($date)->format('M j, Y'),
            'decisions' => null,
        ]);
    }

    /**
     * Who actually decided a document's fate, and when — used by the
     * Approved/Rejected dashboard drill-downs. Returns 'by' as a LIST of
     * ['name' => string, 'role' => ?string] — not a single name — because
     * this app's workflow is vote-based, not single-decider:
     *
     * Rejected: a stage rejects once a MAJORITY of its seats vote reject
     * (see DocumentAssignment::stageRejectionStatus()), not on one lone
     * reject — WorkflowService::completeStage() then cascade-closes every
     * OTHER pending seat, stamping cascade_closed_by on them. A seat that
     * genuinely voted reject itself (first mover or the one that tipped
     * the majority) is never touched by that cascade query (it only
     * targets 'pending' seats), so cascade_closed_by IS NULL reliably
     * selects every real voter — and since the whole document terminates
     * the instant majority is reached, every real reject-voter necessarily
     * belongs to that one stage, not scattered across several.
     *
     * Approved: Final Approval is Head-only (see WorkflowService.php's
     * eligibleApproversForStage()) and is the stage that actually
     * finalizes a document — its sign-off is what closes out every other
     * stage's Admin-review requirement (see WorkflowService.php:799-891).
     * So "who approved this" means every Head Approver who resolved THAT
     * stage, not whichever single assignment happened to be acted on last
     * across the document.
     */
    private function resolveDecision(DocumentRepository $doc): array
    {
        if ($doc->is_legacy_import) {
            return ['by' => [['name' => 'Admin (Legacy Import)', 'role' => null]], 'at' => $doc->upload_date];
        }

        if ($doc->global_status === 'rejected') {
            $rejecters = $doc->assignments
                ->where('individual_status', 'rejected')
                ->whereNull('cascade_closed_by');

            if ($rejecters->isEmpty()) {
                return ['by' => [['name' => '—', 'role' => null]], 'at' => null];
            }

            return [
                'by' => $rejecters->map(fn (DocumentAssignment $a) => [
                    'name' => $a->approver->full_name ?? '—',
                    'role' => $a->approver?->displayRole(),
                ])->values()->all(),
                'at' => $rejecters->max('acted_at'),
            ];
        }

        $finalSeats = $doc->assignments
            ->filter(fn (DocumentAssignment $a) => $a->stage?->stage_name === 'Final Approval')
            ->whereIn('individual_status', ['approved', 'auto_approved']);

        // Every document routed through the real workflow gets a Final
        // Approval seat (confirmed 2026-10-01: every configured category's
        // stage list ends in it) — but older/seeded records can be
        // 'approved' without ever having one (verified against this app's
        // own local data: a demo document whose only assignment sits on an
        // earlier stage, yet is globally 'approved'). Falling back to every
        // approved seat on the document, rather than showing nothing,
        // means that data still displays something real instead of
        // silently going blank.
        if ($finalSeats->isEmpty()) {
            $finalSeats = $doc->assignments->whereIn('individual_status', ['approved', 'auto_approved']);
        }

        if ($finalSeats->isEmpty()) {
            return ['by' => [['name' => '—', 'role' => null]], 'at' => null];
        }

        // Admin override / full auto-approval still take priority over
        // listing individual approvers — these are one specific action,
        // not a vote, same special-casing the old single-value logic had.
        $overridden = $finalSeats->first(fn (DocumentAssignment $a) => $a->admin_override_by);
        if ($overridden) {
            return ['by' => [['name' => 'Admin Override', 'role' => null]], 'at' => $overridden->admin_override_at];
        }

        if ($finalSeats->every(fn (DocumentAssignment $a) => $a->auto_approved)) {
            return ['by' => [['name' => 'System Auto-Approval', 'role' => null]], 'at' => $finalSeats->max('acted_at')];
        }

        return [
            'by' => $finalSeats->map(fn (DocumentAssignment $a) => [
                'name' => $a->approver->full_name ?? '—',
                'role' => $a->approver?->displayRole(),
            ])->values()->all(),
            'at' => $finalSeats->max('acted_at'),
        ];
    }

    /**
     * Renders the KPI cards + SLA alerts + Active ML Model fragment
     * (admin/partials/overview.blade.php) for the dashboard's live-poll JS
     * to swap in place — see resources/js/app.js's startLivePoll() and
     * dashboard.blade.php for why this beats a full page reload. The ML
     * Model panel is included here too, even though it rarely changes,
     * purely so the whole 3-column grid row (SLA alerts + ML model side
     * by side) stays one swap target instead of splitting the layout
     * across two independently-swapped pieces.
     */
    public function overviewRefresh()
    {
        [$stats, $autoApprovalAlerts, $reviewCount] = $this->overviewData();
        [$recentActivity, $analytics] = $this->dashboardExtras();
        $activeModel = MlModelRepository::active();
        $modelHistory = $this->modelHistory();

        return view('admin.partials.overview', compact(
            'stats', 'autoApprovalAlerts', 'reviewCount', 'activeModel', 'modelHistory',
            'recentActivity', 'analytics'
        ));
    }

    /**
     * Lightweight JSON endpoint the dashboard's JS polls every ~5-10s.
     * Uses overviewStats() plus its own cheap COUNT queries, deliberately
     * NOT overviewData() — that now does heavier eager-loaded fetches for
     * the preview list, too expensive to repeat on every poll tick.
     */
    public function overviewPoll()
    {
        $stats = $this->overviewStats();
        $reviewCount = DocumentAssignment::awaitingAdminReview()->count();

        return response()->json([
            'stats' => $stats,
            'review_count' => $reviewCount,
            // Fallback-path signal for what AdminActivityLogged covers over
            // the WebSocket — the poll can't "listen" for that event, so it
            // detects the same changes structurally instead: a new audit
            // log row covers logins/uploads/decisions/escalations/etc.
            'latest_log_id' => AuditLog::max('log_id'),
        ]);
    }

    // ---------------------------------------------------------------
    // User account management (Section 3: Account ID <-> workflow role)
    // ---------------------------------------------------------------

    public function users(Request $request)
    {
        $stagesByCategory = WorkflowStage::configured()->where('is_archived', false)->with('departments')->orderBy('sequence_order')->get()->groupBy('document_category');

        return view('admin.users', array_merge(
            compact('stagesByCategory'),
            $this->usersTableData($request)
        ));
    }

    /**
     * Fragment refresh for the account list — same live-channel/poll
     * pattern used elsewhere (see ml_training.blade.php's #ml-review-panels).
     * Verification status doesn't broadcast via DocumentRepository::
     * booted()-style model hooks (there's no document involved at all),
     * so without this an admin watching this page would only see the
     * "Unverified" badge disappear on their next manual reload — see
     * AuthController::verifyEmail() firing UserVerified.
     */
    public function usersRefresh(Request $request)
    {
        return view('admin.partials.users_table', $this->usersTableData($request));
    }

    /** Lightweight JSON signal for the poll fallback — see overviewPoll()'s docblock for the same reasoning. */
    public function usersPoll()
    {
        return response()->json([
            'unverified_ids' => User::whereNull('email_verified_at')->pluck('user_id'),
        ]);
    }

    /** @return array{users: Collection, showInactive: bool, inactiveCount: int} */
    private function usersTableData(Request $request): array
    {
        $showInactive = $request->boolean('show_inactive');

        $query = User::with(['createdBy', 'workflowStages'])->orderBy('role');
        if (! $showInactive) {
            $query->where('is_active', true);
        }

        return [
            // Feature: client-side row fitting — see resources/js/app.js's
            // initFittedPagination() and DocumentController::dashboard()'s
            // matching docblock. Every matching account is sent in one
            // response; the browser measures the whole list and works out
            // every page's real boundary itself, including numbered
            // page-jump targets.
            'users' => $query->get(),
            'showInactive' => $showInactive,
            'inactiveCount' => User::where('is_active', false)->count(),
        ];
    }

    public function storeUser(Request $request)
    {
        $validated = $request->validate([
            'username' => ['required', 'string', 'max:50', 'unique:users,username'],
            'full_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:100', 'unique:users,email'],
            // No 'admin' — the system is locked to exactly one Admin
            // account, so a second can never be created here, even by a
            // crafted request bypassing the form's own dropdown.
            'role' => ['required', 'in:originator,approver'],
            // Not required_if for a head — see WorkflowService::
            // eligibleApproversForStage()'s docblock: a head's Final
            // Approval eligibility is matched by level+department alone
            // now, across every category, so pinning them to one category
            // here would just be a leftover restriction the routing logic
            // no longer even looks at.
            'assigned_category' => [
                'nullable',
                function ($attribute, $value, $fail) use ($request) {
                    if ($request->input('role') === 'approver' && $request->input('level') !== 'head' && blank($value)) {
                        $fail('The assigned category field is required.');
                    }
                },
                'in:'.implode(',', ValidationService::knownCategories()),
            ],
            'department' => [
                'nullable',
                'required_if:role,approver',
                'in:'.implode(',', User::knownDepartments()),
            ],
            'level' => [
                'nullable',
                'required_if:role,approver',
                'in:'.implode(',', User::knownLevels()),
            ],
            'stage_ids' => ['nullable', 'array'],
            'stage_ids.*' => ['integer', 'exists:workflow_stages,stage_id'],
            // mixedCase()+numbers()+uncompromised() — see the matching
            // comment on AuthController::resetPassword()'s identical rule.
            'password' => ['required', 'string', Password::min(8)->mixedCase()->numbers()->uncompromised()],
        ]);

        $isApprover = $validated['role'] === 'approver';
        $isHead = $isApprover && $validated['level'] === 'head';

        $user = User::create([
            'username' => $validated['username'],
            'full_name' => $validated['full_name'],
            'email' => $validated['email'],
            'role' => $validated['role'],
            // Only Approvers are ever scoped to a category/department/level.
            // Admin and Originator accounts always get null here regardless
            // of what was submitted — Originators upload any document type
            // and are classified automatically, so they are never restricted.
            // A head is also always null here, same reasoning as above —
            // whatever the (hidden, for a head) form field happened to
            // submit is ignored, not just unused.
            'assigned_category' => $isApprover && ! $isHead ? $validated['assigned_category'] : null,
            'department' => $isApprover ? $validated['department'] : null,
            'level' => $isApprover ? $validated['level'] : null,
            'password_hash' => Hash::make($validated['password']),
            'created_by' => $request->user()->user_id,
            'is_active' => true,
        ]);

        if ($user->role === 'approver' && ! $isHead && ! empty($validated['stage_ids'])) {
            $validStageIds = $this->stageIdsOwnedByDepartment($user->assigned_category, $user->department, $validated['stage_ids']);
            $user->workflowStages()->sync($validStageIds);
        }

        $accountDetail = match (true) {
            $user->role !== 'approver' => '.',
            $isHead => ", department '{$user->department}' ({$user->level}) — Final Approval across every category.",
            default => ", assigned category '{$user->assigned_category}', department '{$user->department}' ({$user->level}).",
        };

        AuditLog::record($request->user()->user_id, null, 'user_create',
            "Created account #{$user->user_id} ({$user->username}) with role '{$user->role}'{$accountDetail}");

        // Login is blocked until this is clicked (see AuthController::
        // login()) — sent immediately so the account is usable as soon as
        // its owner checks their inbox, not left silently unusable.
        $user->sendEmailVerificationNotification();

        return back()->with('status', "Account '{$user->username}' created. A verification email was sent to {$user->email}.");
    }

    /**
     * Re-sends the verification email — the only way an unverified account
     * gets a second chance at the link, since the account holder can't log
     * in yet to request it themselves (see AuthController::login()).
     */
    public function resendVerification(User $user)
    {
        abort_if($user->hasVerifiedEmail(), 409, 'This account is already verified.');

        $user->sendEmailVerificationNotification();

        return back()->with('status', "Verification email re-sent to {$user->email}.");
    }

    /**
     * Admin-only: view/edit which specific stages an approver is
     * restricted to. Feature: the "Manage Stages" popup — fetched into
     * components/kpi-drilldown-modal.blade.php by the "Manage Stages"
     * button in admin/partials/users_table.blade.php, same mechanism as
     * the Document Tracker and Import Legacy Document popups, rather than
     * its own dedicated page.
     */
    public function editApproverStages(User $user)
    {
        abort_unless($user->role === 'approver', 422, 'Only approver accounts have stage assignments.');

        $stagesByCategory = WorkflowStage::configured()->where('is_archived', false)->with('departments')->orderBy('sequence_order')->get()->groupBy('document_category');
        $assignedStageIds = $user->workflowStages()->pluck('workflow_stages.stage_id')->all();

        // Informational only — reassigning category/stages never touches
        // already-created DocumentAssignment rows (their approver_id and
        // sla_expires_at are fixed at routing time and never re-evaluated),
        // so this doesn't block the change. It just tells the admin what's
        // still sitting in this approver's queue before they decide.
        $pendingInOldCategory = DocumentAssignment::pendingFor($user->user_id)->count();

        return view('admin.partials.manage-stages-form', compact('user', 'stagesByCategory', 'assignedStageIds', 'pendingInOldCategory'));
    }

    /**
     * Updates an approver's category, department, level, and/or which
     * specific stages within them they handle (Feature: Dynamic Workflow
     * Assignment). Changing category OR department always resets stage
     * picks to "every stage the new category/department combination owns"
     * (unrestricted within that) rather than silently carrying over
     * stage_ids that belonged to the old category/department and might not
     * even be legal for the new one. Leaving every checkbox unchecked has
     * the same "unrestricted" effect.
     *
     * Already-created DocumentAssignment rows are untouched by this — see
     * WorkflowService::eligibleApproversForStage(), which only consults
     * assigned_category/department/workflowStages() when routing a NEW
     * document. A pending assignment this approver already holds stays in
     * their queue and can still be decided normally regardless of this
     * change.
     */
    public function updateApproverStages(Request $request, User $user)
    {
        abort_unless($user->role === 'approver', 422, 'Only approver accounts have stage assignments.');

        $validated = $request->validate([
            // Not required for a head — see storeUser()'s matching rule and
            // WorkflowService::eligibleApproversForStage()'s docblock.
            'assigned_category' => [
                'nullable',
                function ($attribute, $value, $fail) use ($request) {
                    if ($request->input('level') !== 'head' && blank($value)) {
                        $fail('The assigned category field is required.');
                    }
                },
                'in:'.implode(',', ValidationService::knownCategories()),
            ],
            'department' => ['required', 'in:'.implode(',', User::knownDepartments())],
            'level' => ['required', 'in:'.implode(',', User::knownLevels())],
            'stage_ids' => ['nullable', 'array'],
            'stage_ids.*' => ['integer', 'exists:workflow_stages,stage_id'],
        ]);

        $isHead = $validated['level'] === 'head';
        $newCategory = $isHead ? null : $validated['assigned_category'];

        $categoryChanged = $newCategory !== $user->assigned_category;
        $departmentChanged = $validated['department'] !== $user->department;
        $resetPicks = $categoryChanged || $departmentChanged || $isHead;
        $oldCategory = $user->assigned_category;
        $oldDepartment = $user->department;

        // Re-validated server-side against whichever category/department was
        // actually submitted — the dropdowns and stage checkboxes are only
        // kept in sync client-side, so a tampered request could otherwise
        // submit stage IDs from a different category or a department that
        // doesn't own them at all. Skipped entirely for a head — stage picks
        // never apply to them (see eligibleApproversForStage()), so there's
        // nothing to validate against a category that's about to be null.
        $validStageIds = $isHead
            ? collect()
            : $this->stageIdsOwnedByDepartment($newCategory, $validated['department'], $validated['stage_ids'] ?? []);

        $user->assigned_category = $newCategory;
        $user->department = $validated['department'];
        $user->level = $validated['level'];
        $user->save();

        $user->workflowStages()->sync($resetPicks ? [] : $validStageIds);

        $description = match (true) {
            $isHead => "Reassigned {$user->full_name} (#{$user->user_id}) to head of '{$validated['department']}' — ".
                'eligible for Final Approval across every category, no category or stage restrictions.',
            $resetPicks => "Reassigned {$user->full_name} (#{$user->user_id}) from '{$oldCategory}'/'{$oldDepartment}' to ".
                "'{$newCategory}'/'{$validated['department']}' ({$validated['level']}). ".
                'Stage assignments reset to unrestricted (all stages the new department owns in this category).',
            default => "Updated stage assignments for {$user->full_name} (#{$user->user_id}) [{$validated['department']}, {$validated['level']}]: ".
                ($validStageIds->isEmpty() ? 'all stages in category (no restriction).' : implode(', ', $validStageIds->all())),
        };

        AuditLog::record($request->user()->user_id, null, 'assign_stages', $description);

        return redirect()->route('admin.users')->with('status', "Stage assignments updated for {$user->full_name}.");
    }

    /**
     * Server-side integrity gate shared by storeUser() and
     * updateApproverStages(): only stage IDs that both (a) belong to
     * $category and (b) are either unrestricted by department or explicitly
     * owned by $department survive. Mirrors WorkflowService::
     * eligibleApproversForStage()'s own department check exactly, so an
     * approver can never end up holding a stage the admin form wouldn't
     * have let them pick in the first place.
     *
     * @param  array<int>  $requestedStageIds
     */
    private function stageIdsOwnedByDepartment(string $category, ?string $department, array $requestedStageIds): Collection
    {
        return WorkflowStage::configured()->where('document_category', $category)
            ->whereIn('stage_id', $requestedStageIds)
            ->get()
            ->filter(function (WorkflowStage $stage) use ($department) {
                $owners = $stage->departmentNames();

                return $owners === [] || in_array($department, $owners, true);
            })
            ->pluck('stage_id');
    }

    /**
     * Deactivation handoff (Feature): deactivating an approver who's
     * holding pending work resolves each assignment one of three ways —
     * reassigns it to another eligible approver who doesn't already hold
     * their own seat on that stage (see WorkflowService::
     * findReplacementApprover() — rare under the unanimous-approval model,
     * since every eligible approver was normally already seated at routing
     * time), withdraws it with no Admin involvement if a sibling approver
     * already covers that same stage independently (see WorkflowService::
     * withdrawAssignment() — the common case), or — only if genuinely
     * nobody is eligible under the normal category+stage rule — auto-
     * approves it immediately (see WorkflowService::
     * autoApproveDeactivatedSeat()), same as any other stage nobody was
     * ever eligible for, rather than the SLA Override Queue, since this
     * was never an SLA failure and shouldn't be recorded as one. is_active
     * is flipped BEFORE this loop runs, not after — otherwise the approver
     * being deactivated could still show up as their own eligible
     * replacement.
     */
    public function toggleUser(Request $request, User $user)
    {
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $reason = $validated['reason'] ?? null;
        $wasActive = $user->is_active;

        $user->is_active = ! $wasActive;
        $user->save();

        // Push this the instant it happens, not just via the notification
        // bell — a deactivated user sitting idle on a page should be logged
        // out immediately rather than only finding out on their next click
        // (see the 'account.deactivated' listener in app.js).
        if ($wasActive && ! $user->is_active) {
            event(new AccountDeactivated($user->user_id));
        }

        $reassignedCount = 0;
        $withdrawnCount = 0;
        $autoApprovedCount = 0;

        if ($wasActive && $user->role === 'approver') {
            $pendingAssignments = DocumentAssignment::where('user_id', $user->user_id)
                ->where('individual_status', 'pending')
                ->with(['document', 'stage'])
                ->get();

            foreach ($pendingAssignments as $assignment) {
                $replacement = $this->workflow->findReplacementApprover($assignment);

                if ($replacement) {
                    $this->workflow->reassignAssignment($assignment, $replacement, $user, $reason);
                    $reassignedCount++;
                } elseif ($this->workflow->hasSiblingSeat($assignment)) {
                    $this->workflow->withdrawAssignment($assignment, $user, $reason);
                    $withdrawnCount++;
                } else {
                    $this->workflow->autoApproveDeactivatedSeat($assignment, $user, $reason);
                    $autoApprovedCount++;
                }
            }
        }

        AuditLog::record($request->user()->user_id, null, 'user_toggle',
            "Account #{$user->user_id} ({$user->username}) set to ".($user->is_active ? 'active' : 'inactive').'.'.
            ($reason ? " Reason: \"{$reason}\"" : '').
            ($reassignedCount > 0 ? " {$reassignedCount} pending assignment(s) reassigned." : '').
            ($withdrawnCount > 0 ? " {$withdrawnCount} withdrawn (already covered by another approver on the same stage)." : '').
            ($autoApprovedCount > 0 ? " {$autoApprovedCount} auto-approved (no eligible approver remained)." : ''));

        $status = 'Account status updated.';
        if ($reassignedCount > 0 || $withdrawnCount > 0 || $autoApprovedCount > 0) {
            $status .= " {$reassignedCount} reassigned, {$withdrawnCount} withdrawn (already covered), {$autoApprovedCount} auto-approved (no eligible approver).";
        }

        return back()->with('status', $status);
    }

    // ---------------------------------------------------------------
    // ML dataset training (5–10 sample uploads per category — Scope 1.4)
    // ---------------------------------------------------------------

    private const TRAINING_MIN_PER_CATEGORY = 5;

    // Deliberately no lifetime-total ceiling per category — the corpus is
    // meant to keep growing forever as an admin confirms more documents
    // from the ML Review queue over the system's lifetime (see
    // trainModel()'s trained_in_model_id stamping below for how "already
    // taught the model something" is tracked instead of ever deleting a
    // sample). This is purely a per-REQUEST batch limit — the original
    // reason staging is split by category at all (see stageTrainingSamples()'s
    // docblock) — not a total-staged cap.
    private const TRAINING_BATCH_UPLOAD_LIMIT = 20;

    public function mlTraining(Request $request)
    {
        $categories = ValidationService::knownCategories();

        // Shared across every admin, not scoped to the current session —
        // deliberately so: this app only ever has one active classifier at
        // a time, so there's nothing "personal" about staged samples for
        // it. Storing them in the session tied them to one browser/login
        // and silently lost progress on logout, session expiry, or
        // switching devices; any admin can now pick up where another left
        // off. See the ml_staging_samples migration.
        $stagedSamples = MlStagingSample::with(['stagedBy', 'trainedInModel'])->orderBy('created_at')->get()->groupBy('category');
        $minPerCategory = self::TRAINING_MIN_PER_CATEGORY;
        $batchUploadLimit = self::TRAINING_BATCH_UPLOAD_LIMIT;

        return view('admin.ml_training', array_merge(compact(
            'categories', 'stagedSamples', 'minPerCategory', 'batchUploadLimit'
        ), $this->mlMetricsData()));
    }

    /**
     * Fragment refresh for the Active Model / Training History / Estimated
     * Approval Time panels — see App\Events\MlModelTrained's docblock for
     * what triggers it.
     */
    public function mlMetricsRefresh()
    {
        return view('admin.partials.ml_metrics_panels', $this->mlMetricsData());
    }

    /**
     * Lightweight JSON signal for the poll fallback — see overviewPoll()'s
     * docblock for the same reasoning. Active model ids + latest
     * last_trained/trained_at timestamps are enough to detect "something
     * changed" without re-fetching the whole fragment just to compare it.
     */
    public function mlMetricsPoll()
    {
        // training_queue_total doubles as this fragment's poll signal for
        // the Training Queue section too — a document being routed doesn't
        // fire MlModelTrained (no model finished training), but it does
        // change this count, and startLivePoll() compares the whole JSON
        // blob, so adding it here is enough for the poll fallback to catch
        // it without a separate endpoint.
        return response()->json([
            'active_model_id' => MlModelRepository::active()?->model_id,
            'latest_trained' => MlModelRepository::max('last_trained'),
            'latest_time_estimate_trained' => MlTimeEstimateModel::max('trained_at'),
            'training_queue_total' => $this->classifier->trainingQueueStatus()['total_eligible'],
        ]);
    }

    /**
     * @return array{activeModel: ?MlModelRepository, history: Collection, timeEstimateGroups: Collection, timeEstimateTrainingFloor: int, trainingQueue: array}
     */
    private function mlMetricsData(): array
    {
        return [
            'activeModel' => MlModelRepository::active(),
            'history' => MlModelRepository::orderByDesc('last_trained')->limit(10)->get(),
            // Read-only — no "train now" control for this one, see
            // ApprovalTimeMlService's docblock for why it trains itself
            // automatically on a schedule instead.
            'timeEstimateGroups' => $this->timeMl->statusForAllGroups(),
            'timeEstimateTrainingFloor' => ApprovalTimeMlService::MIN_TRAINING_SAMPLES,
            // Feature: admin can see documents piling up for auto-retraining
            // — see ClassificationService::trainingQueueStatus()'s docblock.
            'trainingQueue' => $this->classifier->trainingQueueStatus(),
        ];
    }

    /**
     * Wraps an already-built Collection in a LengthAwarePaginator — shared
     * by every admin queue that groups results into containers before
     * paginating (SLA Queue's auto-approved section, Unassigned
     * Documents). $pageName lets two independently paginated lists
     * coexist on the same page/URL without their ?page= query params
     * colliding.
     *
     * $path is the REAL page route (e.g. route('admin.sla.queue')) —
     * deliberately never $request->url(), since every one of these lists
     * is also rendered via a separate .../refresh route for the live-poll
     * JS to swap in place. Building the path from the current request
     * would bake THAT fragment URL into the Next/Previous links whenever a
     * live swap happens to be what generated this page's markup —
     * clicking one then navigates straight to the bare fragment endpoint
     * (no layout, no CSS) instead of the real page.
     */
    private function paginateContainers(Collection $items, Request $request, int $perPage, string $path, string $pageName = 'page'): LengthAwarePaginator
    {
        $page = (int) $request->input($pageName, 1);

        return new LengthAwarePaginator(
            $items->forPage($page, $perPage)->values(),
            $items->count(),
            $perPage,
            $page,
            ['path' => $path, 'query' => $request->query(), 'pageName' => $pageName]
        );
    }

    /**
     * Uploads and text-extracts sample documents for ONE category at a
     * time, accumulating them in a shared table rather than requiring
     * every category's files in a single request. A single combined
     * submission (up to 30 files across 3 categories) can silently exceed
     * PHP's max_file_uploads ini limit (default 20) — files past that
     * cutoff are dropped by PHP itself before Laravel ever sees them, with
     * no error pointing at the real cause. max_file_uploads is
     * PHP_INI_SYSTEM only (no .htaccess/.user.ini/runtime override exists
     * for it), so fixing this by raising the limit isn't an option without
     * root on every future deployment — staging per category (well under
     * any reasonable limit) sidesteps the ceiling entirely instead of
     * depending on it.
     */
    public function stageTrainingSamples(Request $request, string $category)
    {
        abort_unless(in_array($category, ValidationService::knownCategories(), true), 404);

        $validated = $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:'.self::TRAINING_BATCH_UPLOAD_LIMIT],
            'files.*' => ['required', 'file', 'mimes:pdf,txt,docx', 'max:10240'],
        ]);

        // Compared against as each new file is staged, growing to include
        // files from THIS same batch too — so uploading two near-identical
        // files in one request catches the second against the first, not
        // just against whatever was already staged before this request.
        $existingSamples = MlStagingSample::where('category', $category)->get(['original_filename', 'extracted_text']);
        $duplicateWarnings = [];

        foreach ($validated['files'] as $file) {
            $text = $this->extractor->extract($file)['text'];

            foreach ($existingSamples as $existing) {
                $similarity = $this->classifier->wordOverlapSimilarity($text, $existing->extracted_text);
                if ($similarity >= config('ml.near_duplicate_threshold', 0.85)) {
                    $duplicateWarnings[] = sprintf(
                        '"%s" looks like a near-duplicate of already-staged "%s" (%d%% word overlap) — consider a more varied real example instead.',
                        $file->getClientOriginalName(),
                        $existing->original_filename,
                        round($similarity * 100)
                    );
                    break; // one warning per new file is enough, no need to list every match
                }
            }

            $existingSamples->push(MlStagingSample::create([
                'category' => $category,
                'original_filename' => $file->getClientOriginalName(),
                'extracted_text' => $text,
                'staged_by' => $request->user()->user_id,
            ]));
        }

        $totalStaged = MlStagingSample::where('category', $category)->count();

        $response = back()->with('status', count($validated['files'])." sample(s) added for '{$category}' ({$totalStaged} total staged).");

        if ($duplicateWarnings) {
            $response->with('warning', $duplicateWarnings);
        }

        return $response;
    }

    public function clearTrainingStaging(Request $request, string $category)
    {
        abort_unless(in_array($category, ValidationService::knownCategories(), true), 404);

        MlStagingSample::where('category', $category)->delete();

        return back()->with('status', "Cleared staged samples for '{$category}'.");
    }

    /** Removes one staged sample without clearing the rest of its category. */
    public function destroyTrainingSample(Request $request, MlStagingSample $sample)
    {
        $sample->delete();

        return back()->with('status', "Removed '{$sample->original_filename}' from staging.");
    }

    public function trainModel(Request $request)
    {
        $categories = ValidationService::knownCategories();
        $stagedSamples = MlStagingSample::orderBy('category')->get()->groupBy('category');

        foreach ($categories as $category) {
            $count = $stagedSamples->get($category, collect())->count();
            abort_if($count < self::TRAINING_MIN_PER_CATEGORY, 422,
                "'{$category}' needs at least ".self::TRAINING_MIN_PER_CATEGORY." staged samples (has {$count}).");
        }

        $samplesByCategory = $stagedSamples->map(fn ($samples) => $samples->pluck('extracted_text')->all())->all();

        $model = $this->classifier->train($samplesByCategory);

        AuditLog::record($request->user()->user_id, null, 'ml_train',
            "Trained model #{$model->model_id} ({$model->version}) on {$model->training_sample_count} samples across ".count($categories).' categories. Estimated accuracy: '.$model->accuracy_score.'%.');

        // Staged samples deliberately survive training now (no more
        // truncate() here) — an admin can keep adding samples across
        // multiple sessions and have the NEXT training run combine
        // everything staged so far into one larger corpus, rather than
        // every run starting from zero again. Use "Clear" on the ML
        // Training page to explicitly wipe a category's staging if a fresh
        // start is ever actually wanted.
        //
        // Every row gets swept into $samplesByCategory above regardless of
        // category (no per-category filtering happens before train()), so
        // stamping every currently-staged row here is accurate, not an
        // approximation — lets the page show "already taught this model
        // something" vs "still waiting for the next retrain" per sample.
        MlStagingSample::query()->update(['trained_in_model_id' => $model->model_id]);

        return back()->with('status', "Model {$model->version} trained successfully on {$model->training_sample_count} samples (est. accuracy {$model->accuracy_score}%). Staged samples are kept — add more anytime and retrain to combine them.");
    }

    // ---------------------------------------------------------------
    // SLA override queue (Section 5)
    // ---------------------------------------------------------------

    /**
     * SLA Override Queue. Violated assignments are nested the same way as
     * the Approver dashboard: documents an Originator uploaded together in
     * one SubmissionBatch stay grouped under one container so Admins can
     * see at a glance which violation belongs to which original request,
     * rather than a flat list of unrelated-looking rows.
     */
    /** Shared by slaQueue() and slaQueueRefresh() — one place, can't drift. */
    /**
     * No more escalated/violated list here — a stage with no eligible
     * approver now auto-approves the instant its own (Admin-fallback)
     * deadline passes, same as any other miss, instead of waiting in a
     * separate queue for Admin to act on directly (see SlaService::
     * escalateNeedsApprover()). This page is now exclusively the
     * auto-approved review queue, whichever path produced each entry.
     */
    private function slaQueueData(Request $request): LengthAwarePaginator
    {
        // Grouped by document — a document can have MORE than one
        // auto-approved stage awaiting review at once (e.g. Budget Check
        // and Final Approval both fired), and a flat per-stage list made
        // that look like unrelated rows.
        $reviewAssignments = DocumentAssignment::awaitingAdminReview()
            ->with(['document', 'stage', 'approver'])
            ->get();

        $reviewContainers = $reviewAssignments
            ->groupBy('document_id')
            ->map(fn ($stageAssignments) => (object) [
                'document' => $stageAssignments->first()->document,
                'assignments' => $stageAssignments->sortBy(fn ($a) => $a->stage->sequence_order)->values(),
            ])
            ->sortBy(fn ($c) => $c->assignments->first()->acted_at)
            ->values();

        $perPage = 2;

        // Deep-link support (Admin Violations links here with
        // ?highlight={document_id}) — jump straight to whichever page
        // actually contains that document instead of always landing on
        // page 1 and leaving Admin to hunt for it themselves. Overrides
        // any ?page= the request came in with, since the two are
        // mutually exclusive ways of picking a page.
        if ($request->filled('highlight')) {
            $index = $reviewContainers->search(fn ($c) => $c->document->document_id == $request->input('highlight'));
            if ($index !== false) {
                $request->merge(['page' => intdiv($index, $perPage) + 1]);
            }

            // Cleared from the query bag (not just left out of the merge
            // above) — paginateContainers() below builds every page link
            // from $request->query(), which merge() never touches. Left
            // in place, every one of those links would silently re-send
            // highlight=X, snapping the admin straight back to this same
            // page no matter which page link they actually clicked.
            $request->query->remove('highlight');
        }

        return $this->paginateContainers($reviewContainers, $request, $perPage, route('admin.sla.queue'));
    }

    public function slaQueue(Request $request)
    {
        $reviewContainers = $this->slaQueueData($request);

        return view('admin.sla_queue', compact('reviewContainers'));
    }

    /** Live-refresh fragment (Feature: realtime) — same data as slaQueue(), just the results. */
    public function slaQueueRefresh(Request $request)
    {
        $reviewContainers = $this->slaQueueData($request);

        return view('admin.partials.sla-queue-results', compact('reviewContainers'));
    }

    /** Cheap change-signal for the live-poll fallback — same pattern as overviewPoll(). */
    public function slaQueuePoll()
    {
        return response()->json([
            'awaiting_review' => DocumentAssignment::awaitingAdminReview()->count(),
        ]);
    }

    /**
     * Section 5 follow-up: review every stage the SYSTEM auto-approved on
     * ONE document, all at once — an admin reviews the document as a
     * whole, not stage-by-stage (a document can have more than one
     * auto-approved stage awaiting review, e.g. Budget Check AND Final
     * Approval both firing). Confirming just leaves a note on each.
     * Disputing does NOT reverse the approval(s) — there is no "reopen"
     * path in WorkflowService::completeStage(), and unwinding an
     * already-finalized document (possibly already notified/archived) is
     * unsafe — instead it sets disputed_at once (global_status is left
     * as-is, so the document's approval history stays intact) and asks the
     * originator to resubmit a corrected version.
     */
    public function reviewAutoApproval(Request $request, DocumentRepository $document)
    {
        $pending = DocumentAssignment::where('document_id', $document->document_id)
            ->where('auto_approved', true)
            ->whereNull('admin_reviewed_at')
            ->with('stage')
            ->get();

        abort_if($pending->isEmpty(), 404);

        $validated = $request->validate([
            'outcome' => ['required', 'in:confirmed,disputed'],
            'note' => ['required_if:outcome,disputed', 'nullable', 'string', 'max:1000'],
        ]);

        $admin = $request->user();
        $note = $validated['note'] ?? null;
        $stageNames = $pending->pluck('stage.stage_name')->all();

        foreach ($pending as $assignment) {
            $reviewedAt = now();

            // Logged BEFORE saving admin_reviewed_at, using the same
            // review_due_at set back when this stage was auto-approved
            // (see SlaService::autoApproveOne()) — a soft marker, not a
            // block, so a late review still goes through exactly the
            // same either way; this just records that it was late,
            // Resolves whichever AdminViolation (late_review) SlaService::
            // trackLateReviews() already opened for this assignment — or,
            // if the sweep hasn't run yet since the window lapsed (review
            // happens between sweeps), creates one already resolved. Not
            // attributed to a specific admin — this queue has no single
            // assigned owner the way an approver's seat does.
            if ($assignment->review_due_at && $reviewedAt->greaterThan($assignment->review_due_at)) {
                AdminViolation::firstOrCreate(
                    ['assignment_id' => $assignment->assignment_id, 'violation_type' => 'late_review', 'resolved_at' => null],
                    [
                        'document_id' => $assignment->document_id,
                        'stage_name' => $assignment->stage->stage_name,
                        'first_violated_at' => $assignment->review_due_at,
                        'notification_count' => 0,
                    ]
                )->update(['resolved_at' => $reviewedAt]);
            }

            $assignment->admin_reviewed_at = $reviewedAt;
            $assignment->admin_reviewed_by = $admin->user_id;
            $assignment->admin_review_note = $note;
            $assignment->admin_review_outcome = $validated['outcome'];
            $assignment->save();
        }

        $stageList = implode(', ', $stageNames);

        if ($validated['outcome'] === 'confirmed') {
            AuditLog::record($admin->user_id, $document->document_id, 'admin_review',
                "Confirmed auto-approved stage(s) '{$stageList}'.".($note ? " Note: \"{$note}\"" : ''));

            // Confirmed 2026-10-03, a real reported bug: confirming only
            // ever touched admin_reviewed_at on the ASSIGNMENT rows above —
            // never global_status or disputed_at on $document itself, the
            // only two columns DocumentRepository::booted() actually
            // watches to decide whether to broadcast. DocumentRepository::
            // display_status already correctly computes 'approved' the
            // instant every auto-approved stage is reviewed (see its own
            // accessor), so the status WAS already right on a fresh page
            // load — this was purely a missing live push: an already-open
            // originator/admin page had no way to know anything changed
            // and kept showing the stale "Auto-Approved — Pending Review"
            // badge until manually reloaded. Fired explicitly here, same
            // event the dispute path below already gets for free (it
            // happens to touch disputed_at, a real column the model hook
            // does watch).
            event(new DocumentStatusChanged($document));

            return back()->with('status', 'Marked as reviewed.');
        }

        $document->disputed_at = now();
        $document->save();

        AuditLog::record($admin->user_id, $document->document_id, 'admin_dispute',
            "Disputed auto-approved stage(s) '{$stageList}': \"{$note}\"");

        NotificationRecord::send($document->originator_id, $document->document_id,
            "Your document '{$document->title}' was auto-approved by the system, but an Admin has disputed it: \"{$note}\". Please resubmit a corrected version.", 'high');

        foreach (User::where('role', 'admin')->where('is_active', true)->where('user_id', '!=', $admin->user_id)->get() as $other) {
            NotificationRecord::send($other->user_id, $document->document_id,
                "{$admin->full_name} disputed the system's auto-approval of '{$document->title}': \"{$note}\".", 'high');
        }

        return back()->with('status', 'Disputed — the originator has been notified to resubmit.');
    }

    // ---------------------------------------------------------------
    // Workflow stage configuration
    // ---------------------------------------------------------------

    /**
     * Feature: toggle whether an approver's Approve/Reject is restricted to
     * business hours (see ApprovalController::requireBusinessHoursIfEnforced()).
     * Off by default on a fresh install — an Admin opts in deliberately.
     * A plain checkbox toggle, not AJAX — this is a rare, deliberate
     * configuration change, not something that needs a live-updating UI.
     */
    public function updateBusinessHoursEnforcement(Request $request)
    {
        $setting = SystemSetting::current();
        $setting->enforce_business_hours_decisions = $request->boolean('enforce_business_hours_decisions');
        $setting->updated_by = $request->user()->user_id;
        $setting->save();

        SystemSettingsChanged::dispatch();

        return back()->with('status', $setting->enforce_business_hours_decisions
            ? 'Approver decisions are now restricted to business hours (9 AM–5 PM, Mon–Sat).'
            : 'Approver decisions are no longer restricted to business hours.');
    }

    /**
     * Approval Workflow — a read-only view of UJF's fixed approval pipeline:
     * each category's stages in order, the department(s) that own each one,
     * and the seats currently pending on it. The stages themselves are part
     * of the company's established procedure, so there is nothing here to
     * add, rename, reorder or remove.
     */
    public function workflowConfig()
    {
        $businessHoursEnforced = SystemSetting::current()->enforce_business_hours_decisions;

        return view('admin.workflow_config', $this->approvalWorkflowData() + ['businessHoursEnforced' => $businessHoursEnforced]);
    }

    /** Live-refresh fragment (Feature: realtime) — same stage-list data, just the results panel. */
    public function workflowConfigRefresh()
    {
        return view('admin.partials.workflow-config-results', $this->approvalWorkflowData());
    }

    /** @return array{stages: Collection, pendingByStage: Collection} */
    private function approvalWorkflowData(): array
    {
        return [
            'stages' => WorkflowStage::configured()->with('departments')->orderBy('document_category')->orderBy('sequence_order')->get()->groupBy('document_category'),
            'pendingByStage' => DocumentAssignment::where('individual_status', 'pending')
                ->with(['document', 'approver'])
                ->get()
                ->groupBy('stage_id'),
        ];
    }

    /** Cheap change-signal for the live-poll fallback — same pattern as overviewPoll(). */
    public function workflowConfigPoll()
    {
        return response()->json([
            'stages' => WorkflowStage::configured()->count(),
            'pending' => DocumentAssignment::where('individual_status', 'pending')->count(),
        ]);
    }

    // ---------------------------------------------------------------
    // Operational Window Controls & Holiday Management (Section 1)
    // ---------------------------------------------------------------

    public function calendar(Request $request)
    {
        $month = $request->filled('month') ? Carbon::parse($request->string('month').'-01') : now()->startOfMonth();

        $holidays = SlaHoliday::whereBetween('holiday_date', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])
            ->get()
            ->keyBy(fn (SlaHoliday $h) => $h->holiday_date->toDateString());

        return view('admin.calendar', compact('month', 'holidays'));
    }

    /** Live-refresh fragment (Feature: realtime) — same grid data for the currently-viewed month, just the results. */
    public function calendarRefresh(Request $request)
    {
        $month = $request->filled('month') ? Carbon::parse($request->string('month').'-01') : now()->startOfMonth();

        $holidays = SlaHoliday::whereBetween('holiday_date', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])
            ->get()
            ->keyBy(fn (SlaHoliday $h) => $h->holiday_date->toDateString());

        return view('admin.partials.calendar-grid', compact('month', 'holidays'));
    }

    /** Cheap change-signal for the live-poll fallback, scoped to the visible month — same pattern as overviewPoll(). */
    public function calendarPoll(Request $request)
    {
        $month = $request->filled('month') ? Carbon::parse($request->string('month').'-01') : now()->startOfMonth();

        $holidays = SlaHoliday::whereBetween('holiday_date', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()]);

        return response()->json([
            'count' => (clone $holidays)->count(),
            'latest' => (clone $holidays)->max('updated_at'),
        ]);
    }

    public function storeHoliday(Request $request)
    {
        $validated = $request->validate([
            'holiday_date' => ['required', 'date', 'unique:sla_holidays,holiday_date'],
            'label' => ['nullable', 'string', 'max:255'],
        ]);

        SlaHoliday::create($validated + ['created_by' => $request->user()->user_id]);

        AuditLog::record($request->user()->user_id, null, 'sla_holiday_add', "Marked {$validated['holiday_date']} as a non-working day.");

        // Section 1: a newly-marked holiday must (a) push forward the due
        // date of any in-flight document that was already using that day
        // as its hard deadline, and (b) retroactively recalculate every
        // already-routed pending assignment's SLA window that spans it —
        // both are otherwise "computed once, stored statically" at
        // routing/submission time and would silently stay wrong.
        $sync = $this->workflow->syncDueDatesWithCalendar();

        return back()->with('status', 'Holiday added.'.$this->calendarSyncSummary($sync));
    }

    public function destroyHoliday(Request $request, SlaHoliday $holiday)
    {
        $date = $holiday->holiday_date->toDateString();
        $holiday->delete();

        AuditLog::record($request->user()->user_id, null, 'sla_holiday_remove', "Unmarked {$date} as a non-working day.");

        // Removing a holiday only ever frees up time — it can't invalidate
        // an existing due date — but SLA windows still need re-syncing
        // since more business time may now be available before the
        // (unchanged) due date than was assumed when they were computed.
        $changed = $this->workflow->recalculatePendingSlaDeadlines();

        return back()->with('status', 'Holiday removed.'.($changed ? " {$changed} pending assignment(s) had their SLA deadline recalculated." : ''));
    }

    private function calendarSyncSummary(array $sync): string
    {
        $parts = [];
        if ($sync['documents_shifted'] > 0) {
            $parts[] = "{$sync['documents_shifted']} document(s) had their due date moved off a now-non-working day";
        }
        if ($sync['assignments_recalculated'] > 0) {
            $parts[] = "{$sync['assignments_recalculated']} pending assignment(s) had their SLA deadline recalculated";
        }

        return $parts ? ' '.implode('; ', $parts).'.' : '';
    }

    // ---------------------------------------------------------------
    // SLA Violation reporting (Section 4)
    // ---------------------------------------------------------------

    public function violationsReport(Request $request)
    {
        $query = $this->violationsQuery($request);

        // Same "folders first" pattern as the Archive (Feature: browse by
        // category). The stat cards and the Admin/Approver tables are ALL
        // gated behind picking a category — an unfiltered "Top Category:
        // Job Order" card on first load reads as if the report already
        // defaulted to Job Order, so none of that computes/shows until a
        // category's actually picked. Only the folder tiles themselves
        // (each showing its own count) render on the bare landing screen.
        $showFolders = ! $request->filled('category');

        return view('admin.sla_violations', array_merge(
            $this->violationStats($query, $request),
            $this->adminViolationsData($request),
            [
                'showFolders' => $showFolders,
                'folders' => $showFolders ? $this->violationFolderStats() : null,
            ]
        ));
    }

    /** Live-refresh fragment for the Admin Violations section. */
    public function adminViolationsRefresh(Request $request)
    {
        return view('admin.partials.admin-violations-results', $this->adminViolationsData($request));
    }

    /** Cheap change-signal for the live-poll fallback — scoped to the same category AND violation_type as adminViolationsData(), so this never signals a change the refresh wouldn't actually show. */
    public function adminViolationsPoll(Request $request)
    {
        $query = AdminViolation::query()->where('violation_type', 'late_review');
        if ($request->filled('category')) {
            $category = $request->string('category');
            $query->whereHas('document', fn ($q) => $q->where('ml_category', $category));
        }

        return response()->json([
            'count' => (clone $query)->count(),
            'latest' => (clone $query)->max('first_violated_at'),
        ]);
    }

    /**
     * Popup fragment (Feature: click an approver's row on the SLA
     * Violations page, see every document + the stage(s) where each of
     * their violations happened) — fetched by the shared
     * openKpiDrilldown() modal, same pattern as the Admin dashboard's
     * clickable KPI cards. Scoped to the same category the roster row's
     * own count reflects (see violationStats()'s approverRoster), so the
     * popup never shows more than what the row itself claimed.
     *
     * Grouped by document — a document with violations on more than one
     * stage used to repeat as one row per stage; grouped here instead so
     * it shows once with its stages joined ("Budget Check | Technical
     * Review"). The single "Violated" time shown is the most recent of
     * the group — free from the existing orderByDesc('violation_timestamp')
     * below, since the first row PHP's groupBy() keeps for each document
     * is whichever one sorted first, i.e. the latest.
     */
    public function approverViolationDocuments(Request $request, User $approver)
    {
        $violations = $this->violationsQuery($request)
            ->where('approver_id', $approver->user_id)
            ->with('document')
            ->orderByDesc('violation_timestamp')
            ->get()
            ->groupBy('document_id')
            ->map(fn ($rows) => (object) [
                'document' => $rows->first()->document,
                'stages' => $rows->pluck('stage_name')->unique()->values(),
                'total' => $rows->count(),
                'latestViolatedAt' => $rows->first()->violation_timestamp,
            ])
            ->values();

        return view('admin.partials.approver-violation-documents', compact('violations', 'approver'));
    }

    /**
     * Stat-card data for ONE approver (Feature: clicking their row on the
     * SLA Violations page swaps the top cards to their own numbers,
     * alongside the document popup above). Same category scoping as
     * everything else on this page. Rank mirrors the exact ordering
     * violationStats()'s approverRoster is displayed in (violation_count
     * desc, then name), so "#2 of 12" matches what the visible table
     * itself would show if you counted down to this row.
     */
    public function approverStats(Request $request, User $approver)
    {
        $query = $this->violationsQuery($request)->where('approver_id', $approver->user_id);

        $totalCount = (clone $query)->count();
        $topStage = (clone $query)
            ->selectRaw('stage_name, count(*) as total')
            ->groupBy('stage_name')
            ->orderByDesc('total')
            ->first();
        $disputedCount = (clone $query)->whereHas('document', fn ($q) => $q->whereNotNull('disputed_at'))->count();

        $roster = User::where('role', 'approver')
            ->withCount(['slaViolations as violation_count' => function ($q) use ($request) {
                if ($request->filled('category')) {
                    $category = $request->string('category');
                    $q->whereHas('document', fn ($dq) => $dq->where('ml_category', $category));
                }
            }])
            ->orderByDesc('violation_count')
            ->orderBy('full_name')
            ->get();
        $rank = $roster->search(fn ($u) => $u->user_id === $approver->user_id);

        return response()->json([
            'name' => $approver->full_name,
            'totalCount' => $totalCount,
            'topStageName' => $topStage->stage_name ?? '—',
            'topStageTotal' => $topStage->total ?? 0,
            'rank' => $rank === false ? null : $rank + 1,
            'rosterCount' => $roster->count(),
            'disputedCount' => $disputedCount,
        ]);
    }

    /**
     * Fastest approvers / departments / categories — plain historical
     * averages (see PerformanceInsightsService's docblock for why this
     * isn't ML), shared by the full page load, the live-refresh fragment,
     * and the poll's cheap change-signal below.
     */
    public function performanceInsights()
    {
        return view('admin.performance_insights', $this->performanceInsightsData());
    }

    public function performanceInsightsRefresh()
    {
        return view('admin.partials.performance-insights-results', $this->performanceInsightsData());
    }

    /** Cheap change-signal for the live-poll fallback — the most recent real (non-auto-approved) decision company-wide. */
    public function performanceInsightsPoll()
    {
        $latest = DocumentAssignment::whereNotNull('acted_at')
            ->where('auto_approved', false)
            ->max('acted_at');

        return response()->json(['latest' => $latest]);
    }

    private function performanceInsightsData(): array
    {
        return [
            'fastestApprovers' => $this->performance->fastestApprovers(),
            'fastestDepartments' => $this->performance->fastestDepartments(),
            'fastestCategories' => $this->performance->fastestCategories(),
            // Feature: a Fastest/Slowest toggle — both directions are
            // fetched up front (same cheap grouped-average queries, just
            // sorted the other way) so the toggle swaps instantly on the
            // client with no extra request. See
            // admin/partials/performance-insights-results.blade.php.
            'slowestApprovers' => $this->performance->slowestApprovers(),
            'slowestDepartments' => $this->performance->slowestDepartments(),
            'slowestCategories' => $this->performance->slowestCategories(),
        ];
    }

    private function violationsQuery(Request $request)
    {
        $query = SlaViolation::query();

        if ($request->filled('category')) {
            $category = $request->string('category');
            $query->whereHas('document', fn ($q) => $q->where('ml_category', $category));
        }
        // Set by approverViolationDocuments() when a specific approver's
        // roster row is clicked (see admin/sla_violations.blade.php) —
        // narrows this down to just their own violations for that popup.
        if ($request->filled('approver_id')) {
            $query->where('approver_id', $request->integer('approver_id'));
        }

        return $query;
    }

    /** One row per category for the folder-grid landing screen. */
    private function violationFolderStats()
    {
        return collect(ValidationService::knownCategories())->map(fn ($category) => (object) [
            'category' => $category,
            'total' => SlaViolation::whereHas('document', fn ($q) => $q->where('ml_category', $category))->count(),
        ]);
    }

    /**
     * Everything the stat cards + approver roster need. Still computed
     * regardless of $showFolders, but the view only renders either of them
     * once $showFolders is false (see violationsReport()) — on the bare
     * folder screen this result is simply unused rather than wired to
     * anything, since nothing on that screen needs it yet.
     */
    private function violationStats($query, Request $request): array
    {
        $byApprover = (clone $query)->selectRaw('approver_id, count(*) as total')
            ->groupBy('approver_id')->with('approver')->orderByDesc('total')->limit(5)->get();

        $byStage = (clone $query)->selectRaw('stage_name, count(*) as total')
            ->groupBy('stage_name')->orderByDesc('total')->limit(5)->get();

        // Disputed — how many of these violations were later flagged by an
        // Admin as a bad auto-approval (see AdminController::
        // reviewAutoApproval()). Otherwise only visible per-row as a badge,
        // never as a total anywhere on this page.
        $disputedCount = (clone $query)->whereHas('document', fn ($q) => $q->whereNotNull('disputed_at'))->count();

        $totalCount = (clone $query)->count();

        // Full roster for the Approvers table — EVERY approver, not just
        // the ones with violations, so a clean record is visible too, not
        // just a leaderboard of offenders. violation_count respects the
        // same category filter as the rest of this report; assignment_count
        // is unfiltered by date (a lifetime total) so "0 violations" can be
        // read against "0 of 0 assignments" (never given work yet) vs "0
        // of 50" (a genuinely clean record). The documents+stages behind
        // violation_count are fetched on demand by approverViolationDocuments()
        // when the row is clicked, not precomputed here.
        $approverRoster = User::where('role', 'approver')
            ->withCount([
                'slaViolations as violation_count' => function ($q) use ($request) {
                    if ($request->filled('category')) {
                        $category = $request->string('category');
                        $q->whereHas('document', fn ($dq) => $dq->where('ml_category', $category));
                    }
                },
                'assignmentsAsApprover as assignment_count' => function ($q) use ($request) {
                    if ($request->filled('category')) {
                        $category = $request->string('category');
                        $q->whereHas('document', fn ($dq) => $dq->where('ml_category', $category));
                    }
                },
            ])
            ->orderByDesc('violation_count')
            ->orderBy('full_name')
            ->get();

        return [
            'byApprover' => $byApprover,
            'approverRoster' => $approverRoster,
            'byStage' => $byStage,
            'disputedCount' => $disputedCount,
            'totalCount' => $totalCount,
        ];
    }

    /**
     * Admin-side violations — scoped to `late_review` only (see
     * AdminViolation's docblock): an already-auto-approved document that
     * sat past its review grace period without Admin actually confirming
     * or disputing it. `missed_approval` rows are deliberately excluded
     * here — they're logged already-resolved the instant they happen
     * (the auto-approval that causes one already IS its resolution, see
     * AdminViolation's docblock), so they're just a historical record of
     * WHY a stage got auto-approved, not something Admin still needs to
     * act on. A document auto-approved for lack of an eligible approver
     * only shows up in this list once/if its OWN review grace period
     * later lapses unreviewed too — at that point it's a `late_review`
     * row like any other, same as one caused by an approver's own SLA
     * miss. Not attributed to a specific admin (this queue has no single
     * owner the way an approver's seat does), and the system is locked to
     * exactly one Admin account anyway (see storeUser()).
     *
     * Grouped by DOCUMENT — a document can have more than one stage
     * sitting unreviewed at once, listed together under one entry. Status
     * mirrors DocumentAssignment.admin_reviewed_at, the same field the
     * Confirm/Dispute action on the Auto-Approval Review page sets — one
     * action per DOCUMENT there (it reviews every pending stage at once),
     * so one Open/Resolved badge per document here matches exactly what
     * that one action can affect.
     *
     * Scoped to the same `category` param the rest of the page uses,
     * same as violationsQuery() — the view only renders this section
     * once a category folder is picked (see sla_violations.blade.php),
     * so a category belonging to one folder never bleeds into another.
     */
    private function adminViolationsData(Request $request): array
    {
        $query = AdminViolation::query()->where('violation_type', 'late_review');
        if ($request->filled('category')) {
            $category = $request->string('category');
            $query->whereHas('document', fn ($q) => $q->where('ml_category', $category));
        }

        $totalCount = (clone $query)->count();

        // Feature: client-side row fitting — see resources/js/app.js's
        // initFittedPagination() and AdminController::documents()'s
        // matching docblock. Every matching document is sent in one
        // response now (previously a fixed 5-per-page LengthAwarePaginator,
        // unrelated to actual screen size); the browser measures the whole
        // list and works out every page's real boundary itself.
        $documents = (clone $query)
            ->with(['document', 'assignment'])
            ->get()
            ->groupBy('document_id')
            ->map(fn ($rows) => (object) [
                'document' => $rows->first()->document,
                'stages' => $rows->pluck('stage_name')->unique()->values(),
                'isOpen' => $rows->contains(fn ($v) => is_null(optional($v->assignment)->admin_reviewed_at)),
                'firstViolatedAt' => $rows->min('first_violated_at'),
                // Null while still open — every stage resolves together via
                // one review action (AdminController::reviewAutoApproval()),
                // so max() across this document's rows is the one real
                // moment it was resolved, not an approximation.
                'resolvedAt' => $rows->max('resolved_at'),
            ])
            ->sortByDesc('firstViolatedAt')
            ->values();

        return [
            'adminViolationTotal' => $totalCount,
            'adminViolations' => $documents,
        ];
    }

    // ---------------------------------------------------------------
    // Audit trail viewer (Section 6)
    // ---------------------------------------------------------------

    /**
     * Every document-linked audit entry (upload, classify, validate,
     * route, approve, stage_complete, ...) used to get its own top-level
     * row — with several dozen entries per document, that buried the
     * actual "what happened to this document" question under a wall of
     * near-duplicate rows, especially once the nested "Movements" panel
     * already showed the exact same data in one place. Now each document
     * collapses to ONE row, anchored to its upload (or legacy import), and
     * the full history lives in the expandable panel underneath — same
     * data, once, not scattered across N rows. System-level entries with
     * no document (workflow config, SLA settings, account changes, ML
     * training) have nothing to collapse into, so they stay as their own
     * rows exactly as before, interleaved chronologically with the
     * document rows.
     *
     * The Action/Employees/date filters shift meaning to match: instead
     * of matching one row's own action_type/user_id/timestamp, they now
     * ask "does this document's history contain a matching entry
     * anywhere" — filtering "rejected" surfaces every document that was
     * rejected at some point, not a single rejected-labeled row.
     */
    /**
     * The document-row + system-row building/filtering logic shared by
     * auditLogs() (full page) and auditLogsRefresh() (live-poll fragment)
     * — kept in exactly one place so a live-swapped table can never drift
     * from what a normal page load would have shown for the same filters.
     */
    private function buildAuditRows(Request $request): Collection
    {
        $documentTerm = null;
        $numericId = null;
        if ($request->filled('document')) {
            // Matches either a document title substring or, if the term
            // looks numeric (with or without a leading "#"), the exact
            // document_id — so "47" or "#47" both find it directly
            // without needing to know/guess the title.
            $documentTerm = trim($request->string('document'));
            $numericId = ltrim($documentTerm, '#');
        }

        $documentRows = DocumentRepository::with('originator')
            ->when($documentTerm !== null, function ($q) use ($documentTerm, $numericId) {
                $q->where(function ($q2) use ($documentTerm, $numericId) {
                    $q2->where('title', 'like', "%{$documentTerm}%");
                    if ($numericId !== '' && ctype_digit($numericId)) {
                        $q2->orWhere('document_id', (int) $numericId);
                    }
                });
            })
            ->when($request->filled('action_type'), fn ($q) => $q->whereHas(
                'auditLogs', fn ($q2) => $q2->where('action_type', $request->string('action_type'))
            ))
            ->when($request->filled('actor_id'), function ($q) use ($request) {
                $actorId = $request->integer('actor_id');
                $q->where(function ($q2) use ($actorId) {
                    $q2->where('originator_id', $actorId)
                        ->orWhereHas('auditLogs', fn ($q3) => $q3->where('user_id', $actorId));
                });
            })
            ->when($request->filled('date_from'), function ($q) use ($request) {
                $from = $request->date('date_from');
                $q->where(function ($q2) use ($from) {
                    $q2->whereDate('upload_date', '>=', $from)
                        ->orWhereHas('auditLogs', fn ($q3) => $q3->whereDate('timestamp', '>=', $from));
                });
            })
            ->when($request->filled('date_to'), function ($q) use ($request) {
                $to = $request->date('date_to');
                $q->where(function ($q2) use ($to) {
                    $q2->whereDate('upload_date', '<=', $to)
                        ->orWhereHas('auditLogs', fn ($q3) => $q3->whereDate('timestamp', '<=', $to));
                });
            })
            ->get()
            ->map(fn (DocumentRepository $doc) => (object) [
                'kind' => 'document',
                'sort_at' => $doc->upload_date,
                'document' => $doc,
            ]);

        // A document-name search has nothing meaningful to match against a
        // system-level entry (no document at all) — skip fetching them
        // entirely rather than returning a query that can never match.
        $systemRows = $documentTerm !== null ? collect() : AuditLog::with('user')
            ->whereNull('document_id')
            ->when($request->filled('action_type'), fn ($q) => $q->where('action_type', $request->string('action_type')))
            ->when($request->filled('actor_id'), fn ($q) => $q->where('user_id', $request->integer('actor_id')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('timestamp', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('timestamp', '<=', $request->date('date_to')))
            ->get()
            ->map(fn (AuditLog $log) => (object) [
                'kind' => 'system',
                'sort_at' => $log->timestamp,
                'log' => $log,
            ]);

        return $documentRows->concat($systemRows)->sortByDesc('sort_at')->values();
    }

    public function auditLogs(Request $request)
    {
        // Feature: client-side row fitting — see resources/js/app.js's
        // initFittedPagination() and DocumentController::dashboard()'s
        // matching docblock. buildAuditRows() already materializes the
        // full merged, filtered, sorted collection in memory (it isn't a
        // single Eloquent query), so there's nothing to change there —
        // this just stops truncating it to a fixed page size before
        // handing it to the view.
        $logs = $this->buildAuditRows($request);

        // A curated whitelist, not every distinct action_type this table
        // has ever recorded (~35+ raw values — SLA config edits, ML
        // retraining internals, stage-reassignment bookkeeping, etc.) —
        // this dropdown is for "what happened to documents/accounts," not
        // a raw enumeration of every internal event type. Every one of
        // those un-whitelisted actions is still logged and still visible
        // in the results table itself; they're just not offered as a
        // filter choice. Labels come from DocumentMovementTimeline::
        // ACTION_LABELS, the same friendly-name map already used to
        // render every row's own Action badge, so a chosen filter value
        // and what a row actually shows always say the exact same thing.
        $actionTypes = collect([
            'login', 'logout', 'upload', 'classify', 'validate', 'route',
            'approved', 'rejected', 'admin_override', 'auto_approve',
            'sla_escalation', 'resubmit', 'legacy_import', 'security_blocked',
            'user_create', 'user_toggle',
        ])->mapWithKeys(fn ($type) => [$type => DocumentMovementTimeline::ACTION_LABELS[$type] ?? ucfirst(str_replace('_', ' ', $type))]);

        $actors = User::orderBy('full_name')->get(['user_id', 'full_name']);

        return view('admin.audit_logs', compact('logs', 'actionTypes', 'actors'));
    }

    /**
     * Renders just the results fragment (resources/views/admin/partials/
     * audit-results.blade.php) for the live-poll JS to swap into place —
     * see audit_logs.blade.php. Respects the same filters/page as a
     * normal load (the JS forwards the current query string), so a live
     * update never silently drops an active filter or jumps the admin
     * back to page 1.
     */
    public function auditLogsRefresh(Request $request)
    {
        $logs = $this->buildAuditRows($request);

        return view('admin.partials.audit-results', compact('logs'));
    }

    /**
     * Lightweight JSON endpoint the audit log page's JS polls as a
     * fallback if the WebSocket connection is down — same "just the
     * latest AuditLog id" signal already proven for the admin dashboard's
     * own poll (see overviewPoll()), reused here rather than inventing a
     * second cheap-signal shape.
     */
    public function auditLogsPoll()
    {
        return response()->json(['latest_log_id' => AuditLog::max('log_id')]);
    }

    // ---------------------------------------------------------------
    // Document Tracking module
    // ---------------------------------------------------------------

    /**
     * Every document ever submitted, in one place, permanently — unlike
     * Archive (approved documents only) or the SLA queue (violated
     * assignments only), nothing here is ever filtered out by outcome and
     * nothing is ever removed once a document finishes. Each row links to
     * the same tracking page (<x-workflow-stage-list> +
     * <x-document-movement-timeline>) used elsewhere, so "every movement,
     * who reviewed it, who approved/rejected it and why" is answerable
     * for any document at any time.
     */
    private function buildDocumentTrackingQuery(Request $request)
    {
        return DocumentRepository::with(['originator', 'assignments.approver', 'assignments.stage'])
            ->when($request->filled('document'), function ($q) use ($request) {
                $term = trim($request->string('document'));
                $numericId = ltrim($term, '#');
                $q->where(function ($q2) use ($term, $numericId) {
                    $q2->where('title', 'like', "%{$term}%");
                    if ($numericId !== '' && ctype_digit($numericId)) {
                        $q2->orWhere('document_id', (int) $numericId);
                    }
                });
            })
            ->when($request->filled('category'), fn ($q) => $q->where('ml_category', $request->string('category')))
            ->when($request->filled('status'), fn ($q) => $q->where('global_status', $request->string('status')))
            ->when($request->filled('originator_id'), fn ($q) => $q->where('originator_id', $request->integer('originator_id')))
            ->orderByDesc('upload_date');
    }

    public function documents(Request $request)
    {
        // Feature: client-side row fitting — see resources/js/app.js's
        // initFittedPagination() and DocumentController::dashboard()'s
        // matching docblock. Every matching document is sent in one
        // response; the browser measures the whole list and works out
        // every page's real boundary itself.
        $documents = $this->buildDocumentTrackingQuery($request)->get();

        $categories = WorkflowStage::configured()->select('document_category')->distinct()->orderBy('document_category')->pluck('document_category');
        $originators = User::where('role', 'originator')->orderBy('full_name')->get(['user_id', 'full_name']);

        return view('admin.documents.index', compact('documents', 'categories', 'originators'));
    }

    /**
     * Fragment for the live-poll JS to swap in place — see
     * admin/documents/index.blade.php. Same reasoning as
     * auditLogsRefresh(): respects the current filters/page so a live
     * update never drops an active filter or resets pagination.
     */
    public function documentsRefresh(Request $request)
    {
        $documents = $this->buildDocumentTrackingQuery($request)->get();

        return view('admin.partials.documents-results', compact('documents'));
    }

    /** Same cheap "latest AuditLog id" signal as auditLogsPoll() — a new upload, decision, or review event all write one. */
    public function documentsPoll()
    {
        return response()->json(['latest_log_id' => AuditLog::max('log_id')]);
    }
}
