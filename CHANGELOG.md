# Changelog

All notable changes to LogScope are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.7.1] — 2026-05-20

### Fixed

- **Search NOT toggle is now a true boolean complement** (#24). Previously, when the search input contained any `:` (including a trailing colon like `failed:`), the controller silently flipped into structured-tokenize mode, AND'd the resulting tokens, and propagated the UI's exclude flag onto each token individually. The result: `include` returned logs containing **all** tokens; `exclude` returned logs containing **none** of them. Logs containing some-but-not-all tokens fell through both filters and became invisible. Three changes: (1) the colon-trigger now requires either a quoted phrase, a per-token `-` exclusion, or a `field:value` where `field` is a known searchable column — a stray `:` no longer fragments the input; (2) the UI's exclude flag wraps the parsed AND'd expression in a single SQL `NOT (...)` instead of negating each term; (3) `applyLikeSearch`/`applyRegexSearch` now COALESCE nullable columns to `''` so the wrapped `NOT` doesn't hit SQL three-valued-logic propagation on rows where searchable columns are NULL. Invariant guaranteed by tests: `include_count + exclude_count == total_count` for any input.

## [1.7.0] — 2026-05-13

### Added

- **Write-failure fallback rows in `log_entries`.** When the normal write path throws (e.g. context sanitization touches a class the autoloader can't resolve, or a bulk insert blows up mid-flush), LogScope now writes a minimal marker row to `log_entries` so the failure shows up in the UI — not just in php-fpm's `error_log`. The marker row preserves the original `level`/`message`/`channel`/`trace_id` (re-read from request context if buildLogData itself threw, so correlation survives) and replaces `context` with a `_logscope_write_failure` block carrying the exception class, message, call-site label, and an occurrence counter. Per-process dedupe with a heartbeat every 100th occurrence keeps a sustained outage from flooding the table while still giving the operator a "still happening" signal. Wired into all three write modes (sync via `LogCapture`, queue via `WriteLogEntry::handle`, batch via `LogBuffer::performFlush`). Octane request-boundary listener now also resets `FallbackWriter`'s static map so long-running workers don't strand their dedupe state across requests. New config: `logscope.write_failure.persist_fallback` (default `true`, env `LOGSCOPE_WRITE_FAILURE_PERSIST_FALLBACK`).

### Changed

- **Queue worker retry behavior.** `WriteLogEntry::handle` now classifies failures: SQLSTATE classes `08*` (connection) and `40*` (deadlock/serialization) re-throw so Laravel's queue retries kick in; everything else (autoload errors, schema mismatches, malformed data) is treated as persistent — recorded via the fallback row and `WriteFailureLogger`, then swallowed so a poisoned entry can't loop the worker forever. Previously every failure surfaced as a job-level exception, triggering unbounded retries on bugs that retries couldn't fix.
- **`LogBuffer::performFlush` error isolation improved per-chunk.** `LogEntry::prepareData()` is now called inside the per-chunk try block (previously it ran once over the entire buffer up front). A single malformed entry now loses only its 500-row chunk, not the whole buffer. No config change required — strictly fewer entries lost on the failure path.

## [1.6.1] — 2026-05-13

### Changed

- **Watchtower integration** (formerly LogScope Guard) — README install command, the JS `guard` flag, and the `@includeIf` partial now point at `ahmedmerza/laravel-watchtower` and the `watchtower::` view namespace / `watchtower.*` config namespace. The companion package was renamed; LogScope users following the README will install the new package, and the in-detail-panel Block-IP button activates against the new namespaces. **Breaking only for users on the unreleased pre-rename Guard package** — anyone who already shipped the integration on top of `ahmedmerza/logscope-guard` (≤ v0.1.0) will see the Block-IP button stop appearing until they upgrade Guard to Watchtower.

### Fixed

- **Noisy "Discarded N buffered log entries" warning + silent log loss during test runs** (#22) — when a consumer's project default was `batch` (or the bare package default), unit tests that never dispatched a request never triggered `Application::terminate()`. Buffered entries accumulated across tests, then got discarded at PHP shutdown when the container was gone — producing both a stderr warning per test run AND silently losing the captured logs. Two-layer fix: (1) the service provider now forces `write_mode = sync` whenever the app environment is `testing`, mirroring Laravel's own `mail=array` / `queue=sync` / `cache=array` test defaults; users who specifically want to exercise batch behavior can still opt back in via `config(['logscope.write_mode' => 'batch'])` in their test's `setUp()`. (2) `LogBuffer` now caches `app->environment('testing')` at `add()` time (while the container is alive) and suppresses the shutdown-discard `error_log` notify when that flag is set — production keeps the loud notify so real data loss stays visible. README gains a "Testing" subsection documenting the override and opt-in pattern.

## [1.6.0] — 2026-05-03

### Added

- **`logscope:doctor` command.** Runs a health check across the package's most common configuration footguns: missing migration, capture mode vs. channel wiring, write mode + queue connection, request-context middleware registration, retention policy, authorization resolution path (callback / gate / local-only fallback), Octane peer integration, built-asset presence, and the cached failure breadcrumb. Each check is reported as `PASS`/`WARN`/`FAIL` with an actionable hint. Returns a non-zero exit code on any FAIL, so it's CI-friendly. Supports `--json` for machine-readable output.
- **`logscope:test` command.** Emits a uniquely-tagged sample log through the configured capture path (`Log::info` for `all` mode, `Log::channel('logscope')->info` for `channel` mode), temporarily forces sync writes for the duration of the test, then verifies the entry landed in `log_entries`. Cleans up on success unless `--keep` is passed. Useful as a smoke test after install or whenever you change capture/write mode.
- **Opt-in scheduler registration for `logscope:prune`.** New `logscope.retention.auto_schedule` (default `false`) + `logscope.retention.schedule_at` (default `'03:00'`) config keys. When enabled, the package's service provider registers `logscope:prune` on Laravel's scheduler via `callAfterResolving(Schedule::class, ...)` and uses `->onOneServer()` for safe multi-server deploys. Default off so existing users who already wire prune in their own console kernel are unaffected — no duplicate runs.

## [1.5.9] — 2026-05-02

### Changed

- **Status keyboard shortcuts (default `O`/`I`/`R`/`X`) are now context-aware.** With a log detail panel open, pressing a status shortcut now changes THAT log's status and auto-advances to the next log — the rapid keyboard-triage flow. With no detail open, the legacy behavior is preserved (filter the list by that status). No config changes; same shortcut, smarter behavior.
- **Status changes now use optimistic UI.** The list updates immediately (row removed if the new status is hidden by the current filter, badge updated otherwise); the detail panel auto-advances; no loading spinner. The API call runs in the background. On failure the row is restored (by ULID ordering, robust against intervening mutations) and an error toast appears. Loading is still shown for filter changes / search / pagination.
- A no-op guard skips the API call when the log is already in the target status.
- Shortcuts help dialog (`?`) now explains the dual meaning under "Status".

## [1.5.8] — 2026-05-02

### Fixed

- **`captureContext` callback failures no longer lose the underlying log** (#18) — when a user-registered `LogScope::captureContext()` callback threw (e.g. accessing `currentAccessToken()->id` on a Sanctum `TransientToken` which has no `id` property), the throw cascaded into our log-write try/catch. Result: the original log was silently dropped AND the failure was misleadingly reported as a "write failure" in the in-UI banner. Now: callback throws are isolated. The original log lands with `_logscope_callback_error: "<class>: <message>"` added to the context as a marker. The callback failure is surfaced under its own source label `captureContext-callback` in the failure banner so it's distinguishable from real write failures. README's example was updated to type-check Sanctum tokens.

## [1.5.7] — 2026-04-30

### Fixed

- **Deprecations filter regression in v1.5.5–v1.5.6** (#17) — channel-scoped filter from PR #8 didn't actually catch Laravel's PHP runtime deprecations. Root cause: Laravel synthesizes the `deprecations` channel lazily on first deprecation, AFTER our channel processor registration runs at boot — so the tap was never attached and `$channel` arrived as null in the listener. Re-added a narrow message-pattern fallback that matches Laravel's wrapped format (`"… is deprecated in <file> on line <N>"`). Two-layer match: channel-name first, message-pattern fallback. Avoids the original PR #8 false positives because the regex requires the `on line <N>` suffix that PHP runtime deprecations always have but business logs don't.

  **Affected versions:** v1.5.5, v1.5.6. Upgrade to v1.5.7 to restore the filter behavior.

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

[1.5.9]: https://github.com/AhmedMerza/laravel-logscope/releases/tag/v1.5.9
[1.5.8]: https://github.com/AhmedMerza/laravel-logscope/releases/tag/v1.5.8
[1.5.7]: https://github.com/AhmedMerza/laravel-logscope/releases/tag/v1.5.7
[1.5.6]: https://github.com/AhmedMerza/laravel-logscope/releases/tag/v1.5.6
[1.5.5]: https://github.com/AhmedMerza/laravel-logscope/releases/tag/v1.5.5
[1.5.4]: https://github.com/AhmedMerza/laravel-logscope/releases/tag/v1.5.4
[1.5.3]: https://github.com/AhmedMerza/laravel-logscope/releases/tag/v1.5.3
[1.4.2]: https://github.com/AhmedMerza/laravel-logscope/releases/tag/v1.4.2
