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
            abort(403, 'Unauthorized access to LogScope.');
        }

        return $next($request);
    }
}
