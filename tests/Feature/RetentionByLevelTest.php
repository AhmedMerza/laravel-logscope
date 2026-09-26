<?php

declare(strict_types=1);

// Retention by level (#31): each level listed in retention.levels is pruned
// on its own window, every other level on retention.days.

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use LogScope\Models\LogEntry;

uses(RefreshDatabase::class);

function seedAged(array $rows): void
{
    LogEntry::insert(array_map(fn (array $row) => LogEntry::prepareData([
        'level' => $row[0],
        'message' => "{$row[0]} {$row[1]}d",
        'channel' => 'single',
        'occurred_at' => now()->subDays($row[1]),
    ]), $rows));
}

function survivors(): array
{
    return LogEntry::query()->orderBy('message')->pluck('message')->all();
}

beforeEach(function (): void {
    seedAged([
        ['debug', 1], ['debug', 5],
        ['info', 20], ['info', 40],
        ['error', 60], ['error', 100],
    ]);
});

it('prunes every level on retention.days when no levels are listed', function (): void {
    config(['logscope.retention.days' => 30]);

    Artisan::call('logscope:prune');

    expect(survivors())->toBe(['debug 1d', 'debug 5d', 'info 20d'])
        ->and(Artisan::output())->toContain('older than 30 days');
});

it('prunes each listed level on its own window and the rest on the fallback', function (): void {
    config([
        'logscope.retention.days' => 30,
        'logscope.retention.levels' => ['debug' => 3, 'ERROR' => 90],
    ]);

    Artisan::call('logscope:prune');

    // debug 5d is past 3 days, info 40d past the 30-day fallback, error
    // 100d past 90; error 60d outlives the fallback it would otherwise get.
    expect(survivors())->toBe(['debug 1d', 'error 60d', 'info 20d']);
});

it('applies the same policy through model:prune', function (): void {
    config(['logscope.retention.levels' => ['debug' => 3, 'error' => 90]]);

    expect((new LogEntry)->prunable()->orderBy('message')->pluck('message')->all())
        ->toBe(['debug 5d', 'error 100d', 'info 40d']);
});

it('lets --days override the per-level policy', function (): void {
    config(['logscope.retention.levels' => ['error' => 90]]);

    Artisan::call('logscope:prune', ['--days' => 10]);

    expect(survivors())->toBe(['debug 1d', 'debug 5d']);
});

it('dry run breaks the count down per level with each window, deleting nothing', function (): void {
    config([
        'logscope.retention.days' => 30,
        'logscope.retention.levels' => ['debug' => 3, 'error' => 90],
    ]);

    Artisan::call('logscope:prune', ['--dry-run' => true]);
    $output = Artisan::output();

    expect($output)->toContain('Found 3 log entries past their retention window')
        ->and($output)->toMatch('/DEBUG\s*\|\s*3 days\s*\|\s*1/')
        ->and($output)->toMatch('/INFO\s*\|\s*30 days\s*\|\s*1/')
        ->and($output)->toMatch('/ERROR\s*\|\s*90 days\s*\|\s*1/')
        ->and(LogEntry::query()->count())->toBe(6);
});

it('doctor prints the effective policy per level', function (): void {
    config([
        'logscope.retention.days' => 30,
        'logscope.retention.levels' => ['debug' => 3, 'error' => 90],
    ]);

    Artisan::call('logscope:doctor');

    expect(Artisan::output())->toContain('debug 3d, error 90d, others 30d');
});

it('doctor warns about a level name that matches nothing', function (): void {
    config(['logscope.retention.levels' => ['eror' => 90]]);

    Artisan::call('logscope:doctor');

    expect(Artisan::output())->toContain('unknown level(s): eror');
});
