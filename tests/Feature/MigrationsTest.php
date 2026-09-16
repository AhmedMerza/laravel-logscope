<?php

declare(strict_types=1);

// Each migration's down() must put the table back exactly as its up() found
// it, on the default table name, a custom one, and a schema-qualified one.
// 2026_01_24's down() undid changes its up() never made, so rolling back
// failed on every install and DatabaseMigrations broke after each test (#41).
// 2026_04_28 hard-coded log_entries, so migrate failed when logscope.table
// was set (#42). Several migrations dropped indexes by a bare name, which
// Postgres resolves through search_path instead of the table's own schema,
// so a schema-qualified table couldn't be rolled back — and 2026_09_14
// couldn't be migrated at all, on either engine (#55).
//
// Migrations are the most engine-specific code here, so this runs on Postgres
// or MySQL the same way as tests/Transactions (see TransactionTestCase). It
// drops every table in that database.

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    if ($driver = env('LOGSCOPE_TEST_DB')) {
        config(['database.default' => $driver]);
    }

    Artisan::call('db:wipe', ['--force' => true]);
});

// #55 is about how Postgres resolves a bare index name through search_path,
// and how MySQL keys information_schema on the database. SQLite has neither —
// a qualified name there means an ATTACHed database — so the default SQLite
// run skips that case and CI covers it on both real engines.
function skipUnqualifiableTable(string $table): void
{
    if (str_contains($table, '.') && DB::connection()->getDriverName() === 'sqlite') {
        test()->markTestSkipped('A schema-qualified table needs Postgres or MySQL (#55).');
    }
}

// [table, connection table prefix]. The prefixed case also sets prefix_indexes,
// so it covers the index naming a qualified table goes through as well.
function logScopeTables(): array
{
    return [
        'default table' => ['log_entries', ''],
        'custom table' => ['app_logs', ''],
        'schema-qualified' => ['logs.app_logs', ''],
        'schema-qualified, prefixed connection' => ['logs.app_logs', 'app_'],
    ];
}

// A table prefix on the connection is what catches a qualified index name
// built as one dotted string: Grammar::wrap() treats the first segment of a
// dotted identifier as a table, so it would prefix the schema and look for the
// index somewhere that doesn't exist (#55).
function useTablePrefix(string $prefix): void
{
    if ($prefix === '') {
        return;
    }

    $connection = config('database.default');

    config([
        "database.connections.{$connection}.prefix" => $prefix,
        "database.connections.{$connection}.prefix_indexes" => true,
    ]);

    DB::purge($connection);
}

// A schema-qualified logscope.table puts the table, and so its indexes, in a
// schema the connection's search_path needn't contain (#55). db:wipe doesn't
// reach that schema, so drop and recreate it to get a clean slate.
function resetLogScopeSchema(string $table): void
{
    if (! str_contains($table, '.')) {
        return;
    }

    $schema = substr($table, 0, strrpos($table, '.'));

    $statements = match (DB::connection()->getDriverName()) {
        'pgsql' => ["drop schema if exists {$schema} cascade", "create schema {$schema}"],
        'mysql', 'mariadb' => ["drop database if exists {$schema}", "create database {$schema}"],
        default => [],
    };

    foreach ($statements as $statement) {
        DB::statement($statement);
    }
}

function logScopeSchema(string $table): ?array
{
    if (! Schema::hasTable($table)) {
        return null;
    }

    return [
        'columns' => collect(Schema::getColumns($table))
            ->mapWithKeys(fn (array $c) => [$c['name'] => [$c['type'], $c['nullable'], $c['default']]])
            ->sortKeys()
            ->all(),
        'indexes' => collect(Schema::getIndexes($table))
            ->mapWithKeys(fn (array $i) => [$i['name'] => [$i['columns'], $i['unique'], $i['primary']]])
            ->sortKeys()
            ->all(),
    ];
}

it('rolls each migration back to the schema it started from', function (string $table, string $prefix) {
    skipUnqualifiableTable($table);
    config(['logscope.table' => $table]);
    useTablePrefix($prefix);
    resetLogScopeSchema($table);

    $before = [];

    foreach (glob(__DIR__.'/../../database/migrations/*.php') as $migration) {
        $before[basename($migration)] = logScopeSchema($table);

        expect(Artisan::call('migrate', ['--path' => $migration, '--realpath' => true]))->toBe(0);
    }

    expect(Schema::hasIndex($table, ['ip_address', 'occurred_at']))->toBeTrue();

    foreach (array_reverse($before) as $migration => $schema) {
        expect(Artisan::call('migrate:rollback', ['--step' => 1]))->toBe(0)
            ->and(logScopeSchema($table))->toBe($schema, "rolling back {$migration}");
    }
})->with(logScopeTables());

// 2026_01_24's down() is empty because a converted v0.5 table is identical to
// a new install's. Pin that, and that such an install still resets.
it('converts a v0.5 install to the new schema and resets it', function (string $table, string $prefix) {
    skipUnqualifiableTable($table);
    config(['logscope.table' => $table]);
    useTablePrefix($prefix);
    resetLogScopeSchema($table);
    $migrations = __DIR__.'/../../database/migrations';

    Artisan::call('migrate', ['--path' => $migrations, '--realpath' => true]);
    $fresh = logScopeSchema($table);
    Artisan::call('db:wipe', ['--force' => true]);
    resetLogScopeSchema($table);

    // v0.5.2: 2026_01_12 also created environment, and the old 2026_01_22
    // added resolved_at, resolved_by and note.
    Artisan::call('migrate', ['--path' => "{$migrations}/2026_01_12_000001_create_log_entries_table.php", '--realpath' => true]);
    Schema::table($table, function (Blueprint $table) {
        $table->string('environment', 50)->nullable()->index();
        $table->index(['environment', 'level']);
        $table->timestamp('resolved_at')->nullable()->index();
        $table->string('resolved_by', 255)->nullable();
        $table->text('note')->nullable();
    });
    DB::table('migrations')->insert(['migration' => '2026_01_22_000001_add_resolved_and_note_to_log_entries_table', 'batch' => 1]);
    DB::table($table)->insert([
        'id' => '01J00000000000000000000000', 'level' => 'error', 'message' => 'm', 'environment' => 'production',
        'resolved_at' => now(), 'resolved_by' => 'admin', 'occurred_at' => now(), 'created_at' => now(),
    ]);

    expect(Artisan::call('migrate', ['--path' => $migrations, '--realpath' => true]))->toBe(0)
        ->and(logScopeSchema($table))->toBe($fresh)
        ->and(DB::table($table)->first(['status', 'status_changed_by']))
        ->toEqual((object) ['status' => 'resolved', 'status_changed_by' => 'admin']);

    expect(Artisan::call('migrate:reset', ['--path' => $migrations, '--realpath' => true]))->toBe(0)
        ->and(Schema::hasTable($table))->toBeFalse();
})->with(logScopeTables());
