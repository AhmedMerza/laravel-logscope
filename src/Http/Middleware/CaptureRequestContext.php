<?php

declare(strict_types=1);

namespace LogScope\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class CaptureRequestContext
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Generate a unique trace ID for this request
        $traceId = (string) Str::uuid();

        // Add context that will be automatically included in all logs
        // Note: user_id is NOT captured here because auth middleware hasn't run yet
        // It's captured at log-write time instead (see LogScopeServiceProvider)
        Context::add('logscope', [
            'trace_id' => $traceId,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'http_method' => $request->method(),
            'url' => $request->fullUrl(),
        ]);

        return $next($request);
    }
}
