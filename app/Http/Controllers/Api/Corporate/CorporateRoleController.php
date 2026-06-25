<?php

namespace App\Http\Controllers\Api\Corporate;

use App\Http\Controllers\Controller;
use App\Models\Corporate\CorporateEmployee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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
        'view_audit_log',
        'bookings.view',
        'bookings.create',
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
    }

    public function index(Request $request): JsonResponse
    {
        if (! $this->canManageCorporateRoles($request)) {
            return $this->forbiddenResponse();
        }

        $roles = Role::where('guard_name', 'api')
            ->where(function ($q) {
                $q->whereIn('name', self::DEFAULT_CORPORATE_ROLES)
                    ->orWhere('name', 'like', 'Corporate_%');
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
        if (! $this->canManageCorporateRoles($request)) {
            return $this->forbiddenResponse();
        }

        $request->validate([
            'name'          => ['required', 'string', 'max:255'],
            'description'   => ['nullable', 'string', 'max:1000'],
            'permissions'   => ['required', 'array', 'min:1'],
            'permissions.*' => ['string', 'in:' . implode(',', self::CORPORATE_PERMISSIONS)],
        ]);

        $roleName = Str::startsWith($request->name, 'Corporate_')
            ? $request->name
            : 'Corporate_' . Str::of($request->name)->trim()->replace(' ', '_');

        if (Role::where('name', $roleName)->where('guard_name', 'api')->exists()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'A role with this name already exists.',
            ], 422);
        }

        $role = Role::create([
            'name'       => $roleName,
            'guard_name' => 'api',
        ]);

        $role->syncPermissions($this->ensureCorporatePermissions($request->permissions));

        return response()->json([
            'status'  => 'success',
            'message' => 'Role created successfully',
            'data'    => ['role' => $role->load('permissions')],
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        if (! $this->canManageCorporateRoles($request)) {
            return $this->forbiddenResponse();
        }

        $request->validate([
            'name'          => ['sometimes', 'string', 'max:255'],
            'description'   => ['nullable', 'string', 'max:1000'],
            'permissions'   => ['required', 'array', 'min:1'],
            'permissions.*' => ['string', 'in:' . implode(',', self::CORPORATE_PERMISSIONS)],
        ]);

        $role = Role::where('guard_name', 'api')
            ->where(function ($q) {
                $q->whereIn('name', self::DEFAULT_CORPORATE_ROLES)
                  ->orWhere('name', 'like', 'Corporate_%');
            })
            ->findOrFail($id);

        if ($request->filled('name') && ! in_array($role->name, self::DEFAULT_CORPORATE_ROLES, true)) {
            $roleName = Str::startsWith($request->name, 'Corporate_')
                ? $request->name
                : 'Corporate_' . Str::of($request->name)->trim()->replace(' ', '_');
            $role->name = (string) $roleName;
            $role->save();
        }

        $role->syncPermissions($this->ensureCorporatePermissions($request->permissions));

        return response()->json([
            'status'  => 'success',
            'message' => 'Role updated successfully',
            'data'    => ['role' => $role->load('permissions')],
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        if (! $this->canManageCorporateRoles($request)) {
            return $this->forbiddenResponse();
        }

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
        $this->ensureCorporatePermissions(self::CORPORATE_PERMISSIONS);

        $permissions = Permission::where('guard_name', 'api')
            ->whereIn('name', self::CORPORATE_PERMISSIONS)
            ->get(['id', 'name']);

        return response()->json([
            'status' => 'success',
            'data'   => ['permissions' => $permissions],
        ]);
    }

    private function canManageCorporateRoles(Request $request): bool
    {
        $context = $request->user()?->contexts()
            ->where('context_type', 'corporate')
            ->where('is_active', true)
            ->when($request->header('X-Active-Context-Id'), fn ($q, $id) => $q->where('id', $id))
            ->with('roles.permissions')
            ->first();

        if (! $context) {
            return false;
        }

        return $context->roles
            ->flatMap(fn (Role $role) => $role->permissions->pluck('name'))
            ->contains('manage_employees');
    }

    private function forbiddenResponse(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => 'You do not have permission to manage corporate roles.',
        ], 403);
    }

    private function ensureCorporatePermissions(array $permissionNames): array
    {
        $names = array_values(array_unique($permissionNames));

        foreach ($names as $name) {
            Permission::findOrCreate($name, 'api');
        }

        return $names;
    }
}
