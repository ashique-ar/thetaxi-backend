<?php

namespace App\Services\Driver;

use App\Models\User;
use App\Models\Driver\Driver;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;

/**
 * Driver Authentication Service
 * 
 * Handles authentication for the driver mobile application using Laravel Passport.
 * Provides login, logout, token refresh, and token management functionality for drivers.
 * 
 * @see Requirements 2.1, 2.2, 2.3, 2.5, 2.6
 */
class DriverAuthService
{
    /**
     * @var DeviceService
     */
    protected DeviceService $deviceService;

    /**
     * @var SessionService
     */
    protected SessionService $sessionService;

    public function __construct(DeviceService $deviceService, SessionService $sessionService)
    {
        $this->deviceService = $deviceService;
        $this->sessionService = $sessionService;
    }

    /**
     * Authenticate a driver and issue Passport tokens.
     * 
     * Validates credentials, verifies driver context exists, revokes any existing
     * tokens for single-session enforcement, and issues new access and refresh tokens.
     * Also registers/updates device information if provided.
     *
     * @param array $credentials Array containing 'email', 'password', 'device_uuid', and optional device info
     * @return array Contains 'access_token', 'refresh_token', 'expires_in', 'user', 'driver', and 'device' data
     * @throws ValidationException If credentials are invalid or user is not a driver
     * 
     * @see Requirement 2.1 - Passport token issuance for valid credentials
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

        // Revoke all existing tokens for single-session enforcement
        $this->revokeAllTokens($user);

        // Create new Passport token with driver scope
        $tokenResult = $user->createToken('driver-mobile', ['driver']);
        $token = $tokenResult->token;

        // Set token expiration (30 days for access token)
        $token->expires_at = now()->addDays(30);
        $token->save();

        // Update last login timestamp
        $user->updateLastLogin();

        // Register/update device information FIRST
        $device = null;
        // Device UUID is now optional - backend generates if not provided
        if (isset($credentials['device_uuid']) || isset($credentials['device_fingerprint']) || isset($credentials['platform'])) {
            $deviceData = $this->extractDeviceData($credentials);
            $device = $this->deviceService->registerDevice($driver, $deviceData);

            // Update driver's current device UUID
            $driver->update([
                'current_device_uuid' => $device->device_uuid
            ]);

            // Deactivate other devices for single-session enforcement AFTER registration
            $this->deviceService->deactivateOtherDevices($driver, $device->device_uuid);
        }

        return [
            'user' => $user,
            'driver' => $driver,
            'device' => $device,
            'tokens' => [
                'access_token' => $tokenResult->accessToken,
                'token_type' => 'Bearer',
                'expires_at' => $token->expires_at->toISOString(),
                'refresh_token' => $token->id, // Use token ID as refresh identifier
                'scope' => 'driver'
            ]
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
            'device_uuid' => $credentials['device_uuid'] ?? null,
            'device_fingerprint' => $credentials['device_fingerprint'] ?? null,
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
     * Refresh an access token using a refresh token (token ID).
     *
     * @param string $tokenId The token ID used as refresh token
     * @return array Contains new 'access_token', 'refresh_token', and 'expires_in'
     * @throws \Exception If refresh token is invalid
     */
    public function refreshToken(string $tokenId): array
    {
        // Find the existing token
        $token = \Laravel\Passport\Token::find($tokenId);

        if (!$token || $token->revoked) {
            throw new \Exception('Invalid or revoked token');
        }

        if ($token->expires_at && $token->expires_at < now()) {
            throw new \Exception('Token expired');
        }

        $user = User::find($token->user_id);

        if (!$user || !$user->isActive()) {
            throw new \Exception('User not found or inactive');
        }

        // Verify user is still a driver
        if (!$this->isDriver($user)) {
            throw new \Exception('User is no longer registered as a driver');
        }

        // Revoke old token
        $token->revoke();

        // Create new token with same scopes
        $tokenResult = $user->createToken('driver-mobile', ['driver']);
        $newToken = $tokenResult->token;

        // Set token expiration (30 days)
        $newToken->expires_at = now()->addDays(30);
        $newToken->save();

        return [
            'access_token' => $tokenResult->accessToken,
            'refresh_token' => $newToken->id,
            'token_type' => 'Bearer',
            'expires_in' => $newToken->expires_at->diffInSeconds(now()),
            'expires_at' => $newToken->expires_at->toISOString(),
        ];
    }

    /**
     * Logout a driver by revoking their current token.
     *
     * @param User $user The authenticated user to logout
     * @return void
     * 
     * @see Requirement 2.5 - Token revocation on logout
     */
    public function logout(User $user, ?string $deviceUuid = null): void
    {
        $driver = $this->getDriver($user);
        $logoutDeviceUuid = $deviceUuid;

        if ($driver) {
            $driver->loadMissing('activeSession');

            $logoutDeviceUuid = $logoutDeviceUuid ?: $driver->current_device_uuid;

            if ($driver->activeSession) {
                $this->sessionService->endSession($driver, []);
                $driver->refresh();
            }

            if ($logoutDeviceUuid) {
                $this->deviceService->deactivateDevice($driver, $logoutDeviceUuid);
            }

            $driver->update([
                'is_online' => false,
                'current_device_uuid' => null,
                'last_active_at' => now(),
            ]);
        }

        // Revoke the current access token
        $token = $user->token();
        if ($token) {
            $token->revoke();
        }
    }

    /**
     * Revoke all tokens for a user.
     *
     * @param User $user The user whose tokens should be revoked
     * @return void
     * 
     * @see Requirement 2.3 - Single active session enforcement
     */
    public function revokeAllTokens(User $user): void
    {
        $user->tokens()->update(['revoked' => true]);
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
