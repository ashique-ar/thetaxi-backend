<?php

namespace App\Services;

use App\Models\Corporate\Corporate;
use App\Models\Corporate\CorporateEmployee;
use App\Models\User;
use App\Models\UserContext;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class CorporateRoleStarterService
{
    /**
     * Corporate-scoped permissions that can be assigned to corporate roles.
     */
    public const CORPORATE_PERMISSIONS = [
        'manage-roles',
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
        'corporate.audit-logs.view',
        'corporate.view',
        'bookings.view',
        'bookings.create',
        'staff-transport.self.view',
        'staff-transport.view',
        'staff-transport.manage',
        'staff-transport.override',
        'staff-transport.generate',
    ];

    public const TEMPLATES = [
        'Corporate_Master_Admin' => [
            'label' => 'Master Administrator',
            'key' => 'Master_Administrator',
            'permissions' => [
                'manage-roles', 'manage_employees', 'manage_departments', 'manage_divisions',
                'create_bookings', 'create_bookings_for_others', 'view_all_bookings',
                'approve_bookings', 'view_payments', 'manage_billing', 'manage_rate_charts',
                'view_reports', 'schedule_reports', 'corporate.audit-logs.view', 'corporate.view',
                'bookings.view', 'bookings.create', 'staff-transport.self.view',
                'staff-transport.view', 'staff-transport.manage', 'staff-transport.override',
                'staff-transport.generate',
            ],
        ],
        'Transport_Coordinator' => [
            'label' => 'Transport Coordinator',
            'key' => 'Transport_Coordinator',
            'permissions' => [
                'corporate.view', 'manage-roles', 'manage_employees', 'create_bookings',
                'create_bookings_for_others', 'view_all_bookings', 'bookings.view',
                'bookings.create', 'staff-transport.self.view', 'staff-transport.view',
                'staff-transport.manage', 'staff-transport.override', 'staff-transport.generate',
            ],
        ],
        'Approval_Manager' => [
            'label' => 'Approval Manager',
            'key' => 'Approval_Manager',
            'permissions' => ['corporate.view', 'approve_bookings', 'view_all_bookings'],
        ],
        'Corporate_Employee' => [
            'label' => 'Employee',
            'key' => 'Employee',
            'permissions' => [
                'corporate.view', 'create_bookings', 'bookings.view',
                'bookings.create', 'staff-transport.self.view',
            ],
        ],
    ];

    /**
     * Create missing corporate-account roles and move legacy shared-role assignments.
     *
     * Existing company role permissions are never overwritten.
     *
     * @return array{roles_created: int, assignments_migrated: int}
     */
    public function provision(Corporate $corporate): array
    {
        return DB::transaction(function () use ($corporate): array {
            $created = 0;
            $migrated = 0;

            foreach (self::TEMPLATES as $legacyName => $template) {
                $role = Role::where('guard_name', 'api')
                    ->where('name', $this->roleName($corporate->id, $template['key']))
                    ->first();

                if (! $role) {
                    $role = Role::create([
                        'name' => $this->roleName($corporate->id, $template['key']),
                        'guard_name' => 'api',
                    ]);
                    $role->syncPermissions($this->permissions($template['permissions']));
                    $created++;
                }

                $legacyRole = Role::where('guard_name', 'api')->where('name', $legacyName)->first();
                if (! $legacyRole) {
                    continue;
                }

                $contexts = DB::table('user_contexts')
                    ->join('corporate_employees', 'corporate_employees.id', '=', 'user_contexts.context_id')
                    ->join('user_context_roles', function ($join) use ($legacyRole) {
                        $join->on('user_context_roles.user_context_id', '=', 'user_contexts.id')
                            ->where('user_context_roles.role_id', '=', $legacyRole->id);
                    })
                    ->where('user_contexts.context_type', 'corporate')
                    ->where('corporate_employees.corporate_id', $corporate->id)
                    ->whereNull('user_contexts.deleted_at')
                    ->whereNull('corporate_employees.deleted_at')
                    ->select('user_contexts.id', 'user_contexts.user_id')
                    ->lockForUpdate()
                    ->get();

                foreach ($contexts as $row) {
                    $user = User::query()->whereKey($row->user_id)->lockForUpdate()->firstOrFail();
                    $context = UserContext::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();
                    $contextRoles = app(UserContextService::class);
                    $contextRoles->revokeRoleFromContext($user, $context, (int) $legacyRole->id);
                    $contextRoles->assignRolesToContext($user, $context, [(int) $role->id]);
                    $migrated++;
                }
            }

            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

            return ['roles_created' => $created, 'assignments_migrated' => $migrated];
        });
    }

    public function resolve(Corporate $corporate, string $requestedName): ?Role
    {
        $template = self::TEMPLATES[$requestedName] ?? null;
        if (! $template) {
            $normalized = str_replace([' ', '-'], '_', trim($requestedName));
            $template = collect(self::TEMPLATES)->first(
                fn (array $candidate) => in_array($normalized, [
                    $candidate['key'],
                    str_replace(' ', '_', $candidate['label']),
                ], true)
            );
        }

        $prefix = $this->customRolePrefix($corporate->id);
        $name = $template
            ? $this->roleName($corporate->id, $template['key'])
            : (Str::startsWith($requestedName, $prefix)
                ? $requestedName
                : $this->roleName($corporate->id, Str::of($requestedName)->trim()->replace([' ', '-'], '_')->toString()));

        return Role::where('guard_name', 'api')
            ->where('name', $name)
            ->where('name', 'like', $prefix.'%')
            ->first();
    }

    public function listRoles(Corporate $corporate): Collection
    {
        $this->provision($corporate);
        $prefix = $this->customRolePrefix($corporate->id);

        return Role::where('guard_name', 'api')
            ->where('name', 'like', $prefix.'%')
            ->with('permissions')
            ->get()
            ->each(function (Role $role) use ($prefix): void {
                $role->setAttribute('display_name', Str::startsWith($role->name, $prefix)
                    ? Str::after($role->name, $prefix)
                    : $role->name);
            });
    }

    public function createRole(Corporate $corporate, array $data): Role
    {
        $prefix = $this->customRolePrefix($corporate->id);
        $roleName = $prefix . Str::of($data['name'])->trim()->replace([' ', '-'], '_');

        if (Role::where('name', $roleName)->where('guard_name', 'api')->exists()) {
            $this->abortJson('A role with this name already exists.', 422);
        }

        $attributes = [
            'name' => $roleName,
            'guard_name' => 'api',
        ];
        if (array_key_exists('description', $data) && $this->hasDescriptionColumn()) {
            $attributes['description'] = $data['description'];
        }

        $role = Role::create($attributes);
        $role->syncPermissions($this->ensureCorporatePermissions($data['permissions'] ?? []));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role->setAttribute('display_name', Str::after($role->name, $prefix));

        return $role->load('permissions');
    }

    public function updateRole(Corporate $corporate, string $id, array $data): Role
    {
        $prefix = $this->customRolePrefix($corporate->id);
        $role = Role::where('guard_name', 'api')
            ->where('name', 'like', $prefix.'%')
            ->findOrFail($id);

        if ($this->isAssignedOutsideCorporate($role, $corporate->id)) {
            $this->abortJson('This role is used by another corporate account and cannot be changed here.', 409);
        }

        $hasName = isset($data['name']) && trim((string) $data['name']) !== '';
        if ($this->isDefaultRole($role, $corporate->id) && $hasName) {
            $requestedKey = (string) Str::of($data['name'])->trim()->replace([' ', '-'], '_');
            abort_unless($requestedKey === Str::after($role->name, $prefix), 422, 'Default corporate roles cannot be renamed.');
        }

        if ($hasName && ! $this->isDefaultRole($role, $corporate->id)) {
            $roleName = $prefix . Str::of($data['name'])->trim()->replace([' ', '-'], '_');
            if (Role::where('guard_name', 'api')->where('name', $roleName)->where('id', '!=', $role->id)->exists()) {
                $this->abortJson('A role with this name already exists.', 422);
            }
            $role->name = (string) $roleName;
        }

        if (array_key_exists('description', $data) && $this->hasDescriptionColumn()) {
            $role->description = $data['description'];
        }

        if ($role->isDirty()) {
            $role->save();
        }

        $role->syncPermissions($this->ensureCorporatePermissions($data['permissions'] ?? []));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role->setAttribute('display_name', Str::after($role->name, $prefix));

        return $role->load('permissions');
    }

    public function deleteRole(Corporate $corporate, string $id): void
    {
        $prefix = $this->customRolePrefix($corporate->id);
        $role = Role::where('guard_name', 'api')
            ->where('name', 'like', $prefix.'%')
            ->findOrFail($id);

        abort_if($this->isDefaultRole($role, $corporate->id), 403, 'Default corporate roles cannot be deleted.');

        $assignedCount = CorporateEmployee::withInactive()
            ->where('corporate_id', $corporate->id)
            ->whereHas('userContext.roles', fn ($rq) => $rq->where('roles.id', $role->id))
            ->count();

        if ($assignedCount > 0) {
            $this->abortJson("Cannot delete role. There are {$assignedCount} employees assigned to this role.", 409);
        }

        $role->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function listPermissions(): Collection
    {
        $this->ensureCorporatePermissions(self::CORPORATE_PERMISSIONS);

        return Permission::where('guard_name', 'api')
            ->whereIn('name', self::CORPORATE_PERMISSIONS)
            ->get(['id', 'name']);
    }

    public function roleName(string $corporateId, string $key): string
    {
        return 'Corporate_'.$corporateId.'_'.$key;
    }

    public function customRolePrefix(string $corporateId): string
    {
        return 'Corporate_'.$corporateId.'_';
    }

    public function isDefaultRole(Role $role, string $corporateId): bool
    {
        return in_array(
            Str::after($role->name, $this->customRolePrefix($corporateId)),
            array_column(self::TEMPLATES, 'key'),
            true
        );
    }

    private function isAssignedOutsideCorporate(Role $role, string $corporateId): bool
    {
        return CorporateEmployee::withInactive()
            ->where('corporate_id', '!=', $corporateId)
            ->whereHas('userContext.roles', fn ($query) => $query->where('roles.id', $role->id))
            ->exists();
    }

    private function ensureCorporatePermissions(array $permissionNames): array
    {
        $names = array_values(array_unique($permissionNames));

        foreach ($names as $name) {
            Permission::findOrCreate($name, 'api');
        }

        return $names;
    }

    private function hasDescriptionColumn(): bool
    {
        return Schema::hasColumn(config('permission.table_names.roles', 'roles'), 'description');
    }

    private function abortJson(string $message, int $status): never
    {
        throw new HttpResponseException(response()->json([
            'status' => 'error',
            'message' => $message,
        ], $status));
    }

    public static function isCorporateRoleName(string $name): bool
    {
        $name = strtolower(str_replace([' ', '-'], '_', trim($name)));

        return $name === 'corporate'
            || str_starts_with($name, 'corporate_')
            || in_array($name, ['transport_coordinator', 'approval_manager'], true);
    }

    public static function displayName(string $name): string
    {
        $name = preg_replace('/^corporate_[0-9a-f-]{36}_/i', '', $name) ?? $name;

        return $name === 'super-admin' ? 'Master Admin' : \Illuminate\Support\Str::headline($name);
    }

    private function permissions(array $names): array
    {
        return collect($names)
            ->map(fn (string $name) => Permission::findOrCreate($name, 'api'))
            ->all();
    }
}
