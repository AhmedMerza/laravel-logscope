<?php

return [

    /*
    |--------------------------------------------------------------------------
    | LogScope Tables
    |--------------------------------------------------------------------------
    |
    | Configure the database table names used by LogScope. You can customize
    | these if they conflict with existing tables in your application.
    |
    */

    'tables' => [
        'entries' => env('LOGSCOPE_TABLE_ENTRIES', 'log_entries'),
        'presets' => env('LOGSCOPE_TABLE_PRESETS', 'filter_presets'),
    ],

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
    | Routes
    |--------------------------------------------------------------------------
    |
    | Configure the routes for LogScope's web interface.
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
    | Configure the search driver. Use 'database' for built-in search
    | or 'scout' to use Laravel Scout with your preferred engine.
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

];
