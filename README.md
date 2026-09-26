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

**[v2.3.0](https://github.com/AhmedMerza/laravel-logscope/releases/tag/v2.3.0):** an opt-in JSON API for native clients, authenticated with Sanctum tokens ([docs/api.md](docs/api.md)); retention by level, so debug logs can expire before errors do; and a log whose context holds `INF` or `NAN` keeps its context instead of losing it.

**Upgrading:** no migration. Supported Laravel versions are now 11–13. Full notes are in the [changelog](https://github.com/AhmedMerza/laravel-logscope/blob/master/CHANGELOG.md).

---

## ✨ Features

- **Captures everything, no setup.** Every log from every channel, each with its trace ID, user ID, IP, URL and user agent.
- **Search and filters.** `field:value` search, regex and a NOT toggle. Include or exclude by level, channel, HTTP method and date range, with one-click quick filters.
- **Issues and triage.** Repeated entries are grouped into one issue with an occurrence count. Mark an issue open, investigating, resolved or ignored, and add notes. A resolved issue reopens if it happens again.
- **Readable context.** Collapsible, syntax-highlighted JSON. Request and model objects are expanded, and passwords, tokens and other sensitive keys are redacted.
- **Keyboard-first.** Vim-style navigation, and one key to change an issue's status and move to the next. Dark mode included.
- **Shareable.** Filters live in the URL, and every entry has its own link.
- **Fast on big tables.** Keyset pagination, writes batched until after the response, and indexes on the common filters.

---

## 📋 Requirements

- PHP 8.2+ (8.3+ for Laravel 13)
- Laravel 11, 12 or 13
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
- **[JSON API](https://github.com/AhmedMerza/laravel-logscope/blob/master/docs/api.md)** — the opt-in, token-authenticated v1 API for native clients: setup with Sanctum, access, and every endpoint.
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
