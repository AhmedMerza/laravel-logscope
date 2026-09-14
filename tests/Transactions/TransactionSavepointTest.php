<?php

declare(strict_types=1);

// A failed LogScope write inside the app's DB transaction must not take the
// app's writes down with it (#40). On Postgres a failed statement aborts the
// whole transaction, so without a savepoint the app's commit silently rolls
// back. See tests/TransactionTestCase.php for running this on Postgres/MySQL.

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogScope\Logging\LogScopeHandler;
use LogScope\LogScopeServiceProvider;
use LogScope\Models\LogEntry;
use LogScope\Services\FallbackWriter;
use LogScope\Services\LogBuffer;
use LogScope\Services\WriteFailureLogger;
use LogScope\Services\WriteGuard;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Component\Uid\Ulid;

beforeEach(function () {
    // Not RefreshDatabase: its wrapping transaction would hide what the app's
    // commit really does. Not DatabaseMigrations: its rollback trips over a
    // package migration's down() (#41).
    Artisan::call('migrate:fresh');

    LogScopeServiceProvider::resetBufferState();
    WriteGuard::reset();
    WriteFailureLogger::reset();
    FallbackWriter::reset();

    config(['logscope.write_mode' => 'sync']);

    Schema::create('app_rows', function ($table) {
        $table->id();
        $table->string('note');
    });

    $this->errorLogFile = tempnam(sys_get_temp_dir(), 'logscope-savepoint-test-');
    $this->originalErrorLog = ini_get('error_log');
    ini_set('error_log', $this->errorLogFile);
});

afterEach(function () {
    Str::createUlidsNormally();
    LogEntry::flushEventListeners();
    LogScopeHandler::didHandleCurrentLog();

    ini_set('error_log', $this->originalErrorLog);
    @unlink($this->errorLogFile);
});

/**
 * Make the next LogScope insert fail in the database itself: store a row,
 * then hand its id to the next generated ULID so the insert hits a real
 * duplicate-key error. Later ULIDs are random, so a fallback row can land.
 */
function failNextLogInsert(): void
{
    $taken = new Ulid;

    LogEntry::createEntry(['id' => strtolower((string) $taken), 'level' => 'info', 'message' => 'holds the id']);

    Str::createUlidsUsingSequence([$taken]);
}

function appRowNotes(): array
{
    return DB::table('app_rows')->orderBy('id')->pluck('note')->all();
}

function fallbackRowCount(): int
{
    return LogEntry::query()->where('context', 'like', '%_logscope_write_failure%')->count();
}

it('keeps the app transaction when a sync write fails', function () {
    failNextLogInsert();

    DB::beginTransaction();
    DB::table('app_rows')->insert(['note' => 'before']);
    Log::info('this insert fails');
    DB::table('app_rows')->insert(['note' => 'after']);
    DB::commit();

    expect(appRowNotes())->toBe(['before', 'after'])
        ->and(fallbackRowCount())->toBe(1);
});

it('keeps the app transaction when a channel handler write fails', function () {
    failNextLogInsert();

    DB::beginTransaction();
    DB::table('app_rows')->insert(['note' => 'before']);
    (new LogScopeHandler)->handle(new LogRecord(new DateTimeImmutable, 'logscope', Level::Info, 'this insert fails'));
    DB::table('app_rows')->insert(['note' => 'after']);
    DB::commit();

    expect(appRowNotes())->toBe(['before', 'after']);
});

it('keeps the app transaction when a queued write runs on the sync driver', function () {
    config(['logscope.write_mode' => 'queue', 'queue.default' => 'sync']);
    failNextLogInsert();

    DB::beginTransaction();
    DB::table('app_rows')->insert(['note' => 'before']);
    Log::info('this insert fails');
    DB::table('app_rows')->insert(['note' => 'after']);
    DB::commit();

    expect(appRowNotes())->toBe(['before', 'after'])
        ->and(fallbackRowCount())->toBe(1);
});

