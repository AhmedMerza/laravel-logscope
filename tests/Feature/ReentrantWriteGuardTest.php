<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use LogScope\Logging\ChannelContextProcessor;
use LogScope\LogScopeServiceProvider;
use LogScope\Models\LogEntry;

uses(RefreshDatabase::class);

beforeEach(function () {
    ChannelContextProcessor::clearLastChannel();
    LogScopeServiceProvider::resetBufferState();
    LogEntry::query()->delete();

    config(['logscope.write_mode' => 'sync']);
});

afterEach(function () {
    // Drop any observers we wired up for the test.
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
