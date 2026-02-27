<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a composite (status, occurred_at) index to speed up the default
 * log listing query: WHERE status = 'open' ORDER BY occurred_at DESC.
 * Without this, MySQL/SQLite must sort all matching rows before returning
 * the first page — very slow at scale.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = config('logscope.table', 'log_entries');

        // Only add if the status column exists. It is added by the
        // add_resolved_and_note migration (migration 2) on all installs.
        if (! Schema::hasColumn($table, 'status')) {
            return;
        }

        Schema::table($table, function (Blueprint $table) {
            $table->index(['status', 'occurred_at']);
        });
    }

    public function down(): void
    {
        $table = config('logscope.table', 'log_entries');

        // up() may have returned early if the status column didn't exist,
        // meaning the index was never created. Ignore if it doesn't exist.
        try {
            Schema::table($table, function (Blueprint $table) {
                $table->dropIndex(['status', 'occurred_at']);
            });
        } catch (\Exception $e) {
            // Index was never created
        }
    }
};
