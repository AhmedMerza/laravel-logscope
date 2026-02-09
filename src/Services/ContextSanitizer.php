<?php

declare(strict_types=1);

namespace LogScope\Services;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Http\Request;
use JsonSerializable;
use LogScope\Contracts\ContextSanitizerInterface;
use Throwable;

/**
 * Sanitizes log context for safe storage and extracts source information.
 */
class ContextSanitizer implements ContextSanitizerInterface
{
    /**
     * Whether to expand objects to arrays.
     */
    protected bool $expandObjects;

    /**
     * Whether to redact sensitive data.
     */
    protected bool $redactSensitive;

    /**
     * Keys to redact from request data for security.
     */
    protected array $sensitiveKeys;

    /**
     * Headers to redact from request data.
     */
    protected array $sensitiveHeaders;

    /**
     * Default sensitive keys.
     */
    protected const DEFAULT_SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'secret',
        'token',
        'api_key',
        'apikey',
        'authorization',
        'credit_card',
        'card_number',
        'cvv',
        'ssn',
    ];

    /**
     * Default sensitive headers.
     */
    protected const DEFAULT_SENSITIVE_HEADERS = [
        'authorization',
        'cookie',
        'x-csrf-token',
        'x-xsrf-token',
    ];

    public function __construct()
    {
        $this->expandObjects = config('logscope.context.expand_objects', true);
        $this->redactSensitive = config('logscope.context.redact_sensitive', true);

        // Use config if provided, otherwise use defaults
        $configKeys = config('logscope.context.sensitive_keys', []);
        $this->sensitiveKeys = ! empty($configKeys) ? $configKeys : self::DEFAULT_SENSITIVE_KEYS;

        $configHeaders = config('logscope.context.sensitive_headers', []);
        $this->sensitiveHeaders = ! empty($configHeaders) ? $configHeaders : self::DEFAULT_SENSITIVE_HEADERS;
    }

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
    protected function sanitizeValue(mixed $value, int $depth = 0): mixed
    {
        // Prevent infinite recursion
        if ($depth > 5) {
            return is_object($value) ? '[Object: '.get_class($value).']' : '[Max depth exceeded]';
        }

        // Exceptions are always expanded (they're useful for debugging)
        if ($value instanceof Throwable) {
            return $this->sanitizeException($value);
        }

        // Objects are only expanded if configured
        if (is_object($value)) {
            if (! $this->expandObjects) {
                return '[Object: '.get_class($value).']';
            }

            if ($value instanceof Request) {
                return $this->sanitizeRequest($value);
            }

            return $this->sanitizeObject($value, $depth);
        }

        if (is_array($value)) {
            return $this->sanitizeArray($value, $depth);
        }

        return $value;
    }

    /**
     * Sanitize an array recursively.
     */
    protected function sanitizeArray(array $value, int $depth = 0): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            $result[$key] = $this->sanitizeValue($item, $depth + 1);
        }

        return $result;
    }

    /**
     * Sanitize a generic object.
     */
    protected function sanitizeObject(object $value, int $depth = 0): mixed
    {
        // Handle objects that can convert themselves to arrays
        if ($value instanceof JsonSerializable) {
            return $this->sanitizeArray((array) $value->jsonSerialize(), $depth + 1);
        }

        if ($value instanceof Arrayable) {
            return $this->sanitizeArray($value->toArray(), $depth + 1);
        }

        if ($value instanceof Jsonable) {
            $decoded = json_decode($value->toJson(), true);

            return is_array($decoded) ? $this->sanitizeArray($decoded, $depth + 1) : $decoded;
        }

        // Fallback: just show class name
        return '[Object: '.get_class($value).']';
    }

    /**
     * Sanitize a Request object to extract useful information.
     */
    protected function sanitizeRequest(Request $request): array
    {
        return [
            '_type' => 'request',
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'path' => $request->path(),
            'query' => $this->redactSensitive($request->query()),
            'input' => $this->redactSensitive($request->except($this->sensitiveKeys)),
            'headers' => $this->sanitizeHeaders($request->headers->all()),
        ];
    }

    /**
     * Redact sensitive keys from data.
     */
    protected function redactSensitive(array $data): array
    {
        if (! $this->redactSensitive) {
            return $data;
        }

        $result = [];
        foreach ($data as $key => $value) {
            $lowerKey = strtolower((string) $key);
            if ($this->isSensitiveKey($lowerKey)) {
                $result[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $result[$key] = $this->redactSensitive($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Check if a key is sensitive.
     */
    protected function isSensitiveKey(string $key): bool
    {
        foreach ($this->sensitiveKeys as $sensitive) {
            if (str_contains($key, $sensitive)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sanitize request headers (remove sensitive ones).
     */
    protected function sanitizeHeaders(array $headers): array
    {
        $result = [];

        foreach ($headers as $name => $values) {
            $lowerName = strtolower($name);
            if (in_array($lowerName, $this->sensitiveHeaders, true)) {
                $result[$name] = ['[REDACTED]'];
            } else {
                $result[$name] = $values;
            }
        }

        return $result;
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
