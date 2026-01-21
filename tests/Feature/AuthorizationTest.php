<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use LogScope\LogScope;

beforeEach(function () {
    LogScope::resetAuth();
});

describe('LogScope::check()', function () {
    it('allows access in local environment by default', function () {
        app()->detectEnvironment(fn () => 'local');

        $request = Request::create('/logscope');

        expect(LogScope::check($request))->toBeTrue();
    });

    it('blocks access in production environment by default', function () {
        app()->detectEnvironment(fn () => 'production');

        $request = Request::create('/logscope');

        expect(LogScope::check($request))->toBeFalse();
    });

    it('blocks access in staging environment by default', function () {
        app()->detectEnvironment(fn () => 'staging');

        $request = Request::create('/logscope');

        expect(LogScope::check($request))->toBeFalse();
    });
});

describe('LogScope::auth() callback', function () {
    it('uses custom callback when set', function () {
        app()->detectEnvironment(fn () => 'production');

        LogScope::auth(fn ($request) => true);

        $request = Request::create('/logscope');

        expect(LogScope::check($request))->toBeTrue();
    });

    it('can block access with custom callback', function () {
        app()->detectEnvironment(fn () => 'local');

        LogScope::auth(fn ($request) => false);

        $request = Request::create('/logscope');

        expect(LogScope::check($request))->toBeFalse();
    });

    it('receives the request object in callback', function () {
        $receivedRequest = null;

        LogScope::auth(function ($request) use (&$receivedRequest) {
            $receivedRequest = $request;

            return true;
        });

        $request = Request::create('/logscope', 'GET', [], [], [], ['REMOTE_ADDR' => '192.168.1.100']);
        LogScope::check($request);

        expect($receivedRequest)->toBe($request);
        expect($receivedRequest->ip())->toBe('192.168.1.100');
    });

    it('can check user in callback', function () {
        app()->detectEnvironment(fn () => 'production');

        LogScope::auth(function ($request) {
            return $request->user()?->email === 'admin@example.com';
        });

        // Without user
        $request = Request::create('/logscope');
        expect(LogScope::check($request))->toBeFalse();

        // With admin user
        $admin = new stdClass;
        $admin->email = 'admin@example.com';
        $request->setUserResolver(fn () => $admin);
        expect(LogScope::check($request))->toBeTrue();

        // With regular user
        $user = new stdClass;
        $user->email = 'user@example.com';
        $request->setUserResolver(fn () => $user);
        expect(LogScope::check($request))->toBeFalse();
    });
});

describe('Gate-based authorization', function () {
    it('uses viewLogScope gate when defined', function () {
        app()->detectEnvironment(fn () => 'production');

        Gate::define('viewLogScope', fn ($user) => $user->isAdmin);

        $admin = new stdClass;
        $admin->isAdmin = true;

        $request = Request::create('/logscope');
        $request->setUserResolver(fn () => $admin);

        expect(LogScope::check($request))->toBeTrue();
    });

    it('blocks when gate denies', function () {
        app()->detectEnvironment(fn () => 'production');

        Gate::define('viewLogScope', fn ($user) => $user->isAdmin);

        $user = new stdClass;
        $user->isAdmin = false;

        $request = Request::create('/logscope');
        $request->setUserResolver(fn () => $user);

        expect(LogScope::check($request))->toBeFalse();
    });

    it('blocks when no user for gate check', function () {
        app()->detectEnvironment(fn () => 'production');

        Gate::define('viewLogScope', fn ($user) => true);

        $request = Request::create('/logscope');

        expect(LogScope::check($request))->toBeFalse();
    });

    it('callback takes priority over gate', function () {
        app()->detectEnvironment(fn () => 'production');

        Gate::define('viewLogScope', fn ($user) => false);
        LogScope::auth(fn ($request) => true);

        $user = new stdClass;
        $request = Request::create('/logscope');
        $request->setUserResolver(fn () => $user);

        expect(LogScope::check($request))->toBeTrue();
    });
});

describe('LogScope::resetAuth()', function () {
    it('clears the custom callback', function () {
        app()->detectEnvironment(fn () => 'production');

        LogScope::auth(fn ($request) => true);
        expect(LogScope::check(Request::create('/logscope')))->toBeTrue();

        LogScope::resetAuth();
        expect(LogScope::check(Request::create('/logscope')))->toBeFalse();
    });
});
