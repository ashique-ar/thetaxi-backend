<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\CreateUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\ApiSession;
use App\Models\User;
use App\Services\AuthService;
use App\Services\UserService;
use App\Services\UserContextService;
use App\Services\PermissionAssignmentService;
use App\Services\BusinessCodeGenerator;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class UserController extends Controller
{
    protected $userService;
    protected $contextService;
    protected $authService;
    protected $permissionAssignmentService;

    public function __construct(
        UserService $userService,
        UserContextService $contextService,
        AuthService $authService,
        PermissionAssignmentService $permissionAssignmentService
    )
    {
        $this->userService = $userService;
        $this->contextService = $contextService;
        $this->authService = $authService;
        $this->permissionAssignmentService = $permissionAssignmentService;
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

    public function lookupByMobile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mobile' => ['required', 'string', 'max:30'],
            'context' => ['required', Rule::in(['customer', 'staff', 'driver'])],
        ]);

        $digits = preg_replace('/\D+/', '', $data['mobile']);
        abort_if(strlen($digits) < 7, 422, 'Enter a valid mobile number.');
        $users = User::query()
            ->select(['id', 'first_name', 'last_name', 'email', 'phone', 'is_active'])
            ->withExists(['contexts as has_context' => fn ($query) => $query
                ->where('context_type', $data['context'])
                ->where('is_active', true)])
            ->whereRaw("REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, '+', ''), ' ', ''), '-', ''), '(', ''), ')', '') = ?", [$digits])
            ->limit(10)
            ->get();

        return response()->json(['status' => 'success', 'data' => $users]);
    }

    public function reserveBusinessCode(string $entity, BusinessCodeGenerator $generator): JsonResponse
    {
        abort_unless(in_array($entity, ['customer', 'staff', 'driver'], true), 404);

        return response()->json([
            'status' => 'success',
            'data' => ['code' => $generator->generate($entity)],
        ]);
    }

    /**
     * Get dynamic filter options for the user list.
     */
    public function filterOptions(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => $this->userService->getFilterOptions(),
        ]);
    }

    public function advancedSearch(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'], 'role' => ['nullable', 'string'],
            'status' => ['nullable', 'in:active,inactive'], 'context' => ['nullable', 'string'],
            'agent_id' => ['nullable', 'uuid'], 'verified' => ['nullable', 'in:email,phone'],
            'sort_by' => ['nullable', 'in:first_name,last_name,email,created_at,last_login_at'],
            'sort_order' => ['nullable', 'in:asc,desc'], 'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return UserResource::collection($this->userService->getAllUsers($filters));
    }

    /**
     * Manually clear a user's timed login lock and failed-attempt counter.
     */
    public function unlock(User $user): JsonResponse
    {
        $user->unlockAccount();

        return response()->json([
            'status' => 'success',
            'message' => 'User account unlocked successfully',
            'data' => [
                'login_attempts' => 0,
                'locked_until' => null,
            ],
        ]);
    }

    public function export(Request $request)
    {
        $filters = $request->only(['search', 'role', 'status', 'context', 'agent_id', 'verified', 'sort_by', 'sort_order']);
        $users = $this->userService->getAllUsers([...$filters, 'per_page' => 100000])->getCollection();
        $header = ['id', 'first_name', 'last_name', 'email', 'phone', 'is_active', 'roles', 'created_at'];
        $lines = [$header];
        foreach ($users as $user) {
            $lines[] = [$user->id, $user->first_name, $user->last_name, $user->email, $user->phone, $user->is_active ? 'yes' : 'no', $user->roles->pluck('name')->implode('|'), $user->created_at?->toIso8601String()];
        }
        $csv = collect($lines)->map(fn ($row) => collect($row)->map(fn ($value) => '"' . str_replace('"', '""', (string) $value) . '"')->implode(','))->implode("\n");

        return response($csv, 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="users.csv"']);
    }

    public function import(Request $request): JsonResponse
    {
        $data = $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']]);
        $handle = fopen($data['file']->getRealPath(), 'r');
        $headers = array_map(fn ($value) => strtolower(trim($value)), fgetcsv($handle) ?: []);
        abort_unless(in_array('email', $headers, true) && in_array('first_name', $headers, true), 422, 'CSV must include first_name and email columns.');
        $created = 0; $skipped = 0; $errors = [];
        while (($values = fgetcsv($handle)) !== false) {
            if (count($values) !== count($headers)) { $skipped++; continue; }
            $row = array_combine($headers, $values);
            if (User::where('email', strtolower(trim($row['email'] ?? '')))->exists()) { $skipped++; continue; }
            try {
                $userData = [
                    'first_name' => trim($row['first_name']), 'last_name' => trim($row['last_name'] ?? '') ?: null,
                    'email' => strtolower(trim($row['email'])), 'phone' => trim($row['phone'] ?? '') ?: null,
                    'password' => Str::random(16) . 'Aa1!', 'is_active' => strtolower(trim($row['is_active'] ?? 'yes')) !== 'no',
                ];
                $roles = array_values(array_filter(explode('|', $row['roles'] ?? '')));
                if ($roles) { $userData['roles'] = $roles; }
                $this->userService->createUser($userData);
                $created++;
            } catch (\Throwable $exception) { $skipped++; $errors[] = ($row['email'] ?? 'row') . ': ' . $exception->getMessage(); }
        }
        fclose($handle);

        return response()->json(['status' => 'success', 'data' => ['created' => $created, 'skipped' => $skipped, 'errors' => array_slice($errors, 0, 25)]]);
    }

    public function bulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_ids' => ['required', 'array', 'min:1'], 'user_ids.*' => ['uuid', 'exists:users,id'],
            'action' => ['required', Rule::in(['activate', 'deactivate', 'delete', 'assign_role'])],
            'role' => ['nullable', 'required_if:action,assign_role', 'string', 'exists:roles,name'],
        ]);
        abort_if(in_array(auth()->id(), $data['user_ids'], true) && in_array($data['action'], ['deactivate', 'delete'], true), 422, 'You cannot deactivate or delete your own account.');
        $users = User::whereIn('id', $data['user_ids'])->get();
        DB::transaction(function () use ($users, $data) {
            foreach ($users as $user) {
                match ($data['action']) {
                    'activate' => $user->update(['is_active' => true]),
                    'deactivate' => $user->update(['is_active' => false]),
                    'delete' => $user->delete(),
                    'assign_role' => $user->syncRoles([$data['role']]),
                };
            }
        });

        return response()->json(['status' => 'success', 'message' => 'Bulk user action completed', 'data' => ['affected' => $users->count()]]);
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

        $directNames = $directPermissions->pluck('name')->unique();
        $roleNames = $rolePermissions->pluck('name')->unique();
        $permissions = $directPermissions->merge($rolePermissions)->unique('name')->values();
        
        return response()->json([
            'status' => 'success',
            'data' => [
                'permissions' => $permissions->map(function ($permission) use ($directNames, $roleNames) {
                    return [
                        'id' => $permission->id,
                        'name' => $permission->name,
                        'guard_name' => $permission->guard_name,
                        'source' => $directNames->contains($permission->name)
                            ? 'direct grant'
                            : 'role',
                        'sources' => array_values(array_filter([
                            $directNames->contains($permission->name) ? 'direct grant' : null,
                            $roleNames->contains($permission->name) ? 'role' : null,
                            $permission->guard_name !== config('permissions.canonical_guard', 'api') ? 'legacy' : null,
                        ])),
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
            $permissions = $this->permissionAssignmentService
                ->syncDirectUserPermissions($user, array_values(array_unique(array_merge(
                    $user->permissions()->pluck('name')->all(),
                    $request->permissions
                ))));

            foreach ($permissions as $permission) {
                DB::table('model_has_permissions')->updateOrInsert([
                    'permission_id' => $permission->id,
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
            $remove = $this->permissionAssignmentService->normalizePermissionNames($request->permissions);
            $permissions = collect($user->permissions()->pluck('name')->all())
                ->reject(fn ($permission) => in_array($permission, $remove, true))
                ->values()
                ->all();

            $this->permissionAssignmentService->syncDirectUserPermissions($user, $permissions);
            
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
        $user->load([
            'contexts.roles.permissions',
            'contexts.corporateEmployee.user',
            'contexts.corporateEmployee.corporate',
            'contexts.corporateEmployee.department',
            'contexts.corporateEmployee.division',
            'roles.permissions',
        ]);

        $contextState = $this->contextService->buildUserContextState($user);

        return response()->json([
            'status' => 'success',
            'data' => [
                'contexts' => $contextState['contexts'],
                'available_contexts' => $this->contextService->getActivatableContexts($user),
                'primary_role' => $contextState['primary_role'],
                'has_multiple_contexts' => $contextState['has_multiple_contexts'],
            ]
        ]);
    }

    public function syncDirectPermissions(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string'],
        ]);

        $permissions = $this->permissionAssignmentService->syncDirectUserPermissions($user, $request->permissions);

        return response()->json([
            'status' => 'success',
            'message' => 'Direct user permissions synced successfully',
            'data' => [
                'permissions' => $permissions->map(fn ($permission) => [
                    'id' => $permission->id,
                    'name' => $permission->name,
                    'guard_name' => $permission->guard_name,
                    'source' => 'direct grant',
                    'sources' => ['direct grant'],
                ])->values(),
            ],
        ]);
    }

    /** Create a separate session for the selected user, preserving the actor's session. */
    public function impersonate(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();

        if (!$actor) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if ($actor->id === $user->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'You are already signed in as this user.',
            ], 422);
        }

        if (!$user->isActive()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot sign in as an inactive user.',
            ], 422);
        }

        try {
            $token = $this->authService->createTokenWithRefresh(
                $user,
                $request,
                'Impersonated Session'
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Signed in as user successfully',
                'data' => [
                    'user' => $this->buildUserResponse($user, $request),
                    'token' => $token,
                    'impersonated_by' => [
                        'id' => $actor->id,
                        'email' => $actor->email,
                        'full_name' => $actor->full_name,
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to sign in as the selected user.',
                'error' => $e->getMessage(),
            ], 500);
        }
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
                        'available_contexts' => $this->contextService->getActivatableContexts($user)
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
                    'available_contexts' => $this->contextService->getActivatableContexts($user)
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
        ]);

        try {
            $guards = $this->resolveGuards($request);
            $rolesToAssign = Role::whereIn('name', $request->roles)
                ->whereIn('guard_name', $guards)
                ->with('permissions:id,name,guard_name')
                ->get();
            $roleIds = $rolesToAssign->pluck('id')->all();

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

            $this->contextService->syncContextsForAssignedRoles($user, $rolesToAssign, $request->user()?->id);

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
            $rolesToRevoke = Role::whereIn('name', $request->roles)
                ->whereIn('guard_name', $guards)
                ->with('permissions:id,name,guard_name')
                ->get();
            $roleIds = $rolesToRevoke->pluck('id')->all();

            if (empty($roleIds)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No matching roles found for the selected guards.'
                ], 422);
            }

            DB::transaction(function () use ($user, $roleIds, $rolesToRevoke) {
                DB::table('model_has_roles')
                    ->where('model_type', User::class)
                    ->where('model_id', $user->id)
                    ->whereIn('role_id', $roleIds)
                    ->delete();

                DB::table('user_context_roles')
                    ->whereIn('role_id', $roleIds)
                    ->whereIn('user_context_id', function ($query) use ($user) {
                        $query->select('id')
                            ->from('user_contexts')
                            ->where('user_id', $user->id);
                    })
                    ->delete();

                $revokedPermissionIds = $rolesToRevoke
                    ->flatMap(fn ($role) => $role->permissions)
                    ->pluck('id')
                    ->unique()
                    ->values();

                if ($revokedPermissionIds->isEmpty()) {
                    return;
                }

                $remainingRolePermissionIds = Permission::query()
                    ->join('role_has_permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
                    ->join('model_has_roles', 'role_has_permissions.role_id', '=', 'model_has_roles.role_id')
                    ->where('model_has_roles.model_type', User::class)
                    ->where('model_has_roles.model_id', $user->id)
                    ->pluck('permissions.id')
                    ->unique();

                $permissionIdsToRemove = $revokedPermissionIds
                    ->diff($remainingRolePermissionIds)
                    ->values();

                if ($permissionIdsToRemove->isNotEmpty()) {
                    DB::table('model_has_permissions')
                        ->where('model_type', User::class)
                        ->where('model_id', $user->id)
                        ->whereIn('permission_id', $permissionIdsToRemove->all())
                        ->delete();
                }
            });

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
        
        return response()->json([
            'status' => 'success',
            'data' => [
                'user' => $this->buildSelfProfilePayload($user, $request),
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
            $user->load([
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
            
            return response()->json([
                'status' => 'success',
                'message' => 'Profile updated successfully',
                'data' => [
                    'user' => $this->buildSelfProfilePayload($user, $request),
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

    private function buildUserResponse(User $user, Request $request): array
    {
        $loadedUser = $user->loadMissing([
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
            $loadedUser,
            $request->header('X-Active-Context-Type'),
            $request->header('X-Active-Context-Id'),
            $request->header('X-Active-Portal-Profile')
        );

        return array_merge(
            (new UserResource($loadedUser))->resolve($request),
            $contextState
        );
    }

    /**
     * Build the self-profile payload and suppress access metadata for non-management contexts.
     */
    private function buildSelfProfilePayload(User $user, Request $request): array
    {
        $contextState = $this->contextService->buildUserContextState(
            $user,
            $request->header('X-Active-Context-Type'),
            $request->header('X-Active-Context-Id'),
            $request->header('X-Active-Portal-Profile')
        );

        $payload = (new UserResource($user))->resolve($request);

        if (!$this->shouldExposeSelfProfileAccessDetails($contextState['active_context'] ?? null)) {
            unset($payload['roles'], $payload['permissions']);
        }

        return $payload;
    }

    /**
     * Roles and permissions on the self-profile are only visible for internal admin/management contexts.
     */
    private function shouldExposeSelfProfileAccessDetails(?array $activeContext): bool
    {
        if (!$activeContext) {
            return false;
        }

        $roles = collect($activeContext['roles'] ?? [])
            ->pluck('name')
            ->filter()
            ->map(fn ($role) => strtolower((string) $role))
            ->values();

        $permissions = collect($activeContext['permissions'] ?? [])
            ->pluck('name')
            ->filter()
            ->map(fn ($permission) => strtolower((string) $permission))
            ->values();

        if ($roles->contains('admin')) {
            return true;
        }

        $portalProfile = strtolower((string) ($activeContext['portal_profile'] ?? $activeContext['context_type'] ?? ''));

        if ($portalProfile !== 'internal') {
            return false;
        }

        return $permissions->intersect([
            'users.view',
            'users.manage',
            'users.edit',
            'roles.view',
            'roles.manage',
            'permissions.view',
            'permissions.manage',
            'corporates.manage',
        ])->isNotEmpty();
    }
}
