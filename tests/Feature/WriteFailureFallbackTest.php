<?php

declare(strict_types=1);

// Verifies that when LogScope's normal write fails (e.g. autoload corruption
// blowing up context sanitization for a single entry), LogScope writes a
// minimal *fallback* row to log_entries so the failure is visible in the UI
// — not just in php-fpm's error_log.

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use LogScope\Jobs\WriteLogEntry;
use LogScope\Logging\ChannelContextProcessor;
use LogScope\LogScopeServiceProvider;
use LogScope\Models\LogEntry;
use LogScope\Services\FallbackWriter;
use LogScope\Services\WriteFailureLogger;
use LogScope\Services\WriteGuard;

uses(RefreshDatabase::class);

beforeEach(function () {
    ChannelContextProcessor::clearLastChannel();
    LogScopeServiceProvider::resetBufferState();
    WriteGuard::reset();
    WriteFailureLogger::reset();
    FallbackWriter::reset();
    LogEntry::query()->delete();

    config(['logscope.write_mode' => 'sync']);
    config(['logscope.write_failure.persist_fallback' => true]);

    // Quiet php-fpm-style error_log emissions for the duration of the test.
    $this->errorLogFile = tempnam(sys_get_temp_dir(), 'logscope-fallback-test-');
    $this->originalErrorLog = ini_get('error_log');
    ini_set('error_log', $this->errorLogFile);
});

afterEach(function () {
    ini_set('error_log', $this->originalErrorLog);
    if (file_exists($this->errorLogFile)) {
        @unlink($this->errorLogFile);
    }

    LogEntry::flushEventListeners();

    if (! Schema::hasTable('log_entries')) {
        $this->artisan('migrate', ['--path' => __DIR__.'/../../database/migrations']);
    }
});

/**
 * Helper: poison every normal LogEntry insert. The fallback row carries a
 * `_logscope_write_failure` marker — when the listener sees the marker, it
 * skips throwing, so the fallback insert lands. Mirrors the production
 * scenario where the *original* context triggers an autoload-induced
 * fatal but a stripped-down context does not.
 */
function poisonNormalInserts(): void
{
    LogEntry::creating(function (LogEntry $entry) {
        $context = is_array($entry->context) ? $entry->context : [];
        if (! isset($context['_logscope_write_failure'])) {
            throw new \Error('Class "OwenIt\\Auditing\\Models\\Audit" not found');
        }
    });
}

it('writes a minimal fallback row to log_entries when the sync write fails', function () {
    poisonNormalInserts();

    Log::error('original message');

    $entries = LogEntry::query()->get();
    expect($entries)->toHaveCount(1);

    $entry = $entries->first();
    expect($entry->level)->toBe('error')
        ->and($entry->message)->toBe('original message')
        ->and($entry->context)->toHaveKey('_logscope_write_failure');

    $marker = $entry->context['_logscope_write_failure'];
    expect($marker['exception_class'])->toBe('Error')
        ->and($marker['exception_message'])->toContain('OwenIt\\Auditing\\Models\\Audit')
        ->and($marker['where'])->toBe('listener');
});

it('preserves trace_id from request context on the fallback row', function () {
    poisonNormalInserts();

    Context::add('logscope', [
        'trace_id' => 'trace-abc-123',
        'ip_address' => '1.2.3.4',
        'url' => 'https://example.test/foo',
        'http_method' => 'POST',
    ]);

    Log::error('original message');

    $entry = LogEntry::query()->first();
    expect($entry)->not->toBeNull()
        ->and($entry->trace_id)->toBe('trace-abc-123');
});

it('does not write a fallback row when persist_fallback is disabled', function () {
    config(['logscope.write_failure.persist_fallback' => false]);
    poisonNormalInserts();

    Log::error('original message');

    expect(LogEntry::query()->count())->toBe(0);
});

