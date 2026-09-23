<?php

declare(strict_types=1);

namespace LogScope\Services;

use LogScope\Concerns\ResolvesExceptionSource;
use Throwable;

/**
 * Computes the fingerprint that decides which group an entry belongs to (#29).
 *
 * Two entries with the same fingerprint are the same issue, so this is the
 * whole basis of the rollup: too loose and unrelated errors merge, too tight
 * and `User 4192 not found` never meets `User 87 not found`.
 *
 * Both write paths call this — LogEntry::createEntry() for sync and queue
 * writes, LogEntry::prepareData() for the batch flush. They must produce the
 * same hash for the same log or one error would split into two groups
 * depending on write_mode, which is why the normalisation lives here rather
 * than being inlined at either call site.
 */
final class Fingerprint
{
    use ResolvesExceptionSource;

    public const PLACEHOLDER_UUID = '<uuid>';

    public const PLACEHOLDER_TIMESTAMP = '<timestamp>';

    public const PLACEHOLDER_IP = '<ip>';

    public const PLACEHOLDER_QUOTED = '<str>';

    public const PLACEHOLDER_HEX = '<hex>';

    public const PLACEHOLDER_NUMBER = '<num>';

    /**
     * Reused across a batch flush. The class holds no state, so one instance
     * serves every entry rather than allocating 500 of them per chunk.
     */
    private static ?self $instance = null;

    /**
     * Fingerprint a prepared log entry.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function for(array $attributes): string
    {
        return self::instance()->compute($attributes);
    }

    /**
     * Normalise a message to its shape, dropping the values that vary between
     * occurrences. Exposed for the backfill command and for tests, which need
     * to assert on the readable form rather than on a hash.
     */
    public static function normalize(string $message): string
    {
        // Order is load-bearing, most specific pattern first. A UUID is a run
        // of hex and digits, an ISO timestamp looks like an IPv6 address to a
        // colon-counting pattern, and everything is made of digits — so a
        // looser rule running early would eat the input a tighter rule needs.
        $patterns = [
            // 550e8400-e29b-41d4-a716-446655440000
            '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i' => self::PLACEHOLDER_UUID,

            // 2026-09-23T14:05:00.123+02:00, 2026-09-23 14:05:00, 2026-09-23
            '/\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?)?/i' => self::PLACEHOLDER_TIMESTAMP,

            // Bare clock time, after the datetime rule has taken its own.
            '/\b\d{1,2}:\d{2}:\d{2}\b/' => self::PLACEHOLDER_TIMESTAMP,

            '/\b\d{1,3}(?:\.\d{1,3}){3}\b/' => self::PLACEHOLDER_IP,

            // IPv6, including the :: compressed form. Runs after the timestamp
            // rules because 14:05:00 satisfies a colon-group pattern too.
            '/\b(?:[0-9a-f]{0,4}:){2,7}[0-9a-f]{0,4}\b/i' => self::PLACEHOLDER_IP,

            '/"[^"]*"/' => self::PLACEHOLDER_QUOTED,
            "/'[^']*'/" => self::PLACEHOLDER_QUOTED,

            '/\b0x[0-9a-f]+\b/i' => self::PLACEHOLDER_HEX,

            // A long hex run, but only one containing a digit: pure-letter runs
            // of [a-f] are English words ("facade", "decade"), not hashes.
            '/\b(?=[0-9a-f]*\d)[0-9a-f]{8,}\b/i' => self::PLACEHOLDER_HEX,

            // Decimals before the digit-run rules below, which would
            // otherwise take the integer part of 1234.56 and not of 12.30,
            // normalising two prices of the same message to different shapes.
            '/\b\d+\.\d+\b/' => self::PLACEHOLDER_NUMBER,

            // Digits welded to an underscore. PCRE counts _ as a word
            // character, so order_4192 has no boundary before its digits and
            // the plain integer rule below cannot see them. Safe on its own
            // terms: none of the technical tokens that must survive —
            // sha256, sha512, base64, utf8, oauth2, h264, x86 — contains one.
            '/(?<=_)\d+|\d+(?=_)/' => self::PLACEHOLDER_NUMBER,

            // A run of four or more digits anywhere, including inside a word,
            // so user4192 and abc1234 are ids rather than distinct issues.
            // Four is the threshold because every technical token that has to
            // stay intact carries three digits or fewer. The known cost is
            // spec names: rfc3339 and rfc7231 both become rfc<num> and would
            // share a group. Accepted deliberately — `logscope:backfill-
            // fingerprints --recompute` makes the rule reversible.
            '/\d{4,}/' => self::PLACEHOLDER_NUMBER,

            '/\b\d+\b/' => self::PLACEHOLDER_NUMBER,
        ];

        $normalized = preg_replace(
            array_keys($patterns),
            array_values($patterns),
            $message
        );

        // preg_replace returns null if a pattern fails (e.g. backtrack limit
        // on a pathological message). A fingerprint is never worth losing the
        // log over, so fall back to the raw message.
        $normalized ??= $message;

        return trim((string) preg_replace('/\s+/', ' ', $normalized));
    }

    private static function instance(): self
    {
        return self::$instance ??= new self;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function compute(array $attributes): string
    {
        $exception = $this->exceptionIdentity($attributes['context'] ?? null);

        if ($exception !== null) {
            return sha1($exception);
        }

        return sha1(implode('|', [
            self::normalize((string) ($attributes['message'] ?? '')),
            (string) ($attributes['level'] ?? ''),
            (string) ($attributes['channel'] ?? ''),
        ]));
    }

    /**
     * "class|file|line" for an exception in context, or null when there isn't
     * one and the message has to carry the fingerprint instead.
     *
     * Context reaches here sanitised on both capture paths, so the exception
     * is normally the array ContextSanitizer::sanitizeException() produced. A
     * caller going straight to LogWriter::write() can still pass the raw
     * Throwable, and that must fingerprint identically — hence the same
     * caller-frame resolution on both branches.
     */
    private function exceptionIdentity(mixed $context): ?string
    {
        if (! is_array($context) || ! isset($context['exception'])) {
            return null;
        }

        $exception = $context['exception'];

        if ($exception instanceof Throwable) {
            $frame = $this->callerFrameForException($exception);

            return implode('|', [
                get_class($exception),
                $frame['file'] ?? $exception->getFile(),
                (string) ($frame['line'] ?? $exception->getLine()),
            ]);
        }

        if (! is_array($exception) || ($exception['_type'] ?? null) !== 'exception') {
            return null;
        }

        // A sanitised exception always carries class/file/line, but a context
        // hand-built by an application might not. Without a class there is no
        // identity worth grouping on, so fall through to the message.
        if (! isset($exception['class'])) {
            return null;
        }

        return implode('|', [
            (string) $exception['class'],
            (string) ($exception['file'] ?? ''),
            (string) ($exception['line'] ?? ''),
        ]);
    }
}
