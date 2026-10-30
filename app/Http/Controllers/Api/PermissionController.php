<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Permission\CreatePermissionRequest;
use App\Http\Requests\Permission\UpdatePermissionRequest;
use App\Http\Resources\PermissionResource;
use App\Services\PermissionAssignmentService;
use App\Services\PermissionRegistry;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class PermissionController extends Controller
{
    public function __construct(private PermissionAssignmentService $assignmentService)
    {
        $this->middleware('permission:permissions.view')->only(['index', 'show']);
        $this->middleware('permission:permissions.create')->only(['store']);
        $this->middleware('permission:permissions.edit')->only(['update']);
        $this->middleware('permission:permissions.delete')->only(['destroy']);
    }

    public function registry(Request $request, PermissionRegistry $registry): JsonResponse
    {
        $guard = config('permissions.canonical_guard', 'api');

        return response()->json([
            'status' => 'success',
            'data' => $registry->grouped($guard),
        ]);
    }

    /**
     * Display a listing of permissions
     *
     * @param Request $request
     * @return AnonymousResourceCollection
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Permission::query();

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        $query->where('guard_name', config('permissions.canonical_guard', 'api'));

        $sortable = ['id', 'name', 'guard_name', 'created_at'];
        $sortBy = $request->get('sort_by');
        $sortDirection = strtolower($request->get('sort_direction', 'asc')) === 'desc' ? 'desc' : 'asc';

        if ($sortBy && in_array($sortBy, $sortable, true)) {
            $query->orderBy($sortBy, $sortDirection);
        } else {
            $query->orderBy('name');
        }

        $permissions = $query->paginate($request->per_page ?? 15);
        return PermissionResource::collection($permissions);
    }

    /**
     * Store a newly created permission
     *
     * @param CreatePermissionRequest $request
     * @return JsonResponse
     */
    public function store(CreatePermissionRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();

            $data['guard_name'] = $data['guard_name'] ?? config('permissions.canonical_guard', 'api');
            $this->assignmentService->assertCanManageProtectedPermissions([$data['name']]);

            \DB::beginTransaction();
            try {
                $permission = Permission::firstOrCreate([
                    'name' => $data['name'],
                    'guard_name' => $data['guard_name']
                ], [
                    'display_name' => $data['display_name'] ?? null,
                    'description' => $data['description'] ?? null,
                ]);

                $masterAdmin = Role::firstOrCreate([
                    'name' => 'super-admin',
                    'guard_name' => $data['guard_name'],
                ]);
                $masterAdmin->givePermissionTo($permission);

                if (! PermissionRegistry::isAdminOnlyPermissionName($permission->name)) {
                    Role::firstOrCreate([
                        'name' => 'admin',
                        'guard_name' => $data['guard_name'],
                    ])->givePermissionTo($permission);
                }

                \DB::commit();
            } catch (\Exception $e) {
                \DB::rollBack();
                throw $e;
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return response()->json([
                'status' => 'success',
                'message' => 'Permission created successfully',
                'data' => [
                    'permission' => new PermissionResource($permission),
                ]
            ], 201);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create permission',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified permission
     *
     * @param Permission $permission
     * @return JsonResponse
     */
    public function show(Permission $permission): JsonResponse
    {
        $this->protectCanonicalPermission($permission);

        return response()->json([
            'status' => 'success',
            'data' => [
                'permission' => new PermissionResource($permission)
            ]
        ]);
    }

    /**
     * Update the specified permission
     *
     * @param UpdatePermissionRequest $request
     * @param Permission $permission
     * @return JsonResponse
     */
    public function update(UpdatePermissionRequest $request, Permission $permission): JsonResponse
    {
        $this->protectCanonicalPermission($permission);
        $data = $request->validated();
        $this->assignmentService->assertCanManageProtectedPermissions(array_filter([
            $permission->name,
            $data['name'] ?? null,
        ]));

        try {
            $permission->update($data);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return response()->json([
                'status' => 'success',
                'message' => 'Permission updated successfully',
                'data' => [
                    'permission' => new PermissionResource($permission)
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update permission',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified permission
     *
     * @param Permission $permission
     * @return JsonResponse
     */
    public function destroy(Permission $permission): JsonResponse
    {
        $this->protectCanonicalPermission($permission);
        $this->assignmentService->assertCanManageProtectedPermissions([$permission->name]);

        try {
            // Check if permission is being used by any roles or users
            if ($permission->roles()->exists() || $permission->users()->exists()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cannot delete permission that is assigned to roles or users'
                ], 400);
            }

            $permission->delete();
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return response()->json([
                'status' => 'success',
                'message' => 'Permission deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete permission',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get roles that have this permission
     *
     * @param Permission $permission
     * @return JsonResponse
     */
    public function roles(Permission $permission): JsonResponse
    {
        $this->protectCanonicalPermission($permission);

        $roles = $permission->roles()->select('id', 'name', 'guard_name')->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'roles' => $roles
            ]
        ]);
    }

    /**
     * Get users that have this permission
     *
     * @param Permission $permission
     * @return JsonResponse
     */
    public function users(Permission $permission): JsonResponse
    {
        $this->protectCanonicalPermission($permission);

        $users = $permission->users()->select('id', 'first_name', 'last_name', 'email')->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'users' => $users
            ]
        ]);
    }

    private function protectCanonicalPermission(Permission $permission): void
    {
        abort_unless($permission->guard_name === config('permissions.canonical_guard', 'api'), 404, 'Permission not found.');
    }
}
