<?php

declare(strict_types=1);

// Regression tests for GitHub issue #24:
//
// "Search: NOT toggle is not a true complement of include (multi-word
// math doesn't add up)"
//
// Two compounding bugs in LogController:
//
// 1. ANY stray `:` in the search input triggers parseSearchSyntax, which
//    tokenizes by whitespace. A phrase like "foo skipped: bar" silently
//    becomes three tokens — the user thought they typed one phrase.
//
// 2. Once parseSearchSyntax runs and the UI's outer exclude flag is set,
//    the flag is propagated onto each parsed term and the terms are AND'd
//    together (applyLikeSearch hardcodes `boolean = 'and'`). That gives
//    "contains NONE of the words" (= AND of NOT-LIKEs), not the boolean
//    complement "missing AT LEAST ONE word" (= NOT of AND-of-LIKEs).
//
// Result: include_count + exclude_count != total. Logs containing some
// but not all of the words fall through both filters and become invisible.
//
// These tests assert the complement invariant: for any search input, the
// count of matching rows + the count when the UI's NOT toggle is applied
// must equal the total candidate row count. If it doesn't, a row is being
// silently dropped from both views.

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
 * Seed six entries spanning the include/exclude space for a 4-token search.
 *
 * For the phrase "alpha bravo skipped: driver":
 *   - 2 entries contain ALL 4 tokens   (should match include)
 *   - 3 entries contain SOME tokens    (should match exclude — "missing at least one")
 *   - 1 entry contains NO tokens       (should match exclude — also "contains none")
 *
 * Total = 6. The contract: include_count + exclude_count == 6, always.
 */
function seedComplementFixture(): void
{
    $msgs = [
        'alpha bravo skipped: driver location stale',   // all four
        'alpha bravo skipped: driver',                  // all four
        'alpha bravo skipped:',                         // missing "driver"
        'alpha only',                                   // 1 of 4
        'bravo skipped: driver but no alpha',           // missing "alpha"
        'completely unrelated content here',            // none
    ];

    foreach ($msgs as $i => $message) {
        LogEntry::createEntry([
            'level' => 'info',
            'message' => $message,
            'occurred_at' => now()->subSeconds($i),
        ]);
    }
}

/**
 * Fetch result count for a given searches[] payload.
 */
function searchCount(array $searchEntry): int
{
    $url = '/logscope/api/logs?'.http_build_query(['searches' => [$searchEntry]]);
    $response = test()->getJson($url);
    $response->assertOk();

    return $response->json('meta.total') ?? count($response->json('data'));
}

/**
 * Assert the complement invariant — include + exclude == total — using the
 * live row count so the test stays robust against any incidental rows the
 * surrounding test suite may have written before us (LogCapture listener
 * picking up sync-mode emissions during the suite run, etc.). The point
 * of this test family is the invariant, not a fixed magic number.
 */
function assertComplementInvariant(array $searchEntry): void
{
    $total = LogEntry::query()->count();
    $entryInclude = ['exclude' => 0] + $searchEntry;
    $entryExclude = ['exclude' => 1] + $searchEntry;

    $include = searchCount($entryInclude);
    $exclude = searchCount($entryExclude);

    test()->assertSame(
        $total,
        $include + $exclude,
        "Complement invariant violated: include={$include} + exclude={$exclude} != total={$total} for search ".json_encode($searchEntry)
    );
}

// =============================================================================
// COMPLEMENT INVARIANT — the bug's smoking gun
// =============================================================================

it('multi-word phrase with a trailing colon: include + exclude == total', function () {
    seedComplementFixture();

    // This is the exact shape that triggered the issue report: a multi-word
    // phrase with a stray `:` on one of the words. Pre-fix, the colon flipped
    // the parser into structured-tokenize mode, NOT was applied per-token
    // with AND, and logs containing some-but-not-all tokens vanished.
    assertComplementInvariant(['field' => 'any', 'value' => 'alpha bravo skipped: driver']);
});

it('multi-word phrase WITHOUT any colon: include + exclude == total (regression baseline)', function () {
    seedComplementFixture();

    // No colon → already worked pre-fix as a single LIKE. Captures the
    // baseline so a future change can't accidentally regress it.
    assertComplementInvariant(['field' => 'any', 'value' => 'alpha bravo']);
});

