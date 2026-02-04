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
        $this->registerLogCapture();
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
     */
    protected function registerMiddleware(): void
    {
        if (config('logscope.middleware.enabled', true)) {
            $kernel = $this->app->make(Kernel::class);
            $kernel->pushMiddleware(CaptureRequestContext::class);
        }
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
     */
    public static function resetBufferState(): void
    {
        LogBuffer::reset();
    }
}
