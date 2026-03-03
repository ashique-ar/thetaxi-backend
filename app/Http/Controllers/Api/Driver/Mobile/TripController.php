<?php

namespace App\Http\Controllers\Api\Driver\Mobile;

use App\Enums\TripPhase;
use App\Http\Controllers\Controller;
use App\Http\Requests\Driver\Mobile\EndTripRequest;
use App\Http\Requests\Driver\Mobile\PickupArrivedRequest;
use App\Models\DriverAssignment;
use App\Services\Driver\DriverAuthService;
use App\Services\Driver\TripTrackingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Driver Mobile Trip Controller
 *
 * Manages the trip lifecycle: status, pickup arrival, start, and end.
 *
 * @see Requirements 5.2–5.4, 6.1–6.5, 7.4, 8.1–8.5
 */
class TripController extends Controller
{
    public function __construct(
        private DriverAuthService $authService,
        private TripTrackingService $tripTrackingService
    ) {}

    /**
     * Get current trip status.
     *
     * Returns trip phase, pickup location, distance, waiting time, etc.
     * Returns null data with 200 if no active trip.
     *
     * @see Requirements 5.2, 5.3, 5.4, 7.4
     */
    public function status(Request $request): JsonResponse
    {
        try {
            $driver = $this->authService->getDriver($request->user());

            if (!$driver) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User is not registered as a driver',
                    'error_code' => 'TRIP_NOT_DRIVER'
                ], 403);
            }

            $assignment = $this->getActiveTrip($driver->id);

            if (!$assignment) {
                return response()->json([
                    'status' => 'success',
                    'data' => null,
                ]);
            }

            $tripStatus = $this->tripTrackingService->getTripStatus($assignment);

            return response()->json([
                'status' => 'success',
                'data' => $tripStatus,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve trip status',
                'error_code' => 'TRIP_STATUS_FAILED',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Confirm arrival at pickup location.
     *
     * @see Requirements 6.1, 6.4
     */
    public function pickupArrived(PickupArrivedRequest $request): JsonResponse
    {
        try {
            $driver = $this->authService->getDriver($request->user());

            if (!$driver) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User is not registered as a driver',
                    'error_code' => 'TRIP_NOT_DRIVER'
                ], 403);
            }

            $assignment = $this->getActiveTrip($driver->id);

            if (!$assignment) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No active trip found',
                    'error_code' => 'TRIP_NO_ACTIVE_SESSION'
                ], 400);
            }

            $this->tripTrackingService->confirmPickupArrival($assignment, $request->validated());

            return response()->json([
                'status' => 'success',
                'message' => 'Pickup arrival confirmed',
                'data' => $this->tripTrackingService->getTripStatus($assignment->fresh()),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Trip is not in the correct phase for pickup arrival',
                'error_code' => $e->getMessage(),
                'errors' => []
            ], 400);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to confirm pickup arrival',
                'error_code' => 'TRIP_PICKUP_FAILED',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Start the trip after pickup.
     *
     * @see Requirements 6.2, 6.5
     */
    public function startTrip(Request $request): JsonResponse
    {
        try {
            $driver = $this->authService->getDriver($request->user());

            if (!$driver) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User is not registered as a driver',
                    'error_code' => 'TRIP_NOT_DRIVER'
                ], 403);
            }

            $assignment = $this->getActiveTrip($driver->id);

            if (!$assignment) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No active trip found',
                    'error_code' => 'TRIP_NO_ACTIVE_SESSION'
                ], 400);
            }

            $this->tripTrackingService->startTrip($assignment);

            return response()->json([
                'status' => 'success',
                'message' => 'Trip started',
                'data' => $this->tripTrackingService->getTripStatus($assignment->fresh()),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Pickup arrival must be confirmed before starting the trip',
                'error_code' => $e->getMessage(),
                'errors' => []
            ], 400);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to start trip',
                'error_code' => 'TRIP_START_FAILED',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * End the trip and return summary.
     *
     * @see Requirements 8.1–8.5
     */
    public function endTrip(EndTripRequest $request): JsonResponse
    {
        try {
            $driver = $this->authService->getDriver($request->user());

            if (!$driver) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User is not registered as a driver',
                    'error_code' => 'TRIP_NOT_DRIVER'
                ], 403);
            }

            $assignment = $this->getActiveTrip($driver->id);

            if (!$assignment) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No active trip found',
                    'error_code' => 'TRIP_NO_ACTIVE_SESSION'
                ], 400);
            }

            $summary = $this->tripTrackingService->endTrip($assignment, $request->validated());

            return response()->json([
                'status' => 'success',
                'message' => 'Trip completed',
                'data' => $summary,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Trip is not in progress',
                'error_code' => $e->getMessage(),
                'errors' => []
            ], 400);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to end trip',
                'error_code' => 'TRIP_END_FAILED',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get the active trip assignment for a driver.
     *
     * Active trip = assignment in accepted, pickup_arrived, or in_progress phase.
     */
    private function getActiveTrip(string $driverId): ?DriverAssignment
    {
        return DriverAssignment::where('driver_id', $driverId)
            ->whereIn('trip_phase', [
                TripPhase::ACCEPTED,
                TripPhase::PICKUP_ARRIVED,
                TripPhase::IN_PROGRESS,
            ])
            ->first();
    }
}
