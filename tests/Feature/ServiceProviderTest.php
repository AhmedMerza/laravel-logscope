<?php

use LogScope\LogScopeServiceProvider;

it('registers the service provider', function () {
    expect(app()->getProviders(LogScopeServiceProvider::class))
        ->not->toBeEmpty();
});

it('loads the configuration', function () {
    expect(config('logscope'))
        ->toBeArray()
        ->toHaveKeys(['table', 'retention', 'routes', 'migrations', 'limits', 'search', 'pagination']);
});

it('can reset buffer state for testing', function () {
    // This tests the resetBufferState method used for test isolation
    LogScopeServiceProvider::resetBufferState();

    // Should not throw any errors
    expect(true)->toBeTrue();
});

it('can flush buffer multiple times safely', function () {
    // Reset state
    LogScopeServiceProvider::resetBufferState();

    // First flush (empty buffer - no-op)
    LogScopeServiceProvider::flushLogBufferStatic();

    // Second flush should also be safe (empty buffer)
    LogScopeServiceProvider::flushLogBufferStatic();

    // Should not throw any errors
    expect(true)->toBeTrue();
});