it('keeps the app transaction when the batch buffer is flushed inside it', function () {
    config(['logscope.write_mode' => 'batch']);
    failNextLogInsert();

    DB::beginTransaction();
    DB::table('app_rows')->insert(['note' => 'before']);
    Log::info('this insert fails');
    LogBuffer::flushStatic();
    DB::table('app_rows')->insert(['note' => 'after']);
    DB::commit();

    expect(appRowNotes())->toBe(['before', 'after'])
        ->and(fallbackRowCount())->toBe(1);
});

it('keeps successful writes made inside the transaction', function () {
    DB::transaction(function () {
        DB::table('app_rows')->insert(['note' => 'row']);
        Log::info('first');
        Log::info('second');
    });

    expect(appRowNotes())->toBe(['row'])
        ->and(LogEntry::query()->pluck('message')->sort()->values()->all())->toBe(['first', 'second']);
});

it('keeps the app transaction when the database queue cannot store the job', function () {
    // Laravel's default queue driver, with no jobs table to insert into.
    config(['logscope.write_mode' => 'queue', 'queue.default' => 'database']);
    Schema::dropIfExists('jobs');

    DB::beginTransaction();
    DB::table('app_rows')->insert(['note' => 'before']);
    Log::info('this dispatch fails');
    DB::table('app_rows')->insert(['note' => 'after']);
    DB::commit();

    expect(appRowNotes())->toBe(['before', 'after'])
        ->and(fallbackRowCount())->toBe(1);
});

it('keeps the app transaction when the failure breadcrumb cannot be cached', function () {
    // Laravel's default cache store, with no cache table to write into.
    config(['cache.default' => 'database']);
    Schema::dropIfExists('cache');
    failNextLogInsert();

    DB::beginTransaction();
    DB::table('app_rows')->insert(['note' => 'before']);
    Log::info('this insert fails, and so does its breadcrumb');
    DB::table('app_rows')->insert(['note' => 'after']);
    DB::commit();

    expect(appRowNotes())->toBe(['before', 'after'])
        ->and(fallbackRowCount())->toBe(1);
});

it('rethrows to the app when the write ends its transaction, so DB::transaction retries', function (string $path) {
    // Stand-in for a MySQL deadlock: the database ends the whole transaction
    // during LogScope's first insert, and the savepoint goes with it.
    $ended = false;
    DB::connection()->beforeExecuting(function (string $query) use (&$ended) {
        if ($ended || ! str_starts_with($query, 'insert') || ! str_contains($query, 'log_entries')) {
            return;
        }

        $ended = true;
        DB::getPdo()->exec('ROLLBACK');

        throw new RuntimeException('Deadlock found when trying to get lock; try restarting transaction');
    });

    $attempts = 0;

    DB::transaction(function () use (&$attempts, $path) {
        $attempts++;
        DB::table('app_rows')->insert(['note' => "attempt {$attempts}"]);
        logThrough($path, 'logged in the transaction');
    }, attempts: 2);

    expect($attempts)->toBe(2)
        ->and(appRowNotes())->toBe(['attempt 2'])
        ->and(LogEntry::query()->pluck('message')->all())->toBe(['logged in the transaction'])
        ->and(fallbackRowCount())->toBe(0);
})->with(['listener', 'channel handler', 'queue job', 'batch flush']);

/**
 * Log one message through a single LogScope write path, all the way to the
 * database insert.
 */
function logThrough(string $path, string $message): void
{
    if ($path === 'channel handler') {
        (new LogScopeHandler)->handle(new LogRecord(new DateTimeImmutable, 'logscope', Level::Info, $message));

        return;
    }

    config([
        'logscope.write_mode' => match ($path) {
            'listener' => 'sync',
            'queue job' => 'queue',
            'batch flush' => 'batch',
        },
        'queue.default' => 'sync',
    ]);

    Log::info($message);

    if ($path === 'batch flush') {
        LogBuffer::flushStatic();
    }
}

