# LogScope

A fast, database-backed log viewer for Laravel applications.

> **Status:** Early development - not yet ready for production use.

## Features

- **Database-backed storage** - Fast queries with proper indexing
- **Advanced filtering** - Filter by level, channel, environment, and date range
- **Full-text search** - Find what you need quickly (Scout integration optional)
- **Exclusion filters** - Hide noise, focus on what matters
- **Retention policies** - Auto-prune old logs after configurable period
- **Saved presets** - Save and reuse your filter combinations
- **Highly configurable** - Customize table names, retention periods, and more

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

- **Table names** - Customize the database table names
- **Retention policy** - Enable/disable auto-pruning and set retention days
- **Route configuration** - Customize prefix, middleware, and domain
- **Content limits** - Configure preview lengths and truncation thresholds
- **Search driver** - Choose between database or Scout for search

## Usage

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
LOGSCOPE_TABLE_ENTRIES=log_entries
LOGSCOPE_TABLE_PRESETS=filter_presets
LOGSCOPE_RETENTION_ENABLED=true
LOGSCOPE_RETENTION_DAYS=30
LOGSCOPE_ROUTES_ENABLED=true
LOGSCOPE_ROUTE_PREFIX=logscope
LOGSCOPE_SEARCH_DRIVER=database
```

## Contributing

Contributions are welcome! Please read the contributing guidelines first.

## License

MIT License. See [LICENSE](LICENSE) for details.
