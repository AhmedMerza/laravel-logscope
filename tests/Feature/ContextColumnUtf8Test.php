<?php

declare(strict_types=1);

// Tests for issue #67 — a raw client byte reaching the log entry's own
// `context` column.
//
// Third surface for the same root cause: #30 fixed it for the `headers`
// column, #63 for the request-context bag Laravel serializes into queued
// jobs. This one is the most commonly reached of the three, because it
// fires on ordinary application logging rather than on request capture —
// any Log::warning('…', ['agent' => $request->userAgent()]) will do it.
//
// The failure is self-inflicted rather than host-visible: the request still
// returns 200, but json_encode() returns false, createPreview() raises a
// TypeError, and FallbackWriter rewrites the row with a
// _logscope_write_failure marker. So the one log line written specifically
// to record a bad request is the line that arrives with no detail — which
// is why every assertion below checks for the REAL row, not just any row.

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use LogScope\LogScopeServiceProvider;
use LogScope\Models\LogEntry;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->artisan('migrate', ['--path' => __DIR__.'/../../database/migrations']);

    LogScopeServiceProvider::resetBufferState();
    LogEntry::query()->delete();
});

/**
 * A User-Agent as a broken HTTP client or scanner sends it: 0xB1 is a
 * continuation byte with no lead byte, so the string is not valid UTF-8 and
 * json_encode() returns false on it.
 */
function malformedAgent(): string
{
    return 'Mozilla/5.0 '.chr(0xB1).chr(0x1F);
}

function lastRealEntry(): ?LogEntry
{
    return LogEntry::query()->latest('occurred_at')->first();
}

it('stores the real context in sync mode rather than a failure marker', function () {
    config(['logscope.write_mode' => 'sync']);

    Log::warning('rejected request', ['agent' => malformedAgent()]);

    $entry = lastRealEntry();

    expect($entry)->not->toBeNull()
        ->and($entry->message)->toBe('rejected request')
        ->and($entry->context)->not->toHaveKey('_logscope_write_failure')
        // Substituted, not dropped: the readable part of the agent survives.
        ->and($entry->context['agent'])->toStartWith('Mozilla/5.0 ')
        ->and(mb_check_encoding($entry->context['agent'], 'UTF-8'))->toBeTrue();
});

it('stores the real context in batch mode, which bypasses the model mutator', function () {
    // batch is the shipped default and writes through LogEntry::insert(), so
    // prepareData() hand-encodes the column and the mutator never runs. The
    // testing environment forces sync, so without this the package's own
    // default path is never exercised with a malformed byte at all.
    config(['logscope.write_mode' => 'batch']);

    Log::warning('rejected request', ['agent' => malformedAgent()]);

    LogScopeServiceProvider::flushLogBufferStatic();

    $entry = lastRealEntry();

    expect($entry)->not->toBeNull()
        ->and($entry->message)->toBe('rejected request')
        // Load-bearing: without it the test passes on the consolation row.
        // insert() fails on the unencodable array, LogBuffer catches it per
        // chunk, and FallbackWriter rewrites the row through Eloquent.
        ->and($entry->context)->not->toHaveKey('_logscope_write_failure')
        ->and($entry->context['agent'])->toStartWith('Mozilla/5.0 ');
});

it('stores the real context in queue mode, which serializes before storage', function () {
    // The boundary the storage encoder cannot reach. Laravel encodes the
    // whole job payload inside dispatch(), so a malformed byte throws
    // InvalidPayloadException in the CALLER — no job is ever queued, and
    // FallbackWriter writes the consolation row synchronously instead.
    config(['logscope.write_mode' => 'queue', 'queue.default' => 'database']);

    Schema::dropIfExists('jobs');
    Schema::create('jobs', function (Blueprint $table) {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    Log::warning('rejected request', ['agent' => malformedAgent()]);

    // Dispatch has to have survived for there to be a job at all.
    expect(DB::table('jobs')->count())->toBe(1);

    $this->artisan('queue:work', ['--once' => true]);

    $entry = LogEntry::query()->where('message', 'rejected request')->latest('occurred_at')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->context)->not->toHaveKey('_logscope_write_failure')
        ->and($entry->context['agent'])->toStartWith('Mozilla/5.0 ');
});

it('stores valid JSON in the column itself, not an empty string', function () {
    // sqlite accepts '' in a json column; MySQL and Postgres reject it
    // outright, so an assertion on the decoded attribute alone would pass
    // here and fail the insert on the databases people actually deploy.
    config(['logscope.write_mode' => 'sync']);

    Log::warning('rejected request', ['agent' => malformedAgent()]);

    $raw = lastRealEntry()->getRawOriginal('context');

    expect($raw)->not->toBe('')
        ->and(json_decode($raw, true))->toBeArray()
        ->and(json_last_error())->toBe(JSON_ERROR_NONE);
});

it('keeps the context preview a string when the context cannot be cleanly encoded', function () {
    // createPreview(string $content) raised a TypeError on the false that
    // json_encode() returned — the actual reported crash.
    config(['logscope.write_mode' => 'sync']);

    Log::warning('rejected request', ['agent' => malformedAgent()]);

    expect(lastRealEntry()->context_preview)->toBeString()->not->toBe('');
});

it('survives a malformed byte in a header NAME, not just a value', function () {
    // sanitizeHeaders() — reached when a Request object is logged — has no
    // allowlist, unlike captureHeaders(). The header *names* are therefore
    // attacker-controlled and become JSON object keys inside context, where
    // a bad byte breaks json_encode() exactly as one in a value does.
    config(['logscope.write_mode' => 'sync']);

    Route::get('/logscope-test/bad-header-name', function (Request $request) {
        // Set on the inbound request rather than sent over the wire: the
        // test HTTP layer normalises names on the way in, and the point
        // under test is what sanitizeHeaders() does with one, not whether
        // Symfony will carry it.
        $request->headers->set('X-Trace-'.chr(0xB1), 'abc');

        Log::error('order failed', ['request' => $request]);

        return 'ok';
    });

    $this->get('/logscope-test/bad-header-name')->assertOk();

    $entry = LogEntry::query()->where('message', 'order failed')->latest('occurred_at')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->context)->not->toHaveKey('_logscope_write_failure')
        ->and(json_decode($entry->getRawOriginal('context'), true))->toBeArray()
        ->and(json_last_error())->toBe(JSON_ERROR_NONE);
});

it('writes a real row for a logged Request carrying a malformed header value', function () {
    config(['logscope.write_mode' => 'sync']);

    Route::get('/logscope-test/context-utf8', function (Request $request) {
        Log::error('order failed', ['request' => $request]);

        return 'ok';
    });

    $this->get('/logscope-test/context-utf8', ['Referer' => 'https://shop.test/'.chr(0xB1)])
        ->assertOk();

    $entry = LogEntry::query()->where('message', 'order failed')->latest('occurred_at')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->context)->not->toHaveKey('_logscope_write_failure')
        ->and($entry->context)->toHaveKey('request');
});
