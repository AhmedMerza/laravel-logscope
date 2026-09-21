<?php

declare(strict_types=1);

// The Clear endpoint deletes in bounded chunks rather than one unbounded
// statement (#46), so it must still delete exactly the rows its filters
// match — no more, no fewer — including across a chunk boundary.

use Illuminate\Foundation\Testing\RefreshDatabase;
use LogScope\LogScope;
use LogScope\Models\LogEntry;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->artisan('migrate', ['--path' => __DIR__.'/../../database/migrations']);
    LogScope::auth(fn () => true);
    LogEntry::query()->delete();
});

afterEach(function () {
    LogScope::resetAuth();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function seedEntry(array $attributes = []): LogEntry
{
    return LogEntry::createEntry(array_merge([
        'level' => 'info',
        'message' => 'entry',
        'channel' => 'single',
    ], $attributes));
}

function remainingMessages(): array
{
    return LogEntry::query()->orderBy('message')->pluck('message')->all();
}

// =============================================================================
// FILTERS STILL DELETE EXACTLY THE MATCHING ROWS
// =============================================================================

it('clears only the selected levels', function () {
    seedEntry(['level' => 'info', 'message' => 'keep-info']);
    seedEntry(['level' => 'warning', 'message' => 'drop-warning']);
    seedEntry(['level' => 'error', 'message' => 'drop-error']);

    $response = $this->postJson('/logscope/api/logs/clear', [
        'levels' => ['warning', 'error'],
    ]);

    $response->assertOk();
    expect(remainingMessages())->toBe(['keep-info']);
});

it('clears only the selected channels', function () {
    seedEntry(['channel' => 'single', 'message' => 'keep-single']);
    seedEntry(['channel' => 'queue', 'message' => 'drop-queue']);
    seedEntry(['channel' => 'app', 'message' => 'drop-app']);

    $response = $this->postJson('/logscope/api/logs/clear', [
        'channels' => ['queue', 'app'],
    ]);

    $response->assertOk();
    expect(remainingMessages())->toBe(['keep-single']);
});

it('clears only the selected statuses', function () {
    seedEntry(['message' => 'keep-open', 'status' => 'open']);
    seedEntry(['message' => 'drop-resolved', 'status' => 'resolved']);
    seedEntry(['message' => 'drop-ignored', 'status' => 'ignored']);

    $response = $this->postJson('/logscope/api/logs/clear', [
        'statuses' => ['resolved', 'ignored'],
    ]);

    $response->assertOk();
    expect(remainingMessages())->toBe(['keep-open']);
});

it('combines filters rather than clearing everything that matches any of them', function () {
    seedEntry(['level' => 'error', 'channel' => 'queue', 'message' => 'drop-both']);
    seedEntry(['level' => 'error', 'channel' => 'single', 'message' => 'keep-level-only']);
    seedEntry(['level' => 'info', 'channel' => 'queue', 'message' => 'keep-channel-only']);

    $response = $this->postJson('/logscope/api/logs/clear', [
        'levels' => ['error'],
        'channels' => ['queue'],
    ]);

    $response->assertOk();
    expect(remainingMessages())->toBe(['keep-channel-only', 'keep-level-only']);
});

it('clears the whole table when no filter is given', function () {
    seedEntry(['level' => 'info', 'message' => 'a']);
    seedEntry(['level' => 'error', 'message' => 'b']);

    $response = $this->postJson('/logscope/api/logs/clear');

    $response->assertOk();
    expect(LogEntry::query()->count())->toBe(0);
});

// =============================================================================
// CHUNK BOUNDARY
// =============================================================================

it('deletes every matching row when the set spans more than one chunk', function () {
    // Two full chunks plus a partial one, so the loop has to run more than
    // once and then stop. A single unbounded DELETE would pass this too;
    // what it pins is that chunking did not truncate the work.
    $total = (LogEntry::DELETE_CHUNK_SIZE * 2) + 5;

    LogEntry::insert(array_map(fn ($i) => LogEntry::prepareData([
        'level' => 'error',
        'message' => "bulk {$i}",
        'channel' => 'single',
    ]), range(1, $total)));

    seedEntry(['level' => 'info', 'message' => 'survivor']);

    expect(LogEntry::query()->count())->toBe($total + 1);

    $response = $this->postJson('/logscope/api/logs/clear', [
        'levels' => ['error'],
    ]);

    $response->assertOk();
    expect(remainingMessages())->toBe(['survivor']);
});

it('reports the total deleted across all chunks, not just the last one', function () {
    $total = LogEntry::DELETE_CHUNK_SIZE + 3;

    LogEntry::insert(array_map(fn ($i) => LogEntry::prepareData([
        'level' => 'error',
        'message' => "bulk {$i}",
        'channel' => 'single',
    ]), range(1, $total)));

    $response = $this->postJson('/logscope/api/logs/clear', [
        'levels' => ['error'],
    ]);

    $response->assertOk();
    // Pins the accumulator: returning the last chunk's count would say 3.
    expect($response->json('message'))->toBe("{$total} log entries cleared");
});
