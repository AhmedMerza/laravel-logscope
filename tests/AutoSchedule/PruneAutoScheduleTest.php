<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;

it('registers the prune schedule on the laravel scheduler when auto_schedule is true at boot', function (): void {
    // The AutoScheduleEnabledTestCase sets logscope.retention.auto_schedule
    // = true via defineEnvironment(), so by the time we're inside the test
    // the provider's registerScheduledTasks() has already wired up the
    // callAfterResolving(Schedule::class, ...) callback. Resolving Schedule
    // here triggers it, and we assert the resulting schedule entry.
    $schedule = app(Schedule::class);

    $events = collect($schedule->events())->filter(
        fn ($e) => str_contains($e->command ?? '', 'logscope:prune')
    );

    expect($events)->toHaveCount(1);

    $event = $events->first();
    expect($event->expression)->toBe('30 4 * * *'); // schedule_at='04:30' from the test case
    expect($event->onOneServer)->toBeTrue();
});
