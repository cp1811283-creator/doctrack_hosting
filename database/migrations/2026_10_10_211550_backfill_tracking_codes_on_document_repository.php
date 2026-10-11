<?php

use App\Models\DocumentRepository;
use App\Services\TrackingCodeService;
use Illuminate\Database\Migrations\Migration;

/**
 * Backfills tracking_code for every document uploaded before this feature
 * existed — see the add_tracking_code migration's own docblock. Ordered
 * by upload_date so the numbering reads as a sane chronological history
 * instead of whatever order the table happens to return rows in.
 *
 * Same trusted-vs-OTH split TrackingCodeService::nextCodeFor() applies
 * live in WorkflowService::ingest(), reconstructed from what's already on
 * each row since the original $belowChanceFloor/$routingMode values from
 * that request are long gone:
 *   - desired_routing === 'unrelated' -> OTH (classifier's guess was
 *     never authoritative to begin with, same as the live path).
 *   - global_status === 'rejected' AND no assignments were ever created
 *     -> OTH (the live path only ever skips routing entirely — leaving
 *     zero assignment rows — when a document was rejected outright for
 *     falling below the classification chance floor; a majority-vote
 *     rejection by real approvers, by contrast, always has assignment
 *     rows, since it only happens after routing succeeded).
 *   - everything else with a real ml_category -> that category's own
 *     prefix, same as the live path's "trusted" branch.
 *   - ml_category still null (never classified) -> left untouched, same
 *     as live documents that never reach that point.
 */
return new class extends Migration
{
    public function up(): void
    {
        $service = app(TrackingCodeService::class);

        DocumentRepository::whereNull('tracking_code')
            ->whereNotNull('ml_category')
            ->withCount('assignments')
            ->orderBy('upload_date')
            ->get()
            ->each(function (DocumentRepository $document) use ($service) {
                $isTrusted = $document->desired_routing !== 'unrelated'
                    && ! ($document->global_status === 'rejected' && $document->assignments_count === 0);

                $document->tracking_code = $service->nextCodeFor($document->ml_category, $isTrusted);
                $document->saveQuietly();
            });
    }

    public function down(): void
    {
        // Not reversible — there's no way to tell a backfilled code apart
        // from one WorkflowService::ingest() assigned naturally once this
        // feature existed, so rolling back would risk blanking out
        // legitimately-generated codes too (same reasoning the
        // review_due_at backfill migration's own down() uses).
    }
};
