<?php

declare(strict_types=1);

// The delete-many endpoint used to bind every supplied id into a single
// DELETE statement, so a large enough selection blew past the engine's
// bind-parameter cap and failed outright (#94). It now chunks the incoming
// ids like every other bulk delete.

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
