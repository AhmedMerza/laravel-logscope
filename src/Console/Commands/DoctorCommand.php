<?php

declare(strict_types=1);

namespace LogScope\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\RequestTerminated;
use LogScope\Contracts\ContextSanitizerInterface;
use LogScope\Http\Middleware\CaptureRequestContext;
use LogScope\LogScope;
use LogScope\Models\LogEntry;
use LogScope\Models\LogGroup;
use LogScope\Services\ContextSanitizer;
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
        $this->checkGrouping();
        $this->checkCaptureMode();
        $this->checkWriteMode();
        $this->checkMiddleware();
        $this->checkRetention();
        $this->checkHeaderCapture();
        $this->checkRedaction();
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

    /**
     * Report whether entries are fingerprinted and groups are in step (#29).
     *
     * Deliberately not a count comparison. occurrence_count is a running
     * total of everything a group has ever seen, while retention deletes the
     * entries underneath it, so a group legitimately counts more occurrences
     * than it has rows the moment anything is pruned. Comparing the two would
     * warn on every healthy install with retention enabled. What is checked
     * instead is what can only be wrong: entries with no fingerprint, a
     * fingerprint with no group, a group with no entries, and a count that
     * has fallen *below* the rows still present.
     */
    protected function checkGrouping(): void
    {
        $groupsTable = (string) config('logscope.groups_table', 'log_groups');
        $enabled = config('logscope.grouping.enabled', true) ? 'grouped view on' : 'grouped view off';

        try {
            if (! Schema::hasTable($groupsTable)) {
                $this->markFail('Grouping', "{$groupsTable} does not exist — run `php artisan migrate`");

                return;
            }

            $unfingerprinted = LogEntry::query()->whereNull('fingerprint')->count();

            if ($unfingerprinted > 0) {
                $this->markWarn('Grouping', "{$this->formatCount($unfingerprinted)} entries have no fingerprint — run `php artisan logscope:backfill-fingerprints`");

                return;
            }

            $ungrouped = LogEntry::query()
                ->whereNotNull('fingerprint')
                ->whereNotExists(fn ($query) => $query
                    ->select(DB::raw(1))
                    ->from($groupsTable)
                    ->whereColumn($groupsTable.'.fingerprint', (new LogEntry)->getTable().'.fingerprint'))
                ->count();

            if ($ungrouped > 0) {
                $this->markWarn('Grouping', "{$this->formatCount($ungrouped)} fingerprinted entries have no group — run `php artisan logscope:backfill-fingerprints --groups-only`");

                return;
            }

            $orphaned = LogGroup::query()
                ->whereNotExists(fn ($query) => $query
                    ->select(DB::raw(1))
                    ->from((new LogEntry)->getTable())
                    ->whereColumn((new LogEntry)->getTable().'.fingerprint', $groupsTable.'.fingerprint'))
                ->count();

            if ($orphaned > 0) {
                $this->markWarn('Grouping', "{$this->formatCount($orphaned)} groups have no entries left — run `php artisan logscope:prune` to clear them");

                return;
            }

            $groups = LogGroup::query()->count();

            $this->markPass('Grouping', "{$this->formatCount($groups)} groups, all entries fingerprinted ({$enabled})");
        } catch (Throwable $e) {
            $this->markFail('Grouping', "could not query {$groupsTable}: ".$e->getMessage());
        }
    }

    protected function checkCaptureMode(): void
    {
        $mode = (string) config('logscope.capture', 'all');

        if ($mode === 'all') {
            $this->markPass('Capture mode', 'all (global MessageLogged listener)');

            return;
        }

        if ($mode === 'channel') {
            $channels = (array) config('logging.channels', []);

            if (! isset($channels['logscope'])) {
                $this->markFail('Capture mode', 'channel mode is set but no `logscope` channel is defined in config/logging.php');

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

        // Resolve to (string) so we never interpolate `null` or `false` into
        // the FAIL message. Coerce empty to '(unset)' so the user sees what
        // we actually looked up, not an empty backtick pair.
        $connectionName = (string) (config('logscope.queue.connection') ?: config('queue.default') ?: '');
        $queueName = (string) config('logscope.queue.name', 'default');
        $known = (array) config('queue.connections', []);

        if ($connectionName === '' || ! isset($known[$connectionName])) {
            $displayed = $connectionName === '' ? '(unset)' : $connectionName;
            $this->markFail('Write mode', "queue mode set, but connection `{$displayed}` is not defined in config/queue.php");

            return;
        }

        $driver = $known[$connectionName]['driver'] ?? 'unknown';

        if ($driver === 'sync') {
            $this->markWarn('Write mode', 'queue mode using `sync` driver — writes happen inline, no worker needed');

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

        // Mirror registerMiddleware()'s own branching: without the stack accessors it
        // prepends if it can, and registers nothing at all if it can't. Those are very
        // different outcomes for the operator, so don't report them as one.
        if (! method_exists($kernel, 'getGlobalMiddleware')) {
            if (! method_exists($kernel, 'prependMiddleware')) {
                $this->markFail('Middleware', 'HTTP kernel exposes no middleware API — nothing was registered, so log entries lack trace_id/ip_address/url');

                return;
            }

            $this->markWarn('Middleware', 'HTTP kernel does not expose its global stack — LogScope could only prepend, so the capture runs ahead of TrustProxies and behind a proxy ip_address is the proxy\'s address');

            return;
        }

        $middleware = $kernel->getGlobalMiddleware();
        $capture = array_search(CaptureRequestContext::class, $middleware, true);

        if ($capture === false) {
            $this->markFail('Middleware', 'CaptureRequestContext is not in the global stack — log entries will lack trace_id/ip_address/url');

            return;
        }

        // is_a() also matches an app's own subclass, e.g. App\Http\Middleware\TrustProxies
        $trustProxies = collect($middleware)->search(
            fn ($class) => is_string($class) && is_a($class, TrustProxies::class, true)
        );

        if ($trustProxies === false) {
            $this->markWarn('Middleware', 'CaptureRequestContext registered, but TrustProxies is not in the global stack — behind a proxy, ip_address is the proxy\'s address');

            return;
        }

        if ($capture < $trustProxies) {
            $this->markFail('Middleware', 'CaptureRequestContext runs before TrustProxies — behind a proxy, ip_address is the proxy\'s address');

            return;
        }

        $this->markPass('Middleware', 'CaptureRequestContext registered after TrustProxies in the global stack');
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
        // entry for `logscope:prune`, but only if Schedule has already been
        // resolved by something else (see scanScheduleForPrune for why we
        // refuse to force-resolve it ourselves).
        $detection = $this->scanScheduleForPrune();

        if ($detection === true) {
            $this->markPass('Retention', "{$days}-day window, prune is scheduled by your app");

            return;
        }

        if ($detection === false) {
            $this->markWarn('Retention', "{$days}-day window, but no schedule entry for `logscope:prune` was found — set retention.auto_schedule=true or wire it in your console kernel");

            return;
        }

        // $detection === null: we couldn't check without side effects.
        $this->markWarn('Retention', "{$days}-day window — auto_schedule is off; confirm you've wired `logscope:prune` in your console kernel or set retention.auto_schedule=true");
    }

    protected function checkHeaderCapture(): void
    {
        if (! (bool) config('logscope.context.headers.enabled', true)) {
            $this->markWarn('Header capture', 'disabled — entries store no `headers`; set context.headers.enabled=true to capture them');

            return;
        }

        $allowlist = (array) config('logscope.context.headers.allowlist', []);

        if ($allowlist === []) {
            $this->markWarn('Header capture', 'enabled but the allowlist is empty — nothing will be captured; there is no "capture everything" mode by design');

            return;
        }

        $max = (int) config('logscope.context.headers.max_value_length', 500);

        $this->markPass('Header capture', count($allowlist).' allowlisted, values cut at '.$max.': '.implode(', ', $allowlist));
    }

    /**
     * Report the sensitive keys actually in force.
     *
     * Until #79 no command answered "which keys are being redacted right
     * now?", which is the question that would have exposed `sensitive_keys`
     * replacing the defaults the moment anyone asked it. The list is read
     * off the sanitizer rather than recomputed here, so this cannot claim a
     * key is redacted that the matcher never got.
     */
    protected function checkRedaction(): void
    {
        if (! (bool) config('logscope.context.redact_sensitive', true)) {
            $this->markWarn('Redaction', 'disabled — passwords, tokens and card numbers are stored exactly as logged; set context.redact_sensitive=true');

            return;
        }

        try {
            $sanitizer = $this->getLaravel()->make(ContextSanitizerInterface::class);
        } catch (Throwable $e) {
            $this->markFail('Redaction', 'could not resolve the sanitizer: '.$e->getMessage());

            return;
        }

        // Someone can bind their own implementation over the alias. We can
        // still say redaction is on, but not what it covers — guessing on
        // their behalf is the failure this check exists to prevent.
        if (! $sanitizer instanceof ContextSanitizer) {
            $this->markWarn('Redaction', 'enabled, but '.$sanitizer::class.' is bound in place of LogScope\'s sanitizer — the effective key list is whatever that class matches');

            return;
        }

        $keys = $sanitizer->effectiveSensitiveKeys();
        $except = $sanitizer->effectiveSensitiveKeysExcept();

        $detail = count($keys).' keys redacted: '.implode(', ', $keys);

        if ($except !== []) {
            $detail .= ' (+'.count($except).' kept by sensitive_keys_except)';
        }

        $this->markPass('Redaction', $detail);
    }

    /**
     * Tri-state detection of a user-defined `logscope:prune` schedule entry.
     *
     * Returns true/false when we can give a definitive answer, or null when
     * Schedule hasn't been resolved yet and we refuse to force-resolve it
     * from a diagnostic command. Resolving Schedule for the first time fires
     * every callAfterResolving(Schedule::class, ...) callback in the app —
     * usually harmless (singleton, the doctor process exits shortly after)
     * but a read-only command shouldn't have visible side effects on the
     * container, even if they're benign.
     */
    protected function scanScheduleForPrune(): ?bool
    {
        if (! $this->getLaravel()->resolved(Schedule::class)) {
            return null;
        }

        try {
            $schedule = $this->getLaravel()->make(Schedule::class);

            foreach ($schedule->events() as $event) {
                if (str_contains((string) ($event->command ?? ''), 'logscope:prune')) {
                    return true;
                }
            }

            return false;
        } catch (Throwable) {
            // Schedule was resolved but events() blew up — treat as unknown.
            return null;
        }
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
        if (! class_exists(RequestTerminated::class)) {
            $this->markPass('Octane', 'not installed — nothing to do');

            return;
        }

        $hasTerminated = Event::hasListeners(RequestTerminated::class);
        $hasReceived = Event::hasListeners(RequestReceived::class);

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
