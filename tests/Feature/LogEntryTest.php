<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use LogScope\Models\LogEntry;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Run LogScope migrations
    $this->artisan('migrate', ['--path' => __DIR__ . '/../../database/migrations']);
});

it('creates a log entry with auto-generated fields', function () {
    $entry = LogEntry::createEntry([
        'level' => 'error',
        'message' => 'Test error message',
        'context' => ['user_id' => 123],
        'channel' => 'test',
        'environment' => 'testing',
    ]);

    expect($entry)
        ->id->not->toBeNull()
        ->level->toBe('error')
        ->message->toBe('Test error message')
        ->message_preview->toBe('Test error message')
        ->fingerprint->not->toBeNull()
        ->occurred_at->not->toBeNull();
});

it('generates preview for long messages', function () {
    $longMessage = str_repeat('a', 1000);

    $entry = LogEntry::createEntry([
        'level' => 'info',
        'message' => $longMessage,
    ]);

    expect($entry->message_preview)->toHaveLength(500);
    expect($entry->message_preview)->toEndWith('...');
});

it('generates consistent fingerprints for similar messages', function () {
    $fp1 = LogEntry::generateFingerprint('User 123 logged in', 'info', '/app/Http/Controllers/AuthController.php');
    $fp2 = LogEntry::generateFingerprint('User 456 logged in', 'info', '/app/Http/Controllers/AuthController.php');
    $fp3 = LogEntry::generateFingerprint('User 789 logged out', 'info', '/app/Http/Controllers/AuthController.php');

    // Similar messages with different IDs should have same fingerprint
    expect($fp1)->toBe($fp2);
    // Different messages should have different fingerprints
    expect($fp1)->not->toBe($fp3);
});

it('filters by level', function () {
    LogEntry::createEntry(['level' => 'error', 'message' => 'Error 1']);
    LogEntry::createEntry(['level' => 'warning', 'message' => 'Warning 1']);
    LogEntry::createEntry(['level' => 'error', 'message' => 'Error 2']);

    expect(LogEntry::level('error')->count())->toBe(2);
    expect(LogEntry::level(['error', 'warning'])->count())->toBe(3);
});

it('excludes levels', function () {
    LogEntry::createEntry(['level' => 'debug', 'message' => 'Debug 1']);
    LogEntry::createEntry(['level' => 'info', 'message' => 'Info 1']);
    LogEntry::createEntry(['level' => 'error', 'message' => 'Error 1']);

    expect(LogEntry::excludeLevel('debug')->count())->toBe(2);
    expect(LogEntry::excludeLevel(['debug', 'info'])->count())->toBe(1);
});

it('filters by channel', function () {
    LogEntry::createEntry(['level' => 'info', 'message' => 'Test', 'channel' => 'slack']);
    LogEntry::createEntry(['level' => 'info', 'message' => 'Test', 'channel' => 'mail']);
    LogEntry::createEntry(['level' => 'info', 'message' => 'Test', 'channel' => 'slack']);

    expect(LogEntry::channel('slack')->count())->toBe(2);
});

it('filters by environment', function () {
    LogEntry::createEntry(['level' => 'info', 'message' => 'Test', 'environment' => 'production']);
    LogEntry::createEntry(['level' => 'info', 'message' => 'Test', 'environment' => 'staging']);
    LogEntry::createEntry(['level' => 'info', 'message' => 'Test', 'environment' => 'production']);

    expect(LogEntry::environment('production')->count())->toBe(2);
});

it('filters by date range', function () {
    LogEntry::createEntry(['level' => 'info', 'message' => 'Old', 'occurred_at' => now()->subDays(10)]);
    LogEntry::createEntry(['level' => 'info', 'message' => 'Recent', 'occurred_at' => now()->subDays(2)]);
    LogEntry::createEntry(['level' => 'info', 'message' => 'Today', 'occurred_at' => now()]);

    expect(LogEntry::dateRange(now()->subDays(5))->count())->toBe(2);
    expect(LogEntry::dateRange(null, now()->subDays(5))->count())->toBe(1);
    expect(LogEntry::dateRange(now()->subDays(5), now()->subDay())->count())->toBe(1);
});

it('searches in message', function () {
    LogEntry::createEntry(['level' => 'info', 'message' => 'User login successful']);
    LogEntry::createEntry(['level' => 'error', 'message' => 'Database connection failed']);
    LogEntry::createEntry(['level' => 'info', 'message' => 'User logout']);

    expect(LogEntry::search('User')->count())->toBe(2);
    expect(LogEntry::search('failed')->count())->toBe(1);
});

it('orders by recent', function () {
    $old = LogEntry::createEntry(['level' => 'info', 'message' => 'Old', 'occurred_at' => now()->subHour()]);
    $new = LogEntry::createEntry(['level' => 'info', 'message' => 'New', 'occurred_at' => now()]);

    $first = LogEntry::recent()->first();

    expect($first->id)->toBe($new->id);
});

it('marks truncated entries', function () {
    config(['logscope.limits.truncate_at' => 100]);

    $entry = LogEntry::createEntry([
        'level' => 'info',
        'message' => str_repeat('x', 200),
    ]);

    expect($entry->is_truncated)->toBeTrue();
    expect(strlen($entry->message))->toBe(100);
});
