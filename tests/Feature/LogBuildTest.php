<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use LogScope\Logging\ChannelContextProcessor;
use LogScope\LogScopeServiceProvider;
use LogScope\Models\LogEntry;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Run LogScope migrations
    $this->artisan('migrate', ['--path' => __DIR__.'/../../database/migrations']);

    // Clear any previous state
    ChannelContextProcessor::clearLastChannel();
    LogScopeServiceProvider::resetBufferState();
    LogEntry::query()->delete();

    // Use sync queue for testing queue mode
    Queue::fake();
});

/**
 * Helper to flush logs based on write mode
 */
function flushLogs(): void
{
    LogScopeServiceProvider::flushLogBufferStatic();
}

// =============================================================================
// SYNC MODE TESTS
// =============================================================================

it('captures Log::build() logs with null channel in sync mode', function () {
    config(['logscope.write_mode' => 'sync']);

    $logger = Log::build([
        'driver' => 'single',
        'path' => storage_path('logs/test-build.log'),
    ]);
    $logger->info('Test log from Log::build()');

    $entry = LogEntry::first();

    expect($entry)->not->toBeNull();
    expect($entry->channel)->toBeNull();
});

it('does not inherit channel from previous log in sync mode', function () {
    config(['logscope.write_mode' => 'sync']);

    Log::channel('single')->info('First log');

    $logger = Log::build([
        'driver' => 'single',
        'path' => storage_path('logs/test-build.log'),
    ]);
    $logger->info('Second log');

    $entries = LogEntry::orderBy('id')->get();

    expect($entries)->toHaveCount(2);
    expect($entries[0]->channel)->toBe('single');
    expect($entries[1]->channel)->toBeNull();
});

// =============================================================================
// BATCH MODE TESTS
// =============================================================================

it('captures Log::build() logs with null channel in batch mode', function () {
    config(['logscope.write_mode' => 'batch']);

    $logger = Log::build([
        'driver' => 'single',
        'path' => storage_path('logs/test-build.log'),
    ]);
    $logger->info('Test log from Log::build()');

    // Flush the buffer
    flushLogs();

    $entry = LogEntry::first();

    expect($entry)->not->toBeNull();
    expect($entry->channel)->toBeNull();
});

it('does not inherit channel from previous log in batch mode', function () {
    config(['logscope.write_mode' => 'batch']);

    Log::channel('single')->info('First log');

    $logger = Log::build([
        'driver' => 'single',
        'path' => storage_path('logs/test-build.log'),
    ]);
    $logger->info('Second log');

    // Flush the buffer
    flushLogs();

    $entries = LogEntry::orderBy('id')->get();

    expect($entries)->toHaveCount(2);
    expect($entries[0]->channel)->toBe('single');
    expect($entries[1]->channel)->toBeNull();
});

it('correctly captures channel after Log::build() log in batch mode', function () {
    config(['logscope.write_mode' => 'batch']);

    // First: Log::build()
    $logger = Log::build([
        'driver' => 'single',
        'path' => storage_path('logs/test-build.log'),
    ]);
    $logger->info('Log from build');

    // Second: normal channel log
    Log::channel('single')->info('Log from single channel');

    // Flush
    flushLogs();

    $entries = LogEntry::orderBy('id')->get();

    expect($entries)->toHaveCount(2);
    expect($entries[0]->channel)->toBeNull();
    expect($entries[1]->channel)->toBe('single');
});

// =============================================================================
// QUEUE MODE TESTS
// =============================================================================

it('dispatches Log::build() logs with null channel in queue mode', function () {
    config(['logscope.write_mode' => 'queue']);

    $logger = Log::build([
        'driver' => 'single',
        'path' => storage_path('logs/test-build.log'),
    ]);
    $logger->info('Test log from Log::build()');

    // In queue mode, jobs are dispatched - verify the job was dispatched
    Queue::assertPushed(\LogScope\Jobs\WriteLogEntry::class, function ($job) {
        // Access the job's data property to check channel is null
        $reflection = new ReflectionClass($job);
        $property = $reflection->getProperty('data');
        $property->setAccessible(true);
        $data = $property->getValue($job);

        return $data['channel'] === null;
    });
});

it('does not inherit channel in queue mode', function () {
    config(['logscope.write_mode' => 'queue']);

    Log::channel('single')->info('First log');

    $logger = Log::build([
        'driver' => 'single',
        'path' => storage_path('logs/test-build.log'),
    ]);
    $logger->info('Second log');

    $dispatched = [];
    Queue::assertPushed(\LogScope\Jobs\WriteLogEntry::class, function ($job) use (&$dispatched) {
        $reflection = new ReflectionClass($job);
        $property = $reflection->getProperty('data');
        $property->setAccessible(true);
        $dispatched[] = $property->getValue($job);

        return true;
    });

    // Should have 2 jobs dispatched
    expect($dispatched)->toHaveCount(2);
    expect($dispatched[0]['channel'])->toBe('single');
    expect($dispatched[1]['channel'])->toBeNull();
});

// =============================================================================
// EDGE CASES
// =============================================================================

it('captures Log::build() as first log without filtering', function () {
    config(['logscope.write_mode' => 'sync']);

    // Log::build() as the very first log (no previous channel)
    $logger = Log::build([
        'driver' => 'single',
        'path' => storage_path('logs/test-build.log'),
    ]);
    $logger->warning('First log ever');

    $entry = LogEntry::first();

    expect($entry)->not->toBeNull();
    expect($entry->level)->toBe('warning');
    expect($entry->channel)->toBeNull();
});
