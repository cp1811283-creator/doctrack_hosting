<?php

use App\Models\AdminViolation;
use App\Models\AuditLog;
use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\NotificationRecord;
use App\Models\User;
use App\Models\WorkflowStage;

/**
 * Boundary coverage for the pagination sizes revised per user feedback —
 * exactly N items must show no "Next" link, N+1 must show one. Verifies
 * the EXACT configured size, not just "pagination exists somewhere."
 */
function paginationDoc(User $originator, array $overrides = []): DocumentRepository
{
    return DocumentRepository::create(array_merge([
        'originator_id' => $originator->user_id,
        'title' => 'pg-test-'.uniqid().'.txt',
        'file_path' => 'documents/'.uniqid().'.txt',
        'mime_type' => 'text/plain',
        'due_date' => now()->addDay(),
        'upload_date' => now(),
        'global_status' => 'approved',
        'ml_category' => 'Job Order',
    ], $overrides));
}

it('sends every document in one response for the Archive (Feature: client-side fitted pagination)', function () {
    // Archive used to be server-side ->paginate()'d at a fixed 5 (Admin)
    // or 10 (Approver/Originator) per page regardless of actual screen
    // size — replaced with the same client-side fitted pagination every
    // other list in this app already uses (see resources/js/app.js's
    // initFittedPagination()). The server always sends the full matching
    // list now; the pagination nav is built entirely by JS after load, so
    // the only thing left to verify at the HTTP layer is that the server
    // never truncates and that nav never appears in the raw HTML.
    $admin = User::factory()->admin()->create();
    $docs = [];
    for ($i = 0; $i < 6; $i++) {
        $docs[] = paginationDoc($admin);
    }

    $response = $this->actingAs($admin)->get(route('admin.archive', ['category' => 'Job Order']));

    $response->assertOk();
    foreach ($docs as $doc) {
        $response->assertSee($doc->title);
    }
    $response->assertDontSee('Next');
    $response->assertDontSee('Pagination Navigation', false);
});

it('sends every account in one response for the Admin Users list (Feature: client-side fitted pagination)', function () {
    // Pagination for this list is no longer a fixed per-page server
    // count at all — see resources/js/app.js's initFittedPagination().
    // The server always sends the full matching list; the browser works
    // out real page boundaries from actual rendered row heights, which
    // Pest has no layout engine to verify. What IS verifiable at the HTTP
    // layer: the server never truncates, and the pagination nav (built
    // entirely by JS after load) never appears in the raw HTML.
    $admin = User::factory()->admin()->create();
    $originators = User::factory()->count(6)->originator()->create();

    $response = $this->actingAs($admin)->get(route('admin.users'));

    $response->assertOk();
    foreach ($originators as $originator) {
        $response->assertSee($originator->username);
    }
    $response->assertDontSee('Next');
});

it('paginates the Auto-Approval Review queue at 2 per page', function () {
    $admin = User::factory()->admin()->create();
    $approver = User::factory()->approver('Job Order')->create();
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Review', 'sequence_order' => 1]);

    for ($i = 0; $i < 3; $i++) {
        $autoDoc = paginationDoc($approver, ['title' => "auto-{$i}.txt", 'global_status' => 'approved']);
        DocumentAssignment::create([
            'document_id' => $autoDoc->document_id, 'user_id' => $approver->user_id,
            'stage_id' => WorkflowStage::first()->stage_id, 'due_date' => $autoDoc->due_date,
            'priority_rank' => 2, 'individual_status' => 'approved', 'acted_at' => now(),
            'auto_approved' => true,
            'review_due_at' => now()->addHours(6),
        ]);
    }

    // 3 auto-approved documents, over the 2/page bar.
    $response = $this->actingAs($admin)->get(route('admin.sla.queue'));
    $response->assertOk()->assertViewHas('reviewContainers', fn ($containers) => $containers->total() === 3 && $containers->count() === 2);

    $page2 = $this->actingAs($admin)->get(route('admin.sla.queue', ['page' => 2]));
    $page2->assertOk()->assertViewHas('reviewContainers', fn ($containers) => $containers->count() === 1);
});

it('deep-links from Admin Violations straight to the page containing that document (Feature: highlight)', function () {
    $admin = User::factory()->admin()->create();
    $approver = User::factory()->approver('Job Order')->create();
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Review', 'sequence_order' => 1]);

    $targetDoc = null;
    for ($i = 0; $i < 3; $i++) {
        $autoDoc = paginationDoc($approver, ['title' => "auto-{$i}.txt", 'global_status' => 'approved']);
        DocumentAssignment::create([
            'document_id' => $autoDoc->document_id, 'user_id' => $approver->user_id,
            'stage_id' => WorkflowStage::first()->stage_id, 'due_date' => $autoDoc->due_date,
            'priority_rank' => 2, 'individual_status' => 'approved', 'acted_at' => now()->addSeconds($i),
            'auto_approved' => true,
            'review_due_at' => now()->addHours(6),
        ]);
        if ($i === 2) {
            $targetDoc = $autoDoc; // 3rd of 3, sorted oldest-first by acted_at, so it lands on page 2 of a 2-per-page list
        }
    }

    $response = $this->actingAs($admin)->get(route('admin.sla.queue', ['highlight' => $targetDoc->document_id]));

    $response->assertOk()
        ->assertViewHas('reviewContainers', fn ($containers) => $containers->currentPage() === 2
            && $containers->pluck('document.document_id')->contains($targetDoc->document_id));
});

