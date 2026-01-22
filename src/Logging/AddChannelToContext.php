<?php

declare(strict_types=1);

namespace LogScope\Logging;

use Illuminate\Log\Logger;
use Monolog\Logger as MonologLogger;

/**
 * Tap class that adds a processor to capture the Laravel channel name.
 *
 * The channel name is passed as a parameter when the tap is registered,
 * allowing the processor to store the actual Laravel channel config key
 * (e.g., "single", "stack", "daily") rather than the Monolog logger name.
 */
class AddChannelToContext
{
    public function __invoke(Logger $logger, string $channel): void
    {
        $monolog = $logger->getLogger();

        if ($monolog instanceof MonologLogger) {
            $monolog->pushProcessor(new ChannelContextProcessor($channel));
        }
    }
}
