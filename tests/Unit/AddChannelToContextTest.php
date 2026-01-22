<?php

use Illuminate\Log\Logger;
use LogScope\Logging\AddChannelToContext;
use LogScope\Logging\ChannelContextProcessor;
use Monolog\Handler\NullHandler;
use Monolog\Logger as MonologLogger;

it('adds ChannelContextProcessor with channel name to monolog logger', function () {
    $monolog = new MonologLogger('test-channel');
    $logger = new Logger($monolog);

    $tap = new AddChannelToContext;
    $tap($logger, 'single');

    $processors = $monolog->getProcessors();

    expect($processors)->toHaveCount(1);
    expect($processors[0])->toBeInstanceOf(ChannelContextProcessor::class);
});

it('passes the channel name to the processor', function () {
    ChannelContextProcessor::clearLastChannel();

    $monolog = new MonologLogger('local', [new NullHandler]);
    $logger = new Logger($monolog);

    $tap = new AddChannelToContext;
    $tap($logger, 'daily');

    // Trigger the processor by logging
    $monolog->info('test message');

    expect(ChannelContextProcessor::getLastChannel())->toBe('daily');
});

it('does not fail if logger is not monolog', function () {
    // Create a mock logger that is not Monolog
    $mockPsrLogger = new class implements \Psr\Log\LoggerInterface
    {
        use \Psr\Log\LoggerTrait;

        public function log($level, \Stringable|string $message, array $context = []): void
        {
            // No-op
        }
    };

    $logger = new Logger($mockPsrLogger);

    $tap = new AddChannelToContext;

    // Should not throw an exception
    $tap($logger, 'test');

    expect(true)->toBeTrue();
});
