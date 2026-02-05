<?php
// app/Http/Controllers/Api/DriverController.php
namespace App\Http\Controllers\Api\Driver;

use App\Http\Controllers\Controller;
use App\Models\Driver\Driver;
use App\Models\Driver\DriverSession;
use App\Http\Requests\Driver\Driver\CreateDriverRequest;
use App\Http\Requests\Driver\Driver\UpdateDriverRequest;
use App\Http\Resources\Driver\DriverResource;
use App\Http\Resources\Driver\DriverSessionResource;
use App\Http\Resources\Driver\RoutePointResource;
use App\Models\User;
use App\Services\UserContextService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Carbon\Carbon;

class DriverController extends Controller
{
    private $contextService;

    public function __construct(UserContextService $contextService)
    {
        $this->contextService = $contextService;
        $this->middleware('permission:drivers.view')->only(['index', 'show', 'status', 'sessions', 'sessionRoute', 'locations', 'analytics', 'devices']);
        $this->middleware('permission:drivers.create')->only(['store']);
        $this->middleware('permission:drivers.edit')->only(['update', 'deactivateDevice', 'removeDevice']);
        $this->middleware('permission:drivers.delete')->only(['destroy']);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $q = Driver::with('user');
        if ($request->filled('search')) {
            $q->where('code', 'like', '%' . $request->search . '%');
        }
        return DriverResource::collection($q->paginate($request->per_page ?? 15));
    }

    public function store(CreateDriverRequest $request): JsonResponse
    {
        $data = $request->validated();

        try {
            $existingUser = User::where('email', $data['email'])->first();

            if ($existingUser) {
                $existingContext = \App\Models\UserContext::where('user_id', $existingUser->id)
                    ->where('context_type', 'driver')
                    ->where('is_active', true)
                    ->first();

                if ($existingContext) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'This user is already registered as a driver.',
                        'errors' => [
                            'email' => ['This email is already registered as a driver.']
                        ]
                    ], 422);
                }

                $contextData = [
                    'owner_type_id' => $data['owner_type_id'] ?? null,
                    'address' => $data['address'] ?? null,
                    'country_id' => $data['country_id'] ?? null,
                    'state_id' => $data['state_id'] ?? null,
                    'city' => $data['city'] ?? null,
                    'license_number' => $data['license_number'] ?? null,
                    'license_expiry' => $data['license_expiry'] ?? null,
                    'notes' => $data['notes'] ?? null,
                ];

                $context = $this->contextService->switchContext($existingUser, 'driver', $contextData);
                $driver = Driver::find($context->getAttribute('context_id'));

