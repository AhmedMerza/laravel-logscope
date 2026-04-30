<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use LogScope\Http\Middleware\CaptureRequestContext;
use LogScope\Tests\Fixtures\StubGlobalMiddleware;

/**
 * Read the kernel's global middleware list via reflection. Laravel doesn't
 * expose middleware order publicly, but the order is the property under test.
 *
 * @return list<class-string>
 */
function readMiddlewareStack(Kernel $kernel): array
{
    $reflection = new ReflectionProperty($kernel, 'middleware');
    $reflection->setAccessible(true);

    return $reflection->getValue($kernel);
}

it('registers CaptureRequestContext at the front of the global middleware stack', function () {
    $middleware = readMiddlewareStack($this->app->make(Kernel::class));

    // Currently LogScope is the only prependMiddleware caller in the test
    // setup, so it lands at index 0. Other PRs/packages that also prepend
    // would compete — see the relative-ordering test below for the more
    // robust contract.
    expect(array_search(CaptureRequestContext::class, $middleware, true))->toBe(0);
});

it('runs before middleware that another package pushes after LogScope booted', function () {
    $kernel = $this->app->make(Kernel::class);

    // Simulate another package adding a global middleware AFTER LogScope's
    // service provider booted. CaptureRequestContext must remain ahead of it.
    $kernel->pushMiddleware(StubGlobalMiddleware::class);

    $middleware = readMiddlewareStack($kernel);
    $captureIdx = array_search(CaptureRequestContext::class, $middleware, true);
    $stubIdx = array_search(StubGlobalMiddleware::class, $middleware, true);

    expect($captureIdx)->not->toBeFalse()
        ->and($stubIdx)->not->toBeFalse()
        ->and($captureIdx)->toBeLessThan($stubIdx);
});

it('does not crash when the HTTP kernel is unavailable', function () {
    // Drop the HTTP kernel binding so make() throws — simulating console-only
    // or custom-kernel apps where no HTTP kernel is bound. registerMiddleware
    // should swallow the resolution failure instead of crashing service-provider
    // boot.
    $this->app->offsetUnset(Kernel::class);

    $provider = new \LogScope\LogScopeServiceProvider($this->app);

    // Use reflection to invoke the protected registerMiddleware directly.
    $method = (new ReflectionClass($provider))->getMethod('registerMiddleware');
    $method->setAccessible(true);

    expect(fn () => $method->invoke($provider))->not->toThrow(\Throwable::class);
});

it('does not crash when the resolved kernel lacks prependMiddleware', function () {
    // Bind a stub kernel that doesn't implement prependMiddleware — emulates
    // a custom kernel that doesn't extend Foundation's HTTP kernel.
    $this->app->bind(Kernel::class, fn () => new class
    {
        // intentionally empty — no prependMiddleware/pushMiddleware
    });

    $provider = new \LogScope\LogScopeServiceProvider($this->app);

    $method = (new ReflectionClass($provider))->getMethod('registerMiddleware');
    $method->setAccessible(true);

    expect(fn () => $method->invoke($provider))->not->toThrow(\Throwable::class);
});
