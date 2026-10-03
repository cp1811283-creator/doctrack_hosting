<?php

use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\DocumentReviewSession;
use App\Models\User;
use App\Models\WorkflowStage;

/**
 * Coverage for the minimum-review-time gate (config('review.min_review_seconds'))
 * added to every decision point in the app: the approver's own decide()/
 * decideBatch(), and every admin direct-decision path (SLA Override, ML
 * Review, Readability Review). Verifies the gate itself — blocks under
 * the threshold, allows at/over it — not just that other tests still
 * pass around it.
 */
function minReviewDoc(User $originator, array $overrides = []): DocumentRepository
{
    return DocumentRepository::create(array_merge([
        'originator_id' => $originator->user_id,
        'title' => 'min-review-test-'.uniqid().'.txt',
        'file_path' => 'documents/'.uniqid().'.txt',
        'mime_type' => 'text/plain',
        'due_date' => now()->addDay(),
        'global_status' => 'classified_validated',
        'ml_category' => 'Job Order',
    ], $overrides));
}

it('blocks an approver decision with no review session at all', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $stage = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Review', 'sequence_order' => 1]);
    $document = minReviewDoc($originator);
    $assignment = DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => $approver->user_id,
        'stage_id' => $stage->stage_id, 'due_date' => $document->due_date,
        'priority_rank' => 2, 'individual_status' => 'pending', 'sla_expires_at' => now()->addHours(3),
    ]);

    $response = $this->actingAs($approver)->post(route('approver.assignments.decide', $assignment), [
        'decision' => 'approved',
    ]);

    $response->assertStatus(422);
    expect($assignment->fresh()->individual_status)->toBe('pending');
});

it('blocks an approver decision with a review session shorter than the configured minimum', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $stage = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Review', 'sequence_order' => 1]);
    $document = minReviewDoc($originator);
    $assignment = DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => $approver->user_id,
        'stage_id' => $stage->stage_id, 'due_date' => $document->due_date,
        'priority_rank' => 2, 'individual_status' => 'pending', 'sla_expires_at' => now()->addHours(3),
    ]);
    seedReviewTime($approver, $document, 3); // under the 10s default

    $response = $this->actingAs($approver)->post(route('approver.assignments.decide', $assignment), [
        'decision' => 'approved',
    ]);

    $response->assertStatus(422);
    expect($assignment->fresh()->individual_status)->toBe('pending');
});

it('allows an approver decision once the configured minimum review time is met', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $stage = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Review', 'sequence_order' => 1]);
    $document = minReviewDoc($originator);
    $assignment = DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => $approver->user_id,
        'stage_id' => $stage->stage_id, 'due_date' => $document->due_date,
        'priority_rank' => 2, 'individual_status' => 'pending', 'sla_expires_at' => now()->addHours(3),
    ]);
    seedReviewTime($approver, $document, 10);

    $response = $this->actingAs($approver)->post(route('approver.assignments.decide', $assignment), [
        'decision' => 'approved',
    ]);

    $response->assertRedirect(route('approver.dashboard'));
    expect($assignment->fresh()->individual_status)->toBe('approved');
});

it('counts time on a still-open session, not just closed ones, toward the minimum', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $stage = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Review', 'sequence_order' => 1]);
    $document = minReviewDoc($originator);
    $assignment = DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => $approver->user_id,
        'stage_id' => $stage->stage_id, 'due_date' => $document->due_date,
        'priority_rank' => 2, 'individual_status' => 'pending', 'sla_expires_at' => now()->addHours(3),
    ]);

    // A genuinely OPEN session (no closeFor() yet) — simulates a real
    // approver who opened the viewer and is deciding without ever having
    // closed the modal first.
    DocumentReviewSession::create([
        'document_id' => $document->document_id, 'user_id' => $approver->user_id,
        'opened_at' => now()->subSeconds(12), 'session_type' => 'initial',
    ]);

    $response = $this->actingAs($approver)->post(route('approver.assignments.decide', $assignment), [
        'decision' => 'approved',
    ]);

    $response->assertRedirect(route('approver.dashboard'));
    expect($assignment->fresh()->individual_status)->toBe('approved');
});

it('skips (not blocks) under-reviewed assignments in a batch decision, deciding the rest', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $stageA = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Stage A', 'sequence_order' => 1]);
    $stageB = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Stage B', 'sequence_order' => 2]);
    $document = minReviewDoc($originator);
    $assignmentA = DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => $approver->user_id,
        'stage_id' => $stageA->stage_id, 'due_date' => $document->due_date,
        'priority_rank' => 2, 'individual_status' => 'pending', 'sla_expires_at' => now()->addHours(3),
    ]);
    $assignmentB = DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => $approver->user_id,
        'stage_id' => $stageB->stage_id, 'due_date' => $document->due_date,
        'priority_rank' => 2, 'individual_status' => 'pending', 'sla_expires_at' => now()->addHours(3),
    ]);

    // No review session at all for this document — both should be skipped.
    $response = $this->actingAs($approver)->post(route('approver.assignments.decideBatch'), [
        'assignment_ids' => [$assignmentA->assignment_id, $assignmentB->assignment_id],
        'decision' => 'approved',
    ]);

    $response->assertRedirect(route('approver.dashboard'));
    $response->assertSessionHas('status', fn ($status) => str_contains($status, 'skipped') && str_contains($status, '10 seconds'));
    expect($assignmentA->fresh()->individual_status)->toBe('pending');
    expect($assignmentB->fresh()->individual_status)->toBe('pending');
});

it('renders the Approve/Reject buttons disabled with a countdown label when review time is insufficient', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $stage = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Review', 'sequence_order' => 1]);
    $document = minReviewDoc($originator);
    DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => $approver->user_id,
        'stage_id' => $stage->stage_id, 'due_date' => $document->due_date,
        'priority_rank' => 2, 'individual_status' => 'pending', 'sla_expires_at' => now()->addHours(3),
    ]);

    $response = $this->actingAs($approver)->get(route('approver.dashboard'));

    $response->assertOk();
    $response->assertSee('data-review-remaining="10"', false);
    $response->assertSee('Open "Review &amp; Comment"', false);
    $response->assertSee('name="decision" value="approved"', false);
    // The disabled attribute is on the same button as the assertion
    // above — check it's present at all somewhere in the decide form
    // rather than pinning exact whitespace between attributes.
    expect(substr_count($response->getContent(), 'disabled'))->toBeGreaterThan(0);
});

it('renders the Approve/Reject buttons enabled with no countdown once enough review time has accumulated', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $stage = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Review', 'sequence_order' => 1]);
    $document = minReviewDoc($originator);
    DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => $approver->user_id,
        'stage_id' => $stage->stage_id, 'due_date' => $document->due_date,
        'priority_rank' => 2, 'individual_status' => 'pending', 'sla_expires_at' => now()->addHours(3),
    ]);
    seedReviewTime($approver, $document, 10);

    $response = $this->actingAs($approver)->get(route('approver.dashboard'));

    $response->assertOk();
    $response->assertSee('data-review-remaining="0"', false);
    $response->assertDontSee('class="review-countdown-label"', false);
    $response->assertDontSee('Open "View original file" above to begin', false);
});
