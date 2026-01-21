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
    */

    'table' => env('LOGSCOPE_TABLE', 'log_entries'),

    /*
    |--------------------------------------------------------------------------
    | Retention Policy
    |--------------------------------------------------------------------------
    |
    | Control how long log entries are kept in the database. Set enabled to
    | false to keep logs indefinitely, or specify the number of days.
    |
    */

    'retention' => [
        'enabled' => env('LOGSCOPE_RETENTION_ENABLED', true),
        'days' => env('LOGSCOPE_RETENTION_DAYS', 30),
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
    ],

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
        // Primary accent color (used for buttons, links, etc.)
        'primary' => '#6366f1', // Indigo

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
            'label' => 'Errors Only',
            'icon' => 'alert',
            'levels' => ['error', 'critical', 'alert', 'emergency'],
        ],
        // More examples:
        // [
        //     'label' => 'Last 4 Hours',
        //     'icon' => 'clock',
        //     'from' => '-4 hours',
        // ],
        // [
        //     'label' => 'Last Week',
        //     'icon' => 'calendar',
        //     'from' => '-7 days',
        // ],
        // [
        //     'label' => 'Warnings',
        //     'icon' => 'alert',
        //     'levels' => ['warning'],
        // ],
        // [
        //     'label' => 'Recent Errors',
        //     'icon' => 'alert',
        //     'levels' => ['error', 'critical'],
        //     'from' => '-24 hours',
        // ],
    ],

];
