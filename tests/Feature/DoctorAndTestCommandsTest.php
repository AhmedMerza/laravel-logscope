<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use LogScope\LogScope;
use LogScope\Models\LogEntry;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Force the array cache so breadcrumb writes don't hit a non-existent
    // `cache` table in the test SQLite DB.
    config(['cache.default' => 'array']);
    Cache::flush();
});

afterEach(function (): void {
    LogScope::resetAuth();
});

it('logscope:doctor passes the table check after migration', function (): void {
    $exit = Artisan::call('logscope:doctor');
    $output = Artisan::output();

    expect($output)->toContain('LogScope Doctor');
    expect($output)->toContain('Table');
    expect($output)->toContain('log_entries exists');
    // table FAIL absent, but other warnings (auth, retention auto_schedule) may
    // still cause non-zero exit. We don't assert on exit code here — only that
    // the table check passed and the rendering happened.
    expect($exit)->toBeIn([0, 1]);
});

it('logscope:doctor reports the failure breadcrumb when one exists', function (): void {
    cache()->forever('logscope:write_failures:count', 3);
    cache()->forever('logscope:write_failures:first_at', '2026-05-01T00:00:00+00:00');
    cache()->forever('logscope:write_failures:last', [
        'class' => 'Illuminate\\Database\\QueryException',
        'message' => 'connection refused',
        'where' => 'listener',
        'at' => '2026-05-02T00:00:00+00:00',
    ]);

    Artisan::call('logscope:doctor');
    $output = Artisan::output();

    expect($output)->toContain('Recent write failures');
    expect($output)->toContain('connection refused');
    expect($output)->toContain('listener');
});

it('logscope:doctor flags missing auth setup outside local env', function (): void {
    app()['env'] = 'production';

    Artisan::call('logscope:doctor');
    $output = Artisan::output();

    expect($output)->toContain('Authorization');
    expect($output)->toContain('UI is INACCESSIBLE');
});

it('logscope:doctor recognises a custom auth callback', function (): void {
    LogScope::auth(fn () => true);

    Artisan::call('logscope:doctor');
    $output = Artisan::output();

    expect($output)->toContain('custom callback registered');
});

it('logscope:doctor recognises the viewLogScope gate', function (): void {
    Gate::define('viewLogScope', fn () => true);

    Artisan::call('logscope:doctor');
    $output = Artisan::output();

    expect($output)->toContain('gate `viewLogScope` defined');
});

it('logscope:doctor --json emits a parseable summary instead of a table', function (): void {
    $exit = Artisan::call('logscope:doctor', ['--json' => true]);
    $output = trim(Artisan::output());

    $decoded = json_decode($output, true);
    expect($decoded)->toBeArray();
    expect($decoded)->toHaveKeys(['results', 'summary']);
    expect($decoded['summary'])->toHaveKeys(['pass', 'warn', 'fail']);
    expect($decoded['results'])->not->toBeEmpty();
    expect($exit)->toBeIn([0, 1]);
});

it('logscope:doctor flags an unknown queue connection in queue write mode', function (): void {
    config([
        'logscope.write_mode' => 'queue',
        'logscope.queue.connection' => 'this-connection-does-not-exist',
    ]);

    Artisan::call('logscope:doctor');
    $output = Artisan::output();

    expect($output)->toContain('Write mode');
    expect($output)->toContain('this-connection-does-not-exist');
    expect($output)->toContain('not defined');
});

it('logscope:doctor reports queue mode using sync driver as a warning', function (): void {
    config([
        'logscope.write_mode' => 'queue',
        'logscope.queue.connection' => 'sync',
        'queue.connections.sync' => ['driver' => 'sync'],
    ]);

    Artisan::call('logscope:doctor');
    $output = Artisan::output();

    expect($output)->toContain('queue mode using `sync` driver');
});

it('logscope:doctor warns when middleware is disabled', function (): void {
    config(['logscope.middleware.enabled' => false]);

    Artisan::call('logscope:doctor');
    $output = Artisan::output();

    expect($output)->toContain('Middleware');
    expect($output)->toContain('disabled');
});

it('logscope:doctor recognises a user-scheduled prune entry when auto_schedule is off', function (): void {
    config(['logscope.retention.auto_schedule' => false]);

    // Pretend the user wired prune in their own console kernel.
    app(\Illuminate\Console\Scheduling\Schedule::class)
        ->command('logscope:prune')
        ->dailyAt('02:00');

    Artisan::call('logscope:doctor');
    $output = Artisan::output();

    expect($output)->toContain('Retention');
    expect($output)->toContain('prune is scheduled by your app');
});

it('logscope:test captures and verifies a log entry end-to-end', function (): void {
    $exit = Artisan::call('logscope:test');
    $output = Artisan::output();

    expect($exit)->toBe(0);
    expect($output)->toContain('Test log captured successfully');
    // --keep was not passed, so the entry should be cleaned up
    expect(LogEntry::query()->where('message', 'like', '%LogScope test ping%')->count())->toBe(0);
});

it('logscope:test --keep retains the entry for inspection', function (): void {
    $exit = Artisan::call('logscope:test', ['--keep' => true]);

    expect($exit)->toBe(0);
    expect(LogEntry::query()->where('message', 'like', '%LogScope test ping%')->count())->toBe(1);
});

it('logscope:test restores the original write_mode after running', function (): void {
    config(['logscope.write_mode' => 'batch']);

    Artisan::call('logscope:test');

    expect(config('logscope.write_mode'))->toBe('batch');
});

it('logscope:test restores write_mode even when the verify query throws', function (): void {
    config(['logscope.write_mode' => 'queue']);

    // Drop the table so the verify query throws inside the command. The
    // log emit will also fail (the sync writer can't insert), but the
    // command catches both and the finally block must still fire.
    \Illuminate\Support\Facades\Schema::drop('log_entries');

    Artisan::call('logscope:test');

    expect(config('logscope.write_mode'))->toBe('queue');
});

it('logscope:test fails clearly when capture=channel but no logscope channel is defined', function (): void {
    config(['logscope.capture' => 'channel']);
    config(['logging.channels.logscope' => null]);
    // Remove the key entirely so isset() returns false
    $channels = config('logging.channels');
    unset($channels['logscope']);
    config(['logging.channels' => $channels]);

    $exit = Artisan::call('logscope:test');
    $output = Artisan::output();

    expect($exit)->toBe(1);
    expect($output)->toContain('no `logscope` channel is defined');
});
