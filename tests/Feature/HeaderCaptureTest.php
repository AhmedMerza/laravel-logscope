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
use LogScope\Contracts\ContextSanitizerInterface;
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

it('keeps captured headers queue-serializable when a value is not valid UTF-8', function () {
    // The bug this pins is invisible at the storage layer, because
    // encodeHeaders() substitutes bad bytes on the way to the column. It
    // bites earlier: the captured array rides in the `logscope` Context
    // bag, which Laravel serializes into every job queued during the
    // request, using exactly the encode below. Returning false there makes
    // Laravel throw InvalidPayloadException — in the HOST app's own
    // dispatch, not just ours.
    $captured = app(ContextSanitizerInterface::class)->captureHeaders([
        'referer' => ['https://shop.test/cart/'.chr(0xB1).chr(0x1F)],
    ]);

    expect(json_encode($captured, JSON_UNESCAPED_UNICODE))->not->toBeFalse()
        ->and(mb_check_encoding($captured['referer'], 'UTF-8'))->toBeTrue();
});

it('survives a header value that is not valid UTF-8', function () {
    // Header bytes come from the client, so this is reachable by anyone.
    // Unencodable input used to make json_encode return false, which cast
    // to '' — wiping the column here, and failing the insert on MySQL and
    // Postgres, where '' is not valid JSON.
    config(['logscope.context.headers.allowlist' => ['referer']]);

    $this->get('/logscope-test/headers', [
        'Referer' => "https://shop.test/cart/".chr(0xB1).chr(0x1F),
    ])->assertOk();

    $entry = LogEntry::query()->latest('occurred_at')->first();

    expect($entry->getRawOriginal('headers'))->not->toBe('')
        ->and(json_decode($entry->getRawOriginal('headers'), true))->toBeArray()
        ->and($entry->headers)->toHaveKey('referer')
        ->and($entry->headers['referer'])->toStartWith('https://shop.test/cart/');
});

it('stores null rather than an empty object for CLI-originated logs', function () {
    // No request, so CaptureRequestContext never runs.
    Log::info('from the console');

    expect(lastEntryHeaders())->toBeNull()
        ->and(LogEntry::query()->latest('occurred_at')->first()->getRawOriginal('headers'))->toBeNull();
});

it('captures headers in batch write mode, which bypasses the model cast', function () {
    // batch is the shipped default, and it writes through LogEntry::insert()
    // — so prepareData() hand-encodes the column and the Eloquent mutator
    // never runs. The testing environment forces sync, so without this the
    // package's own default path is never exercised with headers at all.
    config([
        'logscope.write_mode' => 'batch',
        'logscope.context.headers.allowlist' => ['x-request-id'],
    ]);

    $this->get('/logscope-test/headers', ['X-Request-Id' => 'batched-1'])->assertOk();

    LogScopeServiceProvider::flushLogBufferStatic();

    $entry = LogEntry::query()->latest('occurred_at')->first();

    // Assert this is the REAL row, not a failure marker. Without that, the
    // test passes even when prepareData() stops encoding: insert() throws
    // on the raw array, LogBuffer catches it per chunk, and FallbackWriter
    // rewrites the row through Eloquent — where the mutator encodes headers
    // correctly. The consolation row would satisfy a headers-only assertion.
    expect($entry->message)->toBe('request logged')
        ->and($entry->context)->not->toHaveKey('_logscope_write_failure')
        ->and($entry->headers)->toBe(['x-request-id' => 'batched-1'])
        ->and(json_decode($entry->getRawOriginal('headers'), true))->toBeArray();
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
            ->and($response->json('data.0.message'))->toBe('xml client')
            // The list payload must carry headers, or the detail panel
            // falls back to a second request per row (#30).
            ->and($response->json('data.0.headers'))->toBe(['content-type' => 'application/xml']);
    });

    it('does not scan headers in plain-text search', function () {
        $response = $this->getJson('/logscope/api/logs?'.http_build_query([
            'searches' => [['field' => 'any', 'value' => 'application/xml', 'exclude' => '0']],
        ]));

        $response->assertOk();

        expect($response->json('data'))->toBeEmpty();
    });
});
