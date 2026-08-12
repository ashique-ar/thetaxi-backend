<?php

namespace App\Http\Controllers\Api\Driver\Mobile;

use App\Enums\TripPhase;
use App\Http\Controllers\Controller;
use App\Http\Requests\Driver\Mobile\EndTripRequest;
use App\Http\Requests\Driver\Mobile\PickupArrivedRequest;
use App\Models\DriverAssignment;
use App\Models\DriverAssignmentStop;
use App\Services\Driver\DriverAuthService;
use App\Services\Driver\MobileAssignmentService;
use App\Services\Driver\TripTrackingService;
use App\Services\Sms\SmsAutomationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TripController extends Controller
{
    public function __construct(
        private DriverAuthService $authService,
        private TripTrackingService $tripTrackingService,
        private MobileAssignmentService $assignmentService,
        private SmsAutomationService $smsAutomationService
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

    public function collectPaymentForAssignment(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'collected_amount' => ['required', 'numeric', 'min:0'],
            'payment_notes' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['required', 'uuid'],
        ]);

        try {
            $driver = $this->authService->getDriver($request->user());

            if (!$driver) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User is not registered as a driver',
                    'error_code' => 'TRIP_NOT_DRIVER'
                ], 403);
            }

            $assignment = $this->getCompletedAssignmentByDriverAndId($driver->id, $id);

            if (!$assignment) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Completed assignment not found',
                    'error_code' => 'TRIP_ASSIGNMENT_NOT_FOUND'
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Payment collection recorded',
                'data' => $this->tripTrackingService->collectPayment($assignment, $validated),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $this->friendlyTripStopError($e->getMessage()),
                'error_code' => $e->getMessage(),
                'errors' => []
            ], 400);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to record payment collection',
                'error_code' => 'PAYMENT_COLLECTION_FAILED',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function stopArrivedForAssignment(Request $request, string $id, string $stopId): JsonResponse
    {
        $validated = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->processStopAction($request, $id, $stopId, function (DriverAssignment $assignment, DriverAssignmentStop $stop) use ($validated) {
            return $this->tripTrackingService->markStopArrived($assignment, $stop, $validated);
        }, 'Stop arrival confirmed');
    }

    public function pickupStopCompletedForAssignment(Request $request, string $id, string $stopId): JsonResponse
    {
        $validated = $request->validate([
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->processStopAction($request, $id, $stopId, function (DriverAssignment $assignment, DriverAssignmentStop $stop) use ($validated) {
            return $this->tripTrackingService->completePickupStop($assignment, $stop, $validated);
        }, 'Pickup stop completed');
    }

    public function dropoffStopCompletedForAssignment(Request $request, string $id, string $stopId): JsonResponse
    {
        $validated = $request->validate([
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->processStopAction($request, $id, $stopId, function (DriverAssignment $assignment, DriverAssignmentStop $stop) use ($validated) {
            return $this->tripTrackingService->completeDropoffStop($assignment, $stop, $validated);
        }, 'Drop-off stop completed');
    }

    public function stopSkippedForAssignment(Request $request, string $id, string $stopId): JsonResponse
    {
        $validated = $request->validate([
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->processStopAction($request, $id, $stopId, function (DriverAssignment $assignment, DriverAssignmentStop $stop) use ($validated) {
            return $this->tripTrackingService->skipStop($assignment, $stop, $validated);
        }, 'Stop skipped');
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
            $this->smsAutomationService->queueDriverArrived($assignment->booking, $assignment->fresh());

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

    private function processStopAction(Request $request, string $assignmentId, string $stopId, callable $action, string $message): JsonResponse
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

            $stop = DriverAssignmentStop::where('id', $stopId)
                ->where('assignment_id', $assignment->id)
                ->first();

            if (!$stop) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Stop not found',
                    'error_code' => 'TRIP_STOP_NOT_FOUND'
                ], 404);
            }

            $data = $action($assignment, $stop);
            $processedStop = $stop->fresh();

            if (is_array($data) && $processedStop) {
                $data['processed_stop'] = $this->mapStopForResponse($processedStop);
            }

            return response()->json([
                'status' => 'success',
                'message' => $message,
                'data' => $data,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $this->friendlyTripStopError($e->getMessage()),
                'error_code' => $e->getMessage(),
                'errors' => []
            ], 400);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update stop status',
                'error_code' => 'TRIP_STOP_UPDATE_FAILED',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function mapStopForResponse(DriverAssignmentStop $stop): array
    {
        $typeSequence = $stop->type_sequence;
        $displayLabel = $stop->label ?: (
            $stop->stop_type === 'pickup'
                ? 'Pickup ' . ($typeSequence ?: $stop->route_order)
                : 'Drop-off ' . ($typeSequence ?: $stop->route_order)
        );

        return [
            'id' => $stop->id,
            'booking_stop_id' => $stop->booking_stop_id,
            'type' => $stop->stop_type,
            'type_sequence' => $typeSequence !== null ? (int) $typeSequence : null,
            'route_order' => (int) $stop->route_order,
            'status' => $stop->status,
            'label' => $displayLabel,
            'display_label' => $displayLabel,
            'address' => $stop->address,
            'contact' => [
                'employee_id' => $stop->location['employee_id'] ?? null,
                'contact_name' => $stop->location['contact_name'] ?? null,
                'contact_phone' => $stop->location['contact_phone'] ?? null,
                'contact_note' => $stop->location['contact_note'] ?? null,
            ],
            'arrived_at' => $stop->arrived_at?->toIso8601String(),
            'completed_at' => $stop->completed_at?->toIso8601String(),
            'completed_action' => $stop->completed_action,
            'skip_reason' => $stop->skip_reason,
        ];
    }

    private function friendlyTripStopError(string $code): string
    {
        return match ($code) {
            'TRIP_NOT_IN_PROGRESS' => 'Hire must be started before updating route stops',
            'STOP_ALREADY_COMPLETED' => 'Stop has already been completed',
            'STOP_INVALID_STATE' => 'Stop is not in a valid state for this action',
            'STOP_OUT_OF_SEQUENCE' => 'Previous stops must be completed or skipped first',
            'STOP_TYPE_MISMATCH' => 'Stop type does not match the requested action',
            'STOP_ARRIVAL_REQUIRED' => 'Driver must mark arrived before completing this stop',
            'TRIP_STOPS_INCOMPLETE' => 'All route stops must be completed or skipped before ending the hire',
            'PAYMENT_BOOKING_NOT_FOUND' => 'Booking payment record was not found',
            'PAYMENT_COLLECTION_NOT_REQUIRED' => 'Driver cash collection is not required for this hire',
            'PAYMENT_AMOUNT_EXCEEDS_OUTSTANDING' => 'Collected amount cannot exceed the outstanding booking balance',
            default => $code,
        };
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
            $completedAssignment = $assignment->fresh();
            $summary['assignment'] = $this->assignmentService->buildAssignmentPayload($completedAssignment);

            return response()->json([
                'status' => 'success',
                'message' => 'Trip completed',
                'data' => $summary,
            ]);
        } catch (\DomainException $e) {
            $distanceRequired = str_contains($e->getMessage(), 'Final distance is required');

            return response()->json([
                'status' => 'error',
                'message' => $distanceRequired
                    ? 'Trip route records are required to calculate the final package price.'
                    : $e->getMessage(),
                'error_code' => $distanceRequired
                    ? 'FINAL_DISTANCE_REQUIRED'
                    : 'TRIP_COMPLETION_BLOCKED',
                'errors' => $distanceRequired ? [
                    'route_points' => [
                        'Sync at least two buffered GPS route points for this trip before retrying completion.',
                    ],
                ] : [],
            ], 422);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $this->friendlyTripStopError($e->getMessage()),
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
                // Administrative force completion can win a race with a final
                // mobile request. Let endTrip return its stored idempotent
                // summary instead of reporting the assignment as missing.
                TripPhase::COMPLETED,
            ])
            ->first();
    }

    private function getCompletedAssignmentByDriverAndId(string $driverId, string $assignmentId): ?DriverAssignment
    {
        return DriverAssignment::where('id', $assignmentId)
            ->where('driver_id', $driverId)
            ->where('trip_phase', TripPhase::COMPLETED)
            ->first();
    }
}
