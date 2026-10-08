<?php

namespace App\Services;

use App\Events\MlModelTrained;
use App\Models\DocumentAssignment;
use App\Models\MlTimeEstimateModel;
use App\Models\WorkflowStage;
use App\Support\DecisionTiming;
use App\Support\RidgeRegression;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Trains one Ridge Regression model per (category, department) combo,
 * predicting how long the NEXT decision in that combo will take. Two
 * features: the deciding approver's own historical average speed
 * (leave-one-out, so a row is never used to predict itself) and the day
 * of week — genuinely learnable signal, not just echoing the target back
 * at itself. Runs fully automatically (see TrainTimeEstimateModels) —
 * there is deliberately no manual "train now" control.
 *
 * Ridge, not plain Linear Regression — see App\Support\RidgeRegression's
 * own docblock for the mechanism, but in short: it stays conservative
 * when there's little data to constrain it (fewer real decisions, more
 * room for one unusual outlier to skew a plain fit), and automatically
 * trusts the data more as more real decisions accumulate — exactly the
 * "works reasonably from a small sample, gets sharper as data grows"
 * property MIN_TRAINING_SAMPLES alone can't provide on its own.
 *
 * See ApprovalForecastService's docblock for why THAT stays a plain
 * statistical average — this is the genuine ML regression counterpart,
 * gated behind having enough real history (MIN_TRAINING_SAMPLES) to mean
 * anything more than noise. Estimates for stages beyond the immediate
 * next one still fall back to that plain average, since we don't know in
 * advance which department will end up handling those.
 */
class ApprovalTimeMlService
{
    /**
     * Below this many real decisions for a (category, department) combo,
     * there isn't enough signal to train on — lowered from 20, now that
     * Ridge Regression's own conservatism (see class docblock) makes a
     * smaller floor safe: below this, it isn't that no algorithm COULD
     * run (Ridge's penalty term means it technically can, even on very
     * few rows), it's that grading the result honestly needs at least a
     * couple of held-out folds' worth of real data to mean anything more
     * than a coin flip — see crossValidatedMae()'s docblock.
     */
    public const MIN_TRAINING_SAMPLES = 10;

    private const CROSS_VALIDATION_SEED = 42;

    /** How hard Ridge's penalty pushes coefficients toward a conservative baseline — see RidgeRegression's own docblock. */
    private const RIDGE_LAMBDA = 1.0;

    private ?Collection $rowsCache = null;

    public function __construct(private BusinessHoursService $businessHours) {}

    /** Every (category, department) combo with enough real decision history to be worth training on right now. */
    public function trainableGroups(): Collection
    {
        return $this->rows()
            ->groupBy(fn ($row) => $row->ml_category.'|'.$row->department)
            ->filter(fn (Collection $rows) => $rows->count() >= self::MIN_TRAINING_SAMPLES)
            ->map(fn (Collection $rows) => ['ml_category' => $rows->first()->ml_category, 'department' => $rows->first()->department])
            ->values();
    }

    /**
     * One row per (category, department) combo that has ANY real decision
     * history at all — read-only status for the ML Training admin page
     * (there is no "train now" control for this model; see this class's
     * docblock). Below the training floor it's just a progress count;
     * once trained, the active model's own stats.
     *
     * Filtered down to combos that still match the CURRENT workflow
     * config (see currentlyValidCombos()) — a department that used to
     * own a stage (e.g. Service Report's Final Approval briefly included
     * Finance before that was corrected to Engineering-only) leaves real
     * decision rows behind permanently; without this filter that combo
     * would sit here forever, frozen below the training floor, since no
     * NEW decision can ever be added to it once the config no longer
     * routes that department there.
     */
    public function statusForAllGroups(): Collection
    {
        $stagesByCategory = $this->currentlyConfiguredStages();

        return $this->rows()
            ->groupBy(fn ($row) => $row->ml_category.'|'.$row->department)
            ->filter(function (Collection $rows, string $key) use ($stagesByCategory) {
                [$category, $department] = explode('|', $key, 2);

                return ($stagesByCategory->get($category) ?? collect())
                    ->contains(fn (WorkflowStage $stage) => empty($stage->departmentNames())
                        || in_array($department, $stage->departmentNames(), true));
            })
            ->map(function (Collection $rows) {
                $category = $rows->first()->ml_category;
                $department = $rows->first()->department;
                $model = MlTimeEstimateModel::activeFor($category, $department);

                return [
                    'ml_category' => $category,
                    'department' => $department,
                    'sample_count' => $rows->count(),
                    'model' => $model,
                ];
            })
            ->sortBy(fn ($row) => $row['ml_category'].$row['department'])
            ->values();
    }

