<?php

declare(strict_types=1);

namespace LogScope\Services;

use Illuminate\Contracts\Foundation\Application;
use LogScope\Contracts\LogBufferInterface;
use LogScope\Models\LogEntry;
use Throwable;

/**
 * Manages buffered log writes for batch mode.
 *
 * Handles accumulating logs during request lifecycle and flushing
 * them at the end via terminating callback and shutdown function.
 */
class LogBuffer implements LogBufferInterface
{
    /**
     * Buffer for batch write mode.
     */
    protected static array $buffer = [];

    /**
     * Cached config limits so flushStatic() works after container teardown.
     */
    protected static array $cachedLimits = [];

    /**
     * Whether the PHP shutdown function has been registered for this
     * process. Stays true once set so re-registering the service provider
     * (e.g. in test suites that re-instantiate the app) doesn't stack
     * multiple shutdown functions calling our flush.
     */
    protected static bool $shutdownRegistered = false;

    public function __construct(
        protected Application $app
    ) {}

    /**
     * Add a log entry to the buffer.
     */
    public function add(array $data): void
    {
        // Cache config limits while the container is still alive
        self::$cachedLimits = config('logscope.limits', []);

        self::$buffer[] = $data;
    }

    /**
     * @internal — called by LogScopeServiceProvider to coordinate eager
     * shutdown-function registration so it happens at most once per
     * process even when the provider registers multiple times (e.g. in
     * test suites that re-instantiate the app).
     */
    public static function shutdownFunctionRegistered(): bool
    {
        return self::$shutdownRegistered;
    }

    /**
     * @internal — see shutdownFunctionRegistered().
     */
    public static function markShutdownFunctionRegistered(): void
    {
        self::$shutdownRegistered = true;
    }

    /**
     * Flush the buffer to the database.
     */
    public function flush(): void
    {
        self::flushStatic();
    }

    /**
     * Static method to flush the buffer (used by shutdown function).
     *
     * This can be called multiple times safely:
     * 1. First by Laravel's terminating callback (during normal request lifecycle)
     * 2. Then by PHP's shutdown function (catches logs from user's shutdown functions)
     */
    public static function flushStatic(): void
    {
        if (empty(self::$buffer)) {
            return;
        }

        // If the Laravel container is gone (e.g. after test teardown or during
        // PHP shutdown), we cannot resolve DB connections or config — bail out
        // gracefully. Surface the discard to error_log so a missing container
        // at flush time is visible in stderr/php-fpm logs instead of being a
        // silent data-loss event.
        try {
            if (! app()->bound('db')) {
                $count = count(self::$buffer);
                self::$buffer = [];
                $entryWord = $count === 1 ? 'entry' : 'entries';
                WriteFailureLogger::notify("Discarded {$count} buffered log {$entryWord} — container has no db binding (PHP shutdown or test teardown)");

                return;
            }
        } catch (Throwable $e) {
            $count = count(self::$buffer);
            self::$buffer = [];
            $entryWord = $count === 1 ? 'entry' : 'entries';
            WriteFailureLogger::notify("Discarded {$count} buffered log {$entryWord} — container unavailable: [".get_class($e).'] '.$e->getMessage());

            return;
        }

        // Take the current buffer and clear it immediately
        // This prevents re-processing the same logs if flush is called again
        $logsToFlush = self::$buffer;
        self::$buffer = [];

        // Guard against re-entry: an observer or query listener that fires
        // a log during the bulk insert would otherwise be re-captured by
        // LogCapture and added back to the buffer or written sync.
        WriteGuard::during(fn () => self::performFlush($logsToFlush));
    }

    /**
     * Bulk-insert the given rows in chunks of 500, with per-chunk error
     * isolation so one bad chunk doesn't lose the rest. Called only from
     * inside flushStatic's WriteGuard frame.
     *
     * @param  array<int, array<string, mixed>>  $logsToFlush
     */
    protected static function performFlush(array $logsToFlush): void
    {
        try {
            $limits = self::$cachedLimits ?: config('logscope.limits', []);
            $rows = array_map(fn ($data) => LogEntry::prepareData($data, $limits), $logsToFlush);

            foreach (array_chunk($rows, 500) as $chunk) {
                try {
                    LogEntry::insert(self::normalizeChunk($chunk));
                } catch (Throwable $e) {
                    WriteFailureLogger::report($e, 'buffer-flush');
                }
            }
        } catch (Throwable $e) {
            WriteFailureLogger::report($e, 'buffer-flush');
        }
    }

    /**
     * Reset the buffer state (used for testing).
     *
     * NOTE: clearing $shutdownRegistered means a subsequent service-provider
     * register() call WILL register a second register_shutdown_function for
     * this process. PHP shutdown handlers stack — both will fire at exit.
     * Both call the same flushSafely() closure, so the second is a no-op
     * (buffer already drained), but the duplicate registration is a small
     * test-time leak. Acceptable because resetBufferState() is only called
     * between tests, and the process exits soon after the suite finishes.
     */
    public static function reset(): void
    {
        self::$buffer = [];
        self::$cachedLimits = [];
        self::$shutdownRegistered = false;
    }

    /**
     * Get the current buffer contents (used for testing).
     */
    public static function getBuffer(): array
    {
        return self::$buffer;
    }

    /**
     * Ensure every row in a bulk insert chunk shares the same column set.
     *
     * Multi-row insert statements require identical columns per row; buffered
     * logs are sparse because optional fields are omitted when absent.
     *
     * @param  array<int, array<string, mixed>>  $chunk
     * @return array<int, array<string, mixed>>
     */
    protected static function normalizeChunk(array $chunk): array
    {
        $columns = [];

        foreach ($chunk as $row) {
            foreach (array_keys($row) as $column) {
                $columns[$column] = true;
            }
        }

        $normalized = [];

        foreach ($chunk as $row) {
            $normalizedRow = [];

            foreach (array_keys($columns) as $column) {
                $normalizedRow[$column] = array_key_exists($column, $row)
                    ? $row[$column]
                    : self::defaultValueForMissingColumn($column);
            }

            $normalized[] = $normalizedRow;
        }

        return $normalized;
    }

    /**
     * Preserve insert-safe defaults for sparse rows when a chunk introduces
     * optional columns that are non-nullable in the schema.
     */
    protected static function defaultValueForMissingColumn(string $column): mixed
    {
        return match ($column) {
            'is_truncated' => false,
            default => null,
        };
    }
}
