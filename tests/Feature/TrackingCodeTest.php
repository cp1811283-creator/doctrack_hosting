<?php

use App\Models\DocumentRepository;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\ClassificationService;
use App\Services\TrackingCodeService;
use App\Services\WorkflowService;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;

beforeEach(function () {
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Technical Review', 'sequence_order' => 1]);
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Final Approval', 'sequence_order' => 2]);
});

/** Same mocked-classifier helper CustomRoutingTest.php already established — no real trained model exists in a fresh test DB. */
function fakeClassifiedIngestForTrackingCode(string $category, float $confidence, string $content, string $routingMode = 'auto'): DocumentRepository
{
    $originator = User::factory()->originator()->create();

    $mock = Mockery::mock(ClassificationService::class);
    $mock->shouldReceive('classify')->andReturn(['category' => $category, 'confidence' => $confidence, 'margin' => 100.0, 'model_id' => null]);
    $mock->shouldReceive('autoTrainIfDue')->andReturn(null);
    app()->instance(ClassificationService::class, $mock);

    return app(WorkflowService::class)->ingest(
        UploadedFile::fake()->createWithContent('test.txt', $content),
        $originator,
        now()->addDay()->toDateTimeString(),
        null, null, false, $routingMode,
    );
}

/**
 * Confirmed real gap this feature closes: document titles have no
 * uniqueness constraint anywhere, so two documents could (and in real
 * local data, do) share the exact same title with nothing on screen to
 * tell them apart. See TrackingCodeService's own docblock for the format.
 */
it('assigns a JO/PR/SR-prefixed code to a normally auto-routed document, not the OTH fallback', function () {
    User::factory()->approver('Job Order')->create();
    $content = str_repeat('word ', 30).'date requested requested by description of work estimated cost';

    $document = fakeClassifiedIngestForTrackingCode('Job Order', 95.0, $content);

    expect($document->tracking_code)->toMatch('/^JO-\d{4}-0001$/');
});

it('falls back to the OTH prefix for a routing_mode=unrelated document, even though the classifier guessed a real category', function () {
    $content = 'This content is long enough to clear the 40-character minimum extracted-text floor.';
    $document = fakeClassifiedIngestForTrackingCode('Job Order', 95.0, $content, 'unrelated');

    expect($document->tracking_code)->toMatch('/^OTH-\d{4}-0001$/');
});

it('falls back to the OTH prefix for a document rejected below the classification chance floor', function () {
    // Below 33.33% (100/3 trained categories) — never routed, ml_category
    // is kept only as an untrusted guess, same as 'unrelated'.
    $content = 'This content is long enough to clear the 40-character minimum extracted-text floor.';
    $document = fakeClassifiedIngestForTrackingCode('Job Order', 10.0, $content, 'auto');

    expect($document->global_status)->toBe('rejected')
        ->and($document->tracking_code)->toMatch('/^OTH-\d{4}-0001$/');
});

it('numbers sequentially within the same category, never colliding, and resets per year', function () {
    $service = app(TrackingCodeService::class);

    Carbon::setTestNow(Carbon::parse('2026-06-01'));
    $first = $service->nextCodeFor('Job Order', true);
    $second = $service->nextCodeFor('Job Order', true);
    $third = $service->nextCodeFor('Purchase Requisition', true); // different prefix, own sequence

    expect($first)->toBe('JO-2026-0001')
        ->and($second)->toBe('JO-2026-0002')
        ->and($third)->toBe('PR-2026-0001');

    Carbon::setTestNow(Carbon::parse('2027-01-01'));
    $fourth = $service->nextCodeFor('Job Order', true);

    expect($fourth)->toBe('JO-2027-0001'); // resets, doesn't continue from 0003
});

it('is searchable on the Originator dashboard even when two documents share the exact same title', function () {
    $originator = User::factory()->originator()->create();
    $due = now()->addDays(5);

    $first = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'Job Order.docx', 'tracking_code' => 'JO-2026-0001',
        'file_path' => 'documents/a.docx', 'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'due_date' => $due, 'global_status' => 'processing', 'ml_category' => 'Job Order',
    ]);
    DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'Job Order.docx', 'tracking_code' => 'JO-2026-0002',
        'file_path' => 'documents/b.docx', 'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'due_date' => $due, 'global_status' => 'processing', 'ml_category' => 'Job Order',
    ]);

    $response = $this->actingAs($originator)->get(route('originator.dashboard', ['document' => 'JO-2026-0002']));

    $response->assertOk()->assertSee('JO-2026-0002');
    $matched = $response->viewData('documents');
    expect($matched)->toHaveCount(1)
        ->and($matched->first()->tracking_code)->toBe('JO-2026-0002')
        ->and($matched->first()->document_id)->not->toBe($first->document_id);
});

it('is searchable in the Archive even when two documents share the exact same title', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();

    DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'Report.pdf', 'tracking_code' => 'SR-2026-0001',
        'file_path' => 'documents/a.pdf', 'mime_type' => 'application/pdf',
        'due_date' => now()->addDay(), 'global_status' => 'approved', 'ml_category' => 'Service Report',
    ]);
    DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'Report.pdf', 'tracking_code' => 'SR-2026-0002',
        'file_path' => 'documents/b.pdf', 'mime_type' => 'application/pdf',
        'due_date' => now()->addDay(), 'global_status' => 'approved', 'ml_category' => 'Service Report',
    ]);

    $response = $this->actingAs($admin)->get(route('admin.archive', ['keyword' => 'SR-2026-0002']));

    $response->assertOk()->assertSee('SR-2026-0002');
});

it('is searchable on the Admin documents list even when two documents share the exact same title', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();

    DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'PR.pdf', 'tracking_code' => 'PR-2026-0001',
        'file_path' => 'documents/a.pdf', 'mime_type' => 'application/pdf',
        'due_date' => now()->addDay(), 'global_status' => 'processing', 'ml_category' => 'Purchase Requisition',
    ]);
    DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'PR.pdf', 'tracking_code' => 'PR-2026-0002',
        'file_path' => 'documents/b.pdf', 'mime_type' => 'application/pdf',
        'due_date' => now()->addDay(), 'global_status' => 'processing', 'ml_category' => 'Purchase Requisition',
    ]);

    $response = $this->actingAs($admin)->get(route('admin.documents.index', ['document' => 'PR-2026-0002']));

    $response->assertOk()->assertSee('PR-2026-0002');
});

it('renders nothing for a document that never got a tracking code', function () {
    $html = view('components.tracking-code', ['code' => null])->render();

    expect(trim($html))->toBe('');
});