it('structured field:value search: include + exclude == total', function () {
    LogEntry::createEntry(['level' => 'error', 'message' => 'payment failed', 'occurred_at' => now()]);
    LogEntry::createEntry(['level' => 'error', 'message' => 'unrelated', 'occurred_at' => now()->subSecond()]);
    LogEntry::createEntry(['level' => 'info', 'message' => 'payment failed', 'occurred_at' => now()->subSeconds(2)]);
    LogEntry::createEntry(['level' => 'info', 'message' => 'unrelated', 'occurred_at' => now()->subSeconds(3)]);

    // include = level='error' AND message contains 'payment'
    // exclude (true complement) = NOT (level='error' AND message contains 'payment')
    assertComplementInvariant(['field' => 'any', 'value' => 'level:error message:payment']);
});

it('quoted phrase containing a colon: include + exclude == total', function () {
    seedComplementFixture();

    // Quoted phrases were the documented workaround pre-fix. This pins the
    // working behavior so a future parser refactor doesn't break it.
    assertComplementInvariant(['field' => 'any', 'value' => '"alpha bravo skipped: driver"']);
});

it('mixed per-token exclusion with outer NOT: include + exclude == total', function () {
    LogEntry::createEntry(['level' => 'info', 'message' => 'foo bar', 'occurred_at' => now()]);
    LogEntry::createEntry(['level' => 'info', 'message' => 'foo only', 'occurred_at' => now()->subSecond()]);
    LogEntry::createEntry(['level' => 'info', 'message' => 'bar only', 'occurred_at' => now()->subSeconds(2)]);
    LogEntry::createEntry(['level' => 'info', 'message' => 'neither', 'occurred_at' => now()->subSeconds(3)]);

    // `foo -bar` = contains 'foo' AND NOT contains 'bar'
    // exclude (true complement) = NOT (contains 'foo' AND NOT contains 'bar')
    //   = NOT contains 'foo' OR contains 'bar'
    assertComplementInvariant(['field' => 'any', 'value' => 'foo -bar']);
});

// =============================================================================
// COLON-TRIGGER FRAGMENTATION — the underlying mode-switch bug
// =============================================================================

it('a phrase with a trailing colon is NOT silently fragmented into tokens', function () {
    LogEntry::createEntry(['level' => 'info', 'message' => 'alpha skipped: bravo', 'occurred_at' => now()]);
    // Reverse-order distractor: contains both "alpha" and "skipped:" but
    // NOT the contiguous phrase "alpha skipped:". Under the current bug
    // (tokenize + AND) this matches; under the fix (single LIKE) it doesn't.
    LogEntry::createEntry(['level' => 'info', 'message' => 'skipped: foo alpha bar', 'occurred_at' => now()->subSecond()]);
    LogEntry::createEntry(['level' => 'info', 'message' => 'alpha standalone', 'occurred_at' => now()->subSeconds(2)]);
    LogEntry::createEntry(['level' => 'info', 'message' => 'skipped: alone', 'occurred_at' => now()->subSeconds(3)]);

    $include = searchCount(['field' => 'any', 'value' => 'alpha skipped:', 'exclude' => 0]);

    // Current bug: returns 2 (AND-matches both reverse-order and contiguous).
    // Fix: returns 1 (contiguous substring only).
    expect($include)->toBe(1);
});

it('a known field name followed by colon DOES trigger structured search', function () {
    LogEntry::createEntry(['level' => 'error', 'message' => 'a', 'occurred_at' => now()]);
    LogEntry::createEntry(['level' => 'info', 'message' => 'b', 'occurred_at' => now()->subSecond()]);

    // `level:error` IS a legitimate structured query — should NOT be treated
    // as a substring search for "level:error" inside the message field.
    $include = searchCount(['field' => 'any', 'value' => 'level:error', 'exclude' => 0]);

    expect($include)->toBe(1);
});

it('a non-field word followed by colon is treated as a literal substring', function () {
    LogEntry::createEntry(['level' => 'info', 'message' => 'skipped: driver', 'occurred_at' => now()]);
    // Reverse-order distractor: contains both "skipped:" and "driver" but
    // NOT the contiguous phrase. Bug matches; fix doesn't.
    LogEntry::createEntry(['level' => 'info', 'message' => 'driver started, then skipped: phase', 'occurred_at' => now()->subSecond()]);
    LogEntry::createEntry(['level' => 'info', 'message' => 'unrelated', 'occurred_at' => now()->subSeconds(2)]);

    $include = searchCount(['field' => 'any', 'value' => 'skipped: driver', 'exclude' => 0]);

    // `skipped` is not a searchable field, so this is a substring search,
    // not a structured query — only the contiguous match counts.
    expect($include)->toBe(1);
});
