# LogScope

A fast, database-backed log viewer for Laravel applications.

> **Status:** Beta - actively developed, used in production by early adopters.

## Features

- **Automatic log capture** - Captures ALL logs from ALL channels automatically (zero config)
- **Request context tracking** - Trace ID, user ID, IP address, URL, and user agent for every log
- **Database-backed storage** - Fast queries with proper indexing
- **Advanced filtering** - Filter by level, channel, HTTP method, user, IP, and date range
- **Include/Exclude filters** - Three-state toggles to include, exclude, or ignore each filter
- **Full-text search** - Search across messages, context, and source with NOT support
- **Resolvable logs** - Mark logs as resolved instead of deleting, track who resolved them
- **Log notes** - Add notes to log entries for context and investigation findings
- **Retention policies** - Auto-prune old logs after configurable period
- **Quick filters** - Configurable one-click filters for common queries
- **Keyboard shortcuts** - Navigate with j/k, search with /, help with ?, close with Esc
- **Shareable links** - URL reflects current filters for easy sharing
- **Resizable detail panel** - Drag to resize, width persisted across sessions
- **Dark mode** - Full dark mode support with persistence
- **Performance optimized** - Batch writes after response, queue support for high-traffic apps
- **Highly configurable** - Customize capture mode, write mode, table names, and more

## Requirements

- PHP 8.2+
- Laravel 10, 11, or 12
- SQLite, MySQL, or PostgreSQL

## When to Use LogScope

LogScope stores logs in your database. This is a deliberate choice that works great for most Laravel applications.

**LogScope is a great fit if you:**
- Want log visibility without setting up external services
- Have a typical Laravel app (up to ~100K requests/day)
- Need to search and filter logs with rich context
- Prefer simplicity over infrastructure complexity

**You might prefer other solutions if you:**
- Process millions of requests daily and need specialized log infrastructure
- Need to retain logs for months or years
- Already use centralized logging (Datadog, CloudWatch, ELK)

**Addressing common concerns:**

| Concern | How LogScope handles it |
|---------|------------------------|
| Database bloat | Retention policies auto-delete old logs (default: 30 days) |
| Performance impact | Batch mode writes logs *after* the response is sent |
| Query speed | Proper indexes on common filter combinations |

For most Laravel apps, the choice comes down to: 5 minutes of setup with LogScope, or hours configuring external log infrastructure. LogScope is designed for developers who want practical log visibility without the complexity.

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
- **Ignore filters** - Suppress deprecations and/or null-channel logs
- **Write mode** - `sync`, `batch` (default), or `queue` for performance tuning
- **Queue settings** - Queue name and connection for queue write mode
- **Middleware** - Enable/disable request context capture
- **Table name** - Customize the database table name
- **Retention policy** - Enable/disable auto-pruning and set retention days
- **Route configuration** - Customize prefix, middleware, and domain
- **Content limits** - Configure preview lengths and truncation thresholds
- **Search driver** - Database search (Scout integration planned)
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

### Reducing Log Noise

LogScope can filter out noisy logs that you don't want to capture:

**Ignore PHP Deprecations** (enabled by default) - Third-party packages often trigger PHP deprecation warnings. These are captured by Laravel's error handler and can clutter your logs.

**Ignore Null Channel Logs** (enabled by default) - Logs without a channel are usually PHP errors/warnings rather than explicit `Log::` calls.

```env
# Both are enabled by default. Set to false to capture these logs:
LOGSCOPE_IGNORE_DEPRECATIONS=false   # Capture "is deprecated" messages
LOGSCOPE_IGNORE_NULL_CHANNEL=false   # Capture logs without a channel
```

### Resolvable Logs

Instead of deleting logs, you can mark them as resolved. Resolved logs are hidden by default but can be viewed when needed.

```php
// Toggle visibility in the UI with the checkmark icon
// Or via API:
POST /logscope/api/logs/{id}/resolve
POST /logscope/api/logs/{id}/unresolve
```

**Customizing "Resolved By"**

By default, LogScope uses the authenticated user's name or email. You can customize this in your `AppServiceProvider`:

