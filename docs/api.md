# JSON API (v1)

LogScope can expose a small, token-authenticated JSON API for native clients
such as a mobile app. It is **off by default** and serves the same data as the
web UI: read logs, stats and groups, and set a status. It deliberately has **no
destructive endpoints** — deleting and clearing stay in the web UI.

## Enabling it

1. Install [Laravel Sanctum](https://laravel.com/docs/sanctum) if your app does
   not have it yet, and add the `HasApiTokens` trait to your `User` model:

   ```bash
   composer require laravel/sanctum
   php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
   php artisan migrate
   ```

2. Turn the API on:

   ```env
   LOGSCOPE_API_ENABLED=true
   ```

3. Issue a token for a user who is allowed into LogScope:

   ```php
   $token = $user->createToken('logscope-phone')->plainTextToken;
   ```

   Send it as `Authorization: Bearer <token>` on every request.

## Access

Requests pass through `auth:sanctum` first, then the **same access check as the
web UI**: your `LogScope::auth()` callback if you set one, otherwise the
`viewLogScope` gate, which receives the token's user. There is no separate
permission for the API.

| Situation | Response |
| --- | --- |
| Missing or invalid token | `401 {"message": "Unauthenticated."}` |
| Token user fails the access check | `403 {"message": "Unauthorized access to LogScope."}` |

Every response is JSON, whatever `Accept` header the client sends — the API
never redirects to a login page.

## Endpoints

All paths are relative to the prefix, `api/logscope/v1` by default.

| Method | Path | Returns |
| --- | --- | --- |
| `GET` | `/config` | Levels, channels, statuses, quick filters, enabled features and theme — what a client needs to build its filters |
| `GET` | `/logs` | Entries, newest first, cursor-paginated (`meta.next_cursor`) |
| `GET` | `/logs/{id}` | One entry with its full context |
| `PATCH` | `/logs/{id}/status` | Sets an entry's status. Body: `status`, optional `note` |
| `GET` | `/stats` | Totals by level, today and this hour |
| `GET` | `/groups` | Groups of repeated entries, cursor-paginated |
| `GET` | `/groups/{id}` | One group |
| `GET` | `/groups/{id}/entries` | The entries in a group, cursor-paginated |
| `PATCH` | `/groups/{id}/status` | Sets a group's status. Body: `status`, optional `note` |

`/logs` takes the same filters as the web UI: `search`, `levels[]`,
`exclude_levels[]`, `channels[]`, `exclude_channels[]`, `statuses[]`,
`http_method`, `url`, `ip_address`, `user_id`, `trace_id`, `from`, `to`,
`regex`, `cursor` and `per_page`. `/groups` takes `search`, the level, channel
and status filters, `cursor` and `per_page`.

Status changes are recorded against the token's user, as they are in the web
UI. **A group's status does not cascade to its entries** — each keeps its own.
Status endpoints return `403` when the status feature is disabled and `422`
for a status that is not configured.

## Configuration

```php
// config/logscope.php
'routes' => [
    'api' => [
        'enabled' => env('LOGSCOPE_API_ENABLED', false),
        'prefix' => env('LOGSCOPE_API_PREFIX', 'api/logscope/v1'),
        'middleware' => ['api', 'auth:sanctum'],
    ],
],
```

To authenticate some other way, replace `auth:sanctum` with your own guard
middleware. The access check and JSON responses are added on top of whatever
you list here. `routes.domain` applies to the API as well.
