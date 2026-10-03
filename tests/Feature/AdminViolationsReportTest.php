<?php

use App\Models\AdminViolation;
use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\SlaViolation;
use App\Models\User;
use App\Models\WorkflowStage;

function violationDoc(User $originator, string $category, string $title): DocumentRepository
{
    return DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => $title, 'file_path' => 'documents/'.$title,
        'mime_type' => 'text/plain', 'due_date' => now()->addDay(), 'global_status' => 'auto_approved', 'ml_category' => $category,
    ]);
}

test('the approver popup only shows that approver\'s own documents', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();
    $stage = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Review', 'sequence_order' => 1]);
    $approverA = User::factory()->approver('Job Order')->create(['full_name' => 'Approver A']);
    $approverB = User::factory()->approver('Job Order')->create(['full_name' => 'Approver B']);

    foreach ([[$approverA, 'a-doc.txt'], [$approverB, 'b-doc.txt']] as [$approver, $title]) {
        $document = violationDoc($originator, 'Job Order', $title);
        $assignment = DocumentAssignment::create([
            'document_id' => $document->document_id, 'user_id' => $approver->user_id, 'stage_id' => $stage->stage_id,
            'due_date' => $document->due_date, 'priority_rank' => 2, 'individual_status' => 'approved',
            'sla_expires_at' => now()->subHour(),
        ]);
        SlaViolation::create([
            'document_id' => $assignment->document_id, 'assignment_id' => $assignment->assignment_id,
            'approver_id' => $approver->user_id, 'violation_timestamp' => now(), 'duration_overdue' => 30, 'stage_name' => 'Review',
        ]);
    }

    $response = $this->actingAs($admin)->get(route('admin.sla.violations.approver', ['approver' => $approverA->user_id, 'category' => 'Job Order']));

    $response->assertOk()->assertSee('a-doc.txt')->assertDontSee('b-doc.txt');
});

test('the Admin Violations section is hidden on the bare landing screen, same as the approver table', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();
    $stage = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Review', 'sequence_order' => 1]);

    $doc = violationDoc($originator, 'Job Order', 'late-review-doc.txt');
    $assignment = DocumentAssignment::create([
        'document_id' => $doc->document_id, 'user_id' => null, 'stage_id' => $stage->stage_id,
        'due_date' => $doc->due_date, 'priority_rank' => 2, 'individual_status' => 'approved', 'auto_approved' => true,
    ]);
    AdminViolation::create([
        'document_id' => $doc->document_id, 'assignment_id' => $assignment->assignment_id,
        'violation_type' => 'late_review', 'stage_name' => 'Review',
        'first_violated_at' => now(), 'resolved_at' => null,
    ]);

    $bare = $this->actingAs($admin)->get(route('admin.sla.violations'));
    $bare->assertOk()->assertDontSee('Admin Violations')->assertDontSee('late-review-doc.txt');
});

