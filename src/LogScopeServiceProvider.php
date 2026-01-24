<?php

declare(strict_types=1);

namespace LogScope;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use LogScope\Console\Commands\ImportCommand;
use LogScope\Console\Commands\InstallCommand;
use LogScope\Console\Commands\PruneCommand;
use LogScope\Console\Commands\SeedCommand;
use LogScope\Http\Middleware\CaptureRequestContext;
use LogScope\Jobs\WriteLogEntry;
use LogScope\Logging\AddChannelToContext;
use LogScope\Logging\ChannelContextProcessor;
use LogScope\Logging\LogScopeHandler;
use LogScope\Models\LogEntry;
use Throwable;

class LogScopeServiceProvider extends ServiceProvider
{
    /**
     * Buffer for batch write mode.
     */
    protected static array $logBuffer = [];

    /**
     * Whether terminating callback is registered.
     */
    protected static bool $terminatingRegistered = false;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/logscope.php',
            'logscope'
        );

        // Register channel processor early, before any channels are resolved
        $this->app->booting(function () {
            $this->registerChannelProcessor();
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
        $this->registerLogListener();
    }

    /**
     * Register the global log listener for 'all' capture mode.
     */
    protected function registerLogListener(): void
    {
        if (config('logscope.capture', 'all') !== 'all') {
            return;
        }

        Event::listen(MessageLogged::class, function (MessageLogged $event) {
            // Prevent infinite loops - don't log our own operations
            if ($this->isInternalLog($event)) {
                return;
            }

            // Skip if LogScopeHandler already captured this log (prevents duplicates
            // when 'logscope' channel is included in a stack alongside 'all' capture mode)
            if (LogScopeHandler::didHandleCurrentLog()) {
                return;
            }

            // Check if this log should be ignored based on config
            $channel = ChannelContextProcessor::getLastChannel();
            if ($this->shouldIgnoreLog($event, $channel)) {
                return;
            }

            try {
                // Get request context from Laravel Context (set by middleware)
                $requestContext = Context::get('logscope', []);

                // Get user_id at log-write time (after auth middleware has run)
                $userId = null;
                $customContext = [];
                if (app()->bound('request')) {
                    $userId = request()->user()?->id;
                    $customContext = LogScope::getCapturedContext(request());
                }

                $data = [
                    'level' => $event->level,
                    'message' => $event->message,
                    'context' => $this->sanitizeContext(array_merge($event->context, $customContext)),
                    'channel' => $channel ?? config('logging.default'),
                    'source' => $this->extractSource($event->context),
                    'source_line' => $this->extractSourceLine($event->context),
                    'trace_id' => $requestContext['trace_id'] ?? null,
                    'user_id' => $userId,
                    'ip_address' => $requestContext['ip_address'] ?? null,
                    'user_agent' => $requestContext['user_agent'] ?? null,
                    'http_method' => $requestContext['http_method'] ?? null,
                    'url' => $requestContext['url'] ?? null,
                    'occurred_at' => now(),
                ];

                $this->writeLog($data);
            } catch (Throwable $e) {
                // Silently fail - don't break the application
                if (config('app.debug')) {
                    error_log('LogScope: Failed to write log entry: '.$e->getMessage());
                }
            }
        });
    }

    /**
     * Write a log entry based on the configured write mode.
     */
    protected function writeLog(array $data): void
    {
        $mode = config('logscope.write_mode', 'batch');

        match ($mode) {
            'sync' => LogEntry::createEntry($data),
            'queue' => WriteLogEntry::dispatch($data),
            'batch' => $this->bufferLog($data),
            default => LogEntry::createEntry($data),
        };
    }

    /**
     * Buffer a log entry for batch writing.
     */
    protected function bufferLog(array $data): void
    {
        self::$logBuffer[] = $data;

        // Register terminating callback once
        if (! self::$terminatingRegistered) {
            self::$terminatingRegistered = true;

            $this->app->terminating(function () {
                $this->flushLogBuffer();
            });
        }
    }

    /**
     * Flush the log buffer to the database.
     */
    protected function flushLogBuffer(): void
    {
        if (empty(self::$logBuffer)) {
            return;
        }

        try {
            foreach (self::$logBuffer as $data) {
                LogEntry::createEntry($data);
            }
        } catch (Throwable $e) {
            if (config('app.debug')) {
                error_log('LogScope: Failed to flush log buffer: '.$e->getMessage());
            }
        } finally {
            self::$logBuffer = [];
        }
    }

    /**
     * Check if this is an internal log that should be skipped.
     */
    protected function isInternalLog(MessageLogged $event): bool
    {
        // Skip logs from our own namespace
        if (str_contains($event->message, 'LogScope')) {
            return true;
        }

        // Check context for LogScope markers
        if (isset($event->context['_logscope_internal'])) {
            return true;
        }

        return false;
    }

    /**
     * Check if this log should be ignored based on config settings.
     */
    protected function shouldIgnoreLog(MessageLogged $event, ?string $channel): bool
    {
        // Check if we should ignore deprecation messages
        if (config('logscope.ignore.deprecations', false)) {
            if (str_contains($event->message, 'is deprecated')) {
                return true;
            }
        }

        // Check if we should ignore logs without a channel
        if (config('logscope.ignore.null_channel', false)) {
            if ($channel === null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sanitize context array for storage.
     */
    protected function sanitizeContext(array $context): array
    {
        $sanitized = [];

        foreach ($context as $key => $value) {
            // Skip internal keys
            if (str_starts_with((string) $key, '__') || str_starts_with((string) $key, '_logscope')) {
                continue;
            }

            if ($value instanceof Throwable) {
                $sanitized[$key] = [
                    '_type' => 'exception',
                    'class' => get_class($value),
                    'message' => $value->getMessage(),
                    'code' => $value->getCode(),
                    'file' => $value->getFile(),
                    'line' => $value->getLine(),
                ];
            } elseif (is_object($value)) {
                $sanitized[$key] = '[Object: '.get_class($value).']';
            } elseif (is_array($value)) {
                $sanitized[$key] = $value;
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Extract source file from context.
     */
    protected function extractSource(array $context): ?string
    {
        if (isset($context['exception']) && $context['exception'] instanceof Throwable) {
            return $context['exception']->getFile();
        }

        return null;
    }

    /**
     * Extract source line from context.
     */
    protected function extractSourceLine(array $context): ?int
    {
        if (isset($context['exception']) && $context['exception'] instanceof Throwable) {
            return $context['exception']->getLine();
        }

        return null;
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
}
