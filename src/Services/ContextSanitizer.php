<?php

declare(strict_types=1);

namespace LogScope\Services;

use LogScope\Contracts\ContextSanitizerInterface;
use Throwable;

/**
 * Sanitizes log context for safe storage and extracts source information.
 */
class ContextSanitizer implements ContextSanitizerInterface
{
    /**
     * Sanitize context array for storage.
     *
     * Converts objects and exceptions to JSON-safe representations.
     */
    public function sanitize(array $context): array
    {
        $sanitized = [];

        foreach ($context as $key => $value) {
            // Skip internal keys
            if (str_starts_with((string) $key, '__') || str_starts_with((string) $key, '_logscope')) {
                continue;
            }

            $sanitized[$key] = $this->sanitizeValue($value);
        }

        return $sanitized;
    }

    /**
     * Sanitize a single value.
     */
    protected function sanitizeValue(mixed $value): mixed
    {
        if ($value instanceof Throwable) {
            return $this->sanitizeException($value);
        }

        if (is_object($value)) {
            return '[Object: '.get_class($value).']';
        }

        if (is_array($value)) {
            return $value;
        }

        return $value;
    }

    /**
     * Convert an exception to a safe array representation.
     */
    protected function sanitizeException(Throwable $exception): array
    {
        return [
            '_type' => 'exception',
            'class' => get_class($exception),
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ];
    }

    /**
     * Extract source file from context.
     */
    public function extractSource(array $context): ?string
    {
        if (isset($context['exception']) && $context['exception'] instanceof Throwable) {
            return $context['exception']->getFile();
        }

        return null;
    }

    /**
     * Extract source line from context.
     */
    public function extractSourceLine(array $context): ?int
    {
        if (isset($context['exception']) && $context['exception'] instanceof Throwable) {
            return $context['exception']->getLine();
        }

        return null;
    }
}
