<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backs TrackingCodeService's "next number" — one row per (prefix, year)
 * pair, incremented with a single atomic UPDATE inside a transaction
 * rather than counting existing document_repository rows, so two
 * documents of the same category classified at nearly the same instant
 * can never be handed the same number (a count-then-insert approach has
 * exactly that race). Resets implicitly each year: a new (prefix, year)
 * pair simply starts its own row at 1, which is also why "year" is part
 * of the key, not just "prefix" — see TrackingCodeService's docblock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracking_code_counters', function (Blueprint $table) {
            $table->id();
            $table->string('prefix', 10);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('next_number')->default(1);
            $table->timestamps();

            $table->unique(['prefix', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_code_counters');
    }
};
