# LogScope

[![Latest Version](https://img.shields.io/packagist/v/ahmedmerza/logscope.svg?style=flat-square)](https://packagist.org/packages/ahmedmerza/logscope)
[![License](https://img.shields.io/packagist/l/ahmedmerza/logscope.svg?style=flat-square)](https://packagist.org/packages/ahmedmerza/logscope)
[![PHP Version](https://img.shields.io/packagist/php-v/ahmedmerza/logscope.svg?style=flat-square)](https://packagist.org/packages/ahmedmerza/logscope)

A log viewer for Laravel that stores your logs in your own database, so you can search, filter and triage them from the browser.

Search by field (`level:error`, `user_id:42`) or across everything. Repeated errors are grouped into one issue with a count, and when you mark an issue resolved it stays resolved, unless it happens again. LogScope is designed to be left on in production, and it works alongside Telescope in development or an exception tracker like Sentry.

![LogScope demo: structured search, NOT filter, keyboard triage, dark mode](https://raw.githubusercontent.com/AhmedMerza/laravel-logscope/master/art/logscope-demo.gif)

## Quick Start

```bash
composer require ahmedmerza/logscope
php artisan logscope:install
php artisan migrate
```

Visit `/logscope` in your browser. That's it!

---

## What's New

**[v2.2.0](https://github.com/AhmedMerza/laravel-logscope/releases/tag/v2.2.0):** repeated entries are now grouped into issues, and a resolved issue reopens if it fires again. Log writes no longer happen inside your transactions.

**Upgrading:** run `php artisan migrate`, then `php artisan logscope:backfill-fingerprints`. Full notes, including one behaviour change to `sensitive_keys`, are in the [changelog](https://github.com/AhmedMerza/laravel-logscope/blob/master/CHANGELOG.md).

---

## Table of Contents

- [Features](#-features)
- [Requirements](#-requirements)
- [When to Use LogScope](#-when-to-use-logscope)
- [Installation](#-installation)
- [Authorization](#-authorization)
- [Documentation](#-documentation)
- [Extensions](#%EF%B8%8F-extensions)
- [Contributing](#-contributing)
- [License](#-license)

---

## ✨ Features

| Feature | Description |
|---------|-------------|
| **Zero-Config Capture** | Automatically captures ALL logs from ALL channels |
| **Request Context** | Trace ID, user ID, IP, URL, and user agent for every log |
| **Advanced Search** | Search syntax (`field:value`), regex support, NOT toggle |
| **Smart Filters** | Include/exclude by level, channel, HTTP method, date range |
| **Active Filters Bar** | See all active filters at a glance, clear individually |
| **Channel Search** | Search and filter channels when you have many |
| **JSON Viewer** | Syntax-highlighted, collapsible JSON with copy support |
| **Smart Context** | Auto-expand Request/Model objects, redact sensitive data |
| **Issue Grouping** | Repeated entries roll up into one issue with an occurrence count |
| **Status Workflow** | Mark issues open, investigating, resolved, or ignored; resolved issues reopen if they recur |
| **Log Notes** | Add investigation notes to any log entry |
| **Quick Filters** | One-click filters for common queries |
| **Keyboard Shortcuts** | 14 shortcuts for navigation, status changes, and actions |
| **Dark Mode** | Full dark mode support with persistence |
| **Shareable URLs** | Current filters reflected in URL for sharing |
| **Deep Linking** | Link directly to specific log entries |
| **Performance** | Keyset pagination, batch writes, query optimization, proper indexing |

---

## 📋 Requirements

- PHP 8.2+
- Laravel 11+
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

## 🔒 Authorization

Outside the `local` environment, `/logscope` is closed until you allow access. Checked in order:

**1. Custom Callback:**
```php
LogScope::auth(fn ($request) => $request->user()?->isAdmin());
```

**2. Gate:**
```php
Gate::define('viewLogScope', fn ($user) => $user->hasRole('admin'));
```

**3. Default:** Only accessible in `local` environment.

---

## 📚 Documentation

- **[Configuration](https://github.com/AhmedMerza/laravel-logscope/blob/master/docs/configuration.md)** — capture and write modes, writes during transactions, retention, noise filters, routes, theme, redaction of sensitive keys and headers, and every environment variable.
- **[Usage](https://github.com/AhmedMerza/laravel-logscope/blob/master/docs/usage.md)** — search syntax, the status workflow and custom statuses, quick filters, keyboard shortcuts, custom context, and the Artisan commands.
- **[Production Deployment](https://github.com/AhmedMerza/laravel-logscope/blob/master/docs/production.md)** — recommended settings, scheduling `logscope:prune`, and high-traffic apps.
- **[Known Limitations](https://github.com/AhmedMerza/laravel-logscope/blob/master/docs/known-limitations.md)** — the few kinds of log that bypass Laravel's logger, and what to do about each.

---

## 🛡️ Extensions

### Watchtower

Block malicious IPs and sync the blacklist across all your environments automatically. Blocking is done from Watchtower's own dashboard — LogScope shows you the traffic, Watchtower acts on it. (Previously named `ahmedmerza/logscope-guard`.)

```bash
composer require ahmedmerza/watchtower
php artisan watchtower:install
```

[View Watchtower →](https://github.com/AhmedMerza/laravel-watchtower)

---

## 🤝 Contributing

Contributions are welcome! Please open an issue or submit a pull request.

---

## 📄 License

MIT License. See [LICENSE](https://github.com/AhmedMerza/laravel-logscope/blob/master/LICENSE) for details.