    /**
     * Every configured, non-archived stage, grouped by its own category —
     * used purely to filter statusForAllGroups() down to (category,
     * department) combos the CURRENT workflow config could still actually
     * route a decision to. A stage with zero WorkflowStageDepartment rows
     * is unrestricted (see that model's own docblock — "zero rows means
     * unrestricted"), so it validates EVERY department for its category,
     * not none; a stage with explicit rows only validates those. Doesn't
     * filter trainFor()/predictNextDecision() — a model already trained
     * on a now-stale combo should keep predicting until it's naturally
     * replaced, not be torn down the moment the config changes.
     */
    private function currentlyConfiguredStages(): Collection
    {
        return WorkflowStage::configured()->where('is_archived', false)->with('departments')->get()
            ->groupBy('document_category');
    }

    /**
     * Trains a fresh model for one (category, department) combo and, if
     * it scores better than whatever was previously active (or nothing
     * was), makes it the active one. Returns null if there still isn't
     * enough data, the new model didn't beat the existing one, or the
     * training data was too degenerate to fit (e.g. every row sharing
     * identical features) — training is a background, self-healing
     * process, so a bad batch just gets skipped rather than crashing the
     * scheduled command.
     */
    public function trainFor(string $category, string $department): ?MlTimeEstimateModel
    {
        $rows = $this->rows()
            ->where('ml_category', $category)
            ->where('department', $department)
            ->values();

        if ($rows->count() < self::MIN_TRAINING_SAMPLES) {
            return null;
        }

        // Fewer than the training floor's worth of GENUINELY non-zero
        // decisions — not just "not literally every row is 0". A handful
        // of real readings mixed in with mostly-zero ones would otherwise
        // still pass the raw count check above and get treated as a
        // trustworthy sample, when most of it is the same blind spot that
        // produced a misleadingly-confident 0-second model earlier: a
        // decision measures 0 whenever it was both routed AND decided
        // entirely outside business hours (e.g. same-evening testing) —
        // real wall-clock time passed, but businessSecondsRemaining() had
        // nothing to measure. Requiring the SAME floor as the raw count
        // (not just ">= 1") means a mostly-zero-contaminated sample gets
        // the same "not enough real data yet" treatment as a genuinely
        // small one, rather than only catching the all-zero extreme.
        if ($rows->filter(fn ($row) => (float) $row->elapsed_seconds > 0.0)->count() < self::MIN_TRAINING_SAMPLES) {
            return null;
        }

        $byApprover = $rows->groupBy('user_id');
        $historicalSpeedFor = function ($row) use ($byApprover, $rows) {
            $others = $byApprover[$row->user_id]->where('assignment_id', '!=', $row->assignment_id);

            return $others->isEmpty() ? $rows->avg('elapsed_seconds') : $others->avg('elapsed_seconds');
        };

        $samples = [];
        $targets = [];
        foreach ($rows as $row) {
            $samples[] = [$historicalSpeedFor($row), Carbon::parse($row->created_at)->dayOfWeek];
            $targets[] = (float) $row->elapsed_seconds;
        }

        try {
            $mae = $this->crossValidatedMae($samples, $targets);

            // The FINAL model is trained on every row, not just whatever
            // fraction happened to land in one fold — same reasoning as
            // ClassificationService::train(): the cross-validation above
            // exists purely to grade the result honestly, the folds are
            // discarded once that's done, and the deployed model
            // shouldn't be built from a smaller slice than what's
            // actually available.
            $regression = new RidgeRegression(self::RIDGE_LAMBDA);
            $regression->train($samples, $targets);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        $existing = MlTimeEstimateModel::activeFor($category, $department);
        if ($existing && $existing->mae_seconds <= $mae) {
            return null; // the existing model is at least as good — don't churn
        }

        MlTimeEstimateModel::where('ml_category', $category)->where('department', $department)
            ->update(['is_active' => false]);

        $model = MlTimeEstimateModel::create([
            'ml_category' => $category,
            'department' => $department,
            'version' => 'v'.now()->format('Ymd.His'),
            'intercept' => $regression->getIntercept(),
            'coefficients' => $regression->getCoefficients(),
            'mae_seconds' => $mae,
            'training_sample_count' => $rows->count(),
            'is_active' => true,
            'trained_at' => now(),
        ]);

        MlModelTrained::dispatch();

        return $model;
    }

    /**
     * 5-fold cross-validation — mirrors ClassificationService::
     * estimateAccuracyViaCrossValidation() exactly, just measuring mean
     * absolute error instead of a right/wrong rate: shuffle, split into
     * 5 groups, and run 5 rounds where each group takes its turn being
     * held out as the test set while a throwaway Ridge model trains on
     * the other 4. Every row's prediction error (across whichever round
     * it was the held-out test row) gets pooled into one list, and the
     * final MAE is the average of that whole pooled list — not an
     * average of 5 separate per-fold averages, and not a single one-time
     * 80/20 split that permanently sacrifices a fixed slice of an
     * already-small dataset to testing.
     *
     * @param  array<int, array<int, float>>  $samples
     * @param  array<int, float>  $targets
     */
    private function crossValidatedMae(array $samples, array $targets): int
    {
        $count = count($samples);
        $folds = min(5, $count);

        // Fixed seed, so grading the same history always produces the same MAE.
        // The new-vs-active comparison in trainFor() ("is this model actually
        // better?") and the stored mae_seconds both depend on this number;
        // with an unpinned shuffle two runs over identical data could grade
        // differently, and a model could win or lose that comparison purely
        // by how the folds happened to fall. Reseeded from entropy afterward
        // so nothing else in the same process is affected.
        $shuffled = range(0, $count - 1);
        mt_srand(self::CROSS_VALIDATION_SEED);
        shuffle($shuffled);
        mt_srand();

        $foldIndices = array_fill(0, $folds, []);
        foreach ($shuffled as $position => $sampleIndex) {
            $foldIndices[$position % $folds][] = $sampleIndex;
        }

        $absErrors = [];
        for ($testFold = 0; $testFold < $folds; $testFold++) {
            $testIndices = $foldIndices[$testFold];
            $trainIndices = [];
            foreach ($foldIndices as $f => $indices) {
                if ($f !== $testFold) {
                    $trainIndices = array_merge($trainIndices, $indices);
                }
            }

            if (empty($testIndices) || empty($trainIndices)) {
                continue; // degenerate fold (can happen at the small end) — skip rather than crash
            }

            $regression = new RidgeRegression(self::RIDGE_LAMBDA);
            $regression->train(
                array_map(fn ($i) => $samples[$i], $trainIndices),
                array_map(fn ($i) => $targets[$i], $trainIndices)
            );

            $predictions = $regression->predict(array_map(fn ($i) => $samples[$i], $testIndices));
            $predictions = is_array($predictions) ? $predictions : [$predictions];

            foreach (array_values($testIndices) as $position => $sampleIndex) {
                $absErrors[] = abs($predictions[$position] - $targets[$sampleIndex]);
            }
        }

        return (int) round(array_sum($absErrors) / count($absErrors));
    }

    /**
     * The predicted elapsed business-seconds for the very next decision,
     * using whichever eligible approver is currently slowest (same
     * "unanimous approval waits on the slowest" reasoning as
     * ApprovalForecastService's queue-depth padding) — or null if no
     * trained model exists yet for this (category, department) combo, or
     * none of the eligible approvers has decision history to feed it.
     */
    public function predictNextDecision(string $category, string $department, Collection $eligibleApprovers): ?int
    {
        $model = MlTimeEstimateModel::activeFor($category, $department);
        if (! $model) {
            return null;
        }

        $slowestSpeed = $eligibleApprovers
            ->map(fn ($approver) => $this->historicalSpeedFor($approver->user_id, $category, $department))
            ->filter()
            ->max();

        if ($slowestSpeed === null) {
            return null;
        }

        return max(0, (int) round($model->predict([$slowestSpeed, now()->dayOfWeek])));
    }

    private function historicalSpeedFor(int $userId, string $category, string $department): ?float
    {
        $rows = $this->rows()
            ->where('user_id', $userId)
            ->where('ml_category', $category)
            ->where('department', $department);

        return $rows->isEmpty() ? null : $rows->avg('elapsed_seconds');
    }

    /**
     * Every real (human, non-auto-approved) APPROVAL ever made by an
     * approver with a department set, with the business-hours-aware
     * elapsed time already computed per row. Similar raw material to
     * PerformanceInsightsService::decisions() (same joins/business-hours
     * treatment), but deliberately narrower: that one intentionally still
     * counts rejections too (it measures general decision responsiveness,
     * not specifically how fast someone approves), while this model
     * predicts approval time specifically — a reject isn't the same
     * behavior, so it's excluded here rather than shared wholesale.
     * Department is required (not merely preferred, as in
     * ApprovalForecastService's fallback) because this model is keyed
     * on it.
     */
    private function rows(): Collection
    {
        if ($this->rowsCache !== null) {
            return $this->rowsCache;
        }

        $rows = DocumentAssignment::query()
            ->join('users', 'document_assignments.user_id', '=', 'users.user_id')
            ->join('document_repository', 'document_assignments.document_id', '=', 'document_repository.document_id')
            ->join('workflow_stages', 'document_assignments.stage_id', '=', 'workflow_stages.stage_id')
            ->whereNotNull('document_assignments.acted_at')
            ->where('document_assignments.auto_approved', false)
            // Approvals only, not rejections — same reasoning as
            // ApprovalForecastService::estimateStageSeconds()'s identical
            // filter: this model predicts approval time specifically, and
            // a reject measures different behavior from a genuine approve.
            ->where('document_assignments.individual_status', 'approved')
            ->whereNotNull('users.department')
            ->get([
                'document_assignments.assignment_id',
                'document_assignments.document_id',
                'document_assignments.user_id',
                'document_assignments.created_at',
                'document_assignments.acted_at',
                'users.department',
                'document_repository.ml_category',
                'workflow_stages.stage_name',
            ]);

        $starts = DecisionTiming::startTimes($rows);

        return $this->rowsCache = $rows->map(function ($row) use ($starts) {
            $row->elapsed_seconds = $this->businessHours->businessSecondsRemaining(
                $starts[$row->assignment_id],
                Carbon::parse($row->acted_at)
            );

            return $row;
        });
    }
}
