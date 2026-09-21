<?php

declare(strict_types=1);

// Clear deletes in bounded chunks (#46). Each engine compiles a limited
// DELETE differently — MySQL runs `DELETE … LIMIT n` natively, Postgres
// rewrites it to `where ctid in (select … limit n)` and SQLite to the same
// over `rowid`. SQLite alone would not catch a rewrite that drops the
// filter, so this runs the loop on whichever engine LOGSCOPE_TEST_DB names.
// See tests/TransactionTestCase.php for pointing it at Postgres/MySQL.

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogScope\Models\LogEntry;

beforeEach(function () {
    Artisan::call('migrate:fresh');
});

function seedLevels(int $errors, int $survivors): void
{
    LogEntry::insert(array_map(fn ($i) => LogEntry::prepareData([
        'level' => 'error',
        'message' => "bulk {$i}",
        'channel' => 'single',
    ]), range(1, $errors)));

    if ($survivors < 1) {
        return;
    }

    LogEntry::insert(array_map(fn ($i) => LogEntry::prepareData([
        'level' => 'info',
        'message' => "survivor {$i}",
        'channel' => 'single',
    ]), range(1, $survivors)));
}

it('deletes only the filtered rows across several chunks on a real engine', function () {
    $errors = (LogEntry::DELETE_CHUNK_SIZE * 2) + 7;
    seedLevels($errors, 5);

    $deleted = LogEntry::deleteInChunks(LogEntry::query()->where('level', 'error'));

    expect($deleted)->toBe($errors)
        ->and(LogEntry::query()->count())->toBe(5)
        ->and(LogEntry::query()->where('level', 'error')->exists())->toBeFalse();
});

it('prunes past the retention cutoff and keeps the rows on the boundary', function () {
    // #46's second test bullet. `occurred_at < $cutoff` is exclusive, so a
    // row landing exactly on the cutoff must survive.
    $cutoff = now()->subDays(30);

    LogEntry::insert([
        LogEntry::prepareData([
            'level' => 'info', 'message' => 'older', 'channel' => 'single',
            'occurred_at' => $cutoff->copy()->subSecond(),
        ]),
        LogEntry::prepareData([
            'level' => 'info', 'message' => 'on the boundary', 'channel' => 'single',
            'occurred_at' => $cutoff->copy(),
        ]),
        LogEntry::prepareData([
            'level' => 'info', 'message' => 'newer', 'channel' => 'single',
            'occurred_at' => $cutoff->copy()->addSecond(),
        ]),
    ]);

    Artisan::call('logscope:prune', ['--days' => 30]);

    expect(LogEntry::query()->orderBy('occurred_at')->pluck('message')->all())
        ->toBe(['on the boundary', 'newer']);
});

it('empties the table when the query has no filter', function () {
    seedLevels(LogEntry::DELETE_CHUNK_SIZE + 2, 3);

    $deleted = LogEntry::deleteInChunks(LogEntry::query());

    expect($deleted)->toBe(LogEntry::DELETE_CHUNK_SIZE + 5)
        ->and(LogEntry::query()->count())->toBe(0);
});

it('leaves another session rows it has not committed, without waiting on them', function () {
    // #46's third test bullet, and the reason the delete goes via an id
    // list: deleting by the filter directly fails here with a lock wait
    // timeout, because the range delete waits on the uncommitted row. The
    // 3-second timeout on the second session turns a block into a failure
    // rather than a hang.
    //
    // The row count matters. Below roughly a few thousand rows InnoDB
    // prefers a full scan to primary-key lookups and waits again, so this
    // seeds enough rows to get the plan the fix relies on.
    seedLevels(5000, 0);

    $other = openSecondSession();
    $other->beginTransaction();
    $other->exec(sprintf(
        "INSERT INTO log_entries (id, level, message, channel, occurred_at, created_at)
         VALUES ('%s', 'error', 'uncommitted', 'single', '%s', '%s')",
        (string) Str::ulid(),
        $now = now()->toDateTimeString(),
        $now,
    ));

    $deleted = LogEntry::deleteInChunks(LogEntry::query()->where('level', 'error'));

    // Only the committed rows; the in-flight one was never visible.
    expect($deleted)->toBe(5000);

    $other->rollBack();

    expect(LogEntry::query()->count())->toBe(0);
})->skip(
    fn () => ! in_array(DB::getDriverName(), ['mysql', 'pgsql'], true),
    'Needs a real engine with row locks',
);

/**
 * A second session that fails fast rather than waiting out the engine's
 * default lock timeout, so a blocked delete shows up as a failure.
 */
function openSecondSession(): PDO
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
