<?php

declare(strict_types=1);

namespace LogScope\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes every v1 API request expect JSON, so a missing token gets a JSON 401
 * instead of a redirect to a `login` route the host may not have.
 */
class ForceJson
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
