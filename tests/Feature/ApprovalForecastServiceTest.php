<?php

use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\ApprovalForecastService;
use App\Services\ApprovalTimeMlService;
use App\Services\BusinessHoursService;
use App\Services\WorkflowService;
use Carbon\Carbon;

function forecastDoc(User $originator, string $category = 'Job Order', ?Carbon $dueDate = null): DocumentRepository
{
    return DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'forecast.txt',
        'file_path' => 'documents/forecast.txt',
        'mime_type' => 'text/plain',
        'ml_category' => $category,
        'is_validated' => true,
        'due_date' => $dueDate ?? now()->addDay(),
        'global_status' => 'classified_validated',
    ]);
}

test('falls back to the stage\'s own SLA deadline when the category has no historical decisions yet', function () {
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Only Stage', 'sequence_order' => 1]);
    $originator = User::factory()->originator()->create();
    User::factory()->approver('Job Order')->create();

    $document = forecastDoc($originator);
    app(WorkflowService::class)->routeToWorkflow($document);

    $estimate = app(ApprovalForecastService::class)->estimateFor($document->fresh());
    $slaExpiresAt = $document->assignments()->first()->sla_expires_at;

    // No history to average or train from, but there IS a real SLA
    // deadline on the routed seat — that's what gets used instead of
    // going blank. Measured business-hours-aware (businessSecondsRemaining(),
    // not a plain wall-clock diff) since that's how the estimate itself is
    // computed and later re-expanded wherever it's rendered — a plain
    // diff would disagree with it across any non-working stretch.
    // Compared with a tolerance since a couple of seconds pass between
    // routing and this assertion.
    $expectedSeconds = app(BusinessHoursService::class)->businessSecondsRemaining(now(), $slaExpiresAt);
    expect($estimate)->not->toBeNull()
        ->and(abs($estimate->totalSeconds - $expectedSeconds))->toBeLessThan(5);
});

test('falls back to the SLA deadline (not a misleading 0) when every historical decision measured 0 business seconds', function () {
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Only Stage', 'sequence_order' => 1]);
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $workflow = app(WorkflowService::class);

    // A prior document routed AND decided entirely outside business hours
    // (9 PM) — real wall-clock time passed, but none of it was business
    // time, so it measures exactly 0 business seconds. This used to get
    // silently trusted as "the real average," producing a confidently
    // near-0 estimate instead of the honest SLA-deadline fallback.
    test()->travelTo(now()->next('Monday')->setTime(21, 0));
    $history = forecastDoc($originator);
    $workflow->routeToWorkflow($history);
    $workflow->decide(DocumentAssignment::where('document_id', $history->document_id)->first(), $approver, 'approved');

    test()->travelTo(now()->addDay()->setTime(10, 0)); // back to a real business hour
    $current = forecastDoc($originator);
    $workflow->routeToWorkflow($current);
    $slaExpiresAt = DocumentAssignment::where('document_id', $current->document_id)->first()->sla_expires_at;

    $estimate = app(ApprovalForecastService::class)->estimateFor($current->fresh());

    $expectedSeconds = app(BusinessHoursService::class)->businessSecondsRemaining(now(), $slaExpiresAt);
    expect($estimate)->not->toBeNull()
        ->and(abs($estimate->totalSeconds - $expectedSeconds))->toBeLessThan(5);
});

test('returns null for a document with no category yet', function () {
    $originator = User::factory()->originator()->create();
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'unclassified.txt', 'file_path' => 'documents/unclassified.txt',
        'mime_type' => 'text/plain', 'due_date' => now()->addDay(), 'global_status' => 'processing',
    ]);

    expect(app(ApprovalForecastService::class)->estimateFor($document))->toBeNull();
});

test('returns null once the document is already fully resolved', function () {
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Only Stage', 'sequence_order' => 1]);
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $workflow = app(WorkflowService::class);

    $document = forecastDoc($originator);
    $workflow->routeToWorkflow($document);
    $workflow->decide(DocumentAssignment::where('document_id', $document->document_id)->first(), $approver, 'approved');

    expect(app(ApprovalForecastService::class)->estimateFor($document->fresh()))->toBeNull();
});

