<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SocialAuthController extends Controller
{
    protected $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * Redirect to social provider
     *
     * @param string $provider
     * @return JsonResponse
     */
    public function redirect(string $provider): JsonResponse
    {
        $supportedProviders = ['google', 'facebook', 'github', 'linkedin'];
        
        if (!in_array($provider, $supportedProviders)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unsupported social provider'
            ], 400);
        }

        try {
            // In a real implementation, you would use Laravel Socialite
            // For now, we'll return a redirect URL
            $redirectUrl = config('app.url') . "/auth/{$provider}/callback";
            
            return response()->json([
                'status' => 'success',
                'data' => [
                    'redirect_url' => $redirectUrl,
                    'provider' => $provider
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to initiate social authentication',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Handle social provider callback
     *
     * @param Request $request
     * @param string $provider
     * @return JsonResponse
     */
    public function callback(Request $request, string $provider): JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string'],
            'state' => ['nullable', 'string'],
            'social_id' => ['required', 'string'],
            'email' => ['required', 'email'],
            'name' => ['required', 'string'],
            'avatar' => ['nullable', 'url'],
        ]);

        try {
            // Check if user exists with this social ID
            $user = User::where('social_id', $request->social_id)
                       ->where('social_provider', $provider)
                       ->first();

            if (!$user) {
                // Check if user exists with this email
                $user = User::where('email', $request->email)->first();
                
                if ($user) {
                    // Link existing account with social provider
                    $user->update([
                        'social_id' => $request->social_id,
                        'social_provider' => $provider,
                        'social_avatar' => $request->avatar,
                    ]);
                } else {
                    // Create new user
                    $nameParts = explode(' ', $request->name, 2);
                    $firstName = $nameParts[0];
                    $lastName = $nameParts[1] ?? '';

                    $user = User::create([
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                        'email' => $request->email,
                        'password' => Hash::make(Str::random(32)), // Random password
                        'social_id' => $request->social_id,
                        'social_provider' => $provider,
                        'social_avatar' => $request->avatar,
                        'email_verified_at' => now(), // Social accounts are considered verified
                        'is_active' => true,
                    ]);

                    // Assign default role
                    $defaultRole = \Spatie\Permission\Models\Role::where('name', 'customer')->first();
                    if ($defaultRole) {
                        $user->assignRole($defaultRole);
                    }
                }
            }

            // Generate token (pass request so session metadata is logged)
            $token = $this->authService->createToken($user, 'Social Login', $request);
            $user->updateLastLogin();

            return response()->json([
                'status' => 'success',
                'message' => 'Social authentication successful',
                'data' => [
                    'user' => new UserResource($user),
                    'token' => $token
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Social authentication failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Unlink social account
     *
     * @param Request $request
     * @param string $provider
     * @return JsonResponse
     */
    public function unlink(Request $request, string $provider): JsonResponse
    {
        try {
            $user = $request->user();
            
            if ($user->social_provider !== $provider) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'This social provider is not linked to your account'
                ], 400);
            }

            $user->update([
                'social_id' => null,
                'social_provider' => null,
                'social_avatar' => null,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Social account unlinked successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to unlink social account',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get linked social accounts
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function linked(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            $linkedAccounts = [];
            if ($user->social_provider) {
                $linkedAccounts[] = [
                    'provider' => $user->social_provider,
                    'social_id' => $user->social_id,
                    'avatar' => $user->social_avatar,
                    'linked_at' => $user->updated_at,
                ];
            }

            return response()->json([
                'status' => 'success',
                'data' => [
                    'linked_accounts' => $linkedAccounts
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve linked accounts',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
