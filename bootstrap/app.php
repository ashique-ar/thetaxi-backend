<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

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
        }
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Register Spatie Permission middleware
        $middleware->alias([
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            // Driver mobile app middleware
            'device.uuid' => \App\Http\Middleware\DeviceUuidMiddleware::class,
            'ensure.driver' => \App\Http\Middleware\EnsureDriverContext::class,
        ]);

        $middleware->appendToGroup('web', \App\Http\Middleware\WebsiteSettingsSecurity::class);

        // Exclude payment callback routes from CSRF verification
        $middleware->validateCsrfTokens(except: [
            '/checkout/webxpay/callback',
            '/checkout/*/callback*',
            '/payment/callback',
            '/webhook/*'
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