test('produces a non-null estimate once historical decisions exist for the category', function () {
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Stage One', 'sequence_order' => 1]);
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Stage Two', 'sequence_order' => 2]);
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $workflow = app(WorkflowService::class);

    // A fully-decided prior document establishes historical decision times
    // for this category — without this, there's nothing to average.
    $history = forecastDoc($originator);
    $workflow->routeToWorkflow($history);
    foreach (DocumentAssignment::where('document_id', $history->document_id)->get() as $assignment) {
        $workflow->decide($assignment, $approver, 'approved');
    }

    $current = forecastDoc($originator);
    $workflow->routeToWorkflow($current);

    $estimate = app(ApprovalForecastService::class)->estimateFor($current);

    expect($estimate)->not->toBeNull()
        ->and($estimate->totalSeconds)->toBeGreaterThanOrEqual(0);
});

test('a multi-stage estimate is the SLOWEST pending stage, not every stage added together', function () {
    // Every configured stage routes simultaneously (see WorkflowService::
    // routeToWorkflow()) — Engineering and Finance both go pending on the
    // new document at once, not one waiting for the other. So the whole-
    // document estimate should track whichever department's history is
    // slower, not the sum of both.
    $fastStage = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Fast Stage', 'sequence_order' => 1]);
    $slowStage = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Slow Stage', 'sequence_order' => 2]);

    $originator = User::factory()->originator()->create();
    $fastApprover = User::factory()->approver('Job Order')->create(['department' => 'Engineering']);
    $slowApprover = User::factory()->approver('Job Order')->create(['department' => 'Finance']);
    // Restrict each approver to exactly their own stage — without this,
    // "no explicit stage picks" means eligible for every stage in the
    // category (see eligibleApproversFor()'s docblock), which would let
    // routing assign either approver to either stage unpredictably.
    $fastApprover->workflowStages()->sync([$fastStage->stage_id]);
    $slowApprover->workflowStages()->sync([$slowStage->stage_id]);
    $workflow = app(WorkflowService::class);

    // Every timestamp below is pinned to an explicit travelTo() rather than
    // relying on travelBack() to restore a prior checkpoint — travelBack()
    // resets to the REAL host clock, not to the last travelTo(), which
    // would make the "5 hours" gap measure against an uncontrolled date.
    $this->travelTo(Carbon::parse('2026-08-12 09:00:00')); // Wednesday, business hours

    // Fast department's history: decided almost immediately — 3 rounds
    // (ApprovalForecastService::MIN_NON_ZERO_DECISIONS), not just 1, so
    // the average is actually trusted instead of falling back to the SLA
    // deadline.
    $fastHistoryDocs = [];
    $slowHistoryDocs = [];
    for ($i = 0; $i < 3; $i++) {
        $this->travelTo(Carbon::parse('2026-08-12 09:00:00'));
        $fastHistory = forecastDoc($originator);
        $workflow->routeToWorkflow($fastHistory);
        $this->travelTo(Carbon::parse('2026-08-12 09:30:00'));
        foreach (DocumentAssignment::where('document_id', $fastHistory->document_id)->where('user_id', $fastApprover->user_id)->get() as $a) {
            $workflow->decide($a, $fastApprover, 'approved');
        }
        $fastHistoryDocs[] = $fastHistory;

        // Slow department's history: took several real business hours, same
        // fixed Wednesday so the gap is deterministic.
        $this->travelTo(Carbon::parse('2026-08-12 09:00:00'));
        $slowHistory = forecastDoc($originator);
        $workflow->routeToWorkflow($slowHistory);
        $this->travelTo(Carbon::parse('2026-08-12 14:00:00'));
        foreach (DocumentAssignment::where('document_id', $slowHistory->document_id)->where('user_id', $slowApprover->user_id)->get() as $a) {
            $workflow->decide($a, $slowApprover, 'approved');
        }
        $slowHistoryDocs[] = $slowHistory;
    }

    // The histories' OTHER stage assignments (the ones each approver
    // wasn't eligible for) are left pending — resolve those too so they
    // don't pollute this category's later queue-depth counts.
    DocumentAssignment::whereIn('document_id', collect([...$fastHistoryDocs, ...$slowHistoryDocs])->pluck('document_id'))
        ->where('individual_status', 'pending')
        ->get()
        ->each(fn ($a) => $workflow->decide($a, $a->approver, 'approved'));

    // A distant due date, so the SLA window is the full 6-hour maximum — a
    // 1-day due date only earns a ~2-hour window, which would (rightly) cap
    // the slow stage's ~5h average.
    $current = forecastDoc($originator, dueDate: now()->addDays(30));
    $workflow->routeToWorkflow($current);

    $estimate = app(ApprovalForecastService::class)->estimateFor($current->fresh());

    expect($estimate)->not->toBeNull()
        // Comfortably north of the fast stage's own ~30min average, and in
        // the neighborhood of the slow stage's ~5h average — not anywhere
        // near their SUM (~5.5h would already be close to the max here,
        // so the real signal is that it's nowhere near double-counted).
        ->and($estimate->totalSeconds)->toBeGreaterThan(3 * 3600)
        ->and($estimate->totalSeconds)->toBeLessThan(6 * 3600);
});

