<?php

declare(strict_types=1);

namespace LogScope\Services;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use JsonSerializable;
use LogScope\Concerns\ResolvesExceptionSource;
use LogScope\Contracts\ContextSanitizerInterface;
use Throwable;

/**
 * Sanitizes log context for safe storage and extracts source information.
 */
class ContextSanitizer implements ContextSanitizerInterface
{
    use ResolvesExceptionSource;

    /**
     * Whether to expand objects to arrays.
     */
    protected bool $expandObjects;

    /**
     * Whether to redact sensitive data.
     */
    protected bool $redactSensitive;

    /**
     * Single-word sensitive fragments, matched inside one key word.
     *
     * @var list<string>
     */
    protected array $sensitiveWords;

    /**
     * Multi-word sensitive fragments, joined, matched across the key.
     *
     * @var list<string>
     */
    protected array $sensitivePhrases;

    /**
     * Word runs that cancel the part of a key they cover.
     *
     * @var list<list<string>>
     */
    protected array $exceptRuns;

    /**
     * Headers to redact from request data.
     */
    protected array $sensitiveHeaders;

    /**
     * Longest key the matcher will examine before redacting on sight.
     *
     * Key names arrive from request bodies, so their length is effectively
     * chosen by the client, and splitting one into words costs time linear
     * in it. No real field name comes close to this.
     */
    protected const MAX_KEY_LENGTH = 256;

    /**
     * Shortest usable exclusion fragment.
     *
     * An exclusion removes whole words, so a one- or two-character entry
     * ('e') removes nearly every word of every key and silently switches
     * redaction off. Sensitive fragments get no such floor on purpose:
     * over-matching is the safe direction, under-matching is not.
     */
    protected const MIN_EXCEPT_LENGTH = 3;

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
     * Default exclusions — keys the list above matches as a fragment but
     * which carry no secret.
     *
     * Redaction matches fragments so that access_token, x-api-key and
     * user_password are all covered without listing them. The cost is that
     * 'token' also appears in an LLM app's token counts, which is what these
     * cancel. Naming the exceptions is what keeps the match broad: the
     * alternative tried in #77 — matching whole words only — silently stopped
     * redacting access_tokens, card_numbers and APIToken.
     */
    protected const DEFAULT_SENSITIVE_KEYS_EXCEPT = [
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'token_count',
        'tokenizer',
    ];

    /**
     * Default sensitive header name fragments.
     *
     * Matched anywhere in the header name, so custom headers (x-auth-token,
     * x-api-key) and Symfony's php-auth-pw are covered without listing them.
     */
    protected const DEFAULT_SENSITIVE_HEADERS = [
        'auth',
        'cookie',
        'token',
        'key',
        'secret',
        'password',
        'session',
    ];

    public function __construct()
    {
        $this->expandObjects = config('logscope.context.expand_objects', true);
        $this->redactSensitive = config('logscope.context.redact_sensitive', true);

        // Use config if provided, otherwise use defaults
        $configKeys = (array) config('logscope.context.sensitive_keys', []);
        $fragments = $this->wordRuns($configKeys !== [] ? $configKeys : self::DEFAULT_SENSITIVE_KEYS);

        // A configured list whose entries are all blank ('', '---', or the
        // [''] that explode(',', env('…', '')) yields when the variable is
        // unset) leaves nothing to match with, and a matcher with no
        // fragments redacts nothing at all. Falling back to the defaults
        // keeps a misconfiguration loud — too much [REDACTED] — rather than
        // silent, which here means secrets in the clear.
        if ($fragments === []) {
            $fragments = $this->wordRuns(self::DEFAULT_SENSITIVE_KEYS);
        }

        $this->sensitiveWords = array_values(array_map(
            static fn (array $run): string => $run[0],
            array_filter($fragments, static fn (array $run): bool => count($run) === 1)
        ));

        $this->sensitivePhrases = array_values(array_map(
            static fn (array $run): string => implode('', $run),
            array_filter($fragments, static fn (array $run): bool => count($run) > 1)
        ));

        // Exclusions add to the defaults rather than replacing them. The
        // shipped entries are known false positives of the key list, and an
        // app that has to name one of its own should not have to re-list
        // LogScope's to keep them.
        $this->exceptRuns = array_values(array_filter(
            $this->wordRuns([
                ...self::DEFAULT_SENSITIVE_KEYS_EXCEPT,
                ...(array) config('logscope.context.sensitive_keys_except', []),
            ]),
            static fn (array $run): bool => strlen(implode('', $run)) >= self::MIN_EXCEPT_LENGTH
        ));

        // Headers add to the defaults rather than replacing them: keys can be
        // replaced to escape false positives (token → prompt_tokens), but no
        // header is worth losing authorization/cookie redaction over.
        $configHeaders = (array) config('logscope.context.sensitive_headers', []);
        $this->sensitiveHeaders = [...self::DEFAULT_SENSITIVE_HEADERS, ...$configHeaders];
    }

