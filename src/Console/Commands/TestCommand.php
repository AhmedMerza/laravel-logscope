<?php

declare(strict_types=1);

namespace LogScope\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogScope\Models\LogEntry;
use LogScope\Services\LogBuffer;

class TestCommand extends Command
{
    protected $signature = 'logscope:test
                            {--keep : Do not delete the test log entry after a successful test}';

    protected $description = 'Emit a tagged sample log and verify it lands in the log_entries table';

    public function handle(): int
    {
        $captureMode = (string) config('logscope.capture', 'all');
        $configuredWriteMode = (string) config('logscope.write_mode', 'batch');

        // Force sync writes for the duration of this test so we can verify
        // synchronously without waiting on a queue worker or end-of-request
        // batch flush. The user's real config is restored in the finally
        // block below — this protects us from leaking sync mode into the
        // surrounding process if any step (verify query, etc.) throws.
        config(['logscope.write_mode' => 'sync']);

        $marker = (string) Str::ulid();
        $message = "LogScope test ping [{$marker}]";

        $this->components->info('Emitting test log...');
        $this->line("  capture mode: {$captureMode}");
        $this->line("  configured write mode: {$configuredWriteMode} (forced to sync for this test)");
        $this->line("  marker: {$marker}");
        $this->newLine();

        try {
            try {
                $this->emit($captureMode, $message);
            } catch (\Throwable $e) {
                $this->components->error('Failed to emit log: ['.get_class($e).'] '.$e->getMessage());

                return self::FAILURE;
            }

            // Drain any buffered writes — defensive, since we forced sync above
            // it should be a no-op, but covers the edge case where a user-installed
            // listener re-buffered the entry.
            try {
                LogBuffer::flushStatic();
            } catch (\Throwable) {
                // Surfaced via WriteFailureLogger already; the verify step below
                // will report the missing entry as a failure.
            }

            try {
                $entry = LogEntry::query()
                    ->where('message', 'like', '%'.$marker.'%')
                    ->orderByDesc('occurred_at')
                    ->first();
            } catch (\Throwable $e) {
                $this->components->error('Failed to query for the test log: ['.get_class($e).'] '.$e->getMessage());

                return self::FAILURE;
            }

            if ($entry === null) {
                $this->components->error('Test log was NOT captured. Check `logscope:doctor` and your error_log for write failures.');

                return self::FAILURE;
            }

            $this->components->info('Test log captured successfully.');
            $this->table(['Field', 'Value'], [
                ['id', (string) $entry->id],
                ['level', (string) $entry->level],
                ['channel', (string) ($entry->channel ?? '(none)')],
                ['source', (string) ($entry->source ?? '(none)')],
                ['occurred_at', (string) $entry->occurred_at],
            ]);

            if (! $this->option('keep')) {
                $entry->delete();
                $this->components->info('Cleaned up test entry. Use --keep to retain it for inspection.');
            }

            return self::SUCCESS;
        } finally {
            $this->restoreWriteMode($configuredWriteMode);
        }
    }

    /**
     * Emit through the path that matches the configured capture mode.
     *
     * - 'all'     → standard Log facade; the global MessageLogged listener picks it up.
     * - 'channel' → must route through the explicit `logscope` channel; otherwise the
     *               handler-based capture path is never exercised.
     */
    protected function emit(string $captureMode, string $message): void
    {
        if ($captureMode === 'channel') {
            $channels = (array) config('logging.channels', []);

            if (! isset($channels['logscope'])) {
                throw new \RuntimeException(
                    'capture mode is `channel` but no `logscope` channel is defined in config/logging.php — cannot route the test log'
                );
            }

            Log::channel('logscope')->info($message, ['_logscope_test' => true]);

            return;
        }

        Log::info($message, ['_logscope_test' => true]);
    }

    protected function restoreWriteMode(string $original): void
    {
        config(['logscope.write_mode' => $original]);
    }
}
