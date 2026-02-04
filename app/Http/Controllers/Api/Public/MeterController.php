<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Public\MeterStartRequest;
use App\Http\Requests\Public\MeterStopRequest;
use App\Http\Requests\Public\MeterLocationRequest;
use App\Http\Requests\Public\MeterEstimateRequest;
use App\Services\Driver\RouteService;
use App\Models\Driver\DriverSession;
use App\Models\Driver\RoutePoint;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Public Meter Controller
 * 
 * Handles meter functionality for guest/unauthenticated users.
 * Tracks trip distance and provides fare estimates using device UUID
 * for identification without requiring authentication.
 * 
 * @see Requirements 3.2, 3.3
 */
class MeterController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @param RouteService $routeService
     */
    public function __construct(
        private RouteService $routeService
    ) {}

    /**
     * Start a new meter session for a guest user.
     * 
     * Creates a new DriverSession record tracked by device_uuid
     * without requiring driver authentication.
     *
     * @param MeterStartRequest $request
     * @return JsonResponse
     * 
     * @see Requirement 3.2 - Track by device_uuid without authentication
     * @see Requirement 3.3 - Track guest usage by Device_UUID
     */
    public function start(MeterStartRequest $request): JsonResponse
    {
        try {
            $deviceUuid = $request->input('device_uuid');
            
            // Check if there's already an active meter session for this device
            $existingSession = DriverSession::where('device_uuid', $deviceUuid)
                ->where('status', 'active')
                ->whereNull('driver_id')
                ->first();

            if ($existingSession) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'A meter session is already active for this device',
                    'error_code' => 'METER_SESSION_ACTIVE',
                    'data' => [
                        'session_id' => $existingSession->id,
                        'start_time' => $existingSession->start_time?->toIso8601String(),
                    ]
                ], 400);
            }

            $session = DB::transaction(function () use ($request, $deviceUuid) {
                $now = Carbon::now();

                // Create meter session (no driver_id for guest sessions)
                $session = DriverSession::create([
                    'driver_id' => null,
                    'device_uuid' => $deviceUuid,
                    'status' => 'active',
                    'start_time' => $now,
                    'start_latitude' => $request->input('latitude'),
                    'start_longitude' => $request->input('longitude'),
                    'metadata' => [
                        'type' => 'meter',
                        'guest' => true,
                    ],
                ]);

                // Create initial route point if location provided
                if ($request->has('latitude') && $request->has('longitude')) {
                    RoutePoint::create([
                        'session_id' => $session->id,
                        'latitude' => $request->input('latitude'),
                        'longitude' => $request->input('longitude'),
                        'altitude' => $request->input('altitude'),
                        'speed' => $request->input('speed'),
                        'heading' => $request->input('heading'),
                        'accuracy' => $request->input('accuracy'),
                        'recorded_at' => $now,
                    ]);
                }

                return $session;
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Meter session started',
                'data' => [
                    'session_id' => $session->id,
                    'device_uuid' => $session->device_uuid,
                    'start_time' => $session->start_time?->toIso8601String(),
                    'start_latitude' => $session->start_latitude,
                    'start_longitude' => $session->start_longitude,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to start meter session',
                'error_code' => 'METER_START_FAILED',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Stop an active meter session.
     * 
     * Closes the meter session, calculates total distance traveled,
     * and returns the session summary.
     *
     * @param MeterStopRequest $request
     * @return JsonResponse
     * 
     * @see Requirement 3.2 - Track by device_uuid without authentication
     */
    public function stop(MeterStopRequest $request): JsonResponse
    {
        try {
            $deviceUuid = $request->input('device_uuid');
            $sessionId = $request->input('session_id');

            // Find the active session
            $query = DriverSession::where('device_uuid', $deviceUuid)
                ->where('status', 'active')
                ->whereNull('driver_id');

            if ($sessionId) {
                $query->where('id', $sessionId);
            }

            $session = $query->first();

            if (!$session) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No active meter session found for this device',
                    'error_code' => 'METER_SESSION_NOT_FOUND'
                ], 404);
            }

            $session = DB::transaction(function () use ($session, $request) {
                $now = Carbon::now();

                // Add final route point if location provided
                if ($request->has('latitude') && $request->has('longitude')) {
                    RoutePoint::create([
                        'session_id' => $session->id,
                        'latitude' => $request->input('latitude'),
                        'longitude' => $request->input('longitude'),
                        'altitude' => $request->input('altitude'),
                        'speed' => $request->input('speed'),
                        'heading' => $request->input('heading'),
                        'accuracy' => $request->input('accuracy'),
                        'recorded_at' => $now,
                    ]);
                }

                // Calculate total distance
                $totalDistance = $this->routeService->calculateDistance($session);

                // Update session
                $session->update([
                    'status' => 'completed',
                    'end_time' => $now,
                    'end_latitude' => $request->input('latitude') ?? $session->start_latitude,
                    'end_longitude' => $request->input('longitude') ?? $session->start_longitude,
                    'total_distance_km' => $totalDistance,
                ]);

                return $session->fresh();
            });

            // Get route statistics
            $routeStats = $this->routeService->getRouteStats($session);

            return response()->json([
                'status' => 'success',
                'message' => 'Meter session stopped',
                'data' => [
                    'session_id' => $session->id,
                    'device_uuid' => $session->device_uuid,
                    'start_time' => $session->start_time?->toIso8601String(),
                    'end_time' => $session->end_time?->toIso8601String(),
                    'start_latitude' => $session->start_latitude,
                    'start_longitude' => $session->start_longitude,
                    'end_latitude' => $session->end_latitude,
                    'end_longitude' => $session->end_longitude,
                    'total_distance_km' => $session->total_distance_km,
                    'duration_seconds' => $routeStats['duration_seconds'],
                    'point_count' => $routeStats['point_count'],
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to stop meter session',
                'error_code' => 'METER_STOP_FAILED',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update location during an active meter session.
     * 
     * Records a new route point for the active meter session
     * to track the trip route.
     *
     * @param MeterLocationRequest $request
     * @return JsonResponse
     * 
     * @see Requirement 3.2 - Track by device_uuid without authentication
     */
    public function updateLocation(MeterLocationRequest $request): JsonResponse
    {
        try {
            $deviceUuid = $request->input('device_uuid');
            $sessionId = $request->input('session_id');

            // Find the active session
            $query = DriverSession::where('device_uuid', $deviceUuid)
                ->where('status', 'active')
                ->whereNull('driver_id');

            if ($sessionId) {
                $query->where('id', $sessionId);
            }

            $session = $query->first();

            if (!$session) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No active meter session found for this device',
                    'error_code' => 'METER_SESSION_NOT_FOUND'
                ], 400);
            }

            // Create route point
            $routePoint = RoutePoint::create([
                'session_id' => $session->id,
                'latitude' => $request->input('latitude'),
                'longitude' => $request->input('longitude'),
                'altitude' => $request->input('altitude'),
                'speed' => $request->input('speed'),
                'heading' => $request->input('heading'),
                'accuracy' => $request->input('accuracy'),
                'recorded_at' => Carbon::now(),
            ]);

            // Calculate current distance
            $currentDistance = $this->routeService->calculateDistance($session);

            return response()->json([
                'status' => 'success',
                'message' => 'Location updated',
                'data' => [
                    'session_id' => $session->id,
                    'point_id' => $routePoint->id,
                    'latitude' => (float) $routePoint->latitude,
                    'longitude' => (float) $routePoint->longitude,
                    'current_distance_km' => $currentDistance,
                    'point_count' => $session->routePoints()->count(),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update location',
                'error_code' => 'METER_LOCATION_FAILED',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Calculate fare estimate based on distance.
     * 
     * Provides a fare estimate for a given distance or route.
     * Can calculate from provided distance or from an active session's route.
     *
     * @param MeterEstimateRequest $request
     * @return JsonResponse
     * 
     * @see Requirement 3.2 - Track by device_uuid without authentication
     */
    public function estimate(MeterEstimateRequest $request): JsonResponse
    {
        try {
            $deviceUuid = $request->input('device_uuid');
            $distanceKm = $request->input('distance_km');
            $sessionId = $request->input('session_id');

            // If session_id provided, calculate distance from session
            if ($sessionId) {
                $session = DriverSession::where('device_uuid', $deviceUuid)
                    ->where('id', $sessionId)
                    ->whereNull('driver_id')
                    ->first();

                if ($session) {
                    $distanceKm = $this->routeService->calculateDistance($session);
                }
            }

            // If no distance provided and no session, check for active session
            if ($distanceKm === null) {
                $activeSession = DriverSession::where('device_uuid', $deviceUuid)
                    ->where('status', 'active')
                    ->whereNull('driver_id')
                    ->first();

                if ($activeSession) {
                    $distanceKm = $this->routeService->calculateDistance($activeSession);
                    $sessionId = $activeSession->id;
                } else {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Distance or active session required for estimate',
                        'error_code' => 'METER_ESTIMATE_NO_DATA'
                    ], 400);
                }
            }

            // Calculate fare estimate
            // Base fare structure (can be made configurable via business settings)
            $baseFare = $this->getBaseFare();
            $perKmRate = $this->getPerKmRate();
            $minimumFare = $this->getMinimumFare();

            $distanceFare = $distanceKm * $perKmRate;
            $totalFare = $baseFare + $distanceFare;
            
            // Apply minimum fare
            if ($totalFare < $minimumFare) {
                $totalFare = $minimumFare;
            }

            return response()->json([
                'status' => 'success',
                'data' => [
                    'session_id' => $sessionId,
                    'distance_km' => round($distanceKm, 2),
                    'base_fare' => round($baseFare, 2),
                    'per_km_rate' => round($perKmRate, 2),
                    'distance_fare' => round($distanceFare, 2),
                    'total_fare' => round($totalFare, 2),
                    'minimum_fare' => round($minimumFare, 2),
                    'currency' => 'LKR',
                    'note' => 'This is an estimate. Actual fare may vary.',
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to calculate estimate',
                'error_code' => 'METER_ESTIMATE_FAILED',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get base fare from business settings.
     *
     * @return float Base fare amount
     */
    protected function getBaseFare(): float
    {
        $setting = \App\Models\BusinessSetting::getSetting('meter_base_fare');
        return $setting !== null ? (float) $setting : 100.00;
    }

    /**
     * Get per kilometer rate from business settings.
     *
     * @return float Per km rate
     */
    protected function getPerKmRate(): float
    {
        $setting = \App\Models\BusinessSetting::getSetting('meter_per_km_rate');
        return $setting !== null ? (float) $setting : 50.00;
    }

    /**
     * Get minimum fare from business settings.
     *
     * @return float Minimum fare amount
     */
    protected function getMinimumFare(): float
    {
        $setting = \App\Models\BusinessSetting::getSetting('meter_minimum_fare');
        return $setting !== null ? (float) $setting : 300.00;
    }
}
