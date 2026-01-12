<?php

use Illuminate\Support\Facades\Route;
use LogScope\Http\Controllers\LogController;
use LogScope\Http\Controllers\PresetController;

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

    // Presets API
    Route::get('/api/presets', [PresetController::class, 'index'])->name('presets.index');
    Route::post('/api/presets', [PresetController::class, 'store'])->name('presets.store');
    Route::put('/api/presets/{id}', [PresetController::class, 'update'])->name('presets.update');
    Route::delete('/api/presets/{id}', [PresetController::class, 'destroy'])->name('presets.destroy');
    Route::post('/api/presets/{id}/default', [PresetController::class, 'setDefault'])->name('presets.default');
    Route::post('/api/presets/reorder', [PresetController::class, 'reorder'])->name('presets.reorder');
});
