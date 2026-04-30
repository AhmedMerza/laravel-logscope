<?php

declare(strict_types=1);

namespace LogScope;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\ServiceProvider;
use LogScope\Console\Commands\ImportCommand;
use LogScope\Console\Commands\InstallCommand;
use LogScope\Console\Commands\PruneCommand;
use LogScope\Console\Commands\SeedCommand;
use LogScope\Contracts\ContextSanitizerInterface;
use LogScope\Contracts\LogBufferInterface;
use LogScope\Contracts\LogWriterInterface;
use LogScope\Http\Middleware\CaptureRequestContext;
use LogScope\Logging\AddChannelToContext;
use LogScope\Services\ContextSanitizer;
use LogScope\Services\LogBuffer;
use LogScope\Services\LogCapture;
use LogScope\Services\LogWriter;

class LogScopeServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/logscope.php',
            'logscope'
        );

        $this->registerServices();

        // Register channel processor early, before any channels are resolved
        $this->app->booting(function () {
            $this->registerChannelProcessor();
        });

        // Attach the MessageLogged listener as early as possible — in
        // register() rather than boot() — so logs emitted during another
        // provider's boot() (or any earlier-running boot phase) are captured.
        // If the listener were registered in our own boot(), any provider
        // that boots before us would have its boot-time logs silently dropped.
        //
        // Caveat: logs fired during another provider's register() (the phase
        // we are in right now) ARE captured by the listener, but the channel
        // name will be null. The Monolog channel processor that records the
        // channel name is installed in the booting() callback above, which
        // fires later. Logs from boot() onwards have correct channel
        // attribution.
        $this->registerLogCapture();

        // In long-running workers (Octane), static state survives across
        // requests. Reset ChannelContextProcessor's slot at each Octane
        // request boundary so a Log::build() log in request N+1 can never
        // inherit a stale channel from request N. Only registers when
        // Octane is actually installed — guards against pulling Octane
        // as a hard dependency.
        $this->registerOctaneStateReset();
    }

    /**
     * Register the package services.
     */
    protected function registerServices(): void
    {
        $this->app->singleton(LogBuffer::class, function ($app) {
            return new LogBuffer($app);
        });
        $this->app->alias(LogBuffer::class, LogBufferInterface::class);

        $this->app->singleton(ContextSanitizer::class, function () {
            return new ContextSanitizer;
        });
        $this->app->alias(ContextSanitizer::class, ContextSanitizerInterface::class);

        $this->app->singleton(LogWriter::class, function ($app) {
            return new LogWriter($app->make(LogBufferInterface::class));
        });
        $this->app->alias(LogWriter::class, LogWriterInterface::class);

        $this->app->singleton(LogCapture::class, function ($app) {
            return new LogCapture(
                $app->make(LogWriterInterface::class),
                $app->make(ContextSanitizerInterface::class)
            );
        });
    }

    /**
     * Register the channel processor tap class for all logging channels.
     *
     * This injects a Monolog processor that adds the channel name to the
     * log context, making it available in the MessageLogged event.
     */
    protected function registerChannelProcessor(): void
    {
        // Only needed for 'all' capture mode
        if (config('logscope.capture', 'all') !== 'all') {
            return;
        }

        $channels = config('logging.channels', []);

        foreach ($channels as $name => $config) {
            // Skip if it's a null channel or doesn't support tap
            if (($config['driver'] ?? null) === 'null') {
                continue;
            }

            // Add our tap class to inject channel name into context
            // Laravel expects tap format: 'ClassName:arg1,arg2' (string with colon separator)
            $existingTap = $config['tap'] ?? [];
            $existingTap[] = AddChannelToContext::class.':'.$name;

            config(["logging.channels.{$name}.tap" => $existingTap]);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerCommands();
        $this->registerPublishing();
        $this->registerRoutes();
        $this->registerViews();
        $this->registerMigrations();
        $this->registerMiddleware();
    }

    /**
     * Register the log capture service.
     */
    protected function registerLogCapture(): void
    {
        $this->app->make(LogCapture::class)->register();
    }

    /**
     * Register the request context middleware.
     *
     * Prepended (not pushed) so CaptureRequestContext runs FIRST in the
     * global middleware stack. If an earlier middleware were to throw,
     * the resulting log entry would have no trace_id/ip_address/url —
     * making it harder to correlate with the failing request.
     *
     * Defensive: in apps that don't bind the HTTP kernel (e.g. console-only
     * applications, or custom kernels that don't extend Foundation's), the
     * make() call may throw or the resolved object may not implement
     * prependMiddleware. Skip in those cases rather than crashing during
     * service-provider boot.
     */
    protected function registerMiddleware(): void
    {
        if (! config('logscope.middleware.enabled', true)) {
            return;
        }

        try {
            $kernel = $this->app->make(Kernel::class);
        } catch (\Throwable) {
            // No HTTP kernel bound — running in a non-HTTP context.
            return;
        }

        if (! method_exists($kernel, 'prependMiddleware')) {
            return;
        }

        $kernel->prependMiddleware(CaptureRequestContext::class);
    }

    /**
     * Reset static channel state at Octane request boundaries.
     *
     * Octane keeps the worker process alive across requests. The Monolog
     * channel processor stores the last channel name in static state.
     * Without this listener, a Log::build() log in request N+1 could
     * inherit the channel of request N's last log if a Monolog handler
     * threw (or any other rare path that orphaned `$isFresh=true`).
     *
     * Only registers if Laravel\Octane\Events\RequestReceived exists —
     * Octane is an optional peer, not a hard dependency.
     */
    protected function registerOctaneStateReset(): void
    {
        if (! class_exists(\Laravel\Octane\Events\RequestReceived::class)) {
            return;
        }

        $this->app['events']->listen(
            \Laravel\Octane\Events\RequestReceived::class,
            function (): void {
                \LogScope\Logging\ChannelContextProcessor::clearLastChannel();
            }
        );
    }

    /**
     * Register the package's commands.
     */
    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                ImportCommand::class,
                PruneCommand::class,
                SeedCommand::class,
            ]);
        }
    }

    /**
     * Register the package's publishable resources.
     */
    protected function registerPublishing(): void
    {
        if ($this->app->runningInConsole()) {
            // Config
            $this->publishes([
                __DIR__.'/../config/logscope.php' => config_path('logscope.php'),
            ], 'logscope-config');

            // Migrations (use publishes() not publishesMigrations() to keep same filename
            // so Laravel's migration tracking prevents duplicates)
            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'logscope-migrations');

            // Views
            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/logscope'),
            ], 'logscope-views');

            // Public assets (images, etc.)
            $this->publishes([
                __DIR__.'/../public' => public_path('vendor/logscope'),
            ], 'logscope-assets');
        }
    }

    /**
     * Register the package routes.
     */
    protected function registerRoutes(): void
    {
        if (config('logscope.routes.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        }
    }

    /**
     * Register the package views.
     */
    protected function registerViews(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'logscope');
    }

    /**
     * Register the package migrations.
     */
    protected function registerMigrations(): void
    {
        if (config('logscope.migrations.enabled', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }
    }

    /**
     * Flush the log buffer (for testing or manual flush).
     */
    public static function flushLogBufferStatic(): void
    {
        LogBuffer::flushStatic();
    }

    /**
     * Reset the buffer state (used for testing).
     *
     * Also resets WriteGuard's depth counter — if a previous test crashed
     * mid-`during()` block, the static depth could be left > 0 and silently
     * skip captures in every subsequent test.
     */
    public static function resetBufferState(): void
    {
        LogBuffer::reset();
        \LogScope\Services\WriteGuard::reset();
    }
}
