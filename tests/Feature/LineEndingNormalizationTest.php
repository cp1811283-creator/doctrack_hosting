<?php

use App\Models\DocumentAnnotation;
use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\TextExtractionService;
use App\Services\WorkflowService;
use Illuminate\Http\UploadedFile;

test('normalizeLineEndings collapses CRLF and lone CR to plain LF', function () {
    expect(TextExtractionService::normalizeLineEndings("a\r\nb\rc\nd"))->toBe("a\nb\nc\nd");
});

test('extracting a .txt file with CRLF line endings returns LF-only text', function () {
    // Long enough to clear TextExtractionService::MIN_USABLE_CHARS (40) —
    // a shorter sample would (correctly) fall through to the OCR
    // fallback, which has nothing to read from a plain text file.
    $content = "Line one of the document.\r\nLine two of the document.\r\nLine three of the document.";
    $file = UploadedFile::fake()->createWithContent('notes.txt', $content);

    $result = app(TextExtractionService::class)->extract($file);

    // Every line here ends with "." — reconstructParagraphs() (added
    // 2026-10-03, see ParagraphReconstructionTest) correctly reads each
    // as a genuine line end, not a page-width wrap, and spaces them out
    // accordingly; this test's own concern is just that no \r survives.
    expect($result['text'])->toBe("Line one of the document.\n\nLine two of the document.\n\nLine three of the document.")
        ->and($result['text'])->not->toContain("\r");
});

test('saving a document revision normalizes CRLF in the submitted text', function () {
    $originator = User::factory()->originator()->create();
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'crlf-save-test.txt',
        'file_path' => 'documents/'.uniqid().'.txt',
        'mime_type' => 'text/plain',
        'due_date' => now()->addDay(),
        'global_status' => 'classified_validated',
        'ml_category' => 'Job Order',
        'ocr_text' => 'original',
    ]);

    app(WorkflowService::class)->saveDocumentRevision($document, $originator, "Revised\r\ntext\r\nhere", []);

    expect($document->fresh()->ocr_text)->toBe("Revised\ntext\nhere");
});

test('the backfill migration normalizes stored CRLF text without touching existing annotation offsets', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();

    // A real browser flagging "Target phrase here" would have computed
    // this offset against its own already-CRLF-collapsed DOM — i.e.
    // against what the NORMALIZED text looks like, never against the
    // raw \r\n-containing string still sitting in the database at that
    // point. That's the offset a real annotation actually carries.
    $oldText = "Line one\r\nLine two\r\nTarget phrase here";
    $normalizedText = "Line one\nLine two\nTarget phrase here";
    $browserComputedStart = mb_strpos($normalizedText, 'Target');
    $browserComputedEnd = $browserComputedStart + mb_strlen('Target phrase here');

    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'crlf-migration-test.txt',
        'file_path' => 'documents/'.uniqid().'.txt',
        'mime_type' => 'text/plain',
        'due_date' => now()->addDay(),
        'global_status' => 'classified_validated',
        'ml_category' => 'Job Order',
        'ocr_text' => $oldText,
    ]);

    $stage = WorkflowStage::firstOrCreate(['document_category' => 'Job Order', 'stage_name' => 'Technical Review', 'sequence_order' => 1]);
    $assignment = DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => $approver->user_id, 'stage_id' => $stage->stage_id,
        'due_date' => $document->due_date, 'priority_rank' => 2, 'individual_status' => 'pending',
        'sla_expires_at' => now()->addHours(20),
    ]);

    $annotation = DocumentAnnotation::create([
        'document_id' => $document->document_id, 'assignment_id' => $assignment->assignment_id, 'raised_by' => $approver->user_id,
        'start_offset' => $browserComputedStart, 'end_offset' => $browserComputedEnd,
        'selected_text' => 'Target phrase here', 'comment' => 'test',
    ]);

    // Sanity check: applying this real (browser-computed) offset against
    // the still-un-normalized text is exactly the bug — it lands on the
    // wrong characters, because the server was still storing \r\n.
    expect(mb_substr($oldText, $browserComputedStart, $browserComputedEnd - $browserComputedStart))
        ->not->toBe('Target phrase here');

    $migration = require base_path('database/migrations/2026_09_15_000002_normalize_line_endings.php');
    $migration->up();

    $document->refresh();
    $annotation->refresh();

    expect($document->ocr_text)->toBe($normalizedText)
        ->and($document->ocr_text)->not->toContain("\r")
        // The offset itself is untouched — it was already right.
        ->and($annotation->start_offset)->toBe($browserComputedStart)
        ->and($annotation->end_offset)->toBe($browserComputedEnd)
        ->and(mb_substr($document->ocr_text, $annotation->start_offset, $annotation->end_offset - $annotation->start_offset))
        ->toBe('Target phrase here');
});
