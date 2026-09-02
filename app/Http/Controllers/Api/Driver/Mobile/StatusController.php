<?php

namespace App\Http\Controllers\Api\Driver\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Driver\Mobile\GoOnlineRequest;
use App\Http\Requests\Driver\Mobile\GoOfflineRequest;
use App\Http\Resources\Driver\DriverSessionResource;
use App\Services\Driver\DriverAuthService;
use App\Services\Driver\MobileAssignmentService;
use App\Services\Driver\SessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Driver Mobile Status Controller
 * 
 * Handles driver online/offline status management for the mobile application.
 * Provides endpoints to go online, go offline, and check current status.
 * 
 * @see Requirements 4.1, 4.3, 4.6, 4.7
 */
class StatusController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @param SessionService $sessionService
     * @param DriverAuthService $authService
     */
    public function __construct(
        private SessionService $sessionService,
        private DriverAuthService $authService,
        private MobileAssignmentService $assignmentService
    ) {}

    /**
     * Set driver status to online and create a new session.
     * 
     * Creates a new DriverSession record and updates the Driver's online status.
     *
     * @param GoOnlineRequest $request
     * @return JsonResponse
     * 
     * @see Requirement 4.1 - Create DriverSession on go-online
     * @see Requirement 4.6 - Set is_online to true
     */
    public function goOnline(GoOnlineRequest $request): JsonResponse
    {
        try {
            $driver = $this->authService->getDriver($request->user());

            if (!$driver) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User is not registered as a driver',
                    'error_code' => 'STATUS_NOT_DRIVER'
                ], 403);
            }

            // Check if driver is already online
            if ($driver->is_online && $driver->activeSession) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Driver is already online',
                    'error_code' => 'STATUS_ALREADY_ONLINE',
                    'data' => [
                        'current_session' => new DriverSessionResource($driver->activeSession)
                    ]
                ], 400);
            }

            $sessionData = $request->validated();
            $sessionData['ip_address'] = $request->ip();

            $session = $this->sessionService->startSession($driver, $sessionData);

            // Check for pending assignments
            $pendingAssignments = $this->assignmentService->getPendingAssignments($driver);

            return response()->json([
                'status' => 'success',
                'message' => 'Driver is now online',
                'data' => [
                    'session' => new DriverSessionResource($session),
                    'pending_assignments' => $pendingAssignments,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to go online',
                'error_code' => 'STATUS_ONLINE_FAILED',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Set driver status to offline and close the active session.
     * 
     * Closes the active DriverSession, calculates distance, and updates Driver state.
     *
     * @param GoOfflineRequest $request
     * @return JsonResponse
     * 
     * @see Requirement 4.3 - Close active session on go-offline
     * @see Requirement 4.7 - Set is_online to false
     */
    public function goOffline(GoOfflineRequest $request): JsonResponse
    {
        try {
            $driver = $this->authService->getDriver($request->user());

            if (!$driver) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User is not registered as a driver',
                    'error_code' => 'STATUS_NOT_DRIVER'
                ], 403);
            }

            // Check if driver has an active session
            if (!$driver->is_online || !$driver->activeSession) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Driver is not currently online',
                    'error_code' => 'STATUS_NOT_ONLINE'
                ], 400);
            }

            // Check for active trip tracking session
            $activeTrip = $this->assignmentService->getActiveTripAssignment($driver);

            // Personal devices cannot be prevented from disabling Android
            // connectivity or Location, but the application must not offer a
            // second path that deliberately closes tracking during a hire.
            // Keep this guard server-owned so an older or modified client
            // cannot bypass the mobile UI restriction.
            if ($activeTrip) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Complete the active hire before going offline.',
                    'error_code' => 'STATUS_ACTIVE_TRIP_REQUIRES_ONLINE',
                    'data' => [
                        'assignment_id' => $activeTrip->id,
                        'trip_phase' => $activeTrip->trip_phase->value,
                    ],
                ], 409);
            }

            $session = $this->sessionService->endSession($driver, $request->validated());

            $responseData = [
                'session' => new DriverSessionResource($session),
            ];

            $message = 'Driver is now offline';

            return response()->json([
                'status' => 'success',
                'message' => $message,
                'data' => $responseData,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to go offline',
                'error_code' => 'STATUS_OFFLINE_FAILED',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get the current status of the authenticated driver.
     * 
     * Returns online/offline status, last active timestamp, and current session details.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getStatus(Request $request): JsonResponse
    {
        try {
            $driver = $this->authService->getDriver($request->user());

            if (!$driver) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User is not registered as a driver',
                    'error_code' => 'STATUS_NOT_DRIVER'
                ], 403);
            }

            // Refresh driver to get latest state
            $this->assignmentService->reconcileCompletedBookingAssignments($driver);
            $driver->refresh();

            return response()->json([
                'status' => 'success',
                'data' => [
                    'is_online' => $driver->is_online,
                    'last_active_at' => $driver->last_active_at?->toIso8601String(),
                    'current_latitude' => $driver->current_latitude,
                    'current_longitude' => $driver->current_longitude,
                    'current_device_uuid' => $driver->current_device_uuid,
                    'current_session' => $driver->activeSession 
                        ? new DriverSessionResource($driver->activeSession) 
                        : null
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get status',
                'error_code' => 'STATUS_GET_FAILED',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
