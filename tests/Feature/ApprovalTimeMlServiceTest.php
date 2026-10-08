<?php

use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\MlTimeEstimateModel;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\ApprovalForecastService;
use App\Services\ApprovalTimeMlService;
use App\Services\WorkflowService;
use Carbon\Carbon;

/** Cycled across several real weekdays so the day-of-week feature actually varies — an all-identical column makes the regression's matrix singular. */
const ML_TEST_DAYS = ['2026-08-10 10:00:00', '2026-08-11 10:00:00', '2026-08-12 10:00:00', '2026-08-13 10:00:00', '2026-08-14 10:00:00'];

function mlDecidedAssignment(User $originator, User $approver, string $category, int $dayIndex, int $elapsedMinutes): DocumentAssignment
{
    test()->travelTo(Carbon::parse(ML_TEST_DAYS[$dayIndex % count(ML_TEST_DAYS)]));

    $stage = WorkflowStage::firstOrCreate(
        ['document_category' => $category, 'stage_name' => 'Only Stage'],
        ['sequence_order' => 1]
    );

    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'ml.txt',
        'file_path' => 'documents/ml.txt',
        'mime_type' => 'text/plain',
        'ml_category' => $category,
        'is_validated' => true,
        'due_date' => now()->addDays(3),
        'global_status' => 'classified_validated',
    ]);

    return DocumentAssignment::create([
        'document_id' => $document->document_id,
        'user_id' => $approver->user_id,
        'stage_id' => $stage->stage_id,
        'due_date' => $document->due_date,
        'priority_rank' => 2,
        'individual_status' => 'approved',
        'sla_expires_at' => now()->addHours(4),
        'acted_at' => now()->addMinutes($elapsedMinutes),
        'auto_approved' => false,
    ]);
}

/**
 * Both created_at AND acted_at fall outside business hours (9 PM), so
 * businessSecondsRemaining() measures exactly 0 regardless of how many
 * real wall-clock minutes passed — the exact "off-hours testing" pattern
 * that produced a misleadingly-confident 0-second model in real use.
 */
function offHoursAssignment(User $originator, User $approver, string $category, int $dayIndex, int $elapsedMinutes): DocumentAssignment
{
    test()->travelTo(Carbon::parse(ML_TEST_DAYS[$dayIndex % count(ML_TEST_DAYS)])->setTime(21, 0));

    $stage = WorkflowStage::firstOrCreate(
        ['document_category' => $category, 'stage_name' => 'Only Stage'],
        ['sequence_order' => 1]
    );

    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'ml-offhours.txt',
        'file_path' => 'documents/ml-offhours.txt',
        'mime_type' => 'text/plain',
        'ml_category' => $category,
        'is_validated' => true,
        'due_date' => now()->addDays(3),
        'global_status' => 'classified_validated',
    ]);

    return DocumentAssignment::create([
        'document_id' => $document->document_id,
        'user_id' => $approver->user_id,
        'stage_id' => $stage->stage_id,
        'due_date' => $document->due_date,
        'priority_rank' => 2,
        'individual_status' => 'approved',
        'sla_expires_at' => now()->addHours(4),
        'acted_at' => now()->addMinutes($elapsedMinutes),
        'auto_approved' => false,
    ]);
}

/** 24 decisions split between a fast and a slow approver, cycled across 5 different weekdays — comfortably over the 20-sample training floor with real feature variance. */
function seedTrainableCombo(string $category = 'Job Order', string $department = 'Engineering'): array
{
    $originator = User::factory()->originator()->create();
    $fast = User::factory()->approver($category)->create(['department' => $department]);
    $slow = User::factory()->approver($category)->create(['department' => $department]);

    for ($i = 0; $i < 12; $i++) {
        mlDecidedAssignment($originator, $fast, $category, $i, 5);
        mlDecidedAssignment($originator, $slow, $category, $i, 30);
    }

    return compact('originator', 'fast', 'slow');
}

