<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use LogScope\Logging\ChannelContextProcessor;
use LogScope\LogScopeServiceProvider;
use LogScope\Models\LogEntry;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->artisan('migrate', ['--path' => __DIR__.'/../../database/migrations']);

    ChannelContextProcessor::clearLastChannel();
    LogScopeServiceProvider::resetBufferState();
    LogEntry::query()->delete();

    config(['logscope.write_mode' => 'sync']);
});

describe('isInternalLog substring false positive', function () {
    it('captures user logs that mention "LogScope" by name', function () {
        Log::error('LogScope client returned 503 — falling back to file driver');

        expect(LogEntry::count())->toBe(1)
            ->and(LogEntry::first()->message)->toContain('LogScope client returned 503');
    });

    it('captures user logs about the package even with mixed case', function () {
        Log::warning('logscope queue depth is at 95% capacity');

        expect(LogEntry::count())->toBe(1);
    });

    it('still skips logs flagged by the structured _logscope_internal context key', function () {
        Log::error('any message at all', ['_logscope_internal' => true]);

        expect(LogEntry::count())->toBe(0);
    });
});

describe('ignore.deprecations substring false positive', function () {
    it('captures user logs containing "is deprecated" when filter is on', function () {
        // Default config has deprecations filter ON — verify it still captures
        // legitimate business logs that happen to use the phrase.
        config(['logscope.ignore.deprecations' => true]);

        Log::warning('Account account-42 is deprecated for billing — migrate before 2026-12-31');

        expect(LogEntry::count())->toBe(1);
    });

    it('still ignores PHP runtime deprecation warnings (channel="deprecations")', function () {
        config(['logscope.ignore.deprecations' => true]);

        // The post-fix filter ignores deprecations only when the LAST channel
        // captured by the processor is "deprecations" — Laravel's standard
        // routing for E_DEPRECATED warnings. Simulate that by setting the
        // channel directly (the processor would normally do this if the
        // channel were in config at boot time).
        $prop = (new ReflectionClass(ChannelContextProcessor::class))
            ->getProperty('lastChannel');
        $prop->setAccessible(true);
        $prop->setValue(null, 'deprecations');

        // Bypass Log::channel() (which would re-resolve the channel and reset
        // the processor) — fire the event directly with the channel context
        // already in place.
        event(new \Illuminate\Log\Events\MessageLogged(
            'warning',
            'strpos(): Passing null to parameter #1 is deprecated',
            []
        ));

        expect(LogEntry::count())->toBe(0);
    });
});
