<?php

declare(strict_types=1);

// Each migration's down() must put the table back exactly as its up() found
// it, on the default table name and a custom one. 2026_01_24's down() undid
// changes its up() never made, so rolling back failed on every install and
// DatabaseMigrations broke after each test (#41). 2026_04_28 hard-coded
// log_entries, so migrate failed when logscope.table was set (#42).
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

it('rolls each migration back to the schema it started from', function (string $table) {
    config(['logscope.table' => $table]);

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
})->with(['log_entries', 'app_logs']);

// 2026_01_24's down() is empty because a converted v0.5 table is identical to
// a new install's. Pin that, and that such an install still resets.
it('converts a v0.5 install to the new schema and resets it', function (string $table) {
    config(['logscope.table' => $table]);
    $migrations = __DIR__.'/../../database/migrations';

    Artisan::call('migrate', ['--path' => $migrations, '--realpath' => true]);
    $fresh = logScopeSchema($table);
    Artisan::call('db:wipe', ['--force' => true]);

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
})->with(['log_entries', 'app_logs']);
