<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use LogScope\Models\LogEntry;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Run LogScope migrations
    $this->artisan('migrate', ['--path' => __DIR__.'/../../database/migrations']);
});

it('creates a log entry with auto-generated fields', function () {
    $entry = LogEntry::createEntry([
        'level' => 'error',
        'message' => 'Test error message',
        'context' => ['user_id' => 123],
        'channel' => 'test',
    ]);

    expect($entry)
        ->id->not->toBeNull()
        ->level->toBe('error')
        ->message->toBe('Test error message')
        ->message_preview->toBe('Test error message')
        ->occurred_at->not->toBeNull()
        ->status->value->toBe('open');
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

it('filters by trace_id', function () {
    $traceId = \Illuminate\Support\Str::uuid()->toString();

    LogEntry::createEntry(['level' => 'info', 'message' => 'Test 1', 'trace_id' => $traceId]);
    LogEntry::createEntry(['level' => 'info', 'message' => 'Test 2', 'trace_id' => $traceId]);
    LogEntry::createEntry(['level' => 'info', 'message' => 'Test 3', 'trace_id' => \Illuminate\Support\Str::uuid()->toString()]);

    expect(LogEntry::traceId($traceId)->count())->toBe(2);
});

it('filters by user_id', function () {
    LogEntry::createEntry(['level' => 'info', 'message' => 'Test 1', 'user_id' => 1]);
    LogEntry::createEntry(['level' => 'info', 'message' => 'Test 2', 'user_id' => 1]);
    LogEntry::createEntry(['level' => 'info', 'message' => 'Test 3', 'user_id' => 2]);

    expect(LogEntry::userId(1)->count())->toBe(2);
});

it('filters by ip_address', function () {
    LogEntry::createEntry(['level' => 'info', 'message' => 'Test 1', 'ip_address' => '127.0.0.1']);
    LogEntry::createEntry(['level' => 'info', 'message' => 'Test 2', 'ip_address' => '127.0.0.1']);
    LogEntry::createEntry(['level' => 'info', 'message' => 'Test 3', 'ip_address' => '192.168.1.1']);

    expect(LogEntry::ipAddress('127.0.0.1')->count())->toBe(2);
});

it('filters by http_method', function () {
    LogEntry::createEntry(['level' => 'info', 'message' => 'Test 1', 'http_method' => 'GET']);
    LogEntry::createEntry(['level' => 'info', 'message' => 'Test 2', 'http_method' => 'POST']);
    LogEntry::createEntry(['level' => 'info', 'message' => 'Test 3', 'http_method' => 'GET']);

    expect(LogEntry::httpMethod('GET')->count())->toBe(2);
    expect(LogEntry::httpMethod(['GET', 'POST'])->count())->toBe(3);
});

it('filters by url', function () {
    LogEntry::createEntry(['level' => 'info', 'message' => 'Test 1', 'url' => '/api/users']);
    LogEntry::createEntry(['level' => 'info', 'message' => 'Test 2', 'url' => '/api/users/1']);
    LogEntry::createEntry(['level' => 'info', 'message' => 'Test 3', 'url' => '/api/posts']);

    expect(LogEntry::url('/api/users')->count())->toBe(2);
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

it('filters by status', function () {
    LogEntry::createEntry(['level' => 'info', 'message' => 'Test 1', 'status' => 'open']);
    LogEntry::createEntry(['level' => 'info', 'message' => 'Test 2', 'status' => 'investigating']);
    LogEntry::createEntry(['level' => 'info', 'message' => 'Test 3', 'status' => 'resolved']);
    LogEntry::createEntry(['level' => 'info', 'message' => 'Test 4', 'status' => 'ignored']);

    expect(LogEntry::status('open')->count())->toBe(1);
    expect(LogEntry::status(['open', 'investigating'])->count())->toBe(2);
    expect(LogEntry::query()->needsAttention()->count())->toBe(2);
    expect(LogEntry::query()->closed()->count())->toBe(2);
});

it('sets status on a log entry', function () {
    $entry = LogEntry::createEntry([
        'level' => 'error',
        'message' => 'Test error',
    ]);

    expect($entry->status->value)->toBe('open');

    $entry->setStatus('investigating', 'Test User', 'Looking into it');

    expect($entry->fresh())
        ->status->value->toBe('investigating')
        ->status_changed_by->toBe('Test User')
        ->note->toBe('Looking into it');
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
