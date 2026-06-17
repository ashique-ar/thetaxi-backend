<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Sentry\Laravel\Integration;
use Sentry\State\Scope;
use function Sentry\configureScope;

class SentryUserContext
{
    public function handle(Request $request, Closure $next)
    {
        if (app()->bound('sentry') && $request->user()) {
            $user = $request->user();

            configureScope(function (Scope $scope) use ($user, $request): void {
                $scope->setUser([
                    'id'    => $user->id,
                    'email' => $user->email,
                    'name'  => $user->name ?? null,
                ]);

                $contextType = $request->header('X-Active-Context-Type');
                $contextId   = $request->header('X-Active-Context-Id');

                if ($contextType && $contextId) {
                    $scope->setTag('tenant_type', $contextType);
                    $scope->setTag('tenant_id', $contextId);
                } elseif (property_exists($user, 'company_id') && $user->company_id) {
                    $scope->setTag('tenant_id', $user->company_id);
                }
            });
        }

        return $next($request);
    }
}
