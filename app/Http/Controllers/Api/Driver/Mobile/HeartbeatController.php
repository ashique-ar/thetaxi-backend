<?php

namespace App\Http\Controllers\Api\Driver\Mobile;

use App\Enums\TripPhase;
use App\Http\Controllers\Controller;
use App\Models\DriverAssignment;
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

            // Check for active trip
            $activeAssignment = DriverAssignment::where('driver_id', $driver->id)
                ->whereIn('trip_phase', [
                    TripPhase::ACCEPTED,
                    TripPhase::PICKUP_ARRIVED,
                    TripPhase::IN_PROGRESS,
                ])
                ->first();

            $responseData = [
                'last_active_at' => $driver->last_active_at?->toIso8601String(),
                'is_online' => $driver->is_online,
            ];

            if ($activeAssignment) {
                $responseData['trip_phase'] = $activeAssignment->trip_phase->value;
                $responseData['assignment_id'] = $activeAssignment->id;
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Heartbeat received',
                'data' => $responseData,
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
