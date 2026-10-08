<?php

use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Models\WorkflowStageDepartment;

function archivedDocument(User $originator, string $category, array $overrides = []): DocumentRepository
{
    return DocumentRepository::create(array_merge([
        'originator_id' => $originator->user_id,
        'title' => $overrides['title'] ?? "{$category}-".uniqid().'.txt',
        'file_path' => 'documents/'.uniqid().'.txt',
        'mime_type' => 'text/plain',
        'due_date' => now()->addDay(),
        'upload_date' => now(),
        'global_status' => 'approved',
        'ml_category' => $category,
    ], $overrides));
}

test('admin visiting the bare archive URL sees the folder grid, not the flat results table', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();
    archivedDocument($originator, 'Job Order');

    $response = $this->actingAs($admin)->get(route('admin.archive'));

    $response->assertOk();
    $response->assertSee('Browse by Category');
    $response->assertSee('Job Order');
    $response->assertSee('Purchase Requisition');
    $response->assertSee('Service Report');
    $response->assertDontSee('Approved Documents'); // the results-view heading
});

test('clicking into a category folder shows the scoped results view', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();
    archivedDocument($originator, 'Job Order', ['title' => 'job-order-doc.txt']);
    archivedDocument($originator, 'Service Report', ['title' => 'service-report-doc.txt']);

    $response = $this->actingAs($admin)->get(route('admin.archive', ['category' => 'Job Order']));

    $response->assertOk();
    $response->assertSee('Approved Documents');
    $response->assertSee('job-order-doc.txt');
    $response->assertDontSee('service-report-doc.txt');
});

test('a staff approver sees the folder grid too (Feature: can have an Other document alongside their one real category)', function () {
    $approver = User::factory()->approver('Job Order')->create();
    $originator = User::factory()->originator()->create();
    $document = archivedDocument($originator, 'Job Order', ['title' => 'my-category-doc.txt']);
    assignApproverTo($approver, $document);

    $response = $this->actingAs($approver)->get(route('approver.archive'));

    $response->assertOk();
    $response->assertSee('Browse by Category');
    // Only their one real category, plus Other — never a category they
    // could never actually have an assigned document in.
    $response->assertSee('Job Order');
    $response->assertSee('Other');
    $response->assertDontSee('Purchase Requisition');
    $response->assertDontSee('Service Report');
});

test('clicking into a staff approver\'s own category folder shows their assigned documents', function () {
    $approver = User::factory()->approver('Job Order')->create();
    $originator = User::factory()->originator()->create();
    $document = archivedDocument($originator, 'Job Order', ['title' => 'my-category-doc.txt']);
    assignApproverTo($approver, $document);

    $response = $this->actingAs($approver)->get(route('approver.archive', ['category' => 'Job Order']));

    $response->assertOk();
    $response->assertSee('Approved Documents');
    $response->assertSee('my-category-doc.txt');
    $response->assertDontSee('Browse by Category');
});

test('an approver sees only approved documents they were assigned to, not every document in their category', function () {
    $approver = User::factory()->approver('Job Order')->create();
    $originator = User::factory()->originator()->create();
    $assigned = archivedDocument($originator, 'Job Order', ['title' => 'assigned-doc.txt']);
    archivedDocument($originator, 'Job Order', ['title' => 'unassigned-doc.txt']);
    assignApproverTo($approver, $assigned);

    $response = $this->actingAs($approver)->get(route('approver.archive', ['category' => 'Job Order']));

    $response->assertOk();
    $response->assertSee('assigned-doc.txt');
    $response->assertDontSee('unassigned-doc.txt');
});

test('an approver with no category still sees the documents assigned to them in the archive', function () {
    $head = User::factory()->approver()->create(['assigned_category' => null, 'level' => 'head', 'department' => 'Engineering']);
    $originator = User::factory()->originator()->create();
    $document = archivedDocument($originator, 'Service Report', ['title' => 'head-assigned-doc.txt']);
    assignApproverTo($head, $document);

    $response = $this->actingAs($head)->get(route('approver.archive', ['category' => 'Service Report']));

    $response->assertOk();
    $response->assertSee('head-assigned-doc.txt');
});

