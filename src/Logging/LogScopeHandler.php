<?php

declare(strict_types=1);

namespace LogScope\Logging;

use Illuminate\Support\Facades\Context;
use LogScope\Concerns\ResolvesExceptionSource;
use LogScope\Contracts\ContextSanitizerInterface;
use LogScope\LogScope;
use LogScope\Models\LogEntry;
use LogScope\Services\TransactionSavepoint;
use LogScope\Services\WriteGuard;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

class LogScopeHandler extends AbstractProcessingHandler
{
    use ResolvesExceptionSource;

    protected bool $initialized = false;

    protected string $channel;

    /**
     * Flag to indicate the handler has captured a log.
     * Used to prevent duplicate captures when both the handler
     * and the MessageLogged listener are active.
     */
    protected static bool $handledCurrentLog = false;

    public function __construct(
        string $channel = 'logscope',
        int|string|Level $level = Level::Debug,
        bool $bubble = true
    ) {
        parent::__construct($level, $bubble);
        $this->channel = $channel;
    }

    /**
     * Write the log record to the database.
     */
    protected function write(LogRecord $record): void
    {
        // Re-entrant guard: skip logs emitted DURING our own write path.
        if (WriteGuard::isWriting()) {
            return;
        }

        // Prevent infinite loops - don't log our own operations
        if ($this->isInternalLog($record)) {
            return;
        }

        // Mark that we're handling this log (prevents duplicate capture by listener)
        static::$handledCurrentLog = true;

        WriteGuard::during(fn () => $this->writeRecord($record));
    }

    /**
     * Persist the captured record. Wrapped by write() inside WriteGuard
     * so a nested log fired during the insert is skipped.
     */
    protected function writeRecord(LogRecord $record): void
    {
        try {
            $this->ensureInitialized();

            // Get request context from Laravel Context (set by middleware)
            $requestContext = Context::get('logscope', []);

            // Get user_id at log-write time (after auth middleware has run)
            $userId = null;
            $customContext = [];
            if (app()->bound('request')) {
                $userId = LogEntry::normalizeUserId(request()->user()?->id);
                $customContext = LogScope::getCapturedContext(request());
            }

            TransactionSavepoint::around(fn () => LogEntry::createEntry([
                'level' => strtolower($record->level->name),
                'message' => $record->message,
                'context' => $this->sanitizeContext(array_merge($record->context, $customContext)),
                'channel' => $this->channel,
                'source' => $this->extractSource($record),
                'source_line' => $this->extractSourceLine($record),
                'trace_id' => $requestContext['trace_id'] ?? null,
                'user_id' => $userId,
                'ip_address' => $requestContext['ip_address'] ?? null,
                'user_agent' => $requestContext['user_agent'] ?? null,
                'http_method' => $requestContext['http_method'] ?? null,
                'url' => $requestContext['url'] ?? null,
                'headers' => $requestContext['headers'] ?? null,
                'occurred_at' => $record->datetime,
            ]));
        } catch (Throwable $e) {
            // Don't break the calling application, but always surface the
            // failure to PHP's error log. Hiding it behind APP_DEBUG meant
            // production DB outages caused silent total log loss with zero
            // observability. WriteFailureLogger dedupes per-process so a
            // sustained outage doesn't dump thousands of identical lines.
            \LogScope\Services\WriteFailureLogger::report($e, 'channel-handler');
        }
    }

    /**
     * Check if the handler captured the current log and reset the flag.
     *
     * This is used by the MessageLogged listener to avoid duplicates
     * when both the handler and listener are active.
     */
    public static function didHandleCurrentLog(): bool
    {
        $handled = static::$handledCurrentLog;
        static::$handledCurrentLog = false;

        return $handled;
    }

    /**
     * Check if this is an internal log that should be skipped.
     *
     * Only the structured `_logscope_internal` context key triggers the skip.
     * Substring matches on the message would silently drop legitimate user
     * logs that mention the package by name.
     */
    protected function isInternalLog(LogRecord $record): bool
    {
        return isset($record->context['_logscope_internal']);
    }

    /**
     * Ensure the database table exists.
     */
    protected function ensureInitialized(): void
    {
        if ($this->initialized) {
            return;
        }

        // Simple check - if the query fails, the table doesn't exist. It runs
        // inside the app's transaction too, so it needs the savepoint as well.
        try {
            TransactionSavepoint::around(fn () => LogEntry::query()->limit(1)->count());
            $this->initialized = true;
        } catch (Throwable) {
            throw new \RuntimeException('LogScope tables not migrated. Run: php artisan migrate');
        }
    }

    /**
     * Sanitize context array for storage.
     *
     * Delegates to the shared ContextSanitizer rather than carrying its own
     * copy. This handler used to duplicate the whole array/object walk, and
     * the copy had no redaction at all: with `capture => channel`, or any
     * stack containing the logscope channel (LogCapture defers via
     * didHandleCurrentLog()), a logged password or a Request's Authorization
     * header was stored in clear while the listener path redacted it (#77).
     *
     * Resolved per call rather than injected: the handler is constructed
     * directly by applications (`new LogScopeHandler('foo')` in the README),
     * so it cannot take constructor dependencies.
     */
    protected function sanitizeContext(array $context): array
    {
        return app(ContextSanitizerInterface::class)->sanitize($context);
    }

    /**
     * Extract source file from log record.
     */
    protected function extractSource(LogRecord $record): ?string
    {
        // Check if exception is in context
        if (isset($record->context['exception']) && $record->context['exception'] instanceof Throwable) {
            $exception = $record->context['exception'];
            $callerFrame = $this->callerFrameForException($exception);

            return $callerFrame['file'] ?? $exception->getFile();
        }

        // Try to find the caller from the backtrace
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15);

        foreach ($trace as $frame) {
            if (! isset($frame['file'])) {
                continue;
            }

            // Skip framework and this package files
            if (
                str_contains($frame['file'], '/vendor/monolog/') ||
                str_contains($frame['file'], '/vendor/laravel/framework/') ||
                str_contains($frame['file'], '/logscope/src/')
            ) {
                continue;
            }

            return $frame['file'];
        }

        return null;
    }

    /**
     * Extract source line from log record.
     */
    protected function extractSourceLine(LogRecord $record): ?int
    {
        // Check if exception is in context
        if (isset($record->context['exception']) && $record->context['exception'] instanceof Throwable) {
            $exception = $record->context['exception'];
            $callerFrame = $this->callerFrameForException($exception);

            return $callerFrame['line'] ?? $exception->getLine();
        }

        // Try to find the caller from the backtrace
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15);

        foreach ($trace as $frame) {
            if (! isset($frame['file'], $frame['line'])) {
                continue;
            }

            // Skip framework and this package files
            if (
                str_contains($frame['file'], '/vendor/monolog/') ||
                str_contains($frame['file'], '/vendor/laravel/framework/') ||
                str_contains($frame['file'], '/logscope/src/')
            ) {
                continue;
            }

            return $frame['line'];
        }

        return null;
    }

}
