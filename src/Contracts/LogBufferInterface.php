<?php

declare(strict_types=1);

namespace LogScope\Contracts;

/**
 * Contract for buffering log entries.
 */
interface LogBufferInterface
{
    /**
     * Add a log entry to the buffer.
     */
    public function add(array $data): void;

    /**
     * Flush the buffer to the database.
     */
    public function flush(): void;
}
