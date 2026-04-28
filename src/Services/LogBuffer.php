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
     * Whether terminating callback is registered.
     */
    protected static bool $terminatingRegistered = false;

    /**
     * Whether shutdown function is registered.
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

        $this->registerCallbacks();
    }

    /**
     * Register terminating and shutdown callbacks if not already registered.
     */
    protected function registerCallbacks(): void
    {
        // Register terminating callback once (for normal Laravel lifecycle)
        if (! self::$terminatingRegistered) {
            self::$terminatingRegistered = true;

            $this->app->terminating(function () {
                $this->flush();
            });
        }

        // Register shutdown function as backup (for exit/die scenarios)
        // This runs at the END of PHP execution, even after exit() or die()
        if (! self::$shutdownRegistered) {
            self::$shutdownRegistered = true;

            register_shutdown_function(function () {
                self::flushStatic();
            });
        }
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
        // gracefully instead of emitting confusing error messages.
        try {
            if (! app()->bound('db')) {
                self::$buffer = [];

                return;
            }
        } catch (Throwable) {
            self::$buffer = [];

            return;
        }

        // Take the current buffer and clear it immediately
        // This prevents re-processing the same logs if flush is called again
        $logsToFlush = self::$buffer;
        self::$buffer = [];

        try {
            $limits = self::$cachedLimits ?: config('logscope.limits', []);
            $rows = array_map(fn ($data) => LogEntry::prepareData($data, $limits), $logsToFlush);

            foreach (array_chunk($rows, 500) as $chunk) {
                try {
                    LogEntry::insert(self::normalizeChunk($chunk));
                } catch (Throwable $e) {
                    error_log('LogScope: Failed to flush log buffer chunk: ['.get_class($e).'] '.$e->getMessage());
                }
            }
        } catch (Throwable $e) {
            error_log('LogScope: Failed to flush log buffer: ['.get_class($e).'] '.$e->getMessage());
        }
    }

    /**
     * Reset the buffer state (used for testing).
     */
    public static function reset(): void
    {
        self::$buffer = [];
        self::$cachedLimits = [];
        self::$terminatingRegistered = false;
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