```php
use LogScope\LogScope;

public function boot(): void
{
    LogScope::resolvedBy(function ($request) {
        return $request->user()?->full_name;
        // Or combine fields:
        // return $request->user() ? "{$user->name} ({$user->role})" : null;
    });
}
```

**Disable Resolvable Feature**

```env
LOGSCOPE_FEATURE_RESOLVABLE=false
```

### Log Notes

Add notes to log entries to document investigation findings or context:

- Click the note area in the detail panel to add/edit
- Notes are saved automatically
- Useful for team collaboration on debugging

**Disable Notes Feature**

```env
LOGSCOPE_FEATURE_NOTES=false
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

### Keyboard Shortcuts

LogScope includes keyboard shortcuts for faster navigation:

| Key | Action |
|-----|--------|
| `j` | Next log entry |
| `k` | Previous log entry |
| `/` | Focus search input |
| `?` | Show keyboard shortcuts help |
| `Esc` | Close detail panel / dialogs |

### Authorization

LogScope uses a flexible authorization system with three options (in priority order):

**1. Custom Callback** (highest priority)

Define a callback in your `AppServiceProvider::boot()`:

```php
use LogScope\LogScope;

public function boot(): void
{
    LogScope::auth(function ($request) {
        return $request->user()?->isAdmin();
    });
}
```

**2. Gate Definition**

Define a `viewLogScope` gate in your `AuthServiceProvider`:

```php
use Illuminate\Support\Facades\Gate;

public function boot(): void
{
    Gate::define('viewLogScope', function ($user) {
        return $user->hasRole('admin');
    });
}
```

**3. Default Behavior**

If no callback or gate is defined, LogScope is only accessible in the `local` environment.

**Additional Middleware**

You can also add middleware via config (e.g., `auth`, custom roles):

```php
// config/logscope.php
'routes' => [
    'middleware' => ['web', 'auth'],
    // ...
],
```

**Full Control**

For complete customization, disable the default routes and register your own:

```php
// config/logscope.php
'routes' => [
    'enabled' => false,
],
```

Then register the routes manually in your `routes/web.php` with any middleware or logic you need.

## Production Deployment

### Recommended Settings

For production environments, we recommend:

```env
# Use batch (default) or queue mode - never sync in production
LOGSCOPE_WRITE_MODE=batch

# Keep logs for 7-14 days (adjust based on your needs)
LOGSCOPE_RETENTION_DAYS=14

# Set appropriate log level (reduces noise)
LOG_LEVEL=info
```

### Schedule Pruning

Add to your scheduler to automatically clean old logs:

```php
// Laravel 11+ (routes/console.php)
use Illuminate\Support\Facades\Schedule;

Schedule::command('logscope:prune')->daily();

// Laravel 10 (app/Console/Kernel.php)
protected function schedule(Schedule $schedule): void
{
    $schedule->command('logscope:prune')->daily();
}
```

### High-Traffic Applications

For applications with high traffic (thousands of requests/day):

1. **Use queue mode** with a dedicated queue:
   ```env
   LOGSCOPE_WRITE_MODE=queue
   LOGSCOPE_QUEUE=logs
   ```

2. **Run a separate queue worker** for logs:
   ```bash
   php artisan queue:work --queue=logs
   ```

3. **Consider shorter retention** (7 days) to keep the table size manageable.

### Performance Notes

- **Stats are cached** for 60 seconds to reduce database load
- **Batch mode** (default) writes logs after the response is sent, so it won't slow down your requests
- **Indexes** are optimized for common filter combinations (level + date, channel + date, etc.)

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

# Ignore filters (both enabled by default, set to false to capture)
LOGSCOPE_IGNORE_DEPRECATIONS=false
LOGSCOPE_IGNORE_NULL_CHANNEL=false

# Features (both enabled by default)
LOGSCOPE_FEATURE_RESOLVABLE=true
LOGSCOPE_FEATURE_NOTES=true

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

# Search (Scout integration planned)
LOGSCOPE_SEARCH_DRIVER=database

# Migrations
LOGSCOPE_MIGRATIONS_ENABLED=true
```

## Contributing

Contributions are welcome! Please open an issue or submit a pull request.

## License

MIT License. See [LICENSE](LICENSE) for details.
