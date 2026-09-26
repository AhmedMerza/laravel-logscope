<?php

use Illuminate\Support\Facades\Route;
use LogScope\Http\Controllers\LogController;
use LogScope\Http\Controllers\LogGroupController;
use LogScope\Http\Middleware\Authorize;
use LogScope\Http\Middleware\ForceJson;

// Token-authenticated v1 API for native clients (#64). Read endpoints plus
// triage; delete, clear, bulk status and notes stay in the web UI for now.
Route::group([
    'prefix' => config('logscope.routes.api.prefix', 'api/logscope/v1'),
    'middleware' => [
        ForceJson::class,
        ...config('logscope.routes.api.middleware', ['api', 'auth:sanctum']),
        Authorize::class,
    ],
    'domain' => config('logscope.routes.domain'),
    'as' => 'logscope.api.v1.',
], function () {
    Route::get('/config', [LogController::class, 'config'])->name('config');

    Route::get('/logs', [LogController::class, 'logs'])->name('logs');
    Route::get('/logs/{id}', [LogController::class, 'show'])->name('logs.show');
    Route::patch('/logs/{id}/status', [LogController::class, 'setStatus'])->name('logs.set-status');
    Route::get('/stats', [LogController::class, 'stats'])->name('stats');

    Route::get('/groups', [LogGroupController::class, 'index'])->name('groups');
    Route::get('/groups/{id}', [LogGroupController::class, 'show'])->name('groups.show');
    Route::get('/groups/{id}/entries', [LogGroupController::class, 'entries'])->name('groups.entries');
    Route::patch('/groups/{id}/status', [LogGroupController::class, 'setStatus'])->name('groups.set-status');
});
