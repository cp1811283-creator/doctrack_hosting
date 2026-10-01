<?php

namespace App\Console\Commands;

use App\Models\DocumentRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Run via: php artisan documents:purge-duplicate-uploads
 *
 * Before DocumentController::store() gained a double-click guard (commit
 * TBD, 2026-10-01), the Submit button had no protection against a second
 * click registering before the first request's response came back — each
 * click was a fully independent form submission, so a double-click created
 * two separate DocumentRepository rows for the same file (confirmed in
 * production: #8 and #9, both "Job order.docx", 3 seconds apart). Each
 * duplicate then went through the normal pipeline independently — auto-
 * approved, then flagged as a late-review Admin Violation once it sat
 * unreviewed — which is what inflated the SLA Violation Reports counts.
 *
 * The current code can no longer create these going forward (client-side
 * button guard + a server-side 10-second duplicate check), so this only
 * ever needs to run once per environment against the pre-fix backlog —
 * never scheduled.
 *
 * A "duplicate" here is: same originator, same original filename, same
 * byte size, uploaded within --window-seconds of an earlier upload that
 * matches on all three. Rows are compared pairwise in upload order (not
 * just grouped) so a legitimate resubmission of the same filename weeks
 * later is never touched — only genuinely back-to-back uploads are. The
 * earliest row in each matching chain is kept; every later match in that
 * chain is deleted, along with its stored file (failures there are logged
 * and skipped, never fatal — the DB row is still the source of truth).
 */
class PurgeDuplicateDocumentUploads extends Command
{
    protected $signature = 'documents:purge-duplicate-uploads
        {--window-seconds=120 : Max gap between uploads to treat as the same double-click}
        {--force : Delete without asking for confirmation}';

    protected $description = 'Delete duplicate documents left behind by the pre-fix double-click upload bug, keeping the earliest of each matching pair.';

    public function handle(): int
    {
        $windowSeconds = (int) $this->option('window-seconds');

        $candidates = DocumentRepository::query()
            ->whereIn('original_filename', function ($q) {
                $q->select('original_filename')
                    ->from('document_repository')
                    ->whereNotNull('original_filename')
                    ->groupBy('originator_id', 'original_filename')
                    ->havingRaw('COUNT(*) > 1');
            })
            ->orderBy('originator_id')
            ->orderBy('original_filename')
            ->orderBy('upload_date')
            ->get();

        $toDelete = collect();
        $kept = [];

        foreach ($candidates->groupBy(fn ($d) => $d->originator_id.'|'.$d->original_filename) as $group) {
            $anchor = null;
            $anchorSize = null;

            foreach ($group as $document) {
                $size = Storage::exists($document->file_path) ? Storage::size($document->file_path) : null;

                if ($anchor
                    && $size !== null && $size === $anchorSize
                    // abs(): Carbon 3's diffInSeconds() returns a signed
                    // value depending on direction, not always positive even
                    // though $document is always later here (rows are
                    // walked in upload_date order) — same gotcha already
                    // documented at SlaService::autoApproveApproverMiss()'s
                    // duration_overdue calculation. Without it, a NEGATIVE
                    // diff satisfied "<= $windowSeconds" unconditionally,
                    // which is exactly what let two uploads 7 days apart
                    // match (confirmed failing without abs() here).
                    && abs($document->upload_date->diffInSeconds($anchor->upload_date)) <= $windowSeconds
                ) {
                    $toDelete->push($document);

                    continue; // anchor stays the same — chains 3+ duplicates back to the first
                }

                $anchor = $document;
                $anchorSize = $size;
                $kept[] = $document;
            }
        }

        if ($toDelete->isEmpty()) {
            $this->info('No duplicate uploads found.');

            return self::SUCCESS;
        }

        $this->info("{$toDelete->count()} duplicate document(s) found, keeping the earliest of each matching pair:");
        $this->table(
            ['Document ID', 'Title', 'Originator', 'Uploaded', 'Status'],
            $toDelete->take(20)->map(fn ($d) => [
                $d->document_id, $d->title, $d->originator_id, $d->upload_date, $d->global_status,
            ])->all()
        );
        if ($toDelete->count() > 20) {
            $this->line('… and '.($toDelete->count() - 20).' more.');
        }

        if (! $this->option('force') && ! $this->confirm("Delete these {$toDelete->count()} duplicate document(s) and their stored files? This also removes their assignments, violations, and audit history.")) {
            $this->info('Nothing deleted.');

            return self::SUCCESS;
        }

        foreach ($toDelete as $document) {
            try {
                if (Storage::exists($document->file_path)) {
                    Storage::delete($document->file_path);
                }
            } catch (\Throwable $e) {
                report($e);
                $this->warn("Could not delete stored file for document #{$document->document_id}: {$e->getMessage()}");
            }
        }

        DocumentRepository::whereIn('document_id', $toDelete->pluck('document_id'))->delete();
        $this->info("Deleted {$toDelete->count()} duplicate document(s).");

        return self::SUCCESS;
    }
}
