<?php

declare(strict_types=1);

namespace LogScope;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\ServiceProvider;
use LogScope\Console\Commands\DoctorCommand;
use LogScope\Console\Commands\ImportCommand;
use LogScope\Console\Commands\InstallCommand;
use LogScope\Console\Commands\PruneCommand;
use LogScope\Console\Commands\SeedCommand;
use LogScope\Console\Commands\TestCommand;
use LogScope\Contracts\ContextSanitizerInterface;
use LogScope\Contracts\LogBufferInterface;
use LogScope\Contracts\LogWriterInterface;
use LogScope\Http\Middleware\CaptureRequestContext;
use LogScope\Logging\AddChannelToContext;
use LogScope\Models\LogEntry;
use LogScope\Services\ContextSanitizer;
use LogScope\Services\FallbackWriter;
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

        // Register our buffer-flush callback as early as possible so we're
        // ahead of most user-provider terminate callbacks in the chain.
        // Laravel's Application::terminate() runs callbacks in registration
        // order with NO try/catch around each — if a later-registered
        // callback throws, our flush would still run; but if a callback
        // registered before ours throws, we'd be skipped. Registering in
        // register() (instead of lazily on first add()) puts us as early
        // in the user-provider phase as possible.
        //
        // For Octane specifically, also wire RequestTerminated as an
        // independent flush trigger that survives even when Laravel's
        // terminate callback chain is broken by an earlier throw.
        $this->registerEagerFlushCallbacks();
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

        $this->app->singleton(FallbackWriter::class, function () {
            return new FallbackWriter;
        });

        $this->app->singleton(LogCapture::class, function ($app) {
            return new LogCapture(
                $app->make(LogWriterInterface::class),
                $app->make(ContextSanitizerInterface::class),
                $app->make(FallbackWriter::class)
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
        $this->applyTestingEnvironmentDefaults();
        $this->registerCommands();
        $this->registerPublishing();
        $this->registerRoutes();
        $this->registerViews();
        $this->registerMigrations();
        $this->registerMiddleware();
        $this->registerScheduledTasks();
    }

    /**
     * Mirror Laravel's "sensible defaults in tests" pattern (mail=array,
     * queue=sync, cache=array) when the app environment is 'testing': logs
     * are written immediately, so a test can assert on them.
     *
     * write_mode is forced to 'sync'. Without this, the package-default
     * 'batch' accumulates entries across tests that never trigger
     * Application::terminate(); the leftover buffer is then discarded at
     * PHP shutdown — emitting a noisy "Discarded N buffered log entries"
     * line and silently losing the captured logs.
     *
     * Users who specifically want to exercise batch behavior in a test
     * can still opt back in via `config(['logscope.write_mode' => 'batch'])`
     * inside the test's setUp(); this default runs once at boot time and
     * does not re-assert.
     *
     * Caveat: logs fired during *other* service providers' register()
     * phase run before our boot(), so they still see write_mode = batch
     * and land in the buffer. LogBuffer's Layer-2 testing-env cache
     * silences the resulting shutdown discard so the user impact is
     * limited to the buffered entries being lost (which is the same
     * outcome they'd get without LogScope installed).
     */
    protected function applyTestingEnvironmentDefaults(): void
    {
        if (! $this->app->environment('testing')) {
            return;
        }

        config([
            'logscope.write_mode' => 'sync',
            // RefreshDatabase wraps every test in a transaction it never
            // commits, and unsets the event dispatcher around it — so
            // transactionLevel() never reaches 0 and no committed/rolled-back
            // event ever fires. Deferring there would buffer every log in the
            // suite and flush none of them, breaking any test that asserts a
            // log row exists. Nothing distinguishes that wrapping transaction
            // from a real one at runtime, so testing writes stay immediate,
            // protected by savepoints as before (#45).
            //
            // Tests that want the real behaviour opt back in with
            // config(['logscope.defer_in_transactions' => true]) — see
            // tests/Transactions, which avoids RefreshDatabase for this
            // reason.
            'logscope.defer_in_transactions' => false,
        ]);
    }

    /**
     * Opt-in scheduling for `logscope:prune`.
     *
     * Off by default. When the user sets `logscope.retention.auto_schedule`
     * to true, we register the prune command on Laravel's scheduler so they
     * don't have to wire it themselves.
     *
     * Uses callAfterResolving so we don't force-construct the Schedule
     * binding in HTTP requests that never touch it. ->onOneServer() guards
     * multi-server deploys against duplicate prune runs (requires a cache
     * driver that supports atomic locks; Laravel falls back to a single-
     * server run with a warning if not).
     */
    protected function registerScheduledTasks(): void
    {
        if (! config('logscope.retention.auto_schedule', false)) {
            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $at = (string) config('logscope.retention.schedule_at', '03:00');

            $schedule->command('logscope:prune')
                ->dailyAt($at)
                ->onOneServer()
                ->name('logscope:prune-auto');
        });
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
     * Inserted directly AFTER TrustProxies, not prepended: `$request->ip()`
     * only honours `X-Forwarded-For` once TrustProxies has handed Symfony the
     * trusted-proxy list, so capturing any earlier records the load balancer's
     * address as `ip_address` for every request behind a proxy. With no
     * TrustProxies in the global stack it goes first, as it always did.
     *
     * The cost of running second: if one of the few middleware ahead of
     * TrustProxies throws (ValidatePathEncoding, TrustHosts), that log entry
     * has no trace_id/ip_address/url to correlate with the failing request.
     *
     * Defensive: in apps that don't bind the HTTP kernel (e.g. console-only
     * applications, or custom kernels that don't extend Foundation's), the
     * make() call may throw or the resolved object may not expose the global
     * middleware stack. Skip in those cases rather than crashing during
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

        if (! method_exists($kernel, 'getGlobalMiddleware') || ! method_exists($kernel, 'setGlobalMiddleware')) {
            // Custom kernel without the Foundation stack accessors: take the
            // front of the stack if it will have us, otherwise skip. The stack
            // isn't readable here, so this can't place us after TrustProxies
            // and can't dedupe a second boot — both are the kernel's own to
            // handle. `logscope:doctor` reports the position it can't verify.
            if (method_exists($kernel, 'prependMiddleware')) {
                $kernel->prependMiddleware(CaptureRequestContext::class);
            }

            return;
        }

        $middleware = $kernel->getGlobalMiddleware();

        // prependMiddleware() skipped a middleware already in the stack;
        // array_splice() doesn't, so keep the guard ourselves rather than
        // capture (and overwrite) the request context twice per request.
        if (in_array(CaptureRequestContext::class, $middleware, true)) {
            return;
        }

        // is_a() also matches an app's own subclass, e.g. App\Http\Middleware\TrustProxies
        $trustProxies = collect($middleware)->search(
            fn ($class) => is_string($class) && is_a($class, TrustProxies::class, true)
        );

        array_splice($middleware, $trustProxies === false ? 0 : $trustProxies + 1, 0, [CaptureRequestContext::class]);

        // Known and accepted: setGlobalMiddleware() calls syncMiddlewareToRouter(),
        // which re-applies the kernel's middleware groups, priority and aliases over
        // the router's. A group entry pushed straight onto the Router — rather than
        // through the kernel — before we boot is therefore lost. It is the only
        // public API for reordering the global stack, the window is a register()-phase
        // push, and laravel-watchtower registers the same way, so both packages stay
        // consistent. prependMiddleware() avoided this only by never reordering.
        $kernel->setGlobalMiddleware($middleware);
    }

    /**
     * Reset static state at Octane request boundaries.
     *
     * Octane keeps the worker process alive across requests, so any
     * per-process static state will leak across them unless we clear it.
     * Two consumers today:
     *
     * - ChannelContextProcessor's "last channel" slot — without reset,
     *   a Log::build() log in request N+1 could inherit the channel of
     *   request N's last log if a Monolog handler threw.
     *
     * - FallbackWriter's per-failure occurrence map — without reset, a
     *   long-running worker would only ever emit ONE fallback row per
     *   failure key for its entire lifetime (minus heartbeats every
     *   REEMIT_EVERY). Resetting per-request scopes the dedupe to the
     *   request, matching the natural mental model for ops dashboards.
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
                FallbackWriter::reset();
            }
        );
    }

    /**
     * Register flush callbacks as early as possible.
     *
     * - app->terminating(): runs at end of every Laravel request lifecycle.
     *   Registered eagerly here (not lazily on first log) so we're as early
     *   in the callback chain as we can be — minimizes the chance an
     *   earlier-registered callback throws and skips us.
     * - register_shutdown_function(): backup for CLI/HTTP scenarios where
     *   the terminate chain didn't reach us. Doesn't help in Octane (only
     *   fires on worker death).
     * - Octane RequestTerminated: independent flush trigger that survives
     *   even if Laravel's terminate callback chain is broken. Octane is an
     *   optional peer — only registers if installed.
     * - Queue Looping: fires before a worker takes each job, and on every
     *   poll while idle, so a daemon flushes a job's logs once it finishes
     *   instead of when the worker exits (#28).
     * - Queue WorkerStopping: fires before a timed-out job makes the worker
     *   SIGKILL itself, which skips the shutdown function — the only chance
     *   to write the logs of the job that hung. If it hung inside a database
     *   transaction, the insert joins that transaction and the kill rolls it
     *   back; skipping the flush would lose the logs just the same.
     *
     * Note on cost: we register unconditionally regardless of write_mode.
     * In sync/queue modes the buffer is always empty, so flushStatic
     * early-returns — the per-request cost is one closure call returning
     * `if (empty($buffer)) return;`. Negligible. Gating on write_mode at
     * register time would miss runtime config changes (`config([...])`)
     * and add a footgun for marginal benefit.
     *
     * Note on Octane double-flush: in Octane, a request fires BOTH
     * Application::terminate() (running our `terminating` callback) AND
     * Octane's RequestTerminated event (running our second listener).
     * Step 2 is intentionally redundant — the buffer is already drained
     * by step 1, so the second call is a no-op. The redundancy gives us
     * a recovery path if the terminate chain is broken by an earlier
     * throwing callback (the original bug this fix addresses).
     */
    protected function registerEagerFlushCallbacks(): void
    {
        // Internal try/catch wraps our own flush so an exception inside it
        // can't propagate out and break OTHER terminate callbacks downstream.
        // Exceptions surface via error_log only — tests asserting flush
        // success should inspect error_log content (see WriteFailureLogger
        // test pattern) rather than expecting the exception to bubble.
        $flushSafely = static function (): void {
            try {
                LogBuffer::flushStatic();
            } catch (\Throwable $e) {
                error_log('LogScope: Failed to flush buffer at terminate: ['.get_class($e).'] '.$e->getMessage());
            }
        };

        $this->app->terminating($flushSafely);

        // Entries deferred out of the app's transaction (#45) are written as
        // soon as it ends — committed or rolled back, since a rollback's logs
        // are usually the ones that explain it. Laravel fires these events for
        // nested levels and savepoint rollbacks too, so wait for the outermost
        // one to close. The batch buffer is left alone: an unrelated commit is
        // no reason to write it early.
        $this->app['events']->listen(
            [TransactionCommitted::class, TransactionRolledBack::class],
            static function () use ($flushSafely): void {
                if (! LogBuffer::hasDeferredEntries()) {
                    return;
                }

                // Our own connection is what matters: the event may come from
                // another one that has nothing to do with log_entries.
                if ((new LogEntry)->getConnection()->transactionLevel() !== 0) {
                    return;
                }

                $flushSafely();
            }
        );

        if (! LogBuffer::shutdownFunctionRegistered()) {
            register_shutdown_function($flushSafely);
            LogBuffer::markShutdownFunctionRegistered();
        }

        if (class_exists(\Laravel\Octane\Events\RequestTerminated::class)) {
            $this->app['events']->listen(
                \Laravel\Octane\Events\RequestTerminated::class,
                $flushSafely
            );
        }

        // Looping is dispatched with events->until(), so a listener returning
        // false would pause the worker — $flushSafely returns null.
        if (class_exists(\Illuminate\Queue\Events\Looping::class)) {
            $this->app['events']->listen(
                [
                    \Illuminate\Queue\Events\Looping::class,
                    \Illuminate\Queue\Events\WorkerStopping::class,
                ],
                $flushSafely
            );
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
                DoctorCommand::class,
                TestCommand::class,
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
