<?php

use App\Models\DocumentAnnotation;
use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\DocumentRevision;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\DocxRichContentService;
use App\Services\WorkflowService;
use Illuminate\Support\Facades\Storage;
use Tests\Support\DocxFixtureBuilder;

/**
 * WorkflowService::saveDocxRevision() — "edit anywhere, text only" for a
 * real .docx (see DocxRichContentService::applyTextEdits()): the
 * originator can correct any text in the document, not just a
 * pre-flagged passage, and separately checks off which open flags this
 * save addresses — same decoupled shape as saveDocumentRevision() for
 * every other file type. Everything here exercises the FULL path: a
 * real .docx on the fake disk, through the service, back out through
 * Archive's own file.
 *
 * Fixture segment order (see DocxFixtureBuilder): 0 "Label: ",
 * 1 "value text", 2 "Cell A", 3 "Cell B", 4 "After image".
 */
function seedDocxDocument(): array
{
    Storage::fake('local');

    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $stage = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Review', 'sequence_order' => 1]);

    $relativePath = 'documents/revision-test.docx';
    Storage::makeDirectory('documents');
    DocxFixtureBuilder::build(Storage::path($relativePath));

    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'revision-test.docx', 'file_path' => $relativePath,
        'original_filename' => 'revision-test.docx',
        'mime_type' => DocumentRepository::RICH_DOCX_MIME,
        'due_date' => now()->addDay(), 'global_status' => 'classified_validated', 'ml_category' => 'Job Order',
        'ocr_text' => "Label: value text\nCell A\nCell B\nAfter image\n",
    ]);

    $assignment = DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => $approver->user_id, 'stage_id' => $stage->stage_id,
        'due_date' => $document->due_date, 'priority_rank' => 1, 'individual_status' => 'pending',
        'sla_expires_at' => now()->addHours(3),
    ]);

    return compact('originator', 'approver', 'document', 'assignment');
}

it('corrects any text in the real .docx file, including inside a table, and marks the checked annotation resolved', function () {
    ['originator' => $originator, 'approver' => $approver, 'document' => $document, 'assignment' => $assignment] = seedDocxDocument();

    $annotation = DocumentAnnotation::create([
        'document_id' => $document->document_id, 'assignment_id' => $assignment->assignment_id,
        'raised_by' => $approver->user_id, 'start_offset' => 7, 'end_offset' => 17,
        'selected_text' => 'value text', 'comment' => 'Please fix this.',
    ]);

    app(WorkflowService::class)->saveDocxRevision(
        $document, $originator,
        ['Label: ', 'corrected value', 'Cell X', 'Cell B', 'After image'],
        [$annotation->annotation_id]
    );

    $document->refresh();
    $annotation->refresh();

    expect($document->ocr_text)->toBe("Label: corrected value\nCell X\nCell B\nAfter image\n")
        ->and($annotation->resolved_at)->not->toBeNull();

    expect(DocumentRevision::where('document_id', $document->document_id)->count())->toBe(1);
    $revision = DocumentRevision::where('document_id', $document->document_id)->first();
    expect($revision->previous_text)->toBe("Label: value text\nCell A\nCell B\nAfter image\n")
        ->and($revision->new_text)->toBe($document->ocr_text)
        ->and($revision->annotations->pluck('annotation_id')->all())->toBe([$annotation->annotation_id]);

    // The actual file on disk really was edited (including the table
    // cell, which was never flagged at all), not just the DB field.
    $rendered = app(DocxRichContentService::class)->render(Storage::path($document->file_path));
    expect($rendered['flat_text'])->toBe($document->ocr_text)
        ->and($rendered['html'])->toContain('corrected value')
        ->and($rendered['html'])->toContain('Cell X')
        ->and($rendered['html'])->toContain('<table')
        ->and($rendered['html'])->toContain('<img src="data:image/png;base64,');
});

it('resolving a flag is independent of which text actually changed — same decoupling as every other file type', function () {
    ['originator' => $originator, 'approver' => $approver, 'document' => $document, 'assignment' => $assignment] = seedDocxDocument();

    $annotation = DocumentAnnotation::create([
        'document_id' => $document->document_id, 'assignment_id' => $assignment->assignment_id,
        'raised_by' => $approver->user_id, 'start_offset' => 18, 'end_offset' => 24,
        'selected_text' => 'Cell A', 'comment' => 'A separate concern entirely.',
    ]);

    // Nothing actually changed — same text submitted back — but the flag
    // is still checked off as addressed.
    app(WorkflowService::class)->saveDocxRevision(
        $document, $originator,
        ['Label: ', 'value text', 'Cell A', 'Cell B', 'After image'],
        [$annotation->annotation_id]
    );

    expect($annotation->refresh()->resolved_at)->not->toBeNull();
});

it('only resolves annotations actually checked, leaving other open flags untouched', function () {
    ['originator' => $originator, 'approver' => $approver, 'document' => $document, 'assignment' => $assignment] = seedDocxDocument();

    $touched = DocumentAnnotation::create([
        'document_id' => $document->document_id, 'assignment_id' => $assignment->assignment_id,
        'raised_by' => $approver->user_id, 'start_offset' => 7, 'end_offset' => 17,
        'selected_text' => 'value text', 'comment' => 'Fix this.',
    ]);
    $untouched = DocumentAnnotation::create([
        'document_id' => $document->document_id, 'assignment_id' => $assignment->assignment_id,
        'raised_by' => $approver->user_id, 'start_offset' => 18, 'end_offset' => 24,
        'selected_text' => 'Cell A', 'comment' => 'A separate concern.',
    ]);

    app(WorkflowService::class)->saveDocxRevision(
        $document, $originator,
        ['Label: ', 'corrected value', 'Cell A', 'Cell B', 'After image'],
        [$touched->annotation_id]
    );

    expect($touched->refresh()->resolved_at)->not->toBeNull()
        ->and($untouched->refresh()->resolved_at)->toBeNull();
});

it('rejects the whole save when a structural change is attempted (segment count no longer matches)', function () {
    ['document' => $document, 'originator' => $originator] = seedDocxDocument();

    expect(fn () => app(WorkflowService::class)->saveDocxRevision(
        $document, $originator,
        ['Label: ', 'corrected value', 'Cell A'], // missing two real segments
        []
    ))->toThrow(RuntimeException::class);

    // Nothing was written — same ocr_text as before the attempt.
    expect($document->refresh()->ocr_text)->toBe("Label: value text\nCell A\nCell B\nAfter image\n");
});
