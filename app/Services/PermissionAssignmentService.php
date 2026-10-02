<?php

namespace App\Services;

use App\Models\User;
use InvalidArgumentException;
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
        if (! $role->exists || $role->getKey() === null) {
            throw new InvalidArgumentException('Cannot sync permissions for an unsaved role.');
        }

        $permissionNames = $this->normalizePermissionNames($permissionIdentifiers);
        $permissions = $this->permissionsForGuard($permissionNames, $role->guard_name);
        $added = $removed = [];

        DB::transaction(function () use ($role, $permissions, &$added, &$removed) {
            $lockedRole = Role::query()->whereKey($role->getKey())->lockForUpdate()->firstOrFail();
            $previous = DB::table('role_has_permissions as grants')
                ->join('permissions', 'permissions.id', '=', 'grants.permission_id')
                ->where('grants.role_id', $lockedRole->id)
                ->where('permissions.guard_name', $lockedRole->guard_name)
                ->pluck('permissions.name')->all();
            $desired = $permissions->pluck('name')->all();
            $added = array_values(array_diff($desired, $previous));
            $removed = array_values(array_diff($previous, $desired));

            DB::table('role_has_permissions')
                ->where('role_id', $lockedRole->id)
                ->delete();

            $permissions
                ->pluck('id')
                ->unique()
                ->each(fn ($permissionId) => DB::table('role_has_permissions')->insert([
                    'permission_id' => $permissionId,
                    'role_id' => $lockedRole->id,
                ]));

            if ($added || $removed) {
                activity('role-access')->causedBy(request()->user())->performedOn($lockedRole)
                    ->withProperties(['added' => $added, 'removed' => $removed])
                    ->log('role_permissions_synced');
            }
        });

        $clearPermissionCache = fn () => app(PermissionRegistrar::class)->forgetCachedPermissions();
        if (DB::transactionLevel() > 0) {
            DB::afterCommit($clearPermissionCache);
        } else {
            $clearPermissionCache();
        }

        return Permission::query()
            ->join('role_has_permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('role_has_permissions.role_id', $role->id)
            ->where('permissions.guard_name', $role->guard_name)
            ->orderBy('permissions.name')
            ->select('permissions.*')
            ->get();
    }

    public function grantRolePermissions(Role $role, array $permissionIdentifiers): Collection
    {
        $names = $this->normalizePermissionNames($permissionIdentifiers);

        return DB::transaction(function () use ($role, $names) {
            $lockedRole = Role::query()->whereKey($role->getKey())->lockForUpdate()->firstOrFail();
            $current = $lockedRole->permissions()->pluck('name');

            return $this->syncRolePermissions($lockedRole, $current->merge($names)->unique()->values()->all());
        });
    }

    public function revokeRolePermissions(Role $role, array $permissionIdentifiers): Collection
    {
        $names = $this->normalizePermissionNames($permissionIdentifiers);

        return DB::transaction(function () use ($role, $names) {
            $lockedRole = Role::query()->whereKey($role->getKey())->lockForUpdate()->firstOrFail();
            $current = $lockedRole->permissions()->pluck('name');
            $remaining = $current->reject(fn ($name) => in_array($name, $names, true))->values();

            return $this->syncRolePermissions($lockedRole, $remaining->all());
        });
    }

    public function applyTemplate(Role $role, string $templateKey, string $mode = 'merge'): Collection
    {
        $template = collect($this->registry->templates())->firstWhere('key', $templateKey);
        abort_if(!$template, 422, 'Selected permission template is invalid.');

        $templatePermissions = $template['permissions'] ?? [];

        return $mode === 'replace'
            ? $this->syncRolePermissions($role, $templatePermissions)
            : $this->grantRolePermissions($role, $templatePermissions);
    }

    public function syncDirectUserPermissions(User $user, array $permissionIdentifiers): Collection
    {
        $permissions = $this->registry->ensureCanonicalPermissions(
            $this->normalizePermissionNames($permissionIdentifiers)
        );

        $added = $removed = [];
        DB::transaction(function () use ($user, $permissions, &$added, &$removed): void {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $permissionIds = $permissions->pluck('id')->unique()->all();
            $previous = DB::table('user_direct_permission_grants as grants')
                ->join('permissions', 'permissions.id', '=', 'grants.permission_id')
                ->where('grants.user_id', $user->id)->pluck('permissions.name')->all();
            $desired = $permissions->pluck('name')->all();
            $added = array_values(array_diff($desired, $previous));
            $removed = array_values(array_diff($previous, $desired));
            $contextOwnedIds = DB::table('user_context_permission_grants as grants')
                ->join('user_contexts', 'user_contexts.id', '=', 'grants.user_context_id')
                ->where('user_contexts.user_id', $user->id)
                ->where('user_contexts.is_active', true)
                ->pluck('grants.permission_id')->unique();

            $removedDirectIds = DB::table('user_direct_permission_grants')
                ->where('user_id', $user->id)
                ->when($permissionIds->isNotEmpty(), fn ($query) => $query->whereNotIn('permission_id', $permissionIds))
                ->pluck('permission_id')
                ->diff($contextOwnedIds);
            DB::table('model_has_permissions')
                ->where('model_type', User::class)
                ->where('model_id', $user->id)
                ->whereIn('permission_id', $removedDirectIds)
                ->delete();

            foreach ($permissions as $permission) {
                DB::table('model_has_permissions')->updateOrInsert([
                    'permission_id' => $permission->id,
                    'model_type' => User::class,
                    'model_id' => $user->id,
                ]);
            }

            DB::table('user_direct_permission_grants')->where('user_id', $user->id)
                ->when($permissionIds->isNotEmpty(), fn ($query) => $query->whereNotIn('permission_id', $permissionIds))
                ->delete();
            foreach ($permissionIds as $permissionId) {
                DB::table('user_direct_permission_grants')->updateOrInsert(
                    ['user_id' => $user->id, 'permission_id' => $permissionId],
                    ['created_at' => now(), 'updated_at' => now()]
                );
            }

            $this->logDirectPermissionChange($user, 'direct_permission_grants_synced', $added, $removed);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $permissions;
    }

    public function grantDirectUserPermissions(User $user, array $permissionIdentifiers): Collection
    {
        $permissions = $this->registry->ensureCanonicalPermissions(
            $this->normalizePermissionNames($permissionIdentifiers)
        );

        $added = [];
        DB::transaction(function () use ($user, $permissions, &$added): void {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $existing = DB::table('user_direct_permission_grants as grants')
                ->join('permissions', 'permissions.id', '=', 'grants.permission_id')
                ->where('grants.user_id', $user->id)->pluck('permissions.name')->all();
            $added = array_values(array_diff($permissions->pluck('name')->all(), $existing));
            foreach ($permissions as $permission) {
                DB::table('model_has_permissions')->updateOrInsert([
                    'permission_id' => $permission->id,
                    'model_type' => User::class,
                    'model_id' => $user->id,
                ]);
                DB::table('user_direct_permission_grants')->updateOrInsert(
                    ['user_id' => $user->id, 'permission_id' => $permission->id],
                    ['created_at' => now(), 'updated_at' => now()]
                );
            }
            $this->logDirectPermissionChange($user, 'direct_permission_grants_added', $added, []);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        return $permissions;
    }

    public function revokeDirectUserPermissions(User $user, array $permissionIdentifiers): void
    {
        $names = $this->normalizePermissionNames($permissionIdentifiers);
        $removed = [];
        DB::transaction(function () use ($user, $names, &$removed): void {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $permissionIds = Permission::query()->whereIn('name', $names)->pluck('id');
            $removed = DB::table('model_has_permissions as grants')
                ->join('permissions', 'permissions.id', '=', 'grants.permission_id')
                ->where('grants.model_type', User::class)
                ->where('grants.model_id', $user->id)
                ->whereIn('grants.permission_id', $permissionIds)
                ->pluck('permissions.name')->all();
            DB::table('user_direct_permission_grants')->where('user_id', $user->id)
                ->whereIn('permission_id', $permissionIds)->delete();

            $contextOwnedIds = DB::table('user_context_permission_grants as grants')
                ->join('user_contexts', 'user_contexts.id', '=', 'grants.user_context_id')
                ->where('user_contexts.user_id', $user->id)
                ->where('user_contexts.is_active', true)
                ->whereIn('grants.permission_id', $permissionIds)->pluck('grants.permission_id')->unique();
            DB::table('model_has_permissions')
                ->where('model_type', User::class)
                ->where('model_id', $user->id)
                ->whereIn('permission_id', $permissionIds)
                ->when($contextOwnedIds->isNotEmpty(), fn ($query) => $query->whereNotIn('permission_id', $contextOwnedIds))
                ->delete();
            $this->logDirectPermissionChange($user, 'direct_permission_grants_revoked', [], $removed);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function logDirectPermissionChange(User $user, string $event, array $added, array $removed): void
    {
        if (!$added && !$removed) {
            return;
        }

        activity('user-access')->causedBy(request()->user())->performedOn($user)
            ->withProperties(['added' => $added, 'removed' => $removed])
            ->log($event);
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
