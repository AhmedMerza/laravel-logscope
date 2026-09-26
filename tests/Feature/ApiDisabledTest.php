<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

it('registers no v1 API routes unless the host enables them', function () {
    expect(config('logscope.routes.api.enabled'))->toBeFalse()
        ->and(Route::has('logscope.api.v1.logs'))->toBeFalse()
        ->and(Route::has('logscope.logs'))->toBeTrue();
});
