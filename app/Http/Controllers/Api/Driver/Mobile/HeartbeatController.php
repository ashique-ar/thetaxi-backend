<?php

namespace App\Http\Controllers\Api\Driver\Mobile;

use App\Http\Controllers\Controller;
use App\Services\Driver\DriverAuthService;
use App\Services\Driver\SessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Driver Mobile Heartbeat Controller
 * 
 * Handles heartbeat signals from the driver mobile application.
 * Updates the driver's last_active_at timestamp to indicate they are still active.
 * 
 * @see Requirement 5.2
 */
class HeartbeatController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @param SessionService $sessionService
     * @param DriverAuthService $authService
     */
    public function __construct(
        private SessionService $sessionService,
        private DriverAuthService $authService
    ) {}

    /**
     * Process a heartbeat ping from the driver app.
     * 
     * Updates the driver's last_active_at timestamp to indicate they are still active.
     * This prevents the auto-offline job from marking them as offline.
     *
     * @param Request $request
     * @return JsonResponse
     * 
     * @see Requirement 5.2 - Update last_active_at on heartbeat
     */
    public function ping(Request $request): JsonResponse
    {
        try {
            $driver = $this->authService->getDriver($request->user());

            if (!$driver) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User is not registered as a driver',
                    'error_code' => 'HEARTBEAT_NOT_DRIVER'
                ], 403);
            }

            // Update heartbeat timestamp
            $this->sessionService->updateHeartbeat($driver);

            // Refresh driver to get updated timestamp
            $driver->refresh();

            return response()->json([
                'status' => 'success',
                'message' => 'Heartbeat received',
                'data' => [
                    'last_active_at' => $driver->last_active_at?->toIso8601String(),
                    'is_online' => $driver->is_online,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to process heartbeat',
                'error_code' => 'HEARTBEAT_FAILED',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
