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

class TripController extends Controller
{
    public function __construct(
        private DriverAuthService $authService,
        private TripTrackingService $tripTrackingService
    ) {}

    /**
     * Canonical endpoint: assignment-scoped trip status.
     * GET /api/driver/assignments/{id}/status
     */
    public function statusForAssignment(Request $request, string $id): JsonResponse
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

            $assignment = $this->getAnyAssignmentByDriverAndId($driver->id, $id);

            if (!$assignment) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Assignment not found',
                    'error_code' => 'TRIP_ASSIGNMENT_NOT_FOUND'
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'data' => $this->tripTrackingService->getTripStatus($assignment),
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
     * Canonical endpoint: /assignments/{id}/arrived
     */
    public function pickupArrivedForAssignment(PickupArrivedRequest $request, string $id): JsonResponse
    {
        return $this->pickupArrivedByAssignment($request, $id);
    }

    /**
     * Canonical endpoint: /assignments/{id}/start
     */
    public function startTripForAssignment(Request $request, string $id): JsonResponse
    {
        return $this->startTripByAssignment($request, $id);
    }

    /**
     * Canonical endpoint: /assignments/{id}/complete
     */
    public function endTripForAssignment(EndTripRequest $request, string $id): JsonResponse
    {
        return $this->endTripByAssignment($request, $id);
    }

    private function pickupArrivedByAssignment(PickupArrivedRequest $request, string $assignmentId): JsonResponse
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

            $assignment = $this->getAnyAssignmentByDriverAndId($driver->id, $assignmentId);

            if (!$assignment) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Assignment not found or not active',
                    'error_code' => 'TRIP_ASSIGNMENT_NOT_FOUND'
                ], 404);
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

    private function startTripByAssignment(Request $request, string $assignmentId): JsonResponse
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

            $assignment = $this->getAnyAssignmentByDriverAndId($driver->id, $assignmentId);

            if (!$assignment) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Assignment not found or not active',
                    'error_code' => 'TRIP_ASSIGNMENT_NOT_FOUND'
                ], 404);
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

    private function endTripByAssignment(EndTripRequest $request, string $assignmentId): JsonResponse
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

            $assignment = $this->getAnyAssignmentByDriverAndId($driver->id, $assignmentId);

            if (!$assignment) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Assignment not found or not active',
                    'error_code' => 'TRIP_ASSIGNMENT_NOT_FOUND'
                ], 404);
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

    private function getAnyAssignmentByDriverAndId(string $driverId, string $assignmentId): ?DriverAssignment
    {
        return DriverAssignment::where('id', $assignmentId)
            ->where('driver_id', $driverId)
            ->whereIn('trip_phase', [
                TripPhase::ACTIVE,
                TripPhase::CONFIRMED,
                TripPhase::ACCEPTED,
                TripPhase::PICKUP_ARRIVED,
                TripPhase::IN_PROGRESS,
            ])
            ->first();
    }
}
