<?php

namespace App\Http\Controllers\Api\Driver\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Driver\Mobile\AcceptAssignmentRequest;
use App\Http\Requests\Driver\Mobile\DeclineAssignmentRequest;
use App\Models\DriverAssignment;
use App\Services\Driver\DriverAuthService;
use App\Services\Driver\MobileAssignmentService;
use App\Services\Driver\NotificationTriggerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Driver Mobile Assignment Controller
 *
 * Handles booking assignment operations for the driver mobile app:
 * listing, current assignment, accept, and decline.
 *
 * @see Requirements 1.1–1.6, 2.1–2.5
 */
class AssignmentController extends Controller
{
    public function __construct(
        private DriverAuthService $authService,
        private MobileAssignmentService $assignmentService,
        private NotificationTriggerService $notificationService
    ) {}

    /**
     * List all assignments for the authenticated driver.
     *
     * Supports optional status filter via query parameter.
     *
     * @see Requirements 1.1, 1.2, 1.3, 1.6
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $driver = $this->authService->getDriver($request->user());

            if (!$driver) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User is not registered as a driver',
                    'error_code' => 'ASSIGNMENT_NOT_DRIVER'
                ], 403);
            }

            $filters = [
                'status' => $request->query('status'),
                'per_page' => $request->query('per_page', 15),
                'date' => $request->query('date'),
                'from' => $request->query('from'),
                'to' => $request->query('to'),
            ];

            $assignments = $this->assignmentService->getDriverAssignments($driver, $filters);
            $payload = $this->assignmentService->mapAssignmentsForMobile($assignments->items());

            return response()->json([
                'status' => 'success',
                'data' => $payload,
                'meta' => [
                    'current_page' => $assignments->currentPage(),
                    'last_page' => $assignments->lastPage(),
                    'per_page' => $assignments->perPage(),
                    'total' => $assignments->total(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve assignments',
                'error_code' => 'ASSIGNMENT_LIST_FAILED',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get the current active assignment for the authenticated driver.
     *
     * Returns null data with 200 if no active assignment exists.
     *
     * @see Requirements 1.4, 1.5
     */
    public function current(Request $request): JsonResponse
    {
        try {
            $driver = $this->authService->getDriver($request->user());

            if (!$driver) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User is not registered as a driver',
                    'error_code' => 'ASSIGNMENT_NOT_DRIVER'
                ], 403);
            }

            $assignment = $this->assignmentService->getCurrentAssignment($driver);

            return response()->json([
                'status' => 'success',
                'data' => $this->assignmentService->buildAssignmentPayload($assignment),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve current assignment',
                'error_code' => 'ASSIGNMENT_CURRENT_FAILED',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Accept a driver assignment.
     *
     * @see Requirements 2.1, 2.3, 2.5
     */
    public function accept(AcceptAssignmentRequest $request, string $id): JsonResponse
    {
        try {
            $driver = $this->authService->getDriver($request->user());

            if (!$driver) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User is not registered as a driver',
                    'error_code' => 'ASSIGNMENT_NOT_DRIVER'
                ], 403);
            }

            $assignment = DriverAssignment::where('id', $id)
                ->where('driver_id', $driver->id)
                ->first();

            if (!$assignment) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Assignment not found',
                    'error_code' => 'ASSIGNMENT_NOT_FOUND'
                ], 404);
            }

            $updated = $this->assignmentService->acceptAssignment($driver, $assignment);

            return response()->json([
                'status' => 'success',
                'message' => 'Assignment accepted',
                'data' => $this->assignmentService->buildAssignmentPayload($updated),
            ]);
        } catch (\InvalidArgumentException $e) {
            $code = $e->getMessage();
            $httpStatus = 400;
            $message = match ($code) {
                'ASSIGNMENT_ALREADY_CONFIRMED' => 'Assignment has already been confirmed',
                'ASSIGNMENT_INVALID_STATE' => 'Assignment is not in a valid state for acceptance',
                default => $code,
            };

            return response()->json([
                'status' => 'error',
                'message' => $message,
                'error_code' => $code,
                'errors' => []
            ], $httpStatus);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to accept assignment',
                'error_code' => 'ASSIGNMENT_ACCEPT_FAILED',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Decline a driver assignment.
     *
     * @see Requirements 2.2, 2.3
     */
    public function decline(DeclineAssignmentRequest $request, string $id): JsonResponse
    {
        try {
            $driver = $this->authService->getDriver($request->user());

            if (!$driver) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User is not registered as a driver',
                    'error_code' => 'ASSIGNMENT_NOT_DRIVER'
                ], 403);
            }

            $assignment = DriverAssignment::where('id', $id)
                ->where('driver_id', $driver->id)
                ->first();

            if (!$assignment) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Assignment not found',
                    'error_code' => 'ASSIGNMENT_NOT_FOUND'
                ], 404);
            }

            $updated = $this->assignmentService->declineAssignment(
                $driver,
                $assignment,
                $request->validated()['decline_reason']
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Assignment declined',
                'data' => $this->assignmentService->buildAssignmentPayload($updated),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Assignment is not in a valid state for declining',
                'error_code' => $e->getMessage(),
                'errors' => []
            ], 400);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to decline assignment',
                'error_code' => 'ASSIGNMENT_DECLINE_FAILED',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function acknowledge(Request $request, string $id): JsonResponse
    {
        $driver = $this->authService->getDriver($request->user());
        if (!$driver) {
            return response()->json(['status' => 'error', 'message' => 'User is not registered as a driver'], 403);
        }

        $assignment = DriverAssignment::query()->whereKey($id)->where('driver_id', $driver->id)->first();
        if (!$assignment) {
            return response()->json(['status' => 'error', 'message' => 'Assignment not found'], 404);
        }

        $notification = $this->notificationService->acknowledgeAssignment($assignment, (string) $driver->id, 'opened');
        if (!$notification) {
            return response()->json(['status' => 'error', 'message' => 'Assignment notification could not be acknowledged'], 409);
        }

        return response()->json(['status' => 'success', 'data' => [
            'notification_id' => $notification->id,
            'acknowledged_at' => $notification->acknowledged_at?->toIso8601String(),
            'acknowledgement_source' => $notification->acknowledgement_source,
            'acknowledgement_required' => false,
        ]]);
    }

    /**
     * Get completed hire history for the authenticated driver.
     */
    public function hires(Request $request): JsonResponse
    {
        try {
            $driver = $this->authService->getDriver($request->user());

            if (!$driver) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User is not registered as a driver',
                    'error_code' => 'ASSIGNMENT_NOT_DRIVER'
                ], 403);
            }

            $filters = [
                'per_page' => $request->query('per_page', 15),
                'date' => $request->query('date'),
                'from' => $request->query('from'),
                'to' => $request->query('to'),
            ];

            $hires = $this->assignmentService->getDriverHires($driver, $filters);
            $payload = $this->assignmentService->mapAssignmentsForMobile($hires->items());

            return response()->json([
                'status' => 'success',
                'data' => $payload,
                'meta' => [
                    'current_page' => $hires->currentPage(),
                    'last_page' => $hires->lastPage(),
                    'per_page' => $hires->perPage(),
                    'total' => $hires->total(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve hire history',
                'error_code' => 'ASSIGNMENT_HIRES_FAILED',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
