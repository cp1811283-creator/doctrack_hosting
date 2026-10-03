<?php

use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Models\WorkflowStageDepartment;
use App\Services\WorkflowService;

/**
 * Regression coverage for a real reported bug (2026-10-03): every category's
 * Final Approval stage requires a head, but assigned_category + per-stage
 * picks together meant a head set up for one category was silently
 * ineligible for every other category's Final Approval — those documents
 * auto-approved with nobody actually reviewing them (confirmed against
 * production: only 2 head accounts existed, both pinned to Job Order, so
 * Purchase Requisition and Service Report had zero eligible Final Approval
 * reviewers). See WorkflowService::eligibleApproversForStage()'s docblock.
 */
function finalApprovalDoc(string $category): DocumentRepository
{
    $originator = User::factory()->originator()->create();

    return DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'doc.txt', 'file_path' => 'documents/doc.txt', 'mime_type' => 'text/plain',
        'ml_category' => $category, 'is_validated' => true,
        'due_date' => now()->addHours(4), 'global_status' => 'classified_validated',
    ]);
}

it('makes a head eligible for Final Approval in a category other than their assigned_category', function () {
    $jobOrderFinal = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Final Approval', 'sequence_order' => 1]);
    $prFinal = WorkflowStage::create(['document_category' => 'Purchase Requisition', 'stage_name' => 'Final Approval', 'sequence_order' => 1]);
    WorkflowStageDepartment::create(['stage_id' => $jobOrderFinal->stage_id, 'department' => 'Engineering']);
    WorkflowStageDepartment::create(['stage_id' => $prFinal->stage_id, 'department' => 'Engineering']);

    $head = User::factory()->approver('Job Order')->create(['department' => 'Engineering', 'level' => 'head']);

    $document = finalApprovalDoc('Purchase Requisition');
    app(WorkflowService::class)->routeToWorkflow($document);

    $seated = DocumentAssignment::where('document_id', $document->document_id)->pluck('user_id');

    expect($seated->all())->toBe([$head->user_id]);
});

it('ignores a head\'s explicit stage picks on Final Approval across categories', function () {
    $jobOrderFinal = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Final Approval', 'sequence_order' => 1]);
    $prFinal = WorkflowStage::create(['document_category' => 'Purchase Requisition', 'stage_name' => 'Final Approval', 'sequence_order' => 1]);

    $head = User::factory()->approver('Job Order')->create(['department' => 'Engineering', 'level' => 'head']);
    // Explicitly picked ONLY Job Order's own Final Approval stage_id — the
    // exact leftover state the real production accounts had.
    $head->workflowStages()->sync([$jobOrderFinal->stage_id]);

    $document = finalApprovalDoc('Purchase Requisition');
    app(WorkflowService::class)->routeToWorkflow($document);

    $seated = DocumentAssignment::where('document_id', $document->document_id)
        ->where('stage_id', $prFinal->stage_id)
        ->pluck('user_id');

    expect($seated->all())->toBe([$head->user_id]);
});

it('still requires department to match for a head on a department-owned Final Approval stage', function () {
    $prFinal = WorkflowStage::create(['document_category' => 'Purchase Requisition', 'stage_name' => 'Final Approval', 'sequence_order' => 1]);
    WorkflowStageDepartment::create(['stage_id' => $prFinal->stage_id, 'department' => 'Finance']);

    $engineeringHead = User::factory()->approver('Job Order')->create(['department' => 'Engineering', 'level' => 'head']);
    $financeHead = User::factory()->approver('Service Report')->create(['department' => 'Finance', 'level' => 'head']);

    $document = finalApprovalDoc('Purchase Requisition');
    app(WorkflowService::class)->routeToWorkflow($document);

    $seated = DocumentAssignment::where('document_id', $document->document_id)->pluck('user_id');

    expect($seated->all())->toBe([$financeHead->user_id])
        ->and($seated->all())->not->toContain($engineeringHead->user_id);
});

it('creates a head account with no assigned category, regardless of what the (hidden) category field submits', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post(route('admin.users.store'), [
        'username' => 'newhead',
        'full_name' => 'New Head',
        'email' => 'newhead@example.com',
        'role' => 'approver',
        'assigned_category' => 'Job Order', // still submitted by the hidden <select>'s default option
        'department' => 'Engineering',
        'level' => 'head',
        'password' => 'Qz8kVn4RTwmp',
    ])->assertSessionDoesntHaveErrors();

    $created = User::where('username', 'newhead')->firstOrFail();

    expect($created->assigned_category)->toBeNull()
        ->and($created->department)->toBe('Engineering')
        ->and($created->level)->toBe('head')
        ->and($created->workflowStages()->count())->toBe(0);
});

it('updating an approver to head clears their assigned category and stage picks', function () {
    $admin = User::factory()->admin()->create();
    $stage = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Technical Review', 'sequence_order' => 1]);
    $approver = User::factory()->approver('Job Order')->create(['department' => 'Engineering', 'level' => 'staff']);
    $approver->workflowStages()->sync([$stage->stage_id]);

    $this->actingAs($admin)->post(route('admin.users.stages.update', $approver), [
        'assigned_category' => 'Job Order', // still submitted by the hidden field's default option
        'department' => 'Engineering',
        'level' => 'head',
    ])->assertSessionDoesntHaveErrors();

    $approver->refresh();

    expect($approver->assigned_category)->toBeNull()
        ->and($approver->level)->toBe('head')
        ->and($approver->workflowStages()->count())->toBe(0);
});

it('never offers Final Approval as a specific-stage pick on the Create Account form, even though other stages in the same category still show', function () {
    // A Staff account can never actually hold Final Approval regardless of
    // what's checked (WorkflowService::eligibleApproversForStage() blocks
    // it on level alone), so offering the checkbox at all is misleading.
    $admin = User::factory()->admin()->create();
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Technical Review', 'sequence_order' => 1]);
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Final Approval', 'sequence_order' => 2]);

    $response = $this->actingAs($admin)->get(route('admin.users'));

    // '2. Final Approval' (with its sequence-number prefix), not the bare
    // phrase — this same page's own hint note legitimately says "Heads sit
    // on Final Approval across every category..." elsewhere, which a bare
    // assertDontSee('Final Approval') would wrongly also match.
    $response->assertOk()
        ->assertSee('Technical Review')
        ->assertDontSee('2. Final Approval');
});

it('never offers Final Approval as a specific-stage pick on the Manage Category & Stages edit modal', function () {
    $admin = User::factory()->admin()->create();
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Technical Review', 'sequence_order' => 1]);
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Final Approval', 'sequence_order' => 2]);
    $approver = User::factory()->approver('Job Order')->create(['department' => 'Engineering', 'level' => 'staff']);

    $response = $this->actingAs($admin)->get(route('admin.users.stages.edit', $approver));

    $response->assertOk()
        ->assertSee('Technical Review')
        ->assertDontSee('2. Final Approval');
});
