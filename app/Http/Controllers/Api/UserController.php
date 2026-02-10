<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\CreateUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\UserService;
use App\Services\UserContextService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class UserController extends Controller
{
    protected $userService;
    protected $contextService;

    public function __construct(UserService $userService, UserContextService $contextService)
    {
        $this->userService = $userService;
        $this->contextService = $contextService;
        // $this->middleware('permission:permissions.view')->only(['index', 'show']);
        // $this->middleware('permission:permissions.create')->only(['store']);
        // $this->middleware('permission:permissions.edit')->only(['update']);
        // $this->middleware('permission:permissions.delete')->only(['destroy']);
        // $this->middleware('permission:permissions.manage')->only([
        //     'activate', 'deactivate', 'resetPassword', 'assignPermissions', 
        //     'revokePermissions', 'assignRoles', 'revokeRoles'
        // ]);
    }

    /**
     * Display a listing of users
     *
     * @param Request $request
     * @return AnonymousResourceCollection
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $users = $this->userService->getAllUsers($request->all());
        return UserResource::collection($users);
    }

    /**
     * Store a newly created user
     *
     * @param CreateUserRequest $request
     * @return JsonResponse
     */
    public function store(CreateUserRequest $request): JsonResponse
    {
        try {
            $user = $this->userService->createUser($request->validated());
            
            return response()->json([
                'status' => 'success',
                'message' => 'User created successfully',
                'data' => [
                    'user' => new UserResource($user)
                ]
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified user
     *
     * @param User $user
     * @return JsonResponse
     */
    public function show(User $user): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'user' => new UserResource($user->load(['role', 'agent', 'permissions', 'roles']))
            ]
        ]);
    }

    /**
     * Update the specified user
     *
     * @param UpdateUserRequest $request
     * @param User $user
     * @return JsonResponse
     */
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        try {
            $updatedUser = $this->userService->updateUser($user, $request->validated());
            
            return response()->json([
                'status' => 'success',
                'message' => 'User updated successfully',
                'data' => [
                    'user' => new UserResource($updatedUser)
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified user
     *
     * @param User $user
     * @return JsonResponse
     */
    public function destroy(User $user): JsonResponse
    {
        try {
            $this->userService->deleteUser($user);
            
            return response()->json([
                'status' => 'success',
                'message' => 'User deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Activate user account
     *
     * @param User $user
     * @return JsonResponse
     */
    public function activate(User $user): JsonResponse
    {
        try {
            $this->userService->activateUser($user);
            
            return response()->json([
                'status' => 'success',
                'message' => 'User activated successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to activate user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Deactivate user account
     *
     * @param User $user
     * @return JsonResponse
     */
    public function deactivate(User $user): JsonResponse
    {
        try {
            $this->userService->deactivateUser($user);
            
            return response()->json([
                'status' => 'success',
                'message' => 'User deactivated successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to deactivate user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reset user password
     *
     * @param Request $request
     * @param User $user
     * @return JsonResponse
     */
    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'new_password' => ['required', 'string', 'min:8', 'confirmed'],
            'notify_user' => ['nullable', 'boolean']
        ]);

        try {
            $this->userService->resetUserPassword($user, $request->new_password, $request->boolean('notify_user'));
            
            return response()->json([
                'status' => 'success',
                'message' => 'Password reset successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to reset password',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get user permissions
     *
     * @param User $user
     * @return JsonResponse
     */
    public function permissions(User $user): JsonResponse
    {
        $directPermissions = Permission::query()
            ->join('model_has_permissions', 'permissions.id', '=', 'model_has_permissions.permission_id')
            ->where('model_has_permissions.model_type', User::class)
            ->where('model_has_permissions.model_id', $user->id)
            ->select('permissions.*')
            ->get();

        $rolePermissions = Permission::query()
            ->join('role_has_permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->join('model_has_roles', 'role_has_permissions.role_id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', User::class)
            ->where('model_has_roles.model_id', $user->id)
            ->select('permissions.*')
            ->get();

        $permissions = $directPermissions->merge($rolePermissions)->unique('id')->values();
        
        return response()->json([
            'status' => 'success',
            'data' => [
                'permissions' => $permissions->map(function ($permission) {
                    return [
                        'id' => $permission->id,
                        'name' => $permission->name,
                        'guard_name' => $permission->guard_name,
                        'created_at' => $permission->created_at,
                    ];
                })
            ]
        ]);
    }

    /**
     * Assign permissions to user
     *
     * @param Request $request
     * @param User $user
     * @return JsonResponse
     */
    public function assignPermissions(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'permissions' => ['required', 'array'],
            'permissions.*' => ['required', 'string', 'exists:permissions,name'],
            'apply_to_guards' => ['sometimes', 'array'],
            'apply_to_guards.*' => ['string', Rule::in(['web', 'api'])],
        ]);

        try {
            $guards = $this->resolveGuards($request);
            $permissionIds = Permission::whereIn('name', $request->permissions)
                ->whereIn('guard_name', $guards)
                ->pluck('id')
                ->all();

            if (empty($permissionIds)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No matching permissions found for the selected guards.'
                ], 422);
            }

            foreach ($permissionIds as $permissionId) {
                DB::table('model_has_permissions')->updateOrInsert([
                    'permission_id' => $permissionId,
                    'model_type' => User::class,
                    'model_id' => $user->id,
                ]);
            }
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Permissions assigned successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to assign permissions',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Revoke permissions from user
     *
     * @param Request $request
     * @param User $user
     * @return JsonResponse
     */
    public function revokePermissions(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'permissions' => ['required', 'array'],
            'permissions.*' => ['required', 'string', 'exists:permissions,name'],
            'apply_to_guards' => ['sometimes', 'array'],
            'apply_to_guards.*' => ['string', Rule::in(['web', 'api'])],
        ]);

        try {
            $guards = $this->resolveGuards($request);
            $permissionIds = Permission::whereIn('name', $request->permissions)
                ->whereIn('guard_name', $guards)
                ->pluck('id')
                ->all();

            if (empty($permissionIds)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No matching permissions found for the selected guards.'
                ], 422);
            }

            DB::table('model_has_permissions')
                ->where('model_type', User::class)
                ->where('model_id', $user->id)
                ->whereIn('permission_id', $permissionIds)
                ->delete();
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Permissions revoked successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to revoke permissions',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get user roles
     *
     * @param User $user
     * @return JsonResponse
     */
    public function roles(User $user): JsonResponse
    {
        $roles = Role::query()
            ->join('model_has_roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', User::class)
            ->where('model_has_roles.model_id', $user->id)
            ->select('roles.*')
            ->get();
        
        return response()->json([
            'status' => 'success',
            'data' => [
                'roles' => $roles->map(function ($role) {
                    return [
                        'id' => $role->id,
                        'name' => $role->name,
                        'guard_name' => $role->guard_name,
                        'created_at' => $role->created_at,
                    ];
                })
            ]
        ]);
    }

    /**
     * Get contexts for a specific user (admin)
     *
     * @param User $user
     * @return JsonResponse
     */
    public function contexts(User $user): JsonResponse
    {
        $activeContexts = $user->getActiveContexts()->map(function($ctx){
            return array_merge($ctx->toArray(), ['roles' => $ctx->roles()->get()->map(function($r){ return ['id' => $r->id, 'name' => $r->name, 'display_name' => $r->display_name ?? $r->name]; })]);
        });

        return response()->json([
            'status' => 'success',
            'data' => [
                'contexts' => $activeContexts,
                'available_contexts' => $this->contextService->getAvailableContexts($user),
                'primary_role' => $this->contextService->getPrimaryRole($user),
                'has_multiple_contexts' => $user->hasMultipleContexts()
            ]
        ]);
    }

    /**
     * Assign roles to a specific user context (admin)
     */
    public function assignContextRoles(Request $request, User $user, $contextId): JsonResponse
    {
        $request->validate([
            'roles' => ['required', 'array'],
            'roles.*' => ['required'],
            'auto_assign_permissions' => ['sometimes', 'boolean']
        ]);

        try {
            $context = $user->contexts()->where('id', $contextId)->first();
            if (!$context) {
                return response()->json(['status' => 'error', 'message' => 'Context not found'], 404);
            }

            $this->contextService->assignRolesToContext($user, $context, $request->roles);

            // Auto-assign context-based permissions if requested
            if ($request->boolean('auto_assign_permissions', true)) {
                $this->assignContextBasedPermissions($user, $context->context_type);
            }

            $activeContexts = $user->getActiveContexts()->map(function($ctx){
                return array_merge($ctx->toArray(), ['roles' => $ctx->roles()->get()->map(function($r){ return ['id' => $r->id, 'name' => $r->name, 'display_name' => $r->display_name ?? $r->name]; })]);
            });

            return response()->json(['status' => 'success', 'message' => 'Roles assigned', 'data' => ['contexts' => $activeContexts]]);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'Failed to assign roles', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Assign context-based permissions to user
     *
     * @param User $user
     * @param string $contextType
     * @return void
     */
    private function assignContextBasedPermissions(User $user, string $contextType): void
    {
        // Map context types to default permissions
        $contextPermissionMap = [
            'customer' => [
                'bookings.view',
                'bookings.create',
                'profile.view',
                'profile.edit',
            ],
            'driver' => [
                'bookings.view',
                'assignments.view',
                'profile.view',
                'profile.edit',
            ],
            'vehicle_owner' => [
                'vehicles.view',
                'vehicles.create',
                'vehicles.edit',
                'bookings.view',
                'profile.view',
                'profile.edit',
            ],
            'staff' => [
                'bookings.view',
                'bookings.edit',
                'customers.view',
                'vehicles.view',
                'reports.view',
            ],
            'agent' => [
                'bookings.view',
                'bookings.create',
                'customers.view',
                'reports.view',
            ],
        ];

        $permissions = $contextPermissionMap[$contextType] ?? [];
        
        foreach ($permissions as $permissionName) {
            $permission = \Spatie\Permission\Models\Permission::where('name', $permissionName)->first();
            if ($permission && !$user->hasPermissionTo($permissionName)) {
                DB::table('model_has_permissions')->updateOrInsert([
                    'permission_id' => $permission->id,
                    'model_type' => User::class,
                    'model_id' => $user->id,
                ]);
            }
        }
        
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Revoke a role from a specific user context (admin)
     */
    public function revokeContextRole(Request $request, User $user, $contextId): JsonResponse
    {
        $request->validate([
            'role_id' => ['required']
        ]);

        try {
            $context = $user->contexts()->where('id', $contextId)->first();
            if (!$context) {
                return response()->json(['status' => 'error', 'message' => 'Context not found'], 404);
            }

            // If role_id was provided, revoke only that role; otherwise revoke all roles for the context
            $roleId = $request->get('role_id');

            if ($roleId) {
                $this->contextService->revokeRoleFromContext($user, $context, (int)$roleId);
            } else {
                $this->contextService->revokeRolesFromContext($user, $context);
            }

            $activeContexts = $user->getActiveContexts()->map(function($ctx){
                return array_merge($ctx->toArray(), ['roles' => $ctx->roles()->get()->map(function($r){ return ['id' => $r->id, 'name' => $r->name, 'display_name' => $r->display_name ?? $r->name]; })]);
            });

            return response()->json(['status' => 'success', 'message' => 'Role revoked from context', 'data' => ['contexts' => $activeContexts]]);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'Failed to revoke role', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Deactivate a context for a specific user (admin)
     */
    public function deactivateContext(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'context_type' => 'required|string|in:customer,vehicle_owner,staff,agent,driver'
        ]);

        try {
            $success = $this->contextService->deactivateContext($user, $request->get('context_type'));

            if ($success) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Context deactivated successfully',
                    'data' => [
                        'available_contexts' => $this->contextService->getAvailableContexts($user)
                    ]
                ]);
            }

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to deactivate context'
            ], 400);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to deactivate context',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Activate/Create a context for a specific user (admin)
     */
    public function activateContext(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'context_type' => 'required|string|in:customer,vehicle_owner,staff,agent,driver',
            'context_data' => 'sometimes|array',
        ]);

        try {
            $contextType = $request->get('context_type');
            $contextData = $request->get('context_data', []);

            // Use switchContext to create/activate the context
            $userContext = $this->contextService->switchContext($user, $contextType, $contextData);

            // Auto-assign context-based permissions
            $this->assignContextBasedPermissions($user, $contextType);

            return response()->json([
                'status' => 'success',
                'message' => 'Context activated successfully',
                'data' => [
                    'context' => $userContext,
                    'available_contexts' => $this->contextService->getAvailableContexts($user)
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to activate context',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Assign roles to user
     *
     * @param Request $request
     * @param User $user
     * @return JsonResponse
     */
    public function assignRoles(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'roles' => ['required', 'array'],
            'roles.*' => ['required', 'string', 'exists:roles,name'],
            'apply_to_guards' => ['sometimes', 'array'],
            'apply_to_guards.*' => ['string', Rule::in(['web', 'api'])],
            'auto_assign_permissions' => ['sometimes', 'boolean'],
        ]);

        try {
            $guards = $this->resolveGuards($request);
            $roleIds = Role::whereIn('name', $request->roles)
                ->whereIn('guard_name', $guards)
                ->pluck('id')
                ->all();

            if (empty($roleIds)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No matching roles found for the selected guards.'
                ], 422);
            }

            foreach ($roleIds as $roleId) {
                DB::table('model_has_roles')->updateOrInsert([
                    'role_id' => $roleId,
                    'model_type' => User::class,
                    'model_id' => $user->id,
                ]);
            }

            // Auto-assign permissions from roles if requested
            if ($request->boolean('auto_assign_permissions', true)) {
                $this->autoAssignPermissionsFromRoles($user, $request->roles, $guards);
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Roles assigned successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to assign roles',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Auto-assign permissions from roles
     *
     * @param User $user
     * @param array $roleNames
     * @param array $guards
     * @return void
     */
    private function autoAssignPermissionsFromRoles(User $user, array $roleNames, array $guards): void
    {
        $roles = Role::whereIn('name', $roleNames)
            ->whereIn('guard_name', $guards)
            ->with('permissions')
            ->get();
        
        foreach ($roles as $role) {
            foreach ($role->permissions as $permission) {
                DB::table('model_has_permissions')->updateOrInsert([
                    'permission_id' => $permission->id,
                    'model_type' => User::class,
                    'model_id' => $user->id,
                ]);
            }
        }
    }

    /**
     * Revoke roles from user
     *
     * @param Request $request
     * @param User $user
     * @return JsonResponse
     */
    public function revokeRoles(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'roles' => ['required', 'array'],
            'roles.*' => ['required', 'string', 'exists:roles,name'],
            'apply_to_guards' => ['sometimes', 'array'],
            'apply_to_guards.*' => ['string', Rule::in(['web', 'api'])],
        ]);

        try {
            $guards = $this->resolveGuards($request);
            $roleIds = Role::whereIn('name', $request->roles)
                ->whereIn('guard_name', $guards)
                ->pluck('id')
                ->all();

            if (empty($roleIds)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No matching roles found for the selected guards.'
                ], 422);
            }

            DB::table('model_has_roles')
                ->where('model_type', User::class)
                ->where('model_id', $user->id)
                ->whereIn('role_id', $roleIds)
                ->delete();
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Roles revoked successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to revoke roles',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get current user profile
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function profile(Request $request): JsonResponse
    {
        $user = $request->user()->load(['role', 'agent', 'permissions', 'roles']);
        
        return response()->json([
            'status' => 'success',
            'data' => [
                'user' => new UserResource($user)
            ]
        ]);
    }

    /**
     * Update current user profile
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'timezone' => ['nullable', 'string'],
            'language' => ['nullable', 'string', 'max:5'],
        ]);

        try {
            $user = $request->user();
            $user->update($request->only(['first_name', 'last_name', 'phone', 'timezone', 'language']));
            
            return response()->json([
                'status' => 'success',
                'message' => 'Profile updated successfully',
                'data' => [
                    'user' => new UserResource($user->load(['role', 'agent', 'permissions', 'roles']))
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update profile',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Change current user password
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        try {
            $user = $request->user();
            
            // Check current password
            if (!Hash::check($request->current_password, $user->password)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Current password is incorrect'
                ], 400);
            }

            $user->update([
                'password' => Hash::make($request->password),
                'password_changed_at' => now(),
            ]);

            // Revoke all tokens to force re-login
            $user->revokeAllTokens();

            return response()->json([
                'status' => 'success',
                'message' => 'Password changed successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to change password',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update user status
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function updateStatus(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['required', 'string', 'in:online,away,busy,not-visible'],
        ]);

        try {
            $user = $request->user();
            
            // Map status to is_active
            $status = $request->status;
            
            $user->update(['status' => $status]);

            return response()->json([
                'status' => 'success',
                'message' => 'Status updated successfully',
                'data' => [
                    'status' => $status,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function resolveGuards(Request $request): array
    {
        $guards = $request->input('apply_to_guards');
        if (!is_array($guards) || empty($guards)) {
            return ['web', 'api'];
        }

        return array_values(array_unique(array_intersect($guards, ['web', 'api'])));
    }
}
