<?php

declare(strict_types=1);

namespace LogScope\Services;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use LogScope\Contracts\ContextSanitizerInterface;
use LogScope\Contracts\LogWriterInterface;
use LogScope\Logging\ChannelContextProcessor;
use LogScope\Logging\LogScopeHandler;
use LogScope\LogScope;
use Throwable;

/**
 * Handles capturing logs from Laravel's log events.
 */
class LogCapture
{
    public function __construct(
        protected LogWriterInterface $writer,
        protected ContextSanitizerInterface $sanitizer
    ) {}

    /**
     * Register the global log listener for 'all' capture mode.
     */
    public function register(): void
    {
        if (config('logscope.capture', 'all') !== 'all') {
            return;
        }

        Event::listen(MessageLogged::class, function (MessageLogged $event) {
            $this->handleLogEvent($event);
        });
    }

    /**
     * Handle a logged message event.
     */
    protected function handleLogEvent(MessageLogged $event): void
    {
        // Prevent infinite loops - don't log our own operations
        if ($this->isInternalLog($event)) {
            return;
        }

        // Skip if LogScopeHandler already captured this log (prevents duplicates
        // when 'logscope' channel is included in a stack alongside 'all' capture mode)
        if (LogScopeHandler::didHandleCurrentLog()) {
            return;
        }

        // Get channel and reset for next log (prevents sticky channel on Log::build())
        $channel = ChannelContextProcessor::getLastChannel();
        ChannelContextProcessor::clearLastChannel();

        if ($this->shouldIgnoreLog($event, $channel)) {
            return;
        }

        try {
            $data = $this->buildLogData($event, $channel);
            $this->writer->write($data);
        } catch (Throwable $e) {
            // Silently fail - don't break the application
            if (config('app.debug')) {
                error_log('LogScope: Failed to write log entry: '.$e->getMessage());
            }
        }
    }

    /**
     * Build the log entry data array.
     */
    protected function buildLogData(MessageLogged $event, ?string $channel): array
    {
        // Get request context from Laravel Context (set by middleware)
        $requestContext = Context::get('logscope', []);

        // Get user_id at log-write time (after auth middleware has run)
        $userId = null;
        $customContext = [];
        if (app()->bound('request')) {
            $userId = request()->user()?->id;
            $customContext = LogScope::getCapturedContext(request());
        }

        return [
            'level' => $event->level,
            'message' => $event->message,
            'context' => $this->sanitizer->sanitize(array_merge($event->context, $customContext)),
            'channel' => $channel,
            'source' => $this->sanitizer->extractSource($event->context),
            'source_line' => $this->sanitizer->extractSourceLine($event->context),
            'trace_id' => $requestContext['trace_id'] ?? null,
            'user_id' => $userId,
            'ip_address' => $requestContext['ip_address'] ?? null,
            'user_agent' => $requestContext['user_agent'] ?? null,
            'http_method' => $requestContext['http_method'] ?? null,
            'url' => $requestContext['url'] ?? null,
            'occurred_at' => now(),
        ];
    }

    /**
     * Check if this is an internal log that should be skipped.
     */
    protected function isInternalLog(MessageLogged $event): bool
    {
        // Skip logs from our own namespace
        if (str_contains($event->message, 'LogScope')) {
            return true;
        }

        // Check context for LogScope markers
        if (isset($event->context['_logscope_internal'])) {
            return true;
        }

        return false;
    }

    /**
     * Check if this log should be ignored based on config settings.
     */
    protected function shouldIgnoreLog(MessageLogged $event, ?string $channel): bool
    {
        // Check if we should ignore deprecation messages
        if (config('logscope.ignore.deprecations', false)) {
            if (str_contains($event->message, 'is deprecated')) {
                return true;
            }
        }

        // Check if we should ignore logs without a channel
        if (config('logscope.ignore.null_channel', false)) {
            if ($channel === null) {
                return true;
            }
        }

        return false;
    }
}
