<?php

declare(strict_types=1);

namespace LogScope\Tests\Fixtures;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stub global middleware used by MiddlewareOrderingTest to verify that
 * CaptureRequestContext is positioned BEFORE other middleware in the
 * kernel's stack — even ones registered after LogScope booted.
 */
class StubGlobalMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
