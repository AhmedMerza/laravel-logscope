<?php

declare(strict_types=1);

namespace LogScope\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use LogScope\Models\LogEntry;

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
     */
    public function handle(): void
    {
        LogEntry::createEntry($this->data);
    }
}
