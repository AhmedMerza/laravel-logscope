<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use LogScope\Logging\ChannelContextProcessor;
use LogScope\LogScopeServiceProvider;
use LogScope\Models\LogEntry;
use LogScope\Services\LogBuffer;

uses(RefreshDatabase::class);

beforeEach(function () {
    // RefreshDatabase already runs migrations; loadMigrationsFrom() in
    // LogScopeServiceProvider::boot() registers package migrations with
    // the migrator, so RefreshDatabase picks them up. No explicit migrate
    // call needed.
    ChannelContextProcessor::clearLastChannel();
    LogScopeServiceProvider::resetBufferState();
    LogEntry::query()->delete();

    config(['logscope.write_mode' => 'sync']);

    // Redirect error_log() to a tmp file so we can assert on its output.
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

it('emits error_log when sync write fails — even with app.debug=false', function () {
    config(['app.debug' => false]);

    // Force a write failure by dropping the table after migrations ran.
    \Illuminate\Support\Facades\Schema::drop('log_entries');

    Log::error('boom');

    $errorLogContents = file_get_contents($this->errorLogFile);
    expect($errorLogContents)->toContain('LogScope: Failed to write log entry');
});

it('emits error_log when buffer is discarded due to missing container', function () {
    // Put an entry in the buffer, then simulate the container being gone.
    LogBuffer::reset();

    // Reflect into the static buffer to inject an entry without going
    // through add() (which registers the terminate/shutdown callbacks).
    $bufferProperty = (new ReflectionClass(LogBuffer::class))->getProperty('buffer');
    $bufferProperty->setAccessible(true);
    $bufferProperty->setValue(null, [['message' => 'queued before teardown', 'level' => 'error']]);

    // Replace the global app() container with a stub that has no `db` binding.
    $originalApp = \Illuminate\Container\Container::getInstance();
    $stubApp = new \Illuminate\Container\Container;
    \Illuminate\Container\Container::setInstance($stubApp);

    try {
        LogBuffer::flushStatic();
    } finally {
        \Illuminate\Container\Container::setInstance($originalApp);
    }

    $errorLogContents = file_get_contents($this->errorLogFile);
    expect($errorLogContents)->toContain('LogScope: Discarded')
        ->and($errorLogContents)->toContain('1');
});
