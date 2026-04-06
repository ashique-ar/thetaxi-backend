<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Driver\Mobile\AuthController;
use App\Http\Controllers\Api\Driver\Mobile\StatusController;
use App\Http\Controllers\Api\Driver\Mobile\HeartbeatController;
use App\Http\Controllers\Api\Driver\Mobile\LocationController;
use App\Http\Controllers\Api\Driver\Mobile\SessionController;
use App\Http\Controllers\Api\Driver\Mobile\AssignmentController;
use App\Http\Controllers\Api\Driver\Mobile\DeviceController;
use App\Http\Controllers\Api\Driver\Mobile\TripController;
use App\Http\Controllers\Api\Driver\Mobile\EarningsController;

/*
|--------------------------------------------------------------------------
| Driver Mobile API Routes
|--------------------------------------------------------------------------
|
| These routes are loaded by the RouteServiceProvider and are prefixed
| with /api/driver. They provide endpoints specifically for the driver
| mobile application using Passport OAuth2 authentication.
|
*/

// Public authentication routes (no auth required)
Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
});

// Protected routes (Passport authentication required)
Route::middleware(['auth:api', 'ensure.driver'])->group(function () {
    
    // Authentication routes
    Route::prefix('auth')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('profile', [AuthController::class, 'profile']);
        Route::post('refresh', [AuthController::class, 'refresh']); // Token refresh endpoint
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
    
    // Device management routes
    Route::prefix('devices')->group(function () {
        Route::get('', [DeviceController::class, 'index']);
        Route::get('current', [DeviceController::class, 'current']);
        Route::put('', [DeviceController::class, 'update']);
        Route::post('push-token', [DeviceController::class, 'updatePushToken']);
        Route::post('{deviceUuid}/deactivate', [DeviceController::class, 'deactivate']);
        Route::delete('{deviceUuid}', [DeviceController::class, 'destroy']);
    });
    
    // Assignment routes
    Route::prefix('assignments')->group(function () {
        Route::get('', [AssignmentController::class, 'index']);
        Route::get('current', [AssignmentController::class, 'current']);
        Route::post('{id}/accept', [AssignmentController::class, 'accept']);
        Route::post('{id}/decline', [AssignmentController::class, 'decline']);
        // Canonical assignment lifecycle (single-track)
        Route::get('{id}/status', [TripController::class, 'statusForAssignment']);
        Route::post('{id}/arrived', [TripController::class, 'pickupArrivedForAssignment']);
        Route::post('{id}/start', [TripController::class, 'startTripForAssignment']);
        Route::post('{id}/complete', [TripController::class, 'endTripForAssignment']);
    });

    // Hire history route
    Route::get('hires', [AssignmentController::class, 'hires']);

    // Earnings routes
    Route::prefix('earnings')->group(function () {
        Route::get('summary', [EarningsController::class, 'summary']);
        Route::get('daily', [EarningsController::class, 'daily']);
        Route::get('range', [EarningsController::class, 'range']);
    });
});
