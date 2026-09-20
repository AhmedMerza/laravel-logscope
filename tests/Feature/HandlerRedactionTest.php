<?php

declare(strict_types=1);

// Tests for issue #77 — the Monolog handler's own write path.
//
// LogScopeHandler used to carry a full copy of the context sanitizer with
// no redaction in it at all, so everything the listener path redacted was
// stored in clear here: a logged password, and a Request's Authorization
// header, Cookie and raw body. The path is reached by `capture => channel`
// and by any logging stack containing the logscope channel, since
// LogCapture defers to the handler via didHandleCurrentLog().
//
// These tests pin the delegation. If the handler ever grows its own
// sanitizing again, the first two fail.

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use LogScope\Logging\LogScopeHandler;
use LogScope\LogScopeServiceProvider;
use LogScope\Models\LogEntry;
use Monolog\Logger;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->artisan('migrate', ['--path' => __DIR__.'/../../database/migrations']);

    LogScopeServiceProvider::resetBufferState();
    LogEntry::query()->delete();

    // The construction README.md documents for direct Monolog use.
    $this->logger = new Logger('payments');
    $this->logger->pushHandler(new LogScopeHandler('payments'));
});

afterEach(function () {
    // Writing through the handler sets a static "I handled this one" flag
    // that the listener reads-and-clears to avoid double-capturing. Nothing
    // reads it here, so left set it makes the NEXT test's first listener log
    // skip itself — which showed up as an unrelated header test seeing null.
    LogScopeHandler::didHandleCurrentLog();
});

it('redacts sensitive keys logged as a plain array through the handler', function () {
    $this->logger->error('payment failed', [
        'password' => 'hunter2',
        'card_number' => '4111',
        'amount' => 500,
    ]);

    $context = LogEntry::where('message', 'payment failed')->latest('id')->first()->context;

    expect($context['password'])->toBe('[REDACTED]')
        ->and($context['card_number'])->toBe('[REDACTED]')
        ->and($context['amount'])->toBe(500);
});

it('redacts a compound key split across array levels through the handler (#80)', function () {
    // The Unit suite covers this against ContextSanitizer directly. Without
    // a Feature case, a regression in the ancestor-path threading would ship
    // green through every test that exercises a real entry point.
    $this->logger->error('nested payment failed', [
        'card' => ['number' => '4111111111111111', 'exp_month' => 12],
    ]);

    $context = LogEntry::where('message', 'nested payment failed')->latest('id')->first()->context;

    expect($context['card']['number'])->toBe('[REDACTED]')
        ->and($context['card']['exp_month'])->toBe(12);
});

it('expands and redacts a Request logged through the handler', function () {
    // Before delegation this stored Symfony's raw __toString() dump: the
    // Authorization header, the Cookie and the form body, all in clear.
    $request = Request::create('/checkout', 'POST', [
        'password' => 'hunter2',
        'amount' => 500,
    ], server: [
        'HTTP_AUTHORIZATION' => 'Bearer sk-live-SECRET',
        'HTTP_COOKIE' => 'session=abc123',
    ]);

    $this->logger->error('request logged', ['request' => $request]);

    $context = LogEntry::where('message', 'request logged')->latest('id')->first()->context;
    $encoded = json_encode($context);

    expect($context['request']['_type'])->toBe('request')
        ->and($context['request']['input']['password'])->toBe('[REDACTED]')
        ->and($context['request']['input']['amount'])->toBe(500)
        ->and($context['request']['headers']['authorization'])->toBe(['[REDACTED]'])
        ->and($context['request']['headers']['cookie'])->toBe(['[REDACTED]'])
        ->and($encoded)->not->toContain('sk-live-SECRET')
        ->and($encoded)->not->toContain('abc123')
        ->and($encoded)->not->toContain('hunter2');
});

it('still stores a resource without failing the write', function () {
    // The handler's own sanitizer mapped resources to [Resource]; the shared
    // one had no branch for them, and json_encode() cannot represent one.
    $handle = fopen('php://memory', 'r');

    $this->logger->error('resource logged', ['handle' => $handle]);
    fclose($handle);

    $entry = LogEntry::where('message', 'resource logged')->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->context['handle'])->toBe('[Resource]');
});
