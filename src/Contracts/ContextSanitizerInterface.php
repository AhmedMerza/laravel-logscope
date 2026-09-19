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
     * Coerce every string in a request-context bag to valid UTF-8.
     *
     * Laravel serializes the Context bag into every job the host
     * application queues, and json_encode() returns false on a malformed
     * byte — which Laravel reports as InvalidPayloadException.
     */
    public function toValidUtf8Deep(array $bag): array;
}
