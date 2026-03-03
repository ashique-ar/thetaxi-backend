<?php

namespace App\Providers;

use App\Services\WebsiteSettingsService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;
use Illuminate\Support\Facades\Gate;
use App\Models\Corporate\Corporate;
use App\Models\Driver\Driver;
use App\Observers\DriverObserver;
use App\Policies\CorporatePolicy;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register currency helper functions
        require_once app_path('Helpers/Helper.php');
        require_once app_path('Helpers/CurrencyHelpers.php');
        require_once app_path('Helpers/theme_helpers.php');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register model observers
        Driver::observe(DriverObserver::class);

        // Register policies
        Gate::policy(Corporate::class, CorporatePolicy::class);

        // Load broadcast channel authorization routes
        require base_path('routes/channels.php');

        // Configure Passport
        Passport::loadKeysFrom(storage_path('oauth-keys'));
        Passport::tokensExpireIn(now()->addHours(24));
        Passport::refreshTokensExpireIn(now()->addDays(30));
        Passport::personalAccessTokensExpireIn(now()->addMonths(6));
        
        // Define Passport scopes
        Passport::tokensCan([
            'admin' => 'Access admin portal',
            'driver' => 'Access driver mobile app',
            'customer' => 'Access customer features',
            'agent' => 'Access agent features',
        ]);
        
        // Set default scope
        Passport::setDefaultScope([
            'admin',
        ]);

        // Define gates for API authentication
        Gate::define('api-access', function ($user) {
            return $user->isActive();
        });

        // Backwards compatibility: accept legacy hyphen-style abilities (e.g. "update-roles")
        // by mapping them to dot-style permissions (e.g. "roles.update"). This prevents
        // "This action is unauthorized." errors where older code checks use the
        // hyphen naming convention while permissions are stored as resource.operation.
        Gate::before(function ($user, $ability) {
            if (!is_string($ability)) {
                return null;
            }

            // If ability already contains a dot, leave it alone
            if (str_contains($ability, '.')) {
                return null;
            }

            // If ability is hyphenated, try to map verb-resource => resource.verb
            if (str_contains($ability, '-')) {
                [$verb, $resource] = explode('-', $ability, 2);

                if ($verb && $resource) {
                    $mapped = "{$resource}.{$verb}";

                    if ($user->can($mapped)) {
                        return true;
                    }
                }
            }

            return null;
        });

        // Register currency view composer for all views
        view()->composer('*', \App\Http\ViewComposers\CurrencyViewComposer::class);

        // Register settings view composer for all views
        view()->composer('*', \App\Http\ViewComposers\SettingsViewComposer::class);

        // Register services view composer for all views (Updated: 2026-02-14)
        view()->composer('*', \App\Http\ViewComposers\ServicesViewComposer::class);

        RateLimiter::for('api', function (Request $request) {
            $settingsService = app(WebsiteSettingsService::class);
            $settings = $settingsService->getSecuritySettings();
            $enabled = $this->normalizeSettingBoolean($settings['rate_limiting_enabled'] ?? null, true);
            $limit = (int) ($settings['rate_limit_per_minute'] ?? 60);

            if (!$enabled || $limit <= 0) {
                return Limit::none();
            }

            return Limit::perMinute($limit)->by($request->user()?->id ?: $request->ip());
        });

        Relation::morphMap([
            'driver' => \App\Models\Driver\Driver::class,
            'customer' => \App\Models\Customer::class,
            'vehicle_owner' => \App\Models\Vehicle\VehicleOwner::class,
            'staff' => \App\Models\Staff::class,
        ]);

        // Register custom route middleware aliases (fix for missing Kernel routeMiddleware registration)
        try {
            if ($this->app->bound(\Illuminate\Routing\Router::class)) {
                $router = $this->app->make(\Illuminate\Routing\Router::class);
                $router->aliasMiddleware('update.api.session', \App\Http\Middleware\UpdateApiSessionOnRequest::class);
            }
        } catch (\Throwable $e) {
            // Non-fatal: ensure app still boots even if alias can't be registered
            \Log::warning('Failed to register middleware alias update.api.session: ' . $e->getMessage());
        }
    }

    /**
     * Normalize boolean settings values.
     */
    protected function normalizeSettingBoolean($value, bool $default = false): bool
    {
        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            return $default;
        }

        return in_array($normalized, ['1', 'true', 'yes', 'on', 'enabled'], true);
    }
}
