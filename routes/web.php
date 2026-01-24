<?php

use Illuminate\Support\Facades\Route;
use LogScope\Http\Controllers\LogController;
use LogScope\Http\Middleware\Authorize;

Route::group([
    'prefix' => config('logscope.routes.prefix', 'logscope'),
    'middleware' => array_merge(
        config('logscope.routes.middleware', ['web']),
        [Authorize::class]
    ),
    'domain' => config('logscope.routes.domain'),
    'as' => 'logscope.',
], function () {
    // Main view
    Route::get('/', [LogController::class, 'index'])->name('index');

    // Log entries API
    Route::get('/api/logs', [LogController::class, 'logs'])->name('logs');
    Route::get('/api/logs/{id}', [LogController::class, 'show'])->name('logs.show');
    Route::delete('/api/logs/{id}', [LogController::class, 'destroy'])->name('logs.destroy');
    Route::post('/api/logs/delete-many', [LogController::class, 'destroyMany'])->name('logs.destroy-many');
    Route::post('/api/logs/clear', [LogController::class, 'clear'])->name('logs.clear');
    Route::get('/api/stats', [LogController::class, 'stats'])->name('stats');

    // Status API
    Route::patch('/api/logs/{id}/status', [LogController::class, 'setStatus'])->name('logs.set-status');
    Route::post('/api/logs/status-many', [LogController::class, 'setStatusMany'])->name('logs.set-status-many');

    // Notes API
    Route::patch('/api/logs/{id}/note', [LogController::class, 'updateNote'])->name('logs.update-note');
});
