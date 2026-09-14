<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use LogScope\Logging\ChannelContextProcessor;
use LogScope\Logging\LogScopeHandler;
use LogScope\LogScopeServiceProvider;
use LogScope\Models\LogEntry;
use Monolog\Level;
use Monolog\LogRecord;

uses(RefreshDatabase::class);

/*
 * #26: user_id was an unsigned big integer, so an app whose auth identifier
 * isn't an integer (UUID/ULID keys, ids like `admin_1`) had its inserts
 * rejected under MySQL strict mode — in batch mode, the whole chunk. SQLite
 * doesn't enforce column types or lengths, so these tests pin the schema and
 * the capture-time normalization rather than reproducing the MySQL error.
 */

beforeEach(function () {
    ChannelContextProcessor::clearLastChannel();
    LogScopeServiceProvider::resetBufferState();
    LogEntry::query()->delete();

    config(['logscope.write_mode' => 'batch']);
});

afterEach(function () {
    // Handler-path tests set this flag without a MessageLogged event to
    // consume it; left set, the listener would skip the next test's first log.
    LogScopeHandler::didHandleCurrentLog();
    LogScopeServiceProvider::resetBufferState();
});

function logAsUserWithId(string $path, mixed $id): ?LogEntry
{
    request()->setUserResolver(fn () => (object) ['id' => $id]);

    if ($path === 'listener') {
        Log::info('user id capture');
        LogScopeServiceProvider::flushLogBufferStatic();
    } else {
        (new LogScopeHandler)->handle(
            new LogRecord(new DateTimeImmutable, 'logscope', Level::Info, 'user id capture')
        );
    }

    return LogEntry::query()->where('message', 'user id capture')->first();
}

function userIdMigration(): object
{
    return require __DIR__.'/../../database/migrations/2026_09_14_000001_change_user_id_to_string_on_log_entries.php';
}

function expectMigratedUserIdColumn(): void
{
    expect(Schema::getColumnType('log_entries', 'user_id'))->toBe('varchar')
        ->and(Schema::hasColumn('log_entries', 'user_id_legacy'))->toBeFalse();

    // Canonical names, so later migrations can use dropIndex(['user_id']).
    $indexes = collect(Schema::getIndexes('log_entries'))->pluck('columns', 'name');
    expect($indexes['log_entries_user_id_index'] ?? null)->toBe(['user_id'])
        ->and($indexes['log_entries_user_id_occurred_at_index'] ?? null)->toBe(['user_id', 'occurred_at']);
}

it('stores user_id in a string column and keeps its indexes', function () {
    expectMigratedUserIdColumn();
});

it('converts existing integer ids when migrating', function () {
    $migration = userIdMigration();
    $migration->down();

    LogEntry::createEntry(['level' => 'info', 'message' => 'A', 'user_id' => 7]);
    LogEntry::createEntry(['level' => 'info', 'message' => 'B', 'user_id' => null]);

    $migration->up();

    expectMigratedUserIdColumn();
    expect(LogEntry::query()->orderBy('message')->pluck('user_id')->all())->toBe(['7', null]);
});

it('resumes a migration that was stopped during the copy', function () {
    $migration = userIdMigration();
    $migration->down();

    LogEntry::createEntry(['level' => 'info', 'message' => 'A', 'user_id' => 7]);
    LogEntry::createEntry(['level' => 'info', 'message' => 'B', 'user_id' => 8]);

    // The swap finished and A was copied before the run stopped.
    Schema::table('log_entries', function (Blueprint $table) {
        $table->dropIndex(['user_id']);
        $table->dropIndex(['user_id', 'occurred_at']);
        $table->renameColumn('user_id', 'user_id_legacy');
    });
    Schema::table('log_entries', function (Blueprint $table) {
        $table->string('user_id')->nullable()->index();
        $table->index(['user_id', 'occurred_at']);
    });
    DB::table('log_entries')->where('message', 'A')->update(['user_id' => '7']);
    LogEntry::createEntry(['level' => 'info', 'message' => 'C', 'user_id' => 'admin_1']);

    $migration->up();

    expectMigratedUserIdColumn();
    expect(LogEntry::query()->orderBy('message')->pluck('user_id')->all())->toBe(['7', '8', 'admin_1']);
});

it('names indexes the way Laravel does for any table prefix setting', function (string $prefix, ?bool $prefixIndexes) {
    // The MySQL and Postgres branches name indexes themselves; a mismatch
    // makes the MySQL index step drop an index that doesn't exist.
    $default = config('database.default');
    config([
        'database.connections.prefix_probe' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => $prefix, 'prefix_indexes' => $prefixIndexes],
        'database.default' => 'prefix_probe',
    ]);

    try {
        Schema::create('log_entries', function (Blueprint $table) {
            $table->string('user_id')->index();
        });
        $laravelName = collect(Schema::getIndexes('log_entries'))->pluck('name')->sole();

        $migration = userIdMigration();
        expect((new ReflectionMethod($migration, 'indexName'))->invoke($migration, 'log_entries', ['user_id']))
            ->toBe($laravelName);
    } finally {
        config(['database.default' => $default]);
        DB::purge('prefix_probe');
    }
})->with([
    'no prefix' => ['', true],
    'prefixed index names' => ['app_', true],
    'unprefixed index names' => ['app_', false],
]);

it('stores a non-integer user id as-is', function (string $path) {
    expect(logAsUserWithId($path, 'admin_1')?->user_id)->toBe('admin_1');
    LogEntry::query()->delete();

    $uuid = '9d4c1a52-3f0e-4b8e-9c1a-5b2f7e6d8a10';
    expect(logAsUserWithId($path, $uuid)?->user_id)->toBe($uuid);
})->with(['listener', 'handler']);

it('stores an integer or Stringable user id as a string', function (string $path) {
    expect(logAsUserWithId($path, 42)?->user_id)->toBe('42');
    LogEntry::query()->delete();

    $stringable = new class implements Stringable
    {
        public function __toString(): string
        {
            return '01J8ZK3Q7R5V2N6M4T9W0XYB1C';
        }
    };
    expect(logAsUserWithId($path, $stringable)?->user_id)->toBe('01J8ZK3Q7R5V2N6M4T9W0XYB1C');
})->with(['listener', 'handler']);

it('keeps the log but drops a user id the column cannot hold', function (string $path, mixed $id) {
    $entry = logAsUserWithId($path, $id);

    expect($entry)->not->toBeNull()
        ->and($entry->user_id)->toBeNull();
})->with(['listener', 'handler'])->with([
    'too long' => [str_repeat('a', 256)],
    'array' => [['id' => 1]],
]);

it('filters by a non-integer user id', function () {
    LogEntry::createEntry(['level' => 'info', 'message' => 'A', 'user_id' => 'admin_1']);
    LogEntry::createEntry(['level' => 'info', 'message' => 'B', 'user_id' => '42']);

    expect(LogEntry::userId('admin_1')->pluck('message')->all())->toBe(['A']);
});

it('binds a numeric user id filter as a string so the index is usable', function () {
    // An integer binding against a string column makes MySQL cast every row.
    expect(LogEntry::query()->userId(42)->getBindings())->toBe(['42']);
});
