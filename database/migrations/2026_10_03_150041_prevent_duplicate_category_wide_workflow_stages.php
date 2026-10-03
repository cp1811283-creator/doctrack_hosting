<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Confirmed real production data bug (2026-10-03): Job Order ended up with
 * TWO active "Technical Review" rows and TWO active "Final Approval" rows
 * — same document_category, same stage_name, no application code path
 * currently able to reproduce it on demand, but nothing in the schema
 * stopped it from happening either. WorkflowService::
 * eligibleApproversForStage()/isFinalApprovalStage() both match a stage by
 * stage_name alone, so a duplicate silently doubles up a document's whole
 * pipeline (confirmed: it rendered as a repeated "Technical Review" /
 * "Final Approval" pair on the Auto-Approval Review page).
 *
 * A plain UNIQUE(document_category, stage_name) can't be used as-is: a
 * document-scoped one-off stage (WorkflowService::routeToCustomApprovers())
 * deliberately shares the same document_category + a generic stage_name
 * across many different documents, which is legitimate and must stay
 * allowed. The real rule is narrower — no two ACTIVE, CATEGORY-WIDE stages
 * (document_id IS NULL AND NOT archived) may share a name. A generated
 * column that's NULL for every document-scoped or already-archived row
 * (exempting them from the index entirely — both MySQL and SQLite treat
 * multiple NULLs in a unique index as non-conflicting) and a real
 * "category||stage_name" string only for active category-wide rows
 * expresses exactly that narrower rule — archiving a duplicate (rather
 * than deleting it, which is how this migration's own two real duplicates
 * were cleaned up) doesn't leave it permanently blocking that name forever.
 *
 * MySQL vs SQLite (the test suite's driver): both support generated/stored
 * columns, but the string-concat syntax inside the expression differs
 * (CONCAT() vs ||) — same reasoning as every other driver-branched
 * migration in this project (e.g. 2026_09_16_000003_add_late_ml_review_to_
 * admin_violations_table.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        // is_archived = 0 is part of the condition too (confirmed necessary
        // by reproducing it directly: the two real duplicate rows this
        // migration exists because of were archived, not deleted, during
        // cleanup — without this, those archived rows still collide with
        // the unique index forever, and a legitimately re-added stage
        // sharing an old archived stage's name would wrongly be blocked).
        $driver = Schema::getConnection()->getDriverName();
        $expression = $driver === 'sqlite'
            ? "CASE WHEN document_id IS NULL AND is_archived = 0 THEN document_category || '||' || stage_name ELSE NULL END"
            : "CASE WHEN document_id IS NULL AND is_archived = 0 THEN CONCAT(document_category, '||', stage_name) ELSE NULL END";

        // virtualAs(), not storedAs() — MySQL refuses to add a STORED
        // generated column to a table that has incoming foreign keys from
        // other tables (document_assignments, workflow_stage_departments,
        // approver_workflow_stages, admin_violations all reference this
        // one), raising a misleading "Cannot add foreign key constraint"
        // error that has nothing to do with foreign keys themselves —
        // confirmed by reproducing it directly. A virtual (computed on
        // read, not persisted) generated column has no such restriction
        // and MySQL 5.7+/SQLite can both still index one directly.
        Schema::table('workflow_stages', function (Blueprint $table) use ($expression) {
            $table->string('category_wide_stage_key')->nullable()->virtualAs($expression);
        });

        Schema::table('workflow_stages', function (Blueprint $table) {
            $table->unique('category_wide_stage_key', 'workflow_stages_category_wide_unique');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_stages', function (Blueprint $table) {
            $table->dropUnique('workflow_stages_category_wide_unique');
            $table->dropColumn('category_wide_stage_key');
        });
    }
};
