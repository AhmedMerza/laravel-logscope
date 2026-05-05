<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use LogScope\LogScope;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Force the array cache driver — Testbench's default `database` cache
    // would hit a `cache` table that the test SQLite DB doesn't have.
    config(['cache.default' => 'array']);
    Cache::flush();

    // Bypass LogScope's authorize middleware in test env (default check
    // requires 'local' env, but Testbench uses 'testing').
    LogScope::auth(fn () => true);
});

afterEach(function (): void {
    LogScope::resetAuth();
});

/*
 * These tests pin the Watchtower integration contract so a future rename
 * (on either side) fails loudly instead of silently disabling the in-detail
 * Block-IP button. The contract is:
 *
 *   LogScope's dashboard reads `config('watchtower.enabled')` and exposes
 *   it as the boolean `guard` flag in `window.logScopeConfig`. Watchtower's
 *   ip-actions partial reads the same flag to decide whether to render.
 *
 * If LogScope ever renames the JS field or the config key it reads, these
 * tests fail and the change is forced to coordinate with Watchtower.
 */

it('exposes watchtower.enabled as guard: true in the JS config when the flag is set', function (): void {
    config(['watchtower.enabled' => true]);

    $response = $this->get('/logscope');

    $response->assertOk();
    $response->assertSee('guard: true', escape: false);
});

it('exposes guard: false when watchtower is not installed (config namespace empty)', function (): void {
    // Simulate Watchtower not installed — its config namespace doesn't exist.
    config(['watchtower' => []]);

    $response = $this->get('/logscope');

    $response->assertOk();
    $response->assertSee('guard: false', escape: false);
});

it('exposes guard: false when watchtower is installed but explicitly disabled', function (): void {
    config(['watchtower.enabled' => false]);

    $response = $this->get('/logscope');

    $response->assertOk();
    $response->assertSee('guard: false', escape: false);
});