                return response()->json([
                    'status' => 'success',
                    'message' => 'Driver context created for existing user',
                    'data' => ['driver' => new DriverResource($driver)]
                ], 201);

            } else {
                $user = User::create([
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'email' => $data['email'],
                    'password' => bcrypt($data['password'] ?? Str::random(12)),
                    'phone' => $data['phone'],
                    'email_verified_at' => now(),
                    'is_active' => true,
                ]);

                $contextData = [
                    'license_no' => $data['license_no'] ?? null,
                    'license_type' => $data['license_type_id'] ?? null,
                    'license_expiry' => $data['license_expiry'] ?? null,
                    'country_id' => $data['country_id'] ?? null,
                    'state_id' => $data['state_id'] ?? null,
                    'address' => $data['address'] ?? null,
                    'city' => $data['city'] ?? null,
                    'postal_code' => $data['postal_code'] ?? null,
                    'emergency_contact_name' => $data['emergency_contact_name'] ?? null,
                    'emergency_contact_phone' => $data['emergency_contact_phone'] ?? null,
                    'blood_group' => $data['blood_group'] ?? null,
                    'medical_conditions' => $data['medical_conditions'] ?? null,
                    'hire_date' => $data['hire_date'] ?? null,
                    'is_active' => $data['is_active'] ?? null,
                ];

                $context = $this->contextService->switchContext($user, 'driver', $contextData);
                $driver = Driver::find($context->getAttribute('context_id'));

                return response()->json([
                    'status' => 'success',
                    'message' => 'Driver created successfully',
                    'data' => ['driver' => new DriverResource($driver)]
                ], 201);
            }

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create driver',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show(Driver $driver): JsonResponse
    {
        $driver->load(['user', 'country', 'state', 'licenseType']);
        return response()->json([
            'status' => 'success',
            'data' => new DriverResource($driver)
        ]);
    }

    public function update(UpdateDriverRequest $request, Driver $driver): JsonResponse
    {
        try {
            $data = $request->validated();
            $data['updated_user_id'] = $request->user()->id;

            // Separate user data from vehicle owner data
            $userData = array_intersect_key($data, array_flip([
                'first_name',
                'last_name',
                'email',
                'phone'
            ]));

            $driverData = array_diff_key($data, $userData);

            // Update user data if provided
            if (!empty($userData)) {
                $driver->user->update($userData);
            }

            // Update driver data
            $driver->update($driverData);

            // Reload the relationship to get updated data
            $driver->load('user');

            return response()->json([
                'status' => 'success',
                'message' => 'Driver updated successfully',
                'data' => ['driver' => new DriverResource($driver)]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update vehicle owner',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy(Driver $driver): JsonResponse
    {
        $driver->delete();
        return response()->json([
            'status' => 'success',
            'message' => 'Driver deleted'
        ]);
    }

    /**
     * Get driver's current online status and location.
     * 
     * GET /api/drivers/{driver}/status
     * 
     * @see Requirements 9.2, 9.3
     */
    public function status(Driver $driver): JsonResponse
    {
        $driver->load('activeSession');
        
        return response()->json([
            'status' => 'success',
            'data' => [
                'driver_id' => $driver->id,
                'is_online' => $driver->is_online,
                'last_active_at' => $driver->last_active_at?->toIso8601String(),
                'current_latitude' => $driver->current_latitude ? (float) $driver->current_latitude : null,
                'current_longitude' => $driver->current_longitude ? (float) $driver->current_longitude : null,
                'current_device_uuid' => $driver->current_device_uuid,
                'current_session' => $driver->activeSession 
                    ? new DriverSessionResource($driver->activeSession) 
                    : null,
            ]
        ]);
    }

    /**
     * Get driver's session history with pagination.
     * 
     * GET /api/drivers/{driver}/sessions
     * 
     * @see Requirements 9.4
     */
    public function sessions(Request $request, Driver $driver): JsonResponse
    {
        $query = $driver->sessions()
            ->orderBy('start_time', 'desc');
        
        // Optional status filter
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        
        // Optional date range filter
        if ($request->filled('from_date')) {
            $query->where('start_time', '>=', Carbon::parse($request->from_date)->startOfDay());
        }
        if ($request->filled('to_date')) {
            $query->where('start_time', '<=', Carbon::parse($request->to_date)->endOfDay());
        }
        
        $sessions = $query->paginate($request->per_page ?? 15);
        
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
    }

    /**
     * Get route points for a specific session.
     * 
     * GET /api/drivers/{driver}/sessions/{session}/route
     * 
     * @see Requirements 7.5
     */
    public function sessionRoute(Driver $driver, DriverSession $session): JsonResponse
    {
        // Verify the session belongs to this driver
        if ($session->driver_id !== $driver->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'Session does not belong to this driver'
            ], 404);
        }
        
        $routePoints = $session->routePoints()
            ->orderBy('recorded_at', 'asc')
            ->get();
        
        return response()->json([
            'status' => 'success',
            'data' => [
                'session' => new DriverSessionResource($session),
                'route_points' => RoutePointResource::collection($routePoints),
                'point_count' => $routePoints->count(),
            ]
        ]);
    }

    /**
     * Get current locations of online drivers.
     * 
     * GET /api/drivers/locations
     * 
     * @see Requirements 9.5, 9.6
     */
    public function locations(Request $request): JsonResponse
    {
        $query = Driver::query()
            ->where('is_online', true)
            ->whereNotNull('current_latitude')
            ->whereNotNull('current_longitude');
        
        // Optional filter by specific driver IDs
        if ($request->filled('driver_ids')) {
            $driverIds = is_array($request->driver_ids) 
                ? $request->driver_ids 
                : explode(',', $request->driver_ids);
            $query->whereIn('id', $driverIds);
        }
        
        $drivers = $query->with('user:id,first_name,last_name')->get();
        
        $locations = $drivers->map(function ($driver) {
            return [
                'driver_id' => $driver->id,
                'driver_name' => $driver->user ? 
                    trim($driver->user->first_name . ' ' . $driver->user->last_name) : 
                    ($driver->code ?? 'Unknown'),
                'driver_code' => $driver->code,
                'latitude' => (float) $driver->current_latitude,
                'longitude' => (float) $driver->current_longitude,
                'is_online' => $driver->is_online,
                'last_active_at' => $driver->last_active_at?->toIso8601String(),
            ];
        });
        
        return response()->json([
            'status' => 'success',
            'data' => $locations,
            'meta' => [
                'total_online' => $locations->count(),
                'timestamp' => now()->toIso8601String(),
            ]
        ]);
    }

    /**
     * Get devices registered for a driver.
     * 
     * GET /api/drivers/{driver}/devices
     * 
     * @see Requirement 3.1
     */
    public function devices(Request $request, Driver $driver): JsonResponse
    {
        $query = $driver->devices()->orderBy('last_active_at', 'desc');
        
        // Optional filter by active status
        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }
        
        // Optional filter by platform
        if ($request->filled('platform')) {
            $query->where('platform', $request->platform);
        }
        
        $devices = $query->get();
        
        return response()->json([
            'status' => 'success',
            'data' => \App\Http\Resources\Driver\DriverDeviceResource::collection($devices),
            'meta' => [
                'total' => $devices->count(),
                'active' => $devices->where('is_active', true)->count(),
                'inactive' => $devices->where('is_active', false)->count(),
            ]
        ]);
    }

    /**
     * Deactivate a specific device for a driver.
     * 
     * POST /api/drivers/{driver}/devices/{deviceUuid}/deactivate
     */
    public function deactivateDevice(Driver $driver, string $deviceUuid): JsonResponse
    {
        $device = $driver->devices()->where('device_uuid', $deviceUuid)->first();
        
        if (!$device) {
            return response()->json([
                'status' => 'error',
                'message' => 'Device not found'
            ], 404);
        }
        
        $device->deactivate();
        
        return response()->json([
            'status' => 'success',
            'message' => 'Device deactivated successfully',
            'data' => new \App\Http\Resources\Driver\DriverDeviceResource($device)
        ]);
    }

    /**
     * Remove a specific device for a driver.
     * 
     * DELETE /api/drivers/{driver}/devices/{deviceUuid}
     */
    public function removeDevice(Driver $driver, string $deviceUuid): JsonResponse
    {
        $device = $driver->devices()->where('device_uuid', $deviceUuid)->first();
        
        if (!$device) {
            return response()->json([
                'status' => 'error',
                'message' => 'Device not found'
            ], 404);
        }
        
        $device->delete();
        
        return response()->json([
            'status' => 'success',
            'message' => 'Device removed successfully'
        ]);
    }

    /**
     * Get driver activity analytics.
     * 
     * GET /api/drivers/{driver}/analytics
     * 
     * @see Requirements 11.1, 11.3, 11.4
     */
    public function analytics(Request $request, Driver $driver): JsonResponse
    {
        // Default to last 30 days if no date range specified
        $fromDate = $request->filled('from_date') 
            ? Carbon::parse($request->from_date)->startOfDay()
            : now()->subDays(30)->startOfDay();
        $toDate = $request->filled('to_date')
            ? Carbon::parse($request->to_date)->endOfDay()
            : now()->endOfDay();
        
        // Get sessions within date range
        $sessions = $driver->sessions()
            ->where('start_time', '>=', $fromDate)
            ->where('start_time', '<=', $toDate)
            ->get();
        
        // Calculate analytics
        $completedSessions = $sessions->whereIn('status', ['completed', 'auto_closed']);
        
        $totalOnlineSeconds = $completedSessions->sum(function ($session) {
            if ($session->start_time && $session->end_time) {
                return $session->end_time->diffInSeconds($session->start_time);
            }
            return 0;
        });
        
        $sessionCount = $completedSessions->count();
        $averageDurationSeconds = $sessionCount > 0 
            ? $totalOnlineSeconds / $sessionCount 
            : 0;
        
        $totalDistanceKm = $completedSessions->sum('total_distance_km') ?? 0;
        
        // Calculate daily breakdown
        $dailyStats = $completedSessions->groupBy(function ($session) {
            return $session->start_time->format('Y-m-d');
        })->map(function ($daySessions) {
            $dayOnlineSeconds = $daySessions->sum(function ($session) {
                if ($session->start_time && $session->end_time) {
                    return $session->end_time->diffInSeconds($session->start_time);
                }
                return 0;
            });
            
            return [
                'session_count' => $daySessions->count(),
                'online_hours' => round($dayOnlineSeconds / 3600, 2),
                'total_distance_km' => round($daySessions->sum('total_distance_km') ?? 0, 2),
            ];
        });
        
        return response()->json([
            'status' => 'success',
            'data' => [
                'driver_id' => $driver->id,
                'period' => [
                    'from' => $fromDate->toIso8601String(),
                    'to' => $toDate->toIso8601String(),
                ],
                'summary' => [
                    'total_sessions' => $sessionCount,
                    'active_sessions' => $sessions->where('status', 'active')->count(),
                    'auto_closed_sessions' => $sessions->where('status', 'auto_closed')->count(),
                    'total_online_hours' => round($totalOnlineSeconds / 3600, 2),
                    'total_online_minutes' => round($totalOnlineSeconds / 60, 2),
                    'average_session_duration_minutes' => round($averageDurationSeconds / 60, 2),
                    'total_distance_km' => round($totalDistanceKm, 2),
                ],
                'daily_breakdown' => $dailyStats,
                'current_status' => [
                    'is_online' => $driver->is_online,
                    'last_active_at' => $driver->last_active_at?->toIso8601String(),
                ],
            ]
        ]);
    }
}
