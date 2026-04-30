<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogScope\Logging\ChannelContextProcessor;
use LogScope\LogScopeServiceProvider;
use LogScope\Models\LogEntry;
use LogScope\Services\WriteGuard;

uses(RefreshDatabase::class);

beforeEach(function () {
    ChannelContextProcessor::clearLastChannel();
    LogScopeServiceProvider::resetBufferState();
    WriteGuard::reset();
    LogEntry::query()->delete();

    config(['logscope.write_mode' => 'sync']);
});

afterEach(function () {
    // Test isolation: drop observers wired up by this test class so they
    // don't leak to other tests. This wipes ALL listeners on LogEntry,
    // including any the framework may have added (currently none — the
    // model uses HasUlids/HasFactory/Prunable but none register listeners).
    // If future Laravel versions add framework listeners we need to keep,
    // switch to a more surgical removal.
    LogEntry::flushEventListeners();
});

it('does not re-capture logs fired by an observer during the write itself', function () {
    // Hard cap so a regression doesn't actually run forever.
    $observerFireCount = 0;

    LogEntry::created(function () use (&$observerFireCount) {
        $observerFireCount++;
        if ($observerFireCount > 4) {
            return;
        }
        // This Log::warning is fired DURING LogScope's write of the user log.
        // Without a re-entrant guard, the listener captures it → triggers
        // another insert → observer fires again → recursion.
        Log::warning('observer-side-effect-'.$observerFireCount);
    });

    Log::error('user log');

    // With the guard: only the user log lands. The observer's warning fires
    // but the listener returns early (we're inside a write), so no second
    // insert happens.
    expect(LogEntry::count())->toBe(1)
        ->and(LogEntry::first()->message)->toBe('user log')
        ->and($observerFireCount)->toBe(1);
});

it('clears the guard after the write so subsequent unrelated logs are captured', function () {
    Log::error('first');
    Log::error('second');

    expect(LogEntry::count())->toBe(2);
});

it('captures a follow-up log even when a re-entrant write happened just before', function () {
    // Locks in the contract: re-entrancy guard state (WriteGuard::$depth and
    // LogScopeHandler::$handledCurrentLog) must NOT leak into the next log.
    //
    // Trace: user log #1 → observer fires inner log → guard skips inner →
    //        user log #2 must still land.
    //
    // If a future refactor reorders the listener checks (e.g. moves
    // didHandleCurrentLog before the WriteGuard check), the handledCurrentLog
    // flag could be consumed by the inner listener and leak past log #1,
    // causing log #2 to be silently dropped. This test catches that.
    LogEntry::created(function () {
        // Fire exactly one re-entrant log (no cap needed; the guard prevents recursion).
        Log::warning('side-effect');
    });

    Log::error('user log #1');

    // Drop observer so the follow-up log doesn't trigger another side-effect.
    LogEntry::flushEventListeners();

    Log::error('user log #2');

    expect(LogEntry::count())->toBe(2)
        ->and(LogEntry::pluck('message')->all())->toEqualCanonicalizing(['user log #1', 'user log #2']);
});

it('drops re-entrant query-listener logs in batch mode at flush time', function () {
    // Same recursion scenario, but with batch mode and a DB query listener
    // (Eloquent's bulk INSERT bypasses model events, but DB::listen still
    // fires for the underlying query). The guard must protect this path too.
    config(['logscope.write_mode' => 'batch']);

    $queryListenerFireCount = 0;
    DB::listen(function (\Illuminate\Database\Events\QueryExecuted $event) use (&$queryListenerFireCount) {
        // Only count log_entries inserts so other queries (begin/commit/etc.)
        // don't pollute the assertion.
        if (! str_contains($event->sql, 'log_entries')) {
            return;
        }
        $queryListenerFireCount++;
        if ($queryListenerFireCount > 4) {
            return;
        }
        Log::warning('batch-side-effect-'.$queryListenerFireCount);
    });

    Log::error('user log');
    LogScopeServiceProvider::flushLogBufferStatic();

    // The query listener fires for the bulk INSERT. The Log::warning it
    // emits would be re-captured into the buffer without the guard,
    // triggering another flush → another query → another listener call.
    // With the guard, the warning is dropped at the listener level.
    expect(LogEntry::count())->toBe(1)
        ->and(LogEntry::first()->message)->toBe('user log');

    // The listener fires at least once for our INSERT. If the guard were
    // missing, it would fire many more times (each side-effect log creates
    // another buffer add → another flush → another query).
    expect($queryListenerFireCount)->toBeGreaterThan(0)->toBeLessThan(5);
});
