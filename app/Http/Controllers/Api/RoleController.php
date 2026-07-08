<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Role\CreateRoleRequest;
use App\Http\Requests\Role\UpdateRoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\User;
use App\Services\PermissionAssignmentService;
use App\Services\UserContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleController extends Controller
{
    private PermissionAssignmentService $assignmentService;
    private UserContextService $contextService;

    public function __construct(PermissionAssignmentService $assignmentService, UserContextService $contextService)
    {
        $this->assignmentService = $assignmentService;
        $this->contextService = $contextService;
        $this->middleware('permission:roles.view')->only(['index', 'show']);
        $this->middleware('permission:roles.create')->only(['store']);
        $this->middleware('permission:roles.edit')->only(['update']);
        $this->middleware('permission:roles.delete')->only(['destroy']);
        // $this->middleware('permission:manage-roles')->only(['assignPermissions', 'revokePermissions']);
    }




    /**
     * Display a listing of roles
     *
     * @param Request $request
     * @return AnonymousResourceCollection
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Role::with('permissions');

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        $roles = $query->paginate($request->per_page ?? 15);
        return RoleResource::collection($roles);
    }

    /**
     * Store a newly created role
     *
     * @param CreateRoleRequest $request
     * @return JsonResponse
     */
    public function store(CreateRoleRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            $permissions = $data['permissions'] ?? null;
            unset($data['permissions']);

            $contextData = $this->extractContextData($data);
            $role = Role::create($data);
            $this->applyContextData($role, $contextData);
            
            if ($permissions !== null) {
                $this->syncRolePermissionsAndCleanup($role, $permissions);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Role created successfully',
                'data' => [
                    'role' => new RoleResource($role->load('permissions'))
                ]
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create role',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified role
     *
     * @param Role $role
     * @return JsonResponse
     */
    public function show(Role $role): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'role' => new RoleResource($role->load('permissions'))
            ]
        ]);
    }

    /**
     * Update the specified role
     *
     * @param UpdateRoleRequest $request
     * @param Role $role
     * @return JsonResponse
     */
    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        try {
            $data = $request->validated();
            $permissions = $data['permissions'] ?? null;
            unset($data['permissions']);

            $contextData = $this->extractContextData($data);
            $role->update($data);
            $this->applyContextData($role, $contextData);

            if ($contextData !== []) {
                $this->syncUpdatedRoleContextsForUsers($role, $request->user()?->id);
            }
            
            if ($permissions !== null) {
                $this->syncRolePermissionsAndCleanup($role, $permissions);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Role updated successfully',
                'data' => [
                    'role' => new RoleResource($role->load('permissions'))
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update role',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified role
     *
     * @param Role $role
     * @return JsonResponse
     */
    public function destroy(Role $role): JsonResponse
    {
        try {
            // Check if role is being used by any users
            if ($role->users()->exists()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cannot delete role that is assigned to users'
                ], 400);
            }

            $role->delete();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Role deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete role',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get role permissions
     *
     * @param Role $role
     * @return JsonResponse
     */
    public function permissions(Role $role): JsonResponse
    {
        $permissions = $role->permissions;
        
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
     * Assign permissions to role
     *
     * @param Request $request
     * @param Role $role
     * @return JsonResponse
     */
    public function assignPermissions(Request $request, Role $role): JsonResponse
    {
        $request->validate([
            'permissions' => ['required', 'array'],
            'permissions.*' => ['required', 'string']
        ]);

        try {
            $current = $role->permissions()->pluck('name')->all();
            $permissions = array_values(array_unique(array_merge($current, $request->permissions)));
            $this->syncRolePermissionsAndCleanup($role, $permissions);
            
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
     * Revoke permissions from role
     *
     * @param Request $request
     * @param Role $role
     * @return JsonResponse
     */
    public function revokePermissions(Request $request, Role $role): JsonResponse
    {
        $request->validate([
            'permissions' => ['required', 'array'],
            'permissions.*' => ['required', 'string']
        ]);

        try {
            $remove = $this->assignmentService->normalizePermissionNames($request->permissions);
            $permissions = collect($role->permissions()->pluck('name')->all())
                ->reject(fn ($permission) => in_array($permission, $remove, true))
                ->values()
                ->all();
            $this->syncRolePermissionsAndCleanup($role, $permissions);
            
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
     * Get users with this role
     *
     * @param Role $role
     * @return JsonResponse
     */
    public function users(Role $role): JsonResponse
    {
        $users = $role->users()->select('id', 'first_name', 'last_name', 'email')->get();
        
        return response()->json([
            'status' => 'success',
            'data' => [
                'users' => $users
            ]
        ]);
    }

    public function syncPermissions(Request $request, Role $role): JsonResponse
    {
        $jsonPayload = json_decode($request->getContent() ?: '{}', true);
        $rawPermissions = is_array($jsonPayload) && array_key_exists('permissions', $jsonPayload)
            ? $jsonPayload['permissions']
            : $request->input('permissions');

        $request->merge([
            'permissions' => $rawPermissions,
        ]);

        $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string'],
        ]);

        $requestedPermissionNames = collect($this->assignmentService->normalizePermissionNames($rawPermissions))
            ->map(fn ($permission) => (string) $permission)
            ->unique()
            ->values();

        $permissions = $this->syncRolePermissionsAndCleanup($role, $requestedPermissionNames->all())
            ->filter(fn ($permission) => $requestedPermissionNames->contains((string) $permission->name))
            ->values();

        return response()->json([
            'status' => 'success',
            'message' => 'Role permissions synced successfully',
            'data' => [
                'permissions' => $permissions->map(fn ($permission) => [
                    'id' => $permission->id,
                    'name' => $permission->name,
                    'guard_name' => $permission->guard_name,
                ])->values(),
            ],
        ]);
    }

    public function applyTemplate(Request $request, Role $role): JsonResponse
    {
        $request->validate([
            'template' => ['required', 'string'],
            'mode' => ['sometimes', Rule::in(['merge', 'replace'])],
        ]);

        $previousPermissionNames = $role->permissions()
            ->pluck('name')
            ->map(fn ($name) => (string) $name)
            ->all();

        $permissions = $this->assignmentService->applyTemplate(
            $role,
            $request->input('template'),
            $request->input('mode', 'merge')
        );

        $currentPermissionNames = $permissions
            ->pluck('name')
            ->map(fn ($name) => (string) $name)
            ->all();
        $removedPermissionNames = array_values(array_diff($previousPermissionNames, $currentPermissionNames));

        if ($removedPermissionNames !== []) {
            $this->removeRoleDerivedDirectPermissions($role, $removedPermissionNames);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Permission template applied successfully',
            'data' => [
                'permissions' => $permissions->map(fn ($permission) => [
                    'id' => $permission->id,
                    'name' => $permission->name,
                    'guard_name' => $permission->guard_name,
                ])->values(),
            ],
        ]);
    }

    /**
     * Resolve permission IDs or names to permission names for Spatie permission APIs.
     *
     * @param array $permissionIdentifiers
     * @return array
     *
     * @throws ValidationException
     */
    private function resolvePermissionNames(array $permissionIdentifiers): array
    {
        $identifiers = collect($permissionIdentifiers)
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->map(fn ($value) => (string) $value)
            ->unique()
            ->values();

        if ($identifiers->isEmpty()) {
            return [];
        }

        $ids = $identifiers->filter(fn ($value) => ctype_digit($value))->values();
        $names = $identifiers->reject(fn ($value) => ctype_digit($value))->values();

        $permissions = Permission::query()
            ->when($ids->isNotEmpty(), fn ($query) => $query->whereIn('id', $ids))
            ->when($names->isNotEmpty(), function ($query) use ($names, $ids) {
                $method = $ids->isNotEmpty() ? 'orWhereIn' : 'whereIn';
                $query->{$method}('name', $names);
            })
            ->get(['id', 'name']);

        $resolvedNames = $permissions->pluck('name')->unique()->values();
        $resolvedIds = $permissions->pluck('id')->map(fn ($id) => (string) $id);
        $resolvedIdentifiers = $resolvedNames
            ->concat($resolvedIds)
            ->map(fn ($value) => (string) $value)
            ->unique();

        $invalid = $identifiers->diff($resolvedIdentifiers)->values();

        if ($invalid->isNotEmpty()) {
            throw ValidationException::withMessages([
                'permissions' => 'One or more selected permissions are invalid.',
            ]);
        }

        return $this->assignmentService->normalizePermissionNames($resolvedNames->all());
    }

    private function syncRolePermissionsAndCleanup(Role $role, array $permissionIdentifiers): Collection
    {
        $previousPermissionNames = $role->permissions()
            ->pluck('name')
            ->map(fn ($name) => (string) $name)
            ->all();

        $permissions = $this->assignmentService->syncRolePermissions($role, $permissionIdentifiers);

        $currentPermissionNames = $permissions
            ->pluck('name')
            ->map(fn ($name) => (string) $name)
            ->all();
        $removedPermissionNames = array_values(array_diff($previousPermissionNames, $currentPermissionNames));

        if ($removedPermissionNames !== []) {
            $this->removeRoleDerivedDirectPermissions($role, $removedPermissionNames);
        }

        $this->removeOrphanedRoleCopiedDirectPermissions($role);

        return $permissions;
    }

    private function removeRoleDerivedDirectPermissions(Role $role, array $removedPermissionNames): void
    {
        $permissionIds = Permission::query()
            ->where('guard_name', $role->guard_name)
            ->whereIn('name', $removedPermissionNames)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values();

        if ($permissionIds->isEmpty()) {
            return;
        }

        $roleUserIds = DB::table('model_has_roles')
            ->where('role_id', $role->id)
            ->where('model_type', User::class)
            ->pluck('model_id')
            ->map(fn ($id) => (int) $id)
            ->values();

        if ($roleUserIds->isEmpty()) {
            return;
        }

        $permissionIdsStillGrantedByOtherRoles = DB::table('model_has_roles')
            ->join('role_has_permissions', 'model_has_roles.role_id', '=', 'role_has_permissions.role_id')
            ->where('model_has_roles.model_type', User::class)
            ->whereIn('model_has_roles.model_id', $roleUserIds->all())
            ->where('model_has_roles.role_id', '<>', $role->id)
            ->whereIn('role_has_permissions.permission_id', $permissionIds->all())
            ->select('model_has_roles.model_id', 'role_has_permissions.permission_id')
            ->get()
            ->groupBy(fn ($row) => (int) $row->model_id)
            ->map(fn ($rows) => $rows->pluck('permission_id')->map(fn ($id) => (int) $id)->all());

        DB::table('model_has_permissions')
            ->where('model_type', User::class)
            ->whereIn('model_id', $roleUserIds->all())
            ->whereIn('permission_id', $permissionIds->all())
            ->get(['model_id', 'permission_id'])
            ->each(function ($row) use ($permissionIdsStillGrantedByOtherRoles) {
                $modelId = (int) $row->model_id;
                $permissionId = (int) $row->permission_id;

                if (in_array($permissionId, $permissionIdsStillGrantedByOtherRoles->get($modelId, []), true)) {
                    return;
                }

                DB::table('model_has_permissions')
                    ->where('model_type', User::class)
                    ->where('model_id', $modelId)
                    ->where('permission_id', $permissionId)
                    ->delete();
            });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function removeOrphanedRoleCopiedDirectPermissions(Role $role): void
    {
        $roleUserIds = DB::table('model_has_roles')
            ->where('role_id', $role->id)
            ->where('model_type', User::class)
            ->pluck('model_id')
            ->map(fn ($id) => (int) $id)
            ->values();

        if ($roleUserIds->isEmpty()) {
            return;
        }

        $roleGrantedPermissionIdsByUser = DB::table('model_has_roles')
            ->join('role_has_permissions', 'model_has_roles.role_id', '=', 'role_has_permissions.role_id')
            ->where('model_has_roles.model_type', User::class)
            ->whereIn('model_has_roles.model_id', $roleUserIds->all())
            ->select('model_has_roles.model_id', 'role_has_permissions.permission_id')
            ->get()
            ->groupBy(fn ($row) => (int) $row->model_id)
            ->map(fn ($rows) => $rows->pluck('permission_id')->map(fn ($id) => (int) $id)->all());

        DB::table('model_has_permissions')
            ->where('model_type', User::class)
            ->whereIn('model_id', $roleUserIds->all())
            ->get(['model_id', 'permission_id'])
            ->each(function ($row) use ($roleGrantedPermissionIdsByUser) {
                $modelId = (int) $row->model_id;
                $permissionId = (int) $row->permission_id;

                if (in_array($permissionId, $roleGrantedPermissionIdsByUser->get($modelId, []), true)) {
                    return;
                }

                DB::table('model_has_permissions')
                    ->where('model_type', User::class)
                    ->where('model_id', $modelId)
                    ->where('permission_id', $permissionId)
                    ->delete();
            });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function extractContextData(array &$data): array
    {
        $contextData = [];

        foreach (['context_types', 'auto_assign_contexts'] as $field) {
            if (array_key_exists($field, $data)) {
                $contextData[$field] = $data[$field];
                unset($data[$field]);
            }
        }

        return $contextData;
    }

    private function applyContextData(Role $role, array $contextData): void
    {
        if ($contextData === []) {
            return;
        }

        if (array_key_exists('context_types', $contextData)) {
            $role->context_types = $contextData['context_types'] === null
                ? null
                : json_encode(array_values(array_unique($contextData['context_types'])));
        }

        if (array_key_exists('auto_assign_contexts', $contextData)) {
            $role->auto_assign_contexts = (bool) $contextData['auto_assign_contexts'];
        }

        $role->save();
    }

    private function syncUpdatedRoleContextsForUsers(Role $role, ?string $actorUserId = null): void
    {
        $role->refresh();

        $role->users()->chunk(100, function ($users) use ($role, $actorUserId) {
            foreach ($users as $user) {
                $this->contextService->syncContextsForAssignedRoles($user, [$role], $actorUserId);
            }
        });
    }
}
