<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
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

it('does not attribute a stale channel to a fresh log fired without a channel processor', function () {
    // Simulate the leak: lastChannel was left set by a previous log whose
    // listener-handling returned early (didHandleCurrentLog / isInternalLog
    // / WriteGuard early-return). Now a NEW log fires through Log::build()
    // (no channel processor for it). Without the fix, the listener reads
    // the stale lastChannel and attributes it to the wrong log.
    $channelProp = (new ReflectionClass(ChannelContextProcessor::class))->getProperty('lastChannel');
    $channelProp->setAccessible(true);
    $channelProp->setValue(null, 'single');

    // Dispatch a MessageLogged directly — bypassing Laravel's logger lets
    // us simulate a runtime channel that has no processor installed.
    event(new \Illuminate\Log\Events\MessageLogged('error', 'fresh-log', []));

    $entry = LogEntry::where('message', 'fresh-log')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->channel)->toBeNull();
});

it('consumeLastChannel returns the channel on first call and null on the second', function () {
    // Locks in the read+clear contract: a single processor invocation
    // produces exactly one consumable channel value. A second consume
    // (or any consume after a non-processor log) returns null.
    $processor = new ChannelContextProcessor('single');
    $processor(new \Monolog\LogRecord(
        new \DateTimeImmutable,
        'app',
        \Monolog\Level::Error,
        'msg',
        []
    ));

    expect(ChannelContextProcessor::consumeLastChannel())->toBe('single')
        ->and(ChannelContextProcessor::consumeLastChannel())->toBeNull();
});

it('getLastChannel returns the raw value regardless of freshness (deprecated path)', function () {
    // Backwards-compat contract: getLastChannel keeps its original
    // semantics — return whatever's in the static slot, even if stale.
    // This is what existing unit tests rely on; the listener uses
    // consumeLastChannel instead.
    $channelProp = (new ReflectionClass(ChannelContextProcessor::class))->getProperty('lastChannel');
    $channelProp->setAccessible(true);
    $channelProp->setValue(null, 'stale-value');

    // isFresh is false (we set lastChannel directly without a processor invocation).
    expect(ChannelContextProcessor::getLastChannel())->toBe('stale-value');

    // After a consume (which sets isFresh=false and clears lastChannel),
    // the raw getter returns null.
    ChannelContextProcessor::consumeLastChannel();
    expect(ChannelContextProcessor::getLastChannel())->toBeNull();
});

it('registers an Octane RequestReceived listener when Octane is installed', function () {
    // Skip if Octane isn't installed — the listener registration is
    // gated on the class existing.
    if (! class_exists(\Laravel\Octane\Events\RequestReceived::class)) {
        $this->markTestSkipped('Laravel Octane is not installed in the test environment.');
    }

    expect($this->app['events']->hasListeners(\Laravel\Octane\Events\RequestReceived::class))->toBeTrue();
});
