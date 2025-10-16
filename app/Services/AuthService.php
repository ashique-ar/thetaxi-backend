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
    public function createToken(User $user, string $tokenName = 'API Token'): array
    {
        $token = $user->createToken($tokenName);
        
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
    public function authenticateWithRefresh(array $credentials): array
    {
        // First authenticate the user normally
        $user = $this->authenticate($credentials);
        
        if (!$user) {
            throw new \Exception('Authentication failed');
        }

        // Create a Personal Access Token (simpler approach)
        $token = $user->createToken('API Token with Refresh');
        
        // Update user's last login
        $user->updateLastLogin();

        // For now, return a structure similar to OAuth2 response
        // We'll implement true refresh tokens later with a proper OAuth2 flow
        return [
            'user' => $user,
            'tokens' => [
                'access_token' => $token->accessToken,
                'token_type' => 'Bearer',
                'expires_at' => $token->token->expires_at->toISOString(),
                'refresh_token' => $token->token->id, // Use token ID as refresh identifier
                'scope' => '*'
            ]
        ];
    }

    /**
     * Refresh token using token ID (simplified approach)
     *
     * @param string $tokenId
     * @return array
     * @throws \Exception
     */
    public function refreshToken(string $tokenId): array
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
                return [
                    'id' => $token->id,
                    'name' => $token->name,
                    'scopes' => $token->scopes,
                    'created_at' => $token->created_at,
                    'expires_at' => $token->expires_at,
                    'last_used_at' => $token->last_used_at,
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
