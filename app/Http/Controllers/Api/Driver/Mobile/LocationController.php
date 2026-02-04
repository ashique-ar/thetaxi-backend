<?php

namespace App\Http\Controllers\Api\Driver\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Driver\Mobile\LocationUpdateRequest;
use App\Http\Resources\Driver\RoutePointResource;
use App\Services\Driver\DriverAuthService;
use App\Services\Driver\LocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Driver Mobile Location Controller
 * 
 * Handles driver location updates and route history for the mobile application.
 * Provides endpoints to update location and retrieve route points for the current session.
 * 
 * @see Requirements 6.2, 6.4
 */
class LocationController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @param LocationService $locationService
     * @param DriverAuthService $authService
     */
    public function __construct(
        private LocationService $locationService,
        private DriverAuthService $authService
    ) {}

    /**
     * Update the driver's current location.
     * 
     * Updates the driver's coordinates and creates a RoutePoint record
     * linked to the active session.
     *
     * @param LocationUpdateRequest $request
     * @return JsonResponse
     * 
     * @see Requirement 6.2 - Update Driver coordinates
     * @see Requirement 6.4 - Create RoutePoint linked to session
     */
    public function update(LocationUpdateRequest $request): JsonResponse
    {
        try {
            $driver = $this->authService->getDriver($request->user());

            if (!$driver) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User is not registered as a driver',
                    'error_code' => 'LOCATION_NOT_DRIVER'
                ], 403);
            }

            $routePoint = $this->locationService->updateLocation($driver, $request->validated());

            return response()->json([
                'status' => 'success',
                'message' => 'Location updated successfully',
                'data' => new RoutePointResource($routePoint)
            ]);
        } catch (\Exception $e) {
            // Check if it's a "No active session" error
            if ($e->getMessage() === 'No active session') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No active session',
                    'error_code' => 'LOCATION_NO_SESSION'
                ], 400);
            }

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update location',
                'error_code' => 'LOCATION_UPDATE_FAILED',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get route points history for the current active session.
     * 
     * Returns all route points recorded during the driver's current session,
     * ordered by recorded_at ascending.
     *
     * @param Request $request
     * @return JsonResponse
     * 
     * @see Requirement 6.4 - Route points linked to session
     */
    public function history(Request $request): JsonResponse
    {
        try {
            $driver = $this->authService->getDriver($request->user());

            if (!$driver) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User is not registered as a driver',
                    'error_code' => 'LOCATION_NOT_DRIVER'
                ], 403);
            }

            $session = $driver->activeSession;

            if (!$session) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No active session',
                    'error_code' => 'LOCATION_NO_SESSION'
                ], 400);
            }

            $routePoints = $this->locationService->getSessionRoutePoints($session);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'session_id' => $session->id,
                    'route_points' => RoutePointResource::collection($routePoints),
                    'total_points' => $routePoints->count()
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve location history',
                'error_code' => 'LOCATION_HISTORY_FAILED',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
