# Usage

How to search, triage and work with logs once LogScope is installed.

## Automatic Capture

All logs are captured automatically - no code changes needed:

```php
Log::info('User logged in', ['user_id' => 1]);
Log::channel('slack')->error('Payment failed');
Log::stack(['daily', 'slack'])->warning('Low inventory');
```

## Keyboard Shortcuts

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

Action shortcuts (`r`, `h`, `l`) and status shortcuts are configurable — see [Configuring Keyboard Shortcuts](#configuring-keyboard-shortcuts).

## Search Syntax

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

### How multi-word and quoted searches behave

LogScope only switches into structured-parse mode when the input actually looks structured. Otherwise the whole input is matched as a single substring — so a stray `:` in a log message (e.g. `failed: timeout`) doesn't fragment your query.

| Input | Treated as | Matches |
|-------|------------|---------|
| `payment failed: timeout` | Single substring | Logs whose message/context/source contains the contiguous phrase `payment failed: timeout` |
| `"payment failed"` | Single substring (quotes stripped) | Logs containing `payment failed` contiguously |
| `level:error message:timeout` | Structured (`field:value` × 2) | Logs where `level` matches `error` AND `message` contains `timeout` |
| `payment -warning` | Structured (per-token `-` exclusion) | Logs containing `payment` AND NOT containing `warning` |
| `level:error` alone | Structured | Logs where `level` matches `error` |

**Structured mode triggers when the input contains any of:** a quoted phrase (`"..."`), a per-token exclusion (`-word` or ` -word`), or a `field:value` where `field` is one of the searchable field names listed above.

### NOT toggle = true boolean complement

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

## Status Workflow

Logs have a status workflow: **Open** → **Investigating** → **Resolved** or **Ignored**.

Customize who changed the status:

```php
// In AppServiceProvider::boot()
use LogScope\LogScope;

LogScope::statusChangedBy(function ($request) {
    return $request->user()?->name;
});
```

### Customize Statuses

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

## Quick Filters

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

## Status Shortcuts

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

## Configuring Keyboard Shortcuts

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

## Custom Context

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

## Artisan Commands

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

[← Back to README](../README.md)
