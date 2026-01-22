<?php

use LogScope\Logging\ChannelContextProcessor;
use Monolog\Level;
use Monolog\LogRecord;

beforeEach(function () {
    ChannelContextProcessor::clearLastChannel();
});

it('captures the Laravel channel name passed to constructor', function () {
    $processor = new ChannelContextProcessor('single');

    $record = new LogRecord(
        datetime: new DateTimeImmutable,
        channel: 'local', // Monolog channel name (ignored)
        level: Level::Info,
        message: 'Test message',
        context: [],
    );

    $processor($record);

    // Should store the Laravel channel name, not the Monolog channel
    expect(ChannelContextProcessor::getLastChannel())->toBe('single');
});

it('returns the record unchanged', function () {
    $processor = new ChannelContextProcessor('daily');

    $record = new LogRecord(
        datetime: new DateTimeImmutable,
        channel: 'production',
        level: Level::Error,
        message: 'Error occurred',
        context: ['user_id' => 123],
    );

    $processed = $processor($record);

    // Record should be returned as-is
    expect($processed)->toBe($record);
    expect($processed->context)->toBe(['user_id' => 123]);
});

it('overwrites previous channel on subsequent calls', function () {
    $processor1 = new ChannelContextProcessor('single');
    $processor2 = new ChannelContextProcessor('slack');

    $record = new LogRecord(
        datetime: new DateTimeImmutable,
        channel: 'local',
        level: Level::Info,
        message: 'Test',
        context: [],
    );

    $processor1($record);
    expect(ChannelContextProcessor::getLastChannel())->toBe('single');

    $processor2($record);
    expect(ChannelContextProcessor::getLastChannel())->toBe('slack');
});

it('returns null when no channel has been captured', function () {
    expect(ChannelContextProcessor::getLastChannel())->toBeNull();
});

it('can clear the last channel', function () {
    $processor = new ChannelContextProcessor('stack');

    $record = new LogRecord(
        datetime: new DateTimeImmutable,
        channel: 'local',
        level: Level::Info,
        message: 'Test',
        context: [],
    );

    $processor($record);
    expect(ChannelContextProcessor::getLastChannel())->toBe('stack');

    ChannelContextProcessor::clearLastChannel();
    expect(ChannelContextProcessor::getLastChannel())->toBeNull();
});
