<?php

use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\User;
use App\Models\WorkflowStage;
use Illuminate\Support\Facades\Storage;
use Tests\Support\DocxFixtureBuilder;

/**
 * .docx is the one mime type viewFile() still streams as the real file's
 * raw bytes (every other type now serves its extracted TEXT instead —
 * see DocumentController::viewFile()'s own comment, and
 * DocxRevisionTest/DocxRichContentServiceTest for that feature's actual
 * coverage) — so it's the type this disk-resolution regression test now
 * has to use to still exercise what it was written to guard.
 */
it('verifies viewFile() returns the exact uploaded .docx bytes on the local disk (default disk resolution)', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Review', 'sequence_order' => 1]);

    $relativePath = 'documents/local-disk-verify-'.uniqid().'.docx';
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'local-disk-verify.docx',
        'file_path' => $relativePath,
        'mime_type' => DocumentRepository::RICH_DOCX_MIME,
        'due_date' => now()->addDay(),
        'global_status' => 'classified_validated',
        'ml_category' => 'Job Order',
    ]);
    // Written via the DEFAULT disk (no explicit ->disk('local')) — exactly
    // what WorkflowService::ingest() now does after the fix this test
    // originally guarded.
    Storage::makeDirectory('documents');
    DocxFixtureBuilder::build(Storage::path($relativePath));
    $rawBytes = Storage::get($relativePath);

    DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => $approver->user_id,
        'stage_id' => WorkflowStage::first()->stage_id, 'due_date' => $document->due_date,
        'priority_rank' => 2, 'individual_status' => 'pending', 'sla_expires_at' => now()->addHours(3),
    ]);

    $response = $this->actingAs($approver)->get(route('documents.file', $document));

    $response->assertOk();
    expect($response->streamedContent())->toBe($rawBytes);
    expect($response->headers->get('Content-Type'))->toContain(DocumentRepository::RICH_DOCX_MIME);
});
