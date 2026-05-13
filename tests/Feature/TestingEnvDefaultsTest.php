<?php

declare(strict_types=1);

// Regression tests for issue #22.
//
// Layer 1: in the testing environment, write_mode should default to 'sync'
// regardless of what env/config says — mirrors Laravel's mail=array,
// queue=sync, cache=array test defaults. Users who specifically want to
// exercise batch behavior in a test can opt back in via Config::set().
//
// Layer 2: even if a user opts back into batch in tests, the noisy
// "Discarded N buffered log entries" warning at shutdown should be silent
// in testing env (the data loss is expected and uninteresting). Production
// stays loud — real data loss must be visible.

use Illuminate\Container\Container;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogScope\Logging\ChannelContextProcessor;
use LogScope\LogScopeServiceProvider;
use LogScope\Models\LogEntry;
use LogScope\Services\LogBuffer;
use LogScope\Services\WriteFailureLogger;

uses(RefreshDatabase::class);

beforeEach(function () {
    ChannelContextProcessor::clearLastChannel();
    LogScopeServiceProvider::resetBufferState();
    WriteFailureLogger::reset();
    LogEntry::query()->delete();

    $this->errorLogFile = tempnam(sys_get_temp_dir(), 'logscope-test-error-log-');
    $this->originalErrorLog = ini_get('error_log');
    ini_set('error_log', $this->errorLogFile);
});

afterEach(function () {
    ini_set('error_log', $this->originalErrorLog);
    if (file_exists($this->errorLogFile)) {
        @unlink($this->errorLogFile);
    }
});

it('forces write_mode to sync at boot time in the testing environment', function () {
    // Models the user scenario in issue #22: project-wide
    // LOGSCOPE_WRITE_MODE=batch (or just the package default of 'batch')
    // gets merged in during register(), but boot() should override to
    // 'sync' because we're in the testing env. By the time a test
    // observes config(), the override has already been applied.
    expect(app()->environment('testing'))->toBeTrue()
        ->and(config('logscope.write_mode'))->toBe('sync');
});

it('respects an explicit opt-in to batch mode inside a test', function () {
    // Layer 1 must not clobber a user's deliberate setUp() override.
    // Pattern: provider boot has already run with sync forced; then the
    // user sets batch in their own setUp().
    config(['logscope.write_mode' => 'batch']);

    expect(config('logscope.write_mode'))->toBe('batch');
});

it('does not emit the discard warning to error_log when the testing-env buffer is dropped at shutdown', function () {
    // Simulate the Layer-2 scenario: user has explicitly opted into batch
    // for a test, an entry is buffered via add() (which caches the
    // testing-env flag while the container is alive), then the container
    // is gone by the time flushStatic runs.
    config(['logscope.write_mode' => 'batch']);

    /** @var LogBuffer $buffer */
    $buffer = app(LogBuffer::class);
    $buffer->add(['message' => 'buffered in test', 'level' => 'error']);

    $originalApp = Container::getInstance();
    $stubApp = new Container;
    Container::setInstance($stubApp);

    try {
        LogBuffer::flushStatic();
    } finally {
        Container::setInstance($originalApp);
    }

    $errorLogContents = (string) file_get_contents($this->errorLogFile);
    expect($errorLogContents)->not->toContain('Discarded')
        ->and($errorLogContents)->not->toContain('container has no db binding');
});
