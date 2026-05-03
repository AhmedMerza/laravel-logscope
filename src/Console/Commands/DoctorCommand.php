<?php

declare(strict_types=1);

namespace LogScope\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use LogScope\LogScope;
use LogScope\Models\LogEntry;
use LogScope\Services\WriteFailureLogger;
use Throwable;

class DoctorCommand extends Command
{
    protected $signature = 'logscope:doctor
                            {--json : Output a machine-readable summary instead of a table}';

    protected $description = 'Diagnose LogScope configuration, wiring, and recent write failures';

    /**
     * Accumulated check results, in display order.
     *
     * @var array<int, array{status: string, label: string, detail: string}>
     */
    protected array $results = [];

    public function handle(): int
    {
        $this->checkTable();
        $this->checkCaptureMode();
        $this->checkWriteMode();
        $this->checkMiddleware();
        $this->checkRetention();
        $this->checkAuthResolution();
        $this->checkOctaneIntegration();
        $this->checkBuiltAssets();
        $this->checkRecentFailures();

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'results' => $this->results,
                'summary' => $this->summary(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $this->exitCode();
        }

        $this->renderTable();

        return $this->exitCode();
    }

    protected function checkTable(): void
    {
        $table = (string) config('logscope.table', 'log_entries');

        try {
            if (! Schema::hasTable($table)) {
                $this->markFail('Table', "{$table} does not exist — run `php artisan migrate`");

                return;
            }

            $count = LogEntry::query()->count();
            $this->markPass('Table', "{$table} exists ({$this->formatCount($count)} rows)");
        } catch (Throwable $e) {
            $this->markFail('Table', "could not query {$table}: ".$e->getMessage());
        }
    }

    protected function checkCaptureMode(): void
    {
        $mode = (string) config('logscope.capture', 'all');

        if ($mode === 'all') {
            $this->markPass('Capture mode', "all (global MessageLogged listener)");

            return;
        }

        if ($mode === 'channel') {
            $channels = (array) config('logging.channels', []);

            if (! isset($channels['logscope'])) {
                $this->markFail('Capture mode', "channel mode is set but no `logscope` channel is defined in config/logging.php");

                return;
            }

            $this->markPass('Capture mode', 'channel (explicit logscope channel)');

            return;
        }

        $this->markWarn('Capture mode', "unknown value `{$mode}` — falls back to `all`");
    }

    protected function checkWriteMode(): void
    {
        $mode = (string) config('logscope.write_mode', 'batch');

        if (! in_array($mode, ['sync', 'batch', 'queue'], true)) {
            $this->markWarn('Write mode', "unknown value `{$mode}` — falls back to sync");

            return;
        }

        if ($mode !== 'queue') {
            $this->markPass('Write mode', $mode);

            return;
        }

        $connectionName = config('logscope.queue.connection') ?: config('queue.default');
        $queueName = (string) config('logscope.queue.name', 'default');
        $known = (array) config('queue.connections', []);

        if (! isset($known[$connectionName])) {
            $this->markFail('Write mode', "queue mode set, but connection `{$connectionName}` is not defined in config/queue.php");

            return;
        }

        $driver = $known[$connectionName]['driver'] ?? 'unknown';

        if ($driver === 'sync') {
            $this->markWarn('Write mode', "queue mode using `sync` driver — writes happen inline, no worker needed");

            return;
        }

        $this->markPass('Write mode', "queue → connection={$connectionName} ({$driver}), queue={$queueName} (worker required)");
    }

    protected function checkMiddleware(): void
    {
        if (! config('logscope.middleware.enabled', true)) {
            $this->markWarn('Middleware', 'disabled — log entries will lack trace_id/ip_address/url');

            return;
        }

        try {
            $kernel = $this->getLaravel()->make(Kernel::class);
        } catch (Throwable) {
            $this->markWarn('Middleware', 'no HTTP kernel bound — fine for console-only contexts');

            return;
        }

        if (! method_exists($kernel, 'prependMiddleware')) {
            $this->markWarn('Middleware', 'HTTP kernel does not support prependMiddleware() — context middleware not registered');

            return;
        }

        $this->markPass('Middleware', 'CaptureRequestContext prepended to global stack');
    }

    protected function checkRetention(): void
    {
        $enabled = (bool) config('logscope.retention.enabled', true);

        if (! $enabled) {
            $this->markWarn('Retention', 'disabled — `logscope:prune` is a no-op until you set retention.enabled=true');

            return;
        }

        $days = (int) config('logscope.retention.days', 30);
        $auto = (bool) config('logscope.retention.auto_schedule', false);
        $at = (string) config('logscope.retention.schedule_at', '03:00');

        if ($auto) {
            $this->markPass('Retention', "{$days}-day window, auto-scheduled daily at {$at}");

            return;
        }

        // auto_schedule is off — try to detect a user-registered schedule
        // entry for `logscope:prune`. If one exists, the user has wired it
        // themselves and we should PASS (not nag). If not, it's worth a
        // gentle nudge that pruning won't happen automatically.
        if ($this->hasUserScheduledPrune()) {
            $this->markPass('Retention', "{$days}-day window, prune is scheduled by your app");

            return;
        }

        $this->markWarn('Retention', "{$days}-day window, but no schedule for `logscope:prune` was detected — set retention.auto_schedule=true or wire it in your console kernel");
    }

