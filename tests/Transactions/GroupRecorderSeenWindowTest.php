<?php

declare(strict_types=1);

// GroupRecorder::bumpCounts() widens each group's seen window with a
// per-engine two-argument least()/greatest() — min()/max() on SQLite (#119).
// Lives here rather than in tests/Feature so it runs on whichever engine
// LOGSCOPE_TEST_DB points at; see tests/TransactionTestCase.php.

use Illuminate\Support\Facades\Artisan;
use LogScope\Models\LogGroup;
use LogScope\Services\GroupRecorder;

beforeEach(function () {
    Artisan::call('migrate:fresh');
});

it('moves first_seen_at back and keeps last_seen_at when older entries arrive late', function () {
    GroupRecorder::record([
        ['fingerprint' => 'a', 'occurred_at' => '2026-09-05 10:00:00', 'level' => 'error'],
        ['fingerprint' => 'b', 'occurred_at' => '2026-09-05 10:00:00', 'level' => 'error'],
    ]);

    // One batch, two fingerprints: 'a' gets an older entry, 'b' a newer one.
    GroupRecorder::record([
        ['fingerprint' => 'a', 'occurred_at' => '2026-09-01 10:00:00', 'level' => 'error'],
        ['fingerprint' => 'b', 'occurred_at' => '2026-09-08 10:00:00', 'level' => 'error'],
    ]);

    $a = LogGroup::where('fingerprint', 'a')->first();
    $b = LogGroup::where('fingerprint', 'b')->first();

    expect($a->first_seen_at->format('Y-m-d'))->toBe('2026-09-01')
        ->and($a->last_seen_at->format('Y-m-d'))->toBe('2026-09-05')
        ->and($a->occurrence_count)->toBe(2)
        ->and($b->first_seen_at->format('Y-m-d'))->toBe('2026-09-05')
        ->and($b->last_seen_at->format('Y-m-d'))->toBe('2026-09-08');
});
