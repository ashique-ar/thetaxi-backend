<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\UserContextService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class UserContextController extends Controller
{
    protected $contextService;

    public function __construct(UserContextService $contextService)
    {
        $this->contextService = $contextService;
        $this->middleware('auth:api');
    }

    /**
     * Get user's available contexts
     */
    public function getAvailableContexts(Request $request): JsonResponse
    {
        $user = $request->user();
        $contexts = $this->contextService->getAvailableContexts($user);

        return response()->json([
            'status' => 'success',
            'data' => [
                'contexts' => $contexts,
                'primary_role' => $this->contextService->getPrimaryRole($user),
                'has_multiple_contexts' => $user->hasMultipleContexts()
            ]
        ]);
    }

    /**
     * Switch to a specific context
     */
    public function switchContext(Request $request): JsonResponse
    {
        $request->validate([
            'context_type' => 'required|string|in:customer,vehicle_owner,staff,agent,driver',
            'context_data' => 'sometimes|array' // Additional data for context creation
        ]);

        try {
            $user = $request->user();
            $contextData = $request->get('context_data', []);
            
            $context = $this->contextService->switchContext(
                $user, 
                $request->get('context_type'), 
                $contextData
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Context switched successfully',
                'data' => [
                    'context' => $context,
                    'available_contexts' => $this->contextService->getAvailableContexts($user)
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to switch context',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Deactivate a context
     */
    public function deactivateContext(Request $request): JsonResponse
    {
        $request->validate([
            'context_type' => 'required|string|in:customer,vehicle_owner,staff,agent,driver'
        ]);

        try {
            $user = $request->user();
            $success = $this->contextService->deactivateContext($user, $request->get('context_type'));

            if ($success) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Context deactivated successfully',
                    'data' => [
                        'available_contexts' => $this->contextService->getAvailableContexts($user)
                    ]
                ]);
            } else {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Failed to deactivate context'
                ], 400);
            }

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to deactivate context',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get current user profile with all contexts
     */
    public function getUserProfile(Request $request): JsonResponse
    {
        $user = $request->user()->load(['contexts.context']);
        
        return response()->json([
            'status' => 'success',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'roles' => $user->getRoleNames(),
                    'primary_role' => $this->contextService->getPrimaryRole($user),
                ],
                'contexts' => $user->getActiveContexts(),
                'available_contexts' => $this->contextService->getAvailableContexts($user)
            ]
        ]);
    }
}
