<?php

declare(strict_types=1);

namespace LogScope\Tests;

use LogScope\LogScopeServiceProvider;
use LogScope\Tests\Fixtures\EagerLoggingProvider;

/**
 * Test case for verifying behavior when another provider boots before
 * LogScope. EagerLoggingProvider is registered FIRST so its boot() runs
 * before LogScope's boot() — the only way LogScope can capture its log
 * is to register its MessageLogged listener during register(), not boot().
 */
abstract class EagerProviderTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            EagerLoggingProvider::class,
            LogScopeServiceProvider::class,
        ];
    }
}
