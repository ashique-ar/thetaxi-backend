<?php

namespace App\Providers;

use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payment\WebXPayGateway;
use App\Services\WebXPayService;
use App\Services\WebsiteSettingsService;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;
use Dedoc\Scramble\Scramble;
use Illuminate\Support\Facades\Gate;
use App\Models\Booking\Booking;
use App\Models\Corporate\Corporate;
use App\Models\Customer;
use App\Models\Driver\Driver;
use App\Models\Vehicle\Vehicle;
use App\Observers\DriverObserver;
use App\Observers\BookingPaymentObserver;
use App\Policies\BookingPolicy;
use App\Policies\CorporatePolicy;
use App\Policies\CustomerPolicy;
use App\Policies\DriverPolicy;
use App\Policies\VehiclePolicy;

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

        // Payment gateway bindings
        $this->app->singleton(WebXPayGateway::class, fn ($app) => new WebXPayGateway($app->make(WebXPayService::class)));
        $this->app->singleton(PaymentGatewayManager::class, fn ($app) => new PaymentGatewayManager($app));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDriverApiDocumentation();

        EloquentBuilder::macro('whereLikeInsensitive', function (string $column, string $value, string $boolean = 'and') {
            $query = $this->getQuery();
            $driver = $query->getConnection()->getDriverName();
            $wrappedColumn = $query->getGrammar()->wrap($column);
            $searchValue = '%' . $value . '%';

            if ($driver === 'pgsql') {
                return $this->whereRaw("CAST({$wrappedColumn} AS TEXT) ILIKE ?", [$searchValue], $boolean);
            }

            $castType = match ($driver) {
                'sqlsrv' => 'NVARCHAR(MAX)',
                'mysql', 'mariadb' => 'CHAR',
                default => 'TEXT',
            };

            return $this->whereRaw(
                "LOWER(CAST({$wrappedColumn} AS {$castType})) LIKE ?",
                [mb_strtolower($searchValue)],
                $boolean
            );
        });

        EloquentBuilder::macro('orWhereLikeInsensitive', function (string $column, string $value) {
            return $this->whereLikeInsensitive($column, $value, 'or');
        });

        // Register model observers
        Driver::observe(DriverObserver::class);
        Booking::observe(BookingPaymentObserver::class);

        // Register policies
        Gate::policy(Booking::class, BookingPolicy::class);
        Gate::policy(Corporate::class, CorporatePolicy::class);
        Gate::policy(Customer::class, CustomerPolicy::class);
        Gate::policy(Driver::class, DriverPolicy::class);
        Gate::policy(Vehicle::class, VehiclePolicy::class);

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

        view()->composer('partials.header', \App\Http\ViewComposers\NavigationViewComposer::class);
        view()->composer('partials.themes.*.header', \App\Http\ViewComposers\NavigationViewComposer::class);

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
        RateLimiter::for('sms-send', function (Request $request) {
            $actor = $request->user()?->id ?: $request->ip();
            return $request->filled('recipient')
                ? Limit::perMinute(10)->by('single:' . $actor)
                : Limit::perMinute(2)->by('bulk:' . $actor);
        });
        RateLimiter::for('sms-test', fn (Request $request) => Limit::perMinute(3)
            ->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('sms-campaign', fn (Request $request) => Limit::perMinute(2)
            ->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('sms-preview', fn (Request $request) => Limit::perMinute(30)
            ->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('sms-retry', fn (Request $request) => Limit::perMinute(5)
            ->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('sms-webhook', fn (Request $request) => Limit::perMinute(120)
            ->by($request->ip()));

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

        // Restrict Scramble API docs to non-production environments
        if (
            class_exists(\Dedoc\Scramble\Scramble::class)
            && method_exists(\Dedoc\Scramble\Scramble::class, 'shouldGenerateDocs')
        ) {
            \Dedoc\Scramble\Scramble::shouldGenerateDocs(fn () => !app()->isProduction());
        }
    }

    /**
     * Register a focused OpenAPI document for the driver mobile application.
     *
     * Keeping this separate from the very large internal API makes the driver
     * contract quick to generate and ensures newly added driver routes are not
     * lost among unrelated portal endpoints.
     */
    private function configureDriverApiDocumentation(): void
    {
        if (! class_exists(Scramble::class)) {
            return;
        }

        Scramble::registerApi('driver', [
            'api_path' => 'api/driver',
            'info' => [
                'title' => 'TheTaxi Driver Mobile API',
                'version' => env('APP_VERSION', '1.0.0'),
                'description' => 'Authentication, onboarding, profile, availability, tracking, devices, notifications, assignments, trips, and earnings for the driver mobile application.',
            ],
            'ui' => ['title' => 'TheTaxi Driver Mobile API'],
            'middleware' => [
                'web',
                \App\Http\Middleware\DriverApiDocsAccess::class,
            ],
        ])
            ->withDocumentTransformers(function (\Dedoc\Scramble\Support\Generator\OpenApi $openApi): void {
                $openApi->info->title = 'TheTaxi Driver Mobile API';

                $scheme = \Dedoc\Scramble\Support\Generator\SecurityScheme::http('bearer', 'Passport access token or onboarding token')
                    ->as('driverBearer')
                    ->setDescription('Use the Passport access token after approval. During onboarding, use the onboarding_token returned by OTP verification; X-Onboarding-Token is also accepted by the API.');
                $openApi->components->addSecurityScheme('driverBearer', $scheme);

                $publicOperations = [
                    'GET version-check',
                    'POST version-check',
                    'POST auth/login',
                    'POST auth/request-otp',
                    'POST auth/verify-otp',
                    'POST auth/forgot-password',
                    'POST auth/reset-password',
                    'GET onboarding/countries',
                    'GET onboarding/countries/{country}/states',
                    'GET onboarding/makes',
                    'GET onboarding/makes/{make}/models',
                ];

                foreach ($openApi->paths as $path) {
                    foreach ($path->operations as $operation) {
                        $key = strtoupper($operation->method).' '.ltrim($path->path, '/');
                        if (! in_array($key, $publicOperations, true)) {
                            $operation->addSecurity(new \Dedoc\Scramble\Support\Generator\SecurityRequirement('driverBearer'));
                        }
                    }
                }
            })
            ->expose(
                ui: 'docs/driver',
                document: 'docs/driver/openapi.json',
            );
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
