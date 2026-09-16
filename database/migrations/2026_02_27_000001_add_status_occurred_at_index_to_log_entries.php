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
        // meaning the index was never created. Asking is exact: catching every
        // exception instead swallowed two real failures during #55 and left
        // the index in place while down() reported success.
        if (! Schema::hasIndex($table, ['status', 'occurred_at'])) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($table) {
            $blueprint->dropIndex($this->indexToDrop($table, ['status', 'occurred_at']));
        });
    }

    /**
     * What to hand Blueprint::dropIndex(). Postgres compiles a bare name to
     * `drop index <name>` and resolves it through search_path, which needn't
     * contain the schema of a schema-qualified logscope.table (#55); the index
     * lives in the table's schema, so name it there. Without this the catch
     * above swallowed the "does not exist" error and left the index behind.
     * Only Postgres needs it: MySQL scopes index names to their table, and
     * SQLite's grammar qualifies them itself. Blueprint only prefixes index
     * names when the connection sets prefix_indexes.
     *
     * Each segment is wrapped on its own and handed over already quoted:
     * Grammar::wrap() on a dotted string treats the first segment as a table
     * and prepends the connection's table prefix, so a prefixed connection
     * would look for the index under a schema that doesn't exist.
     */
    private function indexToDrop(string $table, array $columns): array|Expression
    {
        $connection = Schema::getConnection();

        if (! str_contains($table, '.') || $connection->getDriverName() !== 'pgsql') {
            return $columns;
        }

        $name = $connection->getConfig('prefix_indexes')
            ? substr_replace($table, '.'.$connection->getTablePrefix(), strrpos($table, '.'), 1)
            : $table;

        $grammar = $connection->getQueryGrammar();

        return $connection->raw(
            $grammar->wrap(substr($table, 0, strrpos($table, '.'))).'.'
            .$grammar->wrap(str_replace(['-', '.'], '_', strtolower($name.'_'.implode('_', $columns).'_index')))
        );
    }
};
