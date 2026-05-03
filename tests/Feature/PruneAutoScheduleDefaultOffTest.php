<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;

it('does not register the prune schedule when auto_schedule is off (default)', function (): void {
    // Default config has auto_schedule=false. The provider's
    // registerScheduledTasks() should early-return, leaving the Schedule
    // binding free of any LogScope-named entries.
    expect(config('logscope.retention.auto_schedule'))->toBeFalse();

    $schedule = app(Schedule::class);
    $events = collect($schedule->events())->filter(
        fn ($e) => str_contains($e->command ?? '', 'logscope:prune')
    );

    expect($events)->toBeEmpty();
});