test('a head only sees folders for categories their own department covers for Final Approval', function () {
    // Mirrors the real seeder's own department ownership: Job Order's
    // Final Approval needs both departments, Service Report Engineering
    // only, Purchase Requisition Finance only (deliberately left
    // unconfigured here) — see approverCategories()'s own docblock.
    foreach ([['Job Order', 'Engineering'], ['Job Order', 'Finance'], ['Service Report', 'Engineering']] as [$category, $department]) {
        $finalApproval = WorkflowStage::firstOrCreate(
            ['document_category' => $category, 'stage_name' => 'Final Approval'],
            ['sequence_order' => 99]
        );
        WorkflowStageDepartment::create(['stage_id' => $finalApproval->stage_id, 'department' => $department]);
    }

    $engineeringHead = User::factory()->approver()->create(['assigned_category' => null, 'level' => 'head', 'department' => 'Engineering']);
    $originator = User::factory()->originator()->create();
    $jobOrderDoc = archivedDocument($originator, 'Job Order', ['title' => 'eng-job-order.txt']);
    $serviceDoc = archivedDocument($originator, 'Service Report', ['title' => 'eng-service-report.txt']);
    assignApproverTo($engineeringHead, $jobOrderDoc);
    assignApproverTo($engineeringHead, $serviceDoc);

    $response = $this->actingAs($engineeringHead)->get(route('approver.archive'));

    $response->assertOk();
    $response->assertSee('Job Order');
    $response->assertSee('Service Report');
    $response->assertSee('Other');
    // Purchase Requisition is Finance-only for Final Approval — an
    // Engineering head can never actually have an assigned document
    // there, so the folder doesn't bother appearing.
    $response->assertDontSee('Purchase Requisition');
});

function assignApproverTo(User $approver, DocumentRepository $document): void
{
    $stage = WorkflowStage::create([
        'document_category' => $document->ml_category,
        'sequence_order' => 1,
        'stage_name' => 'Review',
        'description' => 'Test stage.',
    ]);

    DocumentAssignment::create([
        'document_id' => $document->document_id,
        'stage_id' => $stage->stage_id,
        'user_id' => $approver->user_id,
        'individual_status' => 'approved',
        'sla_expires_at' => now()->addHour(),
        'priority_rank' => 1,
        'auto_approved' => false,
    ]);
}

test('an originator sees folder counts scoped to only their own submissions', function () {
    $originator = User::factory()->originator()->create();
    $otherOriginator = User::factory()->originator()->create();
    archivedDocument($originator, 'Job Order');
    archivedDocument($originator, 'Job Order');
    archivedDocument($otherOriginator, 'Job Order'); // someone else's — must not count

    $response = $this->actingAs($originator)->get(route('originator.archive'));

    $response->assertOk();
    $folders = $response->viewData('folders');
    $jobOrderFolder = $folders->firstWhere('category', 'Job Order');

    expect($jobOrderFolder->total)->toBe(2);
});

test('folder stats correctly break down disputed and auto-approved counts', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();
    archivedDocument($originator, 'Job Order', ['global_status' => 'approved']);
    archivedDocument($originator, 'Job Order', ['global_status' => 'auto_approved']);
    archivedDocument($originator, 'Job Order', ['global_status' => 'auto_approved', 'disputed_at' => now()]);

    $response = $this->actingAs($admin)->get(route('admin.archive'));

    $folders = $response->viewData('folders');
    $jobOrderFolder = $folders->firstWhere('category', 'Job Order');

    expect($jobOrderFolder->total)->toBe(3)
        ->and($jobOrderFolder->disputed)->toBe(1)
        ->and($jobOrderFolder->auto_approved)->toBe(1); // the disputed one is excluded from this count, it has its own
});