test('pads the estimate for a next-stage approver who already has a deep pending queue', function () {
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Only Stage', 'sequence_order' => 1]);
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $workflow = app(WorkflowService::class);
    $forecast = app(ApprovalForecastService::class);

    // Wednesday mid-morning — a fixed, known-safe business-hours moment so
    // the 1-hour gap below lands entirely inside the same working window
    // regardless of when the test suite itself happens to run.
    $this->travelTo(Carbon::parse('2026-08-12 10:00:00'));

    // History so avg-per-stage isn't null AND non-zero — 3 rounds
    // (ApprovalForecastService::MIN_NON_ZERO_DECISIONS), each decided a
    // simulated hour after routing, so the average is actually trusted
    // (not just falling back to the SLA deadline) and the queue-depth
    // padding below has something non-trivial to multiply against.
    for ($i = 0; $i < 3; $i++) {
        $history = forecastDoc($originator);
        $workflow->routeToWorkflow($history);
        $this->travel(1)->hours();
        $workflow->decide(DocumentAssignment::where('document_id', $history->document_id)->first(), $approver, 'approved');
        // Re-freeze back to the SAME fixed Wednesday morning for the next
        // round — travelBack() doesn't "undo the travel() above," it
        // cancels time-freezing entirely and returns to the real current
        // wall-clock moment, which defeated the whole point of freezing
        // time in the first place (confirmed via debug output: only the
        // first of these 3 rounds ever measured a non-zero business-hours
        // gap — rounds 2 and 3 were silently computing their elapsed time
        // against whatever the real time happened to be when the suite
        // ran, not this fixed business-hours window).
        $this->travelTo(Carbon::parse('2026-08-12 10:00:00'));
    }

    $current = forecastDoc($originator);
    $workflow->routeToWorkflow($current);
    $baseline = $forecast->estimateFor($current);

    // Stack a bunch of unrelated pending work onto the sole eligible
    // approver, then measure the same document's estimate again — the
    // padded estimate should come back strictly higher than the baseline.
    for ($i = 0; $i < 5; $i++) {
        $busyDoc = forecastDoc($originator);
        $workflow->routeToWorkflow($busyDoc);
    }
    $padded = $forecast->estimateFor($current->fresh());

    expect($baseline)->not->toBeNull()
        ->and($padded)->not->toBeNull()
        ->and($padded->totalSeconds)->toBeGreaterThan($baseline->totalSeconds);
});

test('the rendered "Est. Approval by" respects business hours instead of raw wall-clock addition', function () {
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Only Stage', 'sequence_order' => 1]);
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $workflow = app(WorkflowService::class);

    // Friday, 15 minutes before closing — a big enough estimate (below)
    // spills well past 5 PM, which is exactly what a plain
    // now()->addSeconds() would have shown before this was fixed.
    $this->travelTo(Carbon::parse('2026-08-14 16:45:00'));

    // Historical decision made Saturday at noon — since the average is now
    // itself business-hours-aware, this is Friday's remaining 15 minutes
    // plus 3 real business hours of Saturday, comfortably enough to guarantee
    // the projected estimate crosses out of today's window.
    $history = forecastDoc($originator);
    $workflow->routeToWorkflow($history);
    $this->travelTo(Carbon::parse('2026-08-15 12:00:00'));
    $workflow->decide(DocumentAssignment::where('document_id', $history->document_id)->first(), $approver, 'approved');

    $this->travelTo(Carbon::parse('2026-08-14 16:45:00'));

    $current = forecastDoc($originator);
    $workflow->routeToWorkflow($current);

    $response = $this->actingAs($approver)->get(route('approver.dashboard'));
    $response->assertOk();

    preg_match('/Est\. Approval by:<\/span>\s*([^<]+)</', $response->getContent(), $matches);
    expect($matches)->toHaveCount(2);

    $renderedMoment = Carbon::parse(trim($matches[1]));

    expect(app(BusinessHoursService::class)->isWithinWorkingWindow($renderedMoment))->toBeTrue();
});

