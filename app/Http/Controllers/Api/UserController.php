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
use App\Services\CorporateRoleStarterService;
use App\Services\PermissionRegistry;
use App\Services\BusinessCodeGenerator;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use App\Notifications\EmailChangeOtpNotification;
use Illuminate\Validation\ValidationException;
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
            'agent_id' => ['nullable', 'uuid'], 'verified' => ['nullable', 'in:email,phone,email_unverified,phone_unverified'],
            'created_from' => ['nullable', 'date_format:Y-m-d'], 'created_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:created_from'],
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
        $filters = $request->only(['search', 'role', 'status', 'context', 'agent_id', 'verified', 'created_from', 'created_to', 'sort_by', 'sort_order']);
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
                $this->assertCanChangePrivilegedRoles($roles);
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
            'role' => ['nullable', 'required_if:action,assign_role', 'string', Rule::exists('roles', 'name')->where('guard_name', config('permissions.canonical_guard', 'api'))],
        ]);
        if ($data['action'] === 'assign_role') {
            abort_if($this->containsCorporateRole([$data['role']]), 422, 'Corporate roles must be assigned within Corporate Management.');
            $this->assertCanChangePrivilegedRoles([$data['role']]);
        }
        abort_if(in_array(auth()->id(), $data['user_ids'], true) && in_array($data['action'], ['deactivate', 'delete'], true), 422, 'You cannot deactivate or delete your own account.');
        $users = User::whereIn('id', $data['user_ids'])->get();
        if ($data['action'] === 'assign_role') {
            $existingRoles = $users->flatMap(fn (User $target) => $target->roles()
                ->where('guard_name', config('permissions.canonical_guard', 'api'))
                ->pluck('name')->all())->all();
            $this->assertCanChangePrivilegedRoles(array_merge([$data['role']], $existingRoles));
        }
        DB::transaction(function () use ($users, $data) {
            foreach ($users as $user) {
                if ($data['action'] === 'assign_role') {
                    $user = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                    $roles = Role::query()->where('name', $data['role'])
                        ->where('guard_name', config('permissions.canonical_guard', 'api'))->get();
                    $user->syncRoles($roles);
                    $this->userService->syncDirectRoleGrants($user, $roles);
                    continue;
                }

                match ($data['action']) {
                    'activate' => $user->update(['is_active' => true]),
                    'deactivate' => $user->update(['is_active' => false]),
                    'delete' => $user->delete(),
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
        $data = $request->validated();
        $this->assertCanChangePrivilegedRoles($this->roleIdentifiers($data));

        try {
            $user = $this->userService->createUser($data);
            
            return response()->json([
                'status' => 'success',
                'message' => 'User created successfully',
                'data' => [
                    'user' => new UserResource($user)
                ]
            ], 201);
        } catch (ValidationException $e) {
            throw $e;
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
        $data = $request->validated();
        if (array_key_exists('roles', $data) || array_key_exists('role_id', $data) || array_key_exists('permissions', $data)) {
            $existingRoles = $user->roles()
                ->where('guard_name', config('permissions.canonical_guard', 'api'));
            if (! array_key_exists('roles', $data) && ! array_key_exists('role_id', $data)) {
                $existingRoles->whereIn('name', ['admin', 'super-admin']);
            }
            $this->assertCanChangePrivilegedRoles(
                array_merge($this->roleIdentifiers($data), $existingRoles->pluck('name')->all())
            );
        }

        try {
            $updatedUser = $this->userService->updateUser($user, $data);
            
            return response()->json([
                'status' => 'success',
                'message' => 'User updated successfully',
                'data' => [
                    'user' => new UserResource($updatedUser)
                ]
            ]);
        } catch (ValidationException $e) {
            throw $e;
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
            ->where('permissions.guard_name', config('permissions.canonical_guard', 'api'))
            ->where('model_has_permissions.model_type', User::class)
            ->where('model_has_permissions.model_id', $user->id)
            ->select('permissions.*')
            ->get();
        $evaluator = app(\App\Services\PermissionEvaluator::class);
        $rolePermissions = $evaluator->rolesForInternalContext($user)
            ->flatMap(fn (Role $role) => $role->permissions)
            ->unique('id')
            ->values();

        $contextPermissionIds = DB::table('user_context_permission_grants as grants')
            ->join('user_contexts', 'user_contexts.id', '=', 'grants.user_context_id')
            ->where('user_contexts.user_id', $user->id)
            ->where('user_contexts.context_type', 'staff')
            ->where('user_contexts.is_active', true)
            ->whereNull('user_contexts.deleted_at')
            ->pluck('grants.permission_id')->unique();
        $contextPermissionGrants = Permission::query()
            ->join('user_context_permission_grants as grants', 'permissions.id', '=', 'grants.permission_id')
            ->join('user_contexts', 'user_contexts.id', '=', 'grants.user_context_id')
            ->where('user_contexts.user_id', $user->id)
            ->where('user_contexts.context_type', 'staff')
            ->where('user_contexts.is_active', true)
            ->whereNull('user_contexts.deleted_at')
            ->where('permissions.guard_name', config('permissions.canonical_guard', 'api'))
            ->select('permissions.*')
            ->get();
        $externalContextPermissionIds = DB::table('user_context_permission_grants as grants')
            ->join('user_contexts', 'user_contexts.id', '=', 'grants.user_context_id')
            ->where('user_contexts.user_id', $user->id)
            ->where('user_contexts.context_type', '!=', 'staff')
            ->pluck('grants.permission_id')->unique();
        $explicitPermissionIds = DB::table('user_direct_permission_grants')
            ->where('user_id', $user->id)->pluck('permission_id')->unique();
        $directPermissions = $directPermissions->reject(fn (Permission $permission) =>
            $externalContextPermissionIds->contains($permission->id)
            && ! $explicitPermissionIds->contains($permission->id)
        )->values();
        $contextNames = $directPermissions->whereIn('id', $contextPermissionIds)->pluck('name')
            ->merge($contextPermissionGrants->pluck('name'))->unique();
        $directNames = $directPermissions->filter(fn ($permission) =>
            !$contextPermissionIds->contains($permission->id) || $explicitPermissionIds->contains($permission->id)
        )->pluck('name')->unique();
        $roleNames = $rolePermissions->pluck('name')->unique();
        $permissions = $directPermissions->merge($rolePermissions)
            ->merge($contextPermissionGrants)->unique('name')->values();
        $isMasterAdmin = $evaluator->hasInternalRole($user, 'super-admin');
        if (! $isMasterAdmin) {
            $permissions = $permissions->reject(fn (Permission $permission) =>
                PermissionRegistry::isAdminOnlyPermissionName($permission->name)
            )->values();
        }

        $registry = app(PermissionRegistry::class);
        $canonicalName = fn (string $name) => $registry->resolveKey($name);
        $directNames = $directNames->map($canonicalName)->unique();
        $contextNames = $contextNames->map($canonicalName)->unique();
        $roleNames = $roleNames->map($canonicalName)->unique();
        $permissions = $permissions
            ->unique(fn (Permission $permission) => $canonicalName($permission->name))
            ->values();
        
        return response()->json([
            'status' => 'success',
            'data' => [
                'permissions' => $permissions->map(function ($permission) use ($directNames, $contextNames, $roleNames) {
                    return [
                        'id' => $permission->id,
                        'name' => $registry->resolveKey($permission->name),
                        'guard_name' => $permission->guard_name,
                        'source' => $directNames->contains($permission->name)
                            ? 'direct grant'
                            : ($contextNames->contains($permission->name) ? 'context default' : 'role'),
                        'sources' => array_values(array_filter([
                            $directNames->contains($permission->name) ? 'direct grant' : null,
                            $contextNames->contains($permission->name) ? 'context default' : null,
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
            'permissions.*' => ['required', 'string', Rule::exists('permissions', 'name')->where('guard_name', config('permissions.canonical_guard', 'api'))],
        ]);
        $this->assertCanChangePrivilegedRoles([]);

        try {
            $this->permissionAssignmentService->grantDirectUserPermissions($user, $request->permissions);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Permissions assigned successfully'
            ]);
        } catch (ValidationException $e) {
            throw $e;
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
            'permissions.*' => ['required', 'string', Rule::exists('permissions', 'name')->where('guard_name', config('permissions.canonical_guard', 'api'))],
        ]);
        $this->assertCanChangePrivilegedRoles([]);

        try {
            $this->permissionAssignmentService->revokeDirectUserPermissions($user, $request->permissions);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Permissions revoked successfully'
            ]);
        } catch (ValidationException $e) {
            throw $e;
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
            ->where('roles.guard_name', config('permissions.canonical_guard', 'api'))
            ->select('roles.*')
            ->get();
        $roleIds = $roles->pluck('id');
        $directRoleIds = DB::table('user_direct_role_grants')->where('user_id', $user->id)->whereIn('role_id', $roleIds)->pluck('role_id');
        $contextTypes = DB::table('user_context_roles as grants')
            ->join('user_contexts', 'user_contexts.id', '=', 'grants.user_context_id')
            ->where('user_contexts.user_id', $user->id)->whereIn('grants.role_id', $roleIds)
            ->select('grants.role_id', 'grants.user_context_id', 'user_contexts.context_type')->get()->groupBy('role_id');
        
        return response()->json([
            'status' => 'success',
            'data' => [
                'roles' => $roles->map(function ($role) use ($user, $directRoleIds, $contextTypes) {
                    $sources = [];
                    if ($directRoleIds->contains($role->id)) {
                        $sources[] = 'Direct grant';
                    }
                    $contextGrants = $contextTypes->get($role->id, collect());
                    foreach ($contextGrants->pluck('context_type')->unique() as $contextType) {
                        $sources[] = ucfirst(str_replace('_', ' ', $contextType)).' context';
                    }
                    if (!$directRoleIds->contains($role->id)
                        && ($contextGrants->isEmpty() || $this->contextService->hasUnverifiedContextRoleOrigin($user, (int) $role->id))) {
                        $sources[] = 'Prior role origin unverified';
                    }

                    return [
                        'id' => $role->id,
                        'name' => $role->name,
                        'display_name' => $role->display_name ?? CorporateRoleStarterService::displayName($role->name),
                        'guard_name' => $role->guard_name,
                        'sources' => $sources,
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
        $this->assertCanChangePrivilegedRoles([]);

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
            'roles.*' => ['required', Rule::exists('roles', 'id')->where('guard_name', config('permissions.canonical_guard', 'api'))],
            'auto_assign_permissions' => ['sometimes', 'boolean']
        ]);
        $roleNames = $this->roleIdentifiers($request->all());
        abort_if($this->containsCorporateRole($roleNames), 422, 'Corporate roles must be assigned within Corporate Management.');
        $this->assertCanChangePrivilegedRoles($roleNames);

        try {
            $activeContexts = DB::transaction(function () use ($user, $contextId, $request): ?\Illuminate\Support\Collection {
                $user = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                $context = $user->contexts()->whereKey($contextId)->first();
                if (!$context) {
                    return null;
                }

                $this->contextService->assignRolesToContext($user, $context, $request->roles);
                if ($context->is_active && $request->boolean('auto_assign_permissions', true)) {
                    $this->assignContextBasedPermissions($user, $context);
                }

                return $user->getActiveContexts()->map(function ($ctx) {
                    return array_merge($ctx->toArray(), ['roles' => $ctx->roles()->get()->map(fn($role) => ['id' => $role->id, 'name' => $role->name, 'display_name' => $role->display_name ?? CorporateRoleStarterService::displayName($role->name)])]);
                });
            });
            if ($activeContexts === null) {
                return response()->json(['status' => 'error', 'message' => 'Context not found'], 404);
            }

            return response()->json(['status' => 'success', 'message' => 'Roles assigned', 'data' => ['contexts' => $activeContexts]]);
        } catch (ValidationException $e) {
            throw $e;
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
    private function assignContextBasedPermissions(User $user, \App\Models\UserContext $context): void
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

        $permissions = $contextPermissionMap[$context->context_type] ?? [];
        
        $granted = [];
        foreach ($permissions as $permissionName) {
            $permission = \Spatie\Permission\Models\Permission::where('name', $permissionName)->first();
            if (!$permission) {
                continue;
            }

            $alreadyDirect = DB::table('model_has_permissions')
                ->where('permission_id', $permission->id)
                ->where('model_type', User::class)
                ->where('model_id', $user->id)
                ->exists();
            $alreadyOwned = DB::table('user_context_permission_grants')
                ->where('user_context_id', $context->id)
                ->where('permission_id', $permission->id)->exists();
            if ($alreadyOwned) {
                continue;
            }

            $hasTrackedSource = DB::table('user_direct_permission_grants')
                ->where('user_id', $user->id)->where('permission_id', $permission->id)->exists()
                || DB::table('user_context_permission_grants as grants')
                    ->join('user_contexts', 'user_contexts.id', '=', 'grants.user_context_id')
                    ->where('user_contexts.user_id', $user->id)
                    ->where('user_contexts.is_active', true)
                    ->where('grants.permission_id', $permission->id)->exists();

            if ($hasTrackedSource) {
                DB::table('user_context_permission_grants')->insert([
                    'user_context_id' => $context->id,
                    'permission_id' => $permission->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $granted[] = $permissionName;
                continue;
            }

            if (!$alreadyDirect && !$user->hasPermissionTo($permissionName)) {
                DB::table('model_has_permissions')->updateOrInsert([
                    'permission_id' => $permission->id,
                    'model_type' => User::class,
                    'model_id' => $user->id,
                ]);
                DB::table('user_context_permission_grants')->updateOrInsert(
                    ['user_context_id' => $context->id, 'permission_id' => $permission->id],
                    ['created_at' => now(), 'updated_at' => now()]
                );
                $granted[] = $permissionName;
            }
        }

        if ($granted) {
            activity('user-access')->causedBy(request()->user())->performedOn($user)
                ->withProperties(['context_id' => $context->id, 'context_type' => $context->context_type, 'permissions' => $granted])
                ->log('context_default_permissions_granted');
        }
        
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Revoke a role from a specific user context (admin)
     */
    public function revokeContextRole(Request $request, User $user, $contextId): JsonResponse
    {
        $request->validate([
            'role_id' => ['required', Rule::exists('roles', 'id')->where('guard_name', config('permissions.canonical_guard', 'api'))]
        ]);
        $role = Role::query()->where('guard_name', config('permissions.canonical_guard', 'api'))
            ->find($request->input('role_id'));
        abort_if($role && $this->containsCorporateRole([$role->name]), 422, 'Corporate roles must be managed within Corporate Management.');
        $this->assertCanChangePrivilegedRoles($role ? [$role->name] : []);

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
                return array_merge($ctx->toArray(), ['roles' => $ctx->roles()->get()->map(function($r){ return ['id' => $r->id, 'name' => $r->name, 'display_name' => $r->display_name ?? CorporateRoleStarterService::displayName($r->name)]; })]);
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
            $success = $this->contextService->deactivateContext($user, $request->get('context_type'), null, $request->user()?->id);

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
            'context_data.roles' => 'sometimes|array',
            'context_data.roles.*' => ['required', function ($attribute, $value, $fail) {
                if (!is_string($value) && !is_int($value)) {
                    $fail('Each selected role must be a role name or ID.');
                    return;
                }

                $query = Role::query()->where('guard_name', config('permissions.canonical_guard', 'api'));
                $exists = is_int($value) || ctype_digit($value)
                    ? $query->whereKey($value)->exists()
                    : $query->where('name', $value)->exists();
                if (!$exists) $fail('One or more selected roles are invalid.');
            }],
        ]);

        try {
            $contextType = $request->get('context_type');
            $contextData = $request->get('context_data', []);
            $contextRoles = $contextData['roles'] ?? [];
            $roleNames = $this->roleIdentifiers(['roles' => $contextRoles]);
            if (count($roleNames) !== count(array_unique(array_map('strval', $contextRoles)))) {
                throw ValidationException::withMessages(['context_data.roles' => 'One or more selected roles are invalid.']);
            }
            abort_if($this->containsCorporateRole($roleNames), 422, 'Corporate roles must be managed within Corporate Management.');
            $this->assertCanChangePrivilegedRoles($roleNames);
            if ($contextType === 'staff') {
                unset($contextData['company_id']);
            }

            $userContext = DB::transaction(function () use ($user, $contextType, $contextData): \App\Models\UserContext {
                $user = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                $context = $this->contextService->switchContext($user, $contextType, $contextData);
                $this->assignContextBasedPermissions($user, $context);
                return $context;
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Context activated successfully',
                'data' => [
                    'context' => $userContext,
                    'available_contexts' => $this->contextService->getActivatableContexts($user)
                ]
            ]);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage() ?: 'The selected context is unavailable.',
            ], $e->getStatusCode());
        } catch (ValidationException $e) {
            throw $e;
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
            'roles.*' => ['required', 'string', Rule::exists('roles', 'name')->where('guard_name', config('permissions.canonical_guard', 'api'))],
        ]);
        abort_if($this->containsCorporateRole($request->input('roles', [])), 422, 'Corporate roles must be assigned within the employee corporate context.');
        $this->assertCanChangePrivilegedRoles($request->input('roles', []));

        try {
            $rolesToAssign = Role::whereIn('name', $request->roles)
                ->where('guard_name', config('permissions.canonical_guard', 'api'))
                ->with('permissions:id,name,guard_name')
                ->get();
            $roleIds = $rolesToAssign->pluck('id')->all();

            if (empty($roleIds)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No matching roles found for the selected guards.'
                ], 422);
            }

            DB::transaction(function () use ($roleIds, $rolesToAssign, $user, $request): void {
                User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                foreach ($roleIds as $roleId) {
                    DB::table('model_has_roles')->updateOrInsert([
                        'role_id' => $roleId,
                        'model_type' => User::class,
                        'model_id' => $user->id,
                    ]);
                }

                $this->userService->grantDirectRoleGrants($user, $rolesToAssign);

                $this->contextService->syncContextsForAssignedRoles($user, $rolesToAssign, $request->user()?->id);
            });

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
            'roles.*' => ['required', 'string', Rule::exists('roles', 'name')->where('guard_name', config('permissions.canonical_guard', 'api'))],
        ]);
        abort_if($this->containsCorporateRole($request->input('roles', [])), 422, 'Corporate roles must be revoked within the employee corporate context.');
        $this->assertCanChangePrivilegedRoles($request->input('roles', []));

        try {
            $roleIds = Role::whereIn('name', $request->roles)
                ->where('guard_name', config('permissions.canonical_guard', 'api'))
                ->pluck('id')->all();

            if (empty($roleIds)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No matching roles found for the selected guards.'
                ], 422);
            }

            $preservedRoles = $this->contextService->revokeAllSourcesForRoles($user, $roleIds);

            app(PermissionRegistrar::class)->forgetCachedPermissions();
            
            return response()->json([
                'status' => 'success',
                'message' => $preservedRoles
                    ? 'Some roles were retained because their earlier assignment source could not be verified.'
                    : 'Roles revoked successfully',
                'data' => ['preserved_roles' => $preservedRoles],
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
            'last_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'timezone' => ['nullable', 'string'],
            'language' => ['nullable', 'string', 'max:5'],
            'current_password' => ['nullable', 'string'],
        ]);

        $user = $request->user();
        if ($request->has('phone') && $request->phone !== $user->phone && !Hash::check((string) $request->current_password, $user->password)) {
            throw ValidationException::withMessages(['current_password' => ['Current password is incorrect.']]);
        }

        try {
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

    public function requestEmailChange(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'current_password' => ['required', 'string'],
        ]);
        if (!Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => ['Current password is incorrect.']]);
        }
        $email = strtolower(trim($data['email']));

        if (strcasecmp($email, $user->email) === 0) {
            return response()->json(['status' => 'success', 'message' => 'That is already your email address.']);
        }

        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Cache::put('email_change_otp:'.$user->id, [
            'email' => $email,
            'hash' => Hash::make($otp),
            'current_email_hash' => sha1($user->email),
        ], now()->addMinutes(10));
        Cache::forget('email_change_otp_attempts:'.$user->id);
        Notification::route('mail', $email)->notify(new EmailChangeOtpNotification($otp));

        return response()->json(['status' => 'success', 'message' => "A 6-digit verification code was sent to {$email}. It expires in 10 minutes."]);
    }

    public function verifyEmailChange(Request $request): JsonResponse
    {
        $data = $request->validate(['otp' => ['required', 'digits:6']]);
        $userId = $request->user()->id;
        $key = 'email_change_otp:'.$userId;
        $attemptsKey = 'email_change_otp_attempts:'.$userId;
        $pending = Cache::get($key);

        Cache::add($attemptsKey, 0, now()->addMinutes(10));
        if (! $pending || Cache::increment($attemptsKey) > 5 || ! Hash::check($data['otp'], $pending['hash'])) {
            if (Cache::get($attemptsKey) >= 5) Cache::forget($key);
            throw ValidationException::withMessages(['otp' => ['The verification code is invalid or expired.']]);
        }

        DB::transaction(function () use ($userId, $pending): void {
            $user = User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            abort_unless(hash_equals($pending['current_email_hash'], sha1($user->email)), 409, 'Your email changed after this code was sent. Request a new code.');
            abort_if(User::withTrashed()->whereRaw('LOWER(email) = ?', [$pending['email']])->where('id', '!=', $user->id)->exists(), 409, 'This email address is already in use.');
            $user->update(['email' => $pending['email'], 'email_verified_at' => now()]);
        });

        Cache::forget($key);
        Cache::forget($attemptsKey);

        return response()->json(['status' => 'success', 'message' => 'Email address verified and updated successfully.', 'data' => ['email' => $pending['email']]]);
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

    private function containsCorporateRole(array $roleNames): bool
    {
        return collect($roleNames)->contains(CorporateRoleStarterService::isCorporateRoleName(...));
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

    private function roleIdentifiers(array $data): array
    {
        $identifiers = collect($data['roles'] ?? [])
            ->when(isset($data['role_id']), fn ($roles) => $roles->push((string) $data['role_id']))
            ->map(fn ($value) => (string) $value)
            ->unique();

        return Role::query()
            ->where(function ($query) use ($identifiers) {
                $query->whereIn('name', $identifiers)
                    ->orWhereIn('id', $identifiers);
            })
            ->where('guard_name', config('permissions.canonical_guard', 'api'))
            ->pluck('name')
            ->all();
    }

    private function assertCanChangePrivilegedRoles(array $roleNames): void
    {
        $normalizedRoleNames = collect($roleNames)
            ->map(fn ($name) => strtolower(trim((string) $name)));
        $actor = request()->user();
        $evaluator = app(\App\Services\PermissionEvaluator::class);
        $isMasterAdmin = $actor && $evaluator->hasInternalRole($actor, 'super-admin');
        $isAdmin = $isMasterAdmin || ($actor && $evaluator->hasInternalRole($actor, 'admin'));
        if (! $isMasterAdmin && $roleNames !== []) {
            $hasProtectedRole = Role::query()
                ->where('guard_name', config('permissions.canonical_guard', 'api'))
                ->whereIn('name', $roleNames)
                ->with(['permissions' => fn ($permissions) => $permissions
                    ->where('guard_name', config('permissions.canonical_guard', 'api'))])
                ->get()
                ->contains(fn (Role $role) => $role->permissions->contains(fn (Permission $permission) =>
                    PermissionRegistry::isAdminOnlyPermissionName($permission->name)
                ));
            abort_if($hasProtectedRole, 403, 'Only a Master Admin can assign roles with protected permissions.');
        }
        abort_if(
            ! $isAdmin && $normalizedRoleNames->intersect(['admin', 'super-admin'])->isNotEmpty(),
            403,
            'Only an Admin can assign or manage Admin roles.'
        );
        abort_if(! $isMasterAdmin && $normalizedRoleNames->contains('super-admin'), 403, 'Only a Master Admin can assign or manage the Master Admin role.');
    }
}
