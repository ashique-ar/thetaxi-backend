<?php

namespace App\Http\Controllers\Api\Driver\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Resources\Driver\DriverSessionResource;
use App\Http\Resources\Driver\RoutePointResource;
use App\Models\Driver\DriverSession;
use App\Services\Driver\DriverAuthService;
use App\Services\Driver\RouteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Driver Mobile Session Controller
 * 
 * Handles driver session history and details for the mobile application.
 * Provides endpoints to list sessions and view session details with route data.
 * 
 * @see Requirements 7.1, 7.5
 */
class SessionController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @param DriverAuthService $authService
     * @param RouteService $routeService
     */
    public function __construct(
        private DriverAuthService $authService,
        private RouteService $routeService
    ) {}

    /**
     * List all sessions for the authenticated driver.
     * 
     * Returns a paginated list of driver sessions ordered by start_time descending.
     *
     * @param Request $request
     * @return JsonResponse
     * 
     * @see Requirement 7.1 - Route points queryable by session ID
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $driver = $this->authService->getDriver($request->user());

            if (!$driver) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User is not registered as a driver',
                    'error_code' => 'SESSION_NOT_DRIVER'
                ], 403);
            }

            $perPage = $request->input('per_page', 15);
            $status = $request->input('status'); // Optional filter: active, completed, auto_closed

            $query = $driver->sessions()->orderBy('start_time', 'desc');

            // Apply status filter if provided
            if ($status) {
                $query->where('status', $status);
            }

            $sessions = $query->paginate($perPage);

            return response()->json([
                'status' => 'success',
                'data' => DriverSessionResource::collection($sessions),
                'meta' => [
                    'current_page' => $sessions->currentPage(),
                    'last_page' => $sessions->lastPage(),
                    'per_page' => $sessions->perPage(),
                    'total' => $sessions->total(),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve sessions',
                'error_code' => 'SESSION_LIST_FAILED',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get details of a specific session with route data.
     * 
     * Returns session details including route points and statistics.
     *
     * @param Request $request
     * @param string $session Session ID (UUID)
     * @return JsonResponse
     * 
     * @see Requirement 7.5 - Endpoint to retrieve route point data for map replay
     */
    public function show(Request $request, string $session): JsonResponse
    {
        try {
            $driver = $this->authService->getDriver($request->user());

            if (!$driver) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User is not registered as a driver',
                    'error_code' => 'SESSION_NOT_DRIVER'
                ], 403);
            }

            // Find the session and ensure it belongs to the authenticated driver
            $driverSession = DriverSession::where('id', $session)
                ->where('driver_id', $driver->id)
                ->first();

            if (!$driverSession) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Session not found',
                    'error_code' => 'SESSION_NOT_FOUND'
                ], 404);
            }

            // Get route points for the session
            $routePoints = $this->routeService->getSessionRoute($driverSession);

            // Get route statistics
            $routeStats = $this->routeService->getRouteStats($driverSession);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'session' => new DriverSessionResource($driverSession),
                    'route_points' => RoutePointResource::collection($routePoints),
                    'route_stats' => $routeStats
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve session details',
                'error_code' => 'SESSION_SHOW_FAILED',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
