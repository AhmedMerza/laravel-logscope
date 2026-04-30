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
        // Consume the channel set by the most recent processor invocation
        // FIRST, before any early return. consumeLastChannel() returns null
        // unless a processor invocation happened since the previous consume,
        // and always clears state — so a Log::build() log (no processor)
        // gets null, and a stale value left from a prior log can never leak
        // forward, even if any of the early-return paths below fire.
        $channel = ChannelContextProcessor::consumeLastChannel();

        // Re-entrant guard: a write in progress may itself emit a log
        // (observer on log_entries, query listener with Log::debug, etc.).
        // Skip those — capturing them would recurse on every insert.
        if (WriteGuard::isWriting()) {
            return;
        }

        // Prevent infinite loops - don't log our own operations
        if ($this->isInternalLog($event)) {
            return;
        }

        // Skip if LogScopeHandler already captured this log (prevents duplicates
        // when 'logscope' channel is included in a stack alongside 'all' capture mode)
        if (LogScopeHandler::didHandleCurrentLog()) {
            return;
        }

        if ($this->shouldIgnoreLog($event, $channel)) {
            return;
        }

        try {
            $data = $this->buildLogData($event, $channel);
            $this->writer->write($data);
        } catch (Throwable $e) {
            // Don't break the calling application, but always surface the
            // failure to PHP's error log. Hiding it behind APP_DEBUG meant
            // production DB outages caused silent total log loss with zero
            // observability. WriteFailureLogger dedupes per-process so a
            // sustained outage doesn't dump thousands of identical lines.
            WriteFailureLogger::report($e, 'listener');
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
     *
     * Only the structured `_logscope_internal` context key triggers the skip.
     * Substring matches on the message (e.g. checking for "LogScope") are
     * unsafe — they silently drop legitimate user logs that happen to mention
     * the package by name (integration error reports, alerts, etc.).
     */
    protected function isInternalLog(MessageLogged $event): bool
    {
        return isset($event->context['_logscope_internal']);
    }

    /**
     * Check if this log should be ignored based on config settings.
     */
    protected function shouldIgnoreLog(MessageLogged $event, ?string $channel): bool
    {
        // Check if we should ignore deprecation messages.
        //
        // Two-layer match:
        //
        // 1) CHANNEL match (preferred) — PHP runtime deprecations route
        //    through Laravel's `deprecations` channel. If the channel
        //    processor is registered for it, we match by name.
        //
        // 2) MESSAGE-PATTERN fallback — Laravel's HandleExceptions
        //    synthesizes the `deprecations` channel lazily, AFTER our
        //    channel processor registration has run, so the processor
        //    isn't attached and `$channel` is null/empty for these.
        //    Fall back to matching the precise PHP deprecation format:
        //    every PHP-runtime deprecation wraps as
        //      "<actual message> in <file> on line <N>"
        //    So we look for "is deprecated" + "on line <N>" at the end.
        //    This is narrow enough that user logs like "Account 42 is
        //    deprecated for billing" still pass through.
        if (config('logscope.ignore.deprecations', false)) {
            $deprecationChannels = (array) config('logscope.ignore.deprecation_channels', ['deprecations']);

            if ($channel !== null && in_array($channel, $deprecationChannels, true)) {
                return true;
            }

            if ($this->looksLikePhpDeprecation($event->message)) {
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

    /**
     * Match Laravel's wrapped PHP-deprecation message format precisely.
     *
     * Laravel's HandleExceptions::handleDeprecation() formats the message as
     *   "<original message> in <file> on line <N>"
     * before logging through the deprecations channel. The original
     * message itself contains "is deprecated" (the PHP-runtime phrase).
     *
     * Requiring BOTH parts (`is deprecated` somewhere + `on line <N>` at
     * the end) is narrow enough to avoid the false-positives a plain
     * substring match had:
     *   - "Account 42 is deprecated for billing"  → no "on line N" → keep
     *   - "Migration is DEPRECATED"               → no "on line N" → keep
     *   - "strpos(): ... is deprecated in /path/x.php on line 42" → drop
     */
    protected function looksLikePhpDeprecation(string $message): bool
    {
        // Cheap pre-check before regex
        if (! str_contains($message, 'is deprecated')) {
            return false;
        }

        return (bool) preg_match('/ on line \d+$/', $message);
    }
}
