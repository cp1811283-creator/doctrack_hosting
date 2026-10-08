<?php

use App\Models\AdminViolation;
use App\Models\AuditLog;
use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\NotificationRecord;
use App\Models\SlaViolation;
use App\Models\User;
use App\Models\WorkflowStage;

function pendingHandoffAssignmentFor(User $approver, string $category, string $stageName = 'Review'): DocumentAssignment
{
    $originator = User::factory()->originator()->create();
    $stage = WorkflowStage::firstOrCreate(
        ['document_category' => $category, 'stage_name' => $stageName],
        ['sequence_order' => 1]
    );
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'handoff-test-'.uniqid().'.txt',
        'file_path' => 'documents/'.uniqid().'.txt',
        'mime_type' => 'text/plain',
        'due_date' => now()->addDay(),
        'global_status' => 'classified_validated',
        'ml_category' => $category,
    ]);

    return DocumentAssignment::create([
        'document_id' => $document->document_id,
        'user_id' => $approver->user_id,
        'stage_id' => $stage->stage_id,
        'due_date' => $document->due_date,
        'priority_rank' => 2,
        'individual_status' => 'pending',
        'sla_expires_at' => now()->addHours(3),
    ]);
}

test('deactivating an approver with a pending assignment reassigns it to an eligible approver', function () {
    $admin = User::factory()->admin()->create();
    $oldApprover = User::factory()->approver('Job Order')->create();
    $newApprover = User::factory()->approver('Job Order')->create();
    $assignment = pendingHandoffAssignmentFor($oldApprover, 'Job Order');
    $originalDeadline = $assignment->sla_expires_at;

    $this->actingAs($admin)->post(route('admin.users.toggle', $oldApprover), ['reason' => 'test deactivation'])->assertRedirect();

    $fresh = $assignment->fresh();
    expect($fresh->user_id)->toBe($newApprover->user_id)
        ->and($fresh->reassigned_from)->toBe($oldApprover->user_id)
        ->and($fresh->reassigned_at)->not->toBeNull()
        ->and($fresh->sla_expires_at->timestamp)->toBe($originalDeadline->timestamp) // untouched
        ->and($fresh->individual_status)->toBe('pending');

    expect(NotificationRecord::where('recipient_id', $newApprover->user_id)->exists())->toBeTrue();
    expect(AuditLog::where('action_type', 'assignment_reassigned')->exists())->toBeTrue();
    expect($oldApprover->fresh()->is_active)->toBeFalse();
});

test('reassignment picks the least busy eligible approver when more than one exists', function () {
    $admin = User::factory()->admin()->create();
    $oldApprover = User::factory()->approver('Job Order')->create();
    $busyApprover = User::factory()->approver('Job Order')->create();
    $freeApprover = User::factory()->approver('Job Order')->create();

    // Give the "busy" one two other unrelated pending assignments already.
    pendingHandoffAssignmentFor($busyApprover, 'Job Order', 'Other Stage 1');
    pendingHandoffAssignmentFor($busyApprover, 'Job Order', 'Other Stage 2');

    $assignment = pendingHandoffAssignmentFor($oldApprover, 'Job Order', 'Review');

    $this->actingAs($admin)->post(route('admin.users.toggle', $oldApprover), ['reason' => 'test deactivation'])->assertRedirect();

    expect($assignment->fresh()->user_id)->toBe($freeApprover->user_id);
});

test('a deactivation reason is stored on the reassigned assignment and audit log', function () {
    $admin = User::factory()->admin()->create();
    $oldApprover = User::factory()->approver('Job Order')->create();
    $newApprover = User::factory()->approver('Job Order')->create();
    $assignment = pendingHandoffAssignmentFor($oldApprover, 'Job Order');

    $this->actingAs($admin)->post(route('admin.users.toggle', $oldApprover), [
        'reason' => 'Resigned from the company.',
    ])->assertRedirect();

    expect($assignment->fresh()->reassignment_reason)->toBe('Resigned from the company.');

    $log = AuditLog::where('action_type', 'assignment_reassigned')->firstOrFail();
    expect($log->description)->toContain('Resigned from the company.');

    $toggleLog = AuditLog::where('action_type', 'user_toggle')->latest('log_id')->firstOrFail();
    expect($toggleLog->description)->toContain('Resigned from the company.');
});

