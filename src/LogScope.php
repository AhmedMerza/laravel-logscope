<?php

declare(strict_types=1);

namespace LogScope;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class LogScope
{
    /**
     * The callback that should be used to authenticate LogScope users.
     *
     * @var (Closure(Request): bool)|null
     */
    public static ?Closure $authUsing = null;

    /**
     * The callback that should be used to get the "resolved by" identifier.
     *
     * @var (Closure(Request): ?string)|null
     */
    public static ?Closure $resolvedByUsing = null;

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
     * Register the callback used to determine "resolved by" identifier.
     *
     * This allows you to customize how the resolver is identified:
     *
     * ```php
     * // In AppServiceProvider::boot()
     * LogScope::resolvedBy(function ($request) {
     *     return $request->user()?->full_name;
     * });
     * ```
     *
     * @param  Closure(Request): ?string  $callback
     */
    public static function resolvedBy(Closure $callback): void
    {
        static::$resolvedByUsing = $callback;
    }

    /**
     * Get the "resolved by" identifier for the given request.
     *
     * Resolution priority:
     * 1. Custom callback set via LogScope::resolvedBy()
     * 2. Default: user name or email (if authenticated)
     */
    public static function getResolvedBy(Request $request): ?string
    {
        if (static::$resolvedByUsing !== null) {
            return (static::$resolvedByUsing)($request);
        }

        return $request->user()?->name
            ?? $request->user()?->email;
    }

    /**
     * Reset the resolvedBy callback (useful for testing).
     */
    public static function resetResolvedBy(): void
    {
        static::$resolvedByUsing = null;
    }
}
