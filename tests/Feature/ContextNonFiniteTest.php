<?php

declare(strict_types=1);

// Tests for issue #69 — INF or NAN in a log context.
//
// json_encode() cannot represent a non-finite float and returns false for
// the whole document, so one fdiv(1, 0) cost every sibling field. On sqlite
// the column silently stored ''; MySQL and Postgres reject '' as JSON and
// the entry degraded to a fallback row. Every test asserts on the stored
// bytes, because the decoded attribute of '' is null on sqlite and hides it.

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use LogScope\LogScopeServiceProvider;
use LogScope\Models\LogEntry;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->artisan('migrate', ['--path' => __DIR__.'/../../database/migrations']);

    LogScopeServiceProvider::resetBufferState();
    LogEntry::query()->delete();
});

function nonFiniteContext(): array
{
    return [
        'ratio' => fdiv(1, 0),
        'loss' => -INF,
        'nested' => ['score' => NAN],
        'keep' => 'me',
    ];
}

/** The stored column of the real row, decoded — never the fallback marker. */
function storedNonFiniteContext(): array
{
    $entry = LogEntry::query()->where('message', 'rate check')->latest('occurred_at')->first();

    expect($entry)->not->toBeNull();

    $raw = $entry->getRawOriginal('context');
    $decoded = json_decode($raw, true);

    expect($raw)->not->toBe('')
        ->and(json_last_error())->toBe(JSON_ERROR_NONE)
        ->and($decoded)->toBeArray()
        ->and($decoded)->not->toHaveKey('_logscope_write_failure');

    return $decoded;
}

function expectNonFiniteKept(array $context): void
{
    // Readable strings rather than 0: the stored value says what happened.
    expect($context['keep'])->toBe('me')
        ->and($context['ratio'])->toBe('INF')
        ->and($context['loss'])->toBe('-INF')
        ->and($context['nested']['score'])->toBe('NAN');
}

it('keeps a context holding INF and NAN in sync mode', function () {
    config(['logscope.write_mode' => 'sync']);

    Log::warning('rate check', nonFiniteContext());

    expectNonFiniteKept(storedNonFiniteContext());
});

it('keeps a context holding INF and NAN in batch mode', function () {
    config(['logscope.write_mode' => 'batch']);

    Log::warning('rate check', nonFiniteContext());

    LogScopeServiceProvider::flushLogBufferStatic();

    expectNonFiniteKept(storedNonFiniteContext());
});

it('keeps a context holding INF and NAN in queue mode, which serializes before storage', function () {
    // dispatch() encodes the job payload itself, so without the coercion in
    // WriteLogEntry no job is queued and FallbackWriter writes a marker row.
    config(['logscope.write_mode' => 'queue', 'queue.default' => 'database']);

    Schema::dropIfExists('jobs');
    Schema::create('jobs', function (Blueprint $table) {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    Log::warning('rate check', nonFiniteContext());

    expect(DB::table('jobs')->count())->toBe(1);

    $this->artisan('queue:work', ['--once' => true]);

    expectNonFiniteKept(storedNonFiniteContext());
});

it('keeps the siblings of a value the array walk cannot reach', function () {
    // An object's properties and a resource skip the walk, so the
    // partial-output flag is what keeps the rest: just that value degrades.
    $context = ['o' => (object) ['v' => INF], 'r' => fopen('php://memory', 'r'), 'keep' => 'me'];

    expect(json_decode(LogEntry::encodeContext($context), true))
        ->toBe(['o' => ['v' => 0], 'r' => null, 'keep' => 'me']);
});