test('when no eligible approver exists, the assignment is auto-approved immediately instead of escalated to SLA or parked in a queue', function () {
    $admin = User::factory()->admin()->create();
    // The ONLY approver for this category — no one else eligible.
    $onlyApprover = User::factory()->approver('Service Report')->create();
    $assignment = pendingHandoffAssignmentFor($onlyApprover, 'Service Report');

    $this->actingAs($admin)->post(route('admin.users.toggle', $onlyApprover), ['reason' => 'test deactivation'])->assertRedirect();

    $fresh = $assignment->fresh();
    expect($fresh->individual_status)->toBe('approved')
        ->and($fresh->auto_approved)->toBeTrue()
        ->and(SlaViolation::where('assignment_id', $fresh->assignment_id)->exists())->toBeFalse() // must never look like an SLA miss
        ->and($fresh->reassigned_from)->toBe($onlyApprover->user_id) // recorded for the "Admin note" display
        ->and($fresh->user_id)->toBe($onlyApprover->user_id); // stays with them, just flagged

    expect(SlaViolation::where('assignment_id', $fresh->assignment_id)->exists())->toBeFalse();
    expect(AdminViolation::where('assignment_id', $fresh->assignment_id)
        ->where('violation_type', 'missed_approval')->exists())->toBeTrue();
    expect(AuditLog::where('action_type', 'auto_approve_no_approver')->exists())->toBeTrue();
});

test('reassigning one pending stage does not touch a sibling stage another approver already approved', function () {
    $admin = User::factory()->admin()->create();
    $approver1 = User::factory()->approver('Job Order')->create();
    $approver2 = User::factory()->approver('Job Order')->create();
    $newApprover = User::factory()->approver('Job Order')->create();

    $originator = User::factory()->originator()->create();
    $stageA = WorkflowStage::firstOrCreate(['document_category' => 'Job Order', 'stage_name' => 'Stage A'], ['sequence_order' => 1]);
    $stageB = WorkflowStage::firstOrCreate(['document_category' => 'Job Order', 'stage_name' => 'Stage B'], ['sequence_order' => 2]);
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'multi-stage.txt', 'file_path' => 'documents/multi.txt',
        'mime_type' => 'text/plain', 'due_date' => now()->addDay(), 'global_status' => 'classified_validated', 'ml_category' => 'Job Order',
    ]);

    $assignmentA = DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => $approver1->user_id, 'stage_id' => $stageA->stage_id,
        'due_date' => $document->due_date, 'priority_rank' => 2, 'individual_status' => 'pending', 'sla_expires_at' => now()->addHours(3),
    ]);
    $assignmentB = DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => $approver2->user_id, 'stage_id' => $stageB->stage_id,
        'due_date' => $document->due_date, 'priority_rank' => 2, 'individual_status' => 'approved', 'acted_at' => now(), 'sla_expires_at' => now()->addHours(3),
    ]);

    $this->actingAs($admin)->post(route('admin.users.toggle', $approver1), ['reason' => 'test deactivation'])->assertRedirect();

    expect($assignmentA->fresh()->user_id)->toBe($newApprover->user_id)
        ->and($assignmentB->fresh()->individual_status)->toBe('approved')
        ->and($assignmentB->fresh()->user_id)->toBe($approver2->user_id); // completely untouched
});

test('reactivating an approver requires no reason and does not touch any assignments', function () {
    $admin = User::factory()->admin()->create();
    $approver = User::factory()->approver('Job Order')->create(['is_active' => false]);

    $this->actingAs($admin)->post(route('admin.users.toggle', $approver))->assertRedirect();

    expect($approver->fresh()->is_active)->toBeTrue();
});

test('the workflow stage list shows a Reassigned badge for a handed-off stage', function () {
    $admin = User::factory()->admin()->create();
    $oldApprover = User::factory()->approver('Job Order')->create(['full_name' => 'Old Approver']);
    $newApprover = User::factory()->approver('Job Order')->create(['full_name' => 'New Approver']);
    $assignment = pendingHandoffAssignmentFor($oldApprover, 'Job Order');

    $this->actingAs($admin)->post(route('admin.users.toggle', $oldApprover), ['reason' => 'test deactivation']);

    $response = $this->actingAs($newApprover)->get(route('approver.dashboard'));
    $response->assertOk();
    $response->assertSee('Reassigned');
    $response->assertSee('Old Approver');
});
