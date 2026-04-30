<?php

declare(strict_types=1);

namespace LogScope\Tests\Fixtures;

class ServiceWithRequiredArg
{
    public function __construct(public string $required) {}

    /**
     * Triggers a PHP-thrown return-type TypeError. getFile() will point at
     * the `return` line in this file — that location is correct, so logscope
     * should NOT remap it to the caller frame.
     */
    public static function returnsBadType(): int
    {
        /** @phpstan-ignore-next-line — intentionally returning wrong type */
        return 'not an int';
    }

    /**
     * Triggers a user-thrown TypeError. getFile() points at the throw site,
     * which is correct — logscope should NOT remap it.
     */
    public static function userThrowsTypeError(): never
    {
        throw new \TypeError('manual type error');
    }
}
