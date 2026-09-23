<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogScope\Enums\LogStatus;
use LogScope\Models\LogEntry;
use LogScope\Models\LogGroup;
use LogScope\Services\Fingerprint;
use LogScope\Services\GroupRecorder;
use LogScope\Services\LogBuffer;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->artisan('migrate', ['--path' => __DIR__.'/../../database/migrations']);

    LogBuffer::reset();

    // Hold everything for one deliberate flush.
    config(['logscope.batch.max_entries' => 0, 'logscope.batch.max_age' => 0]);
});

afterEach(function () {
    LogBuffer::reset();
});

/** Flush a set of entries through the batch path. */
function flushEntries(array $entries): void
{
    $buffer = new LogBuffer;

    foreach ($entries as $entry) {
        $buffer->add($entry);
    }

    LogBuffer::flushStatic();
}

describe('rollup', function () {
    it('collapses identical errors with varying ids into one group', function () {
        flushEntries([
            ['level' => 'error', 'message' => 'User 4192 not found', 'channel' => 'stack'],
            ['level' => 'error', 'message' => 'User 87 not found', 'channel' => 'stack'],
            ['level' => 'error', 'message' => 'User 6 not found', 'channel' => 'stack'],
        ]);

        expect(LogEntry::count())->toBe(3);
        expect(LogGroup::count())->toBe(1);
        expect(LogGroup::first()->occurrence_count)->toBe(3);
    });

    it('keeps genuinely different errors in different groups', function () {
        flushEntries([
            ['level' => 'error', 'message' => 'User 4192 not found', 'channel' => 'stack'],
            ['level' => 'error', 'message' => 'Payment gateway timed out', 'channel' => 'stack'],
        ]);

        expect(LogGroup::count())->toBe(2);
    });

    it('accumulates the count across separate flushes', function () {
        $entry = ['level' => 'error', 'message' => 'User 1 not found', 'channel' => 'stack'];

        flushEntries([$entry, $entry]);
        flushEntries([$entry]);

        expect(LogGroup::count())->toBe(1);
        expect(LogGroup::first()->occurrence_count)->toBe(3);
    });

    it('records first and last seen across flushes', function () {
        flushEntries([
            ['level' => 'error', 'message' => 'Boom 1', 'channel' => 'stack', 'occurred_at' => '2026-09-01 10:00:00'],
            ['level' => 'error', 'message' => 'Boom 2', 'channel' => 'stack', 'occurred_at' => '2026-09-05 10:00:00'],
        ]);

        $group = LogGroup::first();

        expect($group->first_seen_at->format('Y-m-d'))->toBe('2026-09-01');
        expect($group->last_seen_at->format('Y-m-d'))->toBe('2026-09-05');
    });

    it('never moves last_seen_at backwards when a late write arrives', function () {
        $base = ['level' => 'error', 'message' => 'Boom 1', 'channel' => 'stack'];

        flushEntries([$base + ['occurred_at' => '2026-09-05 10:00:00']]);
        flushEntries([$base + ['occurred_at' => '2026-09-01 10:00:00']]);

        expect(LogGroup::first()->last_seen_at->format('Y-m-d'))->toBe('2026-09-05');
    });

    it('links a group to its occurrences', function () {
        flushEntries([
            ['level' => 'error', 'message' => 'User 1 not found', 'channel' => 'stack'],
            ['level' => 'error', 'message' => 'User 2 not found', 'channel' => 'stack'],
        ]);

        expect(LogGroup::first()->entries()->count())->toBe(2);
    });
});

describe('write cost', function () {
    // The write path bulk-inserts entries; if grouping cost a statement per
    // entry it would undo the batching LogBuffer exists to provide.
    it('costs the same number of queries whatever the batch size', function () {
        $makeEntries = fn (int $n) => array_map(fn ($i) => [
            'level' => 'error',
            'message' => "User {$i} not found",
            'channel' => 'stack',
        ], range(1, $n));

        DB::enableQueryLog();
        flushEntries($makeEntries(5));
        $small = count(DB::getQueryLog());

        DB::flushQueryLog();
        flushEntries($makeEntries(400));
        $large = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect($large)->toBe($small);

        // Pinned rather than left as "the same number": equality alone would
        // still hold if both grew, and the point is that the cost is fixed.
        // One bulk entry insert, plus GroupRecorder's three statements.
        expect($large)->toBe(4);
    });
});

