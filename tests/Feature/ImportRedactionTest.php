<?php

declare(strict_types=1);

// Tests for issue #77 — `logscope:import` as a third write path.
//
// The listener and the Monolog handler both redact; import did not. It
// ingests context an application wrote to disk before LogScope existed,
// which is exactly the unredacted payload #76 is about, and importing is
// what moves it from a rotating file into a queryable, long-lived table.
//
// No test ran the command at all before this, so reverting the sanitize
// call in ImportCommand::prepareEntry() shipped green.

use Illuminate\Foundation\Testing\RefreshDatabase;
use LogScope\LogScopeServiceProvider;
use LogScope\Models\LogEntry;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->artisan('migrate', ['--path' => __DIR__.'/../../database/migrations']);

    LogScopeServiceProvider::resetBufferState();
    LogEntry::query()->delete();

    $this->logFile = sys_get_temp_dir().'/logscope-import-'.uniqid().'.log';
});

afterEach(function () {
    @unlink($this->logFile);
});

it('redacts sensitive context when importing a log file', function () {
    $stamp = now()->format('Y-m-d H:i:s');
    file_put_contents($this->logFile, <<<LOG
    [{$stamp}] testing.ERROR: payment failed {"password":"hunter2","card_number":"4111","amount":500}
    LOG);

    $this->artisan('logscope:import', ['path' => $this->logFile, '--days' => 0])
        ->assertExitCode(0);

    $entry = LogEntry::where('message', 'like', 'payment failed%')->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->context['password'])->toBe('[REDACTED]')
        ->and($entry->context['card_number'])->toBe('[REDACTED]')
        ->and($entry->context['amount'])->toBe(500)
        ->and(json_encode($entry->context))->not->toContain('hunter2');
});
