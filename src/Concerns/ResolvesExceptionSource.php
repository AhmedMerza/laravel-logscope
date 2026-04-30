<?php

declare(strict_types=1);

namespace LogScope\Concerns;

use ArgumentCountError;
use Throwable;
use TypeError;

/**
 * Resolves the user-code call site for exceptions PHP throws from inside the
 * callee. For ArgumentCountError and argument-validation TypeError, PHP's
 * getFile()/getLine() point at the function's declaration, not where the
 * caller wrote the broken `new`/call. We use the first trace frame instead.
 *
 * Other exceptions (including return-type TypeError and user-thrown
 * `throw new TypeError(...)`) keep getFile()/getLine() — those locations are
 * already correct.
 */
trait ResolvesExceptionSource
{
    /**
     * Return the caller frame for arg-validation errors, or null when the
     * exception's own getFile()/getLine() should be used as-is.
     *
     * @return array{file: string, line: ?int}|null
     */
    protected function callerFrameForException(Throwable $exception): ?array
    {
        if (! $this->shouldUseTraceTopForException($exception)) {
            return null;
        }

        $trace = $exception->getTrace();
        if (empty($trace[0]['file'])) {
            return null;
        }

        return [
            'file' => $trace[0]['file'],
            'line' => $trace[0]['line'] ?? null,
        ];
    }

    /**
     * Whether this exception's getFile()/getLine() refers to the callee's
     * declaration site rather than the caller's code.
     *
     * - ArgumentCountError is always thrown by PHP for arg-count mismatches.
     * - TypeError messages starting with "Argument #" are PHP's arg-type
     *   validation errors. PHP's return-type errors say "Return value must
     *   be of type ..." (no "Argument #"), so they are correctly skipped.
     * - User-thrown `throw new TypeError('...')` won't contain "Argument #"
     *   unless the user explicitly mimics PHP's wording.
     */
    protected function shouldUseTraceTopForException(Throwable $exception): bool
    {
        if ($exception instanceof ArgumentCountError) {
            return true;
        }

        if ($exception instanceof TypeError && str_contains($exception->getMessage(), 'Argument #')) {
            return true;
        }

        return false;
    }
}
