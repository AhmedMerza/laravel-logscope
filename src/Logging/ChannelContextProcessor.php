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
     * Whether `$lastChannel` was set by a processor invocation that has
     * not yet been consumed by the listener. Used to distinguish "the
     * processor for THIS log just ran" from "stale state from a previous
     * log whose listener returned early or didn't run". Without this
     * flag, a log fired through Log::build() (which has no processor)
     * would inherit the previous log's channel from the static state.
     */
    protected static bool $isFresh = false;

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
        static::$isFresh = true;

        return $record;
    }

    /**
     * Consume the channel set by the most recent processor invocation.
     *
     * Returns the channel only if a processor invocation has happened
     * since the last consume. Always clears state so the next log
     * either gets its own fresh value (processor fires) or `null`
     * (no processor, e.g. Log::build()).
     */
    public static function consumeLastChannel(): ?string
    {
        $channel = static::$isFresh ? static::$lastChannel : null;
        static::$lastChannel = null;
        static::$isFresh = false;

        return $channel;
    }

    /**
     * Get the last captured channel name without consuming it.
     *
     * @deprecated since 1.5.5 — prefer consumeLastChannel(), which clears
     * state in one operation and prevents stale-channel leaks. Kept for
     * test helpers and backwards compatibility.
     */
    public static function getLastChannel(): ?string
    {
        return static::$isFresh ? static::$lastChannel : null;
    }

    /**
     * Clear the last channel (useful for testing).
     */
    public static function clearLastChannel(): void
    {
        static::$lastChannel = null;
        static::$isFresh = false;
    }
}
