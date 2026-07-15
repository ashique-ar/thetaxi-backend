<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Exceptions\UnauthorizedException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
        then: function () {
            // Load driver mobile API routes with /api/driver prefix
            Route::middleware('api')
                ->prefix('api/driver')
                ->group(base_path('routes/api_driver.php'));
            
            // Load public API routes with /api/public prefix
            Route::middleware('api')
                ->prefix('api/public')
                ->group(base_path('routes/api_public.php'));

            // Register broadcast channel authorization routes
            Broadcast::routes(['middleware' => ['auth:sanctum']]);
        }
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Register Spatie Permission middleware
        $middleware->alias([
            'permission' => \App\Http\Middleware\PermissionMiddleware::class,
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            // Driver mobile app middleware
            'device.uuid' => \App\Http\Middleware\DeviceUuidMiddleware::class,
            'ensure.driver' => \App\Http\Middleware\EnsureDriverContext::class,
            // Corporate portal middleware
            'ensure.corporate' => \App\Http\Middleware\EnsureCorporateContext::class,
            'ensure.internal' => \App\Http\Middleware\EnsureInternalContext::class,
            'update.api.session' => \App\Http\Middleware\UpdateApiSessionOnRequest::class,
            'agent.api' => \App\Http\Middleware\AuthenticateAgentApiKey::class,
            'agent.api.access' => \App\Http\Middleware\EnsureAgentApiAccess::class,
        ]);

        $middleware->appendToGroup('web', \App\Http\Middleware\WebsiteSettingsSecurity::class);
        $middleware->appendToGroup('web', \App\Http\Middleware\SeoIndexableMiddleware::class);
        $middleware->appendToGroup('api', \App\Http\Middleware\SentryUserContext::class);

        // Exclude payment callback routes from CSRF verification
        $middleware->validateCsrfTokens(except: [
            '/checkout/webxpay/callback',
            '/checkout/*/callback*',
            '/payment/callback',
            '/webhook/*'
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        \Sentry\Laravel\Integration::handles($exceptions);

        $exceptions->render(function (UnauthorizedException $exception, Request $request) {
            $payload = [
                'message' => $exception->getMessage(),
            ];

            $shouldExposeAccessDiagnostics = config('app.debug')
                || app()->environment(['local', 'development', 'staging', 'testing']);

            if ($shouldExposeAccessDiagnostics) {
                $user = $request->user();

                $payload['debug'] = [
                    'required_permissions' => $exception->getRequiredPermissions(),
                    'required_roles' => $exception->getRequiredRoles(),
                    'checked_guards' => $request->is('api/*') ? ['api', 'web'] : [config('auth.defaults.guard')],
                    'user_id' => $user?->id,
                    'user_roles' => $user ? $user->getRoleNames()->values() : [],
                    'user_permissions' => $user
                        ? $user->getAllPermissions()
                            ->map(fn ($permission) => [
                                'name' => $permission->name,
                                'guard_name' => $permission->guard_name,
                            ])
                            ->values()
                        : [],
                    'route' => optional($request->route())->uri(),
                    'method' => $request->method(),
                ];
            }

            return response()->json($payload, 403);
        });
    })->create();
