<?php

namespace App\Http\Controllers\Api\Driver\Mobile;

use App\Enums\TripPhase;
use App\Http\Controllers\Controller;
use App\Http\Requests\Driver\Mobile\DriverLoginRequest;
use App\Http\Resources\Driver\DriverResource;
use App\Http\Resources\Driver\DriverDeviceResource;
use App\Http\Resources\UserResource;
use App\Models\DriverAssignment;
use App\Services\Driver\DriverAuthService;
use App\Services\Driver\MobileAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Driver Mobile Authentication Controller
 * 
 * Handles authentication for the driver mobile.
 * Provides login, logout, and profile endpoints specifically for drivers.
 * 
 * @see Requirements 2.1, 2.5, 2.7
 */
class AuthController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @param DriverAuthService $authService
     */
    public function __construct(
        private DriverAuthService $authService,
        private MobileAssignmentService $assignmentService
    ) {}

    /**
     * Authenticate a driver and return access tokens.
     *
     * Validates driver credentials and creates API tokens for mobile access.
     * Includes rate limiting for security.
     *
     * @param DriverLoginRequest $request
     * @return JsonResponse
     *
     * @see Requirement 2.1 - Driver authentication with credentials
     * @see Requirement 2.3 - Rate limiting for authentication attempts
     */
    public function login(DriverLoginRequest $request): JsonResponse
    {
        try {
            $result = $this->authService->login($request->validated());

            // Clear rate limit on successful login
            $request->clearRateLimit();

            // Get current or nearest upcoming assignment for the driver
            $driver = $result['driver'];
            $activeAssignment = $this->assignmentService->getCurrentAssignment($driver);

            return response()->json([
                'status' => 'success',
                'message' => 'Login successful',
                'data' => [
                    'user' => new UserResource($result['user']),
                    'driver' => new DriverResource($result['driver']),
                    'device' => isset($result['device']) ? new DriverDeviceResource($result['device']) : null,
                    'token' => $result['tokens'],
                    'current_assignment' => $activeAssignment,
                    'trip_phase' => $activeAssignment?->trip_phase?->value,
                ]
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid credentials',
                'error_code' => 'AUTH_INVALID_CREDENTIALS',
                'errors' => $e->errors()
            ], 401);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Login failed',
                'error_code' => 'AUTH_FAILED',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Logout the authenticated driver by revoking their current token.
     *
     * @param Request $request
     * @return JsonResponse
     * 
     * @see Requirement 2.5 - Token revocation on logout
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'device_uuid' => ['nullable', 'string', 'max:255'],
            ]);

            $this->authService->logout(
                $request->user(),
                $request->input('device_uuid')
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Logged out successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Logout failed',
                'error_code' => 'AUTH_LOGOUT_FAILED',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get the authenticated driver's profile.
     * 
     * Returns both user account data and driver-specific data.
     *
     * @param Request $request
     * @return JsonResponse
     * 
     * @see Requirement 2.7 - Profile access with valid token
     */
    public function profile(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $driver = $this->authService->getDriver($user);

            if (!$driver) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User is not registered as a driver',
                    'error_code' => 'AUTH_NOT_DRIVER'
                ], 403);
            }

            // Load relationships for the driver
            $driver->load(['user', 'country', 'state', 'licenseType']);

            // Compute assignment statistics
            $assignmentStats = [
                'total_assignments' => DriverAssignment::where('driver_id', $driver->id)->count(),
                'active_assignments' => DriverAssignment::where('driver_id', $driver->id)
                    ->where('status', 'active')->count(),
                'completed_assignments' => DriverAssignment::where('driver_id', $driver->id)
                    ->where('trip_phase', TripPhase::COMPLETED)->count(),
            ];

            return response()->json([
                'status' => 'success',
                'data' => [
                    'user' => new UserResource($user),
                    'driver' => new DriverResource($driver),
                    'assignment_statistics' => $assignmentStats,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch profile',
                'error_code' => 'AUTH_PROFILE_FAILED',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Refresh access token using refresh token (token ID).
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function refresh(Request $request): JsonResponse
    {
        $request->validate([
            'refresh_token' => 'required|string'
        ]);

        try {
            $tokens = $this->authService->refreshToken($request->refresh_token);

            return response()->json([
                'status' => 'success',
                'message' => 'Token refreshed successfully',
                'data' => $tokens
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Token refresh failed',
                'error_code' => 'AUTH_REFRESH_FAILED',
                'error' => $e->getMessage()
            ], 401);
        }
    }
}
