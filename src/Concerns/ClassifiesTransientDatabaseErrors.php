<?php

declare(strict_types=1);

namespace LogScope\Concerns;

use Illuminate\Database\QueryException;
use Throwable;

/**
 * SQLSTATE classes 08 (Connection Exception) and 40 (Transaction Rollback —
 * deadlock/serialization failure) are worth a retry; anything else is a
 * code/data problem a retry won't fix.
 */
trait ClassifiesTransientDatabaseErrors
{
    protected static function isTransientFailure(Throwable $e): bool
    {
        if (! $e instanceof QueryException) {
            return false;
        }

        $sqlState = (string) $e->getCode();

        return str_starts_with($sqlState, '08') || str_starts_with($sqlState, '40');
    }
}
