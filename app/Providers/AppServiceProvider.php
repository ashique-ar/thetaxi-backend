<?php

namespace App\Providers;

use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payment\WebXPayGateway;
use App\Services\WebXPayService;
use App\Services\WebsiteSettingsService;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;
use Dedoc\Scramble\Scramble;
use Illuminate\Support\Facades\Gate;
use App\Models\Booking\Booking;
use App\Observers\BookingApprovalNotificationObserver;
use App\Models\Booking\BookingItem;
use App\Models\Booking\BookingActivity;
use App\Models\Booking\BookingStatus;
use App\Models\Booking\BookingPaymentReceipt;
use App\Models\Booking\BookingPaymentAdjustment;
use App\Models\Booking\BookingPaymentSchedule;
use App\Models\Booking\BookingPaymentReceiptComponent;
use App\Models\Booking\BookingPaymentScheduleAllocation;
use App\Models\Booking\BookingPaymentScheduleRevision;
use App\Models\Booking\BookingPaymentScheduleRuleEvent;
use App\Models\Booking\BookingPaymentReceiptFinalityEvent;
use App\Models\Booking\BookingCollectionSubmission;
use App\Models\Booking\BookingCollectionWorkItem;
use App\Models\Booking\BookingCollectionReminderDelivery;
use App\Models\Booking\BookingCollectionWorkItemFollowUp;
use App\Models\Booking\BookingPaymentScheduleRule;
use App\Models\Booking\BookingCollectionCommission;
use App\Models\Booking\BookingCommonRatePricing;
use App\Models\Booking\BookingCustomerMobileActivity;
use App\Models\Booking\BookingDiscount;
use App\Models\Booking\BookingCommercialValueAdjustment;
use App\Models\Booking\BookingDepositRefund;
use App\Models\Booking\BookingExtensionRequest;
use App\Models\Booking\BookingPricing;
use App\Models\Booking\BookingQC;
use App\Models\Booking\BookingTerm;
use App\Models\BookingPriceAdjustmentHistory;
use App\Models\Finance\FinancialAdjustment;
use App\Models\Finance\FinancialPaymentAllocation;
use App\Models\Sales\SalesBookingAttribution;
use App\Models\Sales\SalesAttributionException;
use App\Models\Sales\SalesCommissionDecision;
use App\Models\Invoice;
use App\Models\PaymentEvent;
use App\Models\PromoCodeUsage;
use App\Models\DriverAssignmentStop;
use App\Models\IntegrationWebhookEvent;
use App\Models\Driver\DriverSession;
use App\Models\Driver\DriverIouAdvance;
use App\Models\Driver\DriverLog;
use App\Models\Driver\DriverTrackingIncident;
use App\Models\Inquiry;
use App\Models\PhoneCall;
use App\Models\LoyaltyPointTransaction;
use App\Models\Finance\FinancialSettlementItem;
use App\Models\Finance\DriverCashSettlementItem;
use App\Models\Driver\DriverHireSettlement;
use App\Models\Agent\AgentCommission;
use App\Models\Corporate\CorporateTransportParticipation;
use App\Models\Corporate\CorporateTransportGenerationLog;
use App\Models\Sales\SalesActivity;
use App\Models\Sales\SalesOpportunity;
use App\Models\Sales\SalesTask;
use App\Models\Booking\BookingApproval;
use App\Models\Booking\BookingDispatch;
use App\Models\Booking\BookingAddon;
use App\Models\Booking\BookingVariableCustomization;
use App\Models\Corporate\Corporate;
use App\Models\Customer;
use App\Models\Driver\Driver;
use App\Models\DriverAssignment;
use App\Models\Vehicle\Vehicle;
use App\Models\Vehicle\VehicleAssignment;
use App\Models\Vehicle\VehicleMaintenanceSchedule;
use App\Models\Vehicle\VehiclePricing\BookingPriceAdjustmentHistory as VehicleBookingPriceAdjustmentHistory;
use App\Models\Website\PendingPaymentLink;
use App\Models\Finance\FinancialAuditEvent;
use App\Models\Staff;
use App\Observers\DriverObserver;
use App\Observers\BookingPaymentObserver;
use App\Observers\BookingSalesObserver;
use App\Observers\BookingHistoryObserver;
use App\Policies\BookingPolicy;
use App\Policies\CorporatePolicy;
use App\Policies\CustomerPolicy;
use App\Policies\DriverPolicy;
use App\Policies\VehiclePolicy;
use App\Policies\StaffPolicy;
use App\Contracts\Foundation\DomainEventPublisher;
use App\Services\Foundation\DomainOutboxService;

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
        $this->app->singleton(DomainEventPublisher::class, DomainOutboxService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDriverApiDocumentation();

        $pushBookingHistorySource = static function (string $source): void {
            $request = request();
            $stack = $request->attributes->get('booking_history_source_stack', []);
            $stack[] = [
                $request->attributes->has('booking_history_source_trigger'),
                $request->attributes->get('booking_history_source_trigger'),
            ];
            $request->attributes->set('booking_history_source_stack', $stack);
            $request->attributes->set('booking_history_source_trigger', $source);
        };
        $popBookingHistorySource = static function (string $prefix): void {
            $request = request();
            $stack = $request->attributes->get('booking_history_source_stack', []);
            if ($stack === [] || !str_starts_with((string) $request->attributes->get('booking_history_source_trigger', ''), $prefix)) return;
            [$hadSource, $source] = array_pop($stack);
            $request->attributes->set('booking_history_source_stack', $stack);
            $hadSource
                ? $request->attributes->set('booking_history_source_trigger', $source)
                : $request->attributes->remove('booking_history_source_trigger');
        };
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Console\Events\CommandStarting::class,
            fn ($event) => $pushBookingHistorySource('artisan:' . $event->command));
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Console\Events\CommandFinished::class,
            fn () => $popBookingHistorySource('artisan:'));
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Queue\Events\JobProcessing::class,
            fn ($event) => $pushBookingHistorySource('queue:' . (method_exists($event->job, 'resolveName') ? $event->job->resolveName() : get_class($event->job))));
        foreach ([
            \Illuminate\Queue\Events\JobProcessed::class,
            \Illuminate\Queue\Events\JobExceptionOccurred::class,
        ] as $completedJobEvent) {
            \Illuminate\Support\Facades\Event::listen($completedJobEvent, fn () => $popBookingHistorySource('queue:'));
        }

        // Outlook and Gmail drop <style> blocks (always in replies/forwards), so inline the CSS at send time.
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Mail\Events\MessageSending::class, function ($event) {
            $html = $event->message->getHtmlBody();
            if (is_string($html) && str_contains($html, '<style')) {
                $event->message->html((new \TijsVerkoyen\CssToInlineStyles\CssToInlineStyles())->convert($html));
            }
        });

        $whereLikeInsensitive = function (string $column, string $value, string $boolean = 'and') {
            $query = $this instanceof EloquentBuilder ? $this->getQuery() : $this;
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
        };

        $orWhereLikeInsensitive = function (string $column, string $value) {
            return $this->whereLikeInsensitive($column, $value, 'or');
        };

        foreach ([EloquentBuilder::class, QueryBuilder::class] as $builder) {
            $builder::macro('whereLikeInsensitive', $whereLikeInsensitive);
            $builder::macro('orWhereLikeInsensitive', $orWhereLikeInsensitive);
        }

        // Register model observers
        Driver::observe(DriverObserver::class);
        Booking::observe(BookingPaymentObserver::class);
        Booking::observe(BookingSalesObserver::class);
        Booking::observe(BookingHistoryObserver::class);
        Booking::observe(BookingApprovalNotificationObserver::class);
        BookingItem::observe(BookingHistoryObserver::class);
        BookingActivity::observe(BookingHistoryObserver::class);
        BookingStatus::observe(BookingHistoryObserver::class);
        BookingPaymentReceipt::observe(BookingHistoryObserver::class);
        BookingPaymentAdjustment::observe(BookingHistoryObserver::class);
        BookingPaymentSchedule::observe(BookingHistoryObserver::class);
        BookingPaymentReceiptComponent::observe(BookingHistoryObserver::class);
        BookingPaymentScheduleAllocation::observe(BookingHistoryObserver::class);
        BookingPaymentScheduleRevision::observe(BookingHistoryObserver::class);
        BookingPaymentScheduleRuleEvent::observe(BookingHistoryObserver::class);
        BookingPaymentReceiptFinalityEvent::observe(BookingHistoryObserver::class);
        BookingCollectionSubmission::observe(BookingHistoryObserver::class);
        BookingCollectionWorkItem::observe(BookingHistoryObserver::class);
        BookingCollectionWorkItemFollowUp::observe(BookingHistoryObserver::class);
        BookingCollectionReminderDelivery::observe(BookingHistoryObserver::class);
        BookingPaymentScheduleRule::observe(BookingHistoryObserver::class);
        BookingCollectionCommission::observe(BookingHistoryObserver::class);
        BookingCommonRatePricing::observe(BookingHistoryObserver::class);
        BookingCustomerMobileActivity::observe(BookingHistoryObserver::class);
        BookingDiscount::observe(BookingHistoryObserver::class);
        BookingCommercialValueAdjustment::observe(BookingHistoryObserver::class);
        BookingDepositRefund::observe(BookingHistoryObserver::class);
        BookingExtensionRequest::observe(BookingHistoryObserver::class);
        BookingPricing::observe(BookingHistoryObserver::class);
        BookingQC::observe(BookingHistoryObserver::class);
        BookingTerm::observe(BookingHistoryObserver::class);
        BookingPriceAdjustmentHistory::observe(BookingHistoryObserver::class);
        VehicleBookingPriceAdjustmentHistory::observe(BookingHistoryObserver::class);
        FinancialAdjustment::observe(BookingHistoryObserver::class);
        FinancialPaymentAllocation::observe(BookingHistoryObserver::class);
        SalesBookingAttribution::observe(BookingHistoryObserver::class);
        SalesAttributionException::observe(BookingHistoryObserver::class);
        SalesCommissionDecision::observe(BookingHistoryObserver::class);
        Invoice::observe(BookingHistoryObserver::class);
        PaymentEvent::observe(BookingHistoryObserver::class);
        PromoCodeUsage::observe(BookingHistoryObserver::class);
        DriverAssignmentStop::observe(BookingHistoryObserver::class);
        \App\Models\WaitingTimeRecord::observe(BookingHistoryObserver::class);
        IntegrationWebhookEvent::observe(BookingHistoryObserver::class);
        DriverSession::observe(BookingHistoryObserver::class);
        DriverIouAdvance::observe(BookingHistoryObserver::class);
        DriverLog::observe(BookingHistoryObserver::class);
        DriverTrackingIncident::observe(BookingHistoryObserver::class);
        Inquiry::observe(BookingHistoryObserver::class);
        PhoneCall::observe(BookingHistoryObserver::class);
        LoyaltyPointTransaction::observe(BookingHistoryObserver::class);
        FinancialSettlementItem::observe(BookingHistoryObserver::class);
        DriverCashSettlementItem::observe(BookingHistoryObserver::class);
        DriverHireSettlement::observe(BookingHistoryObserver::class);
        AgentCommission::observe(BookingHistoryObserver::class);
        CorporateTransportParticipation::observe(BookingHistoryObserver::class);
        CorporateTransportGenerationLog::observe(BookingHistoryObserver::class);
        SalesActivity::observe(BookingHistoryObserver::class);
        SalesOpportunity::observe(BookingHistoryObserver::class);
        SalesTask::observe(BookingHistoryObserver::class);
        BookingApproval::observe(BookingHistoryObserver::class);
        BookingApproval::observe(BookingApprovalNotificationObserver::class);
        BookingDispatch::observe(BookingHistoryObserver::class);
        BookingAddon::observe(BookingHistoryObserver::class);
        BookingVariableCustomization::observe(BookingHistoryObserver::class);
        VehicleAssignment::observe(BookingHistoryObserver::class);
        Vehicle::observe(BookingHistoryObserver::class);
        VehicleMaintenanceSchedule::observe(BookingHistoryObserver::class);
        \App\Models\Vehicle\VehicleMaintenanceRecord::observe(BookingHistoryObserver::class);
        PendingPaymentLink::observe(BookingHistoryObserver::class);
        DriverAssignment::observe(BookingHistoryObserver::class);
        FinancialAuditEvent::observe(BookingHistoryObserver::class);

        // Register policies
        Gate::policy(Booking::class, BookingPolicy::class);
        Gate::policy(Corporate::class, CorporatePolicy::class);
        Gate::policy(Customer::class, CustomerPolicy::class);
        Gate::policy(Driver::class, DriverPolicy::class);
        Gate::policy(Vehicle::class, VehiclePolicy::class);
        Gate::policy(Staff::class, StaffPolicy::class);

        // Load broadcast channel authorization routes
        require base_path('routes/channels.php');

        // Configure Passport
        Passport::loadKeysFrom(storage_path('oauth-keys'));
        Passport::viewPrefix('auth.oauth');
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
            if (is_string($ability)
                && \App\Services\PermissionRegistry::isAdminOnlyPermissionName($ability)
                && ! app(\App\Services\PermissionEvaluator::class)->hasInternalRole($user, 'super-admin')) {
                return false;
            }

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
        // Emails follow the active website theme and the brand colour from settings (validated: it is injected into CSS).
        view()->composer('emails.*', function ($view) {
            $settings = app(\App\Services\WebsiteSettingsService::class);
            $color = collect([$settings->get('primary_color'), $settings->get('brand_color_primary')])
                ->first(fn ($c) => is_string($c) && preg_match('/^#[0-9a-f]{3}([0-9a-f]{3})?$/i', trim($c)));
            $view->with(['brandColor' => $color ? trim($color) : '#BF2629', 'emailTheme' => get_active_theme()]);
        });

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
        RateLimiter::for('driver-location-live', fn (Request $request) => Limit::perMinute(60)
            ->by('driver-location-live:' . ($request->user()?->getAuthIdentifier() ?: $request->ip())));
        RateLimiter::for('driver-location-bulk', fn (Request $request) => Limit::perMinute(60)
            ->by('driver-location-bulk:' . ($request->user()?->getAuthIdentifier() ?: $request->ip())));
        RateLimiter::for('driver-location-incidents', fn (Request $request) => Limit::perMinute(30)
            ->by('driver-location-incidents:' . ($request->user()?->getAuthIdentifier() ?: $request->ip())));
        RateLimiter::for('driver-diagnostic-reports', fn (Request $request) => Limit::perMinutes(60, 30)
            ->by('driver-diagnostic-reports:' . ($request->user()?->getAuthIdentifier() ?: $request->ip())));
        RateLimiter::for('driver-hire-recovery', fn (Request $request) => Limit::perMinute(60)
            ->by('driver-hire-recovery:' . ($request->user()?->getAuthIdentifier() ?: $request->ip())));

        Relation::morphMap([
            'driver' => \App\Models\Driver\Driver::class,
            'vehicle' => \App\Models\Vehicle\Vehicle::class,
            'customer' => \App\Models\Customer::class,
            'vehicle_owner' => \App\Models\Vehicle\VehicleOwner::class,
            'staff' => \App\Models\Staff::class,
            'medical_record' => \App\Models\MedicalRecord::class,
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
                'version' => config('app.version') ?: '1.0.0',
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
