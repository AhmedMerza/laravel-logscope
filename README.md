# LogScope

A fast, database-backed log viewer for Laravel applications.

> **Status:** Early development - not yet ready for production use.

## Features

- **Automatic log capture** - Captures ALL logs from ALL channels automatically (zero config)
- **Request context tracking** - Trace ID, user ID, IP address, URL, and user agent for every log
- **Database-backed storage** - Fast queries with proper indexing
- **Advanced filtering** - Filter by level, channel, environment, user, IP, and date range
- **Full-text search** - Find what you need quickly (Scout integration optional)
- **Exclusion filters** - Hide noise, focus on what matters
- **Retention policies** - Auto-prune old logs after configurable period
- **Quick filters** - Configurable one-click filters for common queries
- **Performance optimized** - Batch writes after response, queue support for high-traffic apps
- **Highly configurable** - Customize capture mode, write mode, table names, and more

## Requirements

- PHP 8.2+
- Laravel 10, 11, or 12
- SQLite, MySQL, or PostgreSQL

## Installation

```bash
composer require ahmedmerza/logscope
```

Run the install command to publish config and migrations:

```bash
php artisan logscope:install
php artisan migrate
```

## Configuration

The config file will be published to `config/logscope.php`. Options include:

- **Capture mode** - `all` (automatic) or `channel` (explicit)
- **Write mode** - `sync`, `batch` (default), or `queue` for performance tuning
- **Queue settings** - Queue name and connection for queue write mode
- **Middleware** - Enable/disable request context capture
- **Table name** - Customize the database table name
- **Retention policy** - Enable/disable auto-pruning and set retention days
- **Route configuration** - Customize prefix, middleware, and domain
- **Content limits** - Configure preview lengths and truncation thresholds
- **Search driver** - Choose between database or Scout for search
- **Quick filters** - Define one-click filters for common queries
- **Theme** - Customize colors for the web interface

## Usage

### Automatic Log Capture (Default)

By default, LogScope automatically captures **all logs from all channels** with zero configuration. Just install and you're done!

```php
// All of these are captured automatically
Log::info('User logged in', ['user_id' => 1]);
Log::channel('slack')->error('Payment failed');
Log::stack(['daily', 'slack'])->warning('Low inventory');
```

### Capture Modes

LogScope supports two capture modes:

**`all` (default)** - Automatically captures logs from ALL channels using Laravel's `Log::listen()`. No configuration needed.

**`channel`** - Only captures logs explicitly sent to the LogScope channel. Add the channel to your `config/logging.php`:

```php
'channels' => [
    'stack' => [
        'driver' => 'stack',
        'channels' => ['single', 'logscope'],
    ],

    'logscope' => [
        'driver' => 'custom',
        'via' => \LogScope\Logging\LogScopeChannel::class,
        'level' => env('LOG_LEVEL', 'debug'),
    ],
],
```

Set the capture mode in your `.env`:

```env
LOGSCOPE_CAPTURE=all    # or 'channel'
```

### Write Modes (Performance)

LogScope offers three write modes optimized for different use cases:

**`batch` (default)** - Buffers logs during the request and writes them AFTER the response is sent. Best balance of performance and simplicity.

**`sync`** - Writes immediately to the database. Simplest but can slow down requests.

**`queue`** - Dispatches a queued job for each log entry. Best performance for high-traffic apps but requires a queue worker.

```env
LOGSCOPE_WRITE_MODE=batch    # 'sync', 'batch', or 'queue'
LOGSCOPE_QUEUE=default       # Queue name (when using 'queue' mode)
LOGSCOPE_QUEUE_CONNECTION=   # Optional queue connection
```

### Request Context

LogScope automatically captures request context for every log entry:

- **Trace ID** - Unique identifier to group all logs from a single request
- **User ID** - Authenticated user (if any)
- **IP Address** - Client IP
- **User Agent** - Browser/client information
- **HTTP Method** - GET, POST, PUT, etc.
- **URL** - The request URL

This makes it easy to trace issues across your application.

### Quick Filters

LogScope includes configurable quick filters for common queries. Customize them in `config/logscope.php`:

```php
'quick_filters' => [
    [
        'label' => 'Today',
        'icon' => 'calendar',  // calendar, clock, alert, or filter
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
    // Combine time and levels
    [
        'label' => 'Recent Errors',
        'icon' => 'alert',
        'levels' => ['error', 'critical'],
        'from' => '-24 hours',
    ],
],
```

**Time format options:**
- `'today'` - Start of today
- `'-1 hour'` - 1 hour ago
- `'-4 hours'` - 4 hours ago
- `'-24 hours'` - 24 hours ago
- `'-7 days'` - 7 days ago
- `'-1 week'` - 1 week ago
- `'-1 month'` - 1 month ago

### Import Existing Logs

```bash
# Import from default Laravel log location
php artisan logscope:import

# Import a specific file
php artisan logscope:import storage/logs/laravel.log

# Import only recent logs
php artisan logscope:import --days=7
```

### Prune Old Logs

```bash
# Delete logs older than configured retention period
php artisan logscope:prune

# Preview what would be deleted
php artisan logscope:prune --dry-run

# Override retention days
php artisan logscope:prune --days=14
```

### Access the Web Interface

After installation, access LogScope at `/logscope` (or your configured prefix).

## Customization

### Publishing Assets

```bash
# Publish config only
php artisan vendor:publish --tag=logscope-config

# Publish migrations (for customization)
php artisan vendor:publish --tag=logscope-migrations

# Publish views (for customization)
php artisan vendor:publish --tag=logscope-views
```

### Environment Variables

```env
# Capture mode: 'all' (default) or 'channel'
LOGSCOPE_CAPTURE=all

# Write mode: 'sync', 'batch' (default), or 'queue'
LOGSCOPE_WRITE_MODE=batch

# Queue settings (when using 'queue' write mode)
LOGSCOPE_QUEUE=default
LOGSCOPE_QUEUE_CONNECTION=

# Request context middleware
LOGSCOPE_MIDDLEWARE_ENABLED=true

# Database table
LOGSCOPE_TABLE=log_entries

# Retention policy
LOGSCOPE_RETENTION_ENABLED=true
LOGSCOPE_RETENTION_DAYS=30

# Routes
LOGSCOPE_ROUTES_ENABLED=true
LOGSCOPE_ROUTE_PREFIX=logscope
LOGSCOPE_DOMAIN=

# Search
LOGSCOPE_SEARCH_DRIVER=database

# Migrations
LOGSCOPE_MIGRATIONS_ENABLED=true
```

## Contributing

Contributions are welcome! Please read the contributing guidelines first.

## License

MIT License. See [LICENSE](LICENSE) for details.
