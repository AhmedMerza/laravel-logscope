# Changelog

All notable changes to LogScope are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.5.6] — 2026-04-30

### Added

- **In-UI failure banner** (#15) — `WriteFailureLogger::report()` now writes a cache breadcrumb (count, first/last timestamps, latest exception class+message+source) in addition to `error_log()`. The LogScope index view shows a red dismissible banner so operators see the package's own write failures without grepping server logs. Configurable via `logscope.failure_banner` (enabled + optional `ttl_seconds`; default forever-until-dismissed). Cache writes are best-effort; `error_log()` remains the canonical signal.
- **Eager flush callbacks + Octane RequestTerminated listener** (#14) — moves terminate-callback registration from `LogBuffer::add()` (lazy) to `LogScopeServiceProvider::register()` (eager) so we're ahead of most user-provider terminate callbacks. Wraps our flush in try/catch so an internal failure doesn't break the terminate chain. Adds a `Laravel\Octane\Events\RequestTerminated` listener as an independent flush trigger that survives even when Laravel's terminate chain is broken by an earlier throwing callback. Octane is an optional peer (registered via `class_exists` guard).

### Changed

- `WriteFailureLogger` truncates cached error messages at 500 chars before storing — keeps cache writes cheap and the index page payload bounded.

### Fixed

- **Quick-filter dates wrong for non-UTC timezones** (#16) — `parseRelativeTime()` formatted "now minus N hours" via `toISOString()`, which always returns UTC. For users east of UTC (Saudi, UAE, India, etc.) the `from` field showed a time that lagged behind their wall clock by their offset. Now formats relative-time strings using local clock components. Backend already accepted a `timezone` URL param and parsed values correctly — only the JS formatting was wrong.

## [1.5.5] — 2026-04-30

### Summary

A reliability-focused release. After auditing the codebase for paths where logs could be silently dropped, six distinct issues were found and fixed. Each landed as its own PR (#8–#13) with failing-test-first verification.

### Fixed

- **Substring filters dropped real user logs.** `isInternalLog` matched the substring "LogScope" in any message; `ignore.deprecations` matched "is deprecated" anywhere. Both silently swallowed legitimate business logs (e.g. `Log::error('LogScope client returned 503')`, `Log::warning('Account 42 is deprecated for billing')`). `isInternalLog` now only checks the structured `_logscope_internal` context key. `ignore.deprecations` scopes by **channel** — see `logscope.ignore.deprecation_channels` (default `['deprecations']`). (#8)
- **`MessageLogged` listener registered too late.** Logs fired during another provider's `boot()` were lost if that provider booted before LogScope (~position 16 in a typical Laravel 12 app — every framework provider boots before us). The listener now registers in `register()`, before any provider's `boot()` runs. (#9)
- **Write failures were hidden behind `APP_DEBUG`.** In production with debug off, a transient DB outage caused silent total log loss. `error_log()` now always fires on write failures, regardless of `APP_DEBUG`. New `WriteFailureLogger` dedupes per-process — first occurrence + a summary every 100th — so a sustained outage doesn't dump thousands of identical lines. The buffer-discard path also surfaces a count when the container is gone at flush time. (#10)
- **Re-entrant `MessageLogged` recursion.** A `LogEntry` observer that itself logs, or a query listener with `Log::debug`, could trigger infinite recursion or runaway entry counts. New `WriteGuard` static counter wraps every write path (sync, queue, batch, channel handler). The listener checks it at entry and skips re-entrant logs. Also wired into `WriteLogEntry::handle()` so queue-mode worker writes are protected. (#11)
- **Trace ID missing on early-pipeline middleware errors.** `CaptureRequestContext` was pushed to the END of the global stack — if CORS, Sanctum, or rate limiting threw, the resulting log lacked `trace_id`/`ip_address`/`url`. Now uses `prependMiddleware` so context is set before any other middleware can fail. Defensive guards skip registration in console-only kernels. (#12)
- **Channel attribution leaked across `Log::build()` and Octane.** Runtime channels (no processor) inherited the previous log's channel from static state. In long-running workers, state survived across requests. New `$isFresh` flag distinguishes "just set by a processor" from "stale state". `consumeLastChannel()` returns null unless a processor invocation has happened since the last consume. Octane users get a `RequestReceived` listener that clears the slot per-request as defense in depth. (#13)

### Added

- `logscope.ignore.deprecation_channels` config (default `['deprecations']`) — list of channel names treated as deprecation channels for the ignore filter.
- `LogScope\Services\WriteFailureLogger` — centralized error_log emission with per-process dedupe.
- `LogScope\Services\WriteGuard` — re-entrancy counter used by all write paths.
- `ChannelContextProcessor::consumeLastChannel()` — read+clear the last channel in one operation.

### Changed

- `LogCapture::handleLogEvent` consumes the channel at the very top, before any early-return path.
- `LogScopeServiceProvider::register()` now attaches the `MessageLogged` listener (was in `boot()`).
- `LogScopeServiceProvider::registerMiddleware()` uses `prependMiddleware` (was `pushMiddleware`).
- `WriteLogEntry::handle()` runs inside `WriteGuard::during()` for queue-mode coverage.

### Deprecated

- `ChannelContextProcessor::getLastChannel()` — kept with original semantics (returns raw value), but new code should use `consumeLastChannel()`. Removal scheduled for 2.0.

### ⚠️ Behavior changes

- **`ignore.deprecations`** is now channel-scoped, not message-scoped. Most users on default Laravel configs are unaffected. If your app emits PHP deprecations through a custom-named channel, add it to `logscope.ignore.deprecation_channels`. Logs containing "is deprecated" outside the deprecations channel are now captured.
- **Logs containing "LogScope"** are now captured. Use `Log::*('msg', ['_logscope_internal' => true])` to suppress specific lines.
- **`error_log()` will be louder during DB outages** — first occurrence + every 100th. Production users should already have log rotation; if not, the noise is your signal.
- **`trace_id` now appears on early-pipeline logs** (CORS, auth, etc.). If your downstream tooling treated a null `trace_id` as a "pre-LogScope" marker, adjust accordingly.

---

## [1.5.4] — 2026-04-30

### Fixed

- **Wrong source location for argument-validation errors.** When you called `new SomeClass()` with the wrong number or type of arguments, PHP threw an `ArgumentCountError` (or `TypeError`). For these errors PHP's `$e->getFile()`/`$e->getLine()` point at the **callee's declaration** (the constructor's signature), not the **caller** (where `new` was actually written). The `source` column showed e.g. `app/Services/UserService.php:11` instead of `app/Http/Controllers/UserController.php:42`.

  LogScope now detects argument-validation errors (`ArgumentCountError` and `TypeError` whose message contains `"Argument #"`) and uses the first stack-trace frame as the source. Other exceptions — including return-type `TypeError` and user-thrown `throw new TypeError(...)` — keep `getFile()`/`getLine()`.

  The exception detail panel and the stored stack-trace slice are aligned with the same logic. The trace slice now strips frame `args` to avoid leaking sensitive arguments (passwords, tokens) that might have been passed into the failing call.

---

## [1.5.3] — 2026-04-29

### Performance

#### Filter queries on large tables (1500–3600× faster)

On installs with hundreds of thousands of rows, filtering by `trace_id`, `user_id`, or `ip_address` was taking 1–14 seconds because the existing `LIKE '%value%'` predicate prevented the BTREE indexes from being used.

| Scenario (500K rows) | Before | After | Improvement |
|----------------------|--------|-------|-------------|
| Filter by full UUID (MariaDB) | 1545 ms | 0.4 ms | **3500× faster** |
| Filter by full UUID (MySQL) | 1268 ms | 0.5 ms | **2600× faster** |
| Filter by full IPv4 (MariaDB) | 1548 ms | 0.4 ms | **3600× faster** |
| Capped count + trace_id | 700 ms | 0.4 ms | **1600× faster** |

#### Zero-RTT detail open

The list response now includes the full `message` and `context` for each row, so clicking a log opens the detail panel instantly — no second request.

| | Before | After |
|---|---|---|
| List response (50 rows, ~750B avg msg) | 72 KB | 144 KB |
| Server time per list | 7 ms | 8 ms |
| RTT per detail click | ~RTT (often 100–300 ms) | **0** |

### Changed

- `scopeTraceId` / `scopeUserId` / `scopeIpAddress` now detect full values (canonical UUIDs, numeric user_ids, valid IPv4/IPv6) and use exact `=` matches that hit the index. Partial input uses prefix `LIKE 'x%'`.
- New composite index `(ip_address, occurred_at)` mirrors the existing `(trace_id, occurred_at)` and `(user_id, occurred_at)` indexes. Auto-applies via `php artisan migrate`.

### ⚠️ Behavior changes

- **Suffix-substring search no longer works for `trace_id` / `user_id` / `ip_address`.** `abc` will no longer find a row whose trace_id ends in `abc`. In practice users paste complete IDs from logs to investigate; partial input now requires a known prefix.
- Set `LOGSCOPE_EAGER_LOAD_DETAIL=false` (or `logscope.pagination.eager_load_detail`) to opt out of the eager-loaded detail payload — useful if your install logs very large messages where the per-page payload could balloon.

---

## [1.4.2] — earlier

### Added

- Full responsive layout for mobile, tablet, and desktop. Sidebar, log table, detail panel, and filters all adapt to screen size.
- Pivot filtering from the detail panel (trace ID / user ID / IP).

### Fixed

- Filter race condition resolved with debounce + AbortController.
- Several stability fixes across v1.4.x.

---

## Earlier versions

For releases before v1.4.2, see the [tag list on GitHub](https://github.com/AhmedMerza/laravel-logscope/tags) or run `git log --oneline v0.1.0..v1.4.0` for commit-level history.

[1.5.6]: https://github.com/AhmedMerza/laravel-logscope/releases/tag/v1.5.6
[1.5.5]: https://github.com/AhmedMerza/laravel-logscope/releases/tag/v1.5.5
[1.5.4]: https://github.com/AhmedMerza/laravel-logscope/releases/tag/v1.5.4
[1.5.3]: https://github.com/AhmedMerza/laravel-logscope/releases/tag/v1.5.3
[1.4.2]: https://github.com/AhmedMerza/laravel-logscope/releases/tag/v1.4.2
