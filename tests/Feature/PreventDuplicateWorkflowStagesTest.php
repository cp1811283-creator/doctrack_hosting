<?php

use App\Models\DocumentRepository;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\WorkflowService;
use Illuminate\Database\QueryException;

/**
 * Regression coverage for a real reported bug (confirmed 2026-10-03): Job
 * Order ended up with two active "Technical Review" rows and two active
 * "Final Approval" rows — no application code path could reproduce it on
 * demand, but nothing in the schema stopped it either, so a database-level
 * constraint was added as the real fix. See the matching migration's own
 * docblock for the full story.
 */
it('blocks a second active category-wide stage sharing a category and name', function () {
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Technical Review', 'sequence_order' => 1]);

    expect(fn () => WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Technical Review', 'sequence_order' => 99]))
        ->toThrow(QueryException::class);
});

it('allows the same stage name to be reused once the earlier one is archived', function () {
    $original = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Technical Review', 'sequence_order' => 1]);
    $original->update(['is_archived' => true]);

    $replacement = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Technical Review', 'sequence_order' => 1]);

    expect($replacement->exists)->toBeTrue();
});

it('allows the same stage name across different categories', function () {
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Final Approval', 'sequence_order' => 1]);
    $other = WorkflowStage::create(['document_category' => 'Purchase Requisition', 'stage_name' => 'Final Approval', 'sequence_order' => 1]);

    expect($other->exists)->toBeTrue();
});

it('allows document-scoped custom stages to share a category and name across different documents', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();

    $docA = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'custom-a.txt', 'file_path' => 'documents/a.txt',
        'mime_type' => 'text/plain', 'due_date' => now()->addDay(), 'global_status' => 'classified_validated',
        'ml_category' => 'Job Order', 'is_validated' => true,
    ]);
    $docB = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'custom-b.txt', 'file_path' => 'documents/b.txt',
        'mime_type' => 'text/plain', 'due_date' => now()->addDay(), 'global_status' => 'classified_validated',
        'ml_category' => 'Job Order', 'is_validated' => true,
    ]);

    app(WorkflowService::class)->routeToCustomApprovers($docA, [$approver->user_id], $originator);
    app(WorkflowService::class)->routeToCustomApprovers($docB, [$approver->user_id], $originator);

    expect(WorkflowStage::whereNotNull('document_id')->count())->toBe(2);
});
