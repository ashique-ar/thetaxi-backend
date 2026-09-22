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
        'manage_billing',
        'manage_rate_charts',
        'view_reports',
        'schedule_reports',
        'view_audit_log',
        'corporate.view',
        'bookings.view',
        'bookings.create',
        'staff-transport.view',
        'staff-transport.manage',
        'staff-transport.override',
        'staff-transport.generate',
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

        $prefix = $this->customRolePrefix($request->corporate_id);
        $roles = Role::where('guard_name', 'api')
            ->where(function ($q) use ($prefix) {
                $q->whereIn('name', self::DEFAULT_CORPORATE_ROLES)
                    ->orWhere('name', 'like', $prefix.'%');
            })
            ->with('permissions')
            ->get()
            ->each(function (Role $role) use ($prefix): void {
                $role->setAttribute('display_name', Str::startsWith($role->name, $prefix)
                    ? Str::after($role->name, $prefix)
                    : $role->name);
            });

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
            'name'          => ['required', 'string', 'max:170'],
            'description'   => ['nullable', 'string', 'max:1000'],
            'permissions'   => ['present', 'array'],
            'permissions.*' => ['string', 'in:' . implode(',', self::CORPORATE_PERMISSIONS)],
        ]);

        $roleName = $this->customRolePrefix($request->corporate_id)
            .Str::of($request->name)->trim()->replace([' ', '-'], '_');

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
            'name'          => ['sometimes', 'string', 'max:170'],
            'description'   => ['nullable', 'string', 'max:1000'],
            'permissions'   => ['present', 'array'],
            'permissions.*' => ['string', 'in:' . implode(',', self::CORPORATE_PERMISSIONS)],
        ]);

        $role = Role::where('guard_name', 'api')
            ->where('name', 'like', $this->customRolePrefix($request->corporate_id).'%')
            ->findOrFail($id);

        if ($this->isAssignedOutsideCorporate($role, $request->corporate_id)) {
            return response()->json([
                'status' => 'error',
                'message' => 'This role is used by another corporate account and cannot be changed here.',
            ], 409);
        }

        if ($request->filled('name')) {
            $roleName = $this->customRolePrefix($request->corporate_id)
                .Str::of($request->name)->trim()->replace([' ', '-'], '_');
            if (Role::where('guard_name', 'api')->where('name', $roleName)->where('id', '!=', $role->id)->exists()) {
                return response()->json(['status' => 'error', 'message' => 'A role with this name already exists.'], 422);
            }
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
            ->where('name', 'like', $this->customRolePrefix($request->corporate_id).'%')
            ->findOrFail($id);

        // Guard deletion when employees are assigned to this role
        $assignedCount = CorporateEmployee::query()
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

    private function isAssignedOutsideCorporate(Role $role, string $corporateId): bool
    {
        return CorporateEmployee::where('corporate_id', '!=', $corporateId)
            ->whereHas('userContext.roles', fn ($query) => $query->where('roles.id', $role->id))
            ->exists();
    }

    private function customRolePrefix(string $corporateId): string
    {
        return 'Corporate_'.$corporateId.'_';
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
