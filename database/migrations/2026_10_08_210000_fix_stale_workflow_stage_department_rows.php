<?php

use App\Models\WorkflowStage;
use App\Models\WorkflowStageDepartment;
use Illuminate\Database\Migrations\Migration;

/**
 * Data correction, not a schema change — see the workflow_stage_departments
 * table's own migration docblock for why Final Approval's department(s)
 * must match whichever department(s) actually worked that category's
 * earlier stages, not a blanket "every head signs off everything" rule.
 * DatabaseSeeder.php's $stageDepartments array was corrected to the
 * mapping below some time ago, but a seeder change only ever affects a
 * FRESH seed — it has no effect on an already-seeded database, which just
 * kept whatever stale rows it had from before the fix. Confirmed real:
 * Service Report's Final Approval was still showing a Finance decision
 * alongside every Engineering one, long after the seeder itself had
 * already been corrected, because nothing had ever gone back and fixed
 * the actual rows already sitting in workflow_stage_departments.
 *
 * Applies that same correction directly to whatever rows already exist,
 * wherever this runs — idempotent either way, so a database that already
 * matches this mapping (any fresh seed from here on) is left untouched.
 */
return new class extends Migration
{
    /** @var array<string, array<int, string>> */
    private const CORRECT_DEPARTMENTS = [
        'Job Order:Technical Review' => ['Engineering'],
        'Job Order:Budget Check' => ['Finance'],
        'Job Order:Final Approval' => ['Engineering', 'Finance'],
        'Purchase Requisition:Budget Check' => ['Finance'],
        'Purchase Requisition:Procurement Review' => ['Finance'],
        'Purchase Requisition:Final Approval' => ['Finance'],
        'Service Report:Quality Inspection' => ['Engineering'],
        'Service Report:Final Approval' => ['Engineering'],
    ];

    public function up(): void
    {
        foreach (self::CORRECT_DEPARTMENTS as $key => $departments) {
            [$category, $stageName] = explode(':', $key, 2);

            // The real, admin-managed, category-wide stage only — never a
            // one-off document-scoped stage that happens to share the same
            // name (see WorkflowStage::scopeConfigured()'s own docblock).
            $stage = WorkflowStage::where('document_category', $category)
                ->where('stage_name', $stageName)
                ->whereNull('document_id')
                ->where('is_archived', false)
                ->first();

            if (! $stage) {
                continue; // this stage doesn't exist in this environment yet — nothing to correct
            }

            WorkflowStageDepartment::where('stage_id', $stage->stage_id)
                ->whereNotIn('department', $departments)
                ->delete();

            foreach ($departments as $department) {
                WorkflowStageDepartment::firstOrCreate([
                    'stage_id' => $stage->stage_id,
                    'department' => $department,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Deliberately a no-op — this corrects live data to match the
        // application's OWN current department-eligibility rules, not a
        // one-off change to revert. Reversing it would put the exact wrong
        // rows this migration exists to remove back in place.
    }
};
