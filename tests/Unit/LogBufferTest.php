<?php

declare(strict_types=1);

use LogScope\Services\LogBuffer;

beforeEach(function () {
    LogBuffer::reset();
});

afterEach(function () {
    LogBuffer::reset();
});

describe('buffer management', function () {
    it('starts with empty buffer', function () {
        expect(LogBuffer::getBuffer())->toBe([]);
    });

    it('adds entries to buffer', function () {
        $buffer = new LogBuffer(app());

        $buffer->add(['message' => 'first']);
        $buffer->add(['message' => 'second']);

        expect(LogBuffer::getBuffer())->toHaveCount(2)
            ->and(LogBuffer::getBuffer()[0]['message'])->toBe('first')
            ->and(LogBuffer::getBuffer()[1]['message'])->toBe('second');
    });

    it('preserves entry data in buffer', function () {
        $buffer = new LogBuffer(app());

        $data = [
            'level' => 'error',
            'message' => 'Test message',
            'context' => ['key' => 'value'],
            'channel' => 'testing',
        ];

        $buffer->add($data);

        expect(LogBuffer::getBuffer()[0])->toBe($data);
    });
});

describe('reset', function () {
    it('clears the buffer', function () {
        $buffer = new LogBuffer(app());

        $buffer->add(['message' => 'test']);
        expect(LogBuffer::getBuffer())->toHaveCount(1);

        LogBuffer::reset();

        expect(LogBuffer::getBuffer())->toBe([]);
    });
});

describe('flushStatic', function () {
    it('does nothing when buffer is empty', function () {
        // Should not throw
        LogBuffer::flushStatic();

        expect(LogBuffer::getBuffer())->toBe([]);
    });

    it('clears buffer after flush', function () {
        $buffer = new LogBuffer(app());
        $buffer->add(['message' => 'test']);

        expect(LogBuffer::getBuffer())->toHaveCount(1);

        // Note: This will try to write to DB which may fail in unit test context
        // but buffer should still be cleared
        try {
            LogBuffer::flushStatic();
        } catch (Throwable) {
            // Expected if DB not available
        }

        expect(LogBuffer::getBuffer())->toBe([]);
    });

    it('can be called multiple times safely', function () {
        $buffer = new LogBuffer(app());
        $buffer->add(['message' => 'test']);

        try {
            LogBuffer::flushStatic();
        } catch (Throwable) {
            // Expected
        }

        // Second call should be safe and do nothing
        LogBuffer::flushStatic();

        expect(LogBuffer::getBuffer())->toBe([]);
    });

    it('does not crash when container is unavailable during shutdown', function () {
        $buffer = new LogBuffer(app());
        $buffer->add(['message' => 'test']);

        // Destroy the container to simulate PHP shutdown state
        // where Laravel facades are no longer available
        app()->flush();

        // flushStatic should handle the error gracefully via error_log()
        // instead of crashing when config() is unavailable
        LogBuffer::flushStatic();

        expect(LogBuffer::getBuffer())->toBe([]);

        // Restore the application so Pest's teardown can run
        $this->refreshApplication();
    });
});
