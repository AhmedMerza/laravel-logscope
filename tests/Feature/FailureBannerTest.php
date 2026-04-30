<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use LogScope\Logging\ChannelContextProcessor;
use LogScope\LogScope;
use LogScope\LogScopeServiceProvider;
use LogScope\Models\LogEntry;
use LogScope\Services\WriteFailureLogger;

uses(RefreshDatabase::class);

beforeEach(function () {
    ChannelContextProcessor::clearLastChannel();
    LogScopeServiceProvider::resetBufferState();
    WriteFailureLogger::reset();
    LogEntry::query()->delete();
    Cache::flush();

    config(['logscope.write_mode' => 'sync']);

    // Bypass LogScope's authorize middleware in test env (default check
    // requires 'local' env, but Testbench uses 'testing').
    LogScope::auth(fn () => true);

    // Redirect error_log to a tmp file so test runs don't pollute stderr.
    $this->errorLogFile = tempnam(sys_get_temp_dir(), 'logscope-banner-test-');
    $this->originalErrorLog = ini_get('error_log');
    ini_set('error_log', $this->errorLogFile);
});

afterEach(function () {
    LogScope::resetAuth();

    ini_set('error_log', $this->originalErrorLog);
    if (file_exists($this->errorLogFile)) {
        @unlink($this->errorLogFile);
    }

    if (! Schema::hasTable('log_entries')) {
        $this->artisan('migrate', ['--path' => __DIR__.'/../../database/migrations']);
    }
});

it('writes a cache breadcrumb on each reported failure', function () {
    Schema::drop('log_entries');

    \Illuminate\Support\Facades\Log::error('boom');

    $banner = WriteFailureLogger::recentFailures();

    expect($banner)->not->toBeNull()
        ->and($banner['count'])->toBe(1)
        ->and($banner['last_class'])->toBe(\Illuminate\Database\QueryException::class)
        ->and($banner['last_where'])->toBe('listener')
        ->and($banner['last_at'])->not->toBe('');
});

it('increments the count across multiple failures', function () {
    Schema::drop('log_entries');

    for ($i = 0; $i < 3; $i++) {
        \Illuminate\Support\Facades\Log::error('repeat');
    }

    $banner = WriteFailureLogger::recentFailures();
    expect($banner['count'])->toBe(3);
});

it('returns null when there are no recent failures', function () {
    expect(WriteFailureLogger::recentFailures())->toBeNull();
});

it('dismissFailures clears the cached breadcrumb', function () {
    Schema::drop('log_entries');
    \Illuminate\Support\Facades\Log::error('boom');

    expect(WriteFailureLogger::recentFailures())->not->toBeNull();

    WriteFailureLogger::dismissFailures();

    expect(WriteFailureLogger::recentFailures())->toBeNull();
});

it('returns null gracefully when the cache binding is missing', function () {
    // Simulate cache being unavailable. We can't unbind 'cache' easily
    // without breaking other parts of the app, so we use a stubbed
    // container. recentFailures() must not throw.
    $original = \Illuminate\Container\Container::getInstance();
    $stub = new \Illuminate\Container\Container;
    \Illuminate\Container\Container::setInstance($stub);

    try {
        expect(WriteFailureLogger::recentFailures())->toBeNull();
    } finally {
        \Illuminate\Container\Container::setInstance($original);
    }
});

it('the dismiss endpoint clears the breadcrumb and returns ok', function () {
    Schema::drop('log_entries');
    \Illuminate\Support\Facades\Log::error('boom');

    expect(WriteFailureLogger::recentFailures())->not->toBeNull();

    $response = $this->postJson(route('logscope.failures.dismiss'));

    $response->assertOk()->assertJson(['ok' => true]);
    expect(WriteFailureLogger::recentFailures())->toBeNull();
});

it('persists the breadcrumb forever by default (no TTL config)', function () {
    config(['logscope.failure_banner.ttl_seconds' => null]);

    WriteFailureLogger::report(new \RuntimeException('forever'), 'test');

    // The cache key should be stored without a TTL — Laravel's array
    // cache doesn't expose TTL inspection, but we can verify the value
    // is still there after the default-1-hour window we used to use.
    expect(WriteFailureLogger::recentFailures())->not->toBeNull()
        ->and(WriteFailureLogger::recentFailures()['count'])->toBe(1);

    // Travel past the old default 1-hour window to confirm it still survives.
    $this->travel(2)->hours();
    expect(WriteFailureLogger::recentFailures())->not->toBeNull();
});

it('honors a configured ttl_seconds when set', function () {
    // Note: this test relies on Testbench's default array-cache driver
    // computing expiry against Carbon::now() — so $this->travel() makes
    // the cached value appear expired. Production drivers like Redis or
    // database cache enforce TTL at the storage layer and ignore Carbon
    // travel; behavior there is verified manually rather than in CI.
    config(['logscope.failure_banner.ttl_seconds' => 10]);

    WriteFailureLogger::report(new \RuntimeException('temporary'), 'test');

    expect(WriteFailureLogger::recentFailures())->not->toBeNull();

    // Travel past the configured TTL — the breadcrumb should be gone.
    $this->travel(11)->seconds();
    expect(WriteFailureLogger::recentFailures())->toBeNull();
});

it('truncates very long error messages before caching', function () {
    $longMessage = str_repeat('A', 800).'_END';

    WriteFailureLogger::report(new \RuntimeException($longMessage), 'test');

    $banner = WriteFailureLogger::recentFailures();

    // 500 char limit + truncation indicator. The "_END" tail should be gone.
    expect($banner['last_message'])->not->toContain('_END')
        ->and(mb_strlen($banner['last_message']))->toBeLessThanOrEqual(515);
});

it('records first_at on the first failure and preserves it across subsequent reports', function () {
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-04-30 12:00:00'));
    WriteFailureLogger::report(new \RuntimeException('first'), 'test');

    $firstAt = WriteFailureLogger::recentFailures()['first_at'];

    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-04-30 12:30:00'));
    WriteFailureLogger::report(new \RuntimeException('second'), 'test');

    $banner = WriteFailureLogger::recentFailures();

    expect($banner['count'])->toBe(2)
        ->and($banner['first_at'])->toBe($firstAt)  // unchanged
        ->and($banner['last_at'])->not->toBe($firstAt);  // updated
});

it('respects the failure_banner.enabled config in the index view', function () {
    // Write a breadcrumb directly without breaking the schema — the index
    // controller queries log_entries to populate filter dropdowns and would
    // fail before reaching our viewData if the table is gone.
    WriteFailureLogger::report(new \RuntimeException('forced'), 'test-injection');

    // Banner enabled: index gets the breadcrumb
    config(['logscope.failure_banner.enabled' => true]);
    $response = $this->get(route('logscope.index'));
    $response->assertOk();
    expect($response->viewData('failureBanner'))->not->toBeNull();

    // Banner disabled: index sees null even though breadcrumb is in cache
    config(['logscope.failure_banner.enabled' => false]);
    $response = $this->get(route('logscope.index'));
    $response->assertOk();
    expect($response->viewData('failureBanner'))->toBeNull();
});
