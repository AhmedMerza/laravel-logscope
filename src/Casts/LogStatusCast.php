<?php

declare(strict_types=1);

namespace LogScope\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use LogScope\Enums\LogStatus;

/**
 * Casts the status column to a LogStatus when it is one of the four built in,
 * and leaves it a plain string when it is a custom status from config.
 *
 * The plain `LogStatus::class` cast this replaces threw a ValueError on read
 * for any custom status, so a config block the package documents — and which
 * `getValidStatuses()` happily accepted on write — produced a 500 the moment
 * the row was read back.
 *
 * Returning a union rather than forcing every status into the enum keeps the
 * common comparisons (`$log->status === LogStatus::Resolved`) working exactly
 * as before; only "is this closed?" needs to go through
 * {@see LogStatus::isClosedValue()}, which understands both shapes.
 *
 * @implements CastsAttributes<LogStatus|string|null, LogStatus|string|null>
 */
class LogStatusCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): LogStatus|string|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        return LogStatus::tryFrom((string) $value) ?? (string) $value;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof LogStatus ? $value->value : (string) $value;
    }
}
