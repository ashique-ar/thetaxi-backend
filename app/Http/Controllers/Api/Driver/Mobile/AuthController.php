<?php

namespace App\Http\Controllers\Api\Driver\Mobile;

use App\Enums\TripPhase;
use App\Http\Controllers\Controller;
use App\Http\Requests\Driver\Mobile\DriverLoginRequest;
use App\Http\Resources\Driver\DriverResource;
use App\Http\Resources\Driver\DriverDeviceResource;
use App\Http\Resources\UserResource;
use App\Models\DriverAssignment;
use App\Models\Driver\DriverOnboardingApplication;
use App\Models\User;
use App\Services\Driver\DriverAuthService;
use App\Services\Driver\MobileAssignmentService;
use App\Services\Sms\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
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

    public function requestOtp(Request $request, SmsService $sms): JsonResponse
    {
        $mobile = $this->mobile($request->validate(['mobile' => ['required', 'string', 'max:30']])['mobile']);
        $appHash = trim((string) config('sms.driver_app_hash'));
        if ($appHash !== '' && ! preg_match('/^[A-Za-z0-9+\/]{11}$/', $appHash)) {
            throw new \RuntimeException('Invalid driver Android SMS app hash configuration.');
        }
        $otp = (string) random_int(100000, 999999);
        Cache::put($this->otpKey($mobile), Hash::make($otp), now()->addMinutes(10));
        $message = "Your driver app OTP is {$otp}. It expires in 10 minutes.";
        if ($appHash !== '') {
            $message .= "\n{$appHash}";
        }
        $sms->queueSingleMessage([
            'recipient' => $mobile, 'message' => $message,
            'source' => 'manual', 'context_type' => 'driver_authentication', 'event_key' => 'driver_authentication_otp',
        ]);

        return response()->json(['status' => 'success', 'message' => 'OTP sent.', 'data' => ['expires_in' => 600]]);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mobile' => ['required', 'string', 'max:30'], 'otp' => ['required', 'digits:6'],
            'device_uuid' => ['nullable', 'string', 'max:255'], 'device_fingerprint' => ['nullable', 'string', 'max:500'],
            'device_name' => ['nullable', 'string', 'max:255'], 'device_model' => ['nullable', 'string', 'max:255'],
            'device_manufacturer' => ['nullable', 'string', 'max:255'], 'platform' => ['nullable', Rule::in(['ios', 'android'])],
            'os_version' => ['nullable', 'string', 'max:50'], 'app_version' => ['nullable', 'string', 'max:50'],
            'app_build' => ['nullable', 'string', 'max:50'], 'push_token' => ['nullable', 'string', 'max:500'],
            'push_provider' => ['nullable', Rule::in(['fcm', 'apns'])], 'locale' => ['nullable', 'string', 'max:10'],
            'timezone' => ['nullable', 'string', 'max:50'],
        ]);
        $mobile = $this->mobile($data['mobile']);
        $hash = Cache::get($this->otpKey($mobile)) ?? Cache::get($this->legacyOtpKey($mobile));
        if (! $hash || ! Hash::check($data['otp'], $hash)) {
            throw ValidationException::withMessages(['otp' => ['The OTP is invalid or has expired.']]);
        }
        Cache::forget($this->otpKey($mobile));
        Cache::forget($this->legacyOtpKey($mobile));

        $user = $this->findUser($mobile);
        if ($user && $user->driverContext()) {
            $result = $this->authService->loginWithOtp($user, $data);
            $driver = $result['driver'];
            $activeAssignment = $this->assignmentService->getCurrentAssignment($driver);
            return response()->json(['status' => 'success', 'message' => 'Login successful', 'data' => [
                'flow' => 'login', 'user' => new UserResource($result['user']), 'driver' => new DriverResource($driver),
                'device' => new DriverDeviceResource($result['device']), 'token' => $result['tokens'],
                'current_assignment' => $activeAssignment, 'trip_phase' => $activeAssignment?->trip_phase?->value,
            ]]);
        }

        $token = Str::random(64);
        $application = DriverOnboardingApplication::create([
            'user_id' => $user?->id, 'mobile' => $mobile, 'access_token_hash' => hash('sha256', $token),
            'mobile_verified_at' => now(), 'payload' => ['identity' => array_filter([
                'first_name' => $user?->first_name, 'last_name' => $user?->last_name, 'email' => $user?->email,
            ])],
        ]);
        $applicationData = $application->load('documents')->toArray();
        unset($applicationData['payload']['identity']['dob']);

        return response()->json(['status' => 'success', 'data' => [
            'flow' => 'registration', 'onboarding_token' => $token, 'application' => $applicationData,
        ]], 201);
    }

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

            // Only return a truly current assignment here. Upcoming hires are
            // available from the assignments list endpoint and should not be
            // treated as current before their scheduled time.
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
            $errors = $e->errors();
            $user = \App\Models\User::where('email', strtolower((string) $request->input('email')))->first();

            if (array_key_exists('account', $errors) && $user?->isLocked()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Account locked after 5 failed login attempts. Try again when the lock expires or contact an administrator.',
                    'error_code' => 'AUTH_ACCOUNT_LOCKED',
                    'code' => 'account_locked',
                    'data' => [
                        'locked_until' => $user->locked_until->toIso8601String(),
                        'retry_after' => max(0, now()->diffInSeconds($user->locked_until, false)),
                    ],
                    'errors' => $errors,
                ], 423);
            }

            return response()->json([
                'status' => 'error',
                'message' => collect($errors)->flatten()->first() ?? 'Invalid credentials',
                'error_code' => 'AUTH_INVALID_CREDENTIALS',
                'errors' => $errors
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

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);
        $this->authService->sendPasswordResetEmail($data['email']);

        return response()->json([
            'status' => 'success',
            'message' => 'If an eligible driver account exists, a password reset OTP has been sent.',
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'otp' => ['required', 'digits:6'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->mixedCase()->numbers()->symbols()->uncompromised()],
        ]);

        try {
            $this->authService->resetPassword($data);
            return response()->json(['status' => 'success', 'message' => 'Password reset successfully. Please sign in again.']);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => collect($e->errors())->flatten()->first(),
                'error_code' => 'AUTH_RESET_INVALID',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'different:current_password', 'confirmed', Password::min(8)->letters()->mixedCase()->numbers()->symbols()->uncompromised()],
        ]);

        try {
            $this->authService->changePassword($request->user(), $data['current_password'], $data['new_password']);
            return response()->json([
                'status' => 'success',
                'message' => 'Password changed successfully. Please sign in again.',
                'data' => ['reauthentication_required' => true],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => collect($e->errors())->flatten()->first(),
                'error_code' => 'AUTH_PASSWORD_CHANGE_FAILED',
                'errors' => $e->errors(),
            ], 422);
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

    private function mobile(string $value): string
    {
        $mobile = '+'.preg_replace('/\D+/', '', $value);
        if (! str_starts_with(trim($value), '+') || ! preg_match('/^\+[1-9]\d{7,14}$/', $mobile)) {
            throw ValidationException::withMessages(['mobile' => ['Enter the mobile number in international format, for example +94771234567.']]);
        }
        return $mobile;
    }

    private function findUser(string $mobile): ?User
    {
        $digits = ltrim($mobile, '+');
        return User::whereRaw("REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, '+', ''), ' ', ''), '-', ''), '(', ''), ')', '') = ?", [$digits])->first();
    }

    private function otpKey(string $mobile): string { return 'driver_auth_otp:'.sha1($mobile); }
    private function legacyOtpKey(string $mobile): string { return 'driver_onboarding_otp:'.sha1($mobile); }
}
