<?php

declare(strict_types=1);

// `logscope:import --fresh` wipes the table before importing, and that wipe
// goes through LogEntry::deleteInChunks() like every other bulk delete (#46).
// Nothing covered the flag before, so neither the confirmation path nor the
// reported count nor the chunked wiring was pinned.

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogScope\Models\LogEntry;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->artisan('migrate', ['--path' => __DIR__.'/../../database/migrations']);
    LogEntry::query()->delete();
});

function seedForFresh(int $count): void
{
    LogEntry::insert(array_map(fn ($i) => LogEntry::prepareData([
        'level' => 'error',
        'message' => "existing {$i}",
        'channel' => 'single',
    ]), range(1, $count)));
}

it('empties the table when the wipe spans more than one chunk', function () {
    $total = LogEntry::DELETE_CHUNK_SIZE + 7;
    seedForFresh($total);

    $this->artisan('logscope:import', ['--fresh' => true, 'path' => nonexistentLogPath()])
        ->expectsConfirmation('This will delete all existing log entries. Continue?', 'yes')
        ->assertExitCode(0);

    expect(LogEntry::query()->count())->toBe(0);
});

it('deletes on the primary key rather than wiping by filter', function () {
    seedForFresh(3);

    $deletes = [];
    DB::listen(function ($query) use (&$deletes) {
        if (str_starts_with(strtolower(trim($query->sql)), 'delete')) {
            $deletes[] = $query->sql;
        }
    });

    $this->artisan('logscope:import', ['--fresh' => true, 'path' => nonexistentLogPath()])
        ->expectsConfirmation('This will delete all existing log entries. Continue?', 'yes')
        ->assertExitCode(0);

    expect($deletes)->not->toBeEmpty();

    foreach ($deletes as $sql) {
        expect($sql)->toContain('"id" in');
    }
})->skip(
    fn () => DB::getDriverName() !== 'sqlite',
    'Asserts on SQLite identifier quoting',
);

it('keeps every row when the confirmation is declined', function () {
    seedForFresh(4);

    $this->artisan('logscope:import', ['--fresh' => true, 'path' => nonexistentLogPath()])
        ->expectsConfirmation('This will delete all existing log entries. Continue?', 'no')
        ->assertExitCode(0);

    expect(LogEntry::query()->count())->toBe(4);
});

/**
 * A path that parses as "no files to import", so each test exercises the
 * --fresh wipe without also depending on log-file fixtures.
 */
function nonexistentLogPath(): string
{
    return __DIR__.'/../Fixtures/does-not-exist.log';
}