it('emits a single fallback row within one heartbeat interval for repeated failures', function () {
    poisonNormalInserts();

    // 25 occurrences is well below REEMIT_EVERY = 100, so we should see
    // exactly one row — the first-occurrence emit. Heartbeat semantics are
    // verified by the dedicated 100-occurrence test below.
    for ($i = 0; $i < 25; $i++) {
        Log::error("repeated message {$i}");
    }

    expect(LogEntry::query()->count())->toBe(1);
});

it('writes a fallback row when the queue worker insert fails', function () {
    config(['logscope.write_mode' => 'queue']);
    Queue::fake();

    Log::error('queued message');

    // Capture the dispatched job, run it manually with a poisoned LogEntry.
    Queue::assertPushed(WriteLogEntry::class, function (WriteLogEntry $job) {
        poisonNormalInserts();
        $job->handle();

        return true;
    });

    $entries = LogEntry::query()->get();
    expect($entries)->toHaveCount(1);

    $entry = $entries->first();
    expect($entry->message)->toBe('queued message')
        ->and($entry->context)->toHaveKey('_logscope_write_failure');

    $marker = $entry->context['_logscope_write_failure'];
    expect($marker['where'])->toBe('queue-worker');
});

it('writes a fallback row when batch buffer flush fails', function () {
    config(['logscope.write_mode' => 'batch']);

    // LogEntry::insert() (the bulk-insert path) bypasses model events, so
    // the `LogEntry::creating` poison used by sync/queue tests can't catch
    // it. Spy on FallbackWriter instead and verify the wiring — the
    // payload shape is already covered end-to-end by the sync tests.
    $spy = new class extends FallbackWriter
    {
        public array $calls = [];

        public function record(array $data, \Throwable $e, string $where): void
        {
            $this->calls[] = ['data' => $data, 'exception_class' => get_class($e), 'where' => $where];
        }
    };
    app()->instance(FallbackWriter::class, $spy);

    Log::error('batched message');

    Schema::drop('log_entries');
    LogScopeServiceProvider::flushLogBufferStatic();

    expect($spy->calls)->not->toBeEmpty();
    $call = $spy->calls[0];
    expect($call['where'])->toBe('buffer-flush')
        ->and($call['data']['message'])->toBe('batched message');
});

it('marks the fallback row with a recognizable channel so operators can filter', function () {
    poisonNormalInserts();

    Log::channel('stack')->error('channel-tagged message');

    $entry = LogEntry::query()->first();
    expect($entry)->not->toBeNull();
    // Original channel is preserved; the discriminator lives in context.
    expect($entry->context)->toHaveKey('_logscope_write_failure');
});

it('reads request context from Laravel Context when recordFromEvent is called', function () {
    // Direct unit test of the recordFromEvent path — the case where
    // buildLogData itself threw, so we have no sanitized $data yet.
    // Without re-reading Context here, trace_id/ip/url would be lost
    // on the very entries operators most need to correlate.
    Context::add('logscope', [
        'trace_id' => 'trace-xyz-789',
        'ip_address' => '5.6.7.8',
        'url' => 'https://test.app/path',
        'http_method' => 'POST',
        'user_agent' => 'TestAgent/1.0',
    ]);

    $fallback = app(FallbackWriter::class);
    $event = new \Illuminate\Log\Events\MessageLogged('error', 'pre-build failure', []);
    $fallback->recordFromEvent($event, 'mychan', new \Error('Class X not found'), 'listener');

    $entry = LogEntry::query()->first();
    expect($entry)->not->toBeNull()
        ->and($entry->trace_id)->toBe('trace-xyz-789')
        ->and($entry->ip_address)->toBe('5.6.7.8')
        ->and($entry->url)->toBe('https://test.app/path')
        ->and($entry->http_method)->toBe('POST')
        ->and($entry->user_agent)->toBe('TestAgent/1.0')
        ->and($entry->channel)->toBe('mychan')
        ->and($entry->message)->toBe('pre-build failure');
});

