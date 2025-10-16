<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\TwoFactorRequest;
use App\Services\AuthService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class TwoFactorController extends Controller
{
    protected $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * Enable two-factor authentication
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function enable(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            if ($user->hasTwoFactorEnabled()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Two-factor authentication is already enabled'
                ], 400);
            }

            $secret = $this->authService->generateTwoFactorSecret();
            $this->authService->enableTwoFactor($user, $secret);

            return response()->json([
                'status' => 'success',
                'message' => 'Two-factor authentication enabled successfully',
                'data' => [
                    'recovery_codes' => $user->two_factor_recovery_codes,
                    'qr_code_url' => $this->getQrCodeUrl($user, $secret)
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to enable two-factor authentication',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Disable two-factor authentication
     *
     * @param TwoFactorRequest $request
     * @return JsonResponse
     */
    public function disable(TwoFactorRequest $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            if (!$user->hasTwoFactorEnabled()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Two-factor authentication is not enabled'
                ], 400);
            }

            // Verify current password or 2FA code before disabling
            if (!$this->authService->verifyTwoFactorCode($user, $request->code)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid two-factor authentication code'
                ], 401);
            }

            $this->authService->disableTwoFactor($user);

            return response()->json([
                'status' => 'success',
                'message' => 'Two-factor authentication disabled successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to disable two-factor authentication',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Verify two-factor authentication code
     *
     * @param TwoFactorRequest $request
     * @return JsonResponse
     */
    public function verify(TwoFactorRequest $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            if (!$user->hasTwoFactorEnabled()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Two-factor authentication is not enabled'
                ], 400);
            }

            $isValid = $this->authService->verifyTwoFactorCode($user, $request->code);

            if (!$isValid) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid two-factor authentication code'
                ], 401);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Two-factor authentication code verified successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to verify two-factor authentication code',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get QR code for two-factor authentication setup
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function qrCode(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            if (!$user->hasTwoFactorEnabled()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Two-factor authentication is not enabled'
                ], 400);
            }

            $secret = decrypt($user->two_factor_secret);
            $qrCodeUrl = $this->getQrCodeUrl($user, $secret);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'qr_code_url' => $qrCodeUrl,
                    'manual_entry_key' => $secret
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to generate QR code',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get recovery codes
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function recoveryCodes(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            if (!$user->hasTwoFactorEnabled()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Two-factor authentication is not enabled'
                ], 400);
            }

            return response()->json([
                'status' => 'success',
                'data' => [
                    'recovery_codes' => $user->two_factor_recovery_codes
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get recovery codes',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Regenerate recovery codes
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            if (!$user->hasTwoFactorEnabled()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Two-factor authentication is not enabled'
                ], 400);
            }

            $recoveryCodes = $user->generateTwoFactorRecoveryCodes();
            $user->update(['two_factor_recovery_codes' => $recoveryCodes]);

            return response()->json([
                'status' => 'success',
                'message' => 'Recovery codes regenerated successfully',
                'data' => [
                    'recovery_codes' => $recoveryCodes
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to regenerate recovery codes',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get QR code URL for Google Authenticator
     *
     * @param \App\Models\User $user
     * @param string $secret
     * @return string
     */
    private function getQrCodeUrl($user, $secret): string
    {
        $appName = config('app.name');
        $email = $user->email;
        
        // This generates a URL that can be used to create a QR code
        // In production, you might want to use a proper QR code library
        $url = "otpauth://totp/{$appName}:{$email}?secret={$secret}&issuer={$appName}";
        
        return "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=" . urlencode($url);
    }
}
