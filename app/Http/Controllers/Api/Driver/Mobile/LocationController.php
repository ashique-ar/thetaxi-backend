<?php

namespace App\Http\Controllers\Api\Driver\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Driver\Mobile\BulkLocationUpdateRequest;
use App\Http\Requests\Driver\Mobile\LocationUpdateRequest;
use App\Http\Resources\Driver\RoutePointResource;
use App\Models\Driver\DriverSession;
use App\Models\DriverAssignment;
use App\Services\Driver\DriverAuthService;
use App\Services\Driver\LocationService;
use Carbon\Carbon;
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
            // Check for specific error types
            if ($e->getMessage() === 'No active session') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No active session',
                    'error_code' => 'LOCATION_NO_SESSION'
                ], 400);
            }

            if ($e->getMessage() === 'LOCATION_RATE_LIMITED') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Location updates must be at least 10 seconds apart',
                    'error_code' => 'LOCATION_RATE_LIMITED'
                ], 429);
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

            $assignmentId = $request->query('assignment_id');
            $sessionId = $request->query('session_id');
            $limit = (int) $request->query('limit', 5000);
            $limit = max(1, min($limit, 10000));
            $from = $request->query('from');
            $to = $request->query('to');

            if ($assignmentId) {
                $assignment = DriverAssignment::where('id', $assignmentId)
                    ->where('driver_id', $driver->id)
                    ->first();

                if (!$assignment) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Assignment not found',
                        'error_code' => 'LOCATION_ASSIGNMENT_NOT_FOUND'
                    ], 404);
                }

                $query = $assignment->routePoints()->orderBy('recorded_at', 'asc');
                $this->applyRecordedAtFilters($query, $from, $to);
                $routePoints = $query->limit($limit)->get();

                return response()->json([
                    'status' => 'success',
                    'data' => [
                        'scope' => 'assignment',
                        'assignment_id' => $assignment->id,
                        'trip_phase' => $assignment->trip_phase?->value,
                        'session_id' => $routePoints->first()?->session_id,
                        'route_points' => RoutePointResource::collection($routePoints),
                        'total_points' => $routePoints->count(),
                    ]
                ]);
            }

            $session = null;
            if ($sessionId) {
                $session = DriverSession::where('id', $sessionId)
                    ->where('driver_id', $driver->id)
                    ->first();

                if (!$session) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Session not found',
                        'error_code' => 'LOCATION_SESSION_NOT_FOUND'
                    ], 404);
                }
            } else {
                // Keep existing behavior (prefer active session), but allow history after trip/session end.
                $session = $driver->activeSession()
                    ->first() ?? $driver->sessions()->orderByDesc('start_time')->first();
            }

            if (!$session) {
                return response()->json([
                    'status' => 'success',
                    'data' => [
                        'scope' => 'session',
                        'session_id' => null,
                        'route_points' => [],
                        'total_points' => 0
                    ]
                ]);
            }

            $query = $session->routePoints()->orderBy('recorded_at', 'asc');
            $this->applyRecordedAtFilters($query, $from, $to);
            $routePoints = $query->limit($limit)->get();

            return response()->json([
                'status' => 'success',
                'data' => [
                    'scope' => 'session',
                    'session_id' => $session->id,
                    'assignment_id' => $session->assignment_id,
                    'session_status' => $session->status,
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

    /**
     * Upload buffered location points collected while the device was offline.
     *
     * Saves non-duplicate points and skips entries where recorded_at + latitude
     * + longitude are identical to an existing or repeated point.
     */
    public function bulkUpdate(BulkLocationUpdateRequest $request): JsonResponse
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

            $result = $this->locationService->syncBufferedLocations(
                $driver,
                $request->validated()['locations']
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Buffered locations processed successfully',
                'data' => [
                    'saved_count' => $result['saved_count'],
                    'skipped_count' => $result['skipped_count'],
                    'duplicate_count' => $result['duplicate_count'],
                    'latest_saved_point' => $result['latest_saved_point']
                        ? new RoutePointResource($result['latest_saved_point'])
                        : null,
                ],
            ]);
        } catch (\Exception $e) {
            if ($e->getMessage() === 'No active session') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No active session',
                    'error_code' => 'LOCATION_NO_SESSION'
                ], 400);
            }

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to process buffered locations',
                'error_code' => 'LOCATION_BULK_UPDATE_FAILED',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function applyRecordedAtFilters($query, ?string $from, ?string $to): void
    {
        if ($from) {
            $query->where('recorded_at', '>=', Carbon::parse($from));
        }

        if ($to) {
            $query->where('recorded_at', '<=', Carbon::parse($to));
        }
    }
}
