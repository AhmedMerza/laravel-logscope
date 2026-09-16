<?php

declare(strict_types=1);

// Regression tests for issue #54.
//
// CaptureRequestContext stores $request->ip() once, at the moment it runs.
// Symfony only honours X-Forwarded-For after TrustProxies has handed it the
// trusted-proxy list, so while the middleware was prepended to the global
// stack every log entry behind a load balancer recorded the balancer's
// address as ip_address.

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use LogScope\LogScopeServiceProvider;
use LogScope\Models\LogEntry;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->artisan('migrate', ['--path' => __DIR__.'/../../database/migrations']);

    LogScopeServiceProvider::resetBufferState();
    LogEntry::query()->delete();

    // Symfony keeps the trusted-proxy list in a static, so a TrustProxies run
    // from an earlier test leaks into this one — and with it leaked, ip()
    // resolves the forwarded address no matter where we capture, hiding the
    // very bug these tests exist to catch.
    Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);

    // TrustProxies reads this when no bootstrap/app.php config is in play.
    config(['trustedproxy.proxies' => ['10.0.0.5']]);

    Route::get('/logscope-test/proxy-ip', function () {
        Log::info('behind the proxy');

        return 'ok';
    });
});

it('records the client IP when a trusted proxy forwards the request', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
        ->get('/logscope-test/proxy-ip', ['X-Forwarded-For' => '203.0.113.7'])
        ->assertOk();

    expect(LogEntry::where('message', 'behind the proxy')->value('ip_address'))
        ->toBe('203.0.113.7');
});

it('records the peer address when the forwarded header comes from an untrusted peer', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
        ->get('/logscope-test/proxy-ip', ['X-Forwarded-For' => '203.0.113.7'])
        ->assertOk();

    expect(LogEntry::where('message', 'behind the proxy')->value('ip_address'))
        ->toBe('198.51.100.9');
});
