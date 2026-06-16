<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Role\CreateRoleRequest;
use App\Http\Requests\Role\UpdateRoleRequest;
use App\Http\Resources\RoleResource;
use App\Services\PermissionAssignmentService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class RoleController extends Controller
{
    private PermissionAssignmentService $assignmentService;

    public function __construct(PermissionAssignmentService $assignmentService)
    {
        $this->assignmentService = $assignmentService;
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

            $role = Role::create($data);
            
            if ($permissions !== null) {
                $this->assignmentService->syncRolePermissions($role, $permissions);
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

            $role->update($data);
            
            if ($permissions !== null) {
                $this->assignmentService->syncRolePermissions($role, $permissions);
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
            $this->assignmentService->syncRolePermissions($role, $permissions);
            
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
            $this->assignmentService->syncRolePermissions($role, $permissions);
            
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
        $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string'],
        ]);

        $permissions = $this->assignmentService->syncRolePermissions($role, $request->permissions);

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
}
