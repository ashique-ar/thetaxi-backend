<?php

use Illuminate\Support\Facades\Route;
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
