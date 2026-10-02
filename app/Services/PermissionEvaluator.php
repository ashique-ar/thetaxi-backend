<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

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
        if ($contextType === 'internal') {
            return $this->userHasAnyForInternalContext($user, $permissions);
        }

        // Corporate permissions belong to the selected employee context. A user
        // may be an admin in one company and an ordinary employee in another.
        if ($contextType !== 'corporate' && $this->userHasAny($user, $permissions)) {
            return true;
        }

        $contextQuery = $user->contexts()
            ->where('is_active', true)
            ->with('roles.permissions');

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
        if (! $this->hasActiveStaffIdentity($user)) {
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
        if (! $this->hasActiveStaffIdentity($user)) {
            return collect();
        }

        $staffContexts = $user->contexts()
            ->where('context_type', 'staff')
            ->where('is_active', true)
            ->with('roles.permissions')
            ->whereIn('context_id', function ($staff) use ($user) {
                $staff->select('id')
                    ->from('staff')
                    ->where('user_id', $user->id)
                    ->whereNull('deleted_at')
                    ->where(fn ($employment) => $employment
                        ->whereNull('employment_ended_at')
                        ->orWhere('employment_ended_at', '>', now()));
            })
            ->get();
        $externalContexts = $user->contexts()
            ->where('context_type', '!=', 'staff')
            ->with('roles.permissions')
            ->get();
        $staffRoleIds = $staffContexts
            ->flatMap(fn (UserContext $context) => $context->roles->pluck('id'))
            ->unique();
        $externalRoleIds = $externalContexts
            ->flatMap(fn (UserContext $context) => $context->roles->pluck('id'))
            ->unique();

        return $user->roles()
            ->with('permissions')
            ->get()
            ->filter(fn ($role) => $staffRoleIds->contains($role->id) || ! $externalRoleIds->contains($role->id))
            ->merge($staffContexts->flatMap(fn (UserContext $context) => $context->roles))
            ->unique('id')
            ->values();
    }

    public function permissionsForInternalContext(User $user): Collection
    {
        return $this->rolesForInternalContext($user)
            ->flatMap(fn ($role) => $role->permissions)
            ->merge($user->getDirectPermissions())
            ->unique('name')
            ->values();
    }

    private function hasActiveStaffIdentity(User $user): bool
    {
        return $user->contexts()
            ->where('context_type', 'staff')
            ->where('is_active', true)
            ->whereIn('context_id', function ($staff) use ($user) {
                $staff->select('id')
                    ->from('staff')
                    ->where('user_id', $user->id)
                    ->whereNull('deleted_at')
                    ->where(fn ($employment) => $employment
                        ->whereNull('employment_ended_at')
                        ->orWhere('employment_ended_at', '>', now()));
            })
            ->exists();
    }

    public function userHas(User $user, string $permission): bool
    {
        $permission = $this->registry->resolveKey($permission);
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
}
