<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class PermissionAssignmentService
{
    public function __construct(private PermissionRegistry $registry)
    {
    }

    public function normalizePermissionNames(array $permissionIdentifiers): array
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
        $names = $identifiers->reject(fn ($value) => ctype_digit($value))->map(fn ($name) => $this->registry->resolveKey($name))->values();

        $resolvedById = $ids->isNotEmpty()
            ? Permission::query()->whereIn('id', $ids)->pluck('name')
            : collect();

        return $names
            ->concat($resolvedById)
            ->map(fn ($name) => $this->registry->resolveKey((string) $name))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function syncRolePermissions(Role $role, array $permissionIdentifiers): Collection
    {
        $permissionNames = $this->normalizePermissionNames($permissionIdentifiers);
        $permissions = $this->permissionsForGuard($permissionNames, $role->guard_name);

        DB::transaction(function () use ($role, $permissions) {
            DB::table('role_has_permissions')
                ->where('role_id', $role->id)
                ->delete();

            $permissions
                ->pluck('id')
                ->unique()
                ->each(fn ($permissionId) => DB::table('role_has_permissions')->insert([
                    'permission_id' => $permissionId,
                    'role_id' => $role->id,
                ]));
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return Permission::query()
            ->join('role_has_permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('role_has_permissions.role_id', $role->id)
            ->where('permissions.guard_name', $role->guard_name)
            ->orderBy('permissions.name')
            ->select('permissions.*')
            ->get();
    }

    public function applyTemplate(Role $role, string $templateKey, string $mode = 'merge'): Collection
    {
        $template = collect($this->registry->templates())->firstWhere('key', $templateKey);
        abort_if(!$template, 422, 'Selected permission template is invalid.');

        $templatePermissions = $template['permissions'] ?? [];
        $permissions = $mode === 'replace'
            ? $templatePermissions
            : collect($role->permissions()->pluck('name'))->merge($templatePermissions)->unique()->values()->all();

        return $this->syncRolePermissions($role, $permissions);
    }

    public function syncDirectUserPermissions(User $user, array $permissionIdentifiers): Collection
    {
        $permissions = $this->registry->ensureCanonicalPermissions(
            $this->normalizePermissionNames($permissionIdentifiers)
        );

        DB::transaction(function () use ($user, $permissions) {
            DB::table('model_has_permissions')
                ->where('model_type', User::class)
                ->where('model_id', $user->id)
                ->delete();

            foreach ($permissions as $permission) {
                DB::table('model_has_permissions')->updateOrInsert([
                    'permission_id' => $permission->id,
                    'model_type' => User::class,
                    'model_id' => $user->id,
                ]);
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $permissions;
    }

    private function ensurePermissionsForGuard(array $permissionNames, string $guard): Collection
    {
        return collect($permissionNames)
            ->map(fn ($name) => $this->registry->resolveKey((string) $name))
            ->filter()
            ->unique()
            ->map(fn ($name) => Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => $guard,
            ]))
            ->values();
    }

    private function permissionsForGuard(array $permissionNames, string $guard): Collection
    {
        $names = collect($permissionNames)
            ->map(fn ($name) => $this->registry->resolveKey((string) $name))
            ->filter()
            ->unique()
            ->values();

        if ($names->isEmpty()) {
            return collect();
        }

        return Permission::query()
            ->where('guard_name', $guard)
            ->whereIn('name', $names->all())
            ->get()
            ->sortBy(fn (Permission $permission) => $names->search($permission->name))
            ->values();
    }
}