test('trainableGroups is empty below the minimum sample count and includes the combo once it is reached', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create(['department' => 'Engineering']);

    for ($i = 0; $i < ApprovalTimeMlService::MIN_TRAINING_SAMPLES - 1; $i++) {
        mlDecidedAssignment($originator, $approver, 'Job Order', $i, 10);
    }
    expect(app(ApprovalTimeMlService::class)->trainableGroups())->toBeEmpty();

    mlDecidedAssignment($originator, $approver, 'Job Order', 0, 10);
    $groups = app(ApprovalTimeMlService::class)->trainableGroups();

    expect($groups)->toHaveCount(1)
        ->and($groups[0])->toBe(['ml_category' => 'Job Order', 'department' => 'Engineering']);
});

test('trainFor refuses to train below the minimum sample count', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create(['department' => 'Engineering']);
    mlDecidedAssignment($originator, $approver, 'Job Order', 0, 10);

    expect(app(ApprovalTimeMlService::class)->trainFor('Job Order', 'Engineering'))->toBeNull();
    expect(MlTimeEstimateModel::count())->toBe(0);
});

test('refuses to train when every decision on record measured exactly 0 business seconds', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create(['department' => 'Engineering']);

    for ($i = 0; $i < ApprovalTimeMlService::MIN_TRAINING_SAMPLES; $i++) {
        offHoursAssignment($originator, $approver, 'Job Order', $i, 10);
    }

    expect(app(ApprovalTimeMlService::class)->trainFor('Job Order', 'Engineering'))->toBeNull();
    expect(MlTimeEstimateModel::count())->toBe(0);
});

test('still refuses to train when non-zero decisions exist but fall short of the training floor themselves', function () {
    // 9 off-hours (zero) + 1 real — passes the raw row-count floor (10
    // total) but only has 1 GENUINELY non-zero reading, nowhere near the
    // training floor. A milder, more realistic version of "mostly
    // contaminated" than the all-zero extreme the test above covers.
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create(['department' => 'Engineering']);

    for ($i = 0; $i < ApprovalTimeMlService::MIN_TRAINING_SAMPLES - 1; $i++) {
        offHoursAssignment($originator, $approver, 'Job Order', $i, 10);
    }
    mlDecidedAssignment($originator, $approver, 'Job Order', 0, 10);

    expect(app(ApprovalTimeMlService::class)->trainFor('Job Order', 'Engineering'))->toBeNull();
});

test('trains normally once the training floor is met by genuinely non-zero decisions, even with extra off-hours ones mixed in', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create(['department' => 'Engineering']);

    // A full training floor's worth of REAL decisions...
    for ($i = 0; $i < ApprovalTimeMlService::MIN_TRAINING_SAMPLES; $i++) {
        mlDecidedAssignment($originator, $approver, 'Job Order', $i, 10);
    }
    // ...plus a few contaminated off-hours ones mixed in — real Ridge
    // Regression noise-tolerance handles a minority of these fine once
    // there's already enough genuine signal to trust.
    for ($i = 0; $i < 3; $i++) {
        offHoursAssignment($originator, $approver, 'Job Order', $i, 10);
    }

    expect(app(ApprovalTimeMlService::class)->trainFor('Job Order', 'Engineering'))->not->toBeNull();
});

test('trains successfully right at the (now lower) minimum sample count, exercising 5-fold cross-validation at its smallest fold sizes', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create(['department' => 'Engineering']);

    for ($i = 0; $i < ApprovalTimeMlService::MIN_TRAINING_SAMPLES; $i++) {
        mlDecidedAssignment($originator, $approver, 'Job Order', $i, 10);
    }

    $model = app(ApprovalTimeMlService::class)->trainFor('Job Order', 'Engineering');

    expect($model)->not->toBeNull()
        // The FINAL model trains on every row, not a held-back 80% slice
        // — see trainFor()'s own docblock update.
        ->and($model->training_sample_count)->toBe(ApprovalTimeMlService::MIN_TRAINING_SAMPLES);
});

