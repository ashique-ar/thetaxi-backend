<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Driver\Mobile\AuthController;
use App\Http\Controllers\Api\Driver\Mobile\StatusController;
use App\Http\Controllers\Api\Driver\Mobile\HeartbeatController;
use App\Http\Controllers\Api\Driver\Mobile\LocationController;
use App\Http\Controllers\Api\Driver\Mobile\SessionController;
use App\Http\Controllers\Api\Driver\Mobile\AssignmentController;

/*
|--------------------------------------------------------------------------
| Driver Mobile API Routes
|--------------------------------------------------------------------------
|
| These routes are loaded by the RouteServiceProvider and are prefixed
| with /api/driver. They provide endpoints specifically for the driver
| mobile application using Sanctum authentication.
|
*/

// Public authentication routes (no auth required)
Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
});

// Protected routes (Sanctum authentication required)
Route::middleware(['auth:sanctum', 'ensure.driver'])->group(function () {
    
    // Authentication routes
    Route::prefix('auth')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('profile', [AuthController::class, 'profile']);
    });
    
    // Status management routes
    Route::prefix('status')->group(function () {
        Route::post('online', [StatusController::class, 'goOnline']);
        Route::post('offline', [StatusController::class, 'goOffline']);
        Route::get('', [StatusController::class, 'getStatus']);
    });
    
    // Heartbeat route
    Route::post('heartbeat', [HeartbeatController::class, 'ping']);
    
    // Location routes
    Route::prefix('location')->group(function () {
        Route::post('', [LocationController::class, 'update']);
        Route::get('history', [LocationController::class, 'history']);
    });
    
    // Session routes
    Route::prefix('sessions')->group(function () {
        Route::get('', [SessionController::class, 'index']);
        Route::get('{session}', [SessionController::class, 'show']);
    });
    
    // Assignment routes (placeholder for future implementation)
    Route::prefix('assignments')->group(function () {
        Route::get('', [AssignmentController::class, 'index']);
        Route::get('current', [AssignmentController::class, 'current']);
    });
});
