<?php

namespace App\Services\Driver;

use App\Models\User;
use App\Models\Driver\Driver;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Driver Authentication Service
 * 
 * Handles authentication for the driver mobile application using Laravel Sanctum.
 * Provides login, logout, and token management functionality specifically for drivers.
 * 
 * @see Requirements 2.1, 2.2, 2.3, 2.5, 2.6
 */
class DriverAuthService
{
    /**
     * @var DeviceService
     */
    protected DeviceService $deviceService;

    public function __construct(DeviceService $deviceService)
    {
        $this->deviceService = $deviceService;
    }

    /**
     * Authenticate a driver and issue a Sanctum token.
     * 
     * Validates credentials, verifies driver context exists, revokes any existing
     * tokens for single-session enforcement, and issues a new token.
     *
     * @param array $credentials Array containing 'email', 'password', 'device_uuid', and optional device info
     * @return array Contains 'token', 'user', 'driver', and 'device' data
     * @throws ValidationException If credentials are invalid or user is not a driver
     * 
     * @see Requirement 2.1 - Sanctum token issuance
     * @see Requirement 2.2 - Link session to User and Driver records
     * @see Requirement 2.3 - Revoke previous session on new login
     * @see Requirement 2.6 - No duplicate records during authentication
     */
    public function login(array $credentials): array
    {
        // Find user by email
        $user = User::where('email', $credentials['email'])->first();

        if (!$user) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials']
            ]);
        }

        // Check if account is active
        if (!$user->isActive()) {
            throw ValidationException::withMessages([
                'account' => ['Account is deactivated']
            ]);
        }

        // Check if account is locked
        if ($user->isLocked()) {
            throw ValidationException::withMessages([
                'account' => ['Account is locked due to multiple failed attempts']
            ]);
        }

        // Verify password
        if (!Hash::check($credentials['password'], $user->password)) {
            $user->incrementLoginAttempts();

            // Lock account after 5 failed attempts
            if ($user->login_attempts >= 5) {
                $user->lockAccount();
            }

            throw ValidationException::withMessages([
                'password' => ['Invalid credentials']
            ]);
        }

        // Verify driver context exists
        $driverContext = $user->driverContext();
        if (!$driverContext) {
            throw ValidationException::withMessages([
                'driver' => ['User is not registered as a driver']
            ]);
        }

        /** @var Driver $driver */
        $driver = $driverContext->context;

        // Reset login attempts on successful authentication
        $user->resetLoginAttempts();

        // Revoke all existing Sanctum tokens for single-session enforcement
        $this->revokeAllTokens($user);

        // Create new Sanctum token
        $tokenName = 'driver-mobile-' . ($credentials['device_uuid'] ?? 'unknown');
        $token = $user->createToken($tokenName, ['driver']);

        // Update last login timestamp
        $user->updateLastLogin();

        // Update driver's current device UUID
        if (isset($credentials['device_uuid'])) {
            $driver->update([
                'current_device_uuid' => $credentials['device_uuid']
            ]);
            
            // Deactivate other devices for single-session enforcement
            $this->deviceService->deactivateOtherDevices($driver, $credentials['device_uuid']);
        }

        // Register/update device information
        $device = null;
        if (isset($credentials['device_uuid'])) {
            $deviceData = $this->extractDeviceData($credentials);
            $device = $this->deviceService->registerDevice($driver, $deviceData);
        }

        return [
            'token' => $token->plainTextToken,
            'user' => $user,
            'driver' => $driver,
            'device' => $device,
        ];
    }

    /**
     * Extract device data from credentials array.
     *
     * @param array $credentials The login credentials with device info
     * @return array Device data for registration
     */
    protected function extractDeviceData(array $credentials): array
    {
        return [
            'device_uuid' => $credentials['device_uuid'],
            'device_name' => $credentials['device_name'] ?? null,
            'device_model' => $credentials['device_model'] ?? null,
            'device_manufacturer' => $credentials['device_manufacturer'] ?? null,
            'platform' => $credentials['platform'] ?? 'unknown',
            'os_version' => $credentials['os_version'] ?? null,
            'app_version' => $credentials['app_version'] ?? null,
            'app_build' => $credentials['app_build'] ?? null,
            'push_token' => $credentials['push_token'] ?? null,
            'push_provider' => $credentials['push_provider'] ?? null,
            'locale' => $credentials['locale'] ?? null,
            'timezone' => $credentials['timezone'] ?? null,
            'ip_address' => request()->ip(),
        ];
    }

    /**
     * Logout a driver by revoking their current Sanctum token.
     *
     * @param User $user The authenticated user to logout
     * @return void
     * 
     * @see Requirement 2.5 - Token revocation on logout
     */
    public function logout(User $user): void
    {
        // Revoke the current access token
        $user->currentAccessToken()?->delete();
    }

    /**
     * Revoke all tokens except the current one for single-session enforcement.
     *
     * @param User $user The user whose other tokens should be revoked
     * @param string|null $currentTokenId The ID of the current token to keep (optional)
     * @return void
     * 
     * @see Requirement 2.3 - Single active session enforcement
     */
    public function revokeOtherTokens(User $user, ?string $currentTokenId = null): void
    {
        $query = $user->tokens();
        
        if ($currentTokenId) {
            $query->where('id', '!=', $currentTokenId);
        }
        
        $query->delete();
    }

    /**
     * Revoke all Sanctum tokens for a user.
     *
     * @param User $user The user whose tokens should be revoked
     * @return void
     */
    public function revokeAllTokens(User $user): void
    {
        $user->tokens()->delete();
    }

    /**
     * Get the driver associated with an authenticated user.
     *
     * @param User $user The authenticated user
     * @return Driver|null The driver record or null if not a driver
     */
    public function getDriver(User $user): ?Driver
    {
        $driverContext = $user->driverContext();
        return $driverContext?->context;
    }

    /**
     * Verify that a user has a valid driver context.
     *
     * @param User $user The user to verify
     * @return bool True if user is a driver, false otherwise
     */
    public function isDriver(User $user): bool
    {
        return $user->driverContext() !== null;
    }
}
