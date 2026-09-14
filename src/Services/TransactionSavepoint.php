<?php

declare(strict_types=1);

namespace LogScope\Services;

use LogScope\Models\LogEntry;
use Throwable;

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
 * A log call still never throws. The one case a savepoint can't cover is a
 * database that has already ended the app's transaction — a MySQL deadlock on
 * the log insert, or a lost connection. Rolling back to the savepoint then
 * fails too; the original error goes to the caller's catch as usual, and the
 * app's own commit reports the ended transaction.
 */
class TransactionSavepoint
{
    private const NAME = 'logscope';

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
        $pdo->exec($grammar->compileSavepoint(self::NAME));

        try {
            return $write();
        } catch (Throwable $e) {
            try {
                $pdo->exec($grammar->compileSavepointRollBack(self::NAME));
            } catch (Throwable) {
                // The transaction is already gone; report the write's own error.
            }

            throw $e;
        }
    }
}
