<?php

declare(strict_types=1);

// LogScope must not write inside the app's transaction (#45). A log written
// there holds locks in log_entries until the app commits — Clear and
// logscope:prune wait on it, and a Clear spanning two levels can deadlock
// with it — and rolls back with the app, losing the logs that explain the
// rollback. See tests/TransactionTestCase.php for running this on
// Postgres/MySQL.

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use LogScope\Contracts\LogWriterInterface;
use LogScope\Jobs\WriteLogEntry;
use LogScope\Logging\LogScopeHandler;
use LogScope\LogScopeServiceProvider;
use LogScope\Models\LogEntry;
use LogScope\Services\FallbackWriter;
use LogScope\Services\LogBuffer;
use LogScope\Services\WriteFailureLogger;
use LogScope\Services\WriteGuard;
use Monolog\Level;
use Monolog\LogRecord;

beforeEach(function () {
    // Not RefreshDatabase: its wrapping transaction never commits, so nothing
    // deferred would ever be written. That is exactly why deferral is off in
    // the testing environment — these tests turn it back on.
    Artisan::call('migrate:fresh');

    LogScopeServiceProvider::resetBufferState();
    WriteGuard::reset();
    WriteFailureLogger::reset();
    FallbackWriter::reset();

    config([
        'logscope.write_mode' => 'sync',
        'logscope.defer_in_transactions' => true,
    ]);

    Schema::create('deferral_rows', function ($table) {
        $table->id();
        $table->string('note');
    });
});

afterEach(function () {
    // A test that fails mid-transaction would otherwise leave it open, and
    // the next migrate:fresh would wait on its locks — on MySQL that means
    // lock_wait_timeout, which defaults to a year.
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    Carbon::setTestNow();
    LogEntry::flushEventListeners();
    LogScopeHandler::didHandleCurrentLog();

    // A test that fails mid-buffer would otherwise leave entries in static
    // state for whichever file runs next in this process.
    LogBuffer::reset();
});

function loggedMessages(): array
{
    return LogEntry::query()->orderBy('occurred_at')->orderBy('id')->pluck('message')->all();
}

function loggedCount(): int
{
    return LogEntry::query()->count();
}

it('writes nothing until the transaction commits', function () {
    DB::beginTransaction();
    DB::table('deferral_rows')->insert(['note' => 'row']);
    Log::info('inside the transaction');

    // Same connection, so this would see LogScope's own uncommitted row.
    expect(loggedCount())->toBe(0);

    DB::commit();

    expect(loggedMessages())->toBe(['inside the transaction']);
});

it('keeps the logs when the transaction rolls back', function () {
    DB::beginTransaction();
    DB::table('deferral_rows')->insert(['note' => 'row']);
    Log::warning('this is why it rolled back');
    DB::rollBack();

    expect(loggedMessages())->toBe(['this is why it rolled back'])
        ->and(DB::table('deferral_rows')->count())->toBe(0);
});

it('records when the log happened, not when it was written', function () {
    Carbon::setTestNow('2026-09-21 10:00:00');

    DB::beginTransaction();
    Log::info('logged early in a long transaction');

    // Without this the test would pass on an immediate write too.
    expect(loggedCount())->toBe(0);

    Carbon::setTestNow('2026-09-21 10:05:00');
    DB::commit();

    expect(LogEntry::query()->value('occurred_at')->format('Y-m-d H:i:s'))
        ->toBe('2026-09-21 10:00:00');
});

it('stamps an entry that reaches the writer without a time of its own', function () {
    Carbon::setTestNow('2026-09-21 10:00:00');

    DB::beginTransaction();
    app(LogWriterInterface::class)->write(['level' => 'info', 'message' => 'no occurred_at']);

    expect(loggedCount())->toBe(0);

    Carbon::setTestNow('2026-09-21 10:05:00');
    DB::commit();

    expect(LogEntry::query()->value('occurred_at')->format('Y-m-d H:i:s'))
        ->toBe('2026-09-21 10:00:00');
});

it('defers the channel handler, which ignores write_mode', function () {
    DB::beginTransaction();
    (new LogScopeHandler)->handle(new LogRecord(new DateTimeImmutable, 'logscope', Level::Info, 'from the handler'));

    expect(loggedCount())->toBe(0);

    DB::commit();

    expect(loggedMessages())->toBe(['from the handler']);
});

it('defers a queued write and writes it when the transaction ends', function () {
    config(['logscope.write_mode' => 'queue', 'queue.default' => 'sync']);

    DB::beginTransaction();
    Log::info('queued inside the transaction');

    expect(loggedCount())->toBe(0);

    DB::commit();

    expect(loggedMessages())->toBe(['queued inside the transaction']);
});

it('flushes only once for a transaction that logged many times', function () {
    $inserts = 0;
    DB::listen(function ($query) use (&$inserts) {
        if (str_starts_with($query->sql, 'insert') && str_contains($query->sql, 'log_entries')) {
            $inserts++;
        }
    });

    DB::beginTransaction();
    Log::info('one');
    Log::info('two');
    Log::info('three');
    DB::commit();

    expect($inserts)->toBe(1)
        ->and(loggedCount())->toBe(3);
});

