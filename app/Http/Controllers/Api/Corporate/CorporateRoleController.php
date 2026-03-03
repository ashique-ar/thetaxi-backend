<?php

namespace App\Http\Controllers\Api\Corporate;

use App\Http\Controllers\Controller;
use App\Models\Corporate\CorporateEmployee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class CorporateRoleController extends Controller
{
    /**
     * Corporate-scoped permissions that can be assigned to roles.
     */
    private const CORPORATE_PERMISSIONS = [
        'manage_employees',
        'manage_departments',
        'manage_divisions',
        'create_bookings',
        'create_bookings_for_others',
        'view_all_bookings',
        'approve_bookings',
        'view_payments',
        'manage_rate_charts',
        'view_reports',
    ];

    /**
     * Default corporate role name prefixes used to identify corporate roles.
     */
    private const DEFAULT_CORPORATE_ROLES = [
        'Corporate_Master_Admin',
        'Transport_Coordinator',
        'Approval_Manager',
        'Corporate_Employee',
    ];

    public function __construct()
    {
        $this->middleware('permission:manage_employees');
    }

    public function index(Request $request): JsonResponse
    {
        $roles = Role::where('guard_name', 'api')
            ->where(function ($q) use ($request) {
                // Include default corporate roles (corporate_id IS NULL)
                $q->where(function ($subQ) {
                    $subQ->whereNull('corporate_id')
                         ->whereIn('name', self::DEFAULT_CORPORATE_ROLES);
                })
                // OR include custom roles belonging to the requesting corporate
                ->orWhere('corporate_id', $request->corporate_id);
            })
            ->with('permissions')
            ->get();

        return response()->json([
            'status' => 'success',
            'data'   => ['roles' => $roles],
        ]);
    }


    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name'          => ['required', 'string', 'max:255'],
            'permissions'   => ['required', 'array', 'min:1'],
            'permissions.*' => ['string', 'in:' . implode(',', self::CORPORATE_PERMISSIONS)],
        ]);

        $roleName = 'Corporate_' . str_replace(' ', '_', $request->name);

        if (Role::where('name', $roleName)->where('guard_name', 'api')->exists()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'A role with this name already exists.',
            ], 422);
        }

        $role = Role::create([
            'name'       => $roleName,
            'guard_name' => 'api',
            'corporate_id' => $request->corporate_id,
        ]);

        $role->syncPermissions($request->permissions);

        return response()->json([
            'status'  => 'success',
            'message' => 'Role created successfully',
            'data'    => ['role' => $role->load('permissions')],
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'permissions'   => ['required', 'array', 'min:1'],
            'permissions.*' => ['string', 'in:' . implode(',', self::CORPORATE_PERMISSIONS)],
        ]);

        $role = Role::where('guard_name', 'api')
            ->where(function ($q) {
                $q->whereIn('name', self::DEFAULT_CORPORATE_ROLES)
                  ->orWhere('name', 'like', 'Corporate_%');
            })
            ->findOrFail($id);

        $role->syncPermissions($request->permissions);

        return response()->json([
            'status'  => 'success',
            'message' => 'Role updated successfully',
            'data'    => ['role' => $role->load('permissions')],
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $role = Role::where('guard_name', 'api')
            ->where(function ($q) {
                $q->whereIn('name', self::DEFAULT_CORPORATE_ROLES)
                  ->orWhere('name', 'like', 'Corporate_%');
            })
            ->findOrFail($id);

        // Guard deletion when employees are assigned to this role
        $assignedCount = CorporateEmployee::where('corporate_id', $request->corporate_id)
            ->whereHas('userContext', function ($q) use ($role) {
                $q->whereHas('roles', function ($rq) use ($role) {
                    $rq->where('roles.id', $role->id);
                });
            })
            ->count();

        if ($assignedCount > 0) {
            return response()->json([
                'status'  => 'error',
                'message' => "Cannot delete role. There are {$assignedCount} employees assigned to this role.",
            ], 409);
        }

        $role->delete();

        return response()->json([
            'status'  => 'success',
            'message' => 'Role deleted successfully',
        ]);
    }

    public function permissions(): JsonResponse
    {
        $permissions = Permission::where('guard_name', 'api')
            ->whereIn('name', self::CORPORATE_PERMISSIONS)
            ->get(['id', 'name']);

        return response()->json([
            'status' => 'success',
            'data'   => ['permissions' => $permissions],
        ]);
    }
}
