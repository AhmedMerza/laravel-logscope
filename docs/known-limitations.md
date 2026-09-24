# Known Limitations

Logs that reach LogScope's capture path are stored; these are the cases that structurally don't, and what to do about each.

LogScope captures everything that flows through Laravel's logger (`Illuminate\Log\Logger`). A few categories of logs structurally bypass that path and **cannot be auto-captured**. These are PHP / framework limitations, not bugs we can fix from a Composer package.

## 1. PHP's native `error_log()` is not interceptable

`error_log("...")` is a built-in PHP function that writes directly to the destination configured in `php.ini`'s `error_log` directive (file, syslog, or stderr). It calls into PHP's C-level logging, never invokes `set_error_handler`, and there's no userland API to redirect it. Workarounds (the [`uopz`](https://www.php.net/manual/en/book.uopz.php) extension, php.ini overrides, stderr capture) all live outside the PHP application — out of scope for a package.

If your app or a dependency calls `error_log()`, those messages land in your php-fpm/server log, **not** in LogScope. Search both places when investigating.

## 2. `trigger_error(E_USER_*)` *should* work, but depends on Laravel's exception handler

PHP routes `trigger_error()` through the registered `set_error_handler`, and Laravel's `HandleExceptions` bootstrapper installs one that converts these to `ErrorException` and reports them — which then fires `MessageLogged` and reaches LogScope. So in normal HTTP/CLI flow this works.

**It can break in specific contexts** where Laravel's exception handler is bypassed:
- `php artisan tinker` (skips reporting via `runningUnitTests()`-style guards in some versions)
- Custom `set_error_handler` calls in user code that don't chain to the previous handler
- `error_reporting` being lowered to exclude `E_USER_*` levels

If something seems missing here, check `error_reporting()` and that `\Illuminate\Foundation\Bootstrap\HandleExceptions::class` is in your bootstrap chain (it is by default).

## 3. Direct Monolog instances bypass capture

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

## 4. SIGKILL / segfault / E_PARSE in batch write mode loses the buffered batch

Batch mode (`LOGSCOPE_WRITE_MODE=batch`, default) accumulates logs during the request and flushes them on `app->terminating()` or PHP shutdown. Both safety nets require a graceful shutdown:

- `kill -9` (SIGKILL): cannot be caught by PHP — buffer is gone
- OOM kill: same
- `E_PARSE` / `E_COMPILE_ERROR`: PHP can't run user code at shutdown for these

What's at risk is what's still in the buffer. The buffer is also written once it holds 500 logs or its oldest log is 10 seconds old (see [Write Mode](configuration.md#write-mode-performance)), and queue workers flush after every job, so a long-running artisan command or worker loses at most that much, not everything it logged since it started. Inside an open database transaction the limits don't apply — the buffer waits for the transaction to end instead — so a crash during a long transaction can lose more, up to the 5,000-entry cap at which LogScope writes anyway.

**If low-loss is critical**, set `LOGSCOPE_WRITE_MODE=sync` to write every log immediately. Cost: each `Log::*()` call adds a synchronous DB round-trip. Logs written inside one of your own transactions still wait for it to end (see [Writes During Your Transactions](configuration.md#writes-during-your-transactions)) unless you also set `LOGSCOPE_DEFER_IN_TRANSACTIONS=false`.

## 5. `null_channel` filter — read before enabling

`LOGSCOPE_IGNORE_NULL_CHANNEL=true` drops logs that LogScope can't attribute to a named channel. **This includes Laravel's own framework-level error reporter in some configurations**, which means enabling this flag may silently drop unhandled exceptions. Only enable if you know exactly which no-channel logs flow through your app.

---

[← Back to README](../README.md)