test('the Admin Violations table groups by document with stages listed underneath, badge reflects whether the document still needs review, scoped to only the current category', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();
    $jobOrderStage1 = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Technical Review', 'sequence_order' => 1]);
    $jobOrderStage2 = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Final Approval', 'sequence_order' => 2]);
    $purchaseStage = WorkflowStage::create(['document_category' => 'Purchase Requisition', 'stage_name' => 'Review', 'sequence_order' => 1]);

    // Still sitting unreviewed in Auto-Approval Review (admin_reviewed_at
    // is null) — must show Open.
    $openDoc = violationDoc($originator, 'Job Order', 'open-late-review-doc.txt');
    $openAssignment = DocumentAssignment::create([
        'document_id' => $openDoc->document_id, 'user_id' => null, 'stage_id' => $jobOrderStage1->stage_id,
        'due_date' => $openDoc->due_date, 'priority_rank' => 2, 'individual_status' => 'approved', 'auto_approved' => true,
        'admin_reviewed_at' => null,
    ]);
    AdminViolation::create([
        'document_id' => $openDoc->document_id, 'assignment_id' => $openAssignment->assignment_id,
        'violation_type' => 'late_review', 'stage_name' => 'Technical Review',
        'first_violated_at' => now(), 'resolved_at' => null,
    ]);

    // Already reviewed by Admin (admin_reviewed_at is set) — must show
    // Resolved.
    $resolvedDoc = violationDoc($originator, 'Job Order', 'resolved-late-review-doc.txt');
    $resolvedAssignment = DocumentAssignment::create([
        'document_id' => $resolvedDoc->document_id, 'user_id' => null, 'stage_id' => $jobOrderStage2->stage_id,
        'due_date' => $resolvedDoc->due_date, 'priority_rank' => 2, 'individual_status' => 'approved', 'auto_approved' => true,
        'admin_reviewed_at' => now(),
    ]);
    AdminViolation::create([
        'document_id' => $resolvedDoc->document_id, 'assignment_id' => $resolvedAssignment->assignment_id,
        'violation_type' => 'late_review', 'stage_name' => 'Final Approval',
        'first_violated_at' => now()->subHours(2), 'resolved_at' => now(),
    ]);

    // A second category's own Admin violation — must never leak into the
    // Job Order folder's section below.
    $otherDoc = violationDoc($originator, 'Purchase Requisition', 'other-category-doc.txt');
    $otherAssignment = DocumentAssignment::create([
        'document_id' => $otherDoc->document_id, 'user_id' => null, 'stage_id' => $purchaseStage->stage_id,
        'due_date' => $otherDoc->due_date, 'priority_rank' => 2, 'individual_status' => 'approved', 'auto_approved' => true,
    ]);
    AdminViolation::create([
        'document_id' => $otherDoc->document_id, 'assignment_id' => $otherAssignment->assignment_id,
        'violation_type' => 'late_review', 'stage_name' => 'Review',
        'first_violated_at' => now(), 'resolved_at' => null,
    ]);

    $response = $this->actingAs($admin)->get(route('admin.sla.violations', ['category' => 'Job Order']));
    $response->assertOk()
        ->assertSee('Admin Violations')
        ->assertSee('open-late-review-doc.txt')
        ->assertSee('resolved-late-review-doc.txt')
        ->assertSee('Technical Review')
        ->assertSee('Final Approval')
        ->assertSee('Open')
        ->assertSee('Resolved')
        ->assertDontSee('other-category-doc.txt');

    $items = $response->viewData('adminViolations')->keyBy(fn ($item) => $item->document->title);
    expect($items['open-late-review-doc.txt']->isOpen)->toBeTrue()
        ->and($items['resolved-late-review-doc.txt']->isOpen)->toBeFalse();
});

