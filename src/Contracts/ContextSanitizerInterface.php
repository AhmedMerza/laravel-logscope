<?php

declare(strict_types=1);

namespace LogScope\Contracts;

/**
 * Contract for sanitizing log context data.
 */
interface ContextSanitizerInterface
{
    /**
     * Sanitize context array for storage.
     *
     * Converts objects and exceptions to JSON-safe representations.
     */
    public function sanitize(array $context): array;

    /**
     * Extract source file from context.
     */
    public function extractSource(array $context): ?string;

    /**
     * Extract source line from context.
     */
    public function extractSourceLine(array $context): ?int;

    /**
     * Sanitize a URL by redacting sensitive query parameters.
     */
    public function sanitizeUrl(string $url): string;

    /**
     * Reduce a request's headers to the configured allowlist.
     *
     * Returns null when capture is disabled or nothing matched.
     */
    public function captureHeaders(array $headers): ?array;

    /**
     * Coerce every string in an array — keys as well as values, at every
     * depth — to valid UTF-8.
     *
     * Laravel serializes the Context bag into every job the host
     * application queues, and json_encode() returns false on a malformed
     * byte — which Laravel reports as InvalidPayloadException.
     *
     * Keys are part of the contract, not an implementation detail (#67).
     * LogScope also calls this on a queued log entry's own data, whose
     * context can hold a Request: sanitizeHeaders() copies header names in
     * verbatim with no allowlist, so they are attacker-controlled and
     * become JSON object keys. An implementation that cleans only values
     * resolves and runs fine, and silently lets a malformed header name
     * break dispatch for the host application.
     */
    public function toValidUtf8Deep(array $bag): array;
}
