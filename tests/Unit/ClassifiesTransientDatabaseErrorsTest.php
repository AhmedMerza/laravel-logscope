<?php

declare(strict_types=1);

// GroupRecorder and WriteLogEntry each exercise this trait only through their
// own narrow fixture choices (one SQLSTATE class apiece) — jointly complete,
// but nothing pins the trait's own full behavior directly. This is that test.

use Illuminate\Database\QueryException;
use LogScope\Concerns\ClassifiesTransientDatabaseErrors;

function classifierUnderTest(): object
{
    return new class
    {
        use ClassifiesTransientDatabaseErrors;

        public function check(Throwable $e): bool
        {
            return self::isTransientFailure($e);
        }
    };
}

function queryExceptionWithCode(string $sqlState): QueryException
{
    $pdo = new class($sqlState) extends PDOException
    {
        public function __construct(string $sqlState)
        {
            parent::__construct('simulated driver error');
            $this->code = $sqlState;
        }
    };

    return new QueryException('mysql', 'select 1', [], $pdo);
}

it('treats SQLSTATE class 08 (connection exception) as transient', function () {
    expect(classifierUnderTest()->check(queryExceptionWithCode('08006')))->toBeTrue();
});

it('treats SQLSTATE class 40 (transaction rollback) as transient', function () {
    expect(classifierUnderTest()->check(queryExceptionWithCode('40001')))->toBeTrue();
});

it('treats Postgres deadlock_detected (40P01) as transient', function () {
    expect(classifierUnderTest()->check(queryExceptionWithCode('40P01')))->toBeTrue();
});

it('does not treat Postgres in_failed_sql_transaction (25P02) as transient', function () {
    // Retrying without a ROLLBACK first can't help — the transaction is
    // already aborted, not merely contended.
    expect(classifierUnderTest()->check(queryExceptionWithCode('25P02')))->toBeFalse();
});

it('does not treat an unrelated SQLSTATE as transient', function () {
    expect(classifierUnderTest()->check(queryExceptionWithCode('42S02')))->toBeFalse();
});

it('does not treat a QueryException with no real SQLSTATE as transient', function () {
    expect(classifierUnderTest()->check(queryExceptionWithCode('0')))->toBeFalse();
});

it('does not treat a non-QueryException Throwable as transient', function () {
    expect(classifierUnderTest()->check(new RuntimeException('not a query exception')))->toBeFalse();
});
