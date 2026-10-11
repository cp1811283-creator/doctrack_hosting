<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feature: a short, human-readable reference (e.g. "JO-2026-0047") shown
 * alongside a document's title everywhere title alone used to be the only
 * way to find it — confirmed real gap: document titles have never had a
 * uniqueness constraint, so two documents can (and do) share the exact
 * same title with nothing distinguishing them on screen. Nullable because
 * it's only ever assigned once classification resolves a real category
 * (see TrackingCodeService::assignTo(), called from WorkflowService::
 * ingest()) — a document that never gets that far (malware-blocked,
 * extraction failed) has nothing to generate a prefix from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_repository', function (Blueprint $table) {
            $table->string('tracking_code', 20)->nullable()->unique()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('document_repository', function (Blueprint $table) {
            $table->dropColumn('tracking_code');
        });
    }
};
