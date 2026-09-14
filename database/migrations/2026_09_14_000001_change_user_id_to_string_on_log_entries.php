<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
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
            // CHANGE rather than RENAME COLUMN, which needs MySQL 8.0.3+ /
            // MariaDB 10.5.2+. Restating the current type keeps it a rename.
            // Aliased: MySQL 8 returns information_schema columns uppercased.
            $currentType = DB::selectOne(
                'select column_type as type from information_schema.columns where table_schema = database() and table_name = ? and column_name = ?',
                [Schema::getConnection()->getTablePrefix().$table, 'user_id'],
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
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropIndex(['user_id']);
                $blueprint->dropIndex(['user_id', 'occurred_at']);
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
        $indexed = DB::selectOne(
            'select column_name as name from information_schema.statistics where table_schema = database() and table_name = ? and index_name = ? and seq_in_index = 1',
            [Schema::getConnection()->getTablePrefix().$table, $this->indexName($table, ['user_id'])],
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
        foreach ([['user_id'], ['user_id', 'occurred_at']] as $columns) {
            DB::statement(sprintf(
                'create index concurrently if not exists %s on %s (%s)',
                $this->indexName($table, $columns),
                DB::getQueryGrammar()->wrapTable($table),
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
     * The name Blueprint generates for index($columns), so the indexes match
     * what the original migration created.
     */
    private function indexName(string $table, array $columns): string
    {
        return str_replace(
            ['-', '.'],
            '_',
            strtolower(Schema::getConnection()->getTablePrefix().$table.'_'.implode('_', $columns).'_index'),
        );
    }
};
