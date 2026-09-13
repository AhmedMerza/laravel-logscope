<?php

declare(strict_types=1);

// Regression for #36: a logger resolved before container teardown still holds
// the event dispatcher, so logging through it afterwards (e.g. from a
// register_shutdown_function) dispatches MessageLogged into LogScope's
// listener with no container behind it. The listener must stay quiet rather
// than throw "Target class [config] does not exist" and exit the process 255.
it('does not crash when a held logger logs after the container is flushed', function () {
    $logger = app('log')->channel();

    app()->flush();

    // Called bare, not via expect()->not->toThrow(): that form let the
    // exception through without failing. Any throw here fails the test.
    try {
        $logger->info('late held-logger log');
    } finally {
        // Restore the application so Pest's teardown can run
        $this->refreshApplication();
    }

    $this->expectNotToPerformAssertions();
});
