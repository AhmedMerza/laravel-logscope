<?php

declare(strict_types=1);

use LogScope\Services\Fingerprint;

describe('message normalisation', function () {
    it('collapses varying integers so the same error meets itself', function () {
        expect(Fingerprint::normalize('User 4192 not found'))
            ->toBe(Fingerprint::normalize('User 87 not found'));
    });

    it('replaces each kind of varying value', function (string $message, string $expected) {
        expect(Fingerprint::normalize($message))->toBe($expected);
    })->with([
        'uuid' => ['Token 550e8400-e29b-41d4-a716-446655440000 invalid', 'Token <uuid> invalid'],
        'datetime' => ['Failed at 2026-09-23 14:05:00', 'Failed at <timestamp>'],
        'iso datetime' => ['Failed at 2026-09-23T14:05:00.123+02:00', 'Failed at <timestamp>'],
        'date' => ['Failed at 2026-09-23', 'Failed at <timestamp>'],
        'clock time' => ['Job ran at 14:05:00 today', 'Job ran at <timestamp> today'],
        'ipv4' => ['Connection from 192.168.1.1 refused', 'Connection from <ip> refused'],
        'ipv6' => ['Connection from 2001:0db8:85a3:0000:0000:8a2e:0370:7334 refused', 'Connection from <ip> refused'],
        'hex digest' => ['Hash deadbeef1234 mismatch', 'Hash <hex> mismatch'],
        'hex literal' => ['Pointer 0xdeadbeef freed', 'Pointer <hex> freed'],
        'double quoted' => ['Order "ORD-4192" failed', 'Order <str> failed'],
        'single quoted' => ["Order 'ORD-4192' failed", 'Order <str> failed'],
        'decimal' => ['Price 19.99 rejected', 'Price <num> rejected'],
    ]);

    // PCRE counts _ as a word character, so \b sees no boundary before the
    // digits in order_4192 and the plain integer rule cannot reach them.
    it('collapses digits welded to an underscore', function () {
        expect(Fingerprint::normalize('order_4192 failed'))->toBe('order_<num> failed');
        expect(Fingerprint::normalize('order_42 failed'))->toBe('order_<num> failed');
    });

    it('collapses a run of four or more digits inside a word', function () {
        expect(Fingerprint::normalize('user4192 not found'))->toBe('user<num> not found');
        expect(Fingerprint::normalize('abc1234 missing'))->toBe('abc<num> missing');
    });

    // Four is the threshold precisely so these survive: collapsing them would
    // merge sha256 with sha512, which are not the same error.
    it('leaves technical tokens of three digits or fewer intact', function (string $message) {
        expect(Fingerprint::normalize($message))->toBe($message);
    })->with([
        'Loaded sha256 digest',
        'Loaded sha512 digest',
        'Using utf8 encoding',
        'oauth2 token expired',
        'h264 stream failed',
        'base64 decode failed',
        'x86 build',
    ]);

    it('normalises decimals to one shape whatever their size', function () {
        expect(Fingerprint::normalize('Price 1234.56 rejected'))
            ->toBe(Fingerprint::normalize('Price 12.30 rejected'));
    });

    it('leaves words that happen to be hex alone', function (string $message) {
        expect(Fingerprint::normalize($message))->toBe($message);
    })->with([
        'facade pattern not found',
        'The decade ended',
        'Error: something: happened',
        'Undefined array key in handler',
    ]);

    it('keeps genuinely different messages apart', function () {
        expect(Fingerprint::normalize('Disk usage critical'))
            ->not->toBe(Fingerprint::normalize('Disk space critical'));
    });
});

describe('fingerprint identity', function () {
    it('groups messages that differ only by their ids', function () {
        $a = Fingerprint::for(['message' => 'User 4192 not found', 'level' => 'error', 'channel' => 'stack']);
        $b = Fingerprint::for(['message' => 'User 87 not found', 'level' => 'error', 'channel' => 'stack']);

        expect($a)->toBe($b);
    });

    it('separates the same message logged at a different level', function () {
        $a = Fingerprint::for(['message' => 'Cache miss', 'level' => 'error', 'channel' => 'stack']);
        $b = Fingerprint::for(['message' => 'Cache miss', 'level' => 'warning', 'channel' => 'stack']);

        expect($a)->not->toBe($b);
    });

    it('separates the same message on a different channel', function () {
        $a = Fingerprint::for(['message' => 'Cache miss', 'level' => 'error', 'channel' => 'stack']);
        $b = Fingerprint::for(['message' => 'Cache miss', 'level' => 'error', 'channel' => 'billing']);

        expect($a)->not->toBe($b);
    });
});

describe('exception identity', function () {
    it('prefers the exception over the message, so varying text still groups', function () {
        $exception = ['_type' => 'exception', 'class' => 'RuntimeException', 'file' => '/app/Gateway.php', 'line' => 212];

        $a = Fingerprint::for(['message' => 'Declined for order 4192', 'level' => 'error', 'context' => ['exception' => $exception]]);
        $b = Fingerprint::for(['message' => 'Totally different wording', 'level' => 'error', 'context' => ['exception' => $exception]]);

        expect($a)->toBe($b);
    });

    it('separates the same exception class thrown from a different line', function () {
        $base = ['_type' => 'exception', 'class' => 'RuntimeException', 'file' => '/app/Gateway.php'];

        $a = Fingerprint::for(['message' => 'x', 'context' => ['exception' => $base + ['line' => 212]]]);
        $b = Fingerprint::for(['message' => 'x', 'context' => ['exception' => $base + ['line' => 500]]]);

        expect($a)->not->toBe($b);
    });

    // The two capture paths sanitise context before writing, but a caller
    // going straight to LogWriter::write() can still pass the Throwable
    // itself. If those disagreed, one error would split into two groups
    // depending on how it was logged.
    it('fingerprints a raw Throwable the same as its sanitised array', function () {
        $exception = new RuntimeException('Declined');

        $raw = Fingerprint::for([
            'message' => 'Declined',
            'level' => 'error',
            'context' => ['exception' => $exception],
        ]);

        $sanitised = Fingerprint::for([
            'message' => 'Declined',
            'level' => 'error',
            'context' => ['exception' => [
                '_type' => 'exception',
                'class' => get_class($exception),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]],
        ]);

        expect($raw)->toBe($sanitised);
    });

    it('falls back to the message when context holds no usable exception', function () {
        $withJunk = Fingerprint::for([
            'message' => 'Something broke',
            'level' => 'error',
            'context' => ['exception' => ['_type' => 'exception']],
        ]);

        $withNone = Fingerprint::for([
            'message' => 'Something broke',
            'level' => 'error',
        ]);

        expect($withJunk)->toBe($withNone);
    });
});
