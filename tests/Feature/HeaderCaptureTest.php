<?php

declare(strict_types=1);

// Tests for issue #30 — request headers captured onto their own column.
//
// The risk this feature carries is storing a secret, so the redaction
// tests here are the load-bearing ones: an allowlist entry must never be
// able to out-vote sensitive_headers. The rest pin the shape of the
// column (lowercased names, flat string values, null rather than {}),
// which both the `headers:` search and the detail panel depend on.

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use LogScope\LogScope;
use LogScope\LogScopeServiceProvider;
use LogScope\Models\LogEntry;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->artisan('migrate', ['--path' => __DIR__.'/../../database/migrations']);

    LogScopeServiceProvider::resetBufferState();
    LogEntry::query()->delete();

    Route::get('/logscope-test/headers', function () {
        Log::info('request logged');

        return 'ok';
    });
});

function lastEntryHeaders(): ?array
{
    return LogEntry::query()->latest('occurred_at')->first()?->headers;
}

it('stores only allowlisted headers', function () {
    config(['logscope.context.headers.allowlist' => ['x-request-id']]);

    $this->get('/logscope-test/headers', [
        'X-Request-Id' => 'req-abc',
        'X-Tenant-Id' => 'acme',
    ])->assertOk();

    expect(lastEntryHeaders())->toBe(['x-request-id' => 'req-abc']);
});

it('redacts a sensitive header even when it is explicitly allowlisted', function () {
    // 'authorization' contains the default sensitive fragment 'auth'.
    config(['logscope.context.headers.allowlist' => ['authorization']]);

    $this->get('/logscope-test/headers', [
        'Authorization' => 'Bearer super-secret-token',
    ])->assertOk();

    $headers = lastEntryHeaders();

    expect($headers)->toBe(['authorization' => '[REDACTED]'])
        ->and(json_encode($headers))->not->toContain('super-secret-token');
});

it('matches the allowlist case-insensitively and stores lowercased names', function () {
    config(['logscope.context.headers.allowlist' => ['X-Request-ID']]);

    $this->get('/logscope-test/headers', ['X-Request-Id' => 'req-xyz'])->assertOk();

    expect(lastEntryHeaders())->toBe(['x-request-id' => 'req-xyz']);
});

it('cuts values over max_value_length and marks them truncated', function () {
    config([
        'logscope.context.headers.allowlist' => ['x-request-id'],
        'logscope.context.headers.max_value_length' => 10,
    ]);

    $this->get('/logscope-test/headers', ['X-Request-Id' => str_repeat('a', 50)])->assertOk();

    $value = lastEntryHeaders()['x-request-id'];

    expect($value)->toBe(str_repeat('a', 10).'…[truncated]')
        ->and($value)->not->toBe(str_repeat('a', 50));
});

it('stores null rather than an empty object for CLI-originated logs', function () {
    // No request, so CaptureRequestContext never runs.
    Log::info('from the console');

    expect(lastEntryHeaders())->toBeNull()
        ->and(LogEntry::query()->latest('occurred_at')->first()->getRawOriginal('headers'))->toBeNull();
});

it('stores null when capture is disabled', function () {
    config(['logscope.context.headers.enabled' => false]);

    $this->get('/logscope-test/headers', ['X-Request-Id' => 'req-abc'])->assertOk();

    expect(lastEntryHeaders())->toBeNull();
});

describe('headers search', function () {
    beforeEach(function () {
        LogScope::auth(fn () => true);

        LogEntry::createEntry([
            'level' => 'info',
            'message' => 'xml client',
            'headers' => ['content-type' => 'application/xml'],
            'occurred_at' => now(),
        ]);

        LogEntry::createEntry([
            'level' => 'info',
            'message' => 'json client',
            'headers' => ['content-type' => 'application/json'],
            'occurred_at' => now()->subSecond(),
        ]);
    });

    afterEach(function () {
        LogScope::resetAuth();
    });

    it('finds an entry by a header value', function () {
        $response = $this->getJson('/logscope/api/logs?'.http_build_query([
            'searches' => [['field' => 'headers', 'value' => 'application/xml', 'exclude' => '0']],
        ]));

        $response->assertOk();

        expect($response->json('data'))->toHaveCount(1)
            ->and($response->json('data.0.message'))->toBe('xml client');
    });

    it('does not scan headers in plain-text search', function () {
        $response = $this->getJson('/logscope/api/logs?'.http_build_query([
            'searches' => [['field' => 'any', 'value' => 'application/xml', 'exclude' => '0']],
        ]));

        $response->assertOk();

        expect($response->json('data'))->toBeEmpty();
    });
});
