<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use LogScope\Logging\ChannelContextProcessor;
use LogScope\LogScopeServiceProvider;
use LogScope\Models\LogEntry;
use LogScope\Services\LogBuffer;

uses(RefreshDatabase::class);

beforeEach(function () {
    ChannelContextProcessor::clearLastChannel();
    LogScopeServiceProvider::resetBufferState();
    LogEntry::query()->delete();

    config(['logscope.write_mode' => 'batch']);
});

afterEach(function () {
    // DDL operations (Schema::drop) don't roll back inside RefreshDatabase's
    // transaction — re-create the table if a test dropped it so subsequent
    // tests in this file aren't affected.
    if (! Schema::hasTable('log_entries')) {
        $this->artisan('migrate', ['--path' => __DIR__.'/../../database/migrations']);
    }
});

it('flushes the buffer before a later-registered terminate callback that throws', function () {
    Log::error('user log');

    // Register a callback AFTER LogScope's (the service provider's eager
    // registration). This simulates a user provider that registers a
    // throwing terminate callback. Since LogScope is registered earlier
    // in the chain, our flush runs first.
    $this->app->terminating(function () {
        throw new RuntimeException('later callback throws');
    });

    try {
        $this->app->terminate();
    } catch (\Throwable) {
        // Expected — the user's callback threw. Our flush should already
        // have completed by then.
    }

    // The user log should be in the DB despite the later throw.
    expect(LogEntry::where('message', 'user log')->count())->toBe(1)
        ->and(LogBuffer::getBuffer())->toBeEmpty();
});

it('the safe flush wrapper swallows its own exceptions instead of breaking the terminate chain', function () {
    // Inject a single entry into the buffer, then break the DB so flushStatic's
    // INSERT throws. Even so, the terminate() call must not propagate the
    // exception — that would prevent OTHER providers' terminate callbacks
    // from running.
    $bufferProperty = (new ReflectionClass(LogBuffer::class))->getProperty('buffer');
    $bufferProperty->setAccessible(true);
    $bufferProperty->setValue(null, [['message' => 'doomed', 'level' => 'error']]);

    Schema::drop('log_entries');

    // Track that a SECOND callback (registered after LogScope's) actually runs.
    $secondCallbackRan = false;
    $this->app->terminating(function () use (&$secondCallbackRan) {
        $secondCallbackRan = true;
    });

    $this->app->terminate();

    expect($secondCallbackRan)->toBeTrue();
    // Schema is restored by the file-level afterEach.
});

it('registers the Octane RequestTerminated listener when Octane is installed', function () {
    if (! class_exists(\Laravel\Octane\Events\RequestTerminated::class)) {
        $this->markTestSkipped('Laravel Octane is not installed in the test environment.');
    }

    expect($this->app['events']->hasListeners(\Laravel\Octane\Events\RequestTerminated::class))->toBeTrue();
});
