<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogScope\Http\Middleware\Authorize;
use LogScope\LogScope;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    LogScope::resetAuth();
});

describe('Authorize middleware', function () {
    it('allows request when authorized', function () {
        app()->detectEnvironment(fn () => 'local');

        $middleware = new Authorize;
        $request = Request::create('/logscope');

        $response = $middleware->handle($request, fn ($req) => new Response('OK'));

        expect($response->getContent())->toBe('OK');
    });

    it('aborts with 403 when unauthorized', function () {
        app()->detectEnvironment(fn () => 'production');

        $middleware = new Authorize;
        $request = Request::create('/logscope');

        $middleware->handle($request, fn ($req) => new Response('OK'));
    })->throws(HttpException::class);

    it('returns 403 status code when unauthorized', function () {
        app()->detectEnvironment(fn () => 'production');

        $middleware = new Authorize;
        $request = Request::create('/logscope');

        try {
            $middleware->handle($request, fn ($req) => new Response('OK'));
        } catch (HttpException $e) {
            expect($e->getStatusCode())->toBe(403);
            expect($e->getMessage())->toBe('Unauthorized access to LogScope.');
        }
    });

    it('respects custom auth callback', function () {
        app()->detectEnvironment(fn () => 'production');

        LogScope::auth(fn ($request) => $request->header('X-Admin') === 'true');

        $middleware = new Authorize;

        // Without header - blocked
        $request = Request::create('/logscope');
        try {
            $middleware->handle($request, fn ($req) => new Response('OK'));
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            expect($e->getStatusCode())->toBe(403);
        }

        // With header - allowed
        $request = Request::create('/logscope');
        $request->headers->set('X-Admin', 'true');
        $response = $middleware->handle($request, fn ($req) => new Response('OK'));
        expect($response->getContent())->toBe('OK');
    });

    it('passes request to next middleware', function () {
        app()->detectEnvironment(fn () => 'local');

        $middleware = new Authorize;
        $request = Request::create('/logscope');
        $request->headers->set('X-Test', 'value');

        $passedRequest = null;
        $middleware->handle($request, function ($req) use (&$passedRequest) {
            $passedRequest = $req;

            return new Response('OK');
        });

        expect($passedRequest)->toBe($request);
        expect($passedRequest->header('X-Test'))->toBe('value');
    });
});
