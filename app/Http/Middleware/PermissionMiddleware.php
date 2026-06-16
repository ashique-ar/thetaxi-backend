<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Guard;

class PermissionMiddleware
{
    public function handle($request, Closure $next, $permission, $guard = null)
    {
        $guard = $guard ?? ($request->is('api/*') ? 'api' : null);
        $authGuard = Auth::guard($guard);
        $user = $authGuard->user();

        if (! $user && $request->bearerToken() && config('permission.use_passport_client_credentials')) {
            $user = Guard::getPassportClient($guard);
        }

        if (! $user) {
            throw UnauthorizedException::notLoggedIn();
        }

        if (! method_exists($user, 'hasAnyPermission')) {
            throw UnauthorizedException::missingTraitHasRoles($user);
        }

        $permissions = explode('|', $this->parsePermissionsToString($permission));

        $guardsToCheck = collect([$guard])
            ->when($guard === 'api', fn ($guards) => $guards->push('web'))
            ->filter()
            ->unique()
            ->values();

        $hasPermission = collect($permissions)
            ->contains(function ($permission) use ($user, $guardsToCheck) {
                return $guardsToCheck->contains(
                    fn ($guardName) => $user->checkPermissionTo($permission, $guardName)
                );
            });

        if (! $hasPermission) {
            throw UnauthorizedException::forPermissions($permissions);
        }

        return $next($request);
    }

    protected function parsePermissionsToString(array|string|\BackedEnum $permission): string
    {
        if ($permission instanceof \BackedEnum) {
            $permission = $permission->value;
        }

        if (is_array($permission)) {
            return implode('|', array_map(
                fn ($value) => $value instanceof \BackedEnum ? $value->value : $value,
                $permission
            ));
        }

        return (string) $permission;
    }
}
