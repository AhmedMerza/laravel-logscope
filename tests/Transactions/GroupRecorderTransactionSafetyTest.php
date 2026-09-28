<?php

declare(strict_types=1);

// GroupRecorder::retrying() must not retry while already inside an app
// transaction (#112): on MySQL a deadlock kills the *whole* transaction, not
// just the statement, so a "successful" retry there writes standalone,
// outside the transaction the app still believes it's in. Confirmed by
// forcing a real deadlock inside GroupRecorder::insertMissing() (log_groups)
// before the fix landed: the retry left an orphaned log_groups row even
// though the app's own transaction — and the log_entries row written moments
// earlier in it — were rolled back. Mirrors the technique in
// TransactionSavepointTest's real-MySQL-deadlock test, targeting log_groups
// instead of log_entries.

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use LogScope\Models\LogEntry;
use LogScope\Models\LogGroup;

beforeEach(function () {
    Artisan::call('migrate:fresh');

    config(['logscope.write_mode' => 'sync']);

    Schema::create('app_rows', function ($table) {
        $table->id();
        $table->string('note');
    });
});

function appRowNotesForGroupDeadlock(): array
{
    return DB::table('app_rows')->orderBy('id')->pluck('note')->all();
}

it('does not leave an orphaned log_groups row when a real MySQL deadlock kills the app transaction', function () {
    DB::statement('CREATE TABLE locks (id INT PRIMARY KEY) ENGINE=InnoDB');
    DB::statement('INSERT INTO locks VALUES (1), (2)');
    DB::statement('CREATE TABLE ballast (id INT AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB');
    // Only the insert made while @lock_on_group_insert is set takes the
    // lock, mirroring TransactionSavepointTest's log_entries trigger.
    DB::unprepared('CREATE TRIGGER group_insert_locks BEFORE INSERT ON log_groups FOR EACH ROW BEGIN IF @lock_on_group_insert = 1 THEN SET @lock_on_group_insert = 0; SELECT id INTO @locked FROM locks WHERE id = 2 FOR UPDATE; END IF; END');

    $config = config('database.connections.mysql');
    $other = new mysqli($config['host'], $config['username'], $config['password'], $config['database'], (int) $config['port']);

    // The other transaction holds row 2 and does more work than the app's,
    // so InnoDB picks the app's transaction as the deadlock victim.
    $other->query('START TRANSACTION');
    $other->query('INSERT INTO ballast VALUES '.implode(',', array_fill(0, 50, '()')));
    $other->query('SELECT id FROM locks WHERE id = 2 FOR UPDATE');

    DB::statement('SET SESSION innodb_lock_wait_timeout = 5');

    DB::beginTransaction();
    DB::table('app_rows')->insert(['note' => 'before']);
    DB::select('SELECT id FROM locks WHERE id = 1 FOR UPDATE');
    $other->query('SELECT id FROM locks WHERE id = 1 FOR UPDATE', MYSQLI_ASYNC);
    waitForGroupLockWait();
    DB::statement('SET @lock_on_group_insert = 1');

    // GroupRecorder::record() catches and reports its own failure (#29's
    // "must not roll back or discard the entries" contract) — the deadlock
    // must not propagate out of Log::info().
    expect(fn () => Log::info('this group insert deadlocks'))->not->toThrow(Throwable::class);

    $read = [$other];
    $write = $error = [];
    mysqli_poll($read, $write, $error, 5);
    $other->reap_async_query();
    $other->query('ROLLBACK');

    // The known limit (same as TransactionSavepointTest's log_entries case):
    // MySQL ended the transaction, so the app's row is gone and its own
    // commit reports it. Confirms the fix didn't mask that with a silent
    // commit — the risk code review raised.
    expect(fn () => DB::commit())->toThrow(PDOException::class, 'There is no active transaction')
        ->and(appRowNotesForGroupDeadlock())->toBe([])
        ->and(LogEntry::query()->count())->toBe(0)
        // The fix: retrying() refuses to retry while transactionLevel() > 0,
        // so insertMissing's deadlock propagates once (as it did before this
        // PR) instead of retrying into a standalone, unintended commit.
        ->and(LogGroup::query()->count())->toBe(0);

    DB::rollBack();
})->skip(fn () => DB::getDriverName() !== 'mysql', 'Needs MySQL');

function waitForGroupLockWait(): void
{
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
