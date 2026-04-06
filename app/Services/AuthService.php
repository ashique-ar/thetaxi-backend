<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\EmailVerificationNotification;
use App\Notifications\PasswordResetNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Token;
use Laravel\Passport\Client;
use Carbon\Carbon;

class AuthService
{
    public function __construct()
    {
        //
    }

    /**
     * Register a new user
     *
     * @param array $userData
     * @return User
     * @throws \Exception
     */
    public function register(array $userData): User
    {
        DB::beginTransaction();
        try {
            $userData['password'] = Hash::make($userData['password']);
            $userData['is_active'] = true;
            $userData['password_changed_at'] = now();
            
            $user = User::create($userData);
            
            // Send email verification
            $this->sendEmailVerification($user);
            
            DB::commit();
            return $user;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Authenticate user
     *
     * @param array $credentials
     * @return User|null
     * @throws ValidationException
     */
    public function authenticate(array $credentials): ?User
    {
        $user = User::where('email', $credentials['email'])->first();
        
        if (!$user) {
            throw ValidationException::withMessages([
                'email' => 'User not found'
            ]);
        }

        // Check if account is locked
        if ($user->isLocked()) {
            throw ValidationException::withMessages([
                'account' => 'Account is locked due to multiple failed attempts'
            ]);
        }

        // Check if account is active
        if (!$user->isActive()) {
            throw ValidationException::withMessages([
                'account' => 'Account is deactivated'
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
                'password' => 'Invalid password'
            ]);
        }

        // Reset login attempts on successful login
        $user->resetLoginAttempts();
        
        return $user;
    }

    /**
     * Create access token for user (Personal Access Token - simple, no refresh)
     *
     * @param User $user
     * @param string $tokenName
     * @return array
     */
    public function createToken(User $user, string $tokenName = 'API Token', $request = null): array
    {
        $token = $user->createToken($tokenName);

        // Log session metadata if request available
        try {
            if ($request) {
                $ua = $request->header('User-Agent');
                $parsed = $this->parseUserAgent($ua);

                // Prefer client provided IP/location when available, otherwise fallback to server-detected IP
                $clientIp = $request->input('client_ip', $request->ip());
                $clientLocation = $request->input('client_location');

                // Log what we're receiving for diagnostics
                \Log::info('AuthService::createToken - request ip: ' . $request->ip() . ' client_ip: ' . ($clientIp ?? 'NULL') . ' client_location: ' . ($clientLocation ?? 'NULL') . ' ua: ' . substr(($ua ?? 'NULL'), 0, 200));

                \App\Models\ApiSession::create([
                    'token_id' => $token->token->id,
                    'user_id' => $user->id,
                    'name' => $tokenName,
                    'ip_address' => $clientIp,
                    'user_agent' => $ua,
                    'device' => $parsed['device'] ?? null,
                    'browser' => $parsed['browser'] ?? null,
                    'os' => $parsed['os'] ?? null,
                    'location' => $clientLocation,
                    'last_active' => now(),
                    'current' => true
                ]);
            } else {
                \Log::info('AuthService::createToken - no request provided when creating token for user ' . $user->id);
            }
        } catch (\Throwable $e) {
            // don't block token creation on logging errors
            \Log::warning('Failed to create api_session record: ' . $e->getMessage());
        }

        return [
            'access_token' => $token->accessToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->token->expires_at,
            'scopes' => $token->token->scopes ?? []
        ];
    }

    /**
     * Authenticate user with OAuth2 credentials and return tokens with refresh support
     *
     * @param array $credentials
     * @return array
     * @throws \Exception
     */
    public function authenticateWithRefresh(array $credentials, $request = null): array
    {
        // First authenticate the user normally
        $user = $this->authenticate($credentials);
        
        if (!$user) {
            throw new \Exception('Authentication failed');
        }

        $tokens = $this->createTokenWithRefresh($user, $request, 'API Token with Refresh');

        // Update user's last login
        $user->updateLastLogin();

        return [
            'user' => $user,
            'tokens' => $tokens,
        ];
    }

    /**
     * Create a token payload compatible with the refresh-token login flow.
     *
     * @param User $user
     * @param mixed $request
     * @param string $tokenName
     * @return array
     */
    public function createTokenWithRefresh(User $user, $request = null, string $tokenName = 'API Token with Refresh'): array
    {
        // Create a Personal Access Token (simpler approach)
        $token = $user->createToken($tokenName);

        // If request available, log session metadata (mirror createToken behavior)
        try {
            if ($request) {
                $ua = $request->header('User-Agent');
                $parsed = $this->parseUserAgent($ua);

                $clientIp = $request->input('client_ip', $request->ip());
                $clientLocation = $request->input('client_location');

                \Log::info('AuthService::authenticateWithRefresh - request ip: ' . $request->ip() . ' client_ip: ' . ($clientIp ?? 'NULL') . ' client_location: ' . ($clientLocation ?? 'NULL') . ' ua: ' . substr(($ua ?? 'NULL'), 0, 200));

                \App\Models\ApiSession::create([
                    'token_id' => $token->token->id,
                    'user_id' => $user->id,
                    'name' => $tokenName,
                    'ip_address' => $clientIp,
                    'user_agent' => $ua,
                    'device' => $parsed['device'] ?? null,
                    'browser' => $parsed['browser'] ?? null,
                    'os' => $parsed['os'] ?? null,
                    'location' => $clientLocation,
                    'last_active' => now(),
                    'current' => true
                ]);
            }
        } catch (\Throwable $e) {
            \Log::warning('Failed to create api_session record (refresh flow): ' . $e->getMessage());
        }

        // For now, return a structure similar to OAuth2 response
        // We'll implement true refresh tokens later with a proper OAuth2 flow
        return [
            'access_token' => $token->accessToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->token->expires_at->toISOString(),
            'refresh_token' => $token->token->id, // Use token ID as refresh identifier
            'scope' => '*'
        ];
    }

    /**
     * Refresh token using token ID (simplified approach)
     *
     * @param string $tokenId
     * @return array
     * @throws \Exception
     */
    public function refreshToken(string $tokenId, $request = null): array
    {
        // Find the existing token
        $token = Token::find($tokenId);
        
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

        // Revoke old token
        $token->revoke();

        // Create new token
        $newToken = $user->createToken('API Token Refreshed');

        // Log session metadata if possible
        try {
            $meta = [
                'token_id' => $newToken->token->id,
                'user_id' => $user->id,
                'name' => 'API Token Refreshed',
                'last_active' => now(),
                'current' => true
            ];

            if ($request) {
                $ua = $request->header('User-Agent');
                $parsed = $this->parseUserAgent($ua);

                $clientIp = $request->input('client_ip', $request->ip());
                $clientLocation = $request->input('client_location');

                $meta['ip_address'] = $clientIp;
                $meta['user_agent'] = $ua;
                $meta['device'] = $parsed['device'] ?? null;
                $meta['browser'] = $parsed['browser'] ?? null;
                $meta['os'] = $parsed['os'] ?? null;
                $meta['location'] = $clientLocation;
            }

            \App\Models\ApiSession::create($meta);
        } catch (\Throwable $e) {
            \Log::warning('Failed to create api_session for refreshed token: ' . $e->getMessage());
        }

        return [
            'access_token' => $newToken->accessToken,
            'token_type' => 'Bearer',
            'expires_at' => $newToken->token->expires_at->toISOString(),
            'refresh_token' => $newToken->token->id, // New token ID as refresh identifier
            'scope' => '*'
        ];
    }

    /**
     * Revoke a specific token
     *
     * @param string $tokenId
     * @return bool
     */
    public function revokeToken(string $tokenId): bool
    {
        $token = Token::find($tokenId);
        
        if (!$token) {
            return false;
        }

        $token->revoke();
        return true;
    }

    /**
     * Logout user (revoke current token)
     *
     * @param User $user
     * @return void
     */
    public function logout(User $user): void
    {
        $token = $user->token();
        if ($token) {
            $token->revoke();
        }
    }

    /**
     * Logout from all devices (revoke all tokens)
     *
     * @param User $user
     * @return void
     */
    public function logoutAll(User $user): void
    {
        $user->revokeAllTokens();
    }

    /**
     * Change user password
     *
     * @param User $user
     * @param array $data
     * @return void
     * @throws ValidationException
     */
    public function changePassword(User $user, array $data): void
    {
        if (!Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'Current password is incorrect'
            ]);
        }

        $user->update([
            'password' => Hash::make($data['new_password']),
            'password_changed_at' => now()
        ]);

        // Optionally revoke all tokens to force re-login
        if (isset($data['revoke_tokens']) && $data['revoke_tokens']) {
            $this->logoutAll($user);
        }
    }

