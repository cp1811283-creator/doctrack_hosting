<?php

namespace App\Console\Commands;

use App\Models\DocumentRepository;
use App\Services\TextExtractionService;
use Illuminate\Console\Command;

/**
 * Run via: php artisan documents:reformat-extracted-paragraphs {--dry-run}
 *
 * One-off backfill for TextExtractionService::reconstructParagraphs()
 * (added 2026-10-03) — every document uploaded BEFORE that change has
 * its ocr_text stuck in the old one-newline-per-visual-line shape (no
 * blank line between genuinely different fields/paragraphs, confirmed
 * against a real uploaded PDF and a real uploaded scanned image). This
 * re-applies the same reconstruction directly to each document's
 * already-stored ocr_text rather than re-running PDF parsing or OCR a
 * second time — reconstructParagraphs() only needs line-separated text,
 * which the original extraction already produced.
 *
 * Never scheduled — the fix is already live for every new upload, this
 * only ever needs to run once per environment against the pre-fix
 * backlog.
 *
 * Excludes:
 *   - real .docx documents — their ocr_text comes straight from the
 *     file's own real paragraph markers, not visual line-wrapping (see
 *     TextExtractionService::extract()'s own reasoning), so there's
 *     nothing to reconstruct.
 *   - any document with a still-OPEN Request Revision annotation — its
 *     start_offset/end_offset are character positions into the CURRENT
 *     ocr_text (see DocumentAnnotation's own docblock on offset drift);
 *     rewriting the text out from under an unresolved flag would corrupt
 *     exactly where its highlight lands. Safe to backfill once every
 *     open flag on it is resolved.
 */
class ReformatExtractedTextParagraphs extends Command
{
    protected $signature = 'documents:reformat-extracted-paragraphs
        {--dry-run : Report what would change without writing anything}';

    protected $description = 'Backfill already-uploaded documents\' extracted text onto the new paragraph-spacing rule';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $query = DocumentRepository::where('mime_type', '!=', DocumentRepository::RICH_DOCX_MIME)
            ->whereNotNull('ocr_text')
            ->where('ocr_text', '!=', '');

        $changed = 0;
        $skippedOpenAnnotations = 0;
        $unchanged = 0;

        $query->with('openAnnotations')->chunkById(100, function ($documents) use (&$changed, &$skippedOpenAnnotations, &$unchanged, $dryRun) {
            foreach ($documents as $document) {
                if ($document->openAnnotations->isNotEmpty()) {
                    $skippedOpenAnnotations++;

                    continue;
                }

                $reformatted = TextExtractionService::reconstructParagraphs($document->ocr_text);

                if ($reformatted === $document->ocr_text) {
                    $unchanged++;

                    continue;
                }

                $changed++;

                if (! $dryRun) {
                    // Query-builder update, not ->save() — deliberately
                    // skips Eloquent's auto-touch of updated_at. This is a
                    // silent formatting cleanup, not a real revision; it
                    // must never shift an already-computed SLA/analytics
                    // timestamp on a document that's long since been
                    // decided (see AnalyticsRedesignSmokeTest's own
                    // reasoning for why updated_at is load-bearing there).
                    DocumentRepository::where('document_id', $document->document_id)
                        ->update(['ocr_text' => $reformatted]);
                }
            }
        }, 'document_id');

        $this->info(($dryRun ? '[dry-run] ' : '')."Reformatted: {$changed}. Already fine: {$unchanged}. Skipped (open revision flag): {$skippedOpenAnnotations}.");

        return self::SUCCESS;
    }
}
