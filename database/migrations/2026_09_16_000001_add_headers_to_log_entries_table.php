<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the `headers` column (#30) — allowlisted request headers, captured
 * per row. Nullable, and null is meaningful: it means "this row had no
 * request headers" (CLI, or capture disabled), not "an empty header set".
 *
 * No index: `headers:` search is a substring LIKE over the JSON, which an
 * index wouldn't serve anyway.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $table = config('logscope.table', 'log_entries');

        if (Schema::hasColumn($table, 'headers')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->json('headers')->nullable()->after('url');
        });
    }

    /**
     * Reverse the migrations.
     *
     * Unlike the v0.5 upgrade migrations, this one is a plain additive
     * column with no state to restore, so dropping it is unambiguous.
     */
    public function down(): void
    {
        $table = config('logscope.table', 'log_entries');

        if (! Schema::hasColumn($table, 'headers')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->dropColumn('headers');
        });
    }
};
