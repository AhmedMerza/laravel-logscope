<?php

declare(strict_types=1);

// Tests in this file deliberately exercise LogScope's write-failure path,
// which calls error_log(). Each test redirects PHP's error_log destination
// to a per-test tempfile via ini_set so the failure messages don't leak
// into phpunit/pest's stderr or to the real php-fpm error log.

use Illuminate\Container\Container;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use LogScope\Logging\ChannelContextProcessor;
use LogScope\LogScopeServiceProvider;
use LogScope\Models\LogEntry;
use LogScope\Services\LogBuffer;
use LogScope\Services\WriteFailureLogger;

uses(RefreshDatabase::class);

beforeEach(function () {
    // RefreshDatabase already runs migrations; loadMigrationsFrom() in
    // LogScopeServiceProvider::boot() registers package migrations with
    // the migrator, so RefreshDatabase picks them up. No explicit migrate
    // call needed.
    ChannelContextProcessor::clearLastChannel();
    LogScopeServiceProvider::resetBufferState();
    WriteFailureLogger::reset();
    LogEntry::query()->delete();

    config(['logscope.write_mode' => 'sync']);

    // Redirect error_log() to a tmp file so we can assert on its output
    // without polluting the test runner's stderr.
    $this->errorLogFile = tempnam(sys_get_temp_dir(), 'logscope-test-error-log-');
    $this->originalErrorLog = ini_get('error_log');
    ini_set('error_log', $this->errorLogFile);
});

afterEach(function () {
    ini_set('error_log', $this->originalErrorLog);
    if (file_exists($this->errorLogFile)) {
        @unlink($this->errorLogFile);
    }

    // Re-create the log_entries table if a test dropped it. Schema DDL
    // isn't rolled back by RefreshDatabase's transaction wrapping, so a
    // dropped table would leak to subsequent tests in the same class.
    if (! Schema::hasTable('log_entries')) {
        $this->artisan('migrate', ['--path' => __DIR__.'/../../database/migrations']);
    }
});

it('emits error_log when sync write fails — even with app.debug=false', function () {
    config(['app.debug' => false]);

    // Force a real write failure at the DB layer by dropping the table.
    // This exercises the catch in LogCapture::handleLogEvent.
    Schema::drop('log_entries');

    Log::error('boom');

    $errorLogContents = file_get_contents($this->errorLogFile);
    expect($errorLogContents)->toContain('LogScope[listener]: Failed to write log entry');
});

it('dedupes identical write failures so a sustained outage does not flood error_log', function () {
    Schema::drop('log_entries');

    // Fire the same failure many times. Only the first should emit;
    // the rest are suppressed until the summary threshold (every 100th).
    for ($i = 0; $i < 25; $i++) {
        Log::error('repeated failure');
    }

    $contents = file_get_contents($this->errorLogFile);
    $occurrences = substr_count($contents, 'LogScope[listener]: Failed to write log entry');

    expect($occurrences)->toBe(1);
});

it('emits a summary line every 100 occurrences so persistent outages stay visible', function () {
    Schema::drop('log_entries');

    // 1 first-emit + 1 summary at the 100th occurrence = 2 total lines.
    for ($i = 0; $i < 100; $i++) {
        Log::error('repeated failure');
    }

    $contents = file_get_contents($this->errorLogFile);
    $firstEmit = substr_count($contents, 'Failed to write log entry');
    $summary = substr_count($contents, 'same failure has now occurred 100 times');

    expect($firstEmit)->toBe(1)
        ->and($summary)->toBe(1);
});

it('emits error_log when buffer is discarded due to missing container', function () {
    // Inject an entry directly into the static buffer (bypassing add(),
    // which would register terminate/shutdown callbacks).
    $bufferProperty = (new ReflectionClass(LogBuffer::class))->getProperty('buffer');
    $bufferProperty->setAccessible(true);
    $bufferProperty->setValue(null, [['message' => 'queued before teardown', 'level' => 'error']]);

    // Swap in a stub container with no `db` binding so flushStatic bails
    // on the missing-container path. Use try/finally so the original
    // container is always restored — otherwise a test failure here would
    // leave the framework in a broken state for every subsequent test.
    $originalApp = Container::getInstance();
    $stubApp = new Container;
    Container::setInstance($stubApp);

    try {
        LogBuffer::flushStatic();
    } finally {
        Container::setInstance($originalApp);
    }

    $errorLogContents = file_get_contents($this->errorLogFile);
    expect($errorLogContents)->toContain('LogScope: Discarded 1 buffered log entry')
        ->and($errorLogContents)->toContain('container has no db binding');
});
