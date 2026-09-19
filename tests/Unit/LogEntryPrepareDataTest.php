<?php

declare(strict_types=1);

use LogScope\Models\LogEntry;

it('generates lowercase ULIDs to match HasUlids behaviour', function () {
    $data = LogEntry::prepareData([
        'level' => 'info',
        'message' => 'Test message',
    ]);

    expect($data['id'])->toHaveLength(26);
    expect($data['id'])->toBe(strtolower($data['id']));
});

describe('encodeContext (#67)', function () {
    it('substitutes a malformed byte instead of returning false', function () {
        $encoded = LogEntry::encodeContext(['agent' => 'Mozilla/5.0 '.chr(0xB1)]);

        expect($encoded)->toBeString()->not->toBe('')
            ->and(json_decode($encoded, true)['agent'])->toStartWith('Mozilla/5.0 ');
    });

    it('substitutes inside object keys, which is what covers header names', function () {
        // sanitizeHeaders() has no allowlist, so the keys it puts in context
        // are attacker-controlled — unlike captureHeaders()'s.
        $encoded = LogEntry::encodeContext(['x-trace-'.chr(0xB1) => 'abc']);

        expect(json_decode($encoded, true))->toBeArray()
            ->and(json_last_error())->toBe(JSON_ERROR_NONE);
    });

    it('leaves valid input byte-identical to the array cast it replaced', function () {
        // Pins the deliberate absence of escaping flags: `context:` search is
        // a substring LIKE over these bytes, so adding JSON_UNESCAPED_SLASHES
        // here would silently change what previous versions stored.
        $context = ['url' => 'https://shop.test/cart', 'note' => 'café', 'n' => 3];

        expect(LogEntry::encodeContext($context))->toBe(json_encode($context));
    });

    it('encodes the context column through the shared encoder in batch mode', function () {
        $data = LogEntry::prepareData([
            'level' => 'warning',
            'message' => 'rejected request',
            'context' => ['agent' => 'Mozilla/5.0 '.chr(0xB1)],
        ]);

        expect($data['context'])->toBeString()->not->toBe('')
            ->and(json_decode($data['context'], true))->toBeArray()
            ->and($data['context_preview'])->toBeString();
    });
});
