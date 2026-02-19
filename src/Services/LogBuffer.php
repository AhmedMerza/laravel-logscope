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

        // Take the current buffer and clear it immediately
        // This prevents re-processing the same logs if flush is called again
        $logsToFlush = self::$buffer;
        self::$buffer = [];

        try {
            foreach ($logsToFlush as $data) {
                LogEntry::createEntry($data);
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
}
