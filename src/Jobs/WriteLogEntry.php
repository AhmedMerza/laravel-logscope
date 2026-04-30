<?php

declare(strict_types=1);

namespace LogScope\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use LogScope\Models\LogEntry;
use LogScope\Services\WriteGuard;

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
        WriteGuard::during(fn () => LogEntry::createEntry($this->data));
    }
}
