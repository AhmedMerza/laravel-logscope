<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use LogScope\Http\Middleware\CaptureRequestContext;
use LogScope\LogScope;
use LogScope\Models\LogEntry;
use LogScope\Services\ContextSanitizer;

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

it('logscope:doctor renders `(unset)` rather than empty backticks when no queue connection resolves', function (): void {
    config([
        'logscope.write_mode' => 'queue',
        'logscope.queue.connection' => null,
        'queue.default' => null,
    ]);

    Artisan::call('logscope:doctor');
    $output = Artisan::output();

    expect($output)->toContain('connection `(unset)`');
    expect($output)->not->toContain('connection `` ');
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

it('logscope:doctor passes when CaptureRequestContext sits after TrustProxies', function (): void {
    Artisan::call('logscope:doctor');

    expect(Artisan::output())->toContain('after TrustProxies');
});

it('logscope:doctor fails when CaptureRequestContext runs before TrustProxies', function (): void {
    // The #54 ordering, as apps that registered the middleware themselves
    // may still have it.
    app(Kernel::class)->setGlobalMiddleware([
        CaptureRequestContext::class,
        TrustProxies::class,
    ]);

    Artisan::call('logscope:doctor');

    expect(Artisan::output())->toContain('before TrustProxies');
});

it('logscope:doctor fails when CaptureRequestContext is not in the global stack at all', function (): void {
    // The branch that flips doctor's exit code: something replaced the stack
    // after LogScope booted, so no request context is captured at all.
    app(Kernel::class)->setGlobalMiddleware([TrustProxies::class]);

    Artisan::call('logscope:doctor');

    // Name the subject: the TrustProxies-absent warning below ends in the same
    // "is not in the global stack", so the bare substring can't tell the two
    // branches apart and would pass on either.
    expect(Artisan::output())->toContain('CaptureRequestContext is not in the global stack');
});

it('logscope:doctor warns when the kernel can only be prepended to', function (): void {
    // No stack accessors but prependMiddleware exists, so registerMiddleware()
    // did register — at the front, ahead of TrustProxies. Wrong position, not
    // absent.
    app()->instance(Kernel::class, new class
    {
        public function prependMiddleware($middleware) {}
    });

    Artisan::call('logscope:doctor');

    expect(Artisan::output())->toContain('could only prepend');
});

it('logscope:doctor fails when the kernel exposes no middleware API at all', function (): void {
    // Neither accessor nor prependMiddleware: registerMiddleware() registered
    // NOTHING. Every context field is missing, not just ip_address — reporting
    // that as a proxy-IP warning would understate it.
    app()->instance(Kernel::class, new class
    {
        // intentionally empty — no middleware API whatsoever
    });

    Artisan::call('logscope:doctor');

    expect(Artisan::output())->toContain('exposes no middleware API');
});

it('logscope:doctor warns when TrustProxies is not in the global stack', function (): void {
    app(Kernel::class)->setGlobalMiddleware([CaptureRequestContext::class]);

    Artisan::call('logscope:doctor');

    expect(Artisan::output())->toContain('TrustProxies is not in the global stack');
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

it('reports the header allowlist when capture is on', function (): void {
    config([
        'logscope.context.headers.enabled' => true,
        'logscope.context.headers.allowlist' => ['content-type', 'x-request-id'],
        'logscope.context.headers.max_value_length' => 250,
    ]);

    Artisan::call('logscope:doctor');
    $output = Artisan::output();

    expect($output)->toContain('Header capture')
        ->and($output)->toContain('content-type')
        ->and($output)->toContain('x-request-id')
        ->and($output)->toContain('250');
});

it('warns that no headers are stored when capture is off', function (): void {
    config(['logscope.context.headers.enabled' => false]);

    Artisan::call('logscope:doctor');
    $output = Artisan::output();

    expect($output)->toContain('Header capture')
        ->and($output)->toContain('disabled');
});

it('warns that an empty allowlist captures nothing', function (): void {
    // Worth its own branch: enabled + empty reads like it works, and the
    // allowlist is the only dial — there is no "capture everything" mode.
    config([
        'logscope.context.headers.enabled' => true,
        'logscope.context.headers.allowlist' => [],
    ]);

    Artisan::call('logscope:doctor');
    $output = Artisan::output();

    expect($output)->toContain('Header capture')
        ->and($output)->toContain('allowlist is empty');
});

it('reports the sensitive keys actually in force (#79)', function (): void {
    // Until #79 nothing answered "what is redacted right now?", which is the
    // question that would have caught sensitive_keys dropping its defaults.
    config(['logscope.context.sensitive_keys' => ['pin']]);

    // The sanitizer is a singleton built from config on first resolve, so a
    // config change mid-process needs the instance dropped to mirror what a
    // fresh `artisan` run does.
    app()->forgetInstance(ContextSanitizer::class);

    Artisan::call('logscope:doctor');
    $output = Artisan::output();

    // The configured key and the defaults it no longer replaces, together.
    expect($output)->toContain('Redaction')
        ->and($output)->toContain('pin')
        ->and($output)->toContain('password')
        ->and($output)->toContain('keys redacted');
});

it('warns when redaction is switched off entirely', function (): void {
    config(['logscope.context.redact_sensitive' => false]);
    app()->forgetInstance(ContextSanitizer::class);

    Artisan::call('logscope:doctor');
    $output = Artisan::output();

    expect($output)->toContain('Redaction')
        ->and($output)->toContain('stored exactly as logged');
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
