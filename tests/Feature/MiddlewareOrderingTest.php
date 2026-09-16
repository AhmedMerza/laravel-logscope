<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Middleware\TrustProxies;
use LogScope\Http\Middleware\CaptureRequestContext;
use LogScope\Tests\Fixtures\StubGlobalMiddleware;

/**
 * Invoke the provider's protected registerMiddleware() against the current app.
 */
function registerMiddlewareAgain(): void
{
    $provider = new \LogScope\LogScopeServiceProvider(app());

    $method = (new ReflectionClass($provider))->getMethod('registerMiddleware');
    $method->setAccessible(true);
    $method->invoke($provider);
}

it('registers CaptureRequestContext directly after TrustProxies', function () {
    // Anywhere earlier and $request->ip() predates TrustProxies handing
    // Symfony its trusted-proxy list, so behind a load balancer every entry
    // records the balancer's address (#54).
    $middleware = $this->app->make(Kernel::class)->getGlobalMiddleware();

    $trustProxies = array_search(TrustProxies::class, $middleware, true);

    expect($trustProxies)->not->toBeFalse()
        ->and(array_search(CaptureRequestContext::class, $middleware, true))->toBe($trustProxies + 1);
});

it('runs before middleware that another package pushes after LogScope booted', function () {
    $kernel = $this->app->make(Kernel::class);

    // Simulate another package adding a global middleware AFTER LogScope's
    // service provider booted. CaptureRequestContext must remain ahead of it.
    $kernel->pushMiddleware(StubGlobalMiddleware::class);

    $middleware = $kernel->getGlobalMiddleware();
    $captureIdx = array_search(CaptureRequestContext::class, $middleware, true);
    $stubIdx = array_search(StubGlobalMiddleware::class, $middleware, true);

    expect($captureIdx)->not->toBeFalse()
        ->and($stubIdx)->not->toBeFalse()
        ->and($captureIdx)->toBeLessThan($stubIdx);
});

it('goes to the front of the stack when TrustProxies is not registered', function () {
    $kernel = $this->app->make(Kernel::class);
    $kernel->setGlobalMiddleware([StubGlobalMiddleware::class]);

    registerMiddlewareAgain();

    expect($kernel->getGlobalMiddleware())
        ->toBe([CaptureRequestContext::class, StubGlobalMiddleware::class]);
});

it('does not register itself twice', function () {
    // array_splice() has none of prependMiddleware()'s already-present guard,
    // so a second boot (an Octane worker reusing the kernel) would otherwise
    // run the capture — and overwrite the context — twice per request.
    $kernel = $this->app->make(Kernel::class);
    $before = $kernel->getGlobalMiddleware();

    registerMiddlewareAgain();

    expect($kernel->getGlobalMiddleware())->toBe($before);
});

it('does not crash when the HTTP kernel is unavailable', function () {
    // Drop the HTTP kernel binding so make() throws — simulating console-only
    // or custom-kernel apps where no HTTP kernel is bound. registerMiddleware
    // should swallow the resolution failure instead of crashing service-provider
    // boot.
    $this->app->offsetUnset(Kernel::class);

    expect(fn () => registerMiddlewareAgain())->not->toThrow(\Throwable::class);
});

it('falls back to prepending on a kernel that only supports prependMiddleware', function () {
    // A custom kernel that doesn't extend Foundation's has no global-stack
    // accessors; take the front of the stack rather than register nothing.
    $kernel = new class
    {
        public array $prepended = [];

        public function prependMiddleware($middleware)
        {
            $this->prepended[] = $middleware;
        }
    };

    $this->app->instance(Kernel::class, $kernel);

    registerMiddlewareAgain();

    expect($kernel->prepended)->toBe([CaptureRequestContext::class]);
});

it('does not crash when the resolved kernel exposes no middleware API at all', function () {
    $this->app->instance(Kernel::class, new class
    {
        // intentionally empty — no prependMiddleware/getGlobalMiddleware
    });

    expect(fn () => registerMiddlewareAgain())->not->toThrow(\Throwable::class);
});
