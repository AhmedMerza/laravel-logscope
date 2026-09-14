<?php

declare(strict_types=1);

namespace LogScope\Services;

use LogScope\Models\LogEntry;
use Throwable;
use WeakMap;

/**
 * Keeps a failed LogScope write from destroying the app's open transaction (#40).
 *
 * LogScope writes on the app's connection. On Postgres, one failed statement
 * aborts the whole transaction: LogScope catches the error, the app's
 * DB::commit() then silently rolls back, and the app's writes are lost.
 * Wrapping each write in a savepoint means a failure undoes only LogScope's
 * insert, and the app's transaction stays usable.
 *
 * The savepoint uses raw statements rather than a nested DB::transaction():
 * Laravel skips ROLLBACK TO SAVEPOINT on any deadlock error, which would leave
 * a Postgres transaction aborted. It is not released after a successful write
 * — measured on Postgres, RELEASE costs an extra round trip per write and
 * saves less than that at commit. Laravel doesn't release its savepoints either.
 *
 * When the savepoint can't be restored, the database has already ended the
 * app's transaction (a MySQL deadlock, a lost connection). Swallowing that
 * would let the app keep writing in autocommit mode and fail at commit with
 * "There is no active transaction", its data half committed. So the original
 * error is marked "lost" and every LogScope catch site rethrows it: the app
 * sees the deadlock where it happened, exactly as if its own query had hit it,
 * and DB::transaction($callback, $attempts) retries the closure.
 */
class TransactionSavepoint
{
    private const NAME = 'logscope';

    /**
     * Errors that ended the app's transaction. Weak so a long-running worker
     * doesn't hold every such exception for its lifetime.
     *
     * @var WeakMap<Throwable, true>|null
     */
    private static ?WeakMap $lost = null;

    /**
     * Run a LogScope write, inside a savepoint when a transaction is open.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $write
     * @return TReturn
     */
    public static function around(callable $write): mixed
    {
        $connection = (new LogEntry)->getConnection();
        $grammar = $connection->getQueryGrammar();

        if ($connection->transactionLevel() === 0 || ! $grammar->supportsSavepoints()) {
            return $write();
        }

        $pdo = $connection->getPdo();

        // A failure here is an ordinary write failure — e.g. the app's own
        // statement already aborted its Postgres transaction and it is now
        // logging that error. LogScope changed nothing, so nothing is lost.
        $pdo->exec($grammar->compileSavepoint(self::NAME));

        // Laravel still counts a transaction the server has already ended —
        // the app is logging its own MySQL deadlock before rolling back.
        // SAVEPOINT then succeeds without effect, and the write autocommits.
        if (! $pdo->inTransaction()) {
            return $write();
        }

        try {
            return $write();
        } catch (Throwable $e) {
            try {
                $pdo->exec($grammar->compileSavepointRollBack(self::NAME));
            } catch (Throwable) {
                self::$lost ??= new WeakMap;
                self::$lost[$e] = true;
            }

            throw $e;
        }
    }

    /**
     * Rethrow the error if it ended the app's transaction. Call first in every
     * catch block that would otherwise swallow a LogScope write failure.
     */
    public static function rethrowIfLost(Throwable $e): void
    {
        if (isset(self::$lost[$e])) {
            throw $e;
        }
    }
}
