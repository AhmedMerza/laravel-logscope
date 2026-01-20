<?php

declare(strict_types=1);

namespace LogScope\Logging;

use Illuminate\Support\Facades\Context;
use LogScope\Models\LogEntry;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

class LogScopeHandler extends AbstractProcessingHandler
{
    protected bool $initialized = false;

    protected string $channel;

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
        // Prevent infinite loops - don't log our own operations
        if ($this->isInternalLog($record)) {
            return;
        }

        try {
            $this->ensureInitialized();

            // Get request context from Laravel Context (set by middleware)
            $requestContext = Context::get('logscope', []);

            LogEntry::createEntry([
                'level' => strtolower($record->level->name),
                'message' => $record->message,
                'context' => $this->sanitizeContext($record->context),
                'channel' => $record->channel,
                'environment' => app()->environment(),
                'source' => $this->extractSource($record),
                'source_line' => $this->extractSourceLine($record),
                'trace_id' => $requestContext['trace_id'] ?? null,
                'user_id' => $requestContext['user_id'] ?? null,
                'ip_address' => $requestContext['ip_address'] ?? null,
                'user_agent' => $requestContext['user_agent'] ?? null,
                'http_method' => $requestContext['http_method'] ?? null,
                'url' => $requestContext['url'] ?? null,
                'occurred_at' => $record->datetime,
            ]);
        } catch (Throwable $e) {
            // Silently fail - don't break the application if logging fails
            // Optionally log to a fallback channel
            if (config('app.debug')) {
                error_log('LogScope: Failed to write log entry: '.$e->getMessage());
            }
        }
    }

    /**
     * Check if this is an internal log that should be skipped.
     */
    protected function isInternalLog(LogRecord $record): bool
    {
        // Skip logs from our own namespace
        if (str_contains($record->message, 'LogScope')) {
            return true;
        }

        // Check context for LogScope markers
        if (isset($record->context['_logscope_internal'])) {
            return true;
        }

        return false;
    }

    /**
     * Ensure the database table exists.
     */
    protected function ensureInitialized(): void
    {
        if ($this->initialized) {
            return;
        }

        // Simple check - if the query fails, the table doesn't exist
        try {
            LogEntry::query()->limit(1)->count();
            $this->initialized = true;
        } catch (Throwable) {
            throw new \RuntimeException('LogScope tables not migrated. Run: php artisan migrate');
        }
    }

    /**
     * Sanitize context array for storage.
     */
    protected function sanitizeContext(array $context): array
    {
        return $this->sanitizeArray($context);
    }

    /**
     * Recursively sanitize array values.
     */
    protected function sanitizeArray(array $array, int $depth = 0): array
    {
        // Prevent infinite recursion
        if ($depth > 10) {
            return ['_truncated' => 'Max depth exceeded'];
        }

        $result = [];

        foreach ($array as $key => $value) {
            // Skip internal keys
            if (str_starts_with((string) $key, '_logscope')) {
                continue;
            }

            if (is_array($value)) {
                $result[$key] = $this->sanitizeArray($value, $depth + 1);
            } elseif (is_object($value)) {
                $result[$key] = $this->sanitizeObject($value);
            } elseif (is_resource($value)) {
                $result[$key] = '[Resource]';
            } elseif (is_string($value) && strlen($value) > 10000) {
                $result[$key] = substr($value, 0, 10000).'... [truncated]';
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Convert object to storable format.
     */
    protected function sanitizeObject(object $object): mixed
    {
        if ($object instanceof Throwable) {
            return [
                '_type' => 'exception',
                'class' => get_class($object),
                'message' => $object->getMessage(),
                'code' => $object->getCode(),
                'file' => $object->getFile(),
                'line' => $object->getLine(),
                'trace' => array_slice($object->getTrace(), 0, 10),
            ];
        }

        if ($object instanceof \DateTimeInterface) {
            return $object->format('Y-m-d H:i:s.u');
        }

        if ($object instanceof \Stringable) {
            return (string) $object;
        }

        if ($object instanceof \JsonSerializable) {
            return $object->jsonSerialize();
        }

        // For other objects, try to get public properties
        try {
            return [
                '_type' => 'object',
                'class' => get_class($object),
                'data' => get_object_vars($object),
            ];
        } catch (Throwable) {
            return '[Object: '.get_class($object).']';
        }
    }

    /**
     * Extract source file from log record.
     */
    protected function extractSource(LogRecord $record): ?string
    {
        // Check if exception is in context
        if (isset($record->context['exception']) && $record->context['exception'] instanceof Throwable) {
            return $record->context['exception']->getFile();
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
            return $record->context['exception']->getLine();
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
