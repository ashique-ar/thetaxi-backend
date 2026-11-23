<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

class PermissionEvaluator
{
    public function __construct(private PermissionRegistry $registry)
    {
    }

    public function userHasAny(User $user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->userHas($user, (string) $permission)) {
                return true;
            }
        }

        return false;
    }

    public function userHasAnyForRequest(User $user, array $permissions, ?string $contextType, ?string $contextId): bool
    {
        $permissions = array_values(array_filter($permissions, fn ($permission) =>
            $this->mayUseProtectedPermission($user, (string) $permission)
        ));
        if ($permissions === []) {
            return false;
        }

        if ($contextType === 'internal') {
            return $this->userHasAnyForInternalContext($user, $permissions);
        }

        if ($contextType === 'staff' && ! $this->hasActiveStaffIdentity($user, $contextId)) {
            return false;
        }

        // Corporate permissions belong to the selected employee context. A user
        // may be an admin in one company and an ordinary employee in another.
        if (! in_array($contextType, ['corporate', 'staff'], true) && $this->userHasAny($user, $permissions)) {
            return true;
        }

        $contextQuery = $user->contexts()
            ->where('is_active', true)
            ->with(['roles' => fn ($roles) => $roles
                ->where('guard_name', $this->registry->canonicalGuard())
                ->with(['permissions' => fn ($permissions) => $permissions
                    ->where('guard_name', $this->registry->canonicalGuard())])]);

        if ($contextId && Str::isUuid($contextId)) {
            $contextQuery->where(function ($query) use ($contextId) {
                $query->where('id', $contextId)
                    ->orWhere('context_id', $contextId);
            });
        }

        if ($contextType) {
            $contextQuery->where(function ($query) use ($contextType) {
                $query->where('context_type', $contextType);

                if ($contextType === 'corporate') {
                    $query->orWhere('context_type', 'corporate');
                }
            });
        }

        /** @var UserContext|null $context */
        $context = $contextQuery->first();

        if (! $context) {
            return false;
        }

        $contextPermissions = $context->roles
            ->flatMap(fn ($role) => $role->permissions->pluck('name'))
            ->merge(Permission::query()
                ->join('user_context_permission_grants as grants', 'permissions.id', '=', 'grants.permission_id')
                ->where('grants.user_context_id', $context->id)
                ->where('permissions.guard_name', $this->registry->canonicalGuard())
                ->pluck('permissions.name'))
            ->map(fn ($permission) => $this->registry->resolveKey((string) $permission))
            ->unique()
            ->values()
            ->all();

        foreach ($permissions as $permission) {
            if (in_array($this->registry->resolveKey((string) $permission), $contextPermissions, true)) {
                return true;
            }
        }

        return false;
    }

    public function userHasAnyForInternalContext(User $user, array $permissions): bool
    {
        $permissions = array_values(array_filter($permissions, fn ($permission) =>
            $this->mayUseProtectedPermission($user, (string) $permission)
        ));
        if ($permissions === []) {
            return false;
        }

        $internalPermissions = $this->permissionsForInternalContext($user)
            ->pluck('name')
            ->map(fn ($permission) => $this->registry->resolveKey((string) $permission))
            ->unique();

        foreach ($permissions as $permission) {
            if ($internalPermissions->contains($this->registry->resolveKey((string) $permission))) {
                return true;
            }
        }

        return false;
    }

    public function rolesForInternalContext(User $user): Collection
    {
        $userRoles = $user->roles()
            ->where('guard_name', $this->registry->canonicalGuard())
            ->with(['permissions' => fn ($permissions) => $permissions
                ->where('guard_name', $this->registry->canonicalGuard())])
            ->get();
        $contextRoleIds = DB::table('user_context_roles')
            ->join('user_contexts', 'user_contexts.id', '=', 'user_context_roles.user_context_id')
            ->where('user_contexts.user_id', $user->id)
            ->pluck('user_context_roles.role_id');
        $directRoleIds = DB::table('user_direct_role_grants')
            ->where('user_id', $user->id)
            ->pluck('role_id');

        return $userRoles->filter(function ($role) use ($directRoleIds, $contextRoleIds) {
            $contextTypes = $role->context_types;
            if (is_string($contextTypes)) {
                $contextTypes = json_decode($contextTypes, true);
            }

            return $directRoleIds->contains($role->id)
                || ! $contextRoleIds->contains($role->id)
                || (is_array($contextTypes) && in_array('internal', $contextTypes, true));
        })
            ->unique('id')
            ->values();
    }

    public function permissionsForInternalContext(User $user): Collection
    {
        $canUseProtectedPermissions = $this->isMasterAdmin($user);

        $contextPermissionIds = DB::table('user_context_permission_grants as grants')
            ->join('user_contexts', 'user_contexts.id', '=', 'grants.user_context_id')
            ->where('user_contexts.user_id', $user->id)
            ->pluck('grants.permission_id')
            ->unique();
        $directPermissionIds = DB::table('user_direct_permission_grants')
            ->where('user_id', $user->id)
            ->pluck('permission_id');
        $directPermissions = $user->getDirectPermissions()
            ->where('guard_name', $this->registry->canonicalGuard())
            ->reject(fn (Permission $permission) => $contextPermissionIds->contains($permission->id)
                && ! $directPermissionIds->contains($permission->id));

        return $this->rolesForInternalContext($user)
            ->flatMap(fn ($role) => $role->permissions)
            ->merge($directPermissions)
            ->reject(fn ($permission) => ! $canUseProtectedPermissions
                && PermissionRegistry::isAdminOnlyPermissionName($this->registry->resolveKey((string) $permission->name)))
            ->unique('name')
            ->values();
    }

    private function hasActiveStaffIdentity(User $user, ?string $contextId = null): bool
    {
        if ($user->roles()->whereIn('name', ['admin', 'super-admin', 'staff'])
            ->where('guard_name', $this->registry->canonicalGuard())->exists()) {
            return true;
        }

        $contexts = $user->contexts()
            ->where('context_type', 'staff')
            ->where('is_active', true);
        $allContexts = clone $contexts;
        $hasSelectedContext = $contextId && Str::isUuid($contextId);

        if ($hasSelectedContext) {
            $contexts->where(function ($query) use ($contextId) {
                $query->where('id', $contextId)->orWhere('context_id', $contextId);
            });
        }

        $activeStaff = function ($staff) use ($user) {
            $staff->select('id')
                ->from('staff')
                ->where('user_id', $user->id)
                ->whereNull('deleted_at')
                ->where(fn ($employment) => $employment
                    ->whereNull('employment_ended_at')
                    ->orWhere('employment_ended_at', '>', now()));
        };

        if (!(clone $contexts)->whereIn('context_id', $activeStaff)->exists()) {
            return false;
        }

        return $hasSelectedContext || !(clone $allContexts)->whereNotIn('context_id', $activeStaff)->exists();
    }

    public function userHas(User $user, string $permission): bool
    {
        $permission = $this->registry->resolveKey($permission);
        if (! $this->mayUseProtectedPermission($user, $permission)) {
            return false;
        }

        $guards = array_values(array_unique(array_merge(
            [$this->registry->canonicalGuard()],
            $this->registry->legacyGuards()
        )));

        foreach ($guards as $guard) {
            if ($user->checkPermissionTo($permission, $guard)) {
                return true;
            }
        }

        return false;
    }

    private function mayUseProtectedPermission(User $user, string $permission): bool
    {
        return ! PermissionRegistry::isAdminOnlyPermissionName($this->registry->resolveKey($permission))
            || $this->isMasterAdmin($user);
    }

    public function hasInternalRole(User $user, string $roleName): bool
    {
        if ($user->roles()
            ->where('name', $roleName)
            ->where('guard_name', $this->registry->canonicalGuard())
            ->exists()) {
            return true;
        }

        return $user->contexts()
            ->where('context_type', 'staff')
            ->where('is_active', true)
            ->whereIn('context_id', function ($staff) use ($user): void {
                $staff->select('id')
                    ->from('staff')
                    ->where('user_id', $user->id)
                    ->whereNull('deleted_at')
                    ->where(fn ($employment) => $employment
                        ->whereNull('employment_ended_at')
                        ->orWhere('employment_ended_at', '>', now()));
            })
            ->whereHas('roles', fn ($roles) => $roles
                ->where('name', $roleName)
                ->where('guard_name', $this->registry->canonicalGuard()))
            ->exists();
    }

    private function isMasterAdmin(User $user): bool
    {
        return $this->hasInternalRole($user, 'super-admin');
    }
}
