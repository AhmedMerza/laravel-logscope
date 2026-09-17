<?php

declare(strict_types=1);

namespace LogScope\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use LogScope\Contracts\ContextSanitizerInterface;
use Symfony\Component\HttpFoundation\Response;

class CaptureRequestContext
{
    public function __construct(
        protected ContextSanitizerInterface $sanitizer
    ) {}

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
        //
        // The whole bag goes through toValidUtf8Deep() rather than the one
        // field known to carry raw client bytes: Laravel serializes this bag
        // into every job the HOST app queues during the request, where a
        // malformed byte throws InvalidPayloadException in the host's own
        // dispatch. Guarding here is what keeps the next field added below
        // from reintroducing it.
        Context::add('logscope', $this->sanitizer->toValidUtf8Deep([
            'trace_id' => $traceId,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'http_method' => $request->method(),
            'url' => $this->sanitizer->sanitizeUrl($request->fullUrl()),
            'headers' => $this->sanitizer->captureHeaders($request->headers->all()),
        ]));

        return $next($request);
    }
}
