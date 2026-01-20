<?php

use Illuminate\Support\Facades\Route;
use LogScope\Http\Controllers\LogController;

Route::group([
    'prefix' => config('logscope.routes.prefix', 'logscope'),
    'middleware' => config('logscope.routes.middleware', ['web']),
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
});
