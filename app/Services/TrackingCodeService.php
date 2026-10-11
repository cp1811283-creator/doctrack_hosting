<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * TrackingCodeService
 * --------------------
 * Generates the short, human-readable reference shown alongside a
 * document's title everywhere (e.g. "JO-2026-0047") — see the
 * document_repository.tracking_code migration's own docblock for why
 * this exists: titles have no uniqueness constraint, so two documents
 * can share one with nothing to tell them apart.
 *
 * Format: {PREFIX}-{YEAR}-{NNNN}, matching the convention this app's own
 * sample fixtures (database/sample_document_uploads/) already used
 * before this feature existed — JO-2026-0001 reads as something that
 * was always meant to be there, not a bolted-on new scheme. NNNN resets
 * to 0001 each year, per prefix, so it stays short and meaningful
 * instead of climbing forever across the app's whole lifetime — the
 * same reasoning real invoice/PO numbering schemes use.
 */
class TrackingCodeService
{
    /** Trusted, real category -> its own prefix. */
    private const CATEGORY_PREFIXES = [
        'Job Order' => 'JO',
        'Purchase Requisition' => 'PR',
        'Service Report' => 'SR',
    ];

    /**
     * Used for anything without a trustworthy real category: a genuinely
     * Unrelated document (classifier's guess kept only for reference,
     * never authoritative — see WorkflowService::ingest()'s $routingMode
     * docblock) or one rejected for falling below the classification
     * chance floor (confidence too low to trust the guess at all).
     * Matches the Archive's existing "Other" folder naming.
     */
    private const FALLBACK_PREFIX = 'OTH';

    /**
     * The next code for a document whose category is now known — call
     * once, exactly when that becomes true (see WorkflowService::
     * ingest(), right before its $document->save()), never speculatively
     * earlier. $isTrustedCategory false routes to the OTH prefix instead
     * of the real category's own — see FALLBACK_PREFIX's docblock for
     * which two cases that covers.
     */
    public function nextCodeFor(?string $category, bool $isTrustedCategory): string
    {
        $prefix = ($isTrustedCategory && $category !== null)
            ? (self::CATEGORY_PREFIXES[$category] ?? self::FALLBACK_PREFIX)
            : self::FALLBACK_PREFIX;

        return $this->nextCodeForPrefix($prefix, (int) now()->year);
    }

    /**
     * The atomic increment itself, split out so the backfill migration
     * can reuse the exact same counter (and thus the exact same
     * never-collide guarantee) instead of hand-rolling its own — see
     * the tracking_code_counters migration's docblock for why this has
     * to be a real atomic UPDATE and not a count-then-insert.
     */
    public function nextCodeForPrefix(string $prefix, int $year): string
    {
        $number = DB::transaction(function () use ($prefix, $year) {
            DB::table('tracking_code_counters')->updateOrInsert(
                ['prefix' => $prefix, 'year' => $year],
                []
            );

            // Locks the row for the duration of this transaction (MySQL:
            // SELECT ... FOR UPDATE) so a second concurrent call blocks
            // here instead of reading the same next_number this one is
            // about to claim — the whole reason this is a transaction at
            // all, not just an UPDATE on its own.
            $current = DB::table('tracking_code_counters')
                ->where('prefix', $prefix)->where('year', $year)
                ->lockForUpdate()
                ->value('next_number');

            DB::table('tracking_code_counters')
                ->where('prefix', $prefix)->where('year', $year)
                ->update(['next_number' => $current + 1]);

            return $current;
        });

        return sprintf('%s-%d-%04d', $prefix, $year, $number);
    }
}