it('writes a separate fallback row for each unique throw site', function () {
    // Dedupe key is `class@file:line`. Two distinct sites must each emit
    // — otherwise a second, unrelated failure in the same process would
    // be invisible.
    $fallback = app(FallbackWriter::class);

    $fallback->record(['level' => 'error', 'message' => 'one'], new \RuntimeException('a'), 'listener');
    $fallback->record(['level' => 'error', 'message' => 'two'], new \LogicException('b'), 'listener');

    expect(LogEntry::query()->count())->toBe(2);
});

it('emits a heartbeat fallback row every 100th occurrence of the same failure', function () {
    // Strict once-per-process dedupe would let a sustained outage go
    // silent in the UI after the first row. The 100-occurrence
    // heartbeat (mirroring WriteFailureLogger) gives the operator
    // a "still happening" signal without flooding the table.
    poisonNormalInserts();

    for ($i = 0; $i < 100; $i++) {
        Log::error("attempt {$i}");
    }

    expect(LogEntry::query()->count())->toBe(2);

    $occurrences = LogEntry::query()
        ->get()
        ->pluck('context')
        ->map(fn ($c) => $c['_logscope_write_failure']['occurrence'])
        ->all();

    expect($occurrences)->toEqualCanonicalizing([1, 100]);
});

it('re-throws transient QueryException so Laravel retries the queue job', function () {
    // SQLSTATE 08* (connection failures) and 40* (deadlocks) are exactly
    // what queue retries exist for. Swallowing them — as the original
    // catch-all did — defeats the queue's transient-error contract.
    $job = new WriteLogEntry(['level' => 'error', 'message' => 'transient']);

    $pdo = new class extends \PDOException
    {
        public function __construct()
        {
            parent::__construct('Connection lost');
            $this->code = '08006';
        }
    };
    $transient = new \Illuminate\Database\QueryException('sqlite', 'SELECT 1', [], $pdo);

    LogEntry::creating(function () use ($transient) {
        throw $transient;
    });

    expect(fn () => $job->handle())->toThrow(\Illuminate\Database\QueryException::class);

    // Critical: no fallback row was written. If the retry succeeds, we'd
    // otherwise have a duplicate (one fallback marker + one real entry).
    expect(LogEntry::query()->count())->toBe(0);
});

it('swallows persistent failures in the queue worker and writes a fallback row', function () {
    // Persistent errors (autoload, schema mismatch, malformed data) won't
    // succeed on retry — they'd just loop the worker. Record observability
    // and swallow so the job marks complete.
    $job = new WriteLogEntry(['level' => 'error', 'message' => 'persistent']);

    poisonNormalInserts();

    expect(fn () => $job->handle())->not->toThrow(\Throwable::class);
    expect(LogEntry::query()->count())->toBe(1);
});

it('survives a missing or broken FallbackWriter binding during batch flush', function () {
    // At PHP shutdown the container can be torn down; resolving the
    // FallbackWriter via app() may itself throw. The buffer-flush path
    // must treat that as best-effort and not propagate the error,
    // because we're already inside another error-handler frame.
    config(['logscope.write_mode' => 'batch']);

    app()->bind(FallbackWriter::class, function () {
        throw new \Exception('intentionally broken container resolution');
    });

    Log::error('batched');

    Schema::drop('log_entries');

    expect(fn () => LogScopeServiceProvider::flushLogBufferStatic())
        ->not->toThrow(\Throwable::class);
});

it('reset() clears the occurrence map so dedupe restarts (Octane request-boundary contract)', function () {
    // The Octane RequestReceived listener calls FallbackWriter::reset() so
    // a long-running worker doesn't strand its dedupe state across
    // request boundaries. This test verifies the underlying invariant
    // without requiring Octane to be installed.
    poisonNormalInserts();

    Log::error('first');
    expect(LogEntry::count())->toBe(1);

    FallbackWriter::reset();

    Log::error('second');
    expect(LogEntry::count())->toBe(2);
});
