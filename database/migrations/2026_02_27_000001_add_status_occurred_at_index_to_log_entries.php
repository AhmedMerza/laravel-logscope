<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
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
        // meaning the index was never created. Looking for the index itself is
        // exact, where catching every exception swallowed two real failures
        // during #55 and left the index in place while down() reported success.
        // Dropping the name the table actually carries also covers an index
        // created under a name Blueprint wouldn't generate today.
        $index = collect(Schema::getIndexes($table))->firstWhere('columns', ['status', 'occurred_at']);

        if (! $index) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($table, $index) {
            $blueprint->dropIndex($this->qualifyIndex($table, $index['name']));
        });
    }

    /**
     * What to hand Blueprint::dropIndex(). Postgres compiles a bare name to
     * `drop index <name>` and resolves it through search_path, which needn't
     * contain the schema of a schema-qualified logscope.table (#55); the index
     * lives in the table's schema, so name it there. Only Postgres needs it:
     * MySQL scopes index names to their table, and SQLite's grammar qualifies
     * them itself.
     *
     * Each segment is wrapped on its own and handed over already quoted:
     * Grammar::wrap() on a dotted string treats the first segment as a table
     * and prepends the connection's table prefix, so a prefixed connection
     * would look for the index under a schema that doesn't exist.
     */
    private function qualifyIndex(string $table, string $name): string|Expression
    {
        $connection = Schema::getConnection();

        if (! str_contains($table, '.') || $connection->getDriverName() !== 'pgsql') {
            return $name;
        }

        $grammar = $connection->getQueryGrammar();

        return $connection->raw(
            $grammar->wrap(substr($table, 0, strrpos($table, '.'))).'.'.$grammar->wrap($name)
        );
    }
};
