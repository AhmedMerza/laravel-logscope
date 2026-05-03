<?php

declare(strict_types=1);

namespace LogScope\Tests;

/**
 * Test case that boots the framework with `logscope.retention.auto_schedule`
 * forced on, so the provider's `registerScheduledTasks()` actually runs at
 * boot time (rather than us re-implementing the wiring inline in a test).
 *
 * This is the only way to verify the real provider code path — config
 * mutated AFTER boot won't trigger registerScheduledTasks because boot
 * has already finished.
 */
abstract class AutoScheduleEnabledTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('logscope.retention.auto_schedule', true);
        $app['config']->set('logscope.retention.schedule_at', '04:30');
    }
}
