<?php

return [

    /*
    |--------------------------------------------------------------------------
    | LogScope Table
    |--------------------------------------------------------------------------
    |
    | Configure the database table name used by LogScope. You can customize
    | this if it conflicts with existing tables in your application.
    |
    | On Postgres and MySQL this may be schema-qualified ("logs.app_logs"),
    | including a schema the connection's search_path doesn't list. Create
    | the schema yourself first. SQLite takes a plain table name only.
    |
    */

    'table' => env('LOGSCOPE_TABLE', 'log_entries'),

    /*
    |--------------------------------------------------------------------------
    | LogScope Groups Table
    |--------------------------------------------------------------------------
    |
    | Table holding one row per distinct issue — the rollup of every entry
    | sharing a fingerprint. Same schema-qualification rules as 'table'.
    |
    */

    'groups_table' => env('LOGSCOPE_GROUPS_TABLE', 'log_groups'),

    /*
    |--------------------------------------------------------------------------
    | Retention Policy
    |--------------------------------------------------------------------------
    |
    | Control how long log entries are kept in the database. Set enabled to
    | false to keep logs indefinitely, or specify the number of days.
    |
    | 'levels'        - Optional per-level windows in days, e.g.
    |                   ['debug' => 3, 'info' => 14, 'error' => 90].
    |                   A level not listed keeps 'days'. Empty (the
    |                   default) applies 'days' to every level.
    |
    | 'auto_schedule' - Opt-in. When true, LogScope registers the
    |                   `logscope:prune` command on Laravel's scheduler so
    |                   you don't need to wire it yourself. Default false:
    |                   if you already schedule prune in your own
    |                   `routes/console.php` or `Console\Kernel`, leave
    |                   this off to avoid a duplicate run.
    |
    | 'schedule_at'   - 24h "HH:MM" string used when 'auto_schedule' is
    |                   true. Defaults to 03:00 (low-traffic window).
    |                   Only consulted when auto_schedule is enabled.
    |
    */

    'retention' => [
        'enabled' => env('LOGSCOPE_RETENTION_ENABLED', true),
        'days' => env('LOGSCOPE_RETENTION_DAYS', 30),
        'levels' => [],
        'auto_schedule' => env('LOGSCOPE_RETENTION_AUTO_SCHEDULE', false),
        'schedule_at' => env('LOGSCOPE_RETENTION_SCHEDULE_AT', '03:00'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes & Authorization
    |--------------------------------------------------------------------------
    |
    | Configure the routes for LogScope's web interface.
    |
    | Authorization:
    | LogScope uses a flexible authorization system with three options:
    |
    | 1. Custom Callback (highest priority):
    |    In your AppServiceProvider::boot():
    |
    |    LogScope::auth(function ($request) {
    |        return $request->user()?->isAdmin();
    |    });
    |
    | 2. Gate (if no callback set):
    |    In your AuthServiceProvider::boot():
    |
    |    Gate::define('viewLogScope', function ($user) {
    |        return $user->hasRole('admin');
    |    });
    |
    | 3. Default (if no callback or gate):
    |    Only accessible in 'local' environment.
    |
    | Middleware:
    | Add your own middleware (auth, roles, etc.) to the array below.
    | The LogScope authorization middleware is always applied automatically.
    |
    | Full Control:
    | Set 'enabled' to false and register routes manually in your app.
    |
    */

    'routes' => [
        'enabled' => env('LOGSCOPE_ROUTES_ENABLED', true),
        'prefix' => env('LOGSCOPE_ROUTE_PREFIX', 'logscope'),
        'middleware' => ['web'],
        'domain' => env('LOGSCOPE_DOMAIN'),
        'forbidden_redirect' => env('LOGSCOPE_FORBIDDEN_REDIRECT', '/'), // Where to redirect on 403, null to show default 403 page
        'unauthenticated_redirect' => env('LOGSCOPE_UNAUTHENTICATED_REDIRECT', '/login'), // Where to redirect on 401/419 (session expired)
    ],

    /*
    |--------------------------------------------------------------------------
    | Capture Mode
    |--------------------------------------------------------------------------
    |
    | Choose how LogScope captures logs:
    |
    | 'channel' - Only capture logs sent to the 'logscope' channel.
    |             User must add 'logscope' to their channel stack.
    |
    | 'all'     - Automatically capture ALL logs from ALL channels.
    |             No channel configuration needed. Uses Log::listen().
    |
    */

    'capture' => env('LOGSCOPE_CAPTURE', 'all'),

    /*
    |--------------------------------------------------------------------------
    | Ignore Filters
    |--------------------------------------------------------------------------
    |
    | Configure which logs should be ignored and not captured by LogScope.
    |
    | 'deprecations' - Ignore PHP deprecation warnings. Filters by CHANNEL
    |                  (default: 'deprecations', Laravel's standard channel
    |                  for routing E_DEPRECATED). If your app routes
    |                  deprecations through a differently-named channel,
    |                  add it to `deprecation_channels`.
    |
    | 'deprecation_channels' - Channels treated as PHP-deprecation channels
    |                  for the filter above. Default ['deprecations'].
    |
    | 'null_channel' - Ignore logs that LogScope can't attribute to a named
    |                  channel. ⚠️ READ BEFORE ENABLING: this is the same
    |                  bucket as logs from Log::build() at runtime AND
    |                  Laravel's own framework-level error reporter (which
    |                  routes uncaught exceptions through a stack that may
    |                  not have the channel processor). Enabling this WILL
    |                  drop unhandled exceptions in many apps. Only enable
    |                  if you know exactly which logs flow through the
    |                  no-channel path in your app and you genuinely don't
    |                  want them. Default: false.
    |
    */

    'ignore' => [
        'deprecations' => env('LOGSCOPE_IGNORE_DEPRECATIONS', true),
        'deprecation_channels' => ['deprecations'],
        'null_channel' => env('LOGSCOPE_IGNORE_NULL_CHANNEL', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Failure Banner
    |--------------------------------------------------------------------------
    |
    | When LogScope's own write path fails (e.g. transient DB outage),
    | the failure is always emitted to PHP's error_log. This config
    | additionally writes a breadcrumb to the cache so the LogScope UI
    | can show an in-banner warning to operators who don't routinely
    | check server logs.
    |
    | 'enabled'      - Toggle the cache-backed banner. error_log emission
    |                  is unaffected and always fires.
    |
    | 'ttl_seconds'  - How long the breadcrumb stays in cache.
    |                  null (default) = persist until the user dismisses.
    |                                    A Saturday-night failure stays
    |                                    visible Monday morning.
    |                  int             = auto-expire after N seconds
    |                                    (e.g. 3600 for 1 hour).
    |
    | Caveats (cache is best-effort, NOT persistent storage):
    |   * `php artisan cache:clear` wipes the breadcrumb.
    |   * If your cache uses the `database` driver and the DB itself is
    |     the failure (the most common case for transient outages), the
    |     cache write fails silently. The error_log line still fires —
    |     it's the reliable signal. Use Redis or file cache if you want
    |     the banner to survive DB outages.
    |   * Hard process kills (SIGKILL, OOM) skip both error_log and
    |     cache writes — there's no userland recovery for those.
    |
    */

    'failure_banner' => [
        'enabled' => env('LOGSCOPE_FAILURE_BANNER_ENABLED', true),
        'ttl_seconds' => env('LOGSCOPE_FAILURE_BANNER_TTL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Write Failure Fallback
    |--------------------------------------------------------------------------
    |
    | When the normal write path throws (e.g. an autoload error inside context
    | sanitization, or a bulk insert blowing up mid-flush), LogScope can write
    | a minimal *fallback* row to log_entries so the failure shows up in the
    | UI alongside everything else — not just in php-fpm's error_log.
    |
    | The fallback row keeps the original level/message/channel/trace_id and
    | replaces `context` with a `_logscope_write_failure` marker carrying the
    | exception class, message, call-site label, and occurrence counter.
    |
    | Dedupe: per-process counter keyed on exception class + throw site. A
    | row is emitted on the first occurrence and every 100th thereafter,
    | so a sustained outage stays visible in the UI (heartbeat) without
    | flooding the table. Octane workers reset the counter at request
    | boundaries so the dedupe scopes to the request, not the worker
    | lifetime.
    |
    | 'persist_fallback' - Toggle the fallback row. Disable if you prefer to
    |                      rely on error_log + cache banner alone.
    |
    */
    'write_failure' => [
        'persist_fallback' => env('LOGSCOPE_WRITE_FAILURE_PERSIST_FALLBACK', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Features
    |--------------------------------------------------------------------------
    |
    | Enable or disable optional features.
    |
    | 'status' - Allow changing log status (open, investigating, resolved, ignored).
    |            Closed logs (resolved/ignored) are hidden by default but can be viewed.
    |
    | 'notes'  - Allow adding notes/comments to log entries.
    |            Useful for documenting investigation findings.
    |
    */

    'features' => [
        'status' => env('LOGSCOPE_FEATURE_STATUS', true),
        'notes' => env('LOGSCOPE_FEATURE_NOTES', true),
        'search_syntax' => env('LOGSCOPE_FEATURE_SEARCH_SYNTAX', true),
        'regex' => env('LOGSCOPE_FEATURE_REGEX', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Grouping
    |--------------------------------------------------------------------------
    |
    | Entries sharing a fingerprint are the same issue, and triage — status
    | and notes — applies to the group rather than to each occurrence.
    |
    | 'enabled' is a VIEW setting, not a capture setting. Fingerprints and
    | log_groups rows are written either way; turning this off only makes the
    | flat entry list the default and hides the Grouped/All toggle. That is
    | deliberate: status lives on the group, so a mode where groups did not
    | exist would need a second, separate status implementation for the flat
    | list, and the two would drift. The cost of always recording is three
    | statements per batch flush, whatever the batch size.
    |
    | 'regression' - When a Resolved group sees a new occurrence it reopens and
    |                records regressed_at, so an error that comes back is
    |                visible again instead of staying hidden behind the status
    |                you gave it last week. Ignored groups are never reopened;
    |                that is the difference between the two closed statuses.
    |                Applies to the built-in 'resolved' status only — a custom
    |                closed status from the 'statuses' block stays as it is.
    |
    */

    'grouping' => [
        'enabled' => env('LOGSCOPE_GROUPING_ENABLED', true),
        'regression' => env('LOGSCOPE_GROUPING_REGRESSION', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Status Configuration
    |--------------------------------------------------------------------------
    |
    | Customize the built-in statuses (open, investigating, resolved, ignored)
    | or add new ones. Each status can have:
    |
    | - label:    Display name
    | - color:    gray, yellow, green, slate, blue, red, orange, purple
    | - closed:   true for statuses that mean "no action needed"
    | - shortcut: Single key for keyboard shortcut. Context-aware:
    |             * With a log detail panel OPEN: change THAT log's
    |               status to this value, then advance to the next log
    |               (rapid keyboard triage flow).
    |             * With NO detail open: filter the list by this status
    |               (legacy behavior).
    |             Set to null to disable.
    |
    | Built-in defaults:
    |   open:          { label: 'Open',          color: 'gray',   closed: false, shortcut: 'O' }
    |   investigating: { label: 'Investigating', color: 'yellow', closed: false, shortcut: 'I' }
    |   resolved:      { label: 'Resolved',      color: 'green',  closed: true,  shortcut: 'R' }
    |   ignored:       { label: 'Ignored',       color: 'slate',  closed: true,  shortcut: 'X' }
    |
    | Example - override built-in + add custom:
    |
    | 'statuses' => [
    |     // Override built-in status
    |     'investigating' => [
    |         'label' => 'In Progress',
    |         'color' => 'blue',
    |     ],
    |     // Disable shortcut for a status
    |     'ignored' => [
    |         'shortcut' => null,
    |     ],
    |     // Add custom status with shortcut
    |     'waiting' => [
    |         'label' => 'Waiting for Customer',
    |         'color' => 'orange',
    |         'closed' => false,
    |         'shortcut' => 'w',
    |     ],
    | ],
    |
    */

    'statuses' => [],

    /*
    |--------------------------------------------------------------------------
    | Keyboard Shortcuts
    |--------------------------------------------------------------------------
    |
    | Configure high-frequency keyboard shortcuts for common actions.
    | Set any shortcut to null to disable it.
    |
    */

    'keyboard_shortcuts' => [
        'refresh' => 'r',
        'prev_page' => 'h',
        'next_page' => 'l',
    ],

    /*
    |--------------------------------------------------------------------------
    | Write Mode (Performance)
    |--------------------------------------------------------------------------
    |
    | Choose how logs are written to the database:
    |
    | 'sync'  - Write immediately (simplest, but can slow requests)
    |
    | 'batch' - Buffer logs during request, write AFTER response is sent.
    |           Best balance of performance and simplicity. (default)
    |
    | 'queue' - Dispatch a queued job for each log entry.
    |           Best performance but requires queue worker running.
    |           Uses the queue specified in 'queue_name'.
    |
    */

    'write_mode' => env('LOGSCOPE_WRITE_MODE', 'batch'),

    /*
    |--------------------------------------------------------------------------
    | Defer Writes During Transactions
    |--------------------------------------------------------------------------
    |
    | LogScope writes on your application's database connection. A log
    | written inside one of your transactions therefore holds locks in the
    | log table until you commit — the Clear button and `logscope:prune`
    | then wait on your request, and a Clear covering two levels or channels
    | can deadlock with it, which your transaction loses. Those rows also
    | roll back with the transaction, losing the logs that explain why it
    | rolled back.
    |
    | With this on, a log written while a transaction is open is held in
    | memory and written as soon as that transaction ends, whether it
    | committed or rolled back. Reading logs then never affects the app.
    |
    | This covers the writes that land on your connection: 'sync', channel
    | capture, and 'queue' on the 'sync' or 'database' driver. 'batch'
    | already behaves this way, and a broker queue (redis, sqs) is left
    | alone — your transaction never touched it, and holding the entry in
    | memory would only cost the dispatch its durability. Entries held
    | during a transaction are written directly when it ends rather than
    | dispatched; the queued job would have written the same rows on the
    | same connection, one statement later.
    |
    | Forced off in the 'testing' environment: RefreshDatabase wraps each
    | test in a transaction that never commits, so deferred logs would
    | never be written and assertions against them would fail. Turn it
    | back on inside a test to exercise this behaviour.
    |
    */

    'defer_in_transactions' => env('LOGSCOPE_DEFER_IN_TRANSACTIONS', true),

    /*
    |--------------------------------------------------------------------------
    | Batch Limits
    |--------------------------------------------------------------------------
    |
    | When write_mode is 'batch', the buffer is also written before the
    | process ends once it holds 'max_entries' logs, or once its oldest log
    | is 'max_age' seconds old. This keeps long-running artisan commands
    | from holding their logs in memory until they exit. Queue workers
    | also flush after every job. Set either limit to 0 to disable it.
    |
    | Neither limit applies inside an open database transaction, where the
    | buffer waits for the transaction to end instead. The exception is a
    | hard cap at ten times 'max_entries': a transaction that logs that
    | much would otherwise grow the buffer until the process runs out of
    | memory, so at the cap the buffer is written inside the transaction
    | after all. Each write is isolated in a savepoint, so a failure there
    | can't take your transaction with it.
    |
    */

    'batch' => [
        'max_entries' => env('LOGSCOPE_BATCH_MAX_ENTRIES', 500),
        'max_age' => env('LOGSCOPE_BATCH_MAX_AGE', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | When write_mode is 'queue', these settings control the queue behavior.
    |
    */

    'queue' => [
        'name' => env('LOGSCOPE_QUEUE', 'default'),
        'connection' => env('LOGSCOPE_QUEUE_CONNECTION'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Request Context Middleware
    |--------------------------------------------------------------------------
    |
    | Enable automatic capturing of request context (trace ID, user ID, IP,
    | etc.) for all log entries. This adds a global middleware that captures
    | request information and attaches it to all logs.
    |
    */

    'middleware' => [
        'enabled' => env('LOGSCOPE_MIDDLEWARE_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Migrations
    |--------------------------------------------------------------------------
    |
    | Control whether LogScope's migrations are automatically loaded.
    | Disable this if you want to publish and customize the migrations.
    |
    */

    'migrations' => [
        'enabled' => env('LOGSCOPE_MIGRATIONS_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Content Limits
    |--------------------------------------------------------------------------
    |
    | Configure size limits for log content. Messages and context larger than
    | the inline max will be truncated. The preview length controls what's
    | shown in list views.
    |
    */

    'limits' => [
        'message_preview_length' => 500,
        'message_inline_max' => 16000,
        'context_preview_length' => 500,
        'context_inline_max' => 32000,
        'truncate_at' => 1000000, // 1MB
    ],

    /*
    |--------------------------------------------------------------------------
    | Context Sanitization
    |--------------------------------------------------------------------------
    |
    | Configure how objects in log context are sanitized for storage.
    |
    | 'expand_objects' - When true, objects that implement Arrayable,
    |                    JsonSerializable, or Jsonable will be converted
    |                    to arrays. Request objects will be expanded to
    |                    show method, url, input, headers, etc.
    |                    When false, objects show as [Object: ClassName].
    |
    | 'redact_sensitive' - When true, sensitive keys/headers are redacted.
    |                      Set to false to disable all redaction (not recommended).
    |
    | 'sensitive_keys' - Keys whose values are replaced with [REDACTED],
    |                    anywhere in an entry's context: arrays you log
    |                    yourself, objects expanded into arrays, and expanded
    |                    Request objects alike, at any depth.
    |                    Matched per word, ignoring case and separators:
    |                    'token' redacts token, access_token, accessToken,
    |                    APIToken and access_tokens, while 'card_number'
    |                    also redacts cardNumber and card.number.
    |                    A multi-word entry spans array levels too, so
    |                    'card_number' covers ['card' => ['number' => …]]
    |                    and the ?card[number]= form Laravel parses into it.
    |                    The halves must be adjacent: a numeric position is
    |                    stepped over (card[0][number] redacts), but a named
    |                    level between them ends the match, just as it would
    |                    inside one key — ['card' => ['holder' => ['number'
    |                    => …]]] is no more covered than card_holder_number.
    |                    A one-word entry only matches inside a single word
    |                    and never spans levels, so 'ssn' redacts neither
    |                    class_name nor ['class' => ['name' => …]].
    |                    Keys over 256 characters are redacted unexamined.
    |                    Entries ADD to the defaults, they never replace
    |                    them, so adding one key keeps the other eleven.
    |                    To stop a default redacting a field of yours, name
    |                    that field in 'sensitive_keys_except' below — that
    |                    is what it is for, and it is precise where dropping
    |                    an entry from this list would not be.
    |                    `php artisan logscope:doctor` prints the list that
    |                    is actually in force.
    |
    | 'sensitive_keys_except' - Keys that look sensitive but are not, so they
    |                    keep their values. An entry removes the words it
    |                    covers, so 'prompt_tokens' keeps prompt_tokens but
    |                    still lets prompt_tokens_password redact.
    |                    Entries ADD to the defaults (the usage counters of
    |                    the common LLM APIs, plus token_count and
    |                    tokenizer), so naming your own keeps LogScope's.
    |                    Entries under 3 characters are ignored.
    |                    This is the dial to reach for when a field of yours
    |                    reads [REDACTED] and should not.
    |                    Prefer the FULL field name: a one-word entry removes
    |                    every word containing it, so 'tokens' would also
    |                    stop access_tokens being redacted, while
    |                    'usage_tokens' only covers that field.
    |                    A multi-word exclusion cancels the key it names, not
    |                    the same name split across array levels: excluding
    |                    'credit_card' keeps credit_card, while
    |                    ['credit' => ['card' => …]] still redacts.
    |
    | 'sensitive_headers' - Request header name fragments that should be redacted.
    |                       Headers containing these strings (case-insensitive)
    |                       will be replaced with [REDACTED].
    |                       Entries are added to the defaults, never replace them.
    |
    | 'headers' - Capture request headers onto each entry's own column.
    |             Allowlist only: there is no "capture everything" mode, because
    |             that is where the risk lives (unknown vendor auth headers,
    |             php-auth-pw, table growth). Add what you need to 'allowlist'.
    |             Anything matching sensitive_headers is stored as [REDACTED]
    |             even when you allowlist it explicitly.
    |
    */

    'context' => [
        'expand_objects' => env('LOGSCOPE_EXPAND_OBJECTS', true),
        'redact_sensitive' => env('LOGSCOPE_REDACT_SENSITIVE', true),

        // Added to the defaults: password, password_confirmation, secret,
        // token, api_key, apikey, authorization, credit_card, card_number,
        // cvv, ssn
        'sensitive_keys' => [],

        // Added to the defaults: prompt_tokens, completion_tokens,
        // total_tokens, input_tokens, output_tokens, max_tokens,
        // tokens_used, token_count, tokenizer
        'sensitive_keys_except' => [],

        // Added to the defaults: auth, cookie, token, key, secret, password, session
        'sensitive_headers' => [],

        'headers' => [
            'enabled' => env('LOGSCOPE_CAPTURE_HEADERS', true),

            // Matched case-insensitively. user-agent is absent on purpose:
            // it already has its own column.
            'allowlist' => [
                'content-type', 'accept', 'referer',
                'x-forwarded-for', 'x-request-id', 'origin',
            ],

            // Longer values are cut and end in …[truncated], so a trimmed
            // value is never mistaken for the real one.
            'max_value_length' => 500,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | JSON Viewer
    |--------------------------------------------------------------------------
    |
    | Configure how JSON context is displayed in the detail panel.
    |
    | 'collapse_threshold' - Arrays/objects with more items than this will be
    |                        collapsed by default. Set to 0 to disable.
    |
    | 'auto_collapse_keys' - Keys that should always be collapsed by default,
    |                        regardless of size (e.g., stack traces).
    |
    */

    'json_viewer' => [
        'collapse_threshold' => 5,
        'auto_collapse_keys' => ['trace', 'stack_trace', 'stacktrace', 'backtrace'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Search
    |--------------------------------------------------------------------------
    |
    | Configure the search driver. Currently only 'database' is supported,
    | which uses SQL LIKE queries. Scout integration is planned for future.
    |
    */

    'search' => [
        'driver' => env('LOGSCOPE_SEARCH_DRIVER', 'database'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    |
    | Configure pagination settings for the log viewer.
    |
    */

    'pagination' => [
        'per_page' => 50,
        'max_per_page' => 100,

        /*
         * Include the full `message` and `context` in the list response so the
         * detail panel can render instantly without a second round-trip. Adds
         * roughly 1-2KB per row to the payload (typical) and is a clear win on
         * high-latency links. Disable for installs with very large messages
         * (multi-MB stack traces, raw HTTP bodies) where the per-page payload
         * could balloon — the show endpoint will fetch on demand instead.
         */
        'eager_load_detail' => env('LOGSCOPE_EAGER_LOAD_DETAIL', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache TTL
    |--------------------------------------------------------------------------
    |
    | How long (in seconds) to cache read-only dashboard data: total log
    | counts, level/channel/method filter options. Lower values mean fresher
    | data at the cost of more database queries. Set to 0 to disable caching.
    |
    */

    'cache_ttl' => env('LOGSCOPE_CACHE_TTL', 60),

    /*
    |--------------------------------------------------------------------------
    | Theme
    |--------------------------------------------------------------------------
    |
    | Customize the appearance of LogScope's web interface. You can override
    | the primary color and log level colors. Colors should be valid CSS values.
    |
    */

    'theme' => [
        // Primary accent color (used for buttons, links, selections, etc.)
        'primary' => '#10b981', // Emerald

        // Default to dark mode for new users (users can toggle and their preference is saved)
        'dark_mode_default' => true,

        // Google Fonts to load (set to false to disable external fonts)
        // When disabled, the UI will fall back to system fonts
        'fonts' => [
            'sans' => 'Outfit',  // Used for UI text
            'mono' => 'JetBrains Mono',  // Used for code/logs
        ],

        // Log level colors
        'levels' => [
            'emergency' => ['bg' => '#7f1d1d', 'text' => '#fecaca'],
            'alert' => ['bg' => '#9a3412', 'text' => '#fed7aa'],
            'critical' => ['bg' => '#991b1b', 'text' => '#fecaca'],
            'error' => ['bg' => '#dc2626', 'text' => '#ffffff'],
            'warning' => ['bg' => '#f59e0b', 'text' => '#1f2937'],
            'notice' => ['bg' => '#06b6d4', 'text' => '#ffffff'],
            'info' => ['bg' => '#3b82f6', 'text' => '#ffffff'],
            'debug' => ['bg' => '#6b7280', 'text' => '#ffffff'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Quick Filters
    |--------------------------------------------------------------------------
    |
    | Define the quick filter buttons shown in the sidebar. Users can customize
    | these to create any filter combination they need.
    |
    | Available options for each filter:
    | - label: Display name (required)
    | - icon: calendar, clock, alert, filter (default: filter)
    | - levels: Array of log levels to filter by
    | - statuses: Array of statuses to filter by (e.g., ['open', 'investigating'])
    | - from: Time filter - 'today', '-1 hour', '-4 hours', '-24 hours', '-7 days', etc.
    | - to: End time filter (optional, defaults to now)
    |
    | Time format examples:
    | - 'today' - Start of today
    | - '-1 hour' - 1 hour ago
    | - '-4 hours' - 4 hours ago
    | - '-24 hours' - 24 hours ago
    | - '-7 days' - 7 days ago
    | - '-1 week' - 1 week ago
    | - '-1 month' - 1 month ago
    |
    */

    'quick_filters' => [
        [
            'label' => 'Today',
            'icon' => 'calendar',
            'from' => 'today',
        ],
        [
            'label' => 'This Hour',
            'icon' => 'clock',
            'from' => '-1 hour',
        ],
        [
            'label' => 'Last 24 Hours',
            'icon' => 'clock',
            'from' => '-24 hours',
        ],
        [
            'label' => 'Recent Errors',
            'icon' => 'alert',
            'levels' => ['error', 'critical', 'alert', 'emergency'],
            'from' => '-24 hours',
        ],
        // More examples:
        // [
        //     'label' => 'Needs Attention',
        //     'icon' => 'alert',
        //     'statuses' => ['open', 'investigating'],
        // ],
        // [
        //     'label' => 'Last Week',
        //     'icon' => 'calendar',
        //     'from' => '-7 days',
        // ],
        // [
        //     'label' => 'Resolved Today',
        //     'icon' => 'filter',
        //     'statuses' => ['resolved'],
        //     'from' => 'today',
        // ],
    ],

];