it('waits for the outermost transaction, not an inner one', function () {
    DB::beginTransaction();
    Log::info('logged at the outer level');

    DB::transaction(function () {
        DB::table('deferral_rows')->insert(['note' => 'inner']);
        Log::info('logged at the inner level');
    });

    // The inner commit fired TransactionCommitted, but the app is still in a
    // transaction: writing now would be the very thing this avoids.
    expect(loggedCount())->toBe(0);

    DB::commit();

    expect(loggedMessages())->toBe(['logged at the outer level', 'logged at the inner level']);
});

it('waits for the outermost transaction when an inner one rolls back', function () {
    DB::beginTransaction();
    Log::info('logged at the outer level');

    try {
        DB::transaction(function () {
            Log::warning('logged before the inner failure');

            throw new RuntimeException('inner work failed');
        });
    } catch (RuntimeException) {
        // The inner savepoint rolled back and fired TransactionRolledBack.
    }

    expect(loggedCount())->toBe(0);

    DB::commit();

    expect(loggedMessages())->toBe(['logged at the outer level', 'logged before the inner failure']);
});

it('does not break the app commit when the deferred flush fails', function () {
    // The flush now runs from inside the commit event, so a failure there
    // must not surface as an exception from the app's own DB::commit().
    DB::beginTransaction();
    DB::table('deferral_rows')->insert(['note' => 'row']);
    Log::info('this entry cannot be written');

    Schema::drop('log_entries');

    expect(fn () => DB::commit())->not->toThrow(Throwable::class)
        ->and(DB::table('deferral_rows')->count())->toBe(1);
});

it('does not defer a queued write that never touches the app connection', function () {
    // A broker driver's dispatch is unaffected by the app's transaction, so
    // deferring it would only cost the dispatch its durability.
    config(['logscope.write_mode' => 'queue', 'queue.default' => 'null']);

    Queue::fake();

    DB::beginTransaction();
    Log::info('dispatched, not deferred');
    DB::commit();

    Queue::assertPushed(WriteLogEntry::class);
    expect(LogBuffer::getBuffer())->toBe([]);
});

it('leaves a batch buffer alone when an unrelated transaction commits', function () {
    config(['logscope.write_mode' => 'batch']);

    Log::info('batched outside any transaction');

    DB::transaction(fn () => DB::table('deferral_rows')->insert(['note' => 'row']));

    // The batch buffer waits for the end of the process as it always has.
    expect(loggedCount())->toBe(0);

    LogBuffer::flushStatic();

    expect(loggedMessages())->toBe(['batched outside any transaction']);
});

it('writes inside the transaction once the buffer hits its cap', function () {
    // Cap is ten times max_entries, so 20 entries here.
    config(['logscope.batch.max_entries' => 2]);

    DB::beginTransaction();

    foreach (range(1, 19) as $i) {
        Log::info("entry {$i}");
    }

    // Well past max_entries, and still nothing written.
    expect(loggedCount())->toBe(0);

    Log::info('entry 20');

    // Bounded memory wins over a transaction-safe write at this point: the
    // rows go in inside the transaction, each isolated in a savepoint (#40).
    expect(loggedCount())->toBe(20);

    DB::commit();

    expect(loggedCount())->toBe(20);
});

it('drains the buffer from the safety-net flush when a transaction never ends', function () {
    DB::beginTransaction();
    Log::info('the process ends before the transaction does');

    expect(loggedCount())->toBe(0);

    // Stand-in for the terminate/shutdown/queue-worker flushes.
    LogBuffer::flushStatic();

    expect(loggedMessages())->toBe(['the process ends before the transaction does'])
        ->and(LogBuffer::getBuffer())->toBe([]);

    // The limit worth being honest about: the safety net bounds what is held
    // in memory, but its insert joins the transaction that is still open. A
    // process that dies before committing loses the row either way — the same
    // as it did before deferral existed.
    DB::rollBack();

    expect(loggedCount())->toBe(0);
});

it('lets another connection delete log rows while the app transaction is open', function () {
    // The reason for all of this: with a log row uncommitted in the app's
    // transaction, a Clear covering two levels waits on it until
    // innodb_lock_wait_timeout, and can deadlock with it outright (#45, #46).
    LogEntry::insert(array_map(fn ($i) => LogEntry::prepareData([
        'level' => $i % 2 === 0 ? 'info' : 'warning',
        'message' => "existing {$i}",
        'channel' => 'single',
    ]), range(1, 200)));

    $other = secondConnection();

    DB::beginTransaction();
    DB::table('deferral_rows')->insert(['note' => 'row']);
    Log::warning('logged inside the transaction');
    Log::info('logged inside the transaction too');

    // Times out instead of hanging if a log row is locked by the app.
    $other->exec("DELETE FROM log_entries WHERE level IN ('info', 'warning')");

    DB::commit();

    expect(loggedMessages())->toBe(['logged inside the transaction', 'logged inside the transaction too']);
})->skip(
    fn () => ! in_array(DB::getDriverName(), ['mysql', 'pgsql'], true),
    'Needs a real engine with row locks'
);

/**
 * A second session that fails fast rather than waiting out the engine's
 * default lock timeout, so a locked log row shows up as a failure.
 */
function secondConnection(): PDO
{
    $driver = DB::getDriverName();
    $config = config("database.connections.{$driver}");

    $pdo = new PDO(
        "{$driver}:host={$config['host']};port={$config['port']};dbname={$config['database']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );

    $pdo->exec($driver === 'mysql'
        ? 'SET SESSION innodb_lock_wait_timeout = 3'
        : "SET lock_timeout = '3s'");

    return $pdo;
}
