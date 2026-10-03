<?php

namespace App\Jobs;

use App\Services\ClassificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Event-driven counterpart to AutoTrainClassifier's scheduled sweep (same
 * pattern as RetrainApprovalTimeModel, dispatched right after a real
 * decision lands) — dispatched right after a document is successfully
 * routed (see WorkflowService::ingest()), instead of waiting up to
 * config('ml.auto_train_check_interval_minutes') for the next scheduled
 * check to even notice. The scheduled check stays in place as a genuine
 * safety-net fallback (catches it within a few minutes if this job's queue
 * is ever behind), not the primary trigger anymore. No urgency requirement
 * of its own justifies this — nothing needs the model retrained within
 * seconds — this exists purely so the system isn't polling on a fixed
 * schedule regardless of whether anything actually happened, the same
 * reasoning RetrainApprovalTimeModel's own docblock gives.
 *
 * Safe to dispatch even when nothing is actually due — ClassificationService::
 * autoTrainIfDue() itself no-ops immediately if neither trigger is met, and
 * is guarded by a Cache::lock() against racing the scheduled poll if both
 * land at nearly the same moment.
 */
class CheckAutoTrainDue implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(ClassificationService $classifier): void
    {
        $classifier->autoTrainIfDue();
    }
}
