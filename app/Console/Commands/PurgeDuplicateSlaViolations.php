<?php

namespace App\Console\Commands;

use App\Models\SlaViolation;
use Illuminate\Console\Command;

/**
 * Run via: php artisan sla:purge-duplicate-violations
 *
 * Before SlaService::autoApproveApproverMiss() was rewritten to lock the
 * assignment row and log the violation inside a single transaction (commit
 * 44ce4cc, 2026-09-24), the old escalateApproverMiss() logged a
 * SlaViolation unconditionally and only afterward tried to auto-approve.
 * If that second step threw (e.g. a Reverb broadcast failure, or the
 * scheduler crashing mid-sweep), the violation row survived but the
 * assignment stayed pending, so the next 5-minute tick logged another
 * violation for the same assignment_id — in production this produced
 * assignment_ids with 2-3 rows within a couple seconds, and at least two
 * assignment_ids with hundreds of rows over several days.
 *
 * The current code can no longer create these (the transaction makes the
 * violation insert and the approval atomic), so this only ever needs to
 * run once against each environment's pre-2026-09-24 backlog — never
 * scheduled.
 *
 * For each assignment_id with more than one violation row, keeps the
 * earliest (lowest violation_id) and deletes the rest. Rows with a null
 * assignment_id (orphaned by a deleted assignment) are left untouched —
 * they aren't duplicates, just historical records with nothing to compare
 * against.
 */
class PurgeDuplicateSlaViolations extends Command
{
    protected $signature = 'sla:purge-duplicate-violations {--force : Delete without asking for confirmation}';

    protected $description = 'Delete duplicate SLA violation rows left behind by the pre-2026-09-24 scheduler crash-retry bug, keeping the earliest row per assignment.';

    public function handle(): int
    {
        $duplicateGroups = SlaViolation::query()
            ->whereNotNull('assignment_id')
            ->selectRaw('assignment_id, COUNT(*) as total, MIN(violation_id) as keep_id')
            ->groupBy('assignment_id')
            ->having('total', '>', 1)
            ->get();

        if ($duplicateGroups->isEmpty()) {
            $this->info('No duplicate violation rows — every assignment has at most one.');

            return self::SUCCESS;
        }

        $toDelete = SlaViolation::query()
            ->whereIn('assignment_id', $duplicateGroups->pluck('assignment_id'))
            ->whereNotIn('violation_id', $duplicateGroups->pluck('keep_id'))
            ->get();

        $this->info("{$duplicateGroups->count()} assignment(s) have duplicate violation rows — {$toDelete->count()} extra row(s) would be deleted, keeping the earliest per assignment:");
        $this->table(
            ['Assignment ID', 'Total Rows', 'Rows To Delete'],
            $duplicateGroups->take(10)->map(fn ($g) => [$g->assignment_id, $g->total, $g->total - 1])->all()
        );
        if ($duplicateGroups->count() > 10) {
            $this->line('… and '.($duplicateGroups->count() - 10).' more assignment(s).');
        }

        if (! $this->option('force') && ! $this->confirm("Delete these {$toDelete->count()} duplicate row(s)? The earliest violation per assignment is kept.")) {
            $this->info('Nothing deleted.');

            return self::SUCCESS;
        }

        SlaViolation::whereIn('violation_id', $toDelete->pluck('violation_id'))->delete();
        $this->info("Deleted {$toDelete->count()} duplicate violation row(s) across {$duplicateGroups->count()} assignment(s).");

        return self::SUCCESS;
    }
}
