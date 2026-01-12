<?php

use Illuminate\Support\Facades\Route;

Route::group([
    'prefix' => config('logscope.routes.prefix', 'logscope'),
    'middleware' => config('logscope.routes.middleware', ['web']),
    'domain' => config('logscope.routes.domain'),
], function () {
    // TODO: Add routes for log viewer interface
    // Route::get('/', [LogController::class, 'index'])->name('logscope.index');
    // Route::get('/api/logs', [LogController::class, 'logs'])->name('logscope.logs');
    // Route::get('/api/logs/{log}', [LogController::class, 'show'])->name('logscope.show');
    // Route::delete('/api/logs/{log}', [LogController::class, 'destroy'])->name('logscope.destroy');
    // Route::post('/api/logs/clear', [LogController::class, 'clear'])->name('logscope.clear');
    // Route::resource('/api/presets', PresetController::class)->names('logscope.presets');
});