test('searching a keyword from the folder screen (no category) shows cross-category flat results', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();
    archivedDocument($originator, 'Job Order', ['title' => 'findme-uniquetoken.txt']);
    archivedDocument($originator, 'Service Report', ['title' => 'unrelated.txt']);

    $response = $this->actingAs($admin)->get(route('admin.archive', ['keyword' => 'findme-uniquetoken']));

    $response->assertOk();
    $response->assertSee('Approved Documents');
    $response->assertSee('findme-uniquetoken.txt');
    $response->assertDontSee('unrelated.txt');
});

test('sort=oldest orders results oldest first instead of the newest-first default', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();
    archivedDocument($originator, 'Job Order', ['title' => 'old-one.txt', 'upload_date' => now()->subDays(5)]);
    archivedDocument($originator, 'Job Order', ['title' => 'new-one.txt', 'upload_date' => now()]);

    $response = $this->actingAs($admin)->get(route('admin.archive', ['category' => 'Job Order', 'sort' => 'oldest']));

    $documents = $response->viewData('documents');
    expect($documents->first()->title)->toBe('old-one.txt');
});

test('a head approver sees the category folders and the Other folder, not a flat list', function () {
    $head = User::factory()->approver()->create(['assigned_category' => null, 'level' => 'head']);
    $originator = User::factory()->originator()->create();
    $document = archivedDocument($originator, 'Job Order', ['title' => 'head-folder-doc.txt']);
    assignApproverTo($head, $document);

    $response = $this->actingAs($head)->get(route('approver.archive'));

    $response->assertOk();
    $response->assertSee('Browse by Category');
    $response->assertSee('Other');
    $response->assertDontSee('head-folder-doc.txt');
});

test('a head approver only counts and opens folders with documents assigned to them', function () {
    $head = User::factory()->approver()->create(['assigned_category' => null, 'level' => 'head']);
    $originator = User::factory()->originator()->create();
    $assigned = archivedDocument($originator, 'Job Order', ['title' => 'head-assigned.txt']);
    archivedDocument($originator, 'Job Order', ['title' => 'head-unassigned.txt']);
    assignApproverTo($head, $assigned);

    $response = $this->actingAs($head)->get(route('approver.archive', ['category' => 'Job Order']));

    $response->assertOk();
    $response->assertSee('head-assigned.txt');
    $response->assertDontSee('head-unassigned.txt');
});

test('a head approver can open the Other folder and see assigned unrelated documents', function () {
    $head = User::factory()->approver()->create(['assigned_category' => null, 'level' => 'head']);
    $originator = User::factory()->originator()->create();
    $unrelated = archivedDocument($originator, 'Service Report', [
        'title' => 'head-other-doc.txt',
        'desired_routing' => 'unrelated',
    ]);
    assignApproverTo($head, $unrelated);

    $response = $this->actingAs($head)->get(route('approver.archive', ['category' => 'Other']));

    $response->assertOk();
    $response->assertSee('head-other-doc.txt');
});

test('a staff approver browsing their own category filter never sees a document outside it, even with a category param mismatch', function () {
    // Superseded the old "staff never sees folders" assumption (Feature:
    // Staff approvers get folders too, see the tests near the top of
    // this file) — what's still true is that a Staff approver's own
    // assigned documents are scoped to the one category they're actually
    // picked for, regardless of what ?category= is passed.
    $staff = User::factory()->approver('Job Order')->create();
    $originator = User::factory()->originator()->create();
    $document = archivedDocument($originator, 'Job Order', ['title' => 'staff-flat-doc.txt']);
    assignApproverTo($staff, $document);

    $response = $this->actingAs($staff)->get(route('approver.archive', ['category' => 'Service Report']));

    $response->assertOk()->assertDontSee('staff-flat-doc.txt');
});
