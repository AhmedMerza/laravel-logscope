<?php

it('registers the service provider', function () {
    expect(app()->getProviders(\LogScope\LogScopeServiceProvider::class))
        ->not->toBeEmpty();
});

it('loads the configuration', function () {
    expect(config('logscope'))
        ->toBeArray()
        ->toHaveKeys(['table', 'retention', 'routes', 'migrations', 'limits', 'search', 'pagination']);
});
