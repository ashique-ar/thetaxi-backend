<?php

namespace App\Http\Controllers\Api\Driver\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Driver\Mobile\BulkLocationUpdateRequest;
use App\Http\Requests\Driver\Mobile\LocationUpdateRequest;
use App\Http\Resources\Driver\RoutePointResource;
use App\Models\Driver\DriverSession;
use App\Models\Driver\DriverTrackingIncident;
use App\Models\DriverAssignment;
use App\Services\Driver\DriverAuthService;
use App\Services\Driver\LocationService;
use App\Services\BookingOperationsHealthMonitor;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
        private DriverAuthService $authService,
        private BookingOperationsHealthMonitor $healthMonitor,
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
        $driver = null;
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

            if ($e->getMessage() === 'LOCATION_ASSIGNMENT_AMBIGUOUS') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Driver has more than one active trip assignment; location point was not saved',
                    'error_code' => 'LOCATION_ASSIGNMENT_AMBIGUOUS'
                ], 409);
            }

            $this->healthMonitor->recordLocationUploadFailure(
                $this->locationHealthContext($driver),
                'single',
                $e
            );

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update location',
                'error_code' => 'LOCATION_UPDATE_FAILED',
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
        $driver = null;
        $correlationId = (string) Str::uuid();
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
            Log::info('Buffered driver locations processed', [
                'correlation_id' => $correlationId,
                'driver_id' => $driver->id,
                'accepted_count' => $result['accepted_count'],
                'duplicate_count' => $result['duplicate_count'],
                'quarantined_count' => $result['quarantined_count'],
                'retryable_count' => $result['retryable_count'],
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Buffered locations processed successfully',
                'data' => [
                    'saved_count' => $result['saved_count'],
                    'skipped_count' => $result['skipped_count'],
                    'duplicate_count' => $result['duplicate_count'],
                    'accepted_count' => $result['accepted_count'],
                    'quarantined_count' => $result['quarantined_count'],
                    'retryable_count' => $result['retryable_count'],
                    'outcomes' => $result['outcomes'],
                    'correlation_id' => $correlationId,
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

            if ($e->getMessage() === 'LOCATION_ASSIGNMENT_AMBIGUOUS') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Driver has more than one active trip assignment; buffered points were not saved',
                    'error_code' => 'LOCATION_ASSIGNMENT_AMBIGUOUS'
                ], 409);
            }

            $this->healthMonitor->recordLocationUploadFailure(
                $this->locationHealthContext($driver),
                'buffered',
                $e
            );
            Log::error('Buffered driver locations failed', [
                'correlation_id' => $correlationId,
                'driver_id' => $driver?->id,
                'exception_class' => $e::class,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to process buffered locations',
                'error_code' => 'LOCATION_BULK_UPDATE_FAILED',
                'correlation_id' => $correlationId,
            ], 500);
        }
    }

    /** Report mobile GPS health and synchronization diagnostics. */
    public function health(Request $request): JsonResponse
    {
        $driver = $this->authService->getDriver($request->user());
        if (!$driver) {
            return response()->json(['status' => 'error', 'message' => 'User is not registered as a driver', 'error_code' => 'LOCATION_NOT_DRIVER'], 403);
        }
        $validated = $request->validate([
            'state' => ['required', 'in:healthy,recovered,delayed,severe_gap,blocked,queue_pressure'],
            'queue_count' => ['required', 'integer', 'min:0'],
            'oldest_queue_age_seconds' => ['nullable', 'integer', 'min:0'],
            'last_fix_age_seconds' => ['nullable', 'integer', 'min:0'],
            'app_version' => ['nullable', 'string', 'max:50'],
            'app_build' => ['nullable', 'string', 'max:50'],
        ]);
        $this->healthMonitor->recordDriverTrackingHealth($this->locationHealthContext($driver), $validated);
        return response()->json(['status' => 'success', 'data' => ['acknowledged' => true]]);
    }

    /** Persist an offline tracking-gap report once the driver's device reconnects. */
    public function trackingIncident(Request $request): JsonResponse
    {
        $driver = $this->authService->getDriver($request->user());
        abort_unless($driver, 403, 'User is not registered as a driver');

        $data = $request->validate([
            'incident_id' => ['required', 'uuid'],
            'assignment_id' => ['nullable', 'uuid'],
            'state' => ['required', 'in:open,recovered'],
            'cause' => ['required', 'in:network_unavailable,upload_queue_stalled,location_source_stalled,tracking_blocked,unknown_after_restart'],
            'started_at' => ['required', 'date'],
            'reported_at' => ['required', 'date'],
            'recovered_at' => ['nullable', 'date'],
            'first_evidence' => ['required', 'array:observed_at,last_fix_at,last_fix_age_seconds,last_upload_at,network_state,queue_count,quarantined_count,trip_queue_count,queue_age_seconds,blocked_reason,location_enabled,location_permission,foreground_service_running,sample_unavailable_count,persistence_failure_count,live_upload_attempt_count,live_upload_success_count,live_upload_failure_count,recovery_upload_attempt_count,recovery_upload_success_count,recovery_upload_failure_count,buffer_eviction_count,rejected_point_count'],
            'last_evidence' => ['required', 'array:observed_at,last_fix_at,last_fix_age_seconds,last_upload_at,network_state,queue_count,quarantined_count,trip_queue_count,queue_age_seconds,blocked_reason,location_enabled,location_permission,foreground_service_running,sample_unavailable_count,persistence_failure_count,live_upload_attempt_count,live_upload_success_count,live_upload_failure_count,recovery_upload_attempt_count,recovery_upload_success_count,recovery_upload_failure_count,buffer_eviction_count,rejected_point_count'],
        ]);

        foreach (['first_evidence', 'last_evidence'] as $key) {
            validator($data[$key], [
                'observed_at' => ['required', 'date'],
                'last_fix_at' => ['nullable', 'date'],
                'last_fix_age_seconds' => ['nullable', 'integer', 'min:0'],
                'last_upload_at' => ['nullable', 'date'],
                'network_state' => ['required', 'string', 'max:40'],
                'queue_count' => ['required', 'integer', 'min:0'],
                'quarantined_count' => ['required', 'integer', 'min:0'],
                'trip_queue_count' => ['required', 'integer', 'min:0'],
                'queue_age_seconds' => ['nullable', 'integer', 'min:0'],
                'blocked_reason' => ['nullable', 'string', 'max:80'],
                'location_enabled' => ['nullable', 'boolean'],
                'location_permission' => ['nullable', 'string', 'max:30'],
                'foreground_service_running' => ['nullable', 'boolean'],
                'sample_unavailable_count' => ['required', 'integer', 'min:0'],
                'persistence_failure_count' => ['required', 'integer', 'min:0'],
                'live_upload_attempt_count' => ['required', 'integer', 'min:0'],
                'live_upload_success_count' => ['required', 'integer', 'min:0'],
                'live_upload_failure_count' => ['required', 'integer', 'min:0'],
                'recovery_upload_attempt_count' => ['required', 'integer', 'min:0'],
                'recovery_upload_success_count' => ['required', 'integer', 'min:0'],
                'recovery_upload_failure_count' => ['required', 'integer', 'min:0'],
                'buffer_eviction_count' => ['required', 'integer', 'min:0'],
                'rejected_point_count' => ['required', 'integer', 'min:0'],
            ])->validate();
        }

        $assignment = null;
        if (!empty($data['assignment_id'])) {
            $assignment = DriverAssignment::query()
                ->whereKey($data['assignment_id'])
                ->where('driver_id', $driver->id)
                ->firstOrFail();
        }

        $incident = DriverTrackingIncident::query()->firstOrNew([
            'incident_id' => $data['incident_id'],
        ]);
        abort_if($incident->exists && (string) $incident->driver_id !== (string) $driver->id, 403);
        $incident->fill([
            'driver_id' => $driver->id,
            'assignment_id' => $assignment?->id,
            'booking_id' => $assignment?->booking_id,
            'booking_item_id' => $assignment?->booking_item_id,
            'state' => $data['state'],
            'cause' => $data['cause'],
            'started_at' => $data['started_at'],
            'reported_at' => now(),
            'recovered_at' => $data['state'] === 'recovered'
                ? ($data['recovered_at'] ?? now())
                : null,
            'first_evidence' => $data['first_evidence'],
            'last_evidence' => $data['last_evidence'],
        ])->save();

        return response()->json(['status' => 'success', 'data' => ['acknowledged' => true]], 202);
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

    private function locationHealthContext($driver): array
    {
        $session = $driver?->activeSession;
        $assignment = $session?->assignment;

        return [
            'driver_id' => $driver?->id,
            'session_id' => $session?->id,
            'assignment_id' => $assignment?->id ?? $session?->assignment_id,
            'booking_id' => $assignment?->booking_id,
            'booking_item_id' => $assignment?->booking_item_id,
        ];
    }
}
