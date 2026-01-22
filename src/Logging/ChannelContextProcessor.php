<?php

declare(strict_types=1);

namespace LogScope\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Monolog processor that captures the Laravel channel name for LogScope.
 *
 * Since Laravel's MessageLogged event doesn't include the channel name,
 * we store it in a static variable that the event listener can access.
 *
 * The channel name is passed in the constructor (the Laravel config key,
 * e.g., "single", "stack", "daily") rather than read from $record->channel
 * (which is just the Monolog logger name, typically the environment).
 */
class ChannelContextProcessor implements ProcessorInterface
{
    /**
     * The most recently processed channel name.
     */
    protected static ?string $lastChannel = null;

    /**
     * The Laravel channel name this processor is registered for.
     */
    protected string $channel;

    public function __construct(string $channel)
    {
        $this->channel = $channel;
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        // Store the Laravel channel name for the MessageLogged listener
        static::$lastChannel = $this->channel;

        return $record;
    }

    /**
     * Get the last captured channel name.
     */
    public static function getLastChannel(): ?string
    {
        return static::$lastChannel;
    }

    /**
     * Clear the last channel (useful for testing).
     */
    public static function clearLastChannel(): void
    {
        static::$lastChannel = null;
    }
}
