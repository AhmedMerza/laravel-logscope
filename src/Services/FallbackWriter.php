<?php

declare(strict_types=1);

namespace LogScope\Services;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Context;
use LogScope\Models\LogEntry;
use Throwable;

/**
 * Writes a minimal failure-marker row to log_entries when LogScope's
 * normal write path throws (e.g. context sanitization touches a class
 * the autoloader can't resolve, or the bulk insert blows up mid-flush).
 *
 * Why this exists: WriteFailureLogger surfaces the failure to PHP's
 * error_log and a cache breadcrumb, but operators live in the LogScope
 * UI. A row in log_entries makes the failure visible there — same place
 * everything else shows up — without needing to grep php-fpm logs.
 *
 * What gets dropped: the original `context` array. That's the usual
 * poison source — sanitized objects whose serialization triggered the
 * underlying error. The fallback row replaces it with a structured
 * marker carrying the exception class, message, and call-site label.
 *
 * Dedupe: per-process counter map keyed on `exceptionClass@file:line`,
 * mirroring WriteFailureLogger. A row is emitted on the first occurrence
 * and every REEMIT_EVERY (100th) occurrence thereafter, so a sustained
 * outage shows a steady heartbeat in the UI rather than going silent
 * after the first row. Octane workers call reset() on RequestReceived
 * so the dedupe scopes to the request, not the worker's lifetime.
 */
class FallbackWriter
{
    /**
     * Per-process occurrence counter: failure key => count.
     *
     * Tracks how many times each unique throw site has fired in this
     * process so we can emit a heartbeat row every Nth occurrence (see
     * REEMIT_EVERY). Counts grow unboundedly across the worker lifetime,
     * but the key space is bounded by distinct throw sites in the
     * codebase — finite in practice.
     */
    private static array $seen = [];

    /**
     * Cap the persisted exception message — production messages can
     * carry full SQL/stack snippets, and this row exists for visibility,
     * not forensics.
     */
    private const MAX_MESSAGE_LENGTH = 1000;

    /**
     * Emit a fresh fallback row on the first occurrence and every Nth
     * occurrence thereafter. Mirrors WriteFailureLogger::SUMMARY_EVERY
     * so a long-running outage doesn't go silent in the UI after the
     * first row — the operator gets a heartbeat that the issue is still
     * happening.
     */
    private const REEMIT_EVERY = 100;

    /**
     * Write a fallback row carrying the original level/message/channel/
     * trace_id, with `context` replaced by a `_logscope_write_failure`
     * marker. Best-effort: gated by config flag, deduped per-process,
     * and wrapped in WriteGuard so the listener doesn't re-capture the
     * insert. If the fallback insert itself fails, we silently swallow —
     * WriteFailureLogger has already emitted the failure to error_log.
     */
    public function record(array $data, Throwable $e, string $where): void
    {
        if (! $this->isPersistEnabled()) {
            return;
        }

        $key = get_class($e).'@'.$e->getFile().':'.$e->getLine();
        $count = (self::$seen[$key] ?? 0) + 1;
        self::$seen[$key] = $count;

        // Emit on first occurrence and every REEMIT_EVERY thereafter.
        // Between heartbeats the row would be a near-duplicate, so we skip.
        if ($count !== 1 && $count % self::REEMIT_EVERY !== 0) {
            return;
        }

        try {
            WriteGuard::during(fn () => LogEntry::createEntry($this->buildPayload($data, $e, $where, $count)));
        } catch (Throwable) {
            // Last-resort: the failure has already been surfaced to error_log
            // by WriteFailureLogger::report. Don't recurse into another error.
        }
    }

    /**
     * Build a fallback payload from a MessageLogged event. Used when the
     * normal write path failed BEFORE buildLogData could finish — so
     * there's no sanitized `$data` array yet, only the raw event.
     *
     * Pulls request context (trace_id/ip/url/method/agent) directly from
     * Laravel's Context facade so the fallback row stays correlatable
     * with the request that produced the failure. The whole point of
     * the fallback is "see this failure in context"; losing trace_id
     * would defeat it.
     */
    public function recordFromEvent(
        MessageLogged $event,
        ?string $channel,
        Throwable $e,
        string $where
    ): void {
        $requestContext = $this->readRequestContext();

        $this->record([
            'level' => $event->level,
            'message' => $event->message,
            'channel' => $channel,
            'trace_id' => $requestContext['trace_id'] ?? null,
            'ip_address' => $requestContext['ip_address'] ?? null,
            'user_agent' => $requestContext['user_agent'] ?? null,
            'http_method' => $requestContext['http_method'] ?? null,
            'url' => $requestContext['url'] ?? null,
        ], $e, $where);
    }

    /**
     * Best-effort read of the request-scoped LogScope context. Returns
     * an empty array when the Context facade is unavailable (CLI, very
     * late shutdown, container teardown).
     *
     * @return array<string, mixed>
     */
    private function readRequestContext(): array
    {
        try {
            $value = Context::get('logscope', []);

            return is_array($value) ? $value : [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @internal — for tests and Octane request-boundary resets.
     *
     * Clears the per-process occurrence map so a long-running worker
     * doesn't strand its dedupe state across request boundaries (which
     * would mean only ever seeing the first failure for the worker's
     * lifetime).
     */
    public static function reset(): void
    {
        self::$seen = [];
    }

    /**
     * @internal — for tests
     */
    public static function occurrenceCount(Throwable $e): int
    {
        $key = get_class($e).'@'.$e->getFile().':'.$e->getLine();

        return self::$seen[$key] ?? 0;
    }

    private function isPersistEnabled(): bool
    {
        try {
            return (bool) config('logscope.write_failure.persist_fallback', true);
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * Construct the minimal payload. Original metadata is preserved so
     * the row threads correctly into trace/IP filters; `context` is
     * replaced with the failure marker so re-sanitization can't fire
     * the same trap that broke the original write.
     */
    private function buildPayload(array $data, Throwable $e, string $where, int $occurrence): array
    {
        return [
            'level' => $data['level'] ?? 'error',
            'message' => $data['message'] ?? '(unknown message)',
            'channel' => $data['channel'] ?? null,
            'trace_id' => $data['trace_id'] ?? null,
            'user_id' => $data['user_id'] ?? null,
            'ip_address' => $data['ip_address'] ?? null,
            'user_agent' => $data['user_agent'] ?? null,
            'http_method' => $data['http_method'] ?? null,
            'url' => $data['url'] ?? null,
            'occurred_at' => $data['occurred_at'] ?? now(),
            'context' => [
                '_logscope_write_failure' => [
                    'exception_class' => get_class($e),
                    'exception_message' => $this->truncate($e->getMessage(), self::MAX_MESSAGE_LENGTH),
                    'where' => $where,
                    'occurrence' => $occurrence,
                ],
            ],
        ];
    }

    private function truncate(string $value, int $max): string
    {
        if (mb_strlen($value) <= $max) {
            return $value;
        }

        return mb_substr($value, 0, $max).'… [truncated]';
    }
}
