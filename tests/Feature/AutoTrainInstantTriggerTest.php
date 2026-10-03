<?php

use App\Jobs\CheckAutoTrainDue;
use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\MlStagingSample;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\ClassificationService;
use App\Services\WorkflowService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

/**
 * Coverage for the instant per-document retrain-check trigger (alongside
 * the existing scheduled sweep — see CheckAutoTrainDue's own docblock) and
 * the Cache::lock() guarding ClassificationService::autoTrainIfDue()
 * against that instant trigger and the scheduled poll racing each other.
 */
it('dispatches a CheckAutoTrainDue job after a document is successfully ingested', function () {
    Queue::fake();
    $originator = User::factory()->originator()->create();

    $mock = Mockery::mock(ClassificationService::class);
    $mock->shouldReceive('classify')->andReturn(['category' => 'Job Order', 'confidence' => 90, 'margin' => 10.0, 'model_id' => null]);
    app()->instance(ClassificationService::class, $mock);

    Carbon\Carbon::setTestNow(Carbon\Carbon::parse('2026-08-12 10:00:00')); // Wednesday, business hours

    app(WorkflowService::class)->ingest(
        UploadedFile::fake()->createWithContent('memo.txt', str_repeat('This is a perfectly ordinary memo. ', 5)),
        $originator,
        now()->addHours(4)->toDateTimeString(),
    );

    Queue::assertPushed(CheckAutoTrainDue::class);
});

it('does not run autoTrainIfDue twice at once — the lock makes a concurrent call a no-op instead of racing', function () {
    $keywords = [
        'Job Order' => 'aircon repair plumbing maintenance facilities work order',
        'Purchase Requisition' => 'laptop budget procurement requisition finance approval',
        'Service Report' => 'technician generator inspection findings completed service',
    ];
    foreach ($keywords as $category => $keyword) {
        for ($i = 1; $i <= 5; $i++) {
            MlStagingSample::create([
                'category' => $category,
                'original_filename' => "seed-{$category}-{$i}.txt",
                'extracted_text' => "{$keyword} sample {$i} unique words filler padding text here today.",
            ]);
        }
    }
    $samplesByCategory = MlStagingSample::get()->groupBy('category')
        ->map(fn ($rows) => $rows->pluck('extracted_text')->all())->all();
    app(ClassificationService::class)->train($samplesByCategory);

    config(['ml.auto_train_batch_size' => 1]);
    $originator = User::factory()->originator()->create();
    $stage = WorkflowStage::firstOrCreate(
        ['document_category' => 'Job Order', 'stage_name' => 'Review'],
        ['sequence_order' => 1]
    );
    $doc = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'doc.txt', 'file_path' => 'documents/doc.txt',
        'mime_type' => 'text/plain', 'ocr_text' => 'aircon repair job order distinct content words right here today now',
        'ml_category' => 'Job Order', 'ml_confidence' => 60.0, 'is_validated' => true, 'due_date' => now()->addDay(),
        'global_status' => 'classified_validated',
    ]);
    DocumentAssignment::create([
        'document_id' => $doc->document_id, 'user_id' => null, 'stage_id' => $stage->stage_id,
        'due_date' => $doc->due_date, 'priority_rank' => 2, 'individual_status' => 'approved', 'auto_approved' => true,
    ]);

    // Simulate the scheduled poll already holding the lock mid-run.
    $lock = Cache::lock('ml-auto-train-if-due', 300);
    expect($lock->get())->toBeTrue();

    try {
        // The "instant trigger" call lands while that lock is held.
        $result = app(ClassificationService::class)->autoTrainIfDue();
        expect($result)->toBeNull();
        expect($doc->fresh()->used_for_training_at)->toBeNull();
    } finally {
        $lock->release();
    }

    // Once free, the exact same call actually processes it.
    $result = app(ClassificationService::class)->autoTrainIfDue();
    expect($result)->not->toBeNull();
    expect($doc->fresh()->used_for_training_at)->not->toBeNull();
});
