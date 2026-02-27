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
