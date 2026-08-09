<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Permission\CreatePermissionRequest;
use App\Http\Requests\Permission\UpdatePermissionRequest;
use App\Http\Resources\PermissionResource;
use App\Services\PermissionRegistry;
use Spatie\Permission\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PermissionController extends Controller
{
    public function registry(Request $request, PermissionRegistry $registry): JsonResponse
    {
        $guard = $request->filled('guard_name')
            ? $request->string('guard_name')->toString()
            : null;

        return response()->json([
            'status' => 'success',
            'data' => $registry->grouped($guard),
        ]);
    }

    public function __construct()
    {
        $this->middleware('permission:permissions.view')->only(['index', 'show']);
        $this->middleware('permission:permissions.create')->only(['store']);
        $this->middleware('permission:permissions.edit')->only(['update']);
        $this->middleware('permission:permissions.delete')->only(['destroy']);
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

        if ($request->filled('guard_name')) {
            $query->where('guard_name', $request->guard_name);
        }

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

            \DB::beginTransaction();
            try {
                $permission = Permission::firstOrCreate([
                    'name' => $data['name'],
                    'guard_name' => $data['guard_name']
                ], [
                    'display_name' => $data['display_name'] ?? null,
                    'description' => $data['description'] ?? null,
                ]);

                \DB::commit();
            } catch (\Exception $e) {
                \DB::rollBack();
                throw $e;
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Permission created successfully',
                'data' => [
                    'permission' => new PermissionResource($permission),
                ]
            ], 201);
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
        try {
            $permission->update($request->validated());

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
        try {
            // Check if permission is being used by any roles or users
            if ($permission->roles()->exists() || $permission->users()->exists()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cannot delete permission that is assigned to roles or users'
                ], 400);
            }

            $permission->delete();

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
        $users = $permission->users()->select('id', 'first_name', 'last_name', 'email')->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'users' => $users
            ]
        ]);
    }
}
