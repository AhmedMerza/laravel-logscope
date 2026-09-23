<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use LogScope\LogScope;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->artisan('migrate', ['--path' => __DIR__.'/../../database/migrations']);
    LogScope::auth(fn () => true);
});

afterEach(function () {
    LogScope::resetAuth();
});

it('renders the dashboard with the grouped view controls', function () {
    $response = $this->get('/logscope');

    $response->assertOk();
    $response->assertSee('Grouped', false);
    $response->assertSee('All entries', false);
    // The grouped table and issue panel partials are included.
    $response->assertSee("viewMode === 'grouped'", false);
    $response->assertSee('selectedGroup && !selectedLog', false);
});

it('passes the grouping config and route to the front end', function () {
    $response = $this->get('/logscope');

    $response->assertSee('"enabled":true', false);
    $response->assertSee('/logscope/api/groups', false);
});

it('defaults to the flat list when grouping is disabled', function () {
    config(['logscope.grouping.enabled' => false]);

    $this->get('/logscope')->assertSee('"enabled":false', false);
});
