# Changelog

All notable changes to LogScope are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- **`sensitive_keys` now redacts the arrays you log yourself, not only a logged `Request` object** (#76). `Log::error('payment failed', ['request' => $request->all()])` stored the password, card number and API token in clear, while the same call passing `$request` itself redacted them — and the unsafe form is the common one. Redaction was reached from exactly one place, the `Request` expansion, and nothing on the path a plain array takes ever called it; `sanitize()` also passed only the *value* down, never the key, so a top-level `['password' => …]` could not have matched even in principle. Redaction now happens where the keys are, which covers arrays you log yourself at any depth, objects expanded into arrays (a DTO's or model's `password` property included) and the `Request` expansion alike. The defaults are unchanged — `password`, `password_confirmation`, `secret`, `token`, `api_key`, `apikey`, `authorization`, `credit_card`, `card_number`, `cvv`, `ssn` — and `redact_sensitive => false` still turns all of it off. Existing rows are not rewritten: whatever was stored in clear stays in clear. Nothing that was redacted before this release stops being redacted; see **Changed** below for how matching works and for the new exclusion list.

- **The Monolog handler redacts too, instead of keeping its own unredacted copy of the sanitizer** (#77). `LogScopeHandler` carried a full second implementation of the context walk with no redaction anywhere in it, so everything the listener path redacted was stored in clear on this one: a logged `['password' => …]`, and a logged `Request`, which fell through to Symfony's `__toString()` and stored the raw HTTP dump — `Authorization` header, `Cookie`, and the form body. This was not a rare path. It is the whole of `capture => channel`, it is the `pushHandler(new LogScopeHandler(…))` usage the README documents, and it also wins under the default `capture => all` whenever the `logscope` channel sits in a stack, because `LogCapture` defers to it via `didHandleCurrentLog()`. The handler now delegates to the shared `ContextSanitizer`, so there is one place that decides what gets redacted and the duplicate walk is gone. Four behaviour changes come with it for that path: a logged `Request` is now expanded into the structured `_type: request` shape rather than stringified; the handler's own per-string 10,000-character cap is gone (whole-context truncation at `limits.truncate_at` still applies); the nesting depth limit drops from 10 to 5, so a structure nested deeper now ends in `[Max depth exceeded]`; and context keys beginning with `__` are skipped, where before only `_logscope`-prefixed ones were. **Breaking only if you subclassed `LogScopeHandler`** and overrode or called its `sanitizeArray()`, `sanitizeObject()` or `sanitizeTrace()` — those `protected` methods are gone. Subclassing was never documented as an extension point; the README shows direct construction only. Shipping unredacted since v0.1.0. Existing rows are not rewritten.

- **`logscope:import` works at all** (#77). Every run threw `SQLSTATE[HY000]: table log_entries has no column named environment`, so no entry was ever imported. Migration `2026_01_24_000001` drops that column, but the command still wrote it into each insert. Found by the first test ever written for the command, which is also why it went unnoticed: nothing in the suite ran it. Imported entries are now redacted like any other, which is the other half of the same fix — before, the command both crashed and, had it not, would have stored historic context unsanitized.

### Changed

- **Sensitive keys are matched per word, ignoring case and separators** (#76, #77). A key is split into words (`card_number`, `card-number`, `card.number` and `cardNumber` all give `card` + `number`), and a fragment matches either inside a single word or, for a multi-word entry, across the joined key. So `token` catches `access_token`, `access_tokens` and `APIToken`, and `card_number` catches `cardNumber` — which no previous release redacted. Confining a one-word fragment to a single word is what stops `class_name` from reading as `classname` and matching `ssn`, or `cv_video` matching `cvv`.

  A key longer than 256 characters is redacted without being examined. Key names come from request bodies, so their length is client-chosen; truncating before matching would let a long prefix hide the fragment, and no real field name is anywhere near that.

- **`sensitive_keys_except` is new: keys that look sensitive but are not** (#77). Matching is deliberately broad, because a missed secret is invisible while an over-redacted field is obvious and one config line away from fixed. The exclusion list is that config line, and it ships with the LLM-era fields `token` would otherwise catch: `prompt_tokens`, `completion_tokens`, `total_tokens`, `token_count`, `tokenizer`. **Your entries add to those defaults** rather than replacing them, unlike `sensitive_keys` — an app naming one of its own fields should not have to re-list LogScope's. Reach for this rather than narrowing `sensitive_keys`: narrowing the key list is what silently stops redacting something.

  Two earlier cuts of this release were reverted before shipping, and the current design is shaped by both. Matching whole words only (so `token` would miss `prompt_tokens`) also missed `access_tokens`, `card_numbers`, `passwords` and `APIToken`; word matching cannot separate `access_tokens` from `prompt_tokens`, because they are the same shape. Matching a fragment across the whole key with separators stripped then glued unrelated words together, so `class_name` collapsed to `classname` and matched `ssn`. Matching per word, with exclusions named explicitly, is what satisfies both.

  Exclusions **remove** the words they cover rather than cancelling the key. An exclusion that merely vetoed a match meant `prompt_tokens_password` contained both an excluded and a sensitive fragment and was stored in the clear; now `prompt_tokens` takes its two words with it and `password` is still there to match. Entries shorter than three characters are ignored, since an exclusion removes whole words and `e` would remove almost all of them.

- **A sensitive field in a logged `Request` body now reads `[REDACTED]` instead of vanishing** (#77). `sanitizeRequest()` dropped exact `sensitive_keys` matches from `input` with `except()` before redacting the rest, so a field named exactly `password` disappeared from the entry while a nested one showed `[REDACTED]`, and a reader could not tell an absent field from one the client never sent.

- **Blank `sensitive_keys` / `sensitive_keys_except` entries are ignored, and an all-blank key list falls back to the defaults** (#77). An empty or whitespace-only entry — one stray comma in an env-driven list — used to match every key, redacting the whole context. Dropping those entries fixes that, but dropping *all* of them leaves a matcher with nothing to match, which redacts nothing at all: `'sensitive_keys' => explode(',', env('LOGSCOPE_SENSITIVE_KEYS', ''))` yields `['']` when the variable is unset, and would have switched redaction off silently. A key list that filters down to nothing now falls back to the shipped defaults, so a misconfiguration is loud rather than silent.

## [2.0.0] — 2026-09-19

**Upgrading from 1.x:** run `php artisan migrate` — this release adds the `headers` column and converts `user_id`.

One breaking change affects ordinary use: the API now returns `user_id` as a string, so a consumer comparing it to a number stops matching. Four more are listed under **Changed**, each narrow — two fail loudly at boot if you bound your own `ContextSanitizerInterface`, one affects code that writes `LogEntry` rows directly, and one drops Laravel 10, which never worked.

### Added

- **Entries can now carry the request's headers** (#30). Run `php artisan migrate` for the new nullable `headers` column. Capture is allowlist-only and off-limits to secrets: `content-type`, `accept`, `referer`, `x-forwarded-for`, `x-request-id` and `origin` are captured by default, anything matching `context.sensitive_headers` is stored as `[REDACTED]` even if you allowlist it explicitly, and values over `context.headers.max_value_length` (500) are cut and end in `…[truncated]`. There is deliberately no "capture everything" mode — unknown vendor auth headers, `php-auth-pw` and table growth all live there. Headers are stored per row, so a request that logs 20 lines stores them 20 times; with the default allowlist that is a few hundred bytes a row. CLI-originated logs store `null`. The detail panel shows them in their own section above Context, each row with a button that pivots the list to a `headers:` search, and `headers:<value>` matches the stored JSON — names as well as values, so `headers:x-request-id` finds every row that carried that header. Plain-text search does not scan the column. Set `LOGSCOPE_CAPTURE_HEADERS=false` to turn the whole thing off; `logscope:doctor` reports whether it is on and what is allowlisted.

### Changed

- **`ContextSanitizerInterface` gained a `captureHeaders()` method** (#30). **Breaking only if you bound your own implementation** of `LogScope\Contracts\ContextSanitizerInterface` in the container — that class must now implement `captureHeaders(array $headers): ?array` or PHP will fatal on resolution. The interface is not documented as an extension point and the package's own `ContextSanitizer` is what ships, so almost no one is affected; if you are, the shipped implementation at `src/Services/ContextSanitizer.php` is the reference.

- **`ContextSanitizerInterface` gained a `toValidUtf8Deep()` method** (#63). **Breaking only if you bound your own implementation** of `LogScope\Contracts\ContextSanitizerInterface` in the container — that class must now implement `toValidUtf8Deep(array $bag): array` or PHP will fatal on resolution. Same situation as `captureHeaders()` above, and the shipped implementation at `src/Services/ContextSanitizer.php` is again the reference.

- **`LogEntry::$context` is now an accessor/mutator pair rather than an `'array'` cast** (#67). **Breaking only if you write `LogEntry` rows yourself** and assign something other than an array or `null` to `context` — a string or an `Arrayable` now raises a `TypeError` where the cast previously encoded it (a string became the double-encoded `"\"…\""`). Reading is unchanged: `$entry->context` is still an array, and the stored bytes are byte-identical for valid input. The cast had to go because it encodes with a bare `json_encode()`, which is the bug above.

- **LogScope now requires Laravel 11 or later** (#52). `composer.json` allowed Laravel 10, but LogScope has never worked on it. Since v0.1.0 it has used Laravel's `Context` facade, which Laravel 11 added. On Laravel 10 every web request failed with `Class "Illuminate\Support\Facades\Context" not found` (unless `LOGSCOPE_MIDDLEWARE_ENABLED=false`), and log entries weren't saved. Composer no longer installs LogScope on Laravel 10. Nothing changes on Laravel 11 or 12.

- **`user_id` is now a string column, and the API returns it as a string** (#26). Run `php artisan migrate`. The migration keeps logging running on large tables: it swaps in the new column in one statement, builds its indexes online, copies existing ids across in batches of 1,000, then drops the old column. On 1M rows it took about two minutes on MySQL 8 and Postgres 16, and no log insert waited longer than 102 ms (a plain type change blocked inserts for 48 s). Older logs show no user id until the copy reaches them. If a run is interrupted, running `migrate` again resumes it. If you published the migrations with the `logscope-migrations` tag, publish again to get the new one. Integer ids keep working until the migration runs.

### Fixed

- **A malformed byte in a log call's context no longer wipes the entry's `context` column** (#67). `Log::warning('rejected request', ['agent' => $request->userAgent()])` — or any log call carrying raw client bytes, including one that logs a `Request` object — lost its **entire** context whenever the client had sent a malformed header, which scanners and broken HTTP clients do routinely. `json_encode()` returns `false` on a byte that isn't valid UTF-8; that `false` reached the preview builder as a `TypeError`, the write failed, and the row was rewritten with a `_logscope_write_failure` marker — so the one log line written specifically to record a bad request was the line that arrived with no detail. Bad bytes are now substituted with `U+FFFD` rather than the write failing, so the readable part of the value survives (`Mozilla/5.0 …`) and the stored `context` is valid JSON for this class of failure. It is not a guarantee for every input: `json_encode()` still fails on a value it cannot represent at all, such as an `INF`/`NAN` float, which stores an empty string instead (#69). On MySQL and Postgres the old behaviour could also fail the insert outright, where `''` is not valid JSON. Header **names** are covered as well as values: a logged `Request` puts them into `context` as JSON object keys, and unlike captured headers (#30) they pass through no allowlist, so they are attacker-controlled. This is the third surface of the root cause #30 fixed for the `headers` column and #63 for the request-context bag, and the most commonly reached of the three, because it fires on ordinary application logging rather than on request capture. Nothing in the host application was ever affected — the request still returned 200, and the cost was LogScope's own data. `sync` and `batch` write modes and `logscope:import` now share one encoder, so a fourth call site can't drift again. `queue` write mode needed a second guard rather than the same one, and was broken in a different way: Laravel encodes the whole job payload inside `dispatch()`, long before the entry reaches any storage encoder, so a malformed byte threw `InvalidPayloadException` in the caller and no job was queued at all. The job's data is now coerced to valid UTF-8 as it is constructed. Shipping since v0.1.0. Nothing to run, and existing rows are unchanged.

- **A malformed `User-Agent` no longer breaks the application's own queue dispatch** (#63). Anyone can send a `User-Agent` containing a byte that isn't valid UTF-8, and scanners and broken HTTP clients do it routinely. `CaptureRequestContext` stored it raw in Laravel's `Context` bag, and Laravel serializes that whole bag into every job queued during the request — the feature that lets a job's logs inherit the originating request's `trace_id`. `Queue::createPayload()` encodes it with `json_encode($value, JSON_UNESCAPED_UNICODE)`, which returns `false` on a malformed sequence, so for the rest of that request **the application's own `SomeJob::dispatch()` threw `Illuminate\Queue\InvalidPayloadException`** — in the app's queue code, with nothing in the trace to say a logging middleware caused it. LogScope's own `queue` write mode failed the same way, degrading the entry to a `_logscope_write_failure` marker that lost its real context. Bad bytes are now substituted at capture rather than the value being dropped, so the readable part of the agent string is kept and the stored `user_agent` is always valid UTF-8. This was the same bug #30 fixed for captured headers, one line above them, so the coercion now covers the whole request-context bag the middleware builds — `trace_id`, `ip_address`, `http_method`, `url`, the captured headers, and anything added to it later — rather than one field at a time. (That bag is what Laravel serializes into queued jobs; it is not the log entry's own `context` column.) Shipping since v0.1.0. Nothing to run, and existing rows are unchanged.

- **Behind a load balancer, `ip_address` is now the client's address instead of the proxy's** (#54). `CaptureRequestContext` was prepended to the global middleware stack, so it read `$request->ip()` before `TrustProxies` had handed Symfony the trusted-proxy list. `X-Forwarded-For` was ignored and every log entry recorded the balancer's address: filtering by IP showed all traffic as one client, and Watchtower's Block IP button and auto-block engine blocked the proxy rather than the visitor. Configuring trusted proxies in `bootstrap/app.php` didn't help, because the capture ran before that configuration was applied. Under Octane the result was inconsistent instead — Symfony's trusted-proxy list is static, so once a worker had handled one request, later requests did resolve the forwarded address. Apps where nginx and PHP-FPM share a host with nothing in front were never affected, since `REMOTE_ADDR` is already the client there. The middleware is now inserted directly after `TrustProxies` (an app's own subclass counts); with no `TrustProxies` in the global stack it still goes first. The cost of running second: if one of the few middleware ahead of `TrustProxies` throws (`ValidatePathEncoding`, `TrustHosts`), that log entry has no `trace_id`/`ip_address`/`url` — the case #12 prepended for. `logscope:doctor`'s Middleware check now reports where the middleware actually sits, and warns when `TrustProxies` isn't registered at all. Existing log entries keep the address they were written with.

- **A failed log write inside a database transaction no longer rolls back the app's own writes on Postgres** (#40). LogScope writes on the app's connection. When that insert failed (a constraint, a timeout, a value too long for its column), Postgres aborted the app's whole transaction. LogScope caught the error, so `DB::commit()` returned normally and nothing reported that the app's writes were gone. This affected `sync` write mode, `queue` write mode (the job insert on the `database` queue driver, or the log insert on the `sync` driver), `capture => channel`, fallback rows, batch flushes that ran during a transaction, and the failure banner's cache entry on the `database` cache store. Each LogScope write inside a transaction now runs in its own savepoint, so a failure undoes only the log row. Writes outside a transaction are unchanged. Inside one, each write costs one extra statement for the savepoint. On Postgres, more than 64 LogScope writes in one transaction overflow Postgres's subtransaction cache, which can slow other queries while that transaction is open — the same cost as nesting `DB::transaction()` that many times.

  A log call still never throws. One case remains that a savepoint can't cover: the database has already ended the app's transaction, because the log insert itself deadlocked on MySQL or the connection was lost. The app's writes before that point are gone, any it makes after it are saved one at a time, and its `DB::commit()` fails with `There is no active transaction`. LogScope reports the failure to `error_log` and, if the database is still reachable, as a fallback row.

- **Rolling back LogScope's migrations no longer fails** (#41). `migrate:reset`, `migrate:refresh` and any `migrate:rollback` that reached `2026_01_22` threw `index "log_entries_status_index" does not exist` on every install. Apps whose tests use `DatabaseMigrations` hit it after every test. The cause was `2026_01_24`, which converts v0.5 installs to the `status` columns: its `down()` ran even on new installs, where its `up()` does nothing. It dropped the status columns and restored v0.5 columns the current code doesn't use, so `2026_01_22`'s `down()` then failed. That `down()` now does nothing, and rolling back works on new installs and on installs upgraded from v0.5. On an upgraded install, rolling back `2026_01_24` alone no longer restores the v0.5 columns. If a rollback already failed on a database, run `php artisan migrate` before rolling back again: the failed rollback left that table with the v0.5 columns, and `migrate` converts it back.

- **`php artisan migrate` no longer fails when `LOGSCOPE_TABLE` is set** (#42). `2026_04_28` (the `ip_address` index, added in v1.5.3) used the table name `log_entries` instead of `logscope.table`. It failed with `no such table`, and no later migration ran, including the `user_id` change (#26). Run `php artisan migrate` after upgrading. On Postgres, `2026_04_28` now builds its index with `CREATE INDEX CONCURRENTLY`, so log writes keep working while it runs on a large table (#48). It used a plain `CREATE INDEX`, which blocks writes until the build finishes. If the build is interrupted, running `migrate` again drops the unusable index it left and builds it again. MySQL already built the index without blocking writes.

  If you published the migrations with the `logscope-migrations` tag, your copies still have both bugs, and on Postgres `2026_04_28` still blocks writes. Publishing again skips files you already have, so either run `php artisan vendor:publish --tag=logscope-migrations --force` (this overwrites any changes you made to them) or make the same changes to your copies.

- **`LOGSCOPE_TABLE` can now name a table in another schema** (#55). With a schema-qualified name like `logs.app_logs` whose schema isn't on the connection's `search_path`, rolling back failed on Postgres with `index "logs_app_logs_status_index" does not exist`. `2026_01_22`, `2026_02_27` and `2026_04_28` dropped their indexes by a bare name, and Postgres resolves that through `search_path` rather than in the table's own schema; `2026_02_27` caught the error and silently left its index behind instead. `2026_01_24` failed the same way on the v0.5 upgrade path. `2026_09_14` (#26) would have failed `migrate` outright — on Postgres for the same reason, and on MySQL with `Attempt to read property "type" on null`, because it looked the table up in `information_schema` under the current database and the whole dotted name. On Postgres, `2026_09_14`'s repair of an interrupted `CREATE INDEX CONCURRENTLY` also looked the index up by a bare name, so it never found the unusable index it had left in another schema and `migrate` reported success anyway — the same bug #48 fixed in `2026_04_28`. Reading and writing logs was never affected; only the migrations were. Create the schema yourself before migrating — LogScope doesn't create it. SQLite still takes a plain table name only.

  If you published the migrations with the `logscope-migrations` tag, your copies still have these bugs. Publishing again skips files you already have, so either run `php artisan vendor:publish --tag=logscope-migrations --force` (this overwrites any changes you made to them) or make the same changes to your copies.

- **A migration that can't drop an index now says so instead of continuing** (#55). `2026_02_27`'s `down()` and `2026_01_24`'s `up()` each wrapped an index drop in `catch (\Exception)`, to tolerate an index their `up()` may never have created. That also hid real failures: it is why the bug above went unnoticed on rollback, with `down()` reporting success while leaving the index in place. Both now look the index up first and drop the name the table actually carries, so a missing index is still skipped, an index created under a name LogScope wouldn't generate today is still dropped, and anything else surfaces as an error. **If a rollback starts failing after this upgrade where it previously appeared to succeed, that failure was already happening — it was being swallowed.** The error names the index it couldn't drop.

- **Long-running artisan commands and queue workers no longer hold their logs until they exit** (#28). In `batch` write mode, logs were only written when the process ended. A command that ran for hours kept every log in memory: none were visible while it ran, and a `SIGKILL` or out-of-memory kill lost all of them. The buffer is now also written once it holds 500 logs (`LOGSCOPE_BATCH_MAX_ENTRIES`) or its oldest log is 10 seconds old (`LOGSCOPE_BATCH_MAX_AGE`). Queue workers also write after every job, and before a job timeout kills the worker, so the timed-out job's own logs are kept (unless it timed out inside a database transaction, which the kill rolls back). Set either limit to `0` to disable it. Neither limit writes inside an open database transaction, so a rollback can't delete buffered logs. The write happens on the next log after the transaction ends. Web requests rarely reach either limit and still write once, after the response.

- **Logs are no longer lost when the logged-in user's id isn't an integer** (#26). `user_id` was an unsigned big integer, so under MySQL strict mode a UUID/ULID key or an id like `admin_1` failed the insert. In batch mode that lost every log in the flushed chunk, and the fallback rows meant to surface the failure in the UI carried the same id and failed too. Both capture modes (`all` and `channel`) now store the id as a string. An id that can't be stored (longer than 255 characters, or not an integer, string or `Stringable`) is saved as `null` so the log itself is kept.

- **A log fired after container teardown no longer crashes the process** (#36). A `Logger` resolved before teardown still dispatches `MessageLogged`, so logging through it afterwards (e.g. from a `register_shutdown_function` in a test suite) ran LogScope's listener with no container. Its ignore-config check called `config()` outside the listener's `try`, threw `Target class [config] does not exist`, and exited a passing suite with code 255. That check now skips the log when config can't be read (with no container, the entry couldn't be written anyway) and reports it to `error_log` as `LogScope[ignore-check]: Failed to write log entry: …`. Reports are deduped, so a teardown adds one line per process, not one per late log.

## [1.8.0] — 2026-09-13

### Security

- **HTTP Basic auth passwords are no longer stored when a `Request` is logged** (#34). Symfony copies Basic auth credentials into the header bag as `php-auth-user` / `php-auth-pw`. Header redaction matched exact names and `php-auth-pw` wasn't one of them, so `Log::info('…', ['request' => $request])` on a Basic-auth request stored the password in plaintext. **Rows written before this release may contain it** — search log context for `php-auth-pw` and prune those entries.

### Changed

- **Sensitive headers match by name fragment and add to the defaults** (#34). The defaults are now `auth`, `cookie`, `token`, `key`, `secret`, `password` and `session`, matched anywhere in the header name, so custom credential headers like `x-auth-token` and `x-api-key` are redacted without configuration. Entries in `context.sensitive_headers` are added to the defaults instead of replacing them. Harmless headers that contain a fragment (e.g. `x-idempotency-key`) are now redacted too.

### Fixed

- **Custom `sensitive_keys` and `sensitive_headers` entries match regardless of case** (#34). Only the incoming name was lowercased, so an entry like `'API_KEY'` or `'X-Api-Key'` never matched. `sensitive_keys` still replaces the defaults, so a false positive such as `token` → `prompt_tokens` can be dropped.

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
