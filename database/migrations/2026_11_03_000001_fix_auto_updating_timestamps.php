<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Repair NOT NULL timestamp columns a MySQL-family server rewrote on create.
 *
 * With explicit_defaults_for_timestamp off — the default before MariaDB 10.10
 * and on MySQL 5.7 — the server gives the first NOT NULL timestamp in a table
 * DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, and the next ones a
 * zero-date default. On log_entries that made every status change, note or
 * fingerprint backfill overwrite occurred_at with the time of the update.
 * log_groups failed to create at all under strict mode; without it,
 * first_seen_at moved on every new occurrence.
 *
 * New installs now create these as dateTime. An existing column is changed
 * only when the server gave it a default — the migrations never set one — so
 * a healthy install (MySQL 8, MariaDB 10.10+, Postgres, SQLite) is left alone
 * instead of rewriting a large log table for nothing. Times already
 * overwritten by the bug can't be recovered.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $this->repair(config('logscope.table', 'log_entries'), ['occurred_at']);
        $this->repair(config('logscope.groups_table', 'log_groups'), ['first_seen_at', 'last_seen_at']);
    }

    /**
     * Nothing to undo: restoring ON UPDATE CURRENT_TIMESTAMP would bring the
     * bug back, and on a healthy install up() changed nothing.
     */
    public function down(): void {}

    /**
     * @param  list<string>  $columns
     */
    private function repair(string $table, array $columns): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $broken = collect(Schema::getColumns($table))
            ->filter(fn (array $column): bool => in_array($column['name'], $columns, true)
                && $column['type_name'] === 'timestamp'
                && $column['default'] !== null)
            ->pluck('name');

        if ($broken->isEmpty()) {
            return;
        }

        // One statement, not Blueprint::change(), which issues one per column:
        // a table rebuilt for first_seen_at alone still carries last_seen_at's
        // zero-date default, which strict mode then rejects.
        $grammar = DB::getQueryGrammar();

        DB::statement(sprintf(
            'alter table %s %s',
            $grammar->wrapTable($table),
            $broken->map(fn (string $column): string => "modify {$grammar->wrap($column)} datetime not null")->implode(', '),
        ));
    }
};
