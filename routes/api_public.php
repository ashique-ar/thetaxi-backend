<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Driver\Mobile\AppSettingsController;
use App\Http\Controllers\Api\Public\MeterController;

/*
|--------------------------------------------------------------------------
| Public API Routes
|--------------------------------------------------------------------------
|
| These routes are loaded by the RouteServiceProvider and are prefixed
| with /api/public. They provide endpoints for guest/unauthenticated
| access including meter functionality. All routes require a Device UUID
| header for tracking purposes.
|
*/

// Backward-compatible driver-app version endpoint documented for supported clients.
// Keep this outside device.uuid: it has the same public contract as /api/driver/version-check.
Route::post('driver-mobile/version-check', [AppSettingsController::class, 'versionCheck']);

// All public routes require device.uuid middleware
Route::middleware(['device.uuid'])->group(function () {
    
    // Meter routes for guest users
    Route::prefix('meter')->group(function () {
        Route::post('start', [MeterController::class, 'start']);
        Route::post('stop', [MeterController::class, 'stop']);
        Route::post('location', [MeterController::class, 'updateLocation']);
        Route::get('estimate', [MeterController::class, 'estimate']);
    });
});