    /**
     * Sanitize context array for storage.
     *
     * Converts objects and exceptions to JSON-safe representations, and
     * replaces the value of any sensitive key with [REDACTED].
     */
    public function sanitize(array $context): array
    {
        $sanitized = [];

        foreach ($context as $key => $value) {
            // Skip internal keys
            if (str_starts_with((string) $key, '__') || str_starts_with((string) $key, '_logscope')) {
                continue;
            }

            $sanitized[$key] = $this->shouldRedactKey($key)
                ? '[REDACTED]'
                : $this->sanitizeValue($value);
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

        // json_encode() cannot represent a resource, and an unencodable value
        // costs the whole context column rather than the one field.
        if (is_resource($value)) {
            return '[Resource]';
        }

        return $value;
    }

    /**
     * Sanitize an array recursively.
     *
     * Redaction happens here rather than only on the Request path, so it
     * reaches every array the application logs itself and every object
     * expanded into one — a DTO's $password property included.
     */
    protected function sanitizeArray(array $value, int $depth = 0): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            $result[$key] = $this->shouldRedactKey($key)
                ? '[REDACTED]'
                : $this->sanitizeValue($item, $depth + 1);
        }

        return $result;
    }

    /**
     * Sanitize a generic object.
     */
    protected function sanitizeObject(object $value, int $depth = 0): mixed
    {
        // Handle enums first — they don't implement any serialization interfaces
        if ($value instanceof \BackedEnum) {
            return [
                '_type' => 'enum',
                'class' => get_class($value),
                'name' => $value->name,
                'value' => $value->value,
            ];
        }

        if ($value instanceof \UnitEnum) {
            return [
                '_type' => 'enum',
                'class' => get_class($value),
                'name' => $value->name,
            ];
        }

        // Handle objects that can convert themselves to arrays/strings
        // Wrap in try-catch to handle objects that throw when serializing
        // (e.g., Sanctum's TransientToken which has no $id property)
        try {
            if ($value instanceof \DateTimeInterface) {
                return $value->format('Y-m-d H:i:s.u');
            }

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

            if ($value instanceof \Stringable) {
                return (string) $value;
            }

            // Public-property fallback — handles stdClass, plain DTOs, etc.
            return [
                '_type' => 'object',
                'class' => get_class($value),
                'data' => $this->sanitizeArray(get_object_vars($value), $depth + 1),
            ];
        } catch (\Throwable) {
            // Fall through to return class name
        }

        // Last resort: just show class name
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
            'url' => $this->sanitizeUrl($request->fullUrl()),
            'path' => $request->path(),
            'query' => $this->redactSensitive($request->query()),
            // all(), not except(...): redaction covers these
            // keys now, and dropping them outright made a sensitive field
            // vanish from the entry while a nested one showed [REDACTED].
            // A reader cannot tell an absent field from one never sent.
            'input' => $this->redactSensitive($request->all()),
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
            if ($this->isSensitiveKey((string) $key)) {
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
     * Whether this context key's value must be replaced with [REDACTED].
     *
     * Integer keys are never sensitive — they are list positions, not names —
     * and skipping them keeps the matching off the hot path for large lists.
     */
    protected function shouldRedactKey(mixed $key): bool
    {
        return $this->redactSensitive
            && is_string($key)
            && $this->isSensitiveKey($key);
    }

    /**
     * Check if a key is sensitive.
     *
     * The key is split into words, the words an exclusion covers are
     * removed, and what remains is matched two ways: a single-word fragment
     * ('ssn', 'token') must appear inside ONE word, while a multi-word one
     * ('card_number') is matched across the words joined together.
     *
     * That split is the whole design, and each half pays for a bug found in
     * review (#77):
     *
     * - Matching whole words only, so that 'token' missed 'prompt_tokens',
     *   also missed access_tokens, card_numbers, passwords and APIToken.
     *   Matching a fragment inside a word keeps all of those.
     * - Matching a fragment across the whole key with separators stripped
     *   glued unrelated words together: class_name became 'classname',
     *   which contains 'ssn'. Confining a single-word fragment to one word
     *   stops that, while a multi-word fragment still spans the join so
     *   cardNumber, card-number and card.number all match card_number.
     *
     * Redaction fails towards [REDACTED]: an over-redacted field is visible
     * and one config line away from fixed, a missed one is a secret in the
     * database that nobody goes looking for.
     */
    protected function isSensitiveKey(string $key): bool
    {
        // A field name this long is not a field name. Tokenizing costs time
        // linear in a length the client chooses, and truncating first would
        // hand that client a long prefix to hide the fragment behind, so an
        // over-long key is simply treated as sensitive.
        if (strlen($key) > self::MAX_KEY_LENGTH) {
            return true;
        }

        $words = $this->withoutExcluded($this->words($key));

        if ($words === []) {
            return false;
        }

        foreach ($words as $word) {
            foreach ($this->sensitiveWords as $fragment) {
                if (str_contains($word, $fragment)) {
                    return true;
                }
            }
        }

        if ($this->sensitivePhrases === []) {
            return false;
        }

        $joined = implode('', $words);

        foreach ($this->sensitivePhrases as $phrase) {
            if (str_contains($joined, $phrase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Drop the words an exclusion covers, leaving the rest to be matched.
     *
     * Removing rather than cancelling is what keeps one excluded term from
     * clearing an unrelated secret elsewhere in the same key: before this,
     * `prompt_tokens_password` contained an exclusion and a sensitive
     * fragment, the exclusion was checked first, and the password was
     * stored in the clear (#77). Now `prompt_tokens` takes its two words
     * with it and `password` is still there to match.
     *
     * A single-word exclusion removes any word containing it, so 'tokenizer'
     * also covers 'tokenizers'.
     *
     * @param  list<string>  $words
     * @return list<string>
     */
    protected function withoutExcluded(array $words): array
    {
        foreach ($this->exceptRuns as $run) {
            if (count($run) === 1) {
                $words = array_values(array_filter(
                    $words,
                    static fn (string $word): bool => ! str_contains($word, $run[0])
                ));

                continue;
            }

            for ($i = 0; $i + count($run) <= count($words); $i++) {
                if (array_slice($words, $i, count($run)) === $run) {
                    array_splice($words, $i, count($run));
                    $i--;
                }
            }
        }

        return $words;
    }

    /**
     * Split a key into its lowercase words.
     *
     * Separators and camelCase boundaries both divide words, so user_id,
     * user-id, user.id and userId all give ['user', 'id']. An acronym run
     * is deliberately left whole — APIToken gives ['apitoken'], and the
     * fragment match inside a word finds 'token' in it anyway.
     *
     * @return list<string>
     */
    protected function words(string $key): array
    {
        $spaced = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $key) ?? $key;

        return array_values(array_filter(
            preg_split('/[^a-z0-9]+/', strtolower($spaced)) ?: [],
            static fn (string $word): bool => $word !== ''
        ));
    }

    /**
     * Split configured fragments into word runs, dropping the empty ones.
     *
     * An entry with no word characters at all ('', '   ', '---') would
     * otherwise become an empty run, and an empty run matches every key.
     *
     * @return list<list<string>>
     */
    protected function wordRuns(array $fragments): array
    {
        $runs = [];

        foreach ($fragments as $fragment) {
            $words = $this->words((string) $fragment);

            if ($words !== []) {
                $runs[] = $words;
            }
        }

        return $runs;
    }

    /**
     * Sanitize request headers (remove sensitive ones).
     */
    protected function sanitizeHeaders(array $headers): array
    {
        $result = [];

        foreach ($headers as $name => $values) {
            if ($this->isSensitiveHeader((string) $name)) {
                $result[$name] = ['[REDACTED]'];
            } else {
                $result[$name] = $values;
            }
        }

        return $result;
    }

    /**
     * Coerce a header value to valid UTF-8.
     *
     * Header bytes come from the client and need not be valid UTF-8. Left
     * as-is they travel in the `logscope` Context bag, which Laravel
     * serializes into every job queued during the request — and
     * Queue::createPayload() encodes with plain
     * json_encode($value, JSON_UNESCAPED_UNICODE), which returns false on
     * a malformed sequence and makes Laravel throw InvalidPayloadException.
     * That breaks the host application's own dispatches, not just ours.
     *
     * Cleaning here rather than at the storage encoder is what covers all
     * three write modes: sync and batch encode at write time, but queue
     * serializes the raw array long before it reaches LogEntry.
     *
     * mb_check_encoding first because this runs per header per log line:
     * valid input (effectively all of it) skips the conversion entirely.
     */
    protected function toValidUtf8(string $value): string
    {
        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }

    /**
     * Coerce every string in a request-context bag to valid UTF-8.
     *
     * CaptureRequestContext builds its bag out of request data, and any of
     * it can be raw client bytes. Laravel copies the Context bag into every
     * job the HOST application queues during that request, so a single bad
     * byte makes the host's own dispatch throw InvalidPayloadException,
     * with nothing in the trace to say a logging middleware caused it.
     *
     * Guarding the whole bag rather than the fields known to carry client
     * bytes is deliberate. #30 fixed this for captured headers; #63 found
     * it again on the user_agent line directly above them. Every field
     * added to the bag later is another chance to forget, and the guard
     * costs one mb_check_encoding per string.
     *
     * Keys are cleaned too, which #63 did not need. Its bag's only
     * request-sourced keys are header names kept by captureHeaders(), which
     * matches an allowlist exactly — so a kept key came from config. #67
     * gave this method a second caller whose keys are not: a log entry's
     * own context can hold a Request, and sanitizeHeaders() copies those
     * header names in verbatim with no allowlist at all.
     *
     * Two malformed keys can therefore collapse into one, last value
     * winning. That is what json_encode() does with them anyway, and the
     * alternative is losing the whole array.
     */
    public function toValidUtf8Deep(array $bag): array
    {
        $clean = [];

        foreach ($bag as $key => $value) {
            $key = is_string($key) ? $this->toValidUtf8($key) : $key;

            if (is_string($value)) {
                $clean[$key] = $this->toValidUtf8($value);
            } elseif (is_array($value)) {
                $clean[$key] = $this->toValidUtf8Deep($value);
            } else {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    /**
     * Check if a header name is sensitive.
     */
    protected function isSensitiveHeader(string $name): bool
    {
        return Str::contains($name, $this->sensitiveHeaders, ignoreCase: true);
    }

    /**
     * Reduce a request's headers to the configured allowlist, for the
     * entry's own `headers` column.
     *
     * Names are lowercased, repeated headers are joined with ", ", and
     * values are flattened to strings — the column is read by humans in
     * the detail panel and substring-matched by `headers:` search, and
     * Symfony's name => [values] shape serves neither.
     *
     * Returns null rather than an empty array when capture is off or
     * nothing matched, so an HTTP row with no allowlisted headers reads
     * the same as a CLI row: no headers to show.
     */
    public function captureHeaders(array $headers): ?array
    {
        if (! config('logscope.context.headers.enabled', true)) {
            return null;
        }

        $allowlist = array_map(
            static fn ($name): string => strtolower((string) $name),
            (array) config('logscope.context.headers.allowlist', [])
        );

        if ($allowlist === []) {
            return null;
        }

        $maxLength = (int) config('logscope.context.headers.max_value_length', 500);

        $captured = [];

        foreach ($headers as $name => $values) {
            $name = strtolower((string) $name);

            if (! in_array($name, $allowlist, true)) {
                continue;
            }

            // Redaction wins over the allowlist: listing authorization
            // explicitly still gets you [REDACTED], never the token.
            if ($this->isSensitiveHeader($name)) {
                $captured[$name] = '[REDACTED]';

                continue;
            }

            $captured[$name] = Str::limit(
                $this->toValidUtf8(implode(', ', (array) $values)),
                $maxLength,
                '…[truncated]'
            );
        }

        return $captured === [] ? null : $captured;
    }

    /**
     * Sanitize a URL by redacting sensitive query parameters.
     */
    public function sanitizeUrl(string $url): string
    {
        if (! $this->redactSensitive) {
            return $url;
        }

        $parsed = parse_url($url);

        // Return original URL if we can't parse it properly
        if (! isset($parsed['scheme']) || ! isset($parsed['host'])) {
            return $url;
        }

        if (! isset($parsed['query'])) {
            return $url;
        }

        parse_str($parsed['query'], $queryParams);
        $sanitizedQuery = $this->redactSensitive($queryParams);

        // Rebuild the URL with sanitized query string
        $newQuery = http_build_query($sanitizedQuery);
        $baseUrl = $parsed['scheme'].'://'.$parsed['host'];
        if (isset($parsed['port'])) {
            $baseUrl .= ':'.$parsed['port'];
        }
        if (isset($parsed['path'])) {
            $baseUrl .= $parsed['path'];
        }
        if ($newQuery) {
            $baseUrl .= '?'.$newQuery;
        }
        if (isset($parsed['fragment'])) {
            $baseUrl .= '#'.$parsed['fragment'];
        }

        return $baseUrl;
    }

    /**
     * Convert an exception to a safe array representation.
     *
     * Includes a slice of the stack trace (with `args` stripped to avoid
     * leaking secrets such as passwords passed to a constructor). For
     * argument-validation errors (ArgumentCountError / arg-validation
     * TypeError), `file`/`line` are remapped to the caller's call site so
     * the detail panel matches the list view's `source` column.
     */
    protected function sanitizeException(Throwable $exception): array
    {
        $callerFrame = $this->callerFrameForException($exception);

        return [
            '_type' => 'exception',
            'class' => get_class($exception),
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'file' => $callerFrame['file'] ?? $exception->getFile(),
            'line' => $callerFrame['line'] ?? $exception->getLine(),
            'trace' => $this->sanitizeTrace($exception),
        ];
    }

    /**
     * Return up to 10 frames of the trace with `args` stripped (to avoid
     * leaking sensitive arguments) and only safe scalar metadata kept.
     *
     * @return list<array{file?: string, line?: int, function?: string, class?: string, type?: string}>
     */
    protected function sanitizeTrace(Throwable $exception): array
    {
        $frames = array_slice($exception->getTrace(), 0, 10);
        $sanitized = [];

        foreach ($frames as $frame) {
            $clean = [];
            foreach (['file', 'line', 'function', 'class', 'type'] as $key) {
                if (isset($frame[$key])) {
                    $clean[$key] = $frame[$key];
                }
            }
            $sanitized[] = $clean;
        }

        return $sanitized;
    }

    /**
     * Extract source file from context.
     */
    public function extractSource(array $context): ?string
    {
        if (! isset($context['exception']) || ! ($context['exception'] instanceof Throwable)) {
            return null;
        }

        $exception = $context['exception'];
        $callerFrame = $this->callerFrameForException($exception);

        return $callerFrame['file'] ?? $exception->getFile();
    }

    /**
     * Extract source line from context.
     */
    public function extractSourceLine(array $context): ?int
    {
        if (! isset($context['exception']) || ! ($context['exception'] instanceof Throwable)) {
            return null;
        }

        $exception = $context['exception'];
        $callerFrame = $this->callerFrameForException($exception);

        return $callerFrame['line'] ?? $exception->getLine();
    }
}
