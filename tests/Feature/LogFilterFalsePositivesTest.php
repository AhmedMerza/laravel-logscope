<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use LogScope\Logging\ChannelContextProcessor;
use LogScope\LogScopeServiceProvider;
use LogScope\Models\LogEntry;

uses(RefreshDatabase::class);

beforeEach(function () {
    // RefreshDatabase already runs all migrations; package migrations are
    // registered via loadMigrationsFrom() in LogScopeServiceProvider::boot()
    // and are picked up automatically. No explicit artisan migrate call needed.
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

    /**
     * Simulate a processor invocation: the channel slot AND isFresh flag
     * must be set together — that's what consumeLastChannel checks.
     */
    function setChannelAsFresh(string $channel): void
    {
        $reflection = new ReflectionClass(ChannelContextProcessor::class);
        $channelProp = $reflection->getProperty('lastChannel');
        $channelProp->setAccessible(true);
        $channelProp->setValue(null, $channel);

        $freshProp = $reflection->getProperty('isFresh');
        $freshProp->setAccessible(true);
        $freshProp->setValue(null, true);
    }

    it('still ignores PHP runtime deprecation warnings on the default deprecations channel', function () {
        config(['logscope.ignore.deprecations' => true]);

        // The post-fix filter ignores deprecations only when the LAST channel
        // captured by the processor is in the configured deprecation_channels
        // list (default: ['deprecations']).
        setChannelAsFresh('deprecations');

        event(new \Illuminate\Log\Events\MessageLogged(
            'warning',
            'strpos(): Passing null to parameter #1 is deprecated',
            []
        ));

        expect(LogEntry::count())->toBe(0);
    });

    it('honors a custom deprecation_channels list for apps that remap the channel name', function () {
        config([
            'logscope.ignore.deprecations' => true,
            'logscope.ignore.deprecation_channels' => ['php-deprecations', 'legacy-warnings'],
        ]);

        setChannelAsFresh('php-deprecations');

        event(new \Illuminate\Log\Events\MessageLogged('warning', 'a deprecation', []));

        // The default 'deprecations' name is no longer in the list, but our
        // custom name is — log should be ignored.
        expect(LogEntry::count())->toBe(0);
    });

    it('does not ignore logs from channels NOT in the deprecation_channels list', function () {
        config([
            'logscope.ignore.deprecations' => true,
            'logscope.ignore.deprecation_channels' => ['deprecations'],
        ]);

        setChannelAsFresh('application');

        // 'application' isn't in the deprecation_channels list — the log must
        // be captured even though the message mentions deprecation (the old
        // substring filter would have dropped it).
        event(new \Illuminate\Log\Events\MessageLogged(
            'warning',
            'feature flag x is deprecated',
            []
        ));

        expect(LogEntry::count())->toBe(1);
    });
});
