<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserContext;

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
        if ($this->userHasAny($user, $permissions)) {
            return true;
        }

        $contextQuery = $user->contexts()
            ->where('is_active', true)
            ->with('roles.permissions');

        if ($contextId) {
            $contextQuery->where('id', $contextId);
        }

        if ($contextType) {
            $contextQuery->where('context_type', $contextType);
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
