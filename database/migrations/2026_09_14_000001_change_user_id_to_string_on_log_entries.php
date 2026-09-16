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
     * Postgres would otherwise run every step in one transaction, holding the
     * table lock from the swap until the copy finished.
     */
    public $withinTransaction = false;

    private const CHUNK_SIZE = 1000;

    /**
     * Widen user_id from an unsigned big integer to a string (#26). Apps whose
     * auth identifier isn't an integer (UUID/ULID keys, ids like `admin_1`)
     * had every insert rejected under MySQL strict mode, and with batch
     * writes that lost the whole buffered chunk.
     *
     * Changing the type in place rewrites the table while blocking writes
     * (MySQL) or reads and writes (Postgres), which on a large log table
     * stalls every request that logs. Instead the old column is renamed and a
     * string user_id added in one statement, existing ids are copied across in
     * small batches, and the old column is dropped. Logging keeps working
     * throughout. Existing logs show no user id until the copy reaches them.
     *
     * Each step checks the table's state first, so if a run is killed
     * part-way, running migrate again resumes it.
     */
    public function up(): void
    {
        $table = config('logscope.table', 'log_entries');

        if (! Schema::hasColumn($table, 'user_id_legacy')) {
            $this->swapInStringColumn($table);
        }

        match (Schema::getConnection()->getDriverName()) {
            'mysql', 'mariadb' => $this->moveMySqlIndexes($table),
            'pgsql' => $this->createIndexesConcurrently($table),
            default => null, // built inside the swap's transaction
        };

        $this->copyLegacyIds($table);

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->dropColumn('user_id_legacy');
        });
    }

    /**
     * Rolling back changes the type in place: it blocks writes on a large
     * table, and fails without changing anything if a non-integer id is stored.
     */
    public function down(): void
    {
        $table = config('logscope.table', 'log_entries');

        // Postgres won't cast varchar to bigint without USING, which
        // Blueprint::change() doesn't emit.
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(sprintf(
                'alter table %s alter column user_id type bigint using user_id::bigint',
                DB::getQueryGrammar()->wrapTable($table),
            ));

            return;
        }

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->unsignedBigInteger('user_id')->nullable()->change();
        });
    }

    /**
     * Atomic: between a separate rename and add there would be no user_id
     * column, and every insert in that gap would fail.
     */
    private function swapInStringColumn(string $table): void
    {
        if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            [$schema, $name] = $this->schemaAndTable($table);

            // CHANGE rather than RENAME COLUMN, which needs MySQL 8.0.3+ /
            // MariaDB 10.5.2+. Restating the current type keeps it a rename.
            // Aliased: MySQL 8 returns information_schema columns uppercased.
            $currentType = DB::selectOne(
                'select column_type as type from information_schema.columns where table_schema = coalesce(?, database()) and table_name = ? and column_name = ?',
                [$schema, $name, 'user_id'],
            )->type;

            // Indexes are moved separately: in this statement they would force
            // a full table rebuild instead of an instant metadata change.
            DB::statement(sprintf(
                'alter table %s change user_id user_id_legacy %s null, add user_id varchar(255) null',
                DB::getQueryGrammar()->wrapTable($table),
                $currentType,
            ));

            return;
        }

        DB::transaction(function () use ($table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropIndex($this->indexToDrop($table, ['user_id']));
                $blueprint->dropIndex($this->indexToDrop($table, ['user_id', 'occurred_at']));
                $blueprint->renameColumn('user_id', 'user_id_legacy');
            });

            // A separate call: Blueprint runs column additions before renames.
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->string('user_id')->nullable();

                // Postgres gets these built concurrently instead.
                if (Schema::getConnection()->getDriverName() !== 'pgsql') {
                    $blueprint->index('user_id');
                    $blueprint->index(['user_id', 'occurred_at']);
                }
            });
        });
    }

    /**
     * The rename carried the original indexes over to user_id_legacy. Rebuild
     * them under the same names on the new column; MySQL builds secondary
     * indexes online. Skipped if a previous run already did it.
     */
    private function moveMySqlIndexes(string $table): void
    {
        [$schema, $name] = $this->schemaAndTable($table);

        $indexed = DB::selectOne(
            'select column_name as name from information_schema.statistics where table_schema = coalesce(?, database()) and table_name = ? and index_name = ? and seq_in_index = 1',
            [$schema, $name, $this->indexName($table, ['user_id'])],
        );

        if ($indexed?->name === 'user_id') {
            return;
        }

        DB::statement(sprintf(
            'alter table %1$s drop index %2$s, drop index %3$s, add index %2$s (user_id), add index %3$s (user_id, occurred_at)',
            DB::getQueryGrammar()->wrapTable($table),
            $this->indexName($table, ['user_id']),
            $this->indexName($table, ['user_id', 'occurred_at']),
        ));
    }

    /**
     * A plain CREATE INDEX blocks writes on Postgres until the build finishes.
     */
    private function createIndexesConcurrently(string $table): void
    {
        $grammar = DB::getQueryGrammar();

        foreach ([['user_id'], ['user_id', 'occurred_at']] as $columns) {
            $name = $grammar->wrap($this->indexName($table, $columns));

            // An index lives in its table's schema, but an unqualified name is
            // resolved through search_path, which needn't include that schema.
            // Each part is wrapped on its own: wrap() on a dotted string would
            // read the schema as a table and prefix it.
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
    }

    /**
     * Batched by primary key so no statement holds row locks for long. Only
     * rows still missing the new value are selected, so a rerun resumes.
     * Logs written after the swap never had a legacy value.
     */
    private function copyLegacyIds(string $table): void
    {
        DB::table($table)
            ->select('id')
            ->whereNotNull('user_id_legacy')
            ->whereNull('user_id')
            ->chunkById(self::CHUNK_SIZE, function ($rows) use ($table) {
                DB::table($table)
                    ->whereIn('id', $rows->pluck('id'))
                    ->update(['user_id' => DB::raw(DB::getQueryGrammar()->wrap('user_id_legacy'))]);
            });
    }

    /**
     * What to hand Blueprint::dropIndex(). Postgres compiles a bare name to
     * `drop index <name>` and resolves it through search_path, which needn't
     * contain the schema of a schema-qualified logscope.table (#55); the index
     * lives in the table's schema, so name it there. Only Postgres needs this:
     * MySQL scopes index names to their table, and SQLite's grammar qualifies
     * them itself. Everything else passes the columns and lets Blueprint name
     * the index, which keeps the prefix_indexes handling in one place.
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
     * information_schema keys on the schema and the bare table name, so a
     * schema-qualified logscope.table has to be split before it is looked up
     * (#55). A null schema means the connection's own database. Laravel
     * applies the table prefix to the last segment only.
     */
    private function schemaAndTable(string $table): array
    {
        $prefix = Schema::getConnection()->getTablePrefix();

        if (! str_contains($table, '.')) {
            return [null, $prefix.$table];
        }

        $split = strrpos($table, '.');

        return [substr($table, 0, $split), $prefix.substr($table, $split + 1)];
    }

    /**
     * The name Blueprint generates for index($columns), so the indexes match
     * what the original migration created. Blueprint only prefixes index names
     * when the connection sets prefix_indexes.
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
