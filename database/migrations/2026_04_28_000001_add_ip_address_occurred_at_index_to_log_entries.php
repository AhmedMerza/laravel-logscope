<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Postgres can't build an index concurrently inside a transaction.
     */
    public $withinTransaction = false;

    /**
     * Add a composite (ip_address, occurred_at) index to mirror the existing
     * (trace_id, occurred_at) and (user_id, occurred_at) indexes. Without it,
     * `WHERE ip_address = ? ORDER BY occurred_at DESC LIMIT 51` queries pick
     * the (status, occurred_at) plan and scan a large slice of the table
     * before applying the ip_address filter.
     */
    public function up(): void
    {
        $table = config('logscope.table', 'log_entries');

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            $this->createIndexConcurrently($table, ['ip_address', 'occurred_at']);

            return;
        }

        // MySQL builds secondary indexes online.
        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->index(['ip_address', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::table(config('logscope.table', 'log_entries'), function (Blueprint $table) {
            $table->dropIndex(['ip_address', 'occurred_at']);
        });
    }

    /**
     * A plain CREATE INDEX blocks writes on Postgres until the build finishes,
     * and apps with a custom table first run this on a table that has been
     * growing since v1.5.3 (#48). Same approach as 2026_09_14.
     */
    private function createIndexConcurrently(string $table, array $columns): void
    {
        $name = $this->indexName($table, $columns);

        // An interrupted concurrent build leaves an INVALID index that the
        // planner never uses, and IF NOT EXISTS would skip past it.
        $invalid = DB::selectOne(
            'select not indisvalid as invalid from pg_index where indexrelid = to_regclass(?)',
            [$name],
        )?->invalid;

        if ($invalid) {
            DB::statement("drop index concurrently {$name}");
        }

        DB::statement(sprintf(
            'create index concurrently if not exists %s on %s (%s)',
            $name,
            DB::getQueryGrammar()->wrapTable($table),
            implode(', ', $columns),
        ));
    }

    /**
     * The name Blueprint generates for index($columns), so down() can drop it.
     * Blueprint only prefixes index names when the connection sets
     * prefix_indexes.
     */
    private function indexName(string $table, array $columns): string
    {
        $connection = Schema::getConnection();

        if ($connection->getConfig('prefix_indexes')) {
            $table = str_contains($table, '.')
                ? substr_replace($table, '.'.$connection->getTablePrefix(), strrpos($table, '.'), 1)
                : $connection->getTablePrefix().$table;
        }

        return str_replace(['-', '.'], '_', strtolower($table.'_'.implode('_', $columns).'_index'));
    }
};
