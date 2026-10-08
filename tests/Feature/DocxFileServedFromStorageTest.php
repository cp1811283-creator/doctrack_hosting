<?php

use App\Models\DocumentRepository;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Tests\Support\DocxFixtureBuilder;

/**
 * Regression coverage for the bug where every .docx read went through
 * Storage::path() — only valid for the 'local' disk driver, and exactly
 * the call that throws outright on an S3-compatible disk (Cloudflare R2
 * in production, see railway/README.md). These three surfaces
 * (DocumentController::viewFile(), the Review & Comment panel, and the
 * originator's revision editor) all now go through
 * DocxRichContentService::renderStoredFile() instead, which reads the
 * file via Storage::get() and never calls Storage::path() — see that
 * method's own docblock. Exercised over real HTTP so a regression back
 * to Storage::path() would be caught the same way the real bug was
 * found (clicking "view" in the browser), not just at the unit level.
 */
function seedViewableDocxDocument(): array
{
    Storage::fake('local');

    $originator = User::factory()->originator()->create();

    $relativePath = 'documents/viewer-test.docx';
    Storage::makeDirectory('documents');
    DocxFixtureBuilder::build(Storage::path($relativePath));

    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'viewer-test.docx',
        'file_path' => $relativePath, 'original_filename' => 'viewer-test.docx',
        'mime_type' => DocumentRepository::RICH_DOCX_MIME,
        'due_date' => now()->addDay(), 'global_status' => 'classified_validated', 'ml_category' => 'Job Order',
        'ocr_text' => "Label: value text\nCell A\nCell B\nAfter image\n",
    ]);

    return compact('originator', 'document');
}

it('serves a .docx as rendered HTML without needing Storage::path()', function () {
    ['originator' => $originator, 'document' => $document] = seedViewableDocxDocument();

    $response = $this->actingAs($originator)
        ->get(route('documents.file', $document).'?format=html');

    $response->assertOk();
    $response->assertSee('value text', false);
    $response->assertSee('<table', false);
    $response->assertSee('data:image/png;base64,', false);
});