test('trainFor fits and activates a model once there is enough real decision history', function () {
    seedTrainableCombo();

    $model = app(ApprovalTimeMlService::class)->trainFor('Job Order', 'Engineering');

    expect($model)->not->toBeNull()
        ->and($model->is_active)->toBeTrue()
        ->and($model->training_sample_count)->toBe(24)
        ->and($model->coefficients)->toHaveCount(2)
        // The fast/slow split is deterministic (no noise), so a correctly
        // fit model should predict close to the truth on held-out data.
        ->and($model->mae_seconds)->toBeLessThan(120);
});

test('trainFor does not replace an existing model that is already at least as good', function () {
    seedTrainableCombo();

    // An unbeatable existing model — nothing trained from noisy real data
    // can score a lower (better) MAE than zero.
    MlTimeEstimateModel::create([
        'ml_category' => 'Job Order', 'department' => 'Engineering', 'version' => 'v-existing',
        'intercept' => 0, 'coefficients' => [1, 0], 'mae_seconds' => 0,
        'training_sample_count' => 24, 'is_active' => true, 'trained_at' => now(),
    ]);

    $result = app(ApprovalTimeMlService::class)->trainFor('Job Order', 'Engineering');

    expect($result)->toBeNull();
    expect(MlTimeEstimateModel::where('version', 'v-existing')->first()->is_active)->toBeTrue();
});

test('predictNextDecision returns null when no model is active for the combo', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create(['department' => 'Engineering']);

    $prediction = app(ApprovalTimeMlService::class)
        ->predictNextDecision('Job Order', 'Engineering', collect([$approver]));

    expect($prediction)->toBeNull();
});

test('predictNextDecision uses whichever eligible approver has the slower historical average', function () {
    ['fast' => $fast, 'slow' => $slow] = seedTrainableCombo();
    app(ApprovalTimeMlService::class)->trainFor('Job Order', 'Engineering');

    $predictedFastOnly = app(ApprovalTimeMlService::class)
        ->predictNextDecision('Job Order', 'Engineering', collect([$fast]));
    $predictedBoth = app(ApprovalTimeMlService::class)
        ->predictNextDecision('Job Order', 'Engineering', collect([$fast, $slow]));

    expect($predictedFastOnly)->not->toBeNull()
        ->and($predictedBoth)->not->toBeNull()
        // Pairing the fast approver with the slow one should never predict
        // FASTER than the fast approver alone — the slow one can only pull
        // the "worst eligible approver" prediction up, never down.
        ->and($predictedBoth)->toBeGreaterThanOrEqual($predictedFastOnly);
});

test('the scheduled command trains every eligible combo and reports how many models it activated', function () {
    seedTrainableCombo();

    $this->artisan('ml:train-time-estimator')
        ->expectsOutputToContain('1 eligible combo(s) checked, 1 model(s)')
        ->assertExitCode(0);

    expect(MlTimeEstimateModel::where('is_active', true)->count())->toBe(1);
});

test('ApprovalForecastService uses the trained model for the immediate next stage once one is active', function () {
    seedTrainableCombo();
    app(ApprovalTimeMlService::class)->trainFor('Job Order', 'Engineering');

    $originator = User::factory()->originator()->create();
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'live.txt',
        'file_path' => 'documents/live.txt',
        'mime_type' => 'text/plain',
        'ml_category' => 'Job Order',
        'is_validated' => true,
        'due_date' => now()->addDays(3),
        'global_status' => 'classified_validated',
    ]);
    app(WorkflowService::class)->routeToWorkflow($document);

    $estimate = app(ApprovalForecastService::class)->estimateFor($document->fresh());

    expect($estimate)->not->toBeNull()
        ->and($estimate->totalSeconds)->toBeGreaterThan(0);
});

test('the ML Training page shows the Estimated Approval Time status with no train-now control', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create(['department' => 'Engineering']);
    mlDecidedAssignment($originator, $approver, 'Job Order', 0, 10);

    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->get(route('admin.ml.training'));

    $response->assertOk()
        ->assertSee('Estimated Approval Time')
        ->assertSee('Job Order')
        ->assertSee('1/'.ApprovalTimeMlService::MIN_TRAINING_SAMPLES);
});

