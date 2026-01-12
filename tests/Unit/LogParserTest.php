<?php

use Carbon\Carbon;
use LogScope\Services\LogParser;

beforeEach(function () {
    $this->parser = new LogParser();
    $this->testLogPath = sys_get_temp_dir() . '/test_laravel.log';
});

afterEach(function () {
    if (file_exists($this->testLogPath)) {
        unlink($this->testLogPath);
    }
});

it('parses a simple log entry', function () {
    $logContent = '[2024-01-15 10:30:45] local.ERROR: Test error message {"user_id":123}';
    file_put_contents($this->testLogPath, $logContent);

    $entries = iterator_to_array($this->parser->parseFile($this->testLogPath));

    expect($entries)->toHaveCount(1);
    expect($entries[0])
        ->level->toBe('error')
        ->message->toBe('Test error message')
        ->environment->toBe('local')
        ->context->toBe(['user_id' => 123]);
});

it('parses multiple log entries', function () {
    $logContent = <<<LOG
[2024-01-15 10:30:45] local.INFO: First message
[2024-01-15 10:30:46] local.WARNING: Second message
[2024-01-15 10:30:47] local.ERROR: Third message
LOG;
    file_put_contents($this->testLogPath, $logContent);

    $entries = iterator_to_array($this->parser->parseFile($this->testLogPath));

    expect($entries)->toHaveCount(3);
    expect($entries[0]['level'])->toBe('info');
    expect($entries[1]['level'])->toBe('warning');
    expect($entries[2]['level'])->toBe('error');
});

it('handles multi-line log entries with stack traces', function () {
    $logContent = <<<LOG
[2024-01-15 10:30:45] local.ERROR: Exception occurred {"exception":"[object] (Exception(code: 0): Something went wrong at /app/Http/Controllers/TestController.php:42)
[stacktrace]
#0 /vendor/laravel/framework/src/Illuminate/Routing/Controller.php(54): App\\Http\\Controllers\\TestController->index()
#1 /vendor/laravel/framework/src/Illuminate/Routing/ControllerDispatcher.php(43): Illuminate\\Routing\\Controller->callAction()"}
[2024-01-15 10:30:46] local.INFO: Next log entry
LOG;
    file_put_contents($this->testLogPath, $logContent);

    $entries = iterator_to_array($this->parser->parseFile($this->testLogPath));

    expect($entries)->toHaveCount(2);
    expect($entries[0]['level'])->toBe('error');
    expect($entries[0]['message'])->toContain('Exception occurred');
    expect($entries[0]['message'])->toContain('#0');
    expect($entries[1]['level'])->toBe('info');
});

it('extracts source file and line from message', function () {
    $logContent = '[2024-01-15 10:30:45] local.ERROR: Error in /app/Http/Controllers/UserController.php:123';
    file_put_contents($this->testLogPath, $logContent);

    $entries = iterator_to_array($this->parser->parseFile($this->testLogPath));

    expect($entries[0]['source'])->toBe('/app/Http/Controllers/UserController.php');
    expect($entries[0]['source_line'])->toBe(123);
});

it('filters entries by date', function () {
    $logContent = <<<LOG
[2024-01-10 10:00:00] local.INFO: Old message
[2024-01-15 10:00:00] local.INFO: Recent message
[2024-01-16 10:00:00] local.INFO: Newer message
LOG;
    file_put_contents($this->testLogPath, $logContent);

    $since = Carbon::parse('2024-01-14');
    $entries = iterator_to_array($this->parser->parseFile($this->testLogPath, $since));

    expect($entries)->toHaveCount(2);
    expect($entries[0]['message'])->toBe('Recent message');
});

it('handles empty log file', function () {
    file_put_contents($this->testLogPath, '');

    $entries = iterator_to_array($this->parser->parseFile($this->testLogPath));

    expect($entries)->toHaveCount(0);
});

it('handles log entries without context', function () {
    $logContent = '[2024-01-15 10:30:45] local.DEBUG: Simple message without context';
    file_put_contents($this->testLogPath, $logContent);

    $entries = iterator_to_array($this->parser->parseFile($this->testLogPath));

    expect($entries[0]['context'])->toBe([]);
});

it('parses different log levels', function () {
    $logContent = <<<LOG
[2024-01-15 10:30:45] local.DEBUG: Debug
[2024-01-15 10:30:45] local.INFO: Info
[2024-01-15 10:30:45] local.NOTICE: Notice
[2024-01-15 10:30:45] local.WARNING: Warning
[2024-01-15 10:30:45] local.ERROR: Error
[2024-01-15 10:30:45] local.CRITICAL: Critical
[2024-01-15 10:30:45] local.ALERT: Alert
[2024-01-15 10:30:45] local.EMERGENCY: Emergency
LOG;
    file_put_contents($this->testLogPath, $logContent);

    $entries = iterator_to_array($this->parser->parseFile($this->testLogPath));

    expect($entries)->toHaveCount(8);
    expect(array_column($entries, 'level'))->toBe([
        'debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency',
    ]);
});

it('counts entries without loading all into memory', function () {
    $logContent = <<<LOG
[2024-01-15 10:30:45] local.INFO: Message 1
[2024-01-15 10:30:46] local.INFO: Message 2
[2024-01-15 10:30:47] local.INFO: Message 3
LOG;
    file_put_contents($this->testLogPath, $logContent);

    $count = $this->parser->countEntries($this->testLogPath);

    expect($count)->toBe(3);
});

it('throws exception for non-existent file', function () {
    // Need to iterate the generator to trigger the exception
    iterator_to_array($this->parser->parseFile('/non/existent/file.log'));
})->throws(RuntimeException::class, 'Log file not found');
