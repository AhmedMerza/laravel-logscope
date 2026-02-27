<?php

declare(strict_types=1);

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

// =============================================================================
// FIRST PAGE / HAS_NEXT
// =============================================================================

it('returns has_next true when more records exist beyond the page', function () {
    for ($i = 0; $i < 12; $i++) {
        LogEntry::createEntry([
            'level' => 'info',
            'message' => "Log {$i}",
            'occurred_at' => now()->subSeconds($i),
        ]);
    }

    $response = $this->getJson('/logscope/api/logs?per_page=10');
    $response->assertOk();

    $data = $response->json();
    expect($data['meta']['has_next'])->toBeTrue();
    expect($data['meta']['next_cursor'])->not->toBeNull();
    expect(count($data['data']))->toBe(10);
});

it('returns has_next false when all records fit on the page', function () {
    for ($i = 0; $i < 5; $i++) {
        LogEntry::createEntry([
            'level' => 'info',
            'message' => "Log {$i}",
            'occurred_at' => now()->subSeconds($i),
        ]);
    }

    $response = $this->getJson('/logscope/api/logs?per_page=10');
    $response->assertOk();

    $data = $response->json();
    expect($data['meta']['has_next'])->toBeFalse();
    expect($data['meta']['next_cursor'])->toBeNull();
    expect(count($data['data']))->toBe(5);
});

it('returns has_next false when record count exactly matches per_page', function () {
    for ($i = 0; $i < 10; $i++) {
        LogEntry::createEntry([
            'level' => 'info',
            'message' => "Log {$i}",
            'occurred_at' => now()->subSeconds($i),
        ]);
    }

    $response = $this->getJson('/logscope/api/logs?per_page=10');
    $response->assertOk();

    $data = $response->json();
    expect($data['meta']['has_next'])->toBeFalse();
    expect($data['meta']['next_cursor'])->toBeNull();
    expect(count($data['data']))->toBe(10);
});

// =============================================================================
// CURSOR NAVIGATION
// =============================================================================

it('fetches the next batch using the cursor with no overlap and no gap', function () {
    // Create 15 entries with distinct timestamps so ordering is predictable
    for ($i = 0; $i < 15; $i++) {
        LogEntry::createEntry([
            'level' => 'info',
            'message' => "Log {$i}",
            'occurred_at' => now()->subSeconds($i),
        ]);
    }

    // Fetch first page
    $first = $this->getJson('/logscope/api/logs?per_page=10');
    $first->assertOk();
    $firstData = $first->json();

    expect($firstData['meta']['has_next'])->toBeTrue();
    $cursor = $firstData['meta']['next_cursor'];
    $firstIds = array_column($firstData['data'], 'id');

    // Fetch second page using cursor
    $second = $this->getJson('/logscope/api/logs?per_page=10&cursor='.urlencode($cursor));
    $second->assertOk();
    $secondData = $second->json();

    $secondIds = array_column($secondData['data'], 'id');

    // No overlap
    expect(array_intersect($firstIds, $secondIds))->toBeEmpty();

    // No gap: combined IDs cover all 15 entries
    $allIds = array_merge($firstIds, $secondIds);
    expect(count($allIds))->toBe(15);

    // Second page is the remainder
    expect(count($secondData['data']))->toBe(5);
    expect($secondData['meta']['has_next'])->toBeFalse();
});

it('reports the remaining count after applying the cursor', function () {
    for ($i = 0; $i < 12; $i++) {
        LogEntry::createEntry([
            'level' => 'info',
            'message' => "Log {$i}",
            'occurred_at' => now()->subSeconds($i),
        ]);
    }

    $first = $this->getJson('/logscope/api/logs?per_page=10');
    $first->assertOk();

    $second = $this->getJson('/logscope/api/logs?per_page=10&cursor='.urlencode($first->json('meta.next_cursor')));
    $second->assertOk();

    $data = $second->json();
    expect($data['meta']['count'])->toBe(2);
    expect($data['meta']['has_next_count'])->toBeFalse();
    expect(count($data['data']))->toBe(2);
});