test('cross-validation grades the same history identically every time, whatever the global random state', function () {
    $service = app(ApprovalTimeMlService::class);
    $crossValidatedMae = new ReflectionMethod($service, 'crossValidatedMae');

    // Noisy on purpose — with clean data every fold layout scores the same, which
    // would let an unpinned shuffle pass this test by accident.
    $samples = [];
    $targets = [];
    foreach (range(0, 29) as $i) {
        $samples[] = [1800 + ($i * 137) % 900, $i % 7];
        $targets[] = 600 + (($i * 7919) % 1500);
    }

    mt_srand(1);
    $first = $crossValidatedMae->invoke($service, $samples, $targets);
    mt_srand(999);
    $second = $crossValidatedMae->invoke($service, $samples, $targets);
    mt_srand(31337);
    $third = $crossValidatedMae->invoke($service, $samples, $targets);

    expect($second)->toBe($first)->and($third)->toBe($first);
});

test('a Final Approval seat routed up front (old behavior) is timed from when the other stages finished, not from routing', function () {
    $this->travelTo(Carbon::parse('2026-08-12 09:00:00')); // Wednesday, business hours
    $originator = User::factory()->originator()->create();
    $reviewer = User::factory()->approver('Job Order')->create(['department' => 'Engineering']);
    $head = User::factory()->approver('Job Order')->create(['department' => 'Engineering', 'level' => 'head']);
    $reviewStage = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Technical Review', 'sequence_order' => 1]);
    $finalStage = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Final Approval', 'sequence_order' => 2]);
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'legacy.txt', 'file_path' => 'documents/legacy.txt',
        'mime_type' => 'text/plain', 'ml_category' => 'Job Order', 'is_validated' => true,
        'due_date' => now()->addDay(), 'global_status' => 'approved',
    ]);

    // Both seats created at 09:00 (all stages routed together); review decided 10:00, Final 12:00.
    foreach ([[$reviewStage, $reviewer, '10:00:00'], [$finalStage, $head, '12:00:00']] as [$stage, $user, $actedAt]) {
        (new DocumentAssignment)->forceFill([
            'document_id' => $document->document_id, 'user_id' => $user->user_id, 'stage_id' => $stage->stage_id,
            'due_date' => $document->due_date, 'priority_rank' => 2, 'individual_status' => 'approved', 'auto_approved' => false,
            'sla_expires_at' => '2026-08-12 15:00:00', 'acted_at' => "2026-08-12 {$actedAt}",
            'created_at' => '2026-08-12 09:00:00', 'updated_at' => '2026-08-12 09:00:00',
        ])->save();
    }

    $rows = (new ReflectionMethod(app(ApprovalTimeMlService::class), 'rows'))->invoke(app(ApprovalTimeMlService::class));
    $byUser = $rows->keyBy('user_id');

    expect((int) $byUser[$reviewer->user_id]->elapsed_seconds)->toBe(3600)   // review: 09:00 -> 10:00
        ->and((int) $byUser[$head->user_id]->elapsed_seconds)->toBe(7200);   // final: 10:00 -> 12:00, not 3 hours
});

