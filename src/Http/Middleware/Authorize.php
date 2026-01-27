<?php

declare(strict_types=1);

namespace LogScope\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use LogScope\LogScope;
use Symfony\Component\HttpFoundation\Response;

class Authorize
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! LogScope::check($request)) {
            return $this->handleUnauthorized($request);
        }

        return $next($request);
    }

    /**
     * Handle unauthorized access.
     */
    protected function handleUnauthorized(Request $request): Response
    {
        // For API/AJAX requests, return JSON response
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Unauthorized access to LogScope.',
            ], 403);
        }

        // For web requests, redirect if configured
        $redirect = config('logscope.routes.forbidden_redirect');

        if ($redirect !== null) {
            return redirect($redirect)->with('error', 'You do not have access to LogScope.');
        }

        // Default: show 403 page
        abort(403, 'Unauthorized access to LogScope.');
    }
}
