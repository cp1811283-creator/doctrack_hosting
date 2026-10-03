<?php

use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\User;
use App\Models\WorkflowStage;

/**
 * Regression coverage for a real reported bug (confirmed 2026-10-03): once
 * an approver has resolved every seat they hold on a document, the queue
 * card showed "Your decision is recorded" regardless of whether they
 * actually decided it themselves or the system auto-approved it because
 * they missed the deadline — factually wrong for the second case. See
 * approver/partials/queue.blade.php.
 */
function inFlightDoc(User $originator, string $title): DocumentRepository
{
    return DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => $title, 'file_path' => 'documents/'.$title,
        'mime_type' => 'text/plain', 'ml_category' => 'Job Order', 'is_validated' => true,
        'due_date' => now()->addDay(),
        // Not a terminal status — keeps the document "in flight" per
        // ApprovalController::resolvedButInFlightQueryFor().
        'global_status' => 'classified_validated',
    ]);
}

it('shows an honest "you missed this deadline" message for a seat that was auto-approved, not "your decision is recorded"', function () {
    $originator = User::factory()->originator()->create();
    $stage1 = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Technical Review', 'sequence_order' => 1]);
    $stage2 = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Budget Check', 'sequence_order' => 2]);

    $approver1 = User::factory()->approver('Job Order')->create();
    $approver2 = User::factory()->approver('Job Order')->create();

    $doc = inFlightDoc($originator, 'auto-approved-seat-doc.txt');

    // approver1's own seat was auto-approved — they missed the deadline.
    DocumentAssignment::create([
        'document_id' => $doc->document_id, 'user_id' => $approver1->user_id, 'stage_id' => $stage1->stage_id,
        'due_date' => $doc->due_date, 'priority_rank' => 2,
        'individual_status' => 'auto_approved', 'auto_approved' => true, 'acted_at' => now(),
    ]);
    // Document still in flight — stage 2 still pending for approver2.
    DocumentAssignment::create([
        'document_id' => $doc->document_id, 'user_id' => $approver2->user_id, 'stage_id' => $stage2->stage_id,
        'due_date' => $doc->due_date, 'priority_rank' => 2,
        'individual_status' => 'pending', 'sla_expires_at' => now()->addHours(2),
    ]);

    $response = $this->actingAs($approver1)->get(route('approver.dashboard'));

    $response->assertOk()
        ->assertSee('Auto-approved — you missed this deadline.')
        ->assertDontSee('Your decision is recorded');
});

it('still shows "your decision is recorded" for a seat the approver genuinely decided themselves', function () {
    $originator = User::factory()->originator()->create();
    $stage1 = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Technical Review', 'sequence_order' => 1]);
    $stage2 = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Budget Check', 'sequence_order' => 2]);

    $approver1 = User::factory()->approver('Job Order')->create();
    $approver2 = User::factory()->approver('Job Order')->create();

    $doc = inFlightDoc($originator, 'genuine-decision-doc.txt');

    // approver1 genuinely decided this one themselves — never auto-approved.
    DocumentAssignment::create([
        'document_id' => $doc->document_id, 'user_id' => $approver1->user_id, 'stage_id' => $stage1->stage_id,
        'due_date' => $doc->due_date, 'priority_rank' => 2,
        'individual_status' => 'approved', 'auto_approved' => false, 'acted_at' => now(),
    ]);
    DocumentAssignment::create([
        'document_id' => $doc->document_id, 'user_id' => $approver2->user_id, 'stage_id' => $stage2->stage_id,
        'due_date' => $doc->due_date, 'priority_rank' => 2,
        'individual_status' => 'pending', 'sla_expires_at' => now()->addHours(2),
    ]);

    $response = $this->actingAs($approver1)->get(route('approver.dashboard'));

    $response->assertOk()
        ->assertSee('Your decision is recorded.')
        ->assertDontSee('Auto-approved — you missed this deadline.');
});