test('a Final Approval decided before the other stages keeps its own start — there was no waiting', function () {
    $this->travelTo(Carbon::parse('2026-08-12 09:00:00'));
    $originator = User::factory()->originator()->create();
    $reviewer = User::factory()->approver('Job Order')->create(['department' => 'Engineering']);
    $head = User::factory()->approver('Job Order')->create(['department' => 'Engineering', 'level' => 'head']);
    $reviewStage = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Technical Review', 'sequence_order' => 1]);
    $finalStage = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Final Approval', 'sequence_order' => 2]);
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'parallel.txt', 'file_path' => 'documents/parallel.txt',
        'mime_type' => 'text/plain', 'ml_category' => 'Job Order', 'is_validated' => true,
        'due_date' => now()->addDay(), 'global_status' => 'approved',
    ]);

    // Old parallel routing: the Head decided at 10:00, BEFORE the review stage (12:00).
    foreach ([[$reviewStage, $reviewer, '12:00:00'], [$finalStage, $head, '10:00:00']] as [$stage, $user, $actedAt]) {
        (new DocumentAssignment)->forceFill([
            'document_id' => $document->document_id, 'user_id' => $user->user_id, 'stage_id' => $stage->stage_id,
            'due_date' => $document->due_date, 'priority_rank' => 2, 'individual_status' => 'approved', 'auto_approved' => false,
            'sla_expires_at' => '2026-08-12 15:00:00', 'acted_at' => "2026-08-12 {$actedAt}",
            'created_at' => '2026-08-12 09:00:00', 'updated_at' => '2026-08-12 09:00:00',
        ])->save();
    }

    $rows = (new ReflectionMethod(app(ApprovalTimeMlService::class), 'rows'))->invoke(app(ApprovalTimeMlService::class));

    expect((int) $rows->firstWhere('user_id', $head->user_id)->elapsed_seconds)->toBe(3600); // 09:00 -> 10:00
});

test('statusForAllGroups excludes a department combo that no longer matches the current workflow config', function () {
    // The only Job Order stage CURRENTLY configured — Engineering only.
    $stage = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Final Approval', 'sequence_order' => 1]);
    $stage->departments()->create(['department' => 'Engineering']);

    $originator = User::factory()->originator()->create();
    $validApprover = User::factory()->approver('Job Order')->create(['department' => 'Engineering']);
    // A department with real past decisions that no longer matches any
    // configured stage for this category — e.g. left over from before a
    // department-routing fix, same real scenario as Service Report once
    // briefly including Finance before being corrected to Engineering-only.
    $staleApprover = User::factory()->approver('Job Order')->create(['department' => 'Marketing']);

    foreach ([$validApprover, $staleApprover] as $approver) {
        $document = DocumentRepository::create([
            'originator_id' => $originator->user_id, 'title' => 'status-'.$approver->user_id.'.txt',
            'file_path' => 'documents/status-'.$approver->user_id.'.txt', 'mime_type' => 'text/plain',
            'ml_category' => 'Job Order', 'is_validated' => true, 'due_date' => now()->addDays(3),
            'global_status' => 'classified_validated',
        ]);
        DocumentAssignment::create([
            'document_id' => $document->document_id, 'user_id' => $approver->user_id, 'stage_id' => $stage->stage_id,
            'due_date' => $document->due_date, 'priority_rank' => 2, 'individual_status' => 'approved',
            'sla_expires_at' => now()->addHours(4), 'acted_at' => now()->addMinutes(10), 'auto_approved' => false,
        ]);
    }

    $statuses = app(ApprovalTimeMlService::class)->statusForAllGroups();

    expect($statuses->pluck('department'))->toContain('Engineering')->not->toContain('Marketing');
});

test('statusForAllGroups still includes a department on a stage that has no department restriction at all', function () {
    // Zero WorkflowStageDepartment rows on this stage means unrestricted
    // — every department stays valid for it, not none (see
    // WorkflowStageDepartment's own docblock).
    $stage = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Only Stage', 'sequence_order' => 1]);

    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create(['department' => 'Engineering']);
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'unrestricted.txt', 'file_path' => 'documents/unrestricted.txt',
        'mime_type' => 'text/plain', 'ml_category' => 'Job Order', 'is_validated' => true,
        'due_date' => now()->addDays(3), 'global_status' => 'classified_validated',
    ]);
    DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => $approver->user_id, 'stage_id' => $stage->stage_id,
        'due_date' => $document->due_date, 'priority_rank' => 2, 'individual_status' => 'approved',
        'sla_expires_at' => now()->addHours(4), 'acted_at' => now()->addMinutes(10), 'auto_approved' => false,
    ]);

    expect(app(ApprovalTimeMlService::class)->statusForAllGroups()->pluck('department'))->toContain('Engineering');
});
