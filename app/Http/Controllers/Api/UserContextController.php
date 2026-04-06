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
        $contextState = $this->contextService->buildUserContextState(
            $user,
            $request->header('X-Active-Context-Type'),
            $request->header('X-Active-Context-Id'),
            $request->header('X-Active-Portal-Profile')
        );

        return response()->json([
            'status' => 'success',
            'data' => $contextState,
        ]);
    }

    /**
     * Switch to a specific context
     */
    public function switchContext(Request $request): JsonResponse
    {
        $request->validate([
            'context_type' => 'required|string|in:internal,customer,vehicle_owner,staff,agent,driver,corporate',
            'context_id' => 'sometimes|nullable|string',
            'context_data' => 'sometimes|array',
        ]);

        try {
            $user = $request->user();
            $contextType = (string) $request->get('context_type');
            $contextId = $request->input('context_id');
            $contextData = $request->get('context_data', []);

            if (!is_array($contextData)) {
                $contextData = [];
            }

            if ($contextId) {
                $contextData['context_id'] = $contextId;
            }

            if ($contextType !== 'internal') {
                if ($contextType === 'corporate' && !$contextId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'A corporate context must be selected.',
                    ], 422);
                }

                if (!$contextId) {
                    $this->contextService->switchContext(
                        $user,
                        $contextType,
                        $contextData
                    );
                }
            }

            $contextState = $this->contextService->buildUserContextState(
                $user->fresh([
                    'role',
                    'agent',
                    'permissions',
                    'roles.permissions',
                    'contexts.roles.permissions',
                    'contexts.corporateEmployee.user',
                    'contexts.corporateEmployee.corporate',
                    'contexts.corporateEmployee.department',
                    'contexts.corporateEmployee.division',
                ]),
                $contextType,
                $contextId ? (string) $contextId : null,
                $contextType === 'internal' ? 'internal' : null
            );

            $activeContext = $contextState['active_context'];
            $matchesRequested = $activeContext
                && (
                    ($contextId
                        && (string) $activeContext['context_type'] === $contextType
                        && (string) $activeContext['id'] === (string) $contextId)
                    || (!$contextId
                        && (
                            (string) $activeContext['context_type'] === $contextType
                            || (string) $activeContext['portal_profile'] === $contextType
                        ))
                );

            if (!$matchesRequested) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'The selected context is not available for this user.',
                ], 422);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Context switched successfully',
                'data' => $contextState,
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
            'context_type' => 'required|string|in:customer,vehicle_owner,staff,agent,driver,corporate'
        ]);

        try {
            $user = $request->user();
            $success = $this->contextService->deactivateContext($user, $request->get('context_type'));

            if ($success) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Context deactivated successfully',
                    'data' => $this->contextService->buildUserContextState($user->fresh(), null, null, null),
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
        $user = $request->user()->load([
            'role',
            'agent',
            'permissions',
            'roles.permissions',
            'contexts.roles.permissions',
            'contexts.corporateEmployee.user',
            'contexts.corporateEmployee.corporate',
            'contexts.corporateEmployee.department',
            'contexts.corporateEmployee.division',
        ]);

        $contextState = $this->contextService->buildUserContextState(
            $user,
            $request->header('X-Active-Context-Type'),
            $request->header('X-Active-Context-Id'),
            $request->header('X-Active-Portal-Profile')
        );
        
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
                'contexts' => $contextState['contexts'],
                'available_contexts' => $contextState['available_contexts'],
                'active_context' => $contextState['active_context'],
                'has_multiple_contexts' => $contextState['has_multiple_contexts'],
            ]
        ]);
    }
}