test('the "Est. Approval by" estimate never exceeds the document\'s own due date', function () {
    // Regression: ApprovalForecastService averages REAL historical
    // decision times — a sparse/slow decision history (e.g. early on,
    // before real usage settles into a consistent pace) can inflate that
    // average enough that the raw forecast lands weeks or months past the
    // document's own due date, which is a nonsensical thing to show next
    // to a due date that's only a day or two away.
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Only Stage', 'sequence_order' => 1]);
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $workflow = app(WorkflowService::class);

    $this->travelTo(Carbon::parse('2026-08-12 10:00:00')); // Wednesday, business hours

    // A wildly slow historical decision (30 days) — inflates
    // avgSecondsPerStage enough that the raw forecast would land weeks
    // past the current document's own due date if left unclamped.
    $history = forecastDoc($originator);
    $workflow->routeToWorkflow($history);
    $this->travel(30)->days();
    $workflow->decide(DocumentAssignment::where('document_id', $history->document_id)->first(), $approver, 'approved');

    $this->travelTo(Carbon::parse('2026-08-12 10:00:00'));

    // Due in 1 day (forecastDoc()'s default) — the raw (unclamped)
    // forecast would blow well past this.
    $current = forecastDoc($originator);
    $workflow->routeToWorkflow($current);

    $response = $this->actingAs($approver)->get(route('approver.dashboard'));
    $response->assertOk();

    preg_match('/Est\. Approval by:<\/span>\s*([^<]+)</', $response->getContent(), $matches);
    expect($matches)->toHaveCount(2);

    $renderedMoment = Carbon::parse(trim($matches[1]));

    expect($renderedMoment->lessThanOrEqualTo($current->fresh()->due_date))->toBeTrue();
});

test('the estimate adds Final Approval after the slowest review stage, because it only opens once the others are approved', function () {
    $review = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Review Stage', 'sequence_order' => 1]);
    $final = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Final Approval', 'sequence_order' => 2]);

    $originator = User::factory()->originator()->create();
    $reviewer = User::factory()->approver('Job Order')->create(['level' => 'staff', 'department' => 'Engineering']);
    $head = User::factory()->approver('Job Order')->create(['level' => 'head', 'department' => 'Engineering']);
    $reviewer->workflowStages()->sync([$review->stage_id]);
    $head->workflowStages()->sync([$final->stage_id]);

    $this->travelTo(Carbon::parse('2026-08-12 09:00:00')); // Wednesday, business hours

    // Real history: the review stage takes 1 business hour, Final Approval 2.
    foreach (range(1, 3) as $i) {
        $historyDoc = forecastDoc($originator);
        // New-style history: Final Approval's seat is created when the review stage is
        // resolved (10:00), not up front at routing, so it measures the Head's own time.
        foreach ([[$review, $reviewer, '09:00:00', '10:00:00'], [$final, $head, '10:00:00', '12:00:00']] as [$stage, $user, $createdAt, $actedAt]) {
            $seat = new DocumentAssignment;
            $seat->forceFill([
                'document_id' => $historyDoc->document_id, 'user_id' => $user->user_id, 'stage_id' => $stage->stage_id,
                'due_date' => $historyDoc->due_date, 'priority_rank' => 2, 'individual_status' => 'approved',
                'auto_approved' => false, 'sla_expires_at' => '2026-08-12 15:00:00',
                'acted_at' => "2026-08-12 {$actedAt}", 'created_at' => "2026-08-12 {$createdAt}", 'updated_at' => "2026-08-12 {$createdAt}",
            ])->save();
        }
    }

    $current = forecastDoc($originator);
    app(WorkflowService::class)->routeToWorkflow($current);
    // Only the review seat exists; Final Approval has not opened yet.
    expect(DocumentAssignment::where('document_id', $current->document_id)->count())->toBe(1);

    $estimate = app(ApprovalForecastService::class)->estimateFor($current->fresh());

    // 1h (review) + 2h (Final Approval) = ~3h — not just the 2h slowest stage.
    expect($estimate)->not->toBeNull()
        ->and($estimate->totalSeconds)->toBeGreaterThan(2.9 * 3600)
        ->and($estimate->totalSeconds)->toBeLessThan(3.1 * 3600);
});