describe('regression', function () {
    it('reopens a resolved group when it fires again', function () {
        $entry = ['level' => 'error', 'message' => 'User 1 not found', 'channel' => 'stack'];

        flushEntries([$entry]);
        LogGroup::first()->setStatus(LogStatus::Resolved, 'ahmed');

        flushEntries([$entry]);

        $group = LogGroup::first();

        expect($group->status)->toBe(LogStatus::Open);
        expect($group->regressed_at)->not->toBeNull();
        expect($group->isRegressed())->toBeTrue();
        expect($group->occurrence_count)->toBe(2);
    });

    it('leaves an ignored group hidden when it fires again', function () {
        $entry = ['level' => 'error', 'message' => 'User 1 not found', 'channel' => 'stack'];

        flushEntries([$entry]);
        LogGroup::first()->setStatus(LogStatus::Ignored, 'ahmed');

        flushEntries([$entry]);

        $group = LogGroup::first();

        expect($group->status)->toBe(LogStatus::Ignored);
        expect($group->regressed_at)->toBeNull();
        // Still counted — silenced, not ignored entirely.
        expect($group->occurrence_count)->toBe(2);
    });

    it('does not mark an already-open group as regressed', function () {
        $entry = ['level' => 'error', 'message' => 'User 1 not found', 'channel' => 'stack'];

        flushEntries([$entry]);
        flushEntries([$entry]);

        expect(LogGroup::first()->regressed_at)->toBeNull();
    });

    it('clears the regression flag once the group is triaged again', function () {
        $entry = ['level' => 'error', 'message' => 'User 1 not found', 'channel' => 'stack'];

        flushEntries([$entry]);
        LogGroup::first()->setStatus(LogStatus::Resolved, 'ahmed');
        flushEntries([$entry]);

        LogGroup::first()->setStatus(LogStatus::Investigating, 'ahmed');

        expect(LogGroup::first()->regressed_at)->toBeNull();
    });

    it('can be turned off', function () {
        config(['logscope.grouping.regression' => false]);

        $entry = ['level' => 'error', 'message' => 'User 1 not found', 'channel' => 'stack'];

        flushEntries([$entry]);
        LogGroup::first()->setStatus(LogStatus::Resolved, 'ahmed');
        flushEntries([$entry]);

        expect(LogGroup::first()->status)->toBe(LogStatus::Resolved);
    });
});

describe('write paths agree', function () {
    // prepareData() and createEntry() are separate implementations. If they
    // fingerprinted differently, one error would land in two groups depending
    // on write_mode.
    it('fingerprints a sync write the same as a batched one', function () {
        LogEntry::createEntry(['level' => 'error', 'message' => 'User 4192 not found', 'channel' => 'stack']);

        flushEntries([['level' => 'error', 'message' => 'User 87 not found', 'channel' => 'stack']]);

        expect(LogEntry::count())->toBe(2);
        expect(LogGroup::count())->toBe(1);
        expect(LogGroup::first()->occurrence_count)->toBe(2);
    });

    it('fingerprints an entry written through the model', function () {
        $entry = LogEntry::createEntry(['level' => 'error', 'message' => 'User 4192 not found', 'channel' => 'stack']);

        expect($entry->fingerprint)->toBe(
            Fingerprint::for(['message' => 'User 4192 not found', 'level' => 'error', 'channel' => 'stack'])
        );
    });
});

describe('pruning', function () {
    it('deletes a group once all of its entries are gone', function () {
        flushEntries([
            ['level' => 'error', 'message' => 'User 1 not found', 'channel' => 'stack'],
            ['level' => 'error', 'message' => 'Payment gateway timed out', 'channel' => 'stack'],
        ]);

        expect(LogGroup::count())->toBe(2);

        LogEntry::query()->where('message', 'Payment gateway timed out')->delete();

        expect(GroupRecorder::deleteOrphaned())->toBe(1);
        expect(LogGroup::count())->toBe(1);
        expect(LogGroup::first()->sample_message)->toBe('User 1 not found');
    });

    it('keeps groups that still have entries', function () {
        flushEntries([['level' => 'error', 'message' => 'User 1 not found', 'channel' => 'stack']]);

        expect(GroupRecorder::deleteOrphaned())->toBe(0);
        expect(LogGroup::count())->toBe(1);
    });
});