    /**
     * Best-effort detection of a user-defined `logscope:prune` schedule
     * entry. Resolves the Schedule binding (which forces every console
     * provider to register its scheduled tasks) and scans for a matching
     * command. Falls back to false on any error — the doctor command
     * shouldn't crash because the scheduler couldn't be built.
     */
    protected function hasUserScheduledPrune(): bool
    {
        try {
            $schedule = $this->getLaravel()->make(Schedule::class);

            foreach ($schedule->events() as $event) {
                if (str_contains((string) ($event->command ?? ''), 'logscope:prune')) {
                    return true;
                }
            }
        } catch (Throwable) {
            // Schedule not bindable in this context — treat as "unknown",
            // caller falls back to the default WARN.
        }

        return false;
    }

    protected function checkAuthResolution(): void
    {
        if (LogScope::$authUsing !== null) {
            $this->markPass('Authorization', 'custom callback registered via LogScope::auth()');

            return;
        }

        if (Gate::has('viewLogScope')) {
            $this->markPass('Authorization', 'gate `viewLogScope` defined');

            return;
        }

        if ($this->getLaravel()->environment('local')) {
            $this->markWarn('Authorization', 'no callback or gate — falling back to local-only access');

            return;
        }

        $this->markFail('Authorization', 'no callback, no gate, and not in local env — UI is INACCESSIBLE. Register LogScope::auth() or define the `viewLogScope` gate.');
    }

    protected function checkOctaneIntegration(): void
    {
        if (! class_exists(\Laravel\Octane\Events\RequestTerminated::class)) {
            $this->markPass('Octane', 'not installed — nothing to do');

            return;
        }

        $hasTerminated = Event::hasListeners(\Laravel\Octane\Events\RequestTerminated::class);
        $hasReceived = Event::hasListeners(\Laravel\Octane\Events\RequestReceived::class);

        if (! $hasTerminated || ! $hasReceived) {
            $missing = [];
            if (! $hasTerminated) {
                $missing[] = 'RequestTerminated (buffer-flush trigger)';
            }
            if (! $hasReceived) {
                $missing[] = 'RequestReceived (channel-state reset)';
            }

            $this->markFail('Octane', 'detected, but missing listener(s): '.implode(', ', $missing).' — workers may leak channel state or skip flushes');

            return;
        }

        $this->markPass('Octane', 'detected — RequestTerminated flush + RequestReceived channel-reset listeners wired');
    }

    protected function checkBuiltAssets(): void
    {
        $dist = dirname(__DIR__, 3).'/dist';
        $required = ['app.css', 'alpine.min.js', 'alpine-collapse.min.js', 'logscope.js'];
        $missing = array_values(array_filter($required, fn ($f) => ! file_exists($dist.'/'.$f)));

        if (empty($missing)) {
            $this->markPass('Assets', 'all built assets present in dist/');

            return;
        }

        $this->markFail('Assets', 'missing in dist/: '.implode(', ', $missing).' — run `npm run build` in the package directory');
    }

    protected function checkRecentFailures(): void
    {
        $recent = WriteFailureLogger::recentFailures();

        if ($recent === null) {
            $this->markPass('Recent write failures', 'none recorded');

            return;
        }

        $count = $recent['count'];
        $where = $recent['last_where'] !== '' ? " [{$recent['last_where']}]" : '';
        $detail = "{$count} failure(s) since {$recent['first_at']}, last at {$recent['last_at']}{$where}: {$recent['last_class']}: {$recent['last_message']}";

        $this->markFail('Recent write failures', $detail);
    }

    protected function renderTable(): void
    {
        $rows = array_map(function (array $r): array {
            return [
                $this->statusLabel($r['status']),
                $r['label'],
                $r['detail'],
            ];
        }, $this->results);

        $this->newLine();
        $this->components->info('LogScope Doctor');
        $this->table(['Status', 'Check', 'Detail'], $rows);

        $summary = $this->summary();
        $this->components->info(
            "{$summary['pass']} passed, {$summary['warn']} warnings, {$summary['fail']} failures"
        );

        if ($summary['fail'] > 0) {
            $this->components->error('One or more checks failed — see detail column above.');
        }
    }

    protected function statusLabel(string $status): string
    {
        return match ($status) {
            'pass' => '<fg=green>PASS</>',
            'warn' => '<fg=yellow>WARN</>',
            'fail' => '<fg=red>FAIL</>',
            default => $status,
        };
    }

    /**
     * @return array{pass: int, warn: int, fail: int}
     */
    protected function summary(): array
    {
        $counts = ['pass' => 0, 'warn' => 0, 'fail' => 0];

        foreach ($this->results as $r) {
            if (isset($counts[$r['status']])) {
                $counts[$r['status']]++;
            }
        }

        return $counts;
    }

    protected function exitCode(): int
    {
        return $this->summary()['fail'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /*
     * Result accumulators are prefixed `mark*` (not `pass`/`warn`/`fail`)
     * because Illuminate\Console\Command already defines `warn(string, ?int)`
     * for terminal output. Re-declaring it with a different signature would
     * be an LSP violation — PHP would still allow it but tooling and
     * subclassers would trip over it. Keep the prefix.
     */

    protected function markPass(string $label, string $detail): void
    {
        $this->results[] = ['status' => 'pass', 'label' => $label, 'detail' => $detail];
    }

    protected function markWarn(string $label, string $detail): void
    {
        $this->results[] = ['status' => 'warn', 'label' => $label, 'detail' => $detail];
    }

    protected function markFail(string $label, string $detail): void
    {
        $this->results[] = ['status' => 'fail', 'label' => $label, 'detail' => $detail];
    }

    protected function formatCount(int $n): string
    {
        return number_format($n);
    }
}
