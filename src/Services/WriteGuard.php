<?php

declare(strict_types=1);

namespace LogScope\Services;

/**
 * Re-entrant guard that prevents the MessageLogged listener (and the
 * channel handler) from re-capturing logs that fire DURING LogScope's
 * own write path. Without this, a user observer on log_entries that
 * itself logs, or a query listener that logs each query, would cause
 * the listener to recurse on every insert.
 *
 * Implemented as a counter so nested writes still un-guard correctly
 * when the outermost frame finishes.
 */
class WriteGuard
{
    private static int $depth = 0;

    public static function isWriting(): bool
    {
        return self::$depth > 0;
    }

    /**
     * Run the given callback inside the guard. Logs fired from anywhere
     * in the call tree of $write are skipped by the listener/handler.
     *
     * @template T
     *
     * @param  callable(): T  $write
     * @return T
     */
    public static function during(callable $write): mixed
    {
        self::$depth++;
        try {
            return $write();
        } finally {
            self::$depth--;
        }
    }

    /**
     * Reset the depth counter. Used only by tests after an unhandled
     * exception inside `during()` could otherwise leave the depth > 0.
     */
    public static function reset(): void
    {
        self::$depth = 0;
    }
}