test('an Open row links to the Auto-Approval Review page, a Resolved row renders as plain non-clickable text', function () {
    // Regression coverage for a real reported bug (2026-10-03): a Resolved
    // row's "highlight" link pointed at AdminController::slaQueueData(),
    // whose list only ever contains documents still AWAITING review — a
    // resolved document has already left that list, so the link silently
    // matched nothing and just dumped the admin on an unrelated page 1
    // with no explanation. See admin-violations-results.blade.php.
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();
    $stage = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Technical Review', 'sequence_order' => 1]);

    $openDoc = violationDoc($originator, 'Job Order', 'open-doc.txt');
    $openAssignment = DocumentAssignment::create([
        'document_id' => $openDoc->document_id, 'user_id' => null, 'stage_id' => $stage->stage_id,
        'due_date' => $openDoc->due_date, 'priority_rank' => 2, 'individual_status' => 'approved', 'auto_approved' => true,
        'admin_reviewed_at' => null,
    ]);
    AdminViolation::create([
        'document_id' => $openDoc->document_id, 'assignment_id' => $openAssignment->assignment_id,
        'violation_type' => 'late_review', 'stage_name' => 'Technical Review',
        'first_violated_at' => now(), 'resolved_at' => null,
    ]);

    $resolvedDoc = violationDoc($originator, 'Job Order', 'resolved-doc.txt');
    $resolvedAssignment = DocumentAssignment::create([
        'document_id' => $resolvedDoc->document_id, 'user_id' => null, 'stage_id' => $stage->stage_id,
        'due_date' => $resolvedDoc->due_date, 'priority_rank' => 2, 'individual_status' => 'approved', 'auto_approved' => true,
        'admin_reviewed_at' => now(),
    ]);
    AdminViolation::create([
        'document_id' => $resolvedDoc->document_id, 'assignment_id' => $resolvedAssignment->assignment_id,
        'violation_type' => 'late_review', 'stage_name' => 'Technical Review',
        'first_violated_at' => now()->subHours(2), 'resolved_at' => now(),
    ]);

    $response = $this->actingAs($admin)->get(route('admin.sla.violations', ['category' => 'Job Order']));
    $response->assertOk();

    $html = $response->getContent();
    $highlightUrl = route('admin.sla.queue', ['highlight' => $openDoc->document_id]);

    expect($html)->toContain('href="'.$highlightUrl.'"') // Open row IS a link
        ->and($html)->not->toContain('highlight='.$resolvedDoc->document_id); // Resolved row is NOT
});

test('a missed_approval violation never appears in Admin Violations by itself — only once/if its own review window later lapses unreviewed too', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();
    $stage = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Technical Review', 'sequence_order' => 1]);

    // No eligible approver — auto-approved instantly, logged already
    // resolved. Even though the assignment itself is still unreviewed by
    // Admin (admin_reviewed_at null), this alone must not surface here —
    // see AdminController::adminViolationsData()'s docblock.
    $doc = violationDoc($originator, 'Job Order', 'no-eligible-approver-doc.txt');
    $assignment = DocumentAssignment::create([
        'document_id' => $doc->document_id, 'user_id' => null, 'stage_id' => $stage->stage_id,
        'due_date' => $doc->due_date, 'priority_rank' => 2, 'individual_status' => 'approved', 'auto_approved' => true,
        'admin_reviewed_at' => null,
    ]);
    AdminViolation::create([
        'document_id' => $doc->document_id, 'assignment_id' => $assignment->assignment_id,
        'violation_type' => 'missed_approval', 'stage_name' => 'Technical Review',
        'first_violated_at' => now(), 'resolved_at' => now(),
    ]);

    $response = $this->actingAs($admin)->get(route('admin.sla.violations', ['category' => 'Job Order']));
    $response->assertOk()->assertDontSee('no-eligible-approver-doc.txt');
    expect($response->viewData('adminViolationTotal'))->toBe(0);
});

test('a Resolved row shows when it was resolved, not just when it was flagged', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();
    $stage = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Technical Review', 'sequence_order' => 1]);

    $doc = violationDoc($originator, 'Job Order', 'resolved-with-timestamp.txt');
    $assignment = DocumentAssignment::create([
        'document_id' => $doc->document_id, 'user_id' => null, 'stage_id' => $stage->stage_id,
        'due_date' => $doc->due_date, 'priority_rank' => 2, 'individual_status' => 'approved', 'auto_approved' => true,
        'admin_reviewed_at' => now(),
    ]);
    $resolvedAt = now()->subHours(3);
    AdminViolation::create([
        'document_id' => $doc->document_id, 'assignment_id' => $assignment->assignment_id,
        'violation_type' => 'late_review', 'stage_name' => 'Technical Review',
        'first_violated_at' => now()->subHours(10), 'resolved_at' => $resolvedAt,
    ]);

    $response = $this->actingAs($admin)->get(route('admin.sla.violations', ['category' => 'Job Order']));

    $response->assertOk()
        ->assertSee('Resolved '.$resolvedAt->format('M j, Y g:i A'));
});
