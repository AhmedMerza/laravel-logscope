<?php

declare(strict_types=1);

// Capture-all mode reads every MessageLogged event, so the LOG_LEVEL the app's
// channels filter on never reached it and Log::debug() filled the table.
// ignore.min_level is the opt-in floor; unset, every level is still captured.

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use LogScope\Logging\ChannelContextProcessor;
use LogScope\LogScopeServiceProvider;
use LogScope\Models\LogEntry;
use LogScope\Services\WriteFailureLogger;

uses(RefreshDatabase::class);

beforeEach(function () {
    ChannelContextProcessor::clearLastChannel();
    LogScopeServiceProvider::resetBufferState();
    WriteFailureLogger::reset();
    LogEntry::query()->delete();

    config(['logscope.write_mode' => 'sync']);

    $this->errorLogFile = tempnam(sys_get_temp_dir(), 'logscope-min-level-');
    $this->originalErrorLog = ini_get('error_log');
    ini_set('error_log', $this->errorLogFile);
});

afterEach(function () {
    ini_set('error_log', $this->originalErrorLog);
    @unlink($this->errorLogFile);
});

function logEveryLevel(): void
{
    foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'] as $level) {
        Log::log($level, "{$level} message");
    }
}

it('captures every level when min_level is not set', function () {
    logEveryLevel();

    expect(LogEntry::count())->toBe(8);
});

it('skips logs below min_level and keeps the rest', function () {
    config(['logscope.ignore.min_level' => 'warning']);

    logEveryLevel();

    expect(LogEntry::query()->orderBy('message')->pluck('level')->sort()->values()->all())
        ->toBe(['alert', 'critical', 'emergency', 'error', 'warning']);
});

it('matches the level name case-insensitively', function () {
    config(['logscope.ignore.min_level' => 'INFO']);

    logEveryLevel();

    expect(LogEntry::where('level', 'debug')->exists())->toBeFalse()
        ->and(LogEntry::count())->toBe(7);
});

it('captures everything and reports it when min_level is not a level name', function () {
    // A typo must not turn into "drop every log": shouldIgnoreLog() treats a
    // throw as "skip", so an unchecked lookup would have done exactly that.
    config(['logscope.ignore.min_level' => 'warn']);

    logEveryLevel();

    expect(LogEntry::count())->toBe(8)
        ->and(file_get_contents($this->errorLogFile))->toContain('LogScope[min-level]');
});
