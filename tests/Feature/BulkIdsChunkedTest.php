<?php

declare(strict_types=1);

// The bulk endpoints (delete-many and both status-many routes) used to bind
// every supplied id into a single statement, so a large enough selection blew
// past the engine's bind-parameter cap and failed outright (#94). They now
// chunk the incoming ids like every other bulk delete.

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogScope\Enums\LogStatus;
use LogScope\LogScope;
use LogScope\Models\LogEntry;
use LogScope\Models\LogGroup;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->artisan('migrate', ['--path' => __DIR__.'/../../database/migrations']);
    LogScope::auth(fn () => true);
    LogEntry::query()->delete();
    LogGroup::query()->delete();
});

afterEach(function () {
    LogScope::resetAuth();
});

/**
 * @return array<int, string>
 */
function seedManyEntries(int $count, string $level = 'error'): array
{
    LogEntry::insert(array_map(fn ($i) => LogEntry::prepareData([
        'level' => $level,
        'message' => "delete-many {$i}",
        'channel' => 'single',
    ]), range(1, $count)));

    return LogEntry::query()->where('message', 'like', 'delete-many %')->pluck('id')->all();
}

it('deletes every named row when the selection spans more than one chunk', function () {
    $total = (LogEntry::DELETE_CHUNK_SIZE * 2) + 5;
    $ids = seedManyEntries($total);

    LogEntry::createEntry(['level' => 'info', 'message' => 'survivor', 'channel' => 'single']);

    $response = $this->postJson('/logscope/api/logs/delete-many', ['ids' => $ids]);

    $response->assertOk();
    expect($response->json('message'))->toBe("{$total} log entries deleted")
        ->and(LogEntry::query()->pluck('message')->all())->toBe(['survivor']);
});

it('never binds more ids to one statement than the chunk size', function () {
    $total = LogEntry::DELETE_CHUNK_SIZE + 3;
    $ids = seedManyEntries($total);

    $bindingsPerDelete = [];
    DB::listen(function ($query) use (&$bindingsPerDelete) {
        if (str_starts_with(strtolower(trim($query->sql)), 'delete')) {
            $bindingsPerDelete[] = count($query->bindings);
        }
    });

    $this->postJson('/logscope/api/logs/delete-many', ['ids' => $ids])->assertOk();

    // One full chunk plus a partial one — and nothing above the cap.
    expect($bindingsPerDelete)->toBe([LogEntry::DELETE_CHUNK_SIZE, 3]);
});

/**
 * Record how many of the given ids each UPDATE binds — the SET values are
 * bound too, so only the ids themselves are counted.
 *
 * @param  array<int, string>  $ids
 * @return array<int, int>
 */
function idsBoundPerUpdate(array $ids, callable $send): array
{
    $perUpdate = [];
    DB::listen(function ($query) use ($ids, &$perUpdate) {
        if (str_starts_with(strtolower(trim($query->sql)), 'update')) {
            $perUpdate[] = count(array_intersect($query->bindings, $ids));
        }
    });

    $send();

    return $perUpdate;
}

it('updates entry statuses in chunks across a large selection', function () {
    $total = LogEntry::DELETE_CHUNK_SIZE + 3;
    $ids = seedManyEntries($total);

    // Outside the selection, so an update that lost its id scope would show.
    LogEntry::createEntry(['level' => 'info', 'message' => 'bystander', 'channel' => 'single']);

    $perUpdate = idsBoundPerUpdate($ids, function () use ($ids, $total) {
        $this->postJson('/logscope/api/logs/status-many', ['ids' => $ids, 'status' => 'resolved'])
            ->assertOk()
            ->assertJson(['message' => "{$total} log entries updated to resolved"]);
    });

    expect($perUpdate)->toBe([LogEntry::DELETE_CHUNK_SIZE, 3])
        ->and(LogEntry::query()->where('status', 'resolved')->count())->toBe($total);
});

it('counts an id repeated across chunks once', function () {
    $total = LogEntry::DELETE_CHUNK_SIZE + 3;
    $ids = seedManyEntries($total);

    // The repeat lands in the second chunk; without deduping, SQLite and
    // Postgres report the row as updated twice.
    $this->postJson('/logscope/api/logs/status-many', ['ids' => [...$ids, $ids[0]], 'status' => 'resolved'])
        ->assertOk()
        ->assertJson(['message' => "{$total} log entries updated to resolved"]);
});

it('updates group statuses in chunks across a large selection', function () {
    LogEntry::createEntry(['level' => 'error', 'message' => 'grouped', 'channel' => 'single']);
    $group = LogGroup::query()->firstOrFail();
    $group->forceFill(['regressed_at' => now()])->save();

    // Only one group exists; the rest are ids a client could still post. The
    // group is named again at the end, in the second chunk, and still counts once.
    $ids = [$group->id, ...array_map(fn ($i) => "missing-{$i}", range(1, LogEntry::DELETE_CHUNK_SIZE + 2)), $group->id];

    $perUpdate = idsBoundPerUpdate($ids, function () use ($ids) {
        $this->postJson('/logscope/api/groups/status-many', ['ids' => $ids, 'status' => 'resolved'])
            ->assertOk()
            ->assertJson(['message' => '1 groups updated to resolved']);
    });

    expect($perUpdate)->toBe([LogEntry::DELETE_CHUNK_SIZE, 3])
        ->and($group->fresh()->status)->toBe(LogStatus::Resolved)
        ->and($group->fresh()->regressed_at)->toBeNull();
});
