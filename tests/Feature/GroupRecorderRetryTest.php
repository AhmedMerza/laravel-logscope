<?php

declare(strict_types=1);

// GroupRecorder self-reported a production deadlock (SQLSTATE 40001) on the
// insertOrIgnore into log_groups — a known InnoDB shape for a batched insert
// keyed on a unique index under concurrent writers. These tests pin the
// retry/classification logic that responds to it. A real deadlock isn't
// reproducible in a fast unit test, so this drives GroupRecorder's private
// retrying() directly with a constructed QueryException, the same technique
// WriteFailureFallbackTest uses for isTransientFailure().

use Illuminate\Database\QueryException;
use LogScope\Services\GroupRecorder;

function queryExceptionWithSqlState(string $sqlState): QueryException
{
    $pdo = new class($sqlState) extends PDOException
    {
        public function __construct(string $sqlState)
        {
            parent::__construct('simulated driver error');
            $this->code = $sqlState;
        }
    };

    return new QueryException('mysql', 'insert ignore into log_groups ...', [], $pdo);
}

function callRetrying(callable $statement): void
{
    $method = new ReflectionMethod(GroupRecorder::class, 'retrying');
    $method->setAccessible(true);
    $method->invoke(null, $statement);
}

it('retries a statement that fails with a transient SQLSTATE', function () {
    $attempts = 0;

    callRetrying(function () use (&$attempts) {
        $attempts++;

        if ($attempts < 3) {
            throw queryExceptionWithSqlState('40001');
        }
    });

    expect($attempts)->toBe(3);
});

it('gives up after exhausting retries on a persistent deadlock', function () {
    $attempts = 0;

    expect(function () use (&$attempts) {
        callRetrying(function () use (&$attempts) {
            $attempts++;
            throw queryExceptionWithSqlState('40001');
        });
    })->toThrow(QueryException::class);

    expect($attempts)->toBe(3);
});

it('does not retry a non-transient SQLSTATE', function () {
    $attempts = 0;

    expect(function () use (&$attempts) {
        callRetrying(function () use (&$attempts) {
            $attempts++;
            throw queryExceptionWithSqlState('42S02');
        });
    })->toThrow(QueryException::class);

    expect($attempts)->toBe(1);
});
