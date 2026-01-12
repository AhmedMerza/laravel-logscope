<?php

declare(strict_types=1);

namespace LogScope\Logging;

use Monolog\Logger;

class LogScopeChannel
{
    /**
     * Create a custom Monolog instance for LogScope.
     */
    public function __invoke(array $config): Logger
    {
        $handler = new LogScopeHandler(
            channel: $config['channel'] ?? 'logscope',
            level: $config['level'] ?? 'debug',
            bubble: $config['bubble'] ?? true
        );

        return new Logger('logscope', [$handler]);
    }
}