it('does not throw when the app logs its own failed statement before rolling back', function () {
    DB::table('app_rows')->insert(['id' => 1, 'note' => 'existing']);

    DB::beginTransaction();

    try {
        // On Postgres this aborts the transaction before LogScope runs.
        DB::table('app_rows')->insert(['id' => 1, 'note' => 'duplicate']);
    } catch (QueryException $e) {
        Log::error('the app logs its own failure', ['exception' => $e]);
        DB::rollBack();
    }

    expect(DB::transactionLevel())->toBe(0)
        ->and(appRowNotes())->toBe(['existing']);
});

it('does not throw when the app logs after a deadlock has already ended its transaction', function () {
    failNextLogInsert();

    DB::beginTransaction();
    // The app's own statement deadlocked: MySQL ended the transaction, but
    // Laravel still counts it until the app calls rollBack().
    DB::getPdo()->exec('ROLLBACK');

    Log::error('the app logs its deadlock; this insert also fails');

    expect(fallbackRowCount())->toBe(1);

    DB::rollBack();
})->skip(fn () => DB::getDriverName() !== 'mysql', 'Only MySQL ends a transaction on its own');

it('rethrows a real MySQL deadlock on the log insert so DB::transaction retries', function () {
    DB::statement('CREATE TABLE locks (id INT PRIMARY KEY) ENGINE=InnoDB');
    DB::statement('INSERT INTO locks VALUES (1), (2)');
    DB::statement('CREATE TABLE ballast (id INT AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB');
    DB::unprepared('CREATE TRIGGER log_insert_locks BEFORE INSERT ON log_entries FOR EACH ROW SELECT id INTO @locked FROM locks WHERE id = 2 FOR UPDATE');

    $config = config('database.connections.mysql');
    $other = new mysqli($config['host'], $config['username'], $config['password'], $config['database'], (int) $config['port']);

    // The other transaction holds row 2 and does more work than the app's,
    // so InnoDB picks the app's transaction as the deadlock victim.
    $other->query('START TRANSACTION');
    $other->query('INSERT INTO ballast VALUES '.implode(',', array_fill(0, 50, '()')));
    $other->query('SELECT id FROM locks WHERE id = 2 FOR UPDATE');

    $attempts = 0;

    // Fail fast instead of InnoDB's 50s default if the test goes wrong.
    DB::statement('SET SESSION innodb_lock_wait_timeout = 5');

    DB::transaction(function () use (&$attempts, $other) {
        $attempts++;

        if ($attempts === 1) {
            DB::table('app_rows')->insert(['note' => 'attempt 1']);
            DB::select('SELECT id FROM locks WHERE id = 1 FOR UPDATE');
            $other->query('SELECT id FROM locks WHERE id = 1 FOR UPDATE', MYSQLI_ASYNC);
            waitForLockWait();
        } else {
            // The deadlock let the other session take row 1; finish it so the
            // retry isn't blocked.
            $read = [$other];
            $write = $error = [];
            mysqli_poll($read, $write, $error, 5);
            $other->reap_async_query();
            $other->query('ROLLBACK');
            DB::table('app_rows')->insert(['note' => 'attempt 2']);
        }

        Log::info("logged on attempt {$attempts}");
    }, attempts: 2);

    expect($attempts)->toBe(2)
        ->and(appRowNotes())->toBe(['attempt 2'])
        ->and(LogEntry::query()->pluck('message')->all())->toBe(['logged on attempt 2']);
})->skip(fn () => DB::getDriverName() !== 'mysql', 'Needs MySQL');

function waitForLockWait(): void
{
    // A separate session, so the check doesn't run inside the app's transaction.
    $config = config('database.connections.mysql');
    $monitor = new PDO("mysql:host={$config['host']};port={$config['port']}", $config['username'], $config['password']);

    for ($i = 0; $i < 100; $i++) {
        if ($monitor->query("SELECT COUNT(*) FROM information_schema.innodb_trx WHERE trx_state = 'LOCK WAIT'")->fetchColumn() > 0) {
            return;
        }

        usleep(50_000);
    }

    throw new RuntimeException('The other transaction never blocked');
}
