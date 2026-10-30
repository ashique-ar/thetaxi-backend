<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Role\CreateRoleRequest;
use App\Http\Requests\Role\UpdateRoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\User;
use App\Services\PermissionAssignmentService;
use App\Services\CorporateRoleStarterService;
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
        $query->whereRaw('LOWER(name) NOT LIKE ?', ['corporate%'])
            ->whereRaw('LOWER(name) NOT IN (?, ?)', ['transport_coordinator', 'approval_manager']);
        $query->where(fn ($roles) => $roles->whereNull('context_types')
            ->orWhereJsonDoesntContain('context_types', 'corporate'));

        $actorRoles = $request->user()->roles()
            ->whereIn('name', ['admin', 'super-admin'])
            ->where('guard_name', config('permissions.canonical_guard', 'api'))
            ->pluck('name');
        if (!$actorRoles->contains('super-admin')) {
            $query->where('name', '!=', 'super-admin');
        }
        if (!$actorRoles->contains('admin') && !$actorRoles->contains('super-admin')) {
            $query->where('name', '!=', 'admin');
        }

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        $query->where('guard_name', config('permissions.canonical_guard', 'api'));

        $sortable = ['name', 'guard_name'];
        $sortBy = $request->get('sort_by');
        $sortDirection = strtolower($request->get('sort_direction', 'asc')) === 'desc' ? 'desc' : 'asc';

        if ($sortBy && in_array($sortBy, $sortable, true)) {
            $query->orderBy($sortBy, $sortDirection);
        } else {
            $query->orderBy('name');
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
            if (CorporateRoleStarterService::isCorporateRoleName($data['name'])) {
                throw ValidationException::withMessages(['name' => 'Corporate roles must be managed in Corporate Management.']);
            }
            $permissions = $data['permissions'] ?? null;
            unset($data['permissions']);

            if ($permissions !== null) {
                $permissions = $this->resolvePermissionNames($permissions, $data['guard_name']);
                $this->assignmentService->assertRolePermissionPolicy($data['name'], $data['guard_name'], $permissions);
            }

            $contextData = $this->extractContextData($data);
            $role = Role::create($data);
            $this->applyContextData($role, $contextData);
            
            if ($permissions !== null) {
                $this->syncRolePermissions($role, $permissions);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Role created successfully',
                'data' => [
                    'role' => new RoleResource($role->load('permissions'))
                ]
            ], 201);
        } catch (ValidationException $e) {
            throw $e;
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
        $this->protectManagedRole($role);

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
        $this->protectManagedRole($role);

        try {
            $data = $request->validated();
            if (in_array($role->name, ['admin', 'super-admin'], true)
                && isset($data['name']) && $data['name'] !== $role->name) {
                throw ValidationException::withMessages(['name' => 'The canonical system role name cannot be changed.']);
            }
            if (isset($data['name']) && CorporateRoleStarterService::isCorporateRoleName($data['name'])) {
                throw ValidationException::withMessages(['name' => 'Corporate roles must be managed in Corporate Management.']);
            }
            $permissions = $data['permissions'] ?? null;
            unset($data['permissions']);

            if ($permissions !== null) {
                $permissions = $this->resolvePermissionNames($permissions, $role->guard_name);
            }
            $this->assignmentService->assertRolePermissionPolicy(
                $data['name'] ?? $role->name,
                $role->guard_name,
                $permissions ?? $role->permissions()->pluck('name')->all()
            );

            $contextData = $this->extractContextData($data);
            $role->update($data);
            $this->applyContextData($role, $contextData);

            if ($contextData !== []) {
                $this->syncUpdatedRoleContextsForUsers($role, $request->user()?->id);
            }
            
            if ($permissions !== null) {
                $this->syncRolePermissions($role, $permissions);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Role updated successfully',
                'data' => [
                    'role' => new RoleResource($role->load('permissions'))
                ]
            ]);
        } catch (ValidationException $e) {
            throw $e;
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
        $this->protectManagedRole($role);
        abort_if(in_array($role->name, ['admin', 'super-admin'], true), 403, 'System roles cannot be deleted.');

        try {
            // Check if role is being used by any users
            if ($role->users()->exists()
                || DB::table('model_has_roles')->where('role_id', $role->id)->exists()
                || DB::table('user_context_roles')->where('role_id', $role->id)->exists()
                || DB::table('user_direct_role_grants')->where('role_id', $role->id)->exists()) {
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
        $this->protectManagedRole($role);

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
        $this->protectManagedRole($role);

        $request->validate([
            'permissions' => ['required', 'array'],
            'permissions.*' => ['required', 'string']
        ]);
        $permissionNames = $this->resolvePermissionNames($request->permissions, $role->guard_name);

        try {
            $this->assignmentService->grantRolePermissions($role, $permissionNames);
            
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
     * Revoke permissions from role
     *
     * @param Request $request
     * @param Role $role
     * @return JsonResponse
     */
    public function revokePermissions(Request $request, Role $role): JsonResponse
    {
        $this->protectManagedRole($role);

        $request->validate([
            'permissions' => ['required', 'array'],
            'permissions.*' => ['required', 'string']
        ]);
        $permissionNames = $this->resolvePermissionNames($request->permissions, $role->guard_name);

        try {
            $this->assignmentService->revokeRolePermissions($role, $permissionNames);
            
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
     * Get users with this role
     *
     * @param Role $role
     * @return JsonResponse
     */
    public function users(Role $role): JsonResponse
    {
        $this->protectManagedRole($role);

        $globalUsers = DB::table('model_has_roles')
            ->where('role_id', $role->id)
            ->where('model_type', User::class)
            ->select('model_id as user_id');
        $contextUsers = DB::table('user_context_roles')
            ->join('user_contexts', 'user_contexts.id', '=', 'user_context_roles.user_context_id')
            ->where('user_context_roles.role_id', $role->id)
            ->where('user_contexts.is_active', true)
            ->whereNull('user_contexts.deleted_at')
            ->select('user_contexts.user_id');
        $users = User::query()->whereIn('id', $globalUsers->union($contextUsers))
            ->select('id', 'first_name', 'last_name', 'email')->get();
        
        return response()->json([
            'status' => 'success',
            'data' => [
                'users' => $users
            ]
        ]);
    }

    public function syncPermissions(Request $request, Role $role): JsonResponse
    {
        $this->protectManagedRole($role);

        abort_if(! $role->exists || $role->getKey() === null, 404, 'Role not found.');

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

        $requestedPermissionNames = collect($this->resolvePermissionNames($rawPermissions, $role->guard_name))
            ->map(fn ($permission) => (string) $permission)
            ->unique()
            ->values();

        $existingPermissionNames = Permission::query()
            ->where('guard_name', $role->guard_name)
            ->whereIn('name', $requestedPermissionNames->all())
            ->pluck('name')
            ->map(fn ($permission) => (string) $permission)
            ->unique()
            ->values();

        $invalidPermissionNames = $requestedPermissionNames->diff($existingPermissionNames)->values();

        if ($invalidPermissionNames->isNotEmpty()) {
            throw ValidationException::withMessages([
                'permissions' => 'One or more selected permissions are invalid for this role.',
            ]);
        }

        $permissions = $this->syncRolePermissions($role, $requestedPermissionNames->all())
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
        $this->protectManagedRole($role);

        $request->validate([
            'template' => ['required', 'string'],
            'mode' => ['sometimes', Rule::in(['merge', 'replace'])],
        ]);

        $permissions = $this->assignmentService->applyTemplate(
            $role,
            $request->input('template'),
            $request->input('mode', 'merge')
        );

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
    private function resolvePermissionNames(array $permissionIdentifiers, ?string $guardName = null): array
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
        $names = $identifiers->reject(fn ($value) => ctype_digit($value))
            ->map(fn ($name) => config("permissions.aliases.{$name}", $name))
            ->values();

        $permissions = Permission::query()
            ->where(function ($query) use ($ids, $names) {
                $query->when($ids->isNotEmpty(), fn ($query) => $query->whereIn('id', $ids))
                    ->when($names->isNotEmpty(), function ($query) use ($names, $ids) {
                        $method = $ids->isNotEmpty() ? 'orWhereIn' : 'whereIn';
                        $query->{$method}('name', $names);
                    });
            })
            ->when($guardName, fn ($query) => $query->where('guard_name', $guardName))
            ->get(['id', 'name']);

        $resolvedNames = $permissions->pluck('name')->unique()->values();
        $resolvedIds = $permissions->pluck('id')->map(fn ($id) => (string) $id);
        $resolvedIdentifiers = $resolvedNames
            ->concat($resolvedIds)
            ->map(fn ($value) => (string) $value)
            ->unique();

        $normalizedIdentifiers = $identifiers->map(
            fn (string $identifier) => ctype_digit($identifier)
                ? $identifier
                : config("permissions.aliases.{$identifier}", $identifier)
        );
        $invalid = $normalizedIdentifiers->diff($resolvedIdentifiers)->values();

        if ($invalid->isNotEmpty()) {
            throw ValidationException::withMessages([
                'permissions' => 'One or more selected permissions are invalid.',
            ]);
        }

        return $this->assignmentService->normalizePermissionNames($resolvedNames->all());
    }

    private function syncRolePermissions(Role $role, array $permissionIdentifiers): Collection
    {
        return $this->assignmentService->syncRolePermissions($role, $permissionIdentifiers);
    }

    private function protectManagedRole(Role $role): void
    {
        abort_unless($role->guard_name === config('permissions.canonical_guard', 'api'), 404, 'Role not found.');
        abort_if(CorporateRoleStarterService::isCorporateRoleName($role->name), 404, 'Role not found.');
        abort_if($this->isCorporateScopedRole($role), 404, 'Role not found.');

        if (in_array($role->name, ['admin', 'super-admin'], true)) {
            $actorRoles = request()->user()?->roles()
                ->whereIn('name', ['admin', 'super-admin'])
                ->where('guard_name', config('permissions.canonical_guard', 'api'))
                ->pluck('name') ?? collect();
            $canManage = $role->name === 'admin'
                ? $actorRoles->contains('admin') || $actorRoles->contains('super-admin')
                : $actorRoles->contains('super-admin');

            abort_unless($canManage, 403, 'You are not allowed to manage this system role.');
        }
    }

    private function isCorporateScopedRole(Role $role): bool
    {
        $contextTypes = $role->context_types;
        if (is_string($contextTypes)) {
            $contextTypes = json_decode($contextTypes, true);
        }

        return is_array($contextTypes) && in_array('corporate', $contextTypes, true);
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
