<?php

namespace App\Services;

use App\Models\User;

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
