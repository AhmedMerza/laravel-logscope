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
        Context::add('logscope', [
            'trace_id' => $traceId,
            'user_id' => $request->user()?->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'http_method' => $request->method(),
            'url' => $request->fullUrl(),
        ]);

        $response = $next($request);

        // Update context with response status
        Context::add('logscope', array_merge(
            Context::get('logscope', []),
            ['http_status' => $response->getStatusCode()]
        ));

        return $response;
    }
}
