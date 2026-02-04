<?php

declare(strict_types=1);

namespace LogScope\Contracts;

/**
 * Contract for writing log entries.
 */
interface LogWriterInterface
{
    /**
     * Write a log entry based on the configured write mode.
     */
    public function write(array $data): void;
}
