<?php

use Illuminate\Http\Request;
use LogScope\LogScope;

beforeEach(function () {
    LogScope::resetCaptureContext();
    LogScope::resetStatusChangedBy();
});

afterEach(function () {
    LogScope::resetCaptureContext();
    LogScope::resetStatusChangedBy();
});

it('returns empty array when no capture context callback is set', function () {
    $request = Request::create('/test');

    $context = LogScope::getCapturedContext($request);

    expect($context)->toBe([]);
});

it('captures custom context via callback', function () {
    LogScope::captureContext(function ($request) {
        return [
            'custom_key' => 'custom_value',
            'tenant_id' => 42,
        ];
    });

    $request = Request::create('/test');
    $context = LogScope::getCapturedContext($request);

    expect($context)->toBe([
        'custom_key' => 'custom_value',
        'tenant_id' => 42,
    ]);
});

it('passes request to capture context callback', function () {
    LogScope::captureContext(function ($request) {
        return [
            'url' => $request->url(),
            'method' => $request->method(),
        ];
    });

    $request = Request::create('/api/users', 'POST');
    $context = LogScope::getCapturedContext($request);

    expect($context['url'])->toContain('/api/users');
    expect($context['method'])->toBe('POST');
});

it('handles null return from callback gracefully', function () {
    LogScope::captureContext(function ($request) {
        return null;
    });

    $request = Request::create('/test');
    $context = LogScope::getCapturedContext($request);

    expect($context)->toBe([]);
});

it('resets capture context callback', function () {
    LogScope::captureContext(function ($request) {
        return ['key' => 'value'];
    });

    LogScope::resetCaptureContext();

    $request = Request::create('/test');
    $context = LogScope::getCapturedContext($request);

    expect($context)->toBe([]);
});

it('returns null when no statusChangedBy callback is set and no user', function () {
    $request = Request::create('/test');

    $changedBy = LogScope::getStatusChangedBy($request);

    expect($changedBy)->toBeNull();
});

it('uses custom statusChangedBy callback', function () {
    LogScope::statusChangedBy(function ($request) {
        return 'Custom User Name';
    });

    $request = Request::create('/test');
    $changedBy = LogScope::getStatusChangedBy($request);

    expect($changedBy)->toBe('Custom User Name');
});

it('resets statusChangedBy callback', function () {
    LogScope::statusChangedBy(function ($request) {
        return 'Custom User';
    });

    LogScope::resetStatusChangedBy();

    $request = Request::create('/test');
    $changedBy = LogScope::getStatusChangedBy($request);

    expect($changedBy)->toBeNull();
});

it('supports deprecated resolvedBy alias', function () {
    LogScope::resolvedBy(function ($request) {
        return 'Legacy User';
    });

    $request = Request::create('/test');
    $changedBy = LogScope::getResolvedBy($request);

    expect($changedBy)->toBe('Legacy User');
});
