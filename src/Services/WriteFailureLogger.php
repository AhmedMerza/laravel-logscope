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
 * gauge severity.
 *
 * Also writes a breadcrumb to the cache so the LogScope UI can show an
 * in-banner warning to operators who might not check server logs. The
 * cache write is best-effort: if cache is unavailable, error_log alone
 * is the safety net.
 */
class WriteFailureLogger
{
    /**
     * Emit every Nth occurrence of an already-seen failure as a summary.
     */
    private const SUMMARY_EVERY = 100;

    /**
     * Cache key prefix for breadcrumbs.
     */
    private const CACHE_PREFIX = 'logscope:write_failures:';

    /**
     * Cap the message we cache. QueryException messages can carry the
     * full SQL with bindings, which can be large. The banner UI only
     * shows a summary line — bound at 500 chars to keep cache writes
     * cheap and the index page payload small.
     */
    private const MAX_MESSAGE_LENGTH = 500;

    /**
     * Map of seen failure-key => occurrence count.
     */
    private static array $seen = [];

    /**
     * Report a write-path exception. Emits to error_log on the first
     * occurrence and every SUMMARY_EVERY occurrences thereafter, and
     * writes a cache breadcrumb so the UI can show a banner.
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
            self::writeBreadcrumb($e, $where);

            return;
        }

        $count = ++self::$seen[$key];
        self::writeBreadcrumb($e, $where);

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
     * Read the cached breadcrumb so the UI can show a banner. Returns
     * null when there's been no recent failure (or the cache is
     * unavailable). Best-effort — never throws into the UI render path.
     *
     * @return array{count: int, first_at: string, last_class: string, last_message: string, last_where: string, last_at: string}|null
     */
    public static function recentFailures(): ?array
    {
        try {
            if (! function_exists('app') || ! app()->bound('cache')) {
                return null;
            }

            $count = (int) (app('cache')->get(self::CACHE_PREFIX.'count') ?? 0);
            if ($count === 0) {
                return null;
            }

            $last = app('cache')->get(self::CACHE_PREFIX.'last');
            if (! is_array($last)) {
                return null;
            }

            return [
                'count' => $count,
                'first_at' => (string) (app('cache')->get(self::CACHE_PREFIX.'first_at') ?? ($last['at'] ?? '')),
                'last_class' => (string) ($last['class'] ?? 'Unknown'),
                'last_message' => (string) ($last['message'] ?? ''),
                'last_where' => (string) ($last['where'] ?? ''),
                'last_at' => (string) ($last['at'] ?? ''),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Clear the breadcrumb. Called by the UI's "dismiss" button on the
     * failure banner. The user has explicitly acknowledged the failures —
     * count, first/last timestamps, and message all reset.
     */
    public static function dismissFailures(): void
    {
        try {
            if (! function_exists('app') || ! app()->bound('cache')) {
                return;
            }

            app('cache')->forget(self::CACHE_PREFIX.'count');
            app('cache')->forget(self::CACHE_PREFIX.'first_at');
            app('cache')->forget(self::CACHE_PREFIX.'last');
        } catch (Throwable) {
            // best-effort
        }
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

    /**
     * Best-effort cache write of the latest failure. Wrapped in try/catch
     * because the cache service itself may be unavailable (it's the cache
     * we use, not THE cache — could be DB-backed and the DB is the thing
     * that just failed). If the write throws, we silently give up — the
     * error_log line is still the canonical record.
     *
     * Persistence:
     *   - Default (no `ttl_seconds` config): stored forever, until the
     *     user explicitly dismisses. A Saturday-night failure stays
     *     visible Monday morning.
     *   - With `logscope.failure_banner.ttl_seconds = N`: auto-expires
     *     after N seconds. Use this if you prefer breadcrumbs to clear
     *     themselves once the issue stops recurring.
     *
     * Either way, `php artisan cache:clear` wipes the breadcrumb (cache
     * is not persistent storage). True persistence would need a dedicated
     * DB table — out of scope here.
     *
     * Tracks first-occurrence time and updates last-occurrence on every
     * report so the UI can show "N failures since [first_at], most recent
     * [last_at]".
     */
    private static function writeBreadcrumb(Throwable $e, string $where): void
    {
        try {
            if (! function_exists('app') || ! app()->bound('cache')) {
                return;
            }

            $cache = app('cache');
            $ttl = self::resolveBreadcrumbTtl();

            // Increment counter; on first write, also stash first_at so the
            // UI can show how long the issue has been ongoing.
            //
            // Concurrency note: the get-after-increment pattern below isn't
            // a check-and-set — it's intentional re-stamping. Two concurrent
            // writers both call increment() (atomic on Redis/DB/array), then
            // both re-put with the same fresh count. The race outcome is
            // "both writers store the same correct value", which is fine.
            // The forever() re-put is only there to defeat increment's
            // implicit auto-TTL on some cache drivers (older Memcached/file).
            $isFirst = ! $cache->has(self::CACHE_PREFIX.'count');
            $cache->increment(self::CACHE_PREFIX.'count');
            self::cachePutWithTtl(
                $cache,
                self::CACHE_PREFIX.'count',
                (int) $cache->get(self::CACHE_PREFIX.'count', 0),
                $ttl
            );

            if ($isFirst) {
                // Concurrency note: two concurrent first-failures may both
                // see has() === false and both write first_at. The second
                // write wins by milliseconds. Eventually-consistent — fine
                // for a banner that's about gauging severity, not forensics.
                self::cachePutWithTtl($cache, self::CACHE_PREFIX.'first_at', self::nowIso(), $ttl);
            }

            self::cachePutWithTtl($cache, self::CACHE_PREFIX.'last', [
                'class' => get_class($e),
                'message' => self::truncate($e->getMessage(), self::MAX_MESSAGE_LENGTH),
                'where' => $where,
                'at' => self::nowIso(),
            ], $ttl);
        } catch (Throwable) {
            // best-effort: error_log is the reliable signal
        }
    }

    /**
     * Current time as an ISO-8601 string, using Laravel's now() helper
     * so test time-travel (Carbon::setTestNow / $this->travel*) is
     * respected. Falls back to PHP's date('c') if helpers aren't
     * available (e.g. very-late shutdown after container teardown).
     */
    private static function nowIso(): string
    {
        try {
            if (function_exists('now')) {
                return now()->toIso8601String();
            }
        } catch (Throwable) {
            // fall through
        }

        return date('c');
    }

    /**
     * Resolve the configured TTL. Returns null (forever) when the config
     * key is missing, null, or non-numeric. Otherwise returns the TTL in
     * seconds as an int.
     */
    private static function resolveBreadcrumbTtl(): ?int
    {
        if (! function_exists('config')) {
            return null;
        }

        $configured = config('logscope.failure_banner.ttl_seconds');

        if ($configured === null || $configured === '' || ! is_numeric($configured)) {
            return null;
        }

        $ttl = (int) $configured;

        return $ttl > 0 ? $ttl : null;
    }

    /**
     * Store a value either forever (TTL null) or with the given lifetime.
     * Wraps the difference between Cache::forever() and Cache::put() so
     * the writeBreadcrumb call sites stay readable.
     */
    private static function cachePutWithTtl(mixed $cache, string $key, mixed $value, ?int $ttl): void
    {
        if ($ttl === null) {
            $cache->forever($key, $value);

            return;
        }

        $cache->put($key, $value, $ttl);
    }

    /**
     * Truncate a string to $max bytes, appending an indicator when cut.
     */
    private static function truncate(string $value, int $max): string
    {
        if (mb_strlen($value) <= $max) {
            return $value;
        }

        return mb_substr($value, 0, $max).'… [truncated]';
    }
}
