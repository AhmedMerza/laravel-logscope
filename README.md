# LogScope

[![Latest Version](https://img.shields.io/packagist/v/ahmedmerza/logscope.svg?style=flat-square)](https://packagist.org/packages/ahmedmerza/logscope)
[![License](https://img.shields.io/packagist/l/ahmedmerza/logscope.svg?style=flat-square)](https://packagist.org/packages/ahmedmerza/logscope)
[![PHP Version](https://img.shields.io/packagist/php-v/ahmedmerza/logscope.svg?style=flat-square)](https://packagist.org/packages/ahmedmerza/logscope)

A log viewer for Laravel that stores your logs in your own database, so you can search, filter and triage them from the browser.

Search by field (`level:error`, `user_id:42`) or across everything. Repeated errors are grouped into one issue with a count, and when you mark an issue resolved it stays resolved, unless it happens again. LogScope is designed to be left on in production, and it works alongside Telescope in development or an exception tracker like Sentry.

![LogScope demo: structured search, NOT filter, keyboard triage, dark mode](https://raw.githubusercontent.com/AhmedMerza/laravel-logscope/master/art/logscope-demo.gif)

## Quick Start

```bash
composer require ahmedmerza/logscope
php artisan logscope:install
php artisan migrate
```

Visit `/logscope` in your browser. That's it!

---

## What's New

**[v2.2.0](https://github.com/AhmedMerza/laravel-logscope/releases/tag/v2.2.0):** repeated entries are now grouped into issues, and a resolved issue reopens if it fires again. Log writes no longer happen inside your transactions.

**Upgrading:** run `php artisan migrate`, then `php artisan logscope:backfill-fingerprints`. Full notes, including one behaviour change to `sensitive_keys`, are in the [changelog](https://github.com/AhmedMerza/laravel-logscope/blob/master/CHANGELOG.md).

---

## Table of Contents

- [Features](#-features)
- [Requirements](#-requirements)
- [When to Use LogScope](#-when-to-use-logscope)
- [Installation](#-installation)
- [Configuration](#%EF%B8%8F-configuration)
- [Usage](#-usage)
- [Production Deployment](#-production-deployment)
- [Customization](#-customization)
- [Contributing](#-contributing)
- [License](#-license)

---

## ✨ Features

| Feature | Description |
|---------|-------------|
| **Zero-Config Capture** | Automatically captures ALL logs from ALL channels |
| **Request Context** | Trace ID, user ID, IP, URL, and user agent for every log |
| **Advanced Search** | Search syntax (`field:value`), regex support, NOT toggle |
| **Smart Filters** | Include/exclude by level, channel, HTTP method, date range |
| **Active Filters Bar** | See all active filters at a glance, clear individually |
| **Channel Search** | Search and filter channels when you have many |
| **JSON Viewer** | Syntax-highlighted, collapsible JSON with copy support |
| **Smart Context** | Auto-expand Request/Model objects, redact sensitive data |
| **Issue Grouping** | Repeated entries roll up into one issue with an occurrence count |
| **Status Workflow** | Mark issues open, investigating, resolved, or ignored; resolved issues reopen if they recur |
| **Log Notes** | Add investigation notes to any log entry |
| **Quick Filters** | One-click filters for common queries |
| **Keyboard Shortcuts** | 14 shortcuts for navigation, status changes, and actions |
| **Dark Mode** | Full dark mode support with persistence |
| **Shareable URLs** | Current filters reflected in URL for sharing |
| **Deep Linking** | Link directly to specific log entries |
| **Performance** | Keyset pagination, batch writes, query optimization, proper indexing |

---

## 📋 Requirements

- PHP 8.2+
- Laravel 11+
- SQLite, MySQL, or PostgreSQL

---

## 🤔 When to Use LogScope

LogScope stores logs in your database - a deliberate choice that works great for most Laravel apps.

**Great fit if you:**
- Want log visibility without external services
- Have a typical Laravel app (up to ~100K requests/day)
- Need rich search and filtering
- Prefer simplicity over infrastructure complexity

**Consider alternatives if you:**
- Process millions of requests daily
- Need months/years of log retention
- Already use centralized logging (Datadog, CloudWatch, ELK)

**How LogScope handles common concerns:**

| Concern | Solution |
|---------|----------|
| Database bloat | Retention policies with scheduled pruning (default: 30 days) |
| Performance | Batch mode writes logs *after* response is sent |
| Query speed | Proper indexes on common filter combinations |

---

## ⚠️ Known Limitations

LogScope captures everything that flows through Laravel's logger (`Illuminate\Log\Logger`). A few categories of logs structurally bypass that path and **cannot be auto-captured**. These are PHP / framework limitations, not bugs we can fix from a Composer package.

### 1. PHP's native `error_log()` is not interceptable

`error_log("...")` is a built-in PHP function that writes directly to the destination configured in `php.ini`'s `error_log` directive (file, syslog, or stderr). It calls into PHP's C-level logging, never invokes `set_error_handler`, and there's no userland API to redirect it. Workarounds (the [`uopz`](https://www.php.net/manual/en/book.uopz.php) extension, php.ini overrides, stderr capture) all live outside the PHP application — out of scope for a package.

If your app or a dependency calls `error_log()`, those messages land in your php-fpm/server log, **not** in LogScope. Search both places when investigating.

### 2. `trigger_error(E_USER_*)` *should* work, but depends on Laravel's exception handler

PHP routes `trigger_error()` through the registered `set_error_handler`, and Laravel's `HandleExceptions` bootstrapper installs one that converts these to `ErrorException` and reports them — which then fires `MessageLogged` and reaches LogScope. So in normal HTTP/CLI flow this works.

**It can break in specific contexts** where Laravel's exception handler is bypassed:
- `php artisan tinker` (skips reporting via `runningUnitTests()`-style guards in some versions)
- Custom `set_error_handler` calls in user code that don't chain to the previous handler
- `error_reporting` being lowered to exclude `E_USER_*` levels

If something seems missing here, check `error_reporting()` and that `\Illuminate\Foundation\Bootstrap\HandleExceptions::class` is in your bootstrap chain (it is by default).

### 3. Direct Monolog instances bypass capture

If a dependency (or your own code) does:

```php
$logger = new \Monolog\Logger('foo');
$logger->error('something');
```

…that's a raw Monolog logger, not Laravel's `Illuminate\Log\Logger`. Only Laravel's logger fires `MessageLogged`. The Monolog instance has no way to know LogScope exists.

**Opt-in workaround:** push our handler onto the Monolog instance:

```php
use LogScope\Logging\LogScopeHandler;

$logger = new \Monolog\Logger('foo');
$logger->pushHandler(new LogScopeHandler('foo'));  // 'foo' = the channel name to record
$logger->error('captured by LogScope now');
```

Auto-instrumentation isn't possible — we'd have to patch the `Monolog\Logger` class itself.

### 4. SIGKILL / segfault / E_PARSE in batch write mode loses the buffered batch

Batch mode (`LOGSCOPE_WRITE_MODE=batch`, default) accumulates logs during the request and flushes them on `app->terminating()` or PHP shutdown. Both safety nets require a graceful shutdown:

- `kill -9` (SIGKILL): cannot be caught by PHP — buffer is gone
- OOM kill: same
- `E_PARSE` / `E_COMPILE_ERROR`: PHP can't run user code at shutdown for these

What's at risk is what's still in the buffer. The buffer is also written once it holds 500 logs or its oldest log is 10 seconds old (see [Write Mode](#write-mode-performance)), and queue workers flush after every job, so a long-running artisan command or worker loses at most that much, not everything it logged since it started. Inside an open database transaction the limits don't apply — the buffer waits for the transaction to end instead — so a crash during a long transaction can lose more, up to the 5,000-entry cap at which LogScope writes anyway.

**If low-loss is critical**, set `LOGSCOPE_WRITE_MODE=sync` to write every log immediately. Cost: each `Log::*()` call adds a synchronous DB round-trip. Logs written inside one of your own transactions still wait for it to end (see [Writes During Your Transactions](#writes-during-your-transactions)) unless you also set `LOGSCOPE_DEFER_IN_TRANSACTIONS=false`.

### 5. `null_channel` filter — read before enabling

`LOGSCOPE_IGNORE_NULL_CHANNEL=true` drops logs that LogScope can't attribute to a named channel. **This includes Laravel's own framework-level error reporter in some configurations**, which means enabling this flag may silently drop unhandled exceptions. Only enable if you know exactly which no-channel logs flow through your app.

---

## 📦 Installation

```bash
composer require ahmedmerza/logscope
```

Run the install command:

```bash
php artisan logscope:install
php artisan migrate
```

Access the dashboard at `/logscope`.

---

## ⚙️ Configuration

After installation, configure LogScope in `config/logscope.php` or via environment variables.

### Capture Mode

```env
# 'all' (default) - Capture all logs automatically
# 'channel' - Only capture logs sent to the logscope channel
LOGSCOPE_CAPTURE=all
```

### Write Mode (Performance)

```env
# 'batch' (default) - Buffer logs, write after response
# 'sync' - Write immediately (simple, but slower)
# 'queue' - Queue each log entry (best for high-traffic)
LOGSCOPE_WRITE_MODE=batch

# Batch mode also writes early, so long-running commands don't hold logs
# until they exit: once 500 logs are buffered, or the oldest is 10 seconds
# old. Queue workers also flush after every job. 0 disables a limit.
LOGSCOPE_BATCH_MAX_ENTRIES=500
LOGSCOPE_BATCH_MAX_AGE=10

# Queue settings (when using 'queue' mode)
LOGSCOPE_QUEUE=default
LOGSCOPE_QUEUE_CONNECTION=
```

### Writes During Your Transactions

LogScope writes on your application's database connection, so a log written inside one of your transactions would sit in the log table, uncommitted, until you commit. That has three costs: the Clear button and `logscope:prune` wait on your request (and a Clear covering two levels or channels can deadlock with it, which your transaction loses); the log rows roll back with the transaction, losing exactly the logs that explain the rollback; and every write pays for a savepoint.

So LogScope doesn't write there. A log written while a transaction is open is held in memory and written as soon as that transaction ends — committed or rolled back, keeping the time it was logged. Reading logs never affects your app.

```env
# Turn it off to write inside your transactions as before.
LOGSCOPE_DEFER_IN_TRANSACTIONS=true
```

This covers the writes that land on your connection: `sync`, channel capture, and `queue` on the `sync` or `database` driver. `batch` already worked this way, and a broker queue (redis, sqs) is left alone — your transaction never touched it, so holding the entry in memory would only cost the dispatch its durability. Entries held during a transaction are written directly when it ends rather than dispatched; the job would have written the same rows on the same connection a moment later.

One bound: a single transaction that logs more than ten times `LOGSCOPE_BATCH_MAX_ENTRIES` (5,000 by default) would grow that buffer until the process ran out of memory, so at that point LogScope writes inside your transaction after all. Each write is isolated in a savepoint, so a failure can't take your transaction with it.

### Testing

LogScope forces `write_mode` to `sync` whenever the app is running in the `testing` environment, regardless of what `LOGSCOPE_WRITE_MODE` or `config/logscope.php` says. This mirrors how Laravel ships sensible test defaults for mail (`array`), queue (`sync`), and cache (`array`).

Why: in `batch` mode the buffer is flushed on `Application::terminate()`. Unit tests that never dispatch a request never trigger that callback, so entries accumulate across tests and get discarded at PHP shutdown — producing both noisy stderr warnings and silent loss of the captured logs. `sync` writes land inside the test's transaction and roll back cleanly with `RefreshDatabase` / `DatabaseTransactions`.

If you specifically want to exercise batch behavior in a test, opt back in inside `setUp()`:

```php
protected function setUp(): void
{
    parent::setUp();
    config(['logscope.write_mode' => 'batch']);
}
```

Even with that override, the shutdown discard warning is suppressed in the testing env (the loss is expected and uninteresting) — production keeps the loud notify so real data loss stays visible.

Deferring writes during transactions is switched off in the testing environment for the same reason. `RefreshDatabase` wraps every test in a transaction it never commits, so a deferred log would never be written and any assertion against it would fail; nothing distinguishes that wrapping transaction from one of your own at runtime. Test writes stay immediate, protected by savepoints as before. To exercise the real behaviour, turn it back on in a test that manages its own transactions (not `RefreshDatabase`):

```php
config(['logscope.defer_in_transactions' => true]);
```

### Retention

```env
LOGSCOPE_RETENTION_ENABLED=true
LOGSCOPE_RETENTION_DAYS=30

# Optional: let LogScope register the prune schedule for you (off by default).
# Leave off if you already wire `logscope:prune` in your own console kernel.
LOGSCOPE_RETENTION_AUTO_SCHEDULE=false
LOGSCOPE_RETENTION_SCHEDULE_AT=03:00
```

> **Note:** Retention requires either flipping `LOGSCOPE_RETENTION_AUTO_SCHEDULE=true` or scheduling `logscope:prune` yourself - see [Schedule Pruning](#schedule-pruning).

### Features

```env
LOGSCOPE_FEATURE_STATUS=true       # Enable status workflow
LOGSCOPE_FEATURE_NOTES=true        # Add notes to logs
```

### Noise Reduction

```env
# Filter out noisy logs
LOGSCOPE_IGNORE_DEPRECATIONS=true  # Skip PHP deprecation notices (default: true)
LOGSCOPE_IGNORE_NULL_CHANNEL=false # Skip logs without a channel (default: false)
```

**How the deprecations filter decides.** It does *not* drop anything containing the words "is deprecated" — that was the behaviour before v1.5.5, and it silently swallowed real logs like `Log::warning('Account 42 is deprecated for billing')`. A log is now ignored if either:

1. its channel is listed in `ignore.deprecation_channels` (default `['deprecations']`, Laravel's standard channel for `E_DEPRECATED`), or
2. its message matches Laravel's wrapped runtime-deprecation format — the one ending `is deprecated in <file> on line <N>`.

The second check exists because Laravel creates the `deprecations` channel lazily, on the first deprecation, which is after LogScope's channel processor registers — so the channel name alone caught nothing (v1.5.5–v1.5.6). Requiring the `on line <N>` suffix is what keeps it from matching business logs again.

If your app routes deprecations through a differently-named channel, add it in `config/logscope.php`:

```php
'ignore' => [
    'deprecation_channels' => ['deprecations', 'php-warnings'],
],
```

> **⚠️ Think before enabling `null_channel`.** Logs with no channel are not just `Log::build()` dynamic loggers — they also include Laravel's own framework-level error reporter, which routes uncaught exceptions through a stack that may not carry the channel processor. **Enabling this will drop unhandled exceptions in many apps.** Only turn it on if you know exactly what flows through the no-channel path in your app.

### Cache TTL

```env
# How long (in seconds) to cache stats and filter options (levels, channels, etc.)
# Set to 0 to disable caching
LOGSCOPE_CACHE_TTL=60
```

### JSON Viewer

Configure collapsible JSON behavior in `config/logscope.php`:

```php
'json_viewer' => [
    'collapse_threshold' => 5,  // Auto-collapse arrays/objects larger than this
    'auto_collapse_keys' => ['trace', 'stack_trace', 'stacktrace', 'backtrace'],
],
```

### Routes

```env
LOGSCOPE_ROUTES_ENABLED=true
LOGSCOPE_ROUTE_PREFIX=logscope
LOGSCOPE_DOMAIN=
LOGSCOPE_FORBIDDEN_REDIRECT=/
LOGSCOPE_UNAUTHENTICATED_REDIRECT=/login
```

Add middleware and configure error redirects:

```php
'routes' => [
    'middleware' => ['web', 'auth'],
    'forbidden_redirect' => '/',           // Where to redirect on 403 (access denied)
    'unauthenticated_redirect' => '/login', // Where to redirect on 401/419 (session expired)
],
```

### Error Handling

LogScope handles errors gracefully with toast notifications:

| Error | Behavior |
|-------|----------|
| 401/419 (Session expired) | Toast + redirect to `unauthenticated_redirect` |
| 403 (Access denied) | Toast + redirect to `forbidden_redirect` |
| 429 (Rate limited) | Toast only (retry later) |
| 500+ (Server error) | Toast only (temporary issue) |
| Network error | Toast only (check connection) |

> **Note:** Redirect URLs can be relative paths (`/login`) or absolute URLs (`https://auth.example.com/login`).

---

## 🚀 Usage

### Automatic Capture

All logs are captured automatically - no code changes needed:

```php
Log::info('User logged in', ['user_id' => 1]);
Log::channel('slack')->error('Payment failed');
Log::stack(['daily', 'slack'])->warning('Low inventory');
```

### Keyboard Shortcuts

| Key | Action |
|-----|--------|
| `j` / `k` | Navigate down / up |
| `h` / `l` | Previous / next page |
| `r` | Refresh data |
| `Enter` | Open detail panel |
| `Esc` | Close panel |
| `/` | Focus search |
| `y` | Copy context (yank) |
| `n` | Focus note field |
| `c` | Clear all filters |
| `d` | Toggle dark mode |
| `?` | Show keyboard help |

**Status shortcuts** *(require Shift, context-aware — **behavior change since v1.5.8**)*:

| `O` | Open | `I` | Investigating | `R` | Resolved | `X` | Ignored |

- **With a log detail panel open** → change that log's status, advance to the next log. Optimistic UI: the row updates (or disappears, if hidden by current filter) immediately, no spinner. Designed for rapid triage — chain `R, R, R, R` to resolve four logs in seconds.
- **With no detail open** → filter the list by that status (the legacy behavior).

Pre-v1.5.9, these shortcuts always filtered the list, regardless of whether a log was open. If you relied on that, the in-detail-panel behavior is what you'll notice as different. The "no detail open" path is unchanged.

If a status update fails (network, server error), the row is restored and an error toast appears.

Action shortcuts (`r`, `h`, `l`) and status shortcuts are configurable — see [Keyboard Shortcuts](#keyboard-shortcuts).

### Search Syntax

Type directly in the search box using `field:value` syntax:

| Syntax | Example | Description |
|--------|---------|-------------|
| `field:value` | `message:error` | Search in specific field |
| `-field:value` | `-level:debug` | Exclude matches |
| `field:"value"` | `message:"user login"` | Quoted values with spaces |
| `text` | `error` | Search in all fields |
| `-text` | `-deprecated` | Exclude from all fields |

**Searchable fields:** `message`, `source`, `context`, `level`, `channel`, `user_id`, `ip_address`, `url`, `trace_id`, `http_method`, `headers`

`headers:` matches the stored header JSON, so it finds names as well as values — `headers:application/xml` finds a content type, `headers:x-request-id` finds every row that carried that header at all. Headers are deliberately left out of plain-text search, so an ordinary search never scans the JSON column.

#### How multi-word and quoted searches behave

LogScope only switches into structured-parse mode when the input actually looks structured. Otherwise the whole input is matched as a single substring — so a stray `:` in a log message (e.g. `failed: timeout`) doesn't fragment your query.

| Input | Treated as | Matches |
|-------|------------|---------|
| `payment failed: timeout` | Single substring | Logs whose message/context/source contains the contiguous phrase `payment failed: timeout` |
| `"payment failed"` | Single substring (quotes stripped) | Logs containing `payment failed` contiguously |
| `level:error message:timeout` | Structured (`field:value` × 2) | Logs where `level` matches `error` AND `message` contains `timeout` |
| `payment -warning` | Structured (per-token `-` exclusion) | Logs containing `payment` AND NOT containing `warning` |
| `level:error` alone | Structured | Logs where `level` matches `error` |

**Structured mode triggers when the input contains any of:** a quoted phrase (`"..."`), a per-token exclusion (`-word` or ` -word`), or a `field:value` where `field` is one of the searchable field names listed above.

#### NOT toggle = true boolean complement

The UI's NOT toggle (or `exclude=1` on a `searches[]` query-string entry) inverts the entire search expression. For any input, `include_count + exclude_count == total_count` — logs are never lost between the two views.

> **Tip:** Request context filters (trace ID, user ID, IP, URL) support partial matching. Type `192.168` to find all IPs starting with that prefix, or `42` to find user IDs containing "42".

> **Tip:** Click on trace ID, user ID, or IP address in the detail panel to pivot your investigation — severity, channel, status, and search filters are cleared so nothing is hidden. Your date range is preserved.

**Examples:**

```bash
# Find errors in the API channel
channel:api level:error

# Find payment logs excluding debug
channel:payment -level:debug

# Find logs mentioning a specific user ID
user_id:123

# Find logs containing "timeout" anywhere
timeout

# Find logs with "connection failed" in message
message:"connection failed"

# Find context containing a job ID
context:abc123

# Exclude deprecated warnings
-message:deprecated

# Combine multiple conditions
level:error channel:database message:timeout

# Find all POST requests
http_method:POST

# Find logs from specific URL path
url:/api/payments

# Exclude health check endpoints
-url:/health -url:/ping

# Find logs from specific IP range
ip_address:192.168

# Track a specific request by trace ID
trace_id:abc-123-def
```

**Regex mode:** Click the `.*` button to enable regex patterns:

```bash
# Match error OR warning levels
level:error|warning

# Match any payment-related message
message:payment.*failed

# Match IP addresses starting with 192.168
ip_address:192\.168\.\d+\.\d+
```

Both search syntax and regex can be disabled in config if not needed.

### Status Workflow

Logs have a status workflow: **Open** → **Investigating** → **Resolved** or **Ignored**.

Customize who changed the status:

```php
// In AppServiceProvider::boot()
use LogScope\LogScope;

LogScope::statusChangedBy(function ($request) {
    return $request->user()?->name;
});
```

#### Customize Statuses

Override built-in statuses or add new ones in `config/logscope.php`:

```php
'statuses' => [
    // Override built-in status
    'investigating' => [
        'label' => 'In Progress',
        'color' => 'blue',
    ],
    // Add custom statuses
    'waiting' => [
        'label' => 'Waiting for Customer',
        'color' => 'orange',
        'closed' => false,  // Shows in "Needs Attention"
    ],
    'duplicate' => [
        'label' => 'Duplicate',
        'color' => 'purple',
        'closed' => true,   // Hidden from "Needs Attention"
    ],
],
```

Available colors: `gray`, `yellow`, `green`, `slate`, `blue`, `red`, `orange`, `purple`

### Quick Filters

Configure one-click filters in `config/logscope.php`:

```php
'quick_filters' => [
    ['label' => 'Today', 'icon' => 'calendar', 'from' => 'today'],
    ['label' => 'Recent Errors', 'icon' => 'alert', 'levels' => ['error', 'critical'], 'from' => '-24 hours'],
    ['label' => 'Needs Attention', 'icon' => 'filter', 'statuses' => ['open', 'investigating']],
    ['label' => 'Resolved Today', 'icon' => 'filter', 'statuses' => ['resolved'], 'from' => 'today'],
],
```

Available options: `label`, `icon` (calendar/clock/alert/filter), `levels`, `statuses`, `from`, `to`

### Status Shortcuts

Each status has a default keyboard shortcut (uppercase, requires Shift). The shortcut is **context-aware**:

- **Detail panel open:** change the open log's status, advance to next (rapid triage)
- **Detail panel closed:** filter the list by that status

Customize in `config/logscope.php`:

```php
'statuses' => [
    // Disable a shortcut entirely (no triage AND no filter for this status)
    'ignored' => ['shortcut' => null],

    // Custom status with shortcut — works in both contexts automatically
    'waiting' => ['label' => 'Waiting', 'color' => 'orange', 'shortcut' => 'w'],
],
```

### Keyboard Shortcuts

Action shortcuts (refresh, pagination) are configurable or can be disabled:

```php
'keyboard_shortcuts' => [
    'refresh'   => 'r',  // Refresh logs and stats
    'prev_page' => 'h',  // Previous page
    'next_page' => 'l',  // Next page
],
```

Set any shortcut to `null` to disable it:

```php
'keyboard_shortcuts' => [
    'prev_page' => null,  // Disable previous page shortcut
    'next_page' => null,
],
```

### Authorization

LogScope uses a flexible auth system (checked in order):

**1. Custom Callback:**
```php
LogScope::auth(fn ($request) => $request->user()?->isAdmin());
```

**2. Gate:**
```php
Gate::define('viewLogScope', fn ($user) => $user->hasRole('admin'));
```

**3. Default:** Only accessible in `local` environment.

### Custom Context

Add custom data to every log entry (e.g., API token ID, tenant ID):

```php
// In AppServiceProvider::boot()
use LogScope\LogScope;

LogScope::captureContext(function ($request) {
    $token = $request->user()?->currentAccessToken();

    return [
        // Sanctum returns TransientToken (no `id`) for session-authenticated
        // requests and PersonalAccessToken (has `id`) for token-authenticated
        // requests. Type-check before accessing.
        'token_id' => $token instanceof \Laravel\Sanctum\PersonalAccessToken ? $token->id : null,
        'tenant_id' => $request->user()?->tenant_id,
    ];
});
```

This data is merged into the log's `context` field and appears in the JSON viewer.

If your callback throws (a property access on the wrong type, an unbound service, etc.), LogScope catches the exception, drops the custom context for that log entry only, and adds `_logscope_callback_error` to the entry's context with the exception class + message. The original log is still captured. The callback failure is also surfaced via `error_log()` and the in-UI failure banner so you know the callback is broken.

### Artisan Commands

```bash
# Import existing log files (one-time migration)
php artisan logscope:import
php artisan logscope:import storage/logs/laravel.log --days=7

# Prune old logs
php artisan logscope:prune
php artisan logscope:prune --dry-run
php artisan logscope:prune --days=14

# Diagnose configuration & wiring (CI-friendly: non-zero exit on any FAIL)
php artisan logscope:doctor
php artisan logscope:doctor --json

# Smoke-test the capture pipeline end-to-end
php artisan logscope:test
php artisan logscope:test --keep   # leave the test entry in the table

# Fill the dashboard with sample entries (local evaluation)
php artisan logscope:seed
php artisan logscope:seed 200 --level=error
php artisan logscope:seed --realistic   # emit through Laravel's logger, not direct inserts
```

> **Note:** The import command is a one-time migration for existing log files. After setup, new logs are captured automatically.

`logscope:doctor` checks the table, capture mode, write mode (including queue connection), middleware wiring, retention + schedule status, authorization resolution path, Octane integration, built assets, and any cached write-failure breadcrumb. Run it after install or whenever something feels off.

`logscope:test` emits a uniquely-tagged log through the configured capture path, forces a sync write for the duration of the test, and verifies the entry lands in `log_entries`. Useful as a one-shot post-install sanity check.

`logscope:seed` generates sample entries so you can see the dashboard populated before your app has produced any real logs. `--realistic` routes them through Laravel's logger instead of inserting rows directly, which exercises the actual capture path. It writes fabricated data into `log_entries` — keep it out of production.

---

## 🏭 Production Deployment

### Recommended Settings

```env
LOGSCOPE_WRITE_MODE=batch
LOGSCOPE_RETENTION_DAYS=14
LOG_LEVEL=info
```

### Schedule Pruning

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

### High-Traffic Apps

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

## 🎨 Customization

### Theme

Customize the appearance in `config/logscope.php`:

```php
'theme' => [
    // Primary accent color (buttons, links, selections)
    'primary' => '#10b981',

    // Default to dark mode for new users (users can toggle and preference is saved)
    'dark_mode_default' => true,

    // Google Fonts (set to false to use system fonts)
    'fonts' => [
        'sans' => 'Outfit',         // UI text
        'mono' => 'JetBrains Mono', // Code/logs
    ],

    // Log level badge colors
    'levels' => [
        'error' => ['bg' => '#dc2626', 'text' => '#ffffff'],
        'warning' => ['bg' => '#f59e0b', 'text' => '#1f2937'],
        // ... other levels
    ],
],
```

**Disable external fonts** (use system fonts instead):

```php
'fonts' => [
    'sans' => false,
    'mono' => false,
],
```

### Context Sanitization

LogScope automatically expands objects in your log context for better debugging:

```php
// Request objects show useful data
Log::info('API request', ['request' => $request]);
// Context: { "request": { "_type": "request", "method": "POST", "url": "...", "input": {...} } }

// Models and Arrayable objects are converted
Log::info('User action', ['user' => $user]);
// Context: { "user": { "name": "John", "email": "..." } }
```

**Sensitive data is automatically redacted** (password, token, api_key, credit_card, etc.) — anywhere in an entry's context, at any depth:

```php
Log::info('Login', ['request' => $request]);
// Input: { "email": "john@example.com", "password": "[REDACTED]" }

Log::error('Payment failed', ['card_number' => $card, 'amount' => 500]);
// Context: { "card_number": "[REDACTED]", "amount": 500 }
```

Keys are matched **per word**, ignoring case and separators — so one entry covers every spelling. `card_number` redacts `card_number`, `card-number`, `cardNumber`, `CardNumber` and `card_numbers`; `token` redacts `access_token`, `APIToken` and `refresh_tokens`.

A multi-word entry also spans **array levels**, which is how a bracketed form field arrives:

```php
Log::error('Payment failed', ['card' => ['number' => $number, 'exp_month' => 12]]);
// Context: { "card": { "number": "[REDACTED]", "exp_month": 12 } }

// ?card[number]=4111 in a logged Request or URL is the same shape, and redacts too.
```

The two halves have to be **adjacent**. A numeric position is stepped over, so `card[0][number]` and `card[01][number]` both redact — but a named level between them ends the match, exactly as it would in a flat key: `['card' => ['holder' => ['number' => …]]]` is no more covered than `card_holder_number` is. List `number` in `sensitive_keys` if you need that shape.

Matching a one-word entry inside a single word is what keeps ordinary keys readable: `class_name` and `cv_video` are left alone, where collapsing the whole key would have made them match `ssn` and `cvv`. One-word entries never span levels for the same reason, so `['class' => ['name' => …]]` is kept as well.

That is deliberately broad, because a missed secret is invisible and a redacted field is not. When a field of your own reads `[REDACTED]` and shouldn't, name it in `sensitive_keys_except` rather than narrowing `sensitive_keys`:

```php
'context' => [
    'expand_objects' => true,      // Set false to show [Object: ClassName]
    'redact_sensitive' => true,    // Set false to disable redaction (not recommended)
    'sensitive_keys' => [],        // Key fragments added to the defaults (password, token, cvv, ...)
    'sensitive_keys_except' => [], // Keys to keep despite matching; adds to the defaults
    'sensitive_headers' => [],     // Name fragments added to the defaults (auth, cookie, token, key, ...)
],
```

`sensitive_keys_except` ships with `prompt_tokens`, `completion_tokens`, `total_tokens`, `token_count` and `tokenizer` — the LLM-era fields that `token` would otherwise catch. Your entries add to those.

All three lists **add to their defaults** — adding `pin` to `sensitive_keys` keeps `password`, `token` and the other nine. (Before v2.2.0 `sensitive_keys` replaced its defaults instead, so adding one key silently dropped eleven; see the CHANGELOG if you are upgrading.) To see what is redacted in a given environment rather than inferring it from config:

```bash
php artisan logscope:doctor
# Redaction   11 keys redacted: password, password_confirmation, secret, token, ... (+9 kept by sensitive_keys_except)
```

Redaction matches **key names, not values**: a secret pasted into a log message, an exception message, or a URL path is stored as written.

### Request Headers

Each entry can carry the request's headers in its own `headers` column — often the whole answer when debugging production: a content-type mismatch, `X-Forwarded-For` behind a proxy, which API client sent the request. CLI-originated logs store `null`.

Capture is **allowlist-only**. There is no "capture everything" mode, because that is where the risk lives: unknown vendor auth headers, `php-auth-pw`, and a table that grows fast. Add what you need:

```php
'context' => [
    'headers' => [
        'enabled' => true,

        // Matched case-insensitively. Add your own here.
        'allowlist' => [
            'content-type', 'accept', 'referer',
            'x-forwarded-for', 'x-request-id', 'origin',
        ],

        // Longer values are cut and end in …[truncated]
        'max_value_length' => 500,
    ],
],
```

The allowlist is the only dial: everything it captures is shown in the detail panel and is searchable. If a header turns out to be noise, take it off the allowlist — that stops storing it, rather than storing it and hiding it.

Anything matching `sensitive_headers` is stored as `[REDACTED]` **even when you allowlist it explicitly** — allowlisting `authorization` gets you the header's presence, never the token. `user-agent` is absent from the defaults on purpose: it already has its own column.

Headers are stored per row, not per request, so a request that logs 20 lines stores its headers 20 times. With the default allowlist that is a few hundred bytes a row — and it is the other reason there is no "capture everything" mode.

In the dashboard, headers appear in their own **Headers** section above Context, colored like the JSON viewer. Each row has a funnel button that pivots the list to a `headers:` search for that value; the values themselves are plain selectable text, so you can copy them.

Run `php artisan logscope:doctor` to see whether capture is on and what is allowlisted.

### Publishing Assets

```bash
php artisan vendor:publish --tag=logscope-config
php artisan vendor:publish --tag=logscope-migrations
php artisan vendor:publish --tag=logscope-views
```

### All Environment Variables

```env
# Capture & Performance
LOGSCOPE_CAPTURE=all
LOGSCOPE_WRITE_MODE=batch
LOGSCOPE_QUEUE=default
LOGSCOPE_QUEUE_CONNECTION=

# Features
LOGSCOPE_FEATURE_STATUS=true
LOGSCOPE_FEATURE_NOTES=true
LOGSCOPE_FEATURE_SEARCH_SYNTAX=true
LOGSCOPE_FEATURE_REGEX=true
LOGSCOPE_IGNORE_DEPRECATIONS=true
LOGSCOPE_IGNORE_NULL_CHANNEL=false

# Database & Retention
LOGSCOPE_TABLE=log_entries
LOGSCOPE_RETENTION_ENABLED=true
LOGSCOPE_RETENTION_DAYS=30
LOGSCOPE_MIGRATIONS_ENABLED=true

# Routes
LOGSCOPE_ROUTES_ENABLED=true
LOGSCOPE_ROUTE_PREFIX=logscope
LOGSCOPE_DOMAIN=
LOGSCOPE_MIDDLEWARE_ENABLED=true
LOGSCOPE_FORBIDDEN_REDIRECT=/
LOGSCOPE_UNAUTHENTICATED_REDIRECT=/login

# Search
LOGSCOPE_SEARCH_DRIVER=database

# Cache
LOGSCOPE_CACHE_TTL=60

# Context Sanitization
LOGSCOPE_EXPAND_OBJECTS=true
LOGSCOPE_REDACT_SENSITIVE=true
LOGSCOPE_CAPTURE_HEADERS=true
```

---

## 🛡️ Extensions

### Watchtower

Block malicious IPs and sync the blacklist across all your environments automatically. Blocking is done from Watchtower's own dashboard — LogScope shows you the traffic, Watchtower acts on it. (Previously named `ahmedmerza/logscope-guard`.)

```bash
composer require ahmedmerza/watchtower
php artisan watchtower:install
```

[View Watchtower →](https://github.com/AhmedMerza/laravel-watchtower)

---

## 🤝 Contributing

Contributions are welcome! Please open an issue or submit a pull request.

---

## 📄 License

MIT License. See [LICENSE](https://github.com/AhmedMerza/laravel-logscope/blob/master/LICENSE) for details.
