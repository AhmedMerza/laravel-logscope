<?php

declare(strict_types=1);

// Tests for issue #63 — a raw client byte reaching the `logscope` Context bag.
//
// The blast radius is what makes these load-bearing: Laravel serializes the
// whole Context bag into every job queued during the request, so a malformed
// User-Agent does not degrade LogScope's own logging — it makes the HOST
// application's `SomeJob::dispatch()` throw InvalidPayloadException, with
// nothing in the trace pointing at a logging middleware.
//
// #30 fixed this class for captured headers. `user_agent` sat on the line
// directly above them and never passed through the same coercion, which is
// why the guard now wraps the bag rather than any one field.

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
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

    // The sync driver hands the job straight to the worker and never encodes
    // a payload, so it cannot reproduce this at all. `database` is the
    // cheapest connection that actually runs Queue::createPayload().
    config(['queue.default' => 'database']);

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
});

/**
 * A User-Agent as a broken HTTP client or scanner sends it: 0xB1 is a
 * continuation byte with no lead byte, so the string is not valid UTF-8 and
 * json_encode() returns false on it.
 */
function malformedUserAgent(): string
{
    return 'Mozilla/5.0 '.chr(0xB1).chr(0x1F);
}

it('leaves the context bag encodable the way Laravel encodes queue payloads', function () {
    // Asserted from inside the request, because the bag is what the queue
    // reads mid-request — not whatever survives to the end of the test.
    Route::get('/logscope-test/bag', fn () => response()->json([
        'encodes' => json_encode(Context::get('logscope'), JSON_UNESCAPED_UNICODE) !== false,
        'user_agent_valid' => mb_check_encoding(Context::get('logscope')['user_agent'], 'UTF-8'),
    ]));

    $this->get('/logscope-test/bag', ['User-Agent' => malformedUserAgent()])
        ->assertOk()
        ->assertJson(['encodes' => true, 'user_agent_valid' => true]);
});

it('lets the host application dispatch its own job during such a request', function () {
    // This is the reported symptom: the app's own queue work, nothing to do
    // with logging, stops for the rest of the request.
    Route::get('/logscope-test/dispatch', function () {
        dispatch(function () {
            // stands in for the host app's own job
        });

        return 'dispatched';
    });

    $this->get('/logscope-test/dispatch', ['User-Agent' => malformedUserAgent()])
        ->assertOk()
        ->assertSee('dispatched');

    expect(DB::table('jobs')->count())->toBe(1);
});

it('writes a real row in queue write mode rather than a failure marker', function () {
    config(['logscope.write_mode' => 'queue']);

    Route::get('/logscope-test/queue-mode', function () {
        Log::info('request logged');

        return 'ok';
    });

    $this->get('/logscope-test/queue-mode', ['User-Agent' => malformedUserAgent()])->assertOk();

    // Dispatch has to have survived for there to be a job at all; without
    // the fix FallbackWriter catches InvalidPayloadException and writes the
    // consolation row synchronously instead.
    expect(DB::table('jobs')->count())->toBe(1);

    $this->artisan('queue:work', ['--once' => true]);

    $entry = LogEntry::query()->where('message', 'request logged')->latest('occurred_at')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->context)->not->toHaveKey('_logscope_write_failure')
        ->and(mb_check_encoding($entry->user_agent, 'UTF-8'))->toBeTrue()
        // Substituted, not dropped: the readable part of the agent survives.
        ->and($entry->user_agent)->toStartWith('Mozilla/5.0 ');
});
