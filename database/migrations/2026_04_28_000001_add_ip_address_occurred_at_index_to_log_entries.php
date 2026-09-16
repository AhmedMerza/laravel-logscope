<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
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
        $table = config('logscope.table', 'log_entries');

        Schema::table($table, function (Blueprint $blueprint) use ($table) {
            $blueprint->dropIndex($this->indexToDrop($table, ['ip_address', 'occurred_at']));
        });
    }

    /**
     * What to hand Blueprint::dropIndex(). Postgres compiles a bare name to
     * `drop index <name>` and resolves it through search_path, which needn't
     * contain the schema of a schema-qualified logscope.table (#55); the index
     * lives in the table's schema, so name it there. Only Postgres needs this:
     * MySQL scopes index names to their table, and SQLite's grammar qualifies
     * them itself.
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

        $grammar = $connection->getQueryGrammar();

        return $connection->raw(
            $grammar->wrap(substr($table, 0, strrpos($table, '.')))
            .'.'.$grammar->wrap($this->indexName($table, $columns))
        );
    }

    /**
     * A plain CREATE INDEX blocks writes on Postgres until the build finishes,
     * and apps with a custom table first run this on a table that has been
     * growing since v1.5.3 (#48). Same approach as 2026_09_14.
     */
    private function createIndexConcurrently(string $table, array $columns): void
    {
        $grammar = DB::getQueryGrammar();
        $name = $grammar->wrap($this->indexName($table, $columns));

        // An index lives in its table's schema, but an unqualified name is
        // resolved through search_path, which needn't include that schema.
        // Each part is wrapped on its own: wrap() on a dotted string would read
        // the schema as a table and prefix it.
        $qualified = str_contains($table, '.')
            ? $grammar->wrap(substr($table, 0, strrpos($table, '.'))).'.'.$name
            : $name;

        // An interrupted concurrent build leaves an INVALID index that the
        // planner never uses, and IF NOT EXISTS would skip past it.
        $invalid = DB::selectOne(
            'select not indisvalid as invalid from pg_index where indexrelid = to_regclass(?)',
            [$qualified],
        )?->invalid;

        if ($invalid) {
            DB::statement("drop index concurrently {$qualified}");
        }

        DB::statement(sprintf(
            'create index concurrently if not exists %s on %s (%s)',
            $name,
            $grammar->wrapTable($table),
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
