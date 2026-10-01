<?php

namespace App\Console\Commands;

use App\Models\DocumentAssignment;
use App\Services\SlaService;
use Illuminate\Console\Command;

/**
 * Run via: php artisan workflow:check-parallel-slas
 * Scheduled every 5 minutes in bootstrap/app.php.
 *
 * Every eligible approver is assigned to a stage at once (see
 * WorkflowService::assignStage()), so a stage genuinely can have several
 * parallel sibling assignments in flight simultaneously. This sweep
 * handles each individually expired PENDING assignment on its own — logs
 * an SlaViolation and auto-approves it (see SlaService::
 * autoApproveApproverMiss()) — with no cross-row coordination; each seat's SLA
 * window is independent of its siblings. A seat that fails is reported and
 * skipped so it can never hold up the seats after it.
 *
 * The auto-approved stage is then reviewed by the Admin afterward — that
 * follow-up is handled by the `sla:check` command via SlaService. The
 * actual per-assignment logic lives in SlaService::autoApproveMissedDeadline() — this command
 * is a bulk periodic sweep, but ApprovalController also calls the same
 * method on-demand so an approver can never act on an assignment past its
 * own SLA window just because this sweep hasn't run yet.
 */
class CheckParallelSlas extends Command
{
    protected $signature = 'workflow:check-parallel-slas';

    protected $description = 'Auto-approve pending approver assignments whose SLA window has expired (each seat handled independently).';

    public function __construct(private SlaService $sla)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $expired = DocumentAssignment::where('individual_status', 'pending')
                ->where('sla_expires_at', '<', now())
                ->with(['stage', 'document', 'approver'])
                ->get();
        } catch (\Throwable $e) {
            // Previously uncaught: a DB hiccup here crashed the whole sweep
            // with no per-seat context, and the trace only ever reached an
            // ephemeral container log Railway doesn't surface. Report it so
            // Sentry actually sees it, instead of a bare "FAIL" with nothing
            // to diagnose from.
            report($e);
            $this->error("Could not fetch overdue assignments: {$e->getMessage()}");

            return self::FAILURE;
        }

        $autoApproved = 0;
        $failed = 0;

        foreach ($expired as $assignment) {
            try {
                $this->sla->autoApproveMissedDeadline($assignment);
                $autoApproved++;
            } catch (\Throwable $e) {
                // One bad seat must not stop the rest of the sweep — report
                // it and keep going; the next tick retries it.
                report($e);
                $failed++;
                $this->warn("Assignment #{$assignment->assignment_id} could not be processed: {$e->getMessage()}");
            }
        }

        $this->info("{$autoApproved} overdue assignment(s) auto-approved".($failed > 0 ? ", {$failed} failed" : '').'.');

        if ($failed > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
