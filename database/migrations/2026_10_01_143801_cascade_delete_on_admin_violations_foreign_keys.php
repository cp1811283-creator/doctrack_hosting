<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026_09_13_000001_create_admin_violations_table.php created both of this
 * table's foreign keys with plain ->constrained(), defaulting to ON DELETE
 * NO ACTION — the one exception among every other table referencing
 * document_repository/document_assignments, which all already cascade or
 * null out (see sla_violations.document_id for the pattern this matches).
 * That default silently blocked deleting a user: deleting them cascades to
 * their documents, which NO ACTION then refuses to let go if any admin_
 * violations row still points at them — this is what failed in phpMyAdmin
 * (2026-10-01) trying to delete a stray non-seeded account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_violations', function (Blueprint $table) {
            $table->dropForeign(['document_id']);
            $table->dropForeign(['assignment_id']);
        });

        Schema::table('admin_violations', function (Blueprint $table) {
            $table->foreign('document_id')->references('document_id')->on('document_repository')->cascadeOnDelete();
            $table->foreign('assignment_id')->references('assignment_id')->on('document_assignments')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('admin_violations', function (Blueprint $table) {
            $table->dropForeign(['document_id']);
            $table->dropForeign(['assignment_id']);
        });

        Schema::table('admin_violations', function (Blueprint $table) {
            $table->foreign('document_id')->references('document_id')->on('document_repository');
            $table->foreign('assignment_id')->references('assignment_id')->on('document_assignments');
        });
    }
};
