<?php

declare(strict_types=1);

namespace LogScope\Tests;

/**
 * Runs tests/Transactions on a real database engine (#40).
 *
 * SQLite never aborts a transaction on a failed statement, so the bug these
 * tests guard only shows on Postgres and MySQL. The suite defaults to SQLite
 * so it always runs; point it at another engine with LOGSCOPE_TEST_DB and the
 * usual DB_* variables. The tests drop and recreate every table in that
 * database — use a throwaway one.
 *
 *   LOGSCOPE_TEST_DB=pgsql DB_PORT=5432 DB_DATABASE=logscope \
 *   DB_USERNAME=postgres DB_PASSWORD=secret vendor/bin/pest tests/Transactions
 */
abstract class TransactionTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        if ($driver = env('LOGSCOPE_TEST_DB')) {
            $app['config']->set('database.default', $driver);
        }
    }
}
