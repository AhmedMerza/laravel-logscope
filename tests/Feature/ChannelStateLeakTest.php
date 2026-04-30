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
