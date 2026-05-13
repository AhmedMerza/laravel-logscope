<?php

declare(strict_types=1);

namespace LogScope\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use LogScope\Models\LogEntry;
use LogScope\Services\FallbackWriter;
use LogScope\Services\WriteFailureLogger;
use LogScope\Services\WriteGuard;
use Throwable;

class WriteLogEntry implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public array $data
    ) {
        $this->onQueue(config('logscope.queue.name', 'default'));

        if ($connection = config('logscope.queue.connection')) {
            $this->onConnection($connection);
        }
    }

    /**
     * Execute the job.
     *
     * Wrapped in WriteGuard so a re-entrant log fired during the insert
     * (LogEntry observer, query listener that logs, etc.) is skipped by
     * the listener instead of recursing back into another job dispatch.
     * The dispatch-time guard in LogWriter::write only covers the parent
     * request — by the time the worker runs this job, the guard has been
     * cleared (or we're in a different process entirely).
     */
    public function handle(): void
    {
        try {
            WriteGuard::during(fn () => LogEntry::createEntry($this->data));
        } catch (Throwable $e) {
            // Transient DB conditions (connection failures, deadlocks) are
            // exactly what queue retries exist for — let Laravel re-run the
            // job. No fallback row in this branch: if the retry succeeds
            // we'd otherwise have a duplicate (one fallback + one real).
            if ($this->isTransientFailure($e)) {
                throw $e;
            }

            // Persistent failure (autoload error, schema mismatch, malformed
            // data). Retrying won't help and would loop the worker, so we
            // swallow after recording observability:
            //   - WriteFailureLogger → error_log + cache banner
            //   - FallbackWriter      → minimal row in log_entries
            WriteFailureLogger::report($e, 'queue-worker');

            try {
                app(FallbackWriter::class)->record($this->data, $e, 'queue-worker');
            } catch (Throwable) {
                // last-resort: error_log already covered observability
            }
        }
    }

    /**
     * Classify a write failure as transient (retry-eligible) or
     * persistent. We treat ONLY SQLSTATE classes 08 (Connection Exception)
     * and 40 (Transaction Rollback — 40001 serialization failure / 40P01
     * postgres deadlock) as transient. Everything else is assumed to be
     * a code/data problem where a retry would just fail the same way.
     *
     * Non-QueryException throws (TypeError, autoload failures, etc.)
     * are always persistent — those are the cases that motivated the
     * fallback row in the first place.
     */
    private function isTransientFailure(Throwable $e): bool
    {
        if (! $e instanceof QueryException) {
            return false;
        }

        $sqlState = (string) $e->getCode();

        return str_starts_with($sqlState, '08') || str_starts_with($sqlState, '40');
    }
}
