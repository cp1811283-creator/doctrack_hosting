<?php

use App\Models\DocumentRepository;
use App\Models\User;
use App\Services\ClassificationService;
use App\Services\TextExtractionService;
use App\Services\WorkflowService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Regression coverage for the orphaned-file bug: WorkflowService::ingest()
 * writes the uploaded file to storage, THEN runs extraction/classification,
 * all inside one DB::transaction. Storage writes are not part of that
 * transaction — before the fix, a thrown exception from either step rolled
 * the DocumentRepository row back while leaving the physical file behind on
 * disk with nothing pointing at it, and surfaced a raw 500 to the user
 * instead of the same graceful "extraction failed" message already used for
 * genuinely-too-short text.
 */
function ingestWithThrowingService(string $throwingService): DocumentRepository
{
    $originator = User::factory()->originator()->create();

    $mock = Mockery::mock($throwingService);
    $method = $throwingService === TextExtractionService::class ? 'extract' : 'classify';
    $mock->shouldReceive($method)->andThrow(new RuntimeException('simulated failure'));
    // WorkflowService::ingest() dispatches CheckAutoTrainDue after every
    // call regardless of outcome (QUEUE_CONNECTION=sync in tests runs it
    // inline) — only relevant when mocking ClassificationService itself,
    // since that's the service CheckAutoTrainDue calls autoTrainIfDue() on.
    if ($throwingService === ClassificationService::class) {
        $mock->shouldReceive('autoTrainIfDue')->andReturn(null);
    }
    app()->instance($throwingService, $mock);

    return app(WorkflowService::class)->ingest(
        UploadedFile::fake()->createWithContent('test.txt', 'Some test content for the pipeline.'),
        $originator,
        now()->addDay()->toDateTimeString(),
    );
}

beforeEach(function () {
    Storage::fake('local');
});

it('keeps the document row and its stored file when extraction throws, instead of rolling back', function () {
    $document = ingestWithThrowingService(TextExtractionService::class);

    expect($document->exists)->toBeTrue()
        ->and($document->global_status)->toBe('processing')
        ->and($document->is_validated)->toBeFalse()
        ->and($document->validation_errors)->not->toBeEmpty();

    // The row survived, AND it still correctly points at a real file —
    // this is the actual regression: before the fix, either the row was
    // gone (rolled back) or, if somehow kept, would have a file_path with
    // nothing behind it. Both must hold together.
    Storage::disk('local')->assertExists($document->file_path);

    expect(DocumentRepository::find($document->document_id))->not->toBeNull();
});

it('keeps the document row and its stored file when classification throws, instead of rolling back', function () {
    $document = ingestWithThrowingService(ClassificationService::class);

    expect($document->exists)->toBeTrue()
        ->and($document->global_status)->toBe('processing')
        ->and($document->is_validated)->toBeFalse();

    Storage::disk('local')->assertExists($document->file_path);
});

it('notifies the originator when extraction throws, same as a genuine extraction failure', function () {
    $document = ingestWithThrowingService(TextExtractionService::class);

    $this->assertDatabaseHas('notifications', [
        'recipient_id' => $document->originator_id,
        'document_id' => $document->document_id,
    ]);

    $this->assertDatabaseHas('audit_logs', [
        'document_id' => $document->document_id,
        'action_type' => 'extraction_failed',
    ]);
});