test('an average slower than the stage\'s SLA window is cut back to the window', function () {
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Only Stage', 'sequence_order' => 1]);
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $workflow = app(WorkflowService::class);

    // Three past documents with a distant due date (6-hour windows), each
    // decided after 5 working hours — a real, trusted 5-hour average.
    foreach (range(1, 3) as $i) {
        $this->travelTo(Carbon::parse('2026-08-12 09:00:00'));
        $history = forecastDoc($originator, dueDate: now()->addDays(30));
        $workflow->routeToWorkflow($history);
        $this->travelTo(Carbon::parse('2026-08-12 14:00:00'));
        $workflow->decide(DocumentAssignment::where('document_id', $history->document_id)->first(), $approver, 'approved');
    }

    // A tight document: due end of the same day, so its window is far shorter than 5h.
    $this->travelTo(Carbon::parse('2026-08-13 09:00:00'));
    $tight = forecastDoc($originator, dueDate: Carbon::parse('2026-08-13 12:00:00'));
    $workflow->routeToWorkflow($tight);

    $seat = $tight->assignments()->first();
    $windowSeconds = app(BusinessHoursService::class)->businessSecondsRemaining(now(), $seat->sla_expires_at);
    $estimate = app(ApprovalForecastService::class)->estimateFor($tight->fresh());

    expect($windowSeconds)->toBeLessThan(5 * 3600)
        ->and($estimate)->not->toBeNull()
        ->and($estimate->totalSeconds)->toBeLessThanOrEqual($windowSeconds + 1);
});

test('an average faster than the stage\'s SLA window is left alone', function () {
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Only Stage', 'sequence_order' => 1]);
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $workflow = app(WorkflowService::class);

    foreach (range(1, 3) as $i) {
        $this->travelTo(Carbon::parse('2026-08-12 09:00:00'));
        $history = forecastDoc($originator, dueDate: now()->addDays(30));
        $workflow->routeToWorkflow($history);
        $this->travelTo(Carbon::parse('2026-08-12 09:30:00'));
        $workflow->decide(DocumentAssignment::where('document_id', $history->document_id)->first(), $approver, 'approved');
    }

    $this->travelTo(Carbon::parse('2026-08-13 09:00:00'));
    $current = forecastDoc($originator, dueDate: now()->addDays(30));
    $workflow->routeToWorkflow($current);

    $estimate = app(ApprovalForecastService::class)->estimateFor($current->fresh());

    // The 30-minute average, not the (much longer) 6-hour window.
    expect($estimate->totalSeconds)->toBeGreaterThan(29 * 60)
        ->and($estimate->totalSeconds)->toBeLessThan(31 * 60);
});

test('the queue padding cannot push the estimate past the stage\'s SLA window', function () {
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Only Stage', 'sequence_order' => 1]);
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $workflow = app(WorkflowService::class);

    foreach (range(1, 3) as $i) {
        $this->travelTo(Carbon::parse('2026-08-12 09:00:00'));
        $history = forecastDoc($originator, dueDate: now()->addDays(30));
        $workflow->routeToWorkflow($history);
        $this->travelTo(Carbon::parse('2026-08-12 11:00:00'));
        $workflow->decide(DocumentAssignment::where('document_id', $history->document_id)->first(), $approver, 'approved');
    }

    $this->travelTo(Carbon::parse('2026-08-13 09:00:00'));
    $current = forecastDoc($originator, dueDate: now()->addDays(30));
    $workflow->routeToWorkflow($current);
    // 2h average x (1 + 6 waiting documents) = 14h, far past a 6-hour window.
    foreach (range(1, 6) as $i) {
        $workflow->routeToWorkflow(forecastDoc($originator, dueDate: now()->addDays(30)));
    }

    $windowSeconds = app(BusinessHoursService::class)->businessSecondsRemaining(now(), $current->assignments()->first()->sla_expires_at);
    $estimate = app(ApprovalForecastService::class)->estimateFor($current->fresh());

    expect($estimate->totalSeconds)->toBeLessThanOrEqual($windowSeconds + 1);
});

