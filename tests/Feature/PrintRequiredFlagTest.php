<?php

use App\Models\AuditLog;
use App\Models\DocumentRepository;
use App\Models\User;
use App\Services\ClassificationService;
use App\Services\WorkflowService;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * The classifier is mocked to a fixed category rather than relying on the
 * real trained SVM's output for arbitrary test content — irrelevant here,
 * since requires_printing is now purely the originator's own explicit
 * checkbox at upload, with no category-level involvement at all.
 */
function fakeClassifiedIngest(string $category, bool $requiresPrinting = false): DocumentRepository
{
    $originator = User::factory()->originator()->create();

    $mock = Mockery::mock(ClassificationService::class);
    $mock->shouldReceive('classify')->andReturn(['category' => $category, 'confidence' => 95, 'margin' => 100.0, 'model_id' => null]);
    // See the matching comment in OtherDisplayTest.php — WorkflowService::
    // ingest() dispatches CheckAutoTrainDue, which runs inline under the
    // test suite's sync queue connection against this same strict mock.
    $mock->shouldReceive('autoTrainIfDue')->andReturn(null);
    app()->instance(ClassificationService::class, $mock);

    return app(WorkflowService::class)->ingest(
        UploadedFile::fake()->createWithContent('test.txt', 'This is plain test content for classification.'),
        $originator,
        now()->addDay()->toDateTimeString(),
        null,
        null,
        $requiresPrinting,
    );
}

it('sets requires_printing when the originator explicitly checks it at upload', function () {
    $document = fakeClassifiedIngest('Job Order', true);

    expect($document->requires_printing)->toBeTrue();
});

it('leaves requires_printing false when not checked, with no category-level override', function () {
    $document = fakeClassifiedIngest('Job Order', false);

    expect($document->requires_printing)->toBeFalse();
});

it('carries the flag forward on resubmission instead of resetting it', function () {
    $this->travelTo(Carbon::parse('2026-08-12 10:00:00')); // Wednesday, business hours

    $originator = User::factory()->originator()->create();
    $rejected = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'rejected.txt', 'file_path' => 'documents/rejected.txt',
        'mime_type' => 'text/plain', 'due_date' => now()->addDay(), 'global_status' => 'rejected',
        'ml_category' => 'Job Order', 'requires_printing' => true,
    ]);

    $this->actingAs($originator)->post(route('originator.documents.resubmit', $rejected), [
        'file' => UploadedFile::fake()->createWithContent('revised.txt', 'Revised content.'),
        'due_date' => now()->addHours(4)->format('Y-m-d\TH:i'),
    ]);

    $revision = DocumentRepository::where('previous_version_id', $rejected->document_id)->first();
    expect($revision)->not->toBeNull();
    expect($revision->requires_printing)->toBeTrue();
});

it('shows the print-required badge on the tracking page only once approved', function () {
    $originator = User::factory()->originator()->create();
    $processing = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'proc.txt', 'file_path' => 'documents/proc.txt',
        'mime_type' => 'text/plain', 'due_date' => now()->addDay(), 'global_status' => 'processing',
        'ml_category' => 'Job Order', 'requires_printing' => true,
    ]);

    $stillProcessing = $this->actingAs($originator)->get(route('originator.documents.show', $processing));
    $stillProcessing->assertDontSee('Print Required');

    $processing->update(['global_status' => 'approved']);
    $approved = $this->actingAs($originator)->get(route('originator.documents.show', $processing));
    $approved->assertSee('Print Required');
});

it('makes the print badge call openDocumentViewer() with autoPrint for the originator', function () {
    $originator = User::factory()->originator()->create();
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'clickable.txt', 'file_path' => 'documents/clickable.txt',
        'mime_type' => 'text/plain', 'due_date' => now()->addDay(), 'global_status' => 'approved',
        'ml_category' => 'Job Order', 'requires_printing' => true,
    ]);

    $response = $this->actingAs($originator)->get(route('originator.documents.show', $document));

    $response->assertOk();
    $response->assertSee('openDocumentViewer(', false);
    $response->assertSee(', true)', false);
});

it('keeps the print badge as a plain non-clickable label for admin', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'admin-view.txt', 'file_path' => 'documents/admin-view.txt',
        'mime_type' => 'text/plain', 'due_date' => now()->addDay(), 'global_status' => 'approved',
        'ml_category' => 'Job Order', 'requires_printing' => true,
    ]);

    $response = $this->actingAs($admin)->get(route('documents.track', $document));

    $response->assertOk();
    $response->assertSee('Print Required');
    // openDocumentViewer(...) itself legitimately appears elsewhere on the
    // page (the "View original file" link) — what must be absent is the
    // autoPrint=true variant the badge would use.
    $response->assertDontSee(', true)', false);
});

it('shows the print-required badge in the Archive results table, clickable for the originator', function () {
    $originator = User::factory()->originator()->create();
    DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'archive-print-test.txt', 'file_path' => 'documents/archive-print-test.txt',
        'mime_type' => 'text/plain', 'due_date' => now()->addDay(), 'upload_date' => now(), 'global_status' => 'approved',
        'ml_category' => 'Job Order', 'requires_printing' => true,
    ]);

    $response = $this->actingAs($originator)->get(route('originator.archive', ['category' => 'Job Order']));

    $response->assertOk();
    $response->assertSee('archive-print-test.txt');
    $response->assertSee('Print Required');
    $response->assertSee('openDocumentViewer(', false);
});

it('opens the shared Document Tracker popup for a document row in the Archive', function () {
    $originator = User::factory()->originator()->create();
    $doc = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'archive-nested-test.txt', 'file_path' => 'documents/archive-nested-test.txt',
        'mime_type' => 'text/plain', 'due_date' => now()->addDay(), 'upload_date' => now(), 'global_status' => 'approved',
        'ml_category' => 'Job Order',
    ]);
    AuditLog::record($originator->user_id, $doc->document_id, 'upload', 'Document uploaded.');

    $response = $this->actingAs($originator)->get(route('originator.archive', ['category' => 'Job Order']));

    $response->assertOk();
    // No more inline-expanding <tr> — this row opens the shared Document
    // Tracker popup instead (see routes/web.php's documents.trackerModal).
    $response->assertSee("openKpiDrilldown('document-tracker'", false);
    $response->assertSee(route('documents.trackerModal', $doc), false);
});

it('no longer exposes a category print-default admin route', function () {
    $admin = User::factory()->admin()->create();

    expect(function () {
        route('admin.workflow.categoryPrintDefault');
    })->toThrow(RouteNotFoundException::class);
});
