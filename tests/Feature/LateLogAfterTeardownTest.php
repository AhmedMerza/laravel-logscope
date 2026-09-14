<?php

declare(strict_types=1);

use LogScope\Services\WriteFailureLogger;

// Regression for #36: a logger resolved before container teardown still holds
// the event dispatcher, so logging through it afterwards (e.g. from a
// register_shutdown_function) dispatches MessageLogged into LogScope's
// listener with no container behind it. The listener must not throw
// "Target class [config] does not exist" and exit the process 255, and the
// skipped log must still surface in error_log rather than vanish.

beforeEach(function () {
    WriteFailureLogger::reset();

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

    WriteFailureLogger::reset();
});

it('skips and reports late logs when a held logger logs after the container is flushed', function () {
    $logger = app('log')->channel();

    app()->flush();

    // Called bare, not via expect()->not->toThrow(): that form let the
    // exception through without failing. Any throw here fails the test.
    try {
        $logger->info('late held-logger log');
        $logger->warning('second late log');
    } finally {
        // Restore the application so Pest's teardown can run
        $this->refreshApplication();
    }

    // Reported once, not once per late log.
    $contents = file_get_contents($this->errorLogFile);
    expect(substr_count($contents, 'LogScope[ignore-check]: Failed to write log entry'))->toBe(1)
        ->and($contents)->toContain('Target class [config] does not exist');
});
