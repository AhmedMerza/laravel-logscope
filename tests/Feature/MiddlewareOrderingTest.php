<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use LogScope\Http\Middleware\CaptureRequestContext;

it('registers CaptureRequestContext at the front of the global middleware stack', function () {
    $kernel = $this->app->make(Kernel::class);

    // Reach into the kernel's middleware list — Laravel doesn't expose
    // ordering via a public API, but the order is what we actually care
    // about: CaptureRequestContext must run before any other global
    // middleware so trace_id/ip_address/url are present in Context if
    // an earlier middleware throws.
    $reflection = new ReflectionProperty($kernel, 'middleware');
    $reflection->setAccessible(true);
    $middleware = $reflection->getValue($kernel);

    $position = array_search(CaptureRequestContext::class, $middleware, true);

    expect($position)->toBe(0);
});
