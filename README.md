# LogScope

[![Latest Version](https://img.shields.io/packagist/v/ahmedmerza/logscope.svg?style=flat-square)](https://packagist.org/packages/ahmedmerza/logscope)
[![License](https://img.shields.io/packagist/l/ahmedmerza/logscope.svg?style=flat-square)](https://packagist.org/packages/ahmedmerza/logscope)
[![PHP Version](https://img.shields.io/packagist/php-v/ahmedmerza/logscope.svg?style=flat-square)](https://packagist.org/packages/ahmedmerza/logscope)

A beautiful, fast, database-backed log viewer for Laravel applications.

<!-- TODO: Add screenshot here -->
<!-- ![LogScope Screenshot](art/screenshot.png) -->

## Quick Start

```bash
composer require ahmedmerza/logscope
php artisan logscope:install
php artisan migrate
```

Visit `/logscope` in your browser. That's it!

---

## Table of Contents

- [Features](#-features)
- [Requirements](#-requirements)
- [When to Use LogScope](#-when-to-use-logscope)
- [Installation](#-installation)
- [Configuration](#%EF%B8%8F-configuration)
- [Usage](#-usage)
- [Production Deployment](#-production-deployment)
- [Customization](#-customization)
- [Contributing](#-contributing)
- [License](#-license)

---

## ✨ Features

| Feature | Description |
|---------|-------------|
| **Zero-Config Capture** | Automatically captures ALL logs from ALL channels |
| **Request Context** | Trace ID, user ID, IP, URL, and user agent for every log |
| **Advanced Search** | Full-text search with field targeting and NOT support |
| **Smart Filters** | Include/exclude by level, channel, HTTP method, date range |
| **JSON Viewer** | Syntax-highlighted, collapsible JSON with smart defaults |
| **Status Workflow** | Track logs as open, investigating, resolved, or ignored |
| **Log Notes** | Add investigation notes to any log entry |
| **Quick Filters** | One-click filters for common queries |
| **Keyboard Shortcuts** | 13 shortcuts for navigation, status changes, and actions |
| **Dark Mode** | Full dark mode support with persistence |
| **Shareable URLs** | Current filters reflected in URL for sharing |
| **Performance** | Batch writes, queue support, proper indexing |

---

## 📋 Requirements

- PHP 8.2+
- Laravel 10, 11, or 12
- SQLite, MySQL, or PostgreSQL

---

## 🤔 When to Use LogScope

LogScope stores logs in your database - a deliberate choice that works great for most Laravel apps.

**Great fit if you:**
- Want log visibility without external services
- Have a typical Laravel app (up to ~100K requests/day)
- Need rich search and filtering
- Prefer simplicity over infrastructure complexity

**Consider alternatives if you:**
- Process millions of requests daily
- Need months/years of log retention
- Already use centralized logging (Datadog, CloudWatch, ELK)

**How LogScope handles common concerns:**

| Concern | Solution |
|---------|----------|
| Database bloat | Retention policies with scheduled pruning (default: 30 days) |
| Performance | Batch mode writes logs *after* response is sent |
| Query speed | Proper indexes on common filter combinations |

---

## 📦 Installation

```bash
composer require ahmedmerza/logscope
```

Run the install command:

```bash
php artisan logscope:install
php artisan migrate
```

Access the dashboard at `/logscope`.

---

## ⚙️ Configuration

After installation, configure LogScope in `config/logscope.php` or via environment variables.

### Capture Mode

```env
# 'all' (default) - Capture all logs automatically
# 'channel' - Only capture logs sent to the logscope channel
LOGSCOPE_CAPTURE=all
```

### Write Mode (Performance)

```env
# 'batch' (default) - Buffer logs, write after response
# 'sync' - Write immediately (simple, but slower)
# 'queue' - Queue each log entry (best for high-traffic)
LOGSCOPE_WRITE_MODE=batch

# Queue settings (when using 'queue' mode)
LOGSCOPE_QUEUE=default
LOGSCOPE_QUEUE_CONNECTION=
```

### Retention

```env
LOGSCOPE_RETENTION_ENABLED=true
LOGSCOPE_RETENTION_DAYS=30
```

> **Note:** Retention requires scheduling `logscope:prune` - see [Schedule Pruning](#schedule-pruning).

### Features

```env
LOGSCOPE_FEATURE_STATUS=true       # Enable status workflow
LOGSCOPE_FEATURE_NOTES=true        # Add notes to logs
```

### Noise Reduction

```env
# Filter out noisy logs (both enabled by default)
LOGSCOPE_IGNORE_DEPRECATIONS=true  # Skip "is deprecated" messages
LOGSCOPE_IGNORE_NULL_CHANNEL=true  # Skip logs without a channel
```

### JSON Viewer

Configure collapsible JSON behavior in `config/logscope.php`:

```php
'json_viewer' => [
    'collapse_threshold' => 5,  // Auto-collapse arrays/objects larger than this
    'auto_collapse_keys' => ['trace', 'stack_trace', 'stacktrace', 'backtrace'],
],
```

### Routes

```env
LOGSCOPE_ROUTES_ENABLED=true
LOGSCOPE_ROUTE_PREFIX=logscope
LOGSCOPE_DOMAIN=
```

Add middleware in config:

```php
'routes' => [
    'middleware' => ['web', 'auth'],
],
```

---

## 🚀 Usage

### Automatic Capture

All logs are captured automatically - no code changes needed:

```php
Log::info('User logged in', ['user_id' => 1]);
Log::channel('slack')->error('Payment failed');
Log::stack(['daily', 'slack'])->warning('Low inventory');
```

### Keyboard Shortcuts

| Key | Action |
|-----|--------|
| `j` / `k` | Navigate down / up |
| `Enter` | Open detail panel |
| `Esc` | Close panel |
| `/` | Focus search |
| `n` | Focus note field |
| `c` | Clear all filters |
| `d` | Toggle dark mode |
| `?` | Show keyboard help |

**Status shortcuts** (configurable, filter by status):
| `o` | Open | `i` | Investigating | `r` | Resolved | `x` | Ignored |

### Status Workflow

Logs have a status workflow: **Open** → **Investigating** → **Resolved** or **Ignored**.

Customize who changed the status:

```php
// In AppServiceProvider::boot()
use LogScope\LogScope;

LogScope::statusChangedBy(function ($request) {
    return $request->user()?->name;
});
```

#### Customize Statuses

Override built-in statuses or add new ones in `config/logscope.php`:

```php
'statuses' => [
    // Override built-in status
    'investigating' => [
        'label' => 'In Progress',
        'color' => 'blue',
    ],
    // Add custom statuses
    'waiting' => [
        'label' => 'Waiting for Customer',
        'color' => 'orange',
        'closed' => false,  // Shows in "Needs Attention"
    ],
    'duplicate' => [
        'label' => 'Duplicate',
        'color' => 'purple',
        'closed' => true,   // Hidden from "Needs Attention"
    ],
],
```

Available colors: `gray`, `yellow`, `green`, `slate`, `blue`, `red`, `orange`, `purple`

### Quick Filters

Configure one-click filters in `config/logscope.php`:

```php
'quick_filters' => [
    ['label' => 'Today', 'icon' => 'calendar', 'from' => 'today'],
    ['label' => 'Recent Errors', 'icon' => 'alert', 'levels' => ['error', 'critical'], 'from' => '-24 hours'],
    ['label' => 'Needs Attention', 'icon' => 'filter', 'statuses' => ['open', 'investigating']],
    ['label' => 'Resolved Today', 'icon' => 'filter', 'statuses' => ['resolved'], 'from' => 'today'],
],
```

Available options: `label`, `icon` (calendar/clock/alert/filter), `levels`, `statuses`, `from`, `to`

### Status Shortcuts

Each status has a default keyboard shortcut. Customize in `config/logscope.php`:

```php
'statuses' => [
    // Disable a shortcut
    'ignored' => ['shortcut' => null],
    // Custom status with shortcut
    'waiting' => ['label' => 'Waiting', 'color' => 'orange', 'shortcut' => 'w'],
],
```

### Authorization

LogScope uses a flexible auth system (checked in order):

**1. Custom Callback:**
```php
LogScope::auth(fn ($request) => $request->user()?->isAdmin());
```

**2. Gate:**
```php
Gate::define('viewLogScope', fn ($user) => $user->hasRole('admin'));
```

**3. Default:** Only accessible in `local` environment.

### Custom Context

Add custom data to every log entry (e.g., API token ID, tenant ID):

```php
// In AppServiceProvider::boot()
use LogScope\LogScope;

LogScope::captureContext(function ($request) {
    return [
        'token_id' => $request->user()?->currentAccessToken()?->id,
        'tenant_id' => $request->user()?->tenant_id,
    ];
});
```

This data is merged into the log's `context` field and appears in the JSON viewer.

### Artisan Commands

```bash
# Import existing log files (one-time migration)
php artisan logscope:import
php artisan logscope:import storage/logs/laravel.log --days=7

# Prune old logs
php artisan logscope:prune
php artisan logscope:prune --dry-run
php artisan logscope:prune --days=14
```

> **Note:** The import command is a one-time migration for existing log files. After setup, new logs are captured automatically.

---

## 🏭 Production Deployment

### Recommended Settings

```env
LOGSCOPE_WRITE_MODE=batch
LOGSCOPE_RETENTION_DAYS=14
LOG_LEVEL=info
```

### Schedule Pruning

```php
// Laravel 11+ (routes/console.php)
Schedule::command('logscope:prune')->daily();

// Laravel 10 (app/Console/Kernel.php)
$schedule->command('logscope:prune')->daily();
```

### High-Traffic Apps

For thousands of requests/day:

1. Use queue mode with a dedicated queue:
   ```env
   LOGSCOPE_WRITE_MODE=queue
   LOGSCOPE_QUEUE=logs
   ```

2. Run a separate queue worker:
   ```bash
   php artisan queue:work --queue=logs
   ```

3. Consider shorter retention (7 days).

---

## 🎨 Customization

### Publishing Assets

```bash
php artisan vendor:publish --tag=logscope-config
php artisan vendor:publish --tag=logscope-migrations
php artisan vendor:publish --tag=logscope-views
```

### All Environment Variables

```env
# Capture & Performance
LOGSCOPE_CAPTURE=all
LOGSCOPE_WRITE_MODE=batch
LOGSCOPE_QUEUE=default
LOGSCOPE_QUEUE_CONNECTION=

# Features
LOGSCOPE_FEATURE_RESOLVABLE=true
LOGSCOPE_FEATURE_NOTES=true
LOGSCOPE_IGNORE_DEPRECATIONS=true
LOGSCOPE_IGNORE_NULL_CHANNEL=true

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

# Search
LOGSCOPE_SEARCH_DRIVER=database
```

---

## 🤝 Contributing

Contributions are welcome! Please open an issue or submit a pull request.

---

## 📄 License

MIT License. See [LICENSE](LICENSE) for details.
