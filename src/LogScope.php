<?php

declare(strict_types=1);

namespace LogScope;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;

class LogScope
{
    /**
     * The callback that should be used to authenticate LogScope users.
     *
     * @var (Closure(Request): bool)|null
     */
    public static ?Closure $authUsing = null;

    /**
     * The callback that should be used to get the "status changed by" identifier.
     *
     * @var (Closure(Request): ?string)|null
     */
    public static ?Closure $statusChangedByUsing = null;

    /**
     * The callback that should be used to capture additional context.
     *
     * @var (Closure(Request): array<string, mixed>)|null
     */
    public static ?Closure $captureContextUsing = null;

    /**
     * Register the callback used to authorize access to LogScope.
     *
     * This allows you to define custom authorization logic:
     *
     * ```php
     * // In AppServiceProvider::boot()
     * LogScope::auth(function ($request) {
     *     return $request->user()?->isAdmin();
     * });
     * ```
     *
     * @param  Closure(Request): bool  $callback
     */
    public static function auth(Closure $callback): void
    {
        static::$authUsing = $callback;
    }

    /**
     * Check if the given request is authorized to access LogScope.
     *
     * Authorization priority:
     * 1. Custom callback set via LogScope::auth()
     * 2. Gate named 'viewLogScope' (if defined)
     * 3. Default: only allow in local environment
     */
    public static function check(Request $request): bool
    {
        // Priority 1: Custom auth callback
        if (static::$authUsing !== null) {
            return (static::$authUsing)($request);
        }

        // Priority 2: Gate (if defined)
        if (Gate::has('viewLogScope')) {
            // Gate requires a user, so check if authenticated first
            $user = $request->user();
            if ($user === null) {
                return false;
            }

            return Gate::forUser($user)->check('viewLogScope');
        }

        // Priority 3: Default - only in local environment
        return app()->environment('local');
    }

    /**
     * Reset the auth callback (useful for testing).
     */
    public static function resetAuth(): void
    {
        static::$authUsing = null;
    }

    /**
     * Register the callback used to determine "status changed by" identifier.
     *
     * This allows you to customize how the user is identified when changing status:
     *
     * ```php
     * // In AppServiceProvider::boot()
     * LogScope::statusChangedBy(function ($request) {
     *     return $request->user()?->full_name;
     * });
     * ```
     *
     * @param  Closure(Request): ?string  $callback
     */
    public static function statusChangedBy(Closure $callback): void
    {
        static::$statusChangedByUsing = $callback;
    }

    /**
     * Get the "status changed by" identifier for the given request.
     *
     * Resolution priority:
     * 1. Custom callback set via LogScope::statusChangedBy()
     * 2. Default: user name or email (if authenticated)
     */
    public static function getStatusChangedBy(Request $request): ?string
    {
        if (static::$statusChangedByUsing !== null) {
            return (static::$statusChangedByUsing)($request);
        }

        return $request->user()?->name
            ?? $request->user()?->email;
    }

    /**
     * Reset the statusChangedBy callback (useful for testing).
     */
    public static function resetStatusChangedBy(): void
    {
        static::$statusChangedByUsing = null;
    }

    /**
     * @deprecated Use statusChangedBy() instead.
     */
    public static function resolvedBy(Closure $callback): void
    {
        static::statusChangedBy($callback);
    }

    /**
     * @deprecated Use getStatusChangedBy() instead.
     */
    public static function getResolvedBy(Request $request): ?string
    {
        return static::getStatusChangedBy($request);
    }

    /**
     * @deprecated Use resetStatusChangedBy() instead.
     */
    public static function resetResolvedBy(): void
    {
        static::resetStatusChangedBy();
    }

    /**
     * Register the callback used to capture additional context.
     *
     * This allows you to add custom data to every log entry:
     *
     * ```php
     * // In AppServiceProvider::boot()
     * LogScope::captureContext(function ($request) {
     *     return [
     *         'token_id' => $request->user()?->currentAccessToken()?->id,
     *         'tenant_id' => $request->user()?->tenant_id,
     *     ];
     * });
     * ```
     *
     * @param  Closure(Request): array<string, mixed>  $callback
     */
    public static function captureContext(Closure $callback): void
    {
        static::$captureContextUsing = $callback;
    }

    /**
     * Get the additional captured context for the given request.
     *
     * @return array<string, mixed>
     */
    public static function getCapturedContext(Request $request): array
    {
        if (static::$captureContextUsing !== null) {
            return (static::$captureContextUsing)($request) ?? [];
        }

        return [];
    }

    /**
     * Reset the captureContext callback (useful for testing).
     */
    public static function resetCaptureContext(): void
    {
        static::$captureContextUsing = null;
    }

    /**
     * Get the CSS for LogScope (inlined).
     */
    public static function css(): HtmlString
    {
        $css = file_get_contents(__DIR__.'/../dist/app.css');

        return new HtmlString("<style>{$css}</style>");
    }

    /**
     * Get the JavaScript for LogScope (inlined).
     */
    public static function js(): HtmlString
    {
        $collapse = file_get_contents(__DIR__.'/../dist/alpine-collapse.min.js');
        $alpine = file_get_contents(__DIR__.'/../dist/alpine.min.js');

        return new HtmlString("<script>{$collapse}{$alpine}</script>");
    }
}
