<?php

declare(strict_types=1);

// Exercises GroupRecorder::record() end-to-end (not retrying() in isolation,
// see GroupRecorderRetryTest.php) to pin two things code review flagged as
// untested: that fingerprints are actually processed in sorted order (the
// deadlock-avoidance half of the fix, not just the retry half), and that one
// record() call really does issue exactly the three documented statements
// against a real log_groups table.

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogScope\Services\GroupRecorder;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->artisan('migrate', ['--path' => __DIR__.'/../../database/migrations']);
});

function rowFor(string $fingerprint): array
{
    return [
        'fingerprint' => $fingerprint,
        'occurred_at' => '2026-09-28 12:00:00',
        'message' => "message for {$fingerprint}",
        'level' => 'error',
        'channel' => 'stack',
    ];
}

it('processes fingerprints in sorted order regardless of input order', function () {
    // Deliberately out of order: c, a, b.
    $rows = [rowFor('fp-c'), rowFor('fp-a'), rowFor('fp-b')];

    DB::enableQueryLog();
    GroupRecorder::record($rows);
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    $insert = collect($log)->firstWhere(fn ($q) => str_starts_with($q['query'], 'insert'));
    expect($insert)->not->toBeNull();

    // Read the column position from the compiled SQL rather than hardcoding
    // it — the grammar orders columns alphabetically, which isn't this
    // test's concern.
    preg_match('/\(("[a-z_]+", )*"fingerprint", ("[a-z_]+", )*"[a-z_]+"\)/', $insert['query'], $match);
    $columns = array_map(fn ($c) => trim($c, '"'), explode(', ', trim($match[0], '()')));
    $columnsPerRow = count($columns);
    $fingerprintIndex = array_search('fingerprint', $columns, true);

    $fingerprints = [];
    for ($i = $fingerprintIndex; $i < count($insert['bindings']); $i += $columnsPerRow) {
        $fingerprints[] = $insert['bindings'][$i];
    }

    expect($fingerprints)->toBe(['fp-a', 'fp-b', 'fp-c']);
});

it('issues exactly the three documented statements for one record() call', function () {
    DB::enableQueryLog();
    GroupRecorder::record([rowFor('fp-only')]);
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    expect($log)->toHaveCount(3);
    expect($log[0]['query'])->toStartWith('insert')
        ->and($log[1]['query'])->toContain('occurrence_count')
        ->and($log[2]['query'])->toContain('status');
});