test('a Final Approval that has not opened yet still counts in the total when there is no history', function () {
    $review = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Review Stage', 'sequence_order' => 1]);
    $final = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Final Approval', 'sequence_order' => 2]);
    $originator = User::factory()->originator()->create();
    $reviewer = User::factory()->approver('Job Order')->create(['level' => 'staff', 'department' => 'Engineering']);
    $head = User::factory()->approver('Job Order')->create(['level' => 'head', 'department' => 'Engineering']);
    $reviewer->workflowStages()->sync([$review->stage_id]);
    $head->workflowStages()->sync([$final->stage_id]);

    $this->travelTo(Carbon::parse('2026-08-12 09:00:00'));
    $current = forecastDoc($originator, dueDate: now()->addDays(30));
    app(WorkflowService::class)->routeToWorkflow($current);
    expect(DocumentAssignment::where('document_id', $current->document_id)->count())->toBe(1);

    $reviewWindow = app(BusinessHoursService::class)->businessSecondsRemaining(now(), $current->assignments()->first()->sla_expires_at);
    $estimate = app(ApprovalForecastService::class)->estimateFor($current->fresh());

    // The review window alone would be the total if Final Approval were left
    // out — Final's own projected window (at least 15 working minutes) is on top.
    expect($estimate->totalSeconds)->toBeGreaterThanOrEqual($reviewWindow + 15 * 60 - 1);
});

test('a document that has not reached its approvers yet still gets an estimate when there is no history', function () {
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Only Stage', 'sequence_order' => 1]);
    $originator = User::factory()->originator()->create();
    User::factory()->approver('Job Order')->create();

    $this->travelTo(Carbon::parse('2026-08-12 09:00:00'));
    $unrouted = forecastDoc($originator, dueDate: now()->addDays(30));
    expect($unrouted->assignments()->count())->toBe(0);

    $estimate = app(ApprovalForecastService::class)->estimateFor($unrouted);

    // Exactly the window it is about to be given: 25% of the working time
    // left, capped at 6 hours.
    expect($estimate)->not->toBeNull()
        ->and($estimate->totalSeconds)->toBe(6 * 3600.0);
});

test('a Final Approval shared by two departments uses the slower of their two trained ML predictions, once both are trained', function () {
    $ml = app(ApprovalTimeMlService::class);
    $originator = User::factory()->originator()->create();

    // Archived, so it never shows up as a configured stage for Job Order
    // (see unresolvedStages()) — purely somewhere to attach the training
    // history's own assignments without it competing as a real review
    // stage alongside the Final Approval stage under test.
    $trainingStage = WorkflowStage::create([
        'document_category' => 'Job Order', 'stage_name' => 'Training History', 'sequence_order' => 99, 'is_archived' => true,
    ]);

    $engineer = User::factory()->approver('Job Order')->create(['department' => 'Engineering']);
    $financier = User::factory()->approver('Job Order')->create(['department' => 'Finance']);

    // Engineering decides fast (2 min); Finance decides much slower (2
    // hours) — deliberately far apart so which one "wins" is unambiguous.
    foreach ([[$engineer, 2], [$financier, 120]] as [$approver, $minutes]) {
        foreach (range(0, ApprovalTimeMlService::MIN_TRAINING_SAMPLES - 1) as $i) {
            $this->travelTo(Carbon::parse(['2026-08-10 10:00:00', '2026-08-11 10:00:00', '2026-08-12 10:00:00', '2026-08-13 10:00:00', '2026-08-14 10:00:00'][$i % 5]));
            $doc = forecastDoc($originator);
            DocumentAssignment::create([
                'document_id' => $doc->document_id, 'user_id' => $approver->user_id, 'stage_id' => $trainingStage->stage_id,
                'due_date' => $doc->due_date, 'priority_rank' => 2, 'individual_status' => 'approved',
                'sla_expires_at' => now()->addHours(4), 'acted_at' => now()->addMinutes($minutes), 'auto_approved' => false,
            ]);
        }
    }

    expect($ml->trainFor('Job Order', 'Engineering'))->not->toBeNull();
    expect($ml->trainFor('Job Order', 'Finance'))->not->toBeNull();

    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Final Approval', 'sequence_order' => 1]);
    $this->travelTo(Carbon::parse('2026-08-17 09:00:00')); // a fresh Monday, clear of the training history above
    $document = forecastDoc($originator, dueDate: now()->addDays(30));

    $estimate = app(ApprovalForecastService::class)->estimateFor($document);

    // Both eligible approvers (Engineer + Financier) have zero OTHER
    // pending work, so queue-depth padding is 0 — the result is purely
    // whichever of the two trained predictions is larger. A plain pooled
    // average across both departments' history would land near an hour;
    // picking up only Engineering's own fast model would land near 2
    // minutes. Neither of those clears 90 minutes — only genuinely
    // picking Finance's (the slower) trained prediction does.
    expect($estimate)->not->toBeNull()
        ->and($estimate->totalSeconds)->toBeGreaterThan(90 * 60);
});
