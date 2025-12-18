<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\TokenRepository;

class AuthController extends Controller
{
    protected $authService;
    protected $tokenRepository;

    public function __construct(
        AuthService $authService,
        TokenRepository $tokenRepository
    ) {
        $this->authService = $authService;
        $this->tokenRepository = $tokenRepository;
    }

    /**
     * Register a new user
     *
     * @param RegisterRequest $request
     * @return JsonResponse
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        try {
            $user = $this->authService->register($request->validated());
            $token = $this->authService->createToken($user, 'API Token', $request);

            return response()->json([
                'status' => 'success',
                'message' => 'User registered successfully',
                'data' => [
                    'user' => new UserResource($user),
                    'token' => $token
                ]
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Registration failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Login user
     *
     * @param LoginRequest $request
     * @return JsonResponse
     */
    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $credentials = $request->only('email', 'password');
            $user = $this->authService->authenticate($credentials);
            
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid credentials'
                ], 401);
            }

            $token = $this->authService->createToken($user, 'API Token with Refresh', $request);
            $user->updateLastLogin();

            return response()->json([
                'status' => 'success',
                'message' => 'Login successful',
                'data' => [
                    'user' => new UserResource($user),
                    'token' => $token
                ]
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Login failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Login user with OAuth2 (includes refresh token)
     *
     * @param LoginRequest $request
     * @return JsonResponse
     */
    public function loginWithRefresh(LoginRequest $request): JsonResponse
    {
        try {
            $credentials = $request->only('email', 'password');
            $result = $this->authService->authenticateWithRefresh($credentials, $request);

            return response()->json([
                'status' => 'success',
                'message' => 'Login successful',
                'data' => [
                    'user' => new UserResource($result['user']),
                    'token' => $result['tokens']
                ]
            ],200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Login failed',
                'error' => $e->getMessage()
            ], 401);
        }
    }

    /**
     * Refresh access token using refresh token
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function refreshToken(Request $request): JsonResponse
    {
        $request->validate([
            'refresh_token' => 'required|string'
        ]);

        try {
            $tokens = $this->authService->refreshToken($request->refresh_token, $request);

            return response()->json([
                'status' => 'success',
                'message' => 'Token refreshed successfully',
                'data' => [
                    'tokens' => $tokens
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Token refresh failed',
                'error' => $e->getMessage()
            ], 401);
        }
    }

    /**
     * Logout user
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $this->authService->logout($user);

            return response()->json([
                'status' => 'success',
                'message' => 'Logged out successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Logout failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Logout from all devices
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function logoutAll(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $this->authService->logoutAll($user);

            return response()->json([
                'status' => 'success',
                'message' => 'Logged out from all devices successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Logout failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get user profile
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function profile(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            return response()->json([
                'status' => 'success',
                'data' => [
                    'user' => new UserResource($user)
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch profile',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Change password
     *
     * @param ChangePasswordRequest $request
     * @return JsonResponse
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        try {
            $user = $request->user();
            $this->authService->changePassword($user, $request->validated());

            return response()->json([
                'status' => 'success',
                'message' => 'Password changed successfully'
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Password change failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Send password reset email
     *
     * @param ForgotPasswordRequest $request
     * @return JsonResponse
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        try {
            $this->authService->sendPasswordResetEmail($request->email);

            return response()->json([
                'status' => 'success',
                'message' => 'Password reset email sent successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to send password reset email',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reset password
     *
     * @param ResetPasswordRequest $request
     * @return JsonResponse
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        try {
            $this->authService->resetPassword($request->validated());

            return response()->json([
                'status' => 'success',
                'message' => 'Password reset successfully'
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Password reset failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Verify email
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function verifyEmail(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $this->authService->verifyEmail($user);

            return response()->json([
                'status' => 'success',
                'message' => 'Email verified successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Email verification failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Resend email verification
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function resendEmailVerification(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $this->authService->resendEmailVerification($user);

            return response()->json([
                'status' => 'success',
                'message' => 'Email verification sent successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to send email verification',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get user's active sessions
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function sessions(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $sessions = $this->authService->getActiveSessions($user);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'sessions' => $sessions
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch sessions',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Revoke specific session
     *
     * @param Request $request
     * @param string $tokenId
     * @return JsonResponse
     */
    public function revokeSession(Request $request, string $tokenId): JsonResponse
    {
        try {
            $user = $request->user();
            $this->authService->revokeSession($user, $tokenId);

            return response()->json([
                'status' => 'success',
                'message' => 'Session revoked successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to revoke session',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