it('honors the pagination param on the SLA Queue refresh fragment (Feature: AJAX pagination)', function () {
    $admin = User::factory()->admin()->create();
    $approver = User::factory()->approver('Job Order')->create();
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Review', 'sequence_order' => 1]);

    for ($i = 0; $i < 3; $i++) {
        $autoDoc = paginationDoc($approver, ['title' => "auto-{$i}.txt", 'global_status' => 'approved']);
        DocumentAssignment::create([
            'document_id' => $autoDoc->document_id, 'user_id' => $approver->user_id,
            'stage_id' => WorkflowStage::first()->stage_id, 'due_date' => $autoDoc->due_date,
            'priority_rank' => 2, 'individual_status' => 'approved', 'acted_at' => now(),
            'auto_approved' => true,
            'review_due_at' => now()->addHours(6),
        ]);
    }

    $response = $this->actingAs($admin)->get(route('admin.sla.queue.refresh', ['page' => 2]));

    $response->assertOk();
    // Page 2 of a 3-item/2-per-page list has exactly 1 item left.
    $response->assertDontSee('auto-0.txt')->assertDontSee('auto-1.txt')->assertSee('auto-2.txt');
});

// The Unassigned Documents pagination test that used to live here is
// gone along with the module itself — a stage with no eligible approver
// auto-approves immediately now (see WorkflowService::assignStage()),
// so there's no longer a "pending, waiting" list of these to paginate.

it('sends every entry in one response for the Admin Audit Trail (Feature: client-side fitted pagination)', function () {
    // Same reasoning as the other fitted-pagination tests in this file —
    // see resources/js/app.js's initFittedPagination().
    $admin = User::factory()->admin()->create();
    for ($i = 0; $i < 11; $i++) {
        AuditLog::record($admin->user_id, null, 'user_toggle', "PAGINATION TEST audit row {$i}");
    }

    $response = $this->actingAs($admin)->get(route('admin.audit.logs'));

    $response->assertOk();
    for ($i = 0; $i < 11; $i++) {
        $response->assertSee("PAGINATION TEST audit row {$i}");
    }
    $response->assertDontSee('Next');
});

it('sends every document in one response for Document Tracking (Feature: client-side fitted pagination)', function () {
    // Same reasoning as the Admin Users / Originator Submissions tests
    // above — this list's pagination is entirely client-side now (see
    // resources/js/app.js's initFittedPagination()), so the only thing
    // left to verify at the HTTP layer is that the server sends the full
    // list, untruncated.
    $admin = User::factory()->admin()->create();
    $docs = [];
    for ($i = 0; $i < 6; $i++) {
        $docs[] = paginationDoc($admin);
    }

    $response = $this->actingAs($admin)->get(route('admin.documents.index'));

    $response->assertOk();
    foreach ($docs as $doc) {
        $response->assertSee($doc->title);
    }
    $response->assertDontSee('Next');
});

it('sends every document in one response for the Originator "Upload & Track Documents" list (Feature: client-side fitted pagination)', function () {
    // Same reasoning as the Admin Users test above — this list's
    // pagination is entirely client-side now (see resources/js/app.js's
    // initFittedPagination()), so the only thing left to verify at the
    // HTTP layer is that the server sends the full list, untruncated.
    $originator = User::factory()->originator()->create();
    $docs = [];
    for ($i = 0; $i < 6; $i++) {
        $docs[] = paginationDoc($originator);
    }

    $response = $this->actingAs($originator)->get(route('originator.dashboard'));

    $response->assertOk();
    foreach ($docs as $doc) {
        $response->assertSee($doc->title);
    }
    $response->assertDontSee('Next');
});

it('sends every Admin Violation in one response (Feature: client-side fitted pagination)', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();
    $stage = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Review', 'sequence_order' => 1]);

    $titles = [];
    for ($i = 0; $i < 6; $i++) {
        $doc = paginationDoc($originator, ['global_status' => 'auto_approved']);
        $titles[] = $doc->title;
        $assignment = DocumentAssignment::create([
            'document_id' => $doc->document_id, 'user_id' => null,
            'stage_id' => $stage->stage_id, 'due_date' => $doc->due_date,
            'priority_rank' => 2, 'individual_status' => 'approved', 'auto_approved' => true,
        ]);
        AdminViolation::create([
            'document_id' => $doc->document_id, 'assignment_id' => $assignment->assignment_id,
            'violation_type' => 'late_review', 'stage_name' => 'Review',
            'first_violated_at' => now(), 'resolved_at' => null,
        ]);
    }

    $response = $this->actingAs($admin)->get(route('admin.sla.violations', ['category' => 'Job Order']));
    $response->assertOk();
    foreach ($titles as $title) {
        $response->assertSee($title);
    }
    $response->assertDontSee('Next');
});

it('paginates Decision History at 10 per page (unchanged)', function () {
    $approver = User::factory()->approver('Job Order')->create();
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Review', 'sequence_order' => 1]);
    for ($i = 0; $i < 11; $i++) {
        $doc = paginationDoc($approver, ['global_status' => 'approved']);
        DocumentAssignment::create([
            'document_id' => $doc->document_id, 'user_id' => $approver->user_id,
            'stage_id' => WorkflowStage::first()->stage_id, 'due_date' => $doc->due_date,
            'priority_rank' => 2, 'individual_status' => 'approved', 'acted_at' => now(),
        ]);
    }

    $this->actingAs($approver)->get(route('approver.history'))->assertSee('Next');
});

it('paginates Notifications at 10 per page (unchanged)', function () {
    $originator = User::factory()->originator()->create();
    for ($i = 0; $i < 10; $i++) {
        NotificationRecord::send($originator->user_id, null, "Notification {$i}");
    }
    $this->actingAs($originator)->get(route('notifications.index'))->assertDontSee('Next');

    NotificationRecord::send($originator->user_id, null, 'Notification 11');
    $this->actingAs($originator)->get(route('notifications.index'))->assertSee('Next');
});
