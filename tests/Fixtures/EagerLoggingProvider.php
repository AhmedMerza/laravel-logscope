<?php

declare(strict_types=1);

namespace LogScope\Tests\Fixtures;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

/**
 * Test fixture: a service provider that emits a log in its boot() method.
 *
 * When this provider is registered BEFORE LogScopeServiceProvider in the
 * provider array, its boot() runs before LogScope's boot(). For the log to
 * be captured, LogScope must register its MessageLogged listener in
 * register() (which runs in the register phase, before any boot phase),
 * not in boot().
 */
class EagerLoggingProvider extends ServiceProvider
{
    public function boot(): void
    {
        Log::error('eager-provider-boot-log');
    }
}
