<?php

declare(strict_types=1);

namespace LogScope\Services;

use LogScope\Jobs\WriteLogEntry;
use LogScope\Models\LogEntry;

/**
 * Routes log writes to the appropriate handler based on write mode.
 */
class LogWriter
{
    public function __construct(
        protected LogBuffer $buffer
    ) {}

    /**
     * Write a log entry based on the configured write mode.
     */
    public function write(array $data): void
    {
        $mode = config('logscope.write_mode', 'batch');

        match ($mode) {
            'sync' => $this->writeSync($data),
            'queue' => $this->writeQueue($data),
            'batch' => $this->writeBatch($data),
            default => $this->writeSync($data),
        };
    }

    /**
     * Write immediately to database.
     */
    protected function writeSync(array $data): void
    {
        LogEntry::createEntry($data);
    }

    /**
     * Dispatch to queue for async writing.
     */
    protected function writeQueue(array $data): void
    {
        WriteLogEntry::dispatch($data);
    }

    /**
     * Buffer for batch writing at end of request.
     */
    protected function writeBatch(array $data): void
    {
        $this->buffer->add($data);
    }
}
