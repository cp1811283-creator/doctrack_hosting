<?php

use App\Models\DocumentAnnotation;
use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\TextExtractionService;
use Illuminate\Http\UploadedFile;
use Tests\Support\DocxFixtureBuilder;

/**
 * TextExtractionService::reconstructParagraphs() — Feature: a real PDF
 * upload and a real scanned-image OCR upload were both confirmed to
 * produce one newline per visual line with no (or inconsistent) blank
 * line between genuinely different fields/paragraphs, unlike a plain
 * .txt upload. This reconstructs that distinction uniformly for every
 * non-.docx extraction path.
 */
it('inserts a blank line between genuinely different fields', function () {
    $text = "Requested by: Jane Doe.\nDate requested: June 24, 2026.\nPriority: High";

    expect(TextExtractionService::reconstructParagraphs($text))->toBe(
        "Requested by: Jane Doe.\n\nDate requested: June 24, 2026.\n\nPriority: High"
    );
});

it('keeps a single newline for a line that was only cut off by the page width, not merging it into one line', function () {
    $text = "Description of work: the automatic sliding door at the main entrance has been\n".
        "sticking intermittently and failing to close fully on some cycles, creating a\n".
        'security concern.';

    $result = TextExtractionService::reconstructParagraphs($text);

    expect($result)->toBe(
        "Description of work: the automatic sliding door at the main entrance has been\n".
        "sticking intermittently and failing to close fully on some cycles, creating a\n".
        'security concern.'
    )
        // Specifically NOT joined into one run-on line — only the BLANK
        // line between genuine boundaries is new, every original line
        // break stays exactly where it was.
        ->and(substr_count($result, "\n\n"))->toBe(0);
});

it('handles a short document, an empty string, and a single line without error', function () {
    expect(TextExtractionService::reconstructParagraphs(''))->toBe('')
        ->and(TextExtractionService::reconstructParagraphs('Just one line.'))->toBe('Just one line.')
        ->and(TextExtractionService::reconstructParagraphs("Line one.\nLine two."))->toBe("Line one.\n\nLine two.");
});

it('applies reconstruction to a plain .txt upload through extract()', function () {
    // Content has to clear TextExtractionService::MIN_USABLE_CHARS (40)
    // or this falls through to the OCR fallback path instead of being
    // read as plain text — unrelated to what this test is checking.
    $file = UploadedFile::fake()->createWithContent('memo.txt', "Field one: a reasonably long value here.\nField two: another reasonably long value.");

    $result = app(TextExtractionService::class)->extract($file);

    expect($result['text'])->toBe("Field one: a reasonably long value here.\n\nField two: another reasonably long value.");
});

it('does NOT reconstruct a .docx — its line breaks are already real paragraph markers', function () {
    $path = sys_get_temp_dir().'/reconstruct-docx-'.uniqid().'.docx';
    DocxFixtureBuilder::build($path);
    $file = new UploadedFile($path, 'sample.docx', DocumentRepository::RICH_DOCX_MIME, null, true);

    $result = app(TextExtractionService::class)->extract($file);

    // Untouched: every line here is already a real paragraph boundary,
    // so a blank line is never inserted even though some of these lines
    // (e.g. "Cell A") are short and would otherwise look like a
    // field-boundary candidate to the heuristic.
    expect($result['text'])->toBe("Label: value text\nCell A\nCell B\nAfter image");

    @unlink($path);
});

it('backfill command reformats an already-uploaded PDF-style document, skipping docx and documents with an open revision flag', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $stage = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Review', 'sequence_order' => 1]);

    $pdfDoc = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'backfill-pdf.pdf', 'file_path' => 'documents/backfill-pdf.pdf',
        'mime_type' => 'application/pdf', 'due_date' => now()->addDay(), 'global_status' => 'classified_validated',
        'ml_category' => 'Job Order', 'ocr_text' => "Requested by: Jane Doe.\nDate requested: June 24, 2026.",
    ]);

    $docxDoc = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'backfill-docx.docx', 'file_path' => 'documents/backfill-docx.docx',
        'mime_type' => DocumentRepository::RICH_DOCX_MIME, 'due_date' => now()->addDay(), 'global_status' => 'classified_validated',
        'ml_category' => 'Job Order', 'ocr_text' => "Label: value\nCell A",
    ]);

    $openFlagDoc = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'backfill-open-flag.pdf', 'file_path' => 'documents/backfill-open-flag.pdf',
        'mime_type' => 'application/pdf', 'due_date' => now()->addDay(), 'global_status' => 'classified_validated',
        'ml_category' => 'Job Order', 'ocr_text' => "Requested by: Jane Doe.\nDate requested: June 24, 2026.",
    ]);
    $assignment = DocumentAssignment::create([
        'document_id' => $openFlagDoc->document_id, 'user_id' => $approver->user_id, 'stage_id' => $stage->stage_id,
        'due_date' => $openFlagDoc->due_date, 'priority_rank' => 1, 'individual_status' => 'pending',
        'sla_expires_at' => now()->addHours(3),
    ]);
    DocumentAnnotation::create([
        'document_id' => $openFlagDoc->document_id, 'assignment_id' => $assignment->assignment_id,
        'raised_by' => $approver->user_id, 'start_offset' => 0, 'end_offset' => 5,
        'selected_text' => 'Reque', 'comment' => 'Fix this.',
    ]);

    $this->artisan('documents:reformat-extracted-paragraphs')->assertSuccessful();

    expect($pdfDoc->refresh()->ocr_text)->toBe("Requested by: Jane Doe.\n\nDate requested: June 24, 2026.")
        ->and($docxDoc->refresh()->ocr_text)->toBe("Label: value\nCell A")
        ->and($openFlagDoc->refresh()->ocr_text)->toBe("Requested by: Jane Doe.\nDate requested: June 24, 2026.");
});

it('backfill --dry-run reports what would change without writing anything', function () {
    $originator = User::factory()->originator()->create();

    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'dry-run.pdf', 'file_path' => 'documents/dry-run.pdf',
        'mime_type' => 'application/pdf', 'due_date' => now()->addDay(), 'global_status' => 'classified_validated',
        'ml_category' => 'Job Order', 'ocr_text' => "Requested by: Jane Doe.\nDate requested: June 24, 2026.",
    ]);

    $this->artisan('documents:reformat-extracted-paragraphs', ['--dry-run' => true])->assertSuccessful();

    expect($document->refresh()->ocr_text)->toBe("Requested by: Jane Doe.\nDate requested: June 24, 2026.");
});