it('returns next_cursor only when has_next is true', function () {
    for ($i = 0; $i < 12; $i++) {
        LogEntry::createEntry([
            'level' => 'info',
            'message' => "Log {$i}",
            'occurred_at' => now()->subSeconds($i),
        ]);
    }

    $response = $this->getJson('/logscope/api/logs?per_page=10');
    $data = $response->json();

    expect($data['meta']['has_next'])->toBeTrue();
    expect($data['meta']['next_cursor'])->not->toBeNull();

    // Use cursor for last page
    $next = $this->getJson('/logscope/api/logs?per_page=10&cursor='.urlencode($data['meta']['next_cursor']));
    $nextData = $next->json();

    expect($nextData['meta']['has_next'])->toBeFalse();
    expect($nextData['meta']['next_cursor'])->toBeNull();
});

it('encodes the cursor using the stored occurred_at wall-clock value', function () {
    $previousTimezone = date_default_timezone_get();
    config(['app.timezone' => 'America/New_York']);
    date_default_timezone_set('America/New_York');

    try {
        LogEntry::createEntry([
            'level' => 'info',
            'message' => 'Log 1',
            'occurred_at' => '2026-02-27 12:00:00',
        ]);
        LogEntry::createEntry([
            'level' => 'info',
            'message' => 'Log 2',
            'occurred_at' => '2026-02-27 11:00:00',
        ]);
        LogEntry::createEntry([
            'level' => 'info',
            'message' => 'Log 3',
            'occurred_at' => '2026-02-27 10:00:00',
        ]);

        $response = $this->getJson('/logscope/api/logs?per_page=2');
        $response->assertOk();

        $cursor = json_decode(base64_decode($response->json('meta.next_cursor'), true) ?: '', true);

        expect($cursor)->toBeArray();
        expect($cursor['occurred_at'])->toBe('2026-02-27 11:00:00');
    } finally {
        config(['app.timezone' => $previousTimezone]);
        date_default_timezone_set($previousTimezone);
    }
});

// =============================================================================
// INVALID / MALFORMED CURSOR
// =============================================================================

it('falls back gracefully when cursor is not valid base64', function () {
    for ($i = 0; $i < 5; $i++) {
        LogEntry::createEntry([
            'level' => 'info',
            'message' => "Log {$i}",
            'occurred_at' => now()->subSeconds($i),
        ]);
    }

    // A cursor that is not valid base64 / JSON — should be silently ignored
    $response = $this->getJson('/logscope/api/logs?per_page=10&cursor=!!!invalid!!!');
    $response->assertOk();

    $data = $response->json();
    expect(count($data['data']))->toBe(5);
});

it('falls back gracefully when cursor json is missing required fields', function () {
    for ($i = 0; $i < 5; $i++) {
        LogEntry::createEntry([
            'level' => 'info',
            'message' => "Log {$i}",
            'occurred_at' => now()->subSeconds($i),
        ]);
    }

    // Cursor with missing id/occurred_at — treated as no cursor
    $badCursor = base64_encode(json_encode(['foo' => 'bar']));
    $response = $this->getJson('/logscope/api/logs?per_page=10&cursor='.urlencode($badCursor));
    $response->assertOk();

    $data = $response->json();
    expect(count($data['data']))->toBe(5);
});

// =============================================================================
// CURSOR RESPECTS FILTERS
// =============================================================================

it('cursor respects active level filters across pages', function () {
    // Create 12 error entries and 12 info entries
    for ($i = 0; $i < 12; $i++) {
        LogEntry::createEntry([
            'level' => 'error',
            'message' => "Error {$i}",
            'occurred_at' => now()->subSeconds($i),
        ]);
    }
    for ($i = 0; $i < 12; $i++) {
        LogEntry::createEntry([
            'level' => 'info',
            'message' => "Info {$i}",
            'occurred_at' => now()->subSeconds(100 + $i),
        ]);
    }

    // First page filtered to errors only
    $first = $this->getJson('/logscope/api/logs?per_page=10&levels[]=error');
    $first->assertOk();
    $firstData = $first->json();

    expect($firstData['meta']['has_next'])->toBeTrue();
    expect(count($firstData['data']))->toBe(10);

    // All items should be errors
    foreach ($firstData['data'] as $log) {
        expect($log['level'])->toBe('error');
    }

    $cursor = $firstData['meta']['next_cursor'];

    // Second page with same filter
    $second = $this->getJson('/logscope/api/logs?per_page=10&levels[]=error&cursor='.urlencode($cursor));
    $second->assertOk();
    $secondData = $second->json();

    expect(count($secondData['data']))->toBe(2);
    expect($secondData['meta']['has_next'])->toBeFalse();

    // All items still errors
    foreach ($secondData['data'] as $log) {
        expect($log['level'])->toBe('error');
    }
});

