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

use Illuminate\Support\Facades\Artisan;
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
