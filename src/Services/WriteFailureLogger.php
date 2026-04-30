<?php

declare(strict_types=1);

namespace LogScope\Services;

use Throwable;

/**
 * Surfaces LogScope's own write failures to PHP's error log, with
 * per-process deduplication so a sustained DB outage doesn't dump
 * thousands of identical lines into php-fpm's stderr.
 *
 * Each unique (exception class + message) is emitted once per process,
 * with a follow-up summary every Nth occurrence so the user can still
 * gauge severity. Once the underlying issue is fixed and a different
 * (or new) error class fires, that one is emitted afresh.
 */
class WriteFailureLogger
{
    /**
     * Emit every Nth occurrence of an already-seen failure as a summary.
     */
    private const SUMMARY_EVERY = 100;

    /**
     * Map of seen failure-key => occurrence count.
     */
    private static array $seen = [];

    /**
     * Report a write-path exception. Emits to error_log on the first
     * occurrence and every SUMMARY_EVERY occurrences thereafter.
     *
     * The dedupe key is exception class + throw site (file:line), which
     * is stable across calls. The exception's message often contains
     * variable data (e.g. QueryException embeds the parameter-substituted
     * SQL with a fresh ULID per call), so message-based dedupe wouldn't
     * actually catch repeated identical failures.
     *
     * @param  string  $where  Short label for the call site, e.g. "listener" or "channel-handler".
     */
    public static function report(Throwable $e, string $where = ''): void
    {
        $key = get_class($e).'@'.$e->getFile().':'.$e->getLine();
        $prefix = $where !== '' ? "LogScope[{$where}]" : 'LogScope';

        if (! isset(self::$seen[$key])) {
            self::$seen[$key] = 1;
            error_log($prefix.': Failed to write log entry: ['.get_class($e).'] '.$e->getMessage());

            return;
        }

        $count = ++self::$seen[$key];
        if ($count % self::SUMMARY_EVERY === 0) {
            error_log($prefix.": same failure has now occurred {$count} times: [".get_class($e).'] '.$e->getMessage());
        }
    }

    /**
     * Emit a one-shot informational message (used for buffer-discard
     * notifications, which are not exception-driven). No dedupe — these
     * should be rare (once per PHP shutdown).
     */
    public static function notify(string $message): void
    {
        error_log('LogScope: '.$message);
    }

    /**
     * Reset the dedupe map. Used by tests, and by long-running workers
     * (Octane) that want to reset rate-limiting per request boundary.
     */
    public static function reset(): void
    {
        self::$seen = [];
    }

    /**
     * @internal for tests
     */
    public static function seenCount(string $exceptionClass, string $file, int $line): int
    {
        $key = $exceptionClass.'@'.$file.':'.$line;

        return self::$seen[$key] ?? 0;
    }
}
