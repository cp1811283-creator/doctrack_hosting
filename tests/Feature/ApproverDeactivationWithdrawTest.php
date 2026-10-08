<?php

use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\SlaViolation;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\WorkflowService;
use Illuminate\Support\Facades\Queue;

/**
 * Queue::fake() is required in every test here: the test env's `sync`
 * queue driver ignores dispatch(...)->delay() entirely and runs
 * EscalateAssignmentJob immediately in-process, and that job has no
 * time-based guard of its own (it only checks the seat's status and deadline —
 * see its docblock, it trusts the queue's delay to not fire early). Real
 * dev/prod uses a `database` queue with an actual worker that honors
 * delays, so this never happens there — but without faking the queue here,
 * every routed assignment would already have been auto-approved by the
 * SLA safety net before deactivation ever runs, making these tests meaningless.
 */
beforeEach(fn () => Queue::fake());

/**
 * ApproverDeactivationHandoffTest.php covers findReplacementApprover()/
 * reassignAssignment() using manually-crafted single-row assignments — the
 * one genuine case a replacement is ever found (an approver who becomes
 * eligible only AFTER routing). It never exercises what actually happens
 * for a REAL document, routed the normal way via
 * WorkflowService::routeToWorkflow() -> assignStage(), which seats EVERY
 * eligible approver on a stage at once (the unanimous-approval model).
 * Under that real flow, every other eligible approver already holds their
 * own seat by the time one gets deactivated, so findReplacementApprover()
 * almost never finds anyone — this used to silently fall through to an
 * Admin escalation every time, even when a sibling approver already fully
 * covered the stage. These tests cover the fix: withdraw with no Admin
 * involvement when a real sibling exists, escalate only when nobody's left.
 */
function deactivationWithdrawTestSetup(int $approverCount): array
{
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();
    $stage = WorkflowStage::create([
        'document_category' => 'Job Order', 'stage_name' => 'Review', 'sequence_order' => 1,
    ]);
    $approvers = User::factory()->approver('Job Order')->count($approverCount)->create();
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'deactivation-withdraw-'.uniqid().'.txt',
        'file_path' => 'documents/'.uniqid().'.txt',
        'mime_type' => 'text/plain',
        'due_date' => now()->addDay(),
        'global_status' => 'classified_validated',
        'ml_category' => 'Job Order',
    ]);

    app(WorkflowService::class)->routeToWorkflow($document);

    return [$admin, $approvers, $document, $stage];
}

it('withdraws a deactivated approver\'s seat with no Admin involvement when a sibling already covers the stage', function () {
    [$admin, $approvers, $document] = deactivationWithdrawTestSetup(2);
    [$approverA, $approverB] = $approvers;

    $this->actingAs($admin)->post(route('admin.users.toggle', $approverA), ['reason' => 'left the company']);

    $seatA = DocumentAssignment::where('document_id', $document->document_id)->where('user_id', $approverA->user_id)->first();
    $seatB = DocumentAssignment::where('document_id', $document->document_id)->where('user_id', $approverB->user_id)->first();

    expect($seatA->individual_status)->toBe('withdrawn');
    expect(SlaViolation::where('assignment_id', $seatA->assignment_id)->exists())->toBeFalse(); // deactivation is never an SLA miss
    expect($seatB->individual_status)->toBe('pending');
    expect(SlaViolation::where('assignment_id', $seatB->assignment_id)->exists())->toBeFalse(); // deactivation is never an SLA miss
});

it('auto-approves immediately (not an SLA escalation) when the deactivated approver was the only one on the stage', function () {
    [$admin, $approvers, $document] = deactivationWithdrawTestSetup(1);
    [$approverA] = $approvers;

    $this->actingAs($admin)->post(route('admin.users.toggle', $approverA), ['reason' => 'left the company']);

    $seatA = DocumentAssignment::where('document_id', $document->document_id)->where('user_id', $approverA->user_id)->first();

    expect($seatA->individual_status)->toBe('approved');
    expect($seatA->auto_approved)->toBeTrue();
    expect(SlaViolation::where('assignment_id', $seatA->assignment_id)->exists())->toBeFalse(); // deactivation is never an SLA miss
});

it('finalizes the document once the remaining approver decides after a withdrawal', function () {
    [$admin, $approvers, $document] = deactivationWithdrawTestSetup(2);
    [$approverA, $approverB] = $approvers;

    $this->actingAs($admin)->post(route('admin.users.toggle', $approverA), ['reason' => 'test deactivation']);

    $seatB = DocumentAssignment::where('document_id', $document->document_id)->where('user_id', $approverB->user_id)->first();
    seedReviewTime($approverB, $document);
    $this->actingAs($approverB)->post(route('approver.assignments.decide', $seatB), ['decision' => 'approved']);

    expect($document->fresh()->global_status)->toBe('approved');
});

it('auto-approves the last real approver instead of withdrawing, when a prior sibling seat on the stage was already withdrawn', function () {
    [$admin, $approvers, $document] = deactivationWithdrawTestSetup(2);
    [$approverA, $approverB] = $approvers;

    // First deactivation: A withdraws, covered by B.
    $this->actingAs($admin)->post(route('admin.users.toggle', $approverA), ['reason' => 'test deactivation']);
    // Second deactivation: B is now the only REAL seat left — must
    // auto-approve immediately, not withdraw (A's row is already
    // withdrawn and doesn't count as cover).
    $this->actingAs($admin)->post(route('admin.users.toggle', $approverB), ['reason' => 'test deactivation']);

    $seatA = DocumentAssignment::where('document_id', $document->document_id)->where('user_id', $approverA->user_id)->first();
    $seatB = DocumentAssignment::where('document_id', $document->document_id)->where('user_id', $approverB->user_id)->first();

    expect($seatA->individual_status)->toBe('withdrawn');
    expect($seatB->individual_status)->toBe('approved');
    expect($seatB->auto_approved)->toBeTrue();
    expect(SlaViolation::where('assignment_id', $seatB->assignment_id)->exists())->toBeFalse(); // deactivation is never an SLA miss
});

it('excludes a withdrawn seat from the workflow-stage-list progress count', function () {
    [$admin, $approvers, $document] = deactivationWithdrawTestSetup(2);
    [$approverA, $approverB] = $approvers;

    $this->actingAs($admin)->post(route('admin.users.toggle', $approverA), ['reason' => 'test deactivation']);

    $response = $this->actingAs($approverB)->get(route('approver.dashboard'));
    $response->assertOk();
    // Only 1 real seat left on the stage — must not show "N of 2".
    $response->assertDontSee('of 2 approved');
});
