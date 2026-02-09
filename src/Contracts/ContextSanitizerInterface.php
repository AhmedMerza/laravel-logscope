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
}
