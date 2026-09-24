# Production Deployment

Settings and scheduling for running LogScope on a live application.

## Recommended Settings

```env
LOGSCOPE_WRITE_MODE=batch
LOGSCOPE_RETENTION_DAYS=14
LOG_LEVEL=info
```

## Schedule Pruning

You have two options:

**Option 1 — Let LogScope do it (opt-in, off by default).** Set `LOGSCOPE_RETENTION_AUTO_SCHEDULE=true` and LogScope registers `logscope:prune` on Laravel's scheduler at the time configured by `LOGSCOPE_RETENTION_SCHEDULE_AT` (defaults to `03:00`), with `->onOneServer()` for safe multi-server deploys.

**Option 2 — Wire it yourself.** Leave `LOGSCOPE_RETENTION_AUTO_SCHEDULE` off and add the schedule entry where you keep the rest of your scheduled tasks:

```php
// routes/console.php
Schedule::command('logscope:prune')->daily();

// or app/Console/Kernel.php, in apps upgraded from Laravel 10 or earlier that kept it
$schedule->command('logscope:prune')->daily();
```

> **Don't enable both** — auto-schedule plus a manual entry will run prune twice per night.

## High-Traffic Apps

For thousands of requests/day:

1. Use queue mode with a dedicated queue:
   ```env
   LOGSCOPE_WRITE_MODE=queue
   LOGSCOPE_QUEUE=logs
   ```

2. Run a separate queue worker:
   ```bash
   php artisan queue:work --queue=logs
   ```

3. Consider shorter retention (7 days).

---

[← Back to README](../README.md)
