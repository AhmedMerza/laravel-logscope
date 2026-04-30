<?php

declare(strict_types=1);

use LogScope\Services\LogBuffer;

it('captures logs fired in another provider boot() that runs before LogScope boot()', function () {
    // EagerLoggingProvider is registered BEFORE LogScopeServiceProvider in
    // EagerProviderTestCase, so its boot() runs first in the boot phase.
    // For LogScope to hear that log, the MessageLogged listener must be
    // registered during the register phase (before any boot runs).
    //
    // We inspect the LogBuffer directly rather than the DB because the
    // package's migrations haven't run during boot — what we care about is
    // that the listener heard the event, not that it persisted to disk.
    //
    // No LogBuffer::reset() needed: each Pest test gets a fresh framework
    // boot, so the buffer starts empty and contains only what was fired
    // during this test's boot phase. If more tests are added to this folder
    // and they share state across cases, add a beforeEach() with reset().
    $messages = array_map(
        fn ($entry) => $entry['message'] ?? null,
        LogBuffer::getBuffer()
    );

    expect($messages)->toContain('eager-provider-boot-log');
});