    /**
     * Send password reset email
     *
     * @param string $email
     * @return void
     * @throws \Exception
     */
    public function sendPasswordResetEmail(string $email): void
    {
        $user = User::where('email', $email)->first();
        
        if (!$user) {
            throw new \Exception('User not found');
        }

        $token = Password::createToken($user);
        
        $user->notify(new PasswordResetNotification($token));
    }

    /**
     * Reset password
     *
     * @param array $data
     * @return void
     * @throws ValidationException
     */
    public function resetPassword(array $data): void
    {
        $status = Password::reset($data, function ($user, $password) {
            $user->forceFill([
                'password' => Hash::make($password),
                'password_changed_at' => now(),
                'remember_token' => Str::random(60),
            ])->save();

            // Revoke all tokens
            $this->logoutAll($user);
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [__($status)]
            ]);
        }
    }

    /**
     * Verify email
     *
     * @param User $user
     * @return void
     */
    public function verifyEmail(User $user): void
    {
        if (!$user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }
    }

    /**
     * Send email verification
     *
     * @param User $user
     * @return void
     */
    public function sendEmailVerification(User $user): void
    {
        if (!$user->hasVerifiedEmail()) {
            $user->notify(new EmailVerificationNotification());
        }
    }

    /**
     * Resend email verification
     *
     * @param User $user
     * @return void
     */
    public function resendEmailVerification(User $user): void
    {
        if ($user->hasVerifiedEmail()) {
            throw new \Exception('Email already verified');
        }

        $this->sendEmailVerification($user);
    }

    /**
     * Get active sessions for user
     *
     * @param User $user
     * @return array
     */
    public function getActiveSessions(User $user): array
    {
        return $user->tokens()
            ->where('revoked', false)
            ->where('expires_at', '>', now())
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($token) {
                // Attach any api_session metadata if available
                $meta = \App\Models\ApiSession::where('token_id', $token->id)->latest()->first();

                // Compute lastActive as ISO8601 string or null
                $lastActive = null;
                if ($meta?->last_active) {
                    $lastActive = $meta->last_active->toIso8601String();
                } elseif ($token->last_used_at) {
                    $lastActive = $token->last_used_at->toIso8601String();
                }

                return [
                    'id' => $token->id,
                    'name' => $token->name,
                    'scopes' => $token->scopes,
                    'created_at' => $token->created_at,
                    'expires_at' => $token->expires_at,
                    'last_used_at' => $token->last_used_at,
                    // Metadata
                    'ip' => $meta?->ip_address ?? null,
                    'user_agent' => $meta?->user_agent ?? null,
                    'device' => $meta?->device ?? null,
                    'browser' => $meta?->browser ?? null,
                    'os' => $meta?->os ?? null,
                    'location' => $meta?->location ?? null,
                    'lastActive' => $lastActive,
                    'current' => (bool) ($meta?->current ?? false),
                ];
            })
            ->toArray();
    }

    /**
     * Revoke specific session
     *
     * @param User $user
     * @param string $tokenId
     * @return void
     * @throws \Exception
     */
    public function revokeSession(User $user, string $tokenId): void
    {
        $token = $user->tokens()->where('id', $tokenId)->first();
        
        if (!$token) {
            throw new \Exception('Session not found');
        }

        $token->revoke();
    }

    /**
     * Generate two-factor authentication secret
     *
     * @return string
     */
    public function generateTwoFactorSecret(): string
    {
        return random_bytes(32);
    }

    /**
     * Enable two-factor authentication
     *
     * @param User $user
     * @param string $secret
     * @return void
     */
    public function enableTwoFactor(User $user, string $secret): void
    {
        $user->enableTwoFactor($secret);
    }

    /**
     * Disable two-factor authentication
     *
     * @param User $user
     * @return void
     */
    public function disableTwoFactor(User $user): void
    {
        $user->disableTwoFactor();
    }

    /**
     * Verify two-factor authentication code
     *
     * @param User $user
     * @param string $code
     * @return bool
     */
    public function verifyTwoFactorCode(User $user, string $code): bool
    {
        if (!$user->hasTwoFactorEnabled()) {
            return false;
        }

        // This is a simplified version - in production, use a proper 2FA library
        $secret = decrypt($user->two_factor_secret);
        
        // Verify TOTP code or recovery code
        return $this->verifyTOTP($secret, $code) || $this->verifyRecoveryCode($user, $code);
    }

    /**
     * Verify TOTP code (Time-based One-Time Password)
     *
     * @param string $secret
     * @param string $code
     * @return bool
     */
    private function verifyTOTP(string $secret, string $code): bool
    {
        // Implement TOTP verification logic here
        // This is a placeholder - use a proper 2FA library like pragmarx/google2fa
        return true;
    }

    /**
     * Verify recovery code
     *
     * @param User $user
     * @param string $code
     * @return bool
     */
    private function verifyRecoveryCode(User $user, string $code): bool
    {
        $recoveryCodes = $user->two_factor_recovery_codes ?? [];
        
        if (in_array($code, $recoveryCodes)) {
            // Remove used recovery code
            $recoveryCodes = array_diff($recoveryCodes, [$code]);
            $user->update(['two_factor_recovery_codes' => $recoveryCodes]);
            
            return true;
        }

        return false;
    }

    /**
     * Parse a user agent string to extract a simple device/browser/os summary.
     *
     * @param string|null $ua
     * @return array
     */
    private function parseUserAgent(?string $ua): array
    {
        $result = [
            'device' => null,
            'browser' => null,
            'os' => null,
        ];

        if (!$ua) {
            return $result;
        }

        $uaLower = strtolower($ua);

        // Device
        if (stripos($ua, 'mobile') !== false || stripos($ua, 'iphone') !== false || stripos($ua, 'android') !== false) {
            $result['device'] = 'Mobile';
        } elseif (stripos($ua, 'ipad') !== false || stripos($ua, 'tablet') !== false) {
            $result['device'] = 'Tablet';
        } else {
            $result['device'] = 'Desktop';
        }

        // Browser (basic detection)
        if (preg_match('/(edge|edg)\//i', $ua)) {
            $result['browser'] = 'Edge';
        } elseif (preg_match('/opr\//i', $ua) || preg_match('/opera/i', $ua)) {
            $result['browser'] = 'Opera';
        } elseif (preg_match('/chrome\//i', $ua) && !preg_match('/(chromium)/i', $ua)) {
            $result['browser'] = 'Chrome';
        } elseif (preg_match('/safari/i', $ua) && !preg_match('/chrome/i', $ua)) {
            $result['browser'] = 'Safari';
        } elseif (preg_match('/firefox/i', $ua)) {
            $result['browser'] = 'Firefox';
        } else {
            $result['browser'] = 'Unknown';
        }

        // OS
        if (stripos($ua, 'windows') !== false) {
            $result['os'] = 'Windows';
        } elseif (stripos($ua, 'mac os x') !== false || stripos($ua, 'macintosh') !== false) {
            $result['os'] = 'macOS';
        } elseif (stripos($ua, 'android') !== false) {
            $result['os'] = 'Android';
        } elseif (stripos($ua, 'iphone') !== false || stripos($ua, 'ipad') !== false || stripos($ua, 'ios') !== false) {
            $result['os'] = 'iOS';
        } elseif (stripos($ua, 'linux') !== false) {
            $result['os'] = 'Linux';
        } else {
            $result['os'] = 'Unknown';
        }

        return $result;
    }

    /**
     * Get user statistics
     *
     * @param User $user
     * @return array
     */
    public function getUserStats(User $user): array
    {
        return [
            'total_logins' => $user->tokens()->count(),
            'active_sessions' => $user->tokens()->where('revoked', false)->count(),
            'last_login' => $user->last_login_at,
            'account_created' => $user->created_at,
            'email_verified' => $user->hasVerifiedEmail(),
            'phone_verified' => $user->hasVerifiedPhone(),
            'two_factor_enabled' => $user->hasTwoFactorEnabled(),
            'permissions' => $user->getPermissionsArray(),
            'roles' => $user->getRolesArray(),
        ];
    }
}
