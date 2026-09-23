<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogScope\Enums\LogStatus;
use LogScope\Models\LogEntry;
use LogScope\Models\LogGroup;
use LogScope\Services\Fingerprint;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->artisan('migrate', ['--path' => __DIR__.'/../../database/migrations']);
});

/**
 * Insert a row the way a pre-grouping release would have: straight to the
 * table, with no fingerprint.
 */
function legacyEntry(array $overrides = []): string
{
    $id = strtolower((string) Str::ulid());

    DB::table(config('logscope.table', 'log_entries'))->insert(array_merge([
        'id' => $id,
        'level' => 'error',
        'message' => 'User 4192 not found',
        'message_preview' => 'User 4192 not found',
        'channel' => 'stack',
        'occurred_at' => '2026-09-01 10:00:00',
        'status' => LogStatus::Open->value,
        'is_truncated' => false,
        'created_at' => '2026-09-01 10:00:00',
    ], $overrides));

    return $id;
}

it('fingerprints entries that have none', function () {
    legacyEntry(['message' => 'User 1 not found']);
    legacyEntry(['message' => 'User 2 not found']);

    expect(LogEntry::whereNull('fingerprint')->count())->toBe(2);

    $this->artisan('logscope:backfill-fingerprints')->assertSuccessful();

    expect(LogEntry::whereNull('fingerprint')->count())->toBe(0);
    expect(LogEntry::distinct()->pluck('fingerprint'))->toHaveCount(1);
});

it('builds groups with counts recomputed from the entries', function () {
    legacyEntry(['message' => 'User 1 not found', 'occurred_at' => '2026-09-01 10:00:00']);
    legacyEntry(['message' => 'User 2 not found', 'occurred_at' => '2026-09-03 10:00:00']);
    legacyEntry(['message' => 'Gateway timed out', 'occurred_at' => '2026-09-02 10:00:00']);

    $this->artisan('logscope:backfill-fingerprints')->assertSuccessful();

    expect(LogGroup::count())->toBe(2);

    $group = LogGroup::query()->where('occurrence_count', 2)->first();

    expect($group)->not->toBeNull();
    expect($group->first_seen_at->format('Y-m-d'))->toBe('2026-09-01');
    expect($group->last_seen_at->format('Y-m-d'))->toBe('2026-09-03');
});

it('is safe to run twice', function () {
    legacyEntry(['message' => 'User 1 not found']);
    legacyEntry(['message' => 'User 2 not found']);

    $this->artisan('logscope:backfill-fingerprints')->assertSuccessful();
    $this->artisan('logscope:backfill-fingerprints')->assertSuccessful();

    expect(LogGroup::count())->toBe(1);
    // Recomputed from the entries, not incremented, so a second run is a no-op.
    expect(LogGroup::first()->occurrence_count)->toBe(2);
});

it('gives a backfilled entry the same fingerprint as a live-written one', function () {
    legacyEntry(['message' => 'User 4192 not found']);

    $this->artisan('logscope:backfill-fingerprints')->assertSuccessful();

    $backfilled = LogEntry::first()->fingerprint;

    expect($backfilled)->toBe(Fingerprint::for([
        'message' => 'User 4192 not found',
        'level' => 'error',
        'channel' => 'stack',
    ]));
});

it('fingerprints a legacy entry on its stored exception, not its message', function () {
    $exception = ['_type' => 'exception', 'class' => 'RuntimeException', 'file' => '/app/Gateway.php', 'line' => 212];

    legacyEntry(['message' => 'Declined for order 4192', 'context' => json_encode(['exception' => $exception])]);
    legacyEntry(['message' => 'Completely different text', 'context' => json_encode(['exception' => $exception])]);

    $this->artisan('logscope:backfill-fingerprints')->assertSuccessful();

    // Same exception, different wording — one group.
    expect(LogGroup::count())->toBe(1);
    expect(LogGroup::first()->occurrence_count)->toBe(2);
});

describe('recompute', function () {
    it('re-fingerprints rows that already have one', function () {
        legacyEntry(['message' => 'User 1 not found']);
        $this->artisan('logscope:backfill-fingerprints')->assertSuccessful();

        // Simulate a row fingerprinted under different rules.
        LogEntry::query()->update(['fingerprint' => str_repeat('a', 40)]);

        $this->artisan('logscope:backfill-fingerprints')->assertSuccessful();
        expect(LogEntry::first()->fingerprint)->toBe(str_repeat('a', 40));

        $this->artisan('logscope:backfill-fingerprints', ['--recompute' => true])->assertSuccessful();

        expect(LogEntry::first()->fingerprint)->toBe(Fingerprint::for([
            'message' => 'User 1 not found',
            'level' => 'error',
            'channel' => 'stack',
        ]));
    });

    it('clears groups left empty by the new fingerprints', function () {
        legacyEntry(['message' => 'User 1 not found']);
        $this->artisan('logscope:backfill-fingerprints')->assertSuccessful();

        expect(LogGroup::count())->toBe(1);

        LogEntry::query()->update(['fingerprint' => str_repeat('b', 40)]);

        $this->artisan('logscope:backfill-fingerprints', ['--recompute' => true])->assertSuccessful();

        // One group for the recomputed fingerprint, none left stranded.
        expect(LogGroup::count())->toBe(1);
        expect(LogGroup::first()->occurrence_count)->toBe(1);
    });
});

describe('status rollup', function () {
    it('inherits the most recently changed entry status', function () {
        legacyEntry(['message' => 'User 1 not found', 'status' => LogStatus::Open->value]);
        legacyEntry([
            'message' => 'User 2 not found',
            'status' => LogStatus::Resolved->value,
            'status_changed_at' => '2026-09-10 10:00:00',
        ]);
        legacyEntry([
            'message' => 'User 3 not found',
            'status' => LogStatus::Investigating->value,
            'status_changed_at' => '2026-09-12 10:00:00',
        ]);

        $this->artisan('logscope:backfill-fingerprints')->assertSuccessful();

        expect(LogGroup::first()->status)->toBe(LogStatus::Investigating);
    });

    it('defaults to open when no entry was ever triaged', function () {
        legacyEntry(['message' => 'User 1 not found']);

        $this->artisan('logscope:backfill-fingerprints')->assertSuccessful();

        expect(LogGroup::first()->status)->toBe(LogStatus::Open);
    });

    it('does not overwrite triage already set on the group', function () {
        legacyEntry(['message' => 'User 1 not found']);

        $this->artisan('logscope:backfill-fingerprints')->assertSuccessful();

        LogGroup::first()->setStatus(LogStatus::Ignored, 'ahmed', 'known noise');

        // A second entry arrives and the backfill runs again.
        legacyEntry(['message' => 'User 2 not found']);
        $this->artisan('logscope:backfill-fingerprints')->assertSuccessful();

        $group = LogGroup::first();

        expect($group->status)->toBe(LogStatus::Ignored);
        expect($group->note)->toBe('known noise');
        expect($group->occurrence_count)->toBe(2);
    });
});