// =============================================================================
// META FIELDS
// =============================================================================

it('returns correct meta fields in response', function () {
    LogEntry::createEntry(['level' => 'info', 'message' => 'Test']);

    $response = $this->getJson('/logscope/api/logs?per_page=10');
    $response->assertOk();

    $data = $response->json();
    expect($data['meta'])->toHaveKeys(['has_next', 'next_cursor', 'per_page', 'count', 'has_next_count']);
});

it('returns capped count that does not exceed 1000', function () {
    for ($i = 0; $i < 5; $i++) {
        LogEntry::createEntry([
            'level' => 'info',
            'message' => "Log {$i}",
            'occurred_at' => now()->subSeconds($i),
        ]);
    }

    // Level filter activates the capped count
    $response = $this->getJson('/logscope/api/logs?per_page=10&levels[]=info');
    $response->assertOk();

    $data = $response->json();
    expect($data['meta']['count'])->toBe(5);
    expect($data['meta']['has_next_count'])->toBeFalse();
});

it('returns the default open-scope count on the first page', function () {
    LogEntry::createEntry(['level' => 'info', 'message' => 'Open 1']);
    LogEntry::createEntry(['level' => 'info', 'message' => 'Open 2']);
    LogEntry::createEntry(['level' => 'info', 'message' => 'Resolved', 'status' => 'resolved']);

    $response = $this->getJson('/logscope/api/logs?per_page=10');
    $response->assertOk();

    $data = $response->json();
    expect($data['meta']['count'])->toBe(2);
    expect($data['meta']['has_next_count'])->toBeFalse();
    expect(count($data['data']))->toBe(2);
});

it('returns the capped count for status-only filters', function () {
    LogEntry::createEntry(['level' => 'info', 'message' => 'Open']);
    LogEntry::createEntry(['level' => 'info', 'message' => 'Resolved 1', 'status' => 'resolved']);
    LogEntry::createEntry(['level' => 'info', 'message' => 'Resolved 2', 'status' => 'resolved']);

    $response = $this->getJson('/logscope/api/logs?per_page=10&statuses[]=resolved');
    $response->assertOk();

    $data = $response->json();
    expect($data['meta']['count'])->toBe(2);
    expect($data['meta']['has_next_count'])->toBeFalse();
    expect(count($data['data']))->toBe(2);
});

it('returns the current count for the all-statuses view', function () {
    LogEntry::createEntry(['level' => 'info', 'message' => 'Open']);
    LogEntry::createEntry(['level' => 'info', 'message' => 'Investigating', 'status' => 'investigating']);
    LogEntry::createEntry(['level' => 'info', 'message' => 'Resolved', 'status' => 'resolved']);

    $response = $this->getJson('/logscope/api/logs?per_page=10&statuses[]=open&statuses[]=investigating&statuses[]=resolved&statuses[]=ignored');
    $response->assertOk();

    $data = $response->json();
    expect($data['meta']['count'])->toBe(3);
    expect($data['meta']['has_next_count'])->toBeFalse();
    expect(count($data['data']))->toBe(3);
});

it('returns has_next_count true and caps count at 1000 when filtered results exceed 1000', function () {
    $now = now();

    // Bulk-insert 1001 rows to avoid the overhead of createEntry() per row
    $rows = [];
    for ($i = 0; $i < 1001; $i++) {
        $rows[] = [
            'id'              => (string) \Illuminate\Support\Str::ulid(),
            'level'           => 'info',
            'message'         => "Log {$i}",
            'message_preview' => "Log {$i}",
            'occurred_at'     => $now->copy()->subSeconds($i)->format('Y-m-d H:i:s'),
            'created_at'      => $now->format('Y-m-d H:i:s'),
            'status'          => 'open',
            'is_truncated'    => 0,
        ];
    }

    // Chunk to stay within SQLite's bind-parameter limit
    $table = config('logscope.table', 'log_entries');
    foreach (array_chunk($rows, 100) as $chunk) {
        \Illuminate\Support\Facades\DB::table($table)->insert($chunk);
    }

    // Level filter activates the capped count path
    $response = $this->getJson('/logscope/api/logs?per_page=10&levels[]=info');
    $response->assertOk();

    $data = $response->json();
    expect($data['meta']['count'])->toBe(1000);
    expect($data['meta']['has_next_count'])->toBeTrue();
});
