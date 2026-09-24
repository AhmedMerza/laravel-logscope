# Configuration

Every setting lives in `config/logscope.php` (publish it with `php artisan logscope:install`) and most can be set from `.env`. The full list of environment variables is at the [end of this page](#all-environment-variables).

After installation, configure LogScope in `config/logscope.php` or via environment variables.

## Capture Mode

```env
# 'all' (default) - Capture all logs automatically
# 'channel' - Only capture logs sent to the logscope channel
LOGSCOPE_CAPTURE=all
```

## Write Mode (Performance)

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

## Writes During Your Transactions

LogScope writes on your application's database connection, so a log written inside one of your transactions would sit in the log table, uncommitted, until you commit. That has three costs: the Clear button and `logscope:prune` wait on your request (and a Clear covering two levels or channels can deadlock with it, which your transaction loses); the log rows roll back with the transaction, losing exactly the logs that explain the rollback; and every write pays for a savepoint.

So LogScope doesn't write there. A log written while a transaction is open is held in memory and written as soon as that transaction ends — committed or rolled back, keeping the time it was logged. Reading logs never affects your app.

```env
# Turn it off to write inside your transactions as before.
LOGSCOPE_DEFER_IN_TRANSACTIONS=true
```

This covers the writes that land on your connection: `sync`, channel capture, and `queue` on the `sync` or `database` driver. `batch` already worked this way, and a broker queue (redis, sqs) is left alone — your transaction never touched it, so holding the entry in memory would only cost the dispatch its durability. Entries held during a transaction are written directly when it ends rather than dispatched; the job would have written the same rows on the same connection a moment later.

One bound: a single transaction that logs more than ten times `LOGSCOPE_BATCH_MAX_ENTRIES` (5,000 by default) would grow that buffer until the process ran out of memory, so at that point LogScope writes inside your transaction after all. Each write is isolated in a savepoint, so a failure can't take your transaction with it.

## Testing

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

## Retention

```env
LOGSCOPE_RETENTION_ENABLED=true
LOGSCOPE_RETENTION_DAYS=30

# Optional: let LogScope register the prune schedule for you (off by default).
# Leave off if you already wire `logscope:prune` in your own console kernel.
LOGSCOPE_RETENTION_AUTO_SCHEDULE=false
LOGSCOPE_RETENTION_SCHEDULE_AT=03:00
```

> **Note:** Retention requires either flipping `LOGSCOPE_RETENTION_AUTO_SCHEDULE=true` or scheduling `logscope:prune` yourself - see [Schedule Pruning](production.md#schedule-pruning).

## Features

```env
LOGSCOPE_FEATURE_STATUS=true       # Enable status workflow
LOGSCOPE_FEATURE_NOTES=true        # Add notes to logs
```

## Noise Reduction

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

## Cache TTL

```env
# How long (in seconds) to cache stats and filter options (levels, channels, etc.)
# Set to 0 to disable caching
LOGSCOPE_CACHE_TTL=60
```

## JSON Viewer

Configure collapsible JSON behavior in `config/logscope.php`:

```php
'json_viewer' => [
    'collapse_threshold' => 5,  // Auto-collapse arrays/objects larger than this
    'auto_collapse_keys' => ['trace', 'stack_trace', 'stacktrace', 'backtrace'],
],
```

## Routes

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

## Error Handling

LogScope handles errors gracefully with toast notifications:

| Error | Behavior |
|-------|----------|
| 401/419 (Session expired) | Toast + redirect to `unauthenticated_redirect` |
| 403 (Access denied) | Toast + redirect to `forbidden_redirect` |
| 429 (Rate limited) | Toast only (retry later) |
| 500+ (Server error) | Toast only (temporary issue) |
| Network error | Toast only (check connection) |

> **Note:** Redirect URLs can be relative paths (`/login`) or absolute URLs (`https://auth.example.com/login`).

## Theme

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

## Context Sanitization

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

## Request Headers

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

## Publishing Assets

```bash
php artisan vendor:publish --tag=logscope-config
php artisan vendor:publish --tag=logscope-migrations
php artisan vendor:publish --tag=logscope-views
```

## All Environment Variables

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

[← Back to README](../README.md)
