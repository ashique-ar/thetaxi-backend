<?php

use App\Http\Controllers\Api\Booking\BookingFlowController;
use App\Http\Controllers\Api\Booking\BookingLifecycleController;
use App\Http\Controllers\Api\AssignmentController;
use App\Http\Controllers\Api\AgreementController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\GooglePlacesController;
use App\Http\Controllers\Api\MedicalRecordController;
use App\Http\Controllers\Api\ReportsController;
use App\Http\Controllers\Api\SystemController;
use App\Http\Controllers\Api\SystemBackupController;
use App\Http\Controllers\Api\UtilityController;
use App\Http\Controllers\Api\UserContextController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Agent\AgentController;
use App\Http\Controllers\Api\Agent\AgentApiController;
use App\Http\Controllers\Api\Agent\AgentOperationalController;
use App\Http\Controllers\Api\Agent\AgentApiSessionController;
use App\Http\Controllers\Api\Agent\AgentCommissionController;
use App\Http\Controllers\Api\Booking\BookingChannelController;
use App\Http\Controllers\Api\Booking\BookingStatusController;
use App\Http\Controllers\Api\BusinessSettingController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\Company\RegionController;
use App\Http\Controllers\Api\CountryController;
use App\Http\Controllers\Api\CurrencyController;
use App\Http\Controllers\Api\Driver\DriverController;
use App\Http\Controllers\Api\Driver\DriverBattaRuleController;
use App\Http\Controllers\Api\Driver\DriverHireSettlementController;
use App\Http\Controllers\Api\Driver\DriverLogController;
use App\Http\Controllers\Api\DrivingLicenseController;
use App\Http\Controllers\Api\DrivingLicenseTypeController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\ImageGalleryController;
use App\Http\Controllers\Api\InquiryController;
use App\Http\Controllers\Api\InquiryFormController;
use App\Http\Controllers\Api\InquiryServicePageController;
use App\Http\Controllers\Api\NotificationLogController;
use App\Http\Controllers\Api\NotificationTemplateController;
use App\Http\Controllers\Api\PublicInquiryServiceController;
use App\Http\Controllers\Api\PhoneCallController;
use App\Http\Controllers\Api\PaymentMethodController;
use App\Http\Controllers\Api\ServiceTypeController;
use App\Http\Controllers\Api\Service\ServicePackageController;
use App\Http\Controllers\Api\Service\ServiceFormConfigController;
use App\Http\Controllers\Api\Sms\SmsManagementController;
use App\Http\Controllers\Api\AirportController;
use App\Http\Controllers\Api\StateController;
use App\Http\Controllers\Api\Vehicle\VehicleAddonController;
use App\Http\Controllers\Api\Vehicle\VehicleCategoryController;
use App\Http\Controllers\Api\Vehicle\VehicleClassController;
use App\Http\Controllers\Api\Vehicle\VehicleContractTypeController;
use App\Http\Controllers\Api\Vehicle\VehicleCommissionController;
use App\Http\Controllers\Api\Vehicle\VehicleController;
use App\Http\Controllers\Api\Vehicle\VehicleDistanceMultiplierController;
use App\Http\Controllers\Api\Vehicle\VehicleFuelTypeController;
use App\Http\Controllers\Api\Vehicle\VehicleGradeController;
use App\Http\Controllers\Api\Vehicle\VehicleGroupController;
use App\Http\Controllers\Api\Vehicle\VehicleImageController;
use App\Http\Controllers\Api\Vehicle\VehicleInsuranceController;
use App\Http\Controllers\Api\Vehicle\VehicleInsuranceClaimController;
use App\Http\Controllers\Api\Vehicle\VehicleInsuranceProviderController;
use App\Http\Controllers\Api\Vehicle\VehicleInsuranceTypeController;
use App\Http\Controllers\Api\Vehicle\VehicleMaintenanceRecordController;
use App\Http\Controllers\Api\Vehicle\VehicleMaintenanceScheduleController;
use App\Http\Controllers\Api\Vehicle\VehicleMakeController;
use App\Http\Controllers\Api\Vehicle\VehicleModelController;
use App\Http\Controllers\Api\Vehicle\VehicleOwnerController;
use App\Http\Controllers\Api\Vehicle\VehicleOwnerTypeController;
use App\Http\Controllers\Api\Vehicle\VehicleRevenueLicenseController;

use App\Http\Controllers\Api\Vehicle\VehicleTransmissionController;
use App\Http\Controllers\Api\Vehicle\VehicleDiscountController;
use App\Http\Controllers\Api\Vehicle\VehiclePricing\VehiclePricingSlabDefinitionController;
use App\Http\Controllers\Api\Vehicle\VehiclePricing\VehicleGroupPricingController;
use App\Http\Controllers\Api\Vehicle\VehiclePricing\VehiclePricingCommonRateDefinitionController;
use App\Http\Controllers\Api\Vehicle\VehiclePricing\VehiclePricingCalculationDefinitionController;
use App\Http\Controllers\Api\Vehicle\VehiclePricing\KmRangePricingController;
use App\Http\Controllers\Api\Vehicle\VehiclePricing\PriceAdjustmentController;

use App\Http\Controllers\Api\VipTypeController;
use App\Http\Controllers\Api\Website\CmsContentController;
use App\Http\Controllers\Api\Website\CmsContentTypeController;
use App\Http\Controllers\Api\Website\AIContentController;
use App\Http\Controllers\Api\Website\WebsiteSettingController;
use App\Http\Controllers\Api\CMS\NavigationMenuController;
use App\Http\Controllers\Api\CMS\FooterLinkController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Auth\TwoFactorController;
use App\Http\Controllers\Api\Auth\SocialAuthController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\GamificationController;
use App\Http\Controllers\Api\LoyaltyController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AvailabilityController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\FileUploadController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\BookingController;

/*
|--------------------------------------------------------------------------
| Authentication Routes
|--------------------------------------------------------------------------
|
| These routes handle user authentication including registration, login,
| logout, password reset, email verification, and two-factor authentication
|
*/
// Dynamic service configuration API routes

Route::prefix('auth')->group(function () {
    // Public authentication routes — strict throttle to prevent brute-force / enumeration
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    // Route::post('login', [AuthController::class, 'login']);
    Route::post('login', [AuthController::class, 'loginWithRefresh'])->middleware('throttle:10,1');
    Route::get('lockout-status', [AuthController::class, 'lockoutStatus'])->middleware('throttle:30,1');
    Route::post('refresh', [AuthController::class, 'refreshToken'])->middleware('throttle:30,1');
    Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
    Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:10,1');

    // Social authentication routes
    Route::prefix('social')->group(function () {
        Route::get('{provider}/redirect', [SocialAuthController::class, 'redirect']);
        Route::get('{provider}/callback', [SocialAuthController::class, 'callback']);
    });

    // Email verification routes
    Route::get('verify-email/{id}/{hash}', [AuthController::class, 'verifyEmail'])
        ->name('verification.verify');

    // Protected authentication routes
    Route::middleware(['auth:api', 'update.api.session'])->group(function () {
        Route::get('profile', [AuthController::class, 'profile']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('logout-all', [AuthController::class, 'logoutAll']);
        Route::post('change-password', [AuthController::class, 'changePassword']);
        Route::post('resend-verification', [AuthController::class, 'resendEmailVerification']);
        Route::get('sessions', [AuthController::class, 'sessions']);
        Route::delete('sessions/{tokenId}', [AuthController::class, 'revokeSession']);

        // Two-factor authentication routes
        Route::prefix('2fa')->group(function () {
            Route::post('enable', [TwoFactorController::class, 'enable']);
            Route::post('disable', [TwoFactorController::class, 'disable']);
            Route::post('verify', [TwoFactorController::class, 'verify']);
            Route::get('qr-code', [TwoFactorController::class, 'qrCode']);
            Route::get('recovery-codes', [TwoFactorController::class, 'recoveryCodes']);
            Route::post('recovery-codes/regenerate', [TwoFactorController::class, 'regenerateRecoveryCodes']);
        });
    });
});

Route::prefix('agent-api')
    ->middleware(['agent.api'])
    ->group(function () {
        Route::get('me', [AgentOperationalController::class, 'profile'])->middleware('agent.api.access:read');
        Route::get('usage', [AgentOperationalController::class, 'usage'])->middleware('agent.api.access:read');
        Route::get('bookings', [AgentOperationalController::class, 'bookings'])->middleware('agent.api.access:read');
        Route::get('bookings/{booking}', [AgentOperationalController::class, 'showBooking'])->middleware('agent.api.access:read');
        Route::post('bookings/{booking}/status', [AgentOperationalController::class, 'updateBookingStatus'])->middleware('agent.api.access:write');
        Route::get('customers', [AgentOperationalController::class, 'customers'])->middleware('agent.api.access:read');
    });

/*
|--------------------------------------------------------------------------
| Public Inquiry Service Page Routes
|--------------------------------------------------------------------------
*/
Route::prefix('public')->group(function () {
    Route::get('inquiry-services', [PublicInquiryServiceController::class, 'index']);
    Route::get('inquiry-services/{slug}', [PublicInquiryServiceController::class, 'show']);

    // Return trip pricing calculator (public)
    Route::post('return-trip/calculate', [ServicePackageController::class, 'calculateReturnPrice']);

    // Branding settings (public - no auth required)
    Route::get('branding', [\App\Http\Controllers\Api\Website\WebsiteSettingController::class, 'branding']);

    // Non-sensitive module availability flags used before portal route authorization.
    Route::get('business-feature-flags', [WebsiteSettingController::class, 'businessFeatureFlags']);
});

Route::match(['get', 'post'], 'sms/webhooks/delivery-report', [SmsManagementController::class, 'deliveryCallback']);

/*
|--------------------------------------------------------------------------
| User Management Routes
|--------------------------------------------------------------------------
|
| These routes handle user management operations including CRUD operations
| for users, roles, and permissions
|
*/

/*
|--------------------------------------------------------------------------
| Protected API Routes
|--------------------------------------------------------------------------
|
| All routes below require authentication and appropriate permissions
|
*/

Route::prefix('utility')->group(function () {
    Route::get('countries', [UtilityController::class, 'countries']);
    Route::get('searchStates', [UtilityController::class, 'states']);
    Route::get('states', [UtilityController::class, 'searchStates']);
    Route::get('constants', [UtilityController::class, 'constants']);
});

Route::middleware(['auth:api'])->group(function () {

    /*
    |--------------------------------------------------------------------------
    | User Management Routes
    |--------------------------------------------------------------------------
    */

    Route::get('service-types/{serviceType}/form-config', [ServiceFormConfigController::class, 'getFormConfig'])
        ->middleware('permission:bookings.view|bookings.create|create_bookings|view_all_bookings|corporate.view|system.view');
    Route::get('booking-flow/service-types', function (\Illuminate\Http\Request $request) {
        $context = (string) $request->input('context', 'portal');
        $ownerType = (string) $request->input('owner_type', '');
        $ownerId = (string) $request->input('owner_id', '');
        $fallbackContext = (string) $request->input('fallback_context', '');
        $perPage = (int) ($request->input('per_page', 100));

        if ($context === 'corporate') {
            $ownerType = '';
            $ownerId = '';
        }

        $query = \App\Models\Service\ServiceType::query()
            ->where('is_active', true);

        if ($context !== 'all') {
            $query->forContext($context, $ownerType, $ownerId);
        }

        if ($request->filled('search')) {
            $search = (string) $request->input('search');
            $query->where(function ($builder) use ($search) {
                $builder->whereLikeInsensitive('name', $search)
                    ->orWhereLikeInsensitive('code', $search);
            });
        }

        if ($fallbackContext !== '' && !(clone $query)->exists()) {
            $fallbackOwnerType = (string) $request->input('fallback_owner_type', '');
            $fallbackOwnerId = (string) $request->input('fallback_owner_id', '');
            $query = \App\Models\Service\ServiceType::query()
                ->where('is_active', true)
                ->forContext($fallbackContext, $fallbackOwnerType, $fallbackOwnerId);
        }

        $serviceTypes = $query
            ->orderBy('priority')
            ->orderBy('name')
            ->paginate(min(max($perPage, 1), 500));

        return \App\Http\Resources\ServiceTypeResource::collection($serviceTypes);
    });
    Route::get('booking-flow/service-types/{serviceType}/form-config', [ServiceFormConfigController::class, 'getFormConfig'])
        ->middleware('auth:api');

    Route::middleware(['permission:users.view'])->group(function () {
        Route::get('users', [UserController::class, 'index']);
        Route::get('users/filter-options', [UserController::class, 'filterOptions']);
        Route::post('users/search/advanced', [UserController::class, 'advancedSearch']);
        Route::get('users/export', [UserController::class, 'export'])->middleware('permission:users.manage');
        Route::get('users/{user}', [UserController::class, 'show']);
        Route::get('users/{user}/permissions', [UserController::class, 'permissions'])->middleware('permission:permissions.manage');
        Route::get('users/{user}/roles', [UserController::class, 'roles'])->middleware('permission:users.edit');

        // Admin: view contexts for a specific user
        Route::get('users/{user}/contexts', [UserController::class, 'contexts']);
    });
    Route::post('users', [UserController::class, 'store'])->middleware('permission:users.create');
    Route::post('users/import', [UserController::class, 'import'])->middleware('permission:users.manage');
    Route::post('users/bulk', [UserController::class, 'bulk'])->middleware('permission:users.manage');
    Route::put('users/{user}', [UserController::class, 'update'])->middleware('permission:users.edit');
    Route::delete('users/{user}', [UserController::class, 'destroy'])->middleware('permission:users.delete');
    Route::post('users/{user}/activate', [UserController::class, 'activate'])->middleware('permission:users.manage');
    Route::post('users/{user}/deactivate', [UserController::class, 'deactivate'])->middleware('permission:users.manage');
    Route::post('users/{user}/unlock', [UserController::class, 'unlock'])->middleware('permission:users.manage');
    Route::post('users/{user}/impersonate', [UserController::class, 'impersonate'])->middleware('permission:users.manage');
    Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword'])->middleware('permission:users.manage');
    Route::post('users/{user}/permissions', [UserController::class, 'assignPermissions'])->middleware('permission:permissions.manage');
    Route::delete('users/{user}/permissions', [UserController::class, 'revokePermissions'])->middleware('permission:permissions.manage');
    Route::put('users/{user}/permissions/sync-direct', [UserController::class, 'syncDirectPermissions'])->middleware('permission:permissions.manage');
    Route::post('users/{user}/roles', [UserController::class, 'assignRoles'])->middleware('permission:users.edit');
    Route::delete('users/{user}/roles', [UserController::class, 'revokeRoles'])->middleware('permission:users.edit');
    Route::post('users/{user}/contexts/activate', [UserController::class, 'activateContext'])->middleware('permission:users.edit');
    Route::post('users/{user}/contexts/deactivate', [UserController::class, 'deactivateContext'])->middleware('permission:users.edit');
    Route::post('users/{user}/contexts/{context}/roles', [UserController::class, 'assignContextRoles'])->middleware('permission:users.edit');
    Route::delete('users/{user}/contexts/{context}/roles', [UserController::class, 'revokeContextRole'])->middleware('permission:users.edit');

    /*
    |--------------------------------------------------------------------------
    | User Profile Management Routes
    |--------------------------------------------------------------------------
    */
    Route::prefix('profile')->group(function () {
        Route::get('', [UserController::class, 'profile']);
        Route::put('', [UserController::class, 'updateProfile']);
        Route::post('/change-password', [UserController::class, 'changePassword']);
        Route::put('/status', [UserController::class, 'updateStatus']);
    });

    Route::prefix('sms')->group(function () {
        Route::get('overview', [SmsManagementController::class, 'overview']);
        Route::get('settings', [SmsManagementController::class, 'settings']);
        Route::put('settings', [SmsManagementController::class, 'updateSettings']);
        Route::post('send', [SmsManagementController::class, 'send']);
        Route::post('test', [SmsManagementController::class, 'sendTest']);
        Route::get('messages', [SmsManagementController::class, 'messages']);
        Route::post('messages/{smsMessage}/retry', [SmsManagementController::class, 'retryMessage']);
        Route::get('campaigns', [SmsManagementController::class, 'campaigns']);
        Route::post('campaigns', [SmsManagementController::class, 'createCampaign']);
        Route::get('campaigns/{smsCampaign}', [SmsManagementController::class, 'showCampaign']);
        Route::post('campaigns/{smsCampaign}/launch', [SmsManagementController::class, 'launchCampaign']);
        Route::get('balance', [SmsManagementController::class, 'balance']);
    });

    /*
    |--------------------------------------------------------------------------
    | Role Management Routes
    |--------------------------------------------------------------------------
    */

    Route::middleware(['permission:roles.view'])->group(function () {
        Route::apiResource('roles', RoleController::class);
        Route::get('roles/{role}/permissions', [RoleController::class, 'permissions']);
        Route::put('roles/{role}/permissions/sync', [RoleController::class, 'syncPermissions'])->middleware('permission:permissions.manage');
        Route::post('roles/{role}/permissions/apply-template', [RoleController::class, 'applyTemplate'])->middleware('permission:permissions.manage');
        Route::post('roles/{role}/permissions', [RoleController::class, 'assignPermissions'])->middleware('permission:permissions.manage');
        Route::delete('roles/{role}/permissions', [RoleController::class, 'revokePermissions'])->middleware('permission:permissions.manage');
        Route::get('roles/{role}/users', [RoleController::class, 'users']);
    });

    /*
    |--------------------------------------------------------------------------
    | Permission Management Routes
    |--------------------------------------------------------------------------
    */

    Route::get('permissions/registry', [PermissionController::class, 'registry'])->middleware('permission:permissions.view');
    Route::get('permissions', [PermissionController::class, 'index'])->middleware('permission:permissions.view');
    Route::get('permissions/{permission}', [PermissionController::class, 'show'])->middleware('permission:permissions.view');
    Route::post('permissions', [PermissionController::class, 'store'])->middleware('permission:permissions.manage');
    Route::put('permissions/{permission}', [PermissionController::class, 'update'])->middleware('permission:permissions.manage');
    Route::delete('permissions/{permission}', [PermissionController::class, 'destroy'])->middleware('permission:permissions.manage');
    Route::get('permissions/{permission}/roles', [PermissionController::class, 'roles'])->middleware('permission:permissions.view');
    Route::get('permissions/{permission}/users', [PermissionController::class, 'users'])->middleware('permission:permissions.view');


    /*
    |--------------------------------------------------------------------------
    | User Context Management Routes (Multi-Role Support)
    |--------------------------------------------------------------------------
    */

    Route::prefix('user-context')->group(function () {
        Route::get('available', [UserContextController::class, 'getAvailableContexts']);
        Route::post('switch', [UserContextController::class, 'switchContext']);
        Route::post('deactivate', [UserContextController::class, 'deactivateContext']);
        Route::get('profile', [UserContextController::class, 'getUserProfile']);
    });

    Route::prefix('contexts')->group(function () {
        Route::get('available', [UserContextController::class, 'getAvailableContexts']);
        Route::post('switch', [UserContextController::class, 'switchContext']);
        Route::post('deactivate', [UserContextController::class, 'deactivateContext']);
        Route::get('profile', [UserContextController::class, 'getUserProfile']);
    });

    /*
    |--------------------------------------------------------------------------
    | Customer Management Routes
    |--------------------------------------------------------------------------
    */

    Route::apiResource('payment-methods', PaymentMethodController::class)
        ->middleware('permission:payment-methods.manage')
        ->except(['index', 'show']);
    Route::get('payment-methods', [PaymentMethodController::class, 'index'])->middleware('permission:bookings.view');
    Route::get('payment-methods/{payment_method}', [PaymentMethodController::class, 'show'])->middleware('permission:bookings.view');

    Route::middleware(['permission:system.view|agreements.view|customers.view|drivers.view|staff.view|vehicles.view|vehicle-owners.view'])->group(function () {
        Route::get('documents/stats', [DocumentController::class, 'stats']);
        Route::get('documents', [DocumentController::class, 'index']);
        Route::get('documents/{document}', [DocumentController::class, 'show'])->whereUuid('document');
        Route::get('documents/{document}/download', [DocumentController::class, 'download'])->whereUuid('document');
    });

    Route::middleware(['permission:uploads.manage|customers.edit|agreements.view|drivers.edit|staff.edit|vehicles.edit|vehicle-owners.edit'])->group(function () {
        Route::post('documents', [DocumentController::class, 'store']);
        Route::post('documents/{document}/verify', [DocumentController::class, 'verify'])->whereUuid('document');
        Route::post('documents/{document}/reject', [DocumentController::class, 'reject'])->whereUuid('document');
        Route::delete('documents/{document}', [DocumentController::class, 'destroy'])->whereUuid('document');
    });

    Route::middleware(['permission:customers.view'])->group(function () {
        Route::get('customers/search', [CustomerController::class, 'search']);
        Route::get('customers/check-email', [CustomerController::class, 'checkEmail']);
        Route::get('customers/stats', [CustomerController::class, 'getCustomerAnalytics'])->middleware('permission:customers.analytics');
        Route::get('customers/analytics', [CustomerController::class, 'getCustomerAnalytics'])->middleware('permission:customers.analytics');
        Route::get('customers/analytics/growth', [CustomerController::class, 'getCustomerGrowth'])->middleware('permission:customers.analytics');
        Route::get('customers/analytics/segments', [CustomerController::class, 'getCustomerSegments'])->middleware('permission:customers.analytics');
        Route::get('customers/analytics/cohort', [CustomerController::class, 'getCustomerCohort'])->middleware('permission:customers.analytics');
        Route::get('customers/analytics/top-customers', [CustomerController::class, 'getTopCustomers'])->middleware('permission:customers.analytics');
        Route::get('customers/export', [CustomerController::class, 'exportCustomers'])->middleware('permission:customers.export');
        Route::get('customers/feedback/stats', [CustomerController::class, 'getFeedbackStats'])->middleware('permission:customers.feedback');
        Route::get('customers/feedback', [CustomerController::class, 'getAllCustomerFeedback'])->middleware('permission:customers.feedback');
        Route::post('customers/feedback', [CustomerController::class, 'submitCustomerFeedback'])->middleware('permission:customers.feedback');
        Route::post('customers/feedback/{feedbackId}/respond', [CustomerController::class, 'respondToFeedback'])->middleware('permission:customers.feedback');
        Route::get('customers/documents/stats', [CustomerController::class, 'getDocumentStats']);
        Route::get('customers/documents', [CustomerController::class, 'getDocuments']);
        Route::post('customers/documents', [CustomerController::class, 'storeDocument'])->middleware('permission:customers.create');
        Route::get('customers/documents/{document}', [CustomerController::class, 'showDocument'])->whereUuid('document');
        Route::get('customers/documents/{document}/download', [CustomerController::class, 'downloadDocument'])->whereUuid('document');
        Route::post('customers/documents/{document}/verify', [CustomerController::class, 'verifyDocument'])->middleware('permission:customers.edit')->whereUuid('document');
        Route::post('customers/documents/{document}/reject', [CustomerController::class, 'rejectDocument'])->middleware('permission:customers.edit')->whereUuid('document');
        Route::get('customers/{customer}/bookings', [CustomerController::class, 'getCustomerBookings'])->middleware('permission:customers.bookings')->whereUuid('customer');
        Route::get('customers/{customer}/loyalty', [CustomerController::class, 'getCustomerLoyalty'])->middleware('permission:customers.loyalty')->whereUuid('customer');
        Route::post('customers/{customer}/loyalty/points', [CustomerController::class, 'addLoyaltyPoints'])->middleware('permission:customers.loyalty')->whereUuid('customer');
        Route::get('customers/{customer}/documents', [CustomerController::class, 'getCustomerDocuments'])->whereUuid('customer');
        Route::get('customers/{customer}/feedback', [CustomerController::class, 'getCustomerFeedback'])->middleware('permission:customers.feedback')->whereUuid('customer');
        Route::post('customers/{customer}/feedback', [CustomerController::class, 'addCustomerFeedback'])->middleware('permission:customers.feedback')->whereUuid('customer');
        Route::apiResource('customers', CustomerController::class)->whereUuid('customer');
    });

    /*
    |--------------------------------------------------------------------------
    | Staff Management Routes
    |--------------------------------------------------------------------------
    */

    Route::middleware(['permission:staff.view'])->group(function () {
        Route::get('staff/roles', [StaffController::class, 'roles']);
        Route::apiResource('staff', StaffController::class)->whereUuid('staff');
    });

    /*
    |--------------------------------------------------------------------------
    | System Configuration Routes
    |--------------------------------------------------------------------------
    */

    Route::middleware(['permission:system.view'])->group(function () {
        Route::get('system/health', [SystemController::class, 'health']);
        Route::get('system/info', [SystemController::class, 'info']);
        Route::get('system/stats', [SystemController::class, 'stats']);
        Route::get('system/performance', [SystemController::class, 'performance']);
        Route::post('system/clear-cache', [SystemController::class, 'clearCache']);
        Route::post('system/optimize-database', [SystemController::class, 'optimizeDatabase']);
        Route::apiResource('audit-logs', AuditLogController::class)->only(['index', 'show']);

        Route::apiResource('countries', CountryController::class);
        Route::get('countries/{country}/states', [StateController::class, 'index']);
        Route::apiResource('states', StateController::class);
        Route::apiResource('regions', RegionController::class);

        Route::apiResource('business-settings', BusinessSettingController::class);
        Route::apiResource('currencies', CurrencyController::class);
        Route::get('companies/stats', [CompanyController::class, 'stats']);
        Route::apiResource('companies', CompanyController::class)->whereUuid('company');

        // Public CMS routes (no authentication required)
        Route::get('public/cms-contents/published', [CmsContentController::class, 'published'])->name('api.cms-contents.published');
        Route::get('public/{contentTypeSlug}/{contentSlug}', [CmsContentController::class, 'getBySlug'])->name('api.cms-contents.public');
        
        Route::get('business-settings/all/categorized', [BusinessSettingController::class, 'getAllCategorized']);
        Route::put('business-settings/category/{category}', [BusinessSettingController::class, 'updateCategory']);

        Route::get('website-settings/all/categorized', [BusinessSettingController::class, 'getAllCategorized']);
        Route::put('website-settings/category/{category}', [BusinessSettingController::class, 'updateCategory']);
        Route::post('website-settings/update-multiple', [WebsiteSettingController::class, 'updateMultiple']);
        Route::get('website-settings/homepage/settings', [WebsiteSettingController::class, 'homepage']);
        // Trigger server-side cache clear (optimize:clear) - admin only
        Route::post('website-settings/optimize-clear', [WebsiteSettingController::class, 'optimizeClear']);

        // Category-specific settings routes
        Route::get('website-settings/category/{category}', [WebsiteSettingController::class, 'getCategory']);
        Route::put('website-settings/category/{category}', [WebsiteSettingController::class, 'updateCategory']);
        Route::get('website-settings/all/categorized', [WebsiteSettingController::class, 'getAllCategorized']);

        // Individual category endpoints for better organization
        Route::get('website-settings/general/settings', [WebsiteSettingController::class, 'general']);
        Route::get('website-settings/seo/settings', [WebsiteSettingController::class, 'seo']);
        Route::get('website-settings/social-media/settings', [WebsiteSettingController::class, 'socialMedia']);
        Route::get('website-settings/payment/settings', [WebsiteSettingController::class, 'payment']);
        Route::get('website-settings/security/settings', [WebsiteSettingController::class, 'security']);
        Route::get('website-settings/email/settings', [WebsiteSettingController::class, 'email']);
        Route::get('website-settings/booking/settings', [WebsiteSettingController::class, 'booking']);
        Route::get('website-settings/driver-mobile/settings', [WebsiteSettingController::class, 'driverMobile']);
        Route::get('website-settings/appearance/settings', [WebsiteSettingController::class, 'appearance']);
        Route::get('website-settings/{section}/{key}', [WebsiteSettingController::class, 'getByKey']);
        Route::put('website-settings/{section}/{key}', [WebsiteSettingController::class, 'updateByKey']);
        Route::apiResource('website-settings', WebsiteSettingController::class);
        Route::apiResource('vip-types', VipTypeController::class);
        Route::apiResource('service-types', ServiceTypeController::class);
        Route::post('service-types/{serviceType}/clone', [ServiceTypeController::class, 'clone']);
        Route::apiResource('service-packages', ServicePackageController::class);

        // Service Form Configuration
        Route::get('service-types/{serviceType}/form-config', [ServiceFormConfigController::class, 'getFormConfig']);
        Route::put('service-types/{serviceType}/form-config', [ServiceFormConfigController::class, 'updateFormConfig']);

        // Airports Management
        Route::apiResource('airports', AirportController::class);
        Route::get('airports-active', [AirportController::class, 'getActive']);

        // Service Package Return Rules
        Route::get('service-packages/{servicePackage}/return-rules', [ServicePackageController::class, 'getReturnRules']);
        Route::post('service-packages/{servicePackage}/return-rules', [ServicePackageController::class, 'storeReturnRule']);
        Route::put('service-packages/{servicePackage}/return-rules/{returnRule}', [ServicePackageController::class, 'updateReturnRule']);
        Route::delete('service-packages/{servicePackage}/return-rules/{returnRule}', [ServicePackageController::class, 'destroyReturnRule']);

        // Inquiry service pages & dynamic forms
        Route::apiResource('inquiry-forms', InquiryFormController::class);
        Route::apiResource('inquiry-service-pages', InquiryServicePageController::class);
        // Manage sections for inquiry service pages (CRUD)
        Route::apiResource('inquiry-service-pages.sections', \App\Http\Controllers\Api\InquiryServicePageSectionController::class);
        Route::post('inquiry-service-pages/{inquiry_service_page}/sections/reorder', [\App\Http\Controllers\Api\InquiryServicePageSectionController::class, 'reorder']);

        // Service Configuration API routes for dynamic forms
        Route::get('services/configuration', [BookingController::class, 'getServiceConfiguration'])->name('api.services.configuration');
        Route::get('services/{serviceCode}/form-config', [BookingController::class, 'getServiceFormConfig']);
        Route::get('services/{serviceCode}/validation-rules', [BookingController::class, 'getServiceValidationRules']);

        Route::apiResource('driving-license-types', DrivingLicenseTypeController::class);
        Route::apiResource('driving-licenses', DrivingLicenseController::class);
    });

    Route::middleware(['permission:system.manage'])->group(function () {
        Route::get('system/backups', [SystemBackupController::class, 'index']);
        Route::post('system/backups', [SystemBackupController::class, 'store']);
        Route::get('system/backups/{backup}', [SystemBackupController::class, 'show']);
        Route::get('system/backups/{backup}/download', [SystemBackupController::class, 'download']);
        Route::post('system/backups/{backup}/restore', [SystemBackupController::class, 'restore']);
        Route::delete('system/backups/{backup}', [SystemBackupController::class, 'destroy']);
    });

    // CMS content management (protected by controller permissions)
    Route::apiResource('cms-content-types', CmsContentTypeController::class);
    Route::get('cms-contents/filter-users', [CmsContentController::class, 'filterUsers']);
    Route::get('cms-contents/search', [CmsContentController::class, 'search']);
    Route::put('cms-contents/{cms_content}/publish', [CmsContentController::class, 'publish']);
    Route::put('cms-contents/{cms_content}/unpublish', [CmsContentController::class, 'unpublish']);
    Route::apiResource('cms-contents', CmsContentController::class);

    // AI Content Generation Routes
    Route::prefix('ai-content')->group(function () {
        Route::post('generate', [AIContentController::class, 'generateFromTitle'])->name('api.ai-content.generate');
        Route::post('generate-meta', [AIContentController::class, 'generateMeta'])->name('api.ai-content.generate-meta');
        Route::post('improve', [AIContentController::class, 'improveContent'])->name('api.ai-content.improve');
        Route::get('status', [AIContentController::class, 'status'])->name('api.ai-content.status');
    });


    /*
    |--------------------------------------------------------------------------
    | Vehicle Management Routes
    |--------------------------------------------------------------------------
    */

    Route::middleware(['permission:vehicles.view'])->group(function () {
        Route::prefix('vehicles')->group(function () {
            // Vehicle Addons - Extended routes
            Route::prefix('vehicle-addons')->group(function () {
                Route::get('stats', [VehicleAddonController::class, 'stats']);
                Route::get('available', [VehicleAddonController::class, 'available']);
                Route::get('for-service', [VehicleAddonController::class, 'forService']);
                Route::post('calculate-price', [VehicleAddonController::class, 'calculatePrice']);
                Route::post('calculate-multiple', [VehicleAddonController::class, 'calculateMultiple']);
                Route::post('check-availability', [VehicleAddonController::class, 'checkAvailability']);
                Route::post('bulk-update-status', [VehicleAddonController::class, 'bulkUpdateStatus']);
                Route::put('{vehicleAddon}/toggle-status', [VehicleAddonController::class, 'toggleStatus']);
            });
            Route::apiResource('vehicle-addons', VehicleAddonController::class);
            Route::apiResource('vehicle-categories', VehicleCategoryController::class);
            Route::apiResource('vehicle-classes', VehicleClassController::class);
            // Route::apiResource('vehicle-common-rates', VehicleCommonRateController::class);
            Route::apiResource('vehicle-contract-types', VehicleContractTypeController::class);
            Route::apiResource('vehicle-distance-multipliers', VehicleDistanceMultiplierController::class);
            Route::apiResource('vehicle-fuel-types', VehicleFuelTypeController::class);
            Route::apiResource('vehicle-grades', VehicleGradeController::class);
            Route::apiResource('vehicle-groups', VehicleGroupController::class);
            Route::apiResource('vehicle-images', VehicleImageController::class);
            Route::post('vehicle-insurances/{vehicleInsurance}/renew', [VehicleInsuranceController::class, 'renew']);
            Route::apiResource('vehicle-insurances.claims', VehicleInsuranceClaimController::class)->shallow(false);
            Route::apiResource('vehicle-insurances', VehicleInsuranceController::class);
            Route::post('vehicle-revenue-licenses/{vehicleRevenueLicense}/renew', [VehicleRevenueLicenseController::class, 'renew']);
            Route::apiResource('vehicle-revenue-licenses', VehicleRevenueLicenseController::class);
            Route::apiResource('vehicle-insurance-providers', VehicleInsuranceProviderController::class);
            Route::apiResource('vehicle-insurance-types', VehicleInsuranceTypeController::class);
            Route::apiResource('vehicle-maintenance-schedules', VehicleMaintenanceScheduleController::class);
            Route::apiResource('vehicle-maintenance-records', VehicleMaintenanceRecordController::class);
            Route::apiResource('maintenance/schedules', VehicleMaintenanceScheduleController::class)
                ->parameters(['schedules' => 'vehicleMaintenanceSchedule']);
            Route::apiResource('maintenance/records', VehicleMaintenanceRecordController::class)
                ->parameters(['records' => 'vehicleMaintenanceRecord']);
            Route::apiResource('vehicle-makes', VehicleMakeController::class);
            Route::apiResource('vehicle-models', VehicleModelController::class);
            Route::apiResource('vehicle-owners', VehicleOwnerController::class);
            Route::apiResource('vehicle-owner-types', VehicleOwnerTypeController::class);
            // Route::apiResource('slab-definitions', VehiclePricingSlabController::class);
            // Route::apiResource('vehicle-pricing-slab-rates', VehiclePricingSlabRateController::class);
            Route::apiResource('vehicle-transmissions', VehicleTransmissionController::class);
            // Route::apiResource('vehicle-discounts', VehicleDiscountController::class);


            Route::prefix('pricing-slab-definitions')->group(function () {
                Route::get('/', [VehiclePricingSlabDefinitionController::class, 'index']);
                Route::post('/', [VehiclePricingSlabDefinitionController::class, 'store']);
                Route::get('/{id}', [VehiclePricingSlabDefinitionController::class, 'show']);
                Route::put('/{id}', [VehiclePricingSlabDefinitionController::class, 'update']);
                Route::delete('/{id}', [VehiclePricingSlabDefinitionController::class, 'destroy']);
                Route::patch('/{id}/toggle-status', [VehiclePricingSlabDefinitionController::class, 'toggleStatus']);
                Route::post('/find-for-hours', [VehiclePricingSlabDefinitionController::class, 'findForHours']);
            });

            // Vehicle Group Pricing
            Route::prefix('vehicle-group-pricing')->group(function () {
                Route::get('/', [VehicleGroupPricingController::class, 'index']);
                Route::post('/', [VehicleGroupPricingController::class, 'store']);
                Route::post('/bulk-store', [VehicleGroupPricingController::class, 'bulkStore']);
                Route::post('/bulk-save', [VehicleGroupPricingController::class, 'bulkSavePricing']);
                Route::post('/pricing-matrix', [VehicleGroupPricingController::class, 'getPricingMatrix']);
                Route::get('/matrix', [VehicleGroupPricingController::class, 'matrix']);
                Route::get('/unified-pricing', [VehicleGroupPricingController::class, 'unifiedPricing']);
                Route::post('/copy-pricing', [VehicleGroupPricingController::class, 'copyPricing']);
                Route::post('/{vehicleGroupId}/save-pricing', [VehicleGroupPricingController::class, 'saveVehicleGroupPricing']);
                Route::get('/{vehicleGroupId}/history', [VehicleGroupPricingController::class, 'getVehicleGroupPricingHistory']);
                Route::get('/{id}', [VehicleGroupPricingController::class, 'show']);
                Route::put('/{id}', [VehicleGroupPricingController::class, 'update']);
                Route::delete('/{id}', [VehicleGroupPricingController::class, 'destroy']);
            });

            // Pricing Common Rate Definition endpoints
            Route::prefix('common-rate-definitions')->group(function () {
                Route::get('/', [VehiclePricingCommonRateDefinitionController::class, 'index']);
                Route::post('/', [VehiclePricingCommonRateDefinitionController::class, 'store']);
                Route::get('/{id}', [VehiclePricingCommonRateDefinitionController::class, 'show']);
                Route::put('/{id}', [VehiclePricingCommonRateDefinitionController::class, 'update']);
                Route::delete('/{id}', [VehiclePricingCommonRateDefinitionController::class, 'destroy']);
                Route::post('/{id}/toggle-status', [VehiclePricingCommonRateDefinitionController::class, 'toggleStatus']);
                Route::get('/service-type/{serviceTypeId}', [VehiclePricingCommonRateDefinitionController::class, 'getByServiceType']);
                Route::post('/reorder', [VehiclePricingCommonRateDefinitionController::class, 'reorder']);
                Route::get('/statistics/summary', [VehiclePricingCommonRateDefinitionController::class, 'getStats']);
                Route::post('/bulk/toggle-status', [VehiclePricingCommonRateDefinitionController::class, 'bulkToggleStatus']);
                Route::delete('/bulk/delete', [VehiclePricingCommonRateDefinitionController::class, 'bulkDelete']);
                Route::post('/calculate-preview', [VehiclePricingCommonRateDefinitionController::class, 'calculatePreview']);
                Route::post('/validate-name', [VehiclePricingCommonRateDefinitionController::class, 'validateName']);
                Route::get('/export/data', [VehiclePricingCommonRateDefinitionController::class, 'export']);
                Route::post('/import/data', [VehiclePricingCommonRateDefinitionController::class, 'import']);
                Route::get('/import/template', [VehiclePricingCommonRateDefinitionController::class, 'downloadTemplate']);
            });


            // Calculation Definitions Management
            Route::prefix('calculation-definitions')->group(function () {
                Route::get('/', [VehiclePricingCalculationDefinitionController::class, 'index']);
                Route::post('/', [VehiclePricingCalculationDefinitionController::class, 'store']);
                Route::get('/service-types', [VehiclePricingCalculationDefinitionController::class, 'getServiceTypes']);
                Route::get('/vehicle-groups', [VehiclePricingCalculationDefinitionController::class, 'getVehicleGroups']);
                Route::get('/service-types/{serviceTypeId}/available-variables', [VehiclePricingCalculationDefinitionController::class, 'getAvailableVariables']);
                Route::post('/test-calculation', [VehiclePricingCalculationDefinitionController::class, 'testCalculation']);
                Route::post('/test-definition', [VehiclePricingCalculationDefinitionController::class, 'testDefinitionCalculation']);
                Route::post('/calculate-price', [VehiclePricingCalculationDefinitionController::class, 'calculatePrice']);
                Route::post('/slab-rates', [VehiclePricingCalculationDefinitionController::class, 'getSlabRates']);
                Route::post('/bulk-update-status', [VehiclePricingCalculationDefinitionController::class, 'bulkUpdateStatus']);
                Route::get('/{id}', [VehiclePricingCalculationDefinitionController::class, 'show']);
                Route::put('/{id}', [VehiclePricingCalculationDefinitionController::class, 'update']);
                Route::delete('/{id}', [VehiclePricingCalculationDefinitionController::class, 'destroy']);
            });

            // KM-Range Pricing Management
            Route::prefix('km-range-pricing')->group(function () {
                Route::get('/', [KmRangePricingController::class, 'index']);
                Route::post('/', [KmRangePricingController::class, 'store']);
                Route::get('/service-types', [KmRangePricingController::class, 'getServiceTypes']);
                Route::get('/vehicle-groups', [KmRangePricingController::class, 'getVehicleGroups']);
                Route::post('/applicable-rules', [KmRangePricingController::class, 'getApplicableRules']);
                Route::post('/calculate-pricing', [KmRangePricingController::class, 'calculatePricing']);
                Route::post('/bulk-update-status', [KmRangePricingController::class, 'bulkUpdateStatus']);
                Route::get('/{id}', [KmRangePricingController::class, 'show']);
                Route::put('/{id}', [KmRangePricingController::class, 'update']);
                Route::delete('/{id}', [KmRangePricingController::class, 'destroy']);
                Route::patch('/{id}/toggle-status', [KmRangePricingController::class, 'toggleStatus']);
            });

            // Price Adjustments Management
            Route::prefix('price-adjustments')->group(function () {
                Route::get('/', [PriceAdjustmentController::class, 'index']);
                Route::post('/', [PriceAdjustmentController::class, 'store']);
                Route::get('/service-types', [PriceAdjustmentController::class, 'getServiceTypes']);
                Route::get('/vehicle-groups', [PriceAdjustmentController::class, 'getVehicleGroups']);
                Route::post('/applicable-adjustments', [PriceAdjustmentController::class, 'getApplicableAdjustments']);
                Route::post('/apply-adjustments', [PriceAdjustmentController::class, 'applyAdjustments']);
                Route::post('/bulk-update-status', [PriceAdjustmentController::class, 'bulkUpdateStatus']);
                Route::get('/{id}', [PriceAdjustmentController::class, 'show']);
                Route::put('/{id}', [PriceAdjustmentController::class, 'update']);
                Route::delete('/{id}', [PriceAdjustmentController::class, 'destroy']);
                Route::patch('/{id}/toggle-status', [PriceAdjustmentController::class, 'toggleStatus']);
                Route::get('/{id}/usage-statistics', [PriceAdjustmentController::class, 'getUsageStatistics']);
            });


            // Vehicle Discounts
            Route::prefix('vehicle-discounts')->group(function () {
                Route::get('/', [VehicleDiscountController::class, 'index']);
                Route::post('/', [VehicleDiscountController::class, 'store']);
                Route::delete('/bulk', [VehicleDiscountController::class, 'bulkDestroy']);
                Route::post('/bulk/toggle-status', [VehicleDiscountController::class, 'bulkToggleStatus']);
                Route::get('/applicable/search', [VehicleDiscountController::class, 'getApplicable']);
                Route::post('/calculate-preview', [VehicleDiscountController::class, 'calculatePreview']);
                Route::get('/stats/overview', [VehicleDiscountController::class, 'getStats']);
                Route::get('/{vehicleDiscount}', [VehicleDiscountController::class, 'show']);
                Route::put('/{vehicleDiscount}', [VehicleDiscountController::class, 'update']);
                Route::delete('/{vehicleDiscount}', [VehicleDiscountController::class, 'destroy']);
                Route::patch('/{vehicleDiscount}/toggle-status', [VehicleDiscountController::class, 'toggleStatus']);
                Route::get('/{vehicleDiscount}/history', [VehicleDiscountController::class, 'history']);
            });

            // Quick Pricing Calculator
            Route::prefix('pricing')->group(function () {
                Route::get('/analytics', [VehicleGroupPricingController::class, 'getPriceAnalytics']);

                // Bulk Operations
                Route::post('/bulk-update', [VehicleGroupPricingController::class, 'bulkUpdate']);
                Route::post('/copy-pricing', [VehicleGroupPricingController::class, 'copyPricing']);
                Route::get('/bulk-operations', [VehicleGroupPricingController::class, 'getBulkOperations']);
                Route::post('/bulk-operations/{id}/cancel', [VehicleGroupPricingController::class, 'cancelBulkOperation']);
                Route::post('/bulk-operations/{id}/retry', [VehicleGroupPricingController::class, 'retryBulkOperation']);
                Route::get('/bulk-operations/{id}/report', [VehicleGroupPricingController::class, 'downloadBulkOperationReport']);
                Route::get('/bulk-operations/{id}', [VehicleGroupPricingController::class, 'getBulkOperationStatus']);

                // Import/Export
                Route::post('/import', [VehicleGroupPricingController::class, 'importPricing']);
                Route::get('/export/{format}', [VehicleGroupPricingController::class, 'exportPricing']);
                Route::get('/template/download', [VehicleGroupPricingController::class, 'downloadTemplate']);
            });


            Route::get('/available', [VehicleController::class, 'getAvailableVehicles']);
            Route::post('/{id}/complete-maintenance', [VehicleController::class, 'completeMaintenanceSchedule']);
        });

        Route::get('/vehicles/available', [VehicleController::class, 'getAvailableVehicles']);
        Route::get('/vehicles/operations-dashboard', [VehicleController::class, 'operationsDashboard']);
        Route::get('/vehicles/availability-analytics', [VehicleController::class, 'availabilityAnalytics']);
        Route::get('/vehicles/{vehicle}/commissions', [VehicleCommissionController::class, 'index']);
        Route::post('/vehicles/{vehicle}/commissions', [VehicleCommissionController::class, 'store']);
        Route::put('/vehicles/{vehicle}/commissions/{commission}', [VehicleCommissionController::class, 'update']);
        Route::delete('/vehicles/{vehicle}/commissions/{commission}', [VehicleCommissionController::class, 'destroy']);
        Route::get('/vehicles/{id}/availability', [VehicleController::class, 'checkAvailability']);
        Route::get('/vehicles/{id}/maintenance/history', [VehicleController::class, 'getMaintenanceHistory']);
        Route::get('/vehicles/maintenance/upcoming', [VehicleController::class, 'getUpcomingMaintenance']);
        Route::post('/vehicles/maintenance/schedules/{id}/complete', [VehicleController::class, 'completeMaintenanceSchedule']);
        Route::post('/vehicles/{id}/block', [VehicleController::class, 'blockVehicle']);
        Route::patch('/vehicles/{id}/availability', [VehicleController::class, 'updateAvailability']);
        Route::get('/vehicles/service-types', [VehicleController::class, 'getServiceTypes']);
        Route::get('/vehicles/insurance-types', [VehicleController::class, 'getInsuranceTypes']);
        Route::apiResource('vehicles', VehicleController::class);

        // Default driver management for vehicles
        Route::get('vehicles/{vehicle}/default-driver', [\App\Http\Controllers\Api\Admin\BookingAssignmentController::class, 'getVehicleDefaultDriver']);
        Route::put('vehicles/{vehicle}/default-driver', [\App\Http\Controllers\Api\Admin\BookingAssignmentController::class, 'updateVehicleDefaultDriver']);
    });

    Route::group(['prefix' => 'reports'], function () {
        Route::get('/dashboard-stats', [ReportsController::class, 'getDashboardStats'])
            ->middleware('permission:reports.view');
        Route::get('/booking-analytics', [ReportsController::class, 'getBookingAnalytics'])
            ->middleware('permission:reports.view');
        Route::get('/bookings/analytics', [ReportsController::class, 'getBookingAnalytics'])
            ->middleware('permission:reports.view');
        Route::get('/bookings/trends', [ReportsController::class, 'getBookingTrendsEndpoint'])
            ->middleware('permission:reports.view');
        Route::get('/bookings/service-type-performance', [ReportsController::class, 'getServiceTypePerformance'])
            ->middleware('permission:reports.view');
        Route::get('/bookings', [ReportsController::class, 'getBookingReports'])
            ->middleware('permission:reports.view');
        Route::get('/financial-reports', [ReportsController::class, 'getFinancialReports'])
            ->middleware('permission:reports.view');
        Route::get('/financial', [ReportsController::class, 'getFinancialReports'])
            ->middleware('permission:reports.view');
        Route::get('/financial/revenue-analytics', [ReportsController::class, 'getRevenueAnalytics'])
            ->middleware('permission:reports.view');
        Route::get('/financial/profitability', [ReportsController::class, 'getProfitabilityAnalysis'])
            ->middleware('permission:reports.view');
        Route::get('/customers/analytics', [ReportsController::class, 'getCustomerAnalytics'])
            ->middleware('permission:reports.view');
        Route::get('/customers/segmentation', [ReportsController::class, 'getCustomerSegmentation'])
            ->middleware('permission:reports.view');
        Route::get('/customers/loyalty', [ReportsController::class, 'getLoyaltyMetricsEndpoint'])
            ->middleware('permission:reports.view');
        Route::get('/customers', [ReportsController::class, 'getCustomerReports'])
            ->middleware('permission:reports.view');
        Route::get('/vehicles/analytics', [ReportsController::class, 'getVehicleAnalytics'])
            ->middleware('permission:reports.view');
        Route::get('/vehicles/fleet-performance', [ReportsController::class, 'getFleetPerformance'])
            ->middleware('permission:reports.view');
        Route::get('/vehicles/maintenance', [ReportsController::class, 'getMaintenanceAnalytics'])
            ->middleware('permission:reports.view');
        Route::get('/vehicles', [ReportsController::class, 'getVehicleReports'])
            ->middleware('permission:reports.view');
        Route::get('/export/{type}', [ReportsController::class, 'exportReport'])
            ->middleware('permission:reports.generate');
        Route::post('/export', [ReportsController::class, 'exportReport'])
            ->middleware('permission:reports.generate');
        Route::get('/generated', [ReportsController::class, 'generatedReports'])
            ->middleware('permission:reports.view');
        Route::get('/download/{report}', [ReportsController::class, 'downloadGeneratedReport'])
            ->middleware('permission:reports.view');
        Route::delete('/{report}', [ReportsController::class, 'deleteGeneratedReport'])
            ->middleware('permission:reports.generate');
        Route::get('/performance-metrics', [ReportsController::class, 'getPerformanceMetrics'])
            ->middleware('permission:reports.view');
        Route::get('/customer-analytics', [ReportsController::class, 'getCustomerAnalytics'])
            ->middleware('permission:reports.view');
    });

    Route::group(['prefix' => 'medical-records'], function () {
        Route::get('/', [MedicalRecordController::class, 'index'])
            ->middleware('permission:medical-records.view');
        Route::post('/', [MedicalRecordController::class, 'store'])
            ->middleware('permission:medical-records.create');
        Route::get('/categories', [MedicalRecordController::class, 'getCategories'])
            ->middleware('permission:medical-records.view');
        Route::get('/stats', [MedicalRecordController::class, 'getStats'])
            ->middleware('permission:medical-records.view');
        Route::get('/expiring', [MedicalRecordController::class, 'getExpiringRecords'])
            ->middleware('permission:medical-records.view');
        Route::post('/bulk-update', [MedicalRecordController::class, 'bulkUpdate'])
            ->middleware('permission:medical-records.manage');
        Route::post('/bulk/delete', [MedicalRecordController::class, 'bulkDelete'])
            ->middleware('permission:medical-records.delete');
        Route::post('/bulk/export', [MedicalRecordController::class, 'bulkExport'])
            ->middleware('permission:medical-records.view');
        Route::post('/bulk/status', [MedicalRecordController::class, 'bulkStatus'])
            ->middleware('permission:medical-records.manage');
        Route::post('/send-reminders', [MedicalRecordController::class, 'sendReminders'])
            ->middleware('permission:medical-records.view');
        Route::get('/compliance-report', [MedicalRecordController::class, 'getComplianceReport'])
            ->middleware('permission:medical-records.view');
        Route::get('/compliance/{subjectType}/{subjectId}', [MedicalRecordController::class, 'getSubjectComplianceReport'])
            ->middleware('permission:medical-records.view');
        Route::get('/subject/{subjectType}/{subjectId}', [MedicalRecordController::class, 'getRecordsBySubject'])
            ->middleware('permission:medical-records.view');
        Route::get('/{id}/document', [MedicalRecordController::class, 'downloadDocument'])
            ->middleware('permission:medical-records.view');
        Route::post('/{id}/upload-document', [MedicalRecordController::class, 'uploadDocument'])
            ->middleware('permission:medical-records.edit');
        Route::post('/{id}/upload', [MedicalRecordController::class, 'uploadDocument'])
            ->middleware('permission:medical-records.edit');
        Route::get('/{id}', [MedicalRecordController::class, 'show'])
            ->middleware('permission:medical-records.view');
        Route::put('/{id}', [MedicalRecordController::class, 'update'])
            ->middleware('permission:medical-records.edit');
        Route::delete('/{id}', [MedicalRecordController::class, 'destroy'])
            ->middleware('permission:medical-records.delete');
    });

    Route::group(['prefix' => 'availability'], function () {
        Route::post('/check-vehicle', [AvailabilityController::class, 'checkVehicleAvailability'])
            ->middleware('permission:vehicle-availability.view');
        Route::post('/check-driver', [AvailabilityController::class, 'checkDriverAvailability'])
            ->middleware('permission:vehicle-availability.view');
        Route::get('/vehicles', [AvailabilityController::class, 'getAvailableVehicles'])
            ->middleware('permission:vehicle-availability.view');
        Route::post('/block-vehicle', [AvailabilityController::class, 'blockVehicle'])
            ->middleware('permission:vehicle-availability.manage');
        Route::delete('/blocks/{id}', [AvailabilityController::class, 'removeVehicleBlock'])
            ->middleware('permission:vehicle-availability.manage');
        Route::get('/calendar', [AvailabilityController::class, 'getAvailabilityCalendar'])
            ->middleware('permission:vehicle-availability.view');
    });

    /*
    |--------------------------------------------------------------------------
    | Agent Management Routes
    |--------------------------------------------------------------------------
    */

    Route::middleware(['permission:agents.view'])->group(function () {
        Route::get('agents/dashboard-stats', [AgentController::class, 'dashboardStats']);
        Route::get('agents/top-performers', [AgentController::class, 'topPerformers']);
        Route::get('agents/export', [AgentController::class, 'export']);
        Route::prefix('agents')->group(function () {
            Route::get('api-management/stats', [AgentApiController::class, 'stats']);
            Route::post('api-management/{agentApi}/revoke', [AgentApiController::class, 'revoke']);
            Route::post('api-management/{agentApi}/activate', [AgentApiController::class, 'activate']);
            Route::get('api-management/{agentApi}/usage', [AgentApiController::class, 'usage']);
            Route::get('api-management/{agentApi}/logs', [AgentApiController::class, 'logs']);
            Route::apiResource('api-management', AgentApiController::class)
                ->parameters(['api-management' => 'agentApi']);
            Route::apiResource('agent-api-sessions', AgentApiSessionController::class);
            Route::apiResource('agent-commissions', AgentCommissionController::class);
            Route::post('agent-commissions/settle', [AgentCommissionController::class, 'settle']);
            Route::get('{agentId}/commission-statement', [AgentCommissionController::class, 'statement']);
            Route::get('{agent}/statistics', [AgentController::class, 'statistics']);
            Route::put('{agent}/branding', [AgentController::class, 'updateBranding']);
            Route::post('{agent}/reset-password', [AgentController::class, 'resetPassword']);
        });
        Route::apiResource('agents', AgentController::class);
    });

    /*
    |--------------------------------------------------------------------------
    | Driver Management Routes
    |--------------------------------------------------------------------------
    */

    Route::middleware(['permission:drivers.view'])->group(function () {
        // Driver status and location endpoints (place specific routes before resource registration)
        Route::get('drivers/locations', [DriverController::class, 'locations']);
        Route::get('drivers/realtime-status', [DriverController::class, 'realTimeStatus']);
        Route::get('drivers/with-status', [\App\Http\Controllers\Api\Admin\BookingAssignmentController::class, 'driversWithStatus']);
        Route::get('driver-assignments/dashboard-stats', [DriverController::class, 'driverAssignmentDashboardStats']);
        Route::get('driver-assignments/active', [DriverController::class, 'activeDriverAssignments']);
        Route::get('driver-assignments/recent', [DriverController::class, 'recentDriverAssignments']);
        Route::get('drivers/{driver}/status', [DriverController::class, 'status']);
        Route::post('drivers/{driver}/test-notification', [DriverController::class, 'testNotification']);
        Route::get('drivers/{driver}/activity', [DriverController::class, 'activity']);
        Route::apiResource('drivers', DriverController::class);
        Route::get('logsheets/stats', [DriverLogController::class, 'stats']);
        Route::get('logsheets/dashboard', [DriverLogController::class, 'stats']);
        Route::get('logsheets', [DriverLogController::class, 'index']);
        Route::post('logsheets', [DriverLogController::class, 'store']);
        Route::post('logsheets/{driverLog}/assign', [DriverLogController::class, 'assign']);
        Route::post('logsheets/{driverLog}/verify', [DriverLogController::class, 'verify']);
        Route::post('logsheets/{driverLog}/cancel', [DriverLogController::class, 'cancel']);
        Route::get('logsheets/{driverLog}', [DriverLogController::class, 'show']);
        Route::put('logsheets/{driverLog}', [DriverLogController::class, 'update']);
        Route::delete('logsheets/{driverLog}', [DriverLogController::class, 'destroy']);
        Route::apiResource('driver-logs', DriverLogController::class);
        Route::apiResource('driver-batta-rules', DriverBattaRuleController::class);
        Route::get('driver-hire-settlements-dashboard', [DriverHireSettlementController::class, 'dashboard']);
        Route::post('driver-hire-settlements/{driverHireSettlement}/expenses', [DriverHireSettlementController::class, 'addExpense']);
        Route::put('driver-hire-settlements/{driverHireSettlement}/expenses/{expenseId}', [DriverHireSettlementController::class, 'updateExpense']);
        Route::post('driver-hire-settlements/{driverHireSettlement}/iou-advances', [DriverHireSettlementController::class, 'addIou']);
        Route::post('driver-hire-settlements/{driverHireSettlement}/submit', [DriverHireSettlementController::class, 'submit']);
        Route::post('driver-hire-settlements/{driverHireSettlement}/ops-review', [DriverHireSettlementController::class, 'opsReview']);
        Route::post('driver-hire-settlements/{driverHireSettlement}/finalize-accounts', [DriverHireSettlementController::class, 'finalizeAccounts']);
        Route::post('driver-hire-settlements/{driverHireSettlement}/mark-paid', [DriverHireSettlementController::class, 'markPaid']);
        Route::post('driver-hire-settlements/{driverHireSettlement}/mark-recovered', [DriverHireSettlementController::class, 'markRecovered']);
        Route::apiResource('driver-hire-settlements', DriverHireSettlementController::class);
        Route::get('drivers/{driver}/sessions', [DriverController::class, 'sessions']);
        Route::get('drivers/{driver}/sessions/{session}/route', [DriverController::class, 'sessionRoute']);
        Route::get('drivers/{driver}/movement-map', [DriverController::class, 'movementMap']);
        Route::get('drivers/{driver}/analytics', [DriverController::class, 'analytics']);

        // Default vehicle management for drivers
        Route::get('drivers/{driver}/default-vehicle', [\App\Http\Controllers\Api\Admin\BookingAssignmentController::class, 'getDriverDefaultVehicle']);
        Route::put('drivers/{driver}/default-vehicle', [\App\Http\Controllers\Api\Admin\BookingAssignmentController::class, 'updateDriverDefaultVehicle'])
            ->middleware('permission:drivers.edit');

        // Driver device management endpoints
        Route::get('drivers/{driver}/devices', [DriverController::class, 'devices']);
        Route::post('drivers/{driver}/devices/{deviceUuid}/deactivate', [DriverController::class, 'deactivateDevice'])
            ->middleware('permission:drivers.edit');
        Route::delete('drivers/{driver}/devices/{deviceUuid}', [DriverController::class, 'removeDevice'])
            ->middleware('permission:drivers.delete');
    });

    /*
    |--------------------------------------------------------------------------
    | Miscellaneous Routes
    |--------------------------------------------------------------------------
    */

    Route::middleware(['permission:system.view'])->group(function () {
        Route::get('galleries/stats', [ImageGalleryController::class, 'stats']);
        Route::get('galleries/search', [ImageGalleryController::class, 'search']);
        Route::get('galleries/categories', [ImageGalleryController::class, 'categories']);
        Route::apiResource('galleries', ImageGalleryController::class)->parameters(['galleries' => 'imageGallery']);
        Route::apiResource('image-galleries', ImageGalleryController::class);
        Route::put('inquiries/{inquiry}/assign', [InquiryController::class, 'assign']);
        Route::put('inquiries/{inquiry}/status', [InquiryController::class, 'updateStatus']);
        Route::post('inquiries/{inquiry}/respond', [InquiryController::class, 'respond']);
        Route::put('inquiries/{inquiry}/mark-read', [InquiryController::class, 'markRead']);
        Route::apiResource('inquiries', InquiryController::class);
        Route::post('notification-logs/{notificationLog}/retry', [NotificationLogController::class, 'retry']);
        Route::post('notifications/send-bulk', [NotificationLogController::class, 'sendBulk']);
        Route::apiResource('notification-logs', NotificationLogController::class);
        Route::post('notification-templates/{notification_template}/preview', [NotificationTemplateController::class, 'preview']);
        Route::post('notification-templates/{notification_template}/send-test', [NotificationTemplateController::class, 'sendTest']);
        Route::apiResource('notification-templates', NotificationTemplateController::class);
        Route::apiResource('phone-calls', PhoneCallController::class);
    });

    Route::middleware(['permission:agreements.view'])->group(function () {
        Route::get('agreements/stats', [AgreementController::class, 'stats']);
        Route::get('agreements/reports', [AgreementController::class, 'reports']);
        Route::get('agreements/reports/export', [AgreementController::class, 'exportReport']);
        Route::post('agreements/preview', [AgreementController::class, 'preview']);
        Route::get('agreement-templates', [AgreementController::class, 'templates']);
        Route::get('agreement-templates/{template}', [AgreementController::class, 'showTemplate']);
        Route::post('agreement-templates', [AgreementController::class, 'storeTemplate']);
        Route::put('agreement-templates/{template}', [AgreementController::class, 'updateTemplate']);
        Route::delete('agreement-templates/{template}', [AgreementController::class, 'destroyTemplate']);
        Route::get('agreements/{agreement}/documents', [AgreementController::class, 'documents']);
        Route::post('agreements/{agreement}/documents', [AgreementController::class, 'uploadDocument']);
        Route::get('agreements/{agreement}/pdf', [AgreementController::class, 'pdf']);
        Route::get('agreements/{agreement}/timeline', [AgreementController::class, 'timeline']);
        Route::post('agreements/{agreement}/duplicate', [AgreementController::class, 'duplicate']);
        Route::post('agreements/{agreement}/renew', [AgreementController::class, 'renew']);
        Route::post('agreements/{agreement}/verify-identity', [AgreementController::class, 'verifyIdentity']);
        Route::post('agreements/{agreement}/sign', [AgreementController::class, 'sign']);
        Route::post('agreements/{agreement}/email-signed', [AgreementController::class, 'emailSignedAgreement']);
        Route::post('agreements/bulk-update-status', [AgreementController::class, 'bulkUpdateStatus']);
        Route::post('agreements/bulk-delete', [AgreementController::class, 'bulkDelete']);
        Route::post('agreements/bulk-export', [AgreementController::class, 'bulkExport']);
        Route::apiResource('agreements', AgreementController::class);
    });

    /*
    |--------------------------------------------------------------------------
    | Booking Management Routes
    |--------------------------------------------------------------------------
    */

    Route::middleware(['permission:bookings.view'])->group(function () {

        Route::group(['prefix' => 'booking-flow',], function () {
            // Vehicle Availability Routes - Updated to match frontend service
            Route::get('vehicle-groups/availability', [BookingFlowController::class, 'getAvailableVehicleGroups'])
                ->middleware('permission:bookings.view');
            Route::get('vehicles/availability', [BookingFlowController::class, 'getAvailableVehiclesInGroup'])
                ->middleware('permission:bookings.view');
            Route::get('drivers/availability', [BookingFlowController::class, 'getAvailableDrivers'])
                ->middleware('permission:bookings.view');

            Route::get('/availability/vehicle', [BookingFlowController::class, 'getAvailableVehicleGroups']);
            Route::get('/availability/driver', [BookingFlowController::class, 'getAvailableDrivers']);


            // Conflict Checking Routes - Updated to match frontend service
            Route::post('vehicles/{vehicleId}/conflicts', [BookingFlowController::class, 'checkVehicleConflicts'])
                ->middleware('permission:bookings.create');
            Route::post('drivers/{driverId}/conflicts', [BookingFlowController::class, 'checkDriverConflicts'])
                ->middleware('permission:bookings.create');

            // Alternative route names for backward compatibility
            Route::post('check-vehicle-conflicts/{vehicleId}', [BookingFlowController::class, 'checkVehicleConflicts'])
                ->middleware('permission:bookings.create');
            Route::post('check-driver-conflicts/{driverId}', [BookingFlowController::class, 'checkDriverConflicts'])
                ->middleware('permission:bookings.create');

            // Pricing Routes - Updated to match frontend service
            Route::post('pricing/calculate', [BookingFlowController::class, 'calculatePricing'])
                ->middleware('permission:bookings.view');

            Route::get('currencies', [BookingFlowController::class, 'getAvailableCurrencies'])
                ->middleware('permission:bookings.view');

            Route::get('dynamic-pricing-adjustments', [BookingFlowController::class, 'getDynamicPricingAdjustments'])
                ->middleware('permission:bookings.view');

            // Add-ons Routes - Updated to match frontend service
            Route::get('addons/available', [BookingFlowController::class, 'getAvailableAddons'])
                ->middleware('permission:bookings.view');
            Route::post('addons/process-dependencies', [BookingFlowController::class, 'processAddonDependencies'])
                ->middleware('permission:bookings.create');

            // Alternative route names for backward compatibility
            Route::get('available-addons', [BookingFlowController::class, 'getAvailableAddons'])
                ->middleware('permission:bookings.view');
            Route::post('process-addon-dependencies', [BookingFlowController::class, 'processAddonDependencies'])
                ->middleware('permission:bookings.create');

            // Self-Driven Routes - Updated to match frontend service
            Route::get('customers/{customerId}/self-driven-eligibility', [BookingFlowController::class, 'validateSelfDrivenEligibility'])
                ->middleware('permission:bookings.view');
            Route::get('vehicles/{vehicleId}/self-driven-suitability', [BookingFlowController::class, 'getVehicleSelfDrivenSuitability'])
                ->middleware('permission:bookings.view');

            // Alternative route names for backward compatibility
            Route::get('validate-self-driven-eligibility/{customerId}', [BookingFlowController::class, 'validateSelfDrivenEligibility'])
                ->middleware('permission:bookings.view');
            Route::get('vehicle-self-driven-suitability/{vehicleId}', [BookingFlowController::class, 'getVehicleSelfDrivenSuitability'])
                ->middleware('permission:bookings.view');

            // Booking Submission Routes
            Route::post('submit-for-approval', [BookingFlowController::class, 'submitBookingForApproval'])
                ->middleware('permission:bookings.create');
            Route::post('confirm-booking', [BookingFlowController::class, 'confirmBooking'])
                ->middleware('permission:bookings.create');

            // Review & Confirmation Enhancement Routes
            Route::post('booking-summary', [BookingFlowController::class, 'getBookingSummary'])
                ->middleware('permission:bookings.view');

            // Draft Management Routes - Updated to match frontend service
            Route::post('save-draft', [BookingFlowController::class, 'saveBookingDraft'])
                ->middleware('permission:bookings.create');
            Route::post('request-quotation', [BookingFlowController::class, 'requestBookingQuotation'])
                ->middleware('permission:bookings.create');
            Route::get('draft/{draftId}', [BookingFlowController::class, 'loadBookingDraft'])
                ->middleware('permission:bookings.view');

            // Alternative route names for backward compatibility
            Route::get('load-draft/{draftId}', [BookingFlowController::class, 'loadBookingDraft'])
                ->middleware('permission:bookings.view');

            // Approval Workflow Routes - Updated to match frontend service
            Route::get('approval-status/{bookingId}', [BookingFlowController::class, 'getApprovalStatus'])
                ->middleware('permission:bookings.view');
            Route::post('request-approval/{bookingId}', [BookingFlowController::class, 'requestManagerApproval'])
                ->middleware('permission:bookings.approve');

            // Alternative route names for backward compatibility
            Route::post('request-manager-approval/{bookingId}', [BookingFlowController::class, 'requestManagerApproval'])
                ->middleware('permission:bookings.approve');

            // Variable Customization Routes - NEW
            Route::get('variables/customizable', [BookingFlowController::class, 'getCustomizableVariables'])
                ->middleware('permission:bookings.view');
            Route::post('variables/customizations', [BookingFlowController::class, 'storeVariableCustomizations'])
                ->middleware('permission:bookings.create');
            Route::get('variables/customizations', [BookingFlowController::class, 'getVariableCustomizations'])
                ->middleware('permission:bookings.view');

            // Enhanced Assignment Management Routes
            Route::post('select-vehicle-assignment', [BookingFlowController::class, 'selectVehicleWithAssignmentDetails'])
                ->middleware('permission:bookings.view');
            Route::post('alternative-assignments', [BookingFlowController::class, 'getAlternativeAssignments'])
                ->middleware('permission:bookings.view');
            Route::post('process-assignment-confirmation', [BookingFlowController::class, 'processAssignmentConfirmation'])
                ->middleware('permission:bookings.create');
            Route::post('approve-assignments', [BookingFlowController::class, 'approveAssignments'])
                ->middleware('permission:bookings.approve');

            Route::post('validate-rules', [BookingFlowController::class, 'validateBookingRules'])
                ->middleware('permission:bookings.view');
            Route::post('alternatives', [BookingFlowController::class, 'getAlternativeSuggestions'])
                ->middleware('permission:bookings.view');

            // Alternative route names for backward compatibility
            Route::post('validate-booking-rules', [BookingFlowController::class, 'validateBookingRules'])
                ->middleware('permission:bookings.view');
            Route::get('alternative-suggestions', [BookingFlowController::class, 'getAlternativeSuggestions'])
                ->middleware('permission:bookings.view');

            // Confirmation Routes - Updated to match frontend service
            Route::post('generate-confirmation/{bookingId}', [BookingFlowController::class, 'generateBookingConfirmation'])
                ->middleware('permission:bookings.view');

            // Corporate booking context routes
            Route::get('corporates', [BookingFlowController::class, 'getCorporates'])
                ->middleware('permission:bookings.create');
            Route::get('corporates/{corporateId}/departments', [BookingFlowController::class, 'getCorporateDepartments'])
                ->middleware('permission:bookings.create');
            Route::post('corporates/{corporateId}/departments', [BookingFlowController::class, 'createCorporateDepartment'])
                ->middleware('permission:bookings.create');
            Route::get('corporates/{corporateId}/departments/{departmentId}/divisions', [BookingFlowController::class, 'getCorporateDivisions'])
                ->middleware('permission:bookings.create');
            Route::post('corporates/{corporateId}/departments/{departmentId}/divisions', [BookingFlowController::class, 'createCorporateDivision'])
                ->middleware('permission:bookings.create');
            Route::get('corporates/{corporateId}/employees', [BookingFlowController::class, 'getCorporateEmployees'])
                ->middleware('permission:bookings.create');
            Route::post('corporates/{corporateId}/employees', [BookingFlowController::class, 'createCorporateEmployee'])
                ->middleware('permission:bookings.create');
            // Company/System Routes
            Route::get('company/locations', [BookingFlowController::class, 'getCompanyLocations'])
                ->middleware('permission:bookings.view');

            // Route Calculation for Multiple Locations
            Route::post('calculate-route', [BookingFlowController::class, 'calculateRoute'])
                ->middleware('permission:bookings.view');

            Route::get('edit/{id}', [BookingFlowController::class, 'getBookingForEdit']);

            // Update booking and edit history routes expected by frontend
            Route::put('update/{bookingId}', [BookingFlowController::class, 'updateBooking']);
            // ->middleware('permission:bookings.update');
            Route::get('edit-history/{bookingId}', [BookingFlowController::class, 'getBookingEditHistory'])
                ->middleware('permission:bookings.view');
            Route::post('clone/{bookingId}', [BookingFlowController::class, 'cloneBooking'])
                ->middleware('permission:bookings.create');

            // Pricing override/discount routes expected by frontend

            Route::post('pricing/gamify-discount', [BookingFlowController::class, 'applyGamifyDiscount'])
                ->middleware('permission:bookings.update');
            Route::post('pricing/recalculate/{bookingId}', [BookingFlowController::class, 'calculatePricing'])
                ->middleware('permission:bookings.view');

            // Enhanced Discount & Loyalty Management Routes
            Route::get('discounts/customer-loyalty/{customerId}', [BookingFlowController::class, 'getCustomerLoyaltyInfo'])
                ->middleware('permission:bookings.view');
            Route::post('discounts/remove', [BookingFlowController::class, 'removeDiscount'])
                ->middleware('permission:bookings.update');
            Route::get('discounts/booking-summary/{bookingId}', [BookingFlowController::class, 'getBookingDiscountSummary'])
                ->middleware('permission:bookings.view');
            Route::post('loyalty/process-earning', [BookingFlowController::class, 'processLoyaltyPointsEarning'])
                ->middleware('permission:bookings.update');

            // Approval details and processing routes
            Route::get('approval/details/{bookingId}', [BookingFlowController::class, 'getBookingApprovalDetails'])
                ->middleware('permission:bookings.view');
            Route::post('approval/process', [BookingFlowController::class, 'processBookingApproval'])
                ->middleware('permission:bookings.approve');
            Route::post('bookings/{bookingId}/status', [BookingFlowController::class, 'updateBookingStatus'])
                ->middleware('permission:bookings.update');

            // ========================
            // BOOKING LIST MANAGEMENT
            // ========================

            // Advanced booking list with filtering
            Route::get('bookings', [BookingFlowController::class, 'getBookingsList'])
                ->middleware('permission:bookings.view');
            Route::get('bookings/{bookingId}', [BookingFlowController::class, 'getBookingDetails'])
                ->middleware('permission:bookings.view');
            Route::post('bookings/{bookingId}/recurring/cancel', [BookingFlowController::class, 'cancelRecurringBooking'])
                ->middleware('permission:bookings.delete');
            Route::delete('bookings/{bookingId}', [BookingFlowController::class, 'deleteBooking'])
                ->middleware('permission:bookings.delete');

            // Bulk operations
            Route::post('bookings/bulk-operations', [BookingFlowController::class, 'bulkOperations'])
                ->middleware('permission:bookings.manage');

            // ========================
            // DASHBOARD & ANALYTICS
            // ========================

            // Dashboard statistics
            Route::get('dashboard/stats', [BookingFlowController::class, 'getDashboardStats'])
                ->middleware('permission:dashboard.view');

            // Trending and analytics
            Route::get('analytics/trends', [BookingFlowController::class, 'getBookingTrends'])
                ->middleware('permission:analytics.view');
            Route::get('analytics/revenue', [BookingFlowController::class, 'getRevenueAnalytics'])
                ->middleware('permission:analytics.view');
            Route::get('analytics/utilization', [BookingFlowController::class, 'getUtilizationReports'])
                ->middleware('permission:analytics.view');
            Route::get('analytics/customers', [BookingFlowController::class, 'getCustomerAnalytics'])
                ->middleware('permission:analytics.view');

            // Reports and exports
            Route::post('reports/generate', [BookingFlowController::class, 'generateReport'])
                ->middleware('permission:reports.generate');
            Route::post('exports/bookings', [BookingFlowController::class, 'exportBookings'])
                ->middleware('permission:exports.create');

            // Individual booking analytics
            Route::get('bookings/{bookingId}/analytics', [BookingFlowController::class, 'getBookingAnalytics'])
                ->middleware('permission:bookings.view');
        });

        // Assignment Management Routes
        Route::group(['prefix' => 'assignments'], function () {
            Route::get('{bookingId}/details', [AssignmentController::class, 'getAssignmentDetails'])
                ->middleware('permission:bookings.view');
            Route::post('swap', [AssignmentController::class, 'performSwap'])
                ->middleware('permission:bookings.update');
            Route::post('breakdown', [AssignmentController::class, 'recordBreakdown'])
                ->middleware('permission:bookings.update');
        });

        // Booking Item Assignment (inline from booking list)
        Route::post('booking-items/{bookingItem}/assign', [\App\Http\Controllers\Api\Admin\BookingAssignmentController::class, 'createBookingAssignment'])
            ->middleware('permission:bookings.create');

        // Booking Lifecycle Management Routes
        Route::group(['prefix' => 'booking-lifecycle'], function () {
            Route::get('{bookingId}/summary', [BookingLifecycleController::class, 'getLifecycleSummary'])
                ->middleware('permission:bookings.view');
            Route::get('{bookingId}/ongoing-details', [BookingLifecycleController::class, 'getOngoingDetails'])
                ->middleware('permission:bookings.view');
            Route::get('{bookingId}/dispatch-details', [BookingLifecycleController::class, 'getDispatchDetails'])
                ->middleware('permission:bookings.view');
            Route::get('{bookingId}/qc-details', [BookingLifecycleController::class, 'getQCDetails'])
                ->middleware('permission:bookings.view');
            Route::get('inspectors/available', [BookingLifecycleController::class, 'getAvailableInspectors'])
                ->middleware('permission:bookings.view');

            Route::post('dispatch-vehicle', [BookingLifecycleController::class, 'dispatchVehicle'])
                ->middleware('permission:bookings.dispatch');
            Route::post('process-return', [BookingLifecycleController::class, 'processReturn'])
                ->middleware('permission:bookings.process_return');
            Route::post('start-qc-inspection', [BookingLifecycleController::class, 'startQCInspection'])
                ->middleware('permission:bookings.qc_inspect');
            Route::post('complete-qc-inspection', [BookingLifecycleController::class, 'completeQCInspection'])
                ->middleware('permission:bookings.qc_inspect');
            Route::post('complete-repairs', [BookingLifecycleController::class, 'completeRepairs'])
                ->middleware('permission:bookings.complete_repairs');
            Route::post('complete-booking', [BookingLifecycleController::class, 'completeBooking'])
                ->middleware('permission:bookings.complete');
            Route::post('check-vehicle-availability', [BookingLifecycleController::class, 'checkVehicleAvailability'])
                ->middleware('permission:bookings.view');
            Route::get('maintenance-blocks', [BookingLifecycleController::class, 'getMaintenanceBlocks'])
                ->middleware('permission:bookings.view');
            Route::post('update-availability-pool', [BookingLifecycleController::class, 'updateAvailabilityPool'])
                ->middleware('permission:bookings.update');
        });


        Route::group([
            'prefix' => 'public/booking-flow',
            'middleware' => ['throttle:60,1'], // Rate limiting for public endpoints
        ], function () {

            // Public availability checking (with limited data)
            Route::get('check-availability', [BookingFlowController::class, 'getAvailableVehicleGroups']);
            Route::post('estimate-pricing', [BookingFlowController::class, 'calculatePricing']);


            // Self-service booking submission
            Route::post('submit-booking-request', [BookingFlowController::class, 'submitBookingForApproval']);
        });
        Route::apiResource('booking-channels', BookingChannelController::class);
        Route::apiResource('booking-statuses', BookingStatusController::class);
    });

    Route::group([
        'prefix' => 'admin/booking-flow',
        'middleware' => ['role:admin,manager'],
    ], function () {

        // Advanced administrative features
        Route::get('system-availability-overview', [BookingFlowController::class, 'getAvailableVehicleGroups']);
        Route::get('approval-queue', [BookingFlowController::class, 'getApprovalStatus']);

        // Bulk operations
        Route::post('bulk-pricing-updates', [BookingFlowController::class, 'calculatePricing']);
        Route::post('bulk-approval-processing', [BookingFlowController::class, 'requestManagerApproval']);

        // System configuration
        Route::post('update-dynamic-pricing-rules', [BookingFlowController::class, 'getDynamicPricingAdjustments']);
        Route::post('configure-approval-workflows', [BookingFlowController::class, 'getApprovalStatus']);
    });

    Route::prefix('places')->group(function () {
        Route::get('search', [GooglePlacesController::class, 'searchPlaces']);
        Route::get('airports', [GooglePlacesController::class, 'searchAirports']);
        Route::get('details', [GooglePlacesController::class, 'getPlaceDetails']);
        Route::get('reverse-geocode', [GooglePlacesController::class, 'reverseGeocode']);
    });

    Route::group([
        'prefix' => 'mobile/booking-flow',
    ], function () {

        // Mobile-optimized endpoints
        Route::get('quick-availability', [BookingFlowController::class, 'getAvailableVehicleGroups'])
            ->middleware('permission:bookings.view');
        Route::post('quick-booking', [BookingFlowController::class, 'confirmBooking'])
            ->middleware('permission:bookings.create');

        // Mobile-specific features
        Route::post('location-based-suggestions', [BookingFlowController::class, 'getAlternativeSuggestions'])
            ->middleware('permission:bookings.view');
        Route::get('nearby-vehicles', [BookingFlowController::class, 'getAvailableVehiclesInGroup'])
            ->middleware('permission:bookings.view');
        Route::post('instant-booking', [BookingFlowController::class, 'confirmBooking'])
            ->middleware('permission:bookings.create');
    });


    Route::group([
        'prefix' => 'integration/booking-flow',
        'middleware' => ['throttle:api'],
    ], function () {

        // API for external booking systems
        Route::post('external-availability-check', [BookingFlowController::class, 'getAvailableVehicleGroups'])
            ->middleware('permission:api.external');
        Route::post('external-pricing-quote', [BookingFlowController::class, 'calculatePricing'])
            ->middleware('permission:api.external');
        Route::post('external-booking-submission', [BookingFlowController::class, 'submitBookingForApproval'])
            ->middleware('permission:api.external');

        // Webhook endpoints
        Route::post('booking-status-webhook', [BookingFlowController::class, 'getApprovalStatus'])
            ->middleware('permission:api.webhooks');
        Route::post('pricing-update-webhook', [BookingFlowController::class, 'getDynamicPricingAdjustments'])
            ->middleware('permission:api.webhooks');
    });


    if (app()->environment(['local', 'staging'])) {
        Route::group([
            'prefix' => 'dev/booking-flow',
        ], function () {

            // Development utilities
            Route::get('test-availability', [BookingFlowController::class, 'getAvailableVehicleGroups']);
            Route::post('test-pricing', [BookingFlowController::class, 'calculatePricing']);
            Route::post('test-conflicts/{vehicleId}', [BookingFlowController::class, 'checkVehicleConflicts']);
            Route::post('test-approval-flow', [BookingFlowController::class, 'submitBookingForApproval']);

            // Mock data endpoints
            Route::get('mock-vehicle-data', [BookingFlowController::class, 'getAvailableVehiclesInGroup']);
            Route::get('mock-driver-data', [BookingFlowController::class, 'getAvailableDrivers']);
        });
    }
    /*
    |--------------------------------------------------------------------------
    | Gamification Routes
    |--------------------------------------------------------------------------
    */

    Route::prefix('users/{user}')->middleware(['permission:gamification.view'])->group(function () {
        Route::get('points', [GamificationController::class, 'getUserPoints']);
        Route::get('reputation', [GamificationController::class, 'getUserReputation']);
        Route::post('points/give', [GamificationController::class, 'givePoints'])->middleware('permission:gamification.give-points');
        Route::post('points/undo', [GamificationController::class, 'undoPoints'])->middleware('permission:gamification.undo-points');
        Route::post('points/reset', [GamificationController::class, 'resetPoints'])->middleware('permission:gamification.reset-points');
        Route::get('badges', [GamificationController::class, 'getUserBadges']);
        Route::get('rank', [GamificationController::class, 'getUserRank']);
    });

    Route::middleware(['permission:gamification.view'])->group(function () {
        Route::get('badges', [GamificationController::class, 'getAllBadges']);
        Route::get('badges/stats', [GamificationController::class, 'getBadgeStats'])->middleware('permission:gamification.stats');
        Route::get('badges/{id}', [GamificationController::class, 'getBadgeDetails'])->whereNumber('id');
        Route::get('leaderboard', [GamificationController::class, 'getLeaderboard']);
        Route::get('reputation/stats', [GamificationController::class, 'getReputationStats'])->middleware('permission:gamification.stats');
        Route::post('points/bulk-give', [GamificationController::class, 'bulkGivePoints'])->middleware('permission:gamification.bulk-give-points');
    });

    /*
    |--------------------------------------------------------------------------
    | Loyalty Program Routes
    |--------------------------------------------------------------------------
    */

    Route::prefix('customers/{customer}/loyalty')->middleware(['permission:loyalty.view|customers.loyalty'])->group(function () {
        Route::get('points', [LoyaltyController::class, 'getCustomerLoyaltyPoints']);
        Route::get('tier', [LoyaltyController::class, 'getCustomerLoyaltyTier']);
        Route::get('history', [LoyaltyController::class, 'getCustomerLoyaltyHistory']);
        Route::post('redeem', [LoyaltyController::class, 'redeemPoints'])->middleware('permission:loyalty.redeem');
    });

    Route::middleware(['permission:loyalty.view|customers.loyalty'])->group(function () {
        Route::get('customers/loyalty/tiers', [LoyaltyController::class, 'getLoyaltyTiers']);
        Route::get('customers/loyalty/stats', [LoyaltyController::class, 'getLoyaltyStats']);
        Route::get('customers/loyalty/rewards', [LoyaltyController::class, 'getLoyaltyRewards']);
        Route::post('customers/loyalty/rewards', [LoyaltyController::class, 'storeReward'])->middleware('permission:customers.loyalty');
        Route::put('customers/loyalty/rewards/{reward}', [LoyaltyController::class, 'updateReward'])->middleware('permission:customers.loyalty');
        Route::delete('customers/loyalty/rewards/{reward}', [LoyaltyController::class, 'deleteReward'])->middleware('permission:customers.loyalty');
        Route::post('customers/loyalty/rewards/{reward}/status', [LoyaltyController::class, 'updateRewardStatus'])->middleware('permission:customers.loyalty');
        Route::get('customers/loyalty/rewards/{reward}/redemptions', [LoyaltyController::class, 'getRewardRedemptions']);
        Route::get('customers/loyalty/activity', [LoyaltyController::class, 'getLoyaltyActivity']);
    });

    /*
    |--------------------------------------------------------------------------
    | Analytics Routes
    |--------------------------------------------------------------------------
    */

    Route::prefix('analytics')->middleware(['permission:analytics.view'])->group(function () {
        Route::get('dashboard', [AnalyticsController::class, 'getDashboardStats'])->middleware('permission:analytics.dashboard');
        Route::get('booking-trends', [AnalyticsController::class, 'getBookingTrends'])->middleware('permission:analytics.bookings');
        Route::get('revenue-stats', [AnalyticsController::class, 'getRevenueStats'])->middleware('permission:analytics.revenue');
        Route::get('customer-analytics', [AnalyticsController::class, 'getCustomerAnalytics'])->middleware('permission:analytics.customers');
        Route::get('driver-performance', [AnalyticsController::class, 'getDriverPerformance'])->middleware('permission:analytics.drivers');
        Route::get('vehicle-utilization', [AnalyticsController::class, 'getVehicleUtilization'])->middleware('permission:analytics.vehicles');
        Route::get('agent-performance', [AnalyticsController::class, 'getAgentPerformance'])->middleware('permission:analytics.agents');
    });

    /*
    |--------------------------------------------------------------------------
    | Notification Routes
    |--------------------------------------------------------------------------
    */

    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'getUserNotifications']);
        Route::post('mark-read/{id}', [NotificationController::class, 'markAsRead']);
        Route::post('mark-all-read', [NotificationController::class, 'markAllAsRead']);
        Route::delete('{id}', [NotificationController::class, 'deleteNotification']);
        Route::get('unread-count', [NotificationController::class, 'getUnreadCount']);
        Route::post('send', [NotificationController::class, 'sendNotification'])->middleware('permission:notifications.send');
        Route::post('broadcast', [NotificationController::class, 'broadcastNotification'])->middleware('permission:notifications.broadcast');
        Route::post('schedule', [NotificationController::class, 'scheduleNotification'])->middleware('permission:notifications.schedule');
        Route::post('email', [NotificationController::class, 'sendEmailNotification'])->middleware('permission:notifications.email');
        Route::get('email-templates', [NotificationController::class, 'getEmailTemplates'])->middleware('permission:notifications.templates');
        Route::post('email-templates', [NotificationController::class, 'createEmailTemplate'])->middleware('permission:notifications.create-template');
    });

    /*
    |--------------------------------------------------------------------------
    | File Upload Routes
    |--------------------------------------------------------------------------
    */



    Route::prefix('files')->group(function () {
        Route::post('/upload', [FileUploadController::class, 'uploadFile'])
            ->name('api.files.upload');

        // Get file details
        Route::get('/{id}', [FileUploadController::class, 'getFile'])
            ->name('api.files.show');

        // Delete file
        Route::delete('/{id}', [FileUploadController::class, 'deleteFile'])
            ->name('api.files.delete');

        // Serve/proxy file content (for private files or S3 proxying)
        Route::get('/serve/{path}', [FileUploadController::class, 'serveFile'])
            ->where('path', '.*')
            ->name('api.files.serve');
    });

    /*
    |--------------------------------------------------------------------------
    | Payment Routes
    |--------------------------------------------------------------------------
    */

    Route::prefix('payments')->group(function () {
        Route::post('initiate', [PaymentController::class, 'initiatePayment'])->middleware('permission:payments.initiate');
        Route::post('callback', [PaymentController::class, 'paymentCallback'])->middleware('permission:payments.callback');
        Route::get('{id}/status', [PaymentController::class, 'getPaymentStatus'])->middleware('permission:payments.view');
        Route::post('{id}/refund', [PaymentController::class, 'refundPayment'])->middleware('permission:payments.refund');
        Route::get('methods', [PaymentController::class, 'getPaymentMethods'])->middleware('permission:payments.methods');
    });

    Route::prefix('payment-transactions')->middleware(['permission:payments.transactions'])->group(function () {
        Route::get('/', [PaymentController::class, 'getPaymentTransactions']);
        Route::get('{id}', [PaymentController::class, 'getTransactionDetails']);
        Route::post('{id}/refund', [PaymentController::class, 'refundTransaction'])->middleware('permission:payments.refund');
    });

    // ── Invoice Routes ────────────────────────────────────────────────────────
    Route::prefix('invoices')->group(function () {
        Route::get('/', [InvoiceController::class, 'adminIndex']);
        Route::get('{id}', [InvoiceController::class, 'show']);
        Route::get('{id}/download', [InvoiceController::class, 'download']);
        Route::post('{id}/regenerate', [InvoiceController::class, 'regenerate']);
        Route::post('{id}/send', [InvoiceController::class, 'send']);
        Route::post('{id}/void', [InvoiceController::class, 'void']);
    });
    Route::prefix('bookings/{bookingId}/invoices')->group(function () {
        Route::get('/', [InvoiceController::class, 'index']);
        Route::post('generate', [InvoiceController::class, 'generate']);
    });

    /*
    |--------------------------------------------------------------------------
    | Corporate Portal Routes
    |--------------------------------------------------------------------------
    |
    | These routes are for corporate portal users (employees, coordinators,
    | admins). All routes are scoped to the authenticated user's corporate
    | via the EnsureCorporateContext middleware.
    |
    */

    Route::prefix('corporate')->middleware(['ensure.corporate'])->group(function () {
        // Department Management
        Route::get('departments', [\App\Http\Controllers\Api\Corporate\CorporateDepartmentController::class, 'index']);
        Route::post('departments', [\App\Http\Controllers\Api\Corporate\CorporateDepartmentController::class, 'store']);
        Route::put('departments/{id}', [\App\Http\Controllers\Api\Corporate\CorporateDepartmentController::class, 'update']);
        Route::delete('departments/{id}', [\App\Http\Controllers\Api\Corporate\CorporateDepartmentController::class, 'destroy']);

        // Division Management (nested under departments for index/store)
        Route::get('departments/{department}/divisions', [\App\Http\Controllers\Api\Corporate\CorporateDivisionController::class, 'index']);
        Route::post('departments/{department}/divisions', [\App\Http\Controllers\Api\Corporate\CorporateDivisionController::class, 'store']);
        Route::put('divisions/{id}', [\App\Http\Controllers\Api\Corporate\CorporateDivisionController::class, 'update']);
        Route::delete('divisions/{id}', [\App\Http\Controllers\Api\Corporate\CorporateDivisionController::class, 'destroy']);

        // Employee Management
        Route::get('employees', [\App\Http\Controllers\Api\Corporate\CorporateEmployeeController::class, 'index']);
        Route::post('employees', [\App\Http\Controllers\Api\Corporate\CorporateEmployeeController::class, 'store']);
        Route::get('employees/{id}', [\App\Http\Controllers\Api\Corporate\CorporateEmployeeController::class, 'show']);
        Route::put('employees/{id}', [\App\Http\Controllers\Api\Corporate\CorporateEmployeeController::class, 'update']);
        Route::post('employees/{id}/activate', [\App\Http\Controllers\Api\Corporate\CorporateEmployeeController::class, 'activate']);
        Route::post('employees/{id}/deactivate', [\App\Http\Controllers\Api\Corporate\CorporateEmployeeController::class, 'deactivate']);
        Route::post('employees/{id}/role', [\App\Http\Controllers\Api\Corporate\CorporateEmployeeController::class, 'assignRole']);

        // Role Management
        Route::get('roles', [\App\Http\Controllers\Api\Corporate\CorporateRoleController::class, 'index']);
        Route::post('roles', [\App\Http\Controllers\Api\Corporate\CorporateRoleController::class, 'store']);
        Route::put('roles/{id}', [\App\Http\Controllers\Api\Corporate\CorporateRoleController::class, 'update']);
        Route::delete('roles/{id}', [\App\Http\Controllers\Api\Corporate\CorporateRoleController::class, 'destroy']);
        Route::get('permissions', [\App\Http\Controllers\Api\Corporate\CorporateRoleController::class, 'permissions']);

        // Corporate Profile
        Route::get('profile', function (\Illuminate\Http\Request $request) {
            $corporate = \App\Models\Corporate\Corporate::findOrFail($request->corporate_id);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'corporate' => [
                        'id' => $corporate->id,
                        'name' => $corporate->name,
                        'contact_email' => $corporate->contact_email,
                        'contact_phone' => $corporate->contact_phone,
                        'billing_address' => $corporate->billing_address,
                        'is_active' => $corporate->is_active,
                        'approval_required' => $corporate->approval_required,
                        'exempt_coordinator_from_approval' => $corporate->exempt_coordinator_from_approval,
                        'coordinator_can_view_payments' => $corporate->coordinator_can_view_payments,
                    ],
                ],
            ]);
        });

        Route::get('service-types', function (\Illuminate\Http\Request $request) {
            $corporate = \App\Models\Corporate\Corporate::findOrFail($request->corporate_id);
            $serviceTypes = $corporate->serviceTypes()
                ->where('service_types.is_active', true)
                ->orderBy('service_types.priority')
                ->orderBy('service_types.name')
                ->get();

            return \App\Http\Resources\ServiceTypeResource::collection($serviceTypes);
        });

        // Staff Transport
        Route::prefix('staff-transport')->group(function () {
            Route::get('programs', [\App\Http\Controllers\Api\Corporate\CorporateStaffTransportController::class, 'programs']);
            Route::post('programs', [\App\Http\Controllers\Api\Corporate\CorporateStaffTransportController::class, 'storeProgram']);
            Route::put('programs/{program}', [\App\Http\Controllers\Api\Corporate\CorporateStaffTransportController::class, 'updateProgram']);

            Route::get('programs/{program}/shifts', [\App\Http\Controllers\Api\Corporate\CorporateStaffTransportController::class, 'shifts']);
            Route::post('programs/{program}/shifts', [\App\Http\Controllers\Api\Corporate\CorporateStaffTransportController::class, 'storeShift']);
            Route::put('programs/{program}/shifts/{shift}', [\App\Http\Controllers\Api\Corporate\CorporateStaffTransportController::class, 'updateShift']);
            Route::delete('programs/{program}/shifts/{shift}', [\App\Http\Controllers\Api\Corporate\CorporateStaffTransportController::class, 'deleteShift']);

            Route::get('programs/{program}/routes', [\App\Http\Controllers\Api\Corporate\CorporateStaffTransportController::class, 'routes']);
            Route::post('programs/{program}/routes', [\App\Http\Controllers\Api\Corporate\CorporateStaffTransportController::class, 'storeRoute']);
            Route::put('programs/{program}/routes/{route}', [\App\Http\Controllers\Api\Corporate\CorporateStaffTransportController::class, 'updateRoute']);
            Route::delete('programs/{program}/routes/{route}', [\App\Http\Controllers\Api\Corporate\CorporateStaffTransportController::class, 'deleteRoute']);

            Route::get('programs/{program}/routes/{route}/members', [\App\Http\Controllers\Api\Corporate\CorporateStaffTransportController::class, 'members']);
            Route::post('programs/{program}/routes/{route}/members', [\App\Http\Controllers\Api\Corporate\CorporateStaffTransportController::class, 'storeMember']);
            Route::put('programs/{program}/routes/{route}/members/{member}', [\App\Http\Controllers\Api\Corporate\CorporateStaffTransportController::class, 'updateMember']);
            Route::delete('programs/{program}/routes/{route}/members/{member}', [\App\Http\Controllers\Api\Corporate\CorporateStaffTransportController::class, 'deleteMember']);

            Route::post('roster/build', [\App\Http\Controllers\Api\Corporate\CorporateStaffTransportController::class, 'buildRoster']);
            Route::get('roster', [\App\Http\Controllers\Api\Corporate\CorporateStaffTransportController::class, 'roster']);
            Route::get('my-calendar', [\App\Http\Controllers\Api\Corporate\CorporateStaffTransportController::class, 'myCalendar']);
            Route::post('my-calendar/{participation}/status', [\App\Http\Controllers\Api\Corporate\CorporateStaffTransportController::class, 'setMyParticipation']);
            Route::post('roster/{participation}/status', [\App\Http\Controllers\Api\Corporate\CorporateStaffTransportController::class, 'setParticipation']);
            Route::post('generate', [\App\Http\Controllers\Api\Corporate\CorporateStaffTransportController::class, 'generate']);
            Route::get('logs', [\App\Http\Controllers\Api\Corporate\CorporateStaffTransportController::class, 'logs']);
            Route::get('generated-bookings/{booking}', [\App\Http\Controllers\Api\Corporate\CorporateStaffTransportController::class, 'generatedBooking']);
        });

        // Booking Management
        Route::get('bookings/my', [\App\Http\Controllers\Api\Corporate\CorporateBookingController::class, 'myBookings']);
        Route::get('bookings/export', [\App\Http\Controllers\Api\Corporate\CorporateBookingController::class, 'export']);
        Route::get('bookings/stats', [\App\Http\Controllers\Api\Corporate\CorporateBookingController::class, 'stats']);
        Route::get('bookings', [\App\Http\Controllers\Api\Corporate\CorporateBookingController::class, 'index']);
        Route::post('bookings', [\App\Http\Controllers\Api\Corporate\CorporateBookingController::class, 'store']);
        Route::post('bookings/for-employee', [\App\Http\Controllers\Api\Corporate\CorporateBookingController::class, 'storeForEmployee']);
        Route::post('bookings/{id}/recurring/cancel', [\App\Http\Controllers\Api\Corporate\CorporateBookingController::class, 'cancelRecurring']);
        Route::get('bookings/{id}/live-progress', [\App\Http\Controllers\Api\Corporate\CorporateBookingController::class, 'liveProgress']);
        Route::post('bookings/{id}/contractual-distance-override', [\App\Http\Controllers\Api\Corporate\CorporateBookingController::class, 'overrideContractualDistance'])
            ->middleware('permission:approve_bookings');
        Route::get('bookings/{id}', [\App\Http\Controllers\Api\Corporate\CorporateBookingController::class, 'show']);

        // Approval Management
        Route::get('approvals', [\App\Http\Controllers\Api\Corporate\CorporateApprovalController::class, 'index']);
        Route::post('approvals/{bookingId}/approve', [\App\Http\Controllers\Api\Corporate\CorporateApprovalController::class, 'approve']);
        Route::post('approvals/{bookingId}/reject', [\App\Http\Controllers\Api\Corporate\CorporateApprovalController::class, 'reject']);

        // Audit Log
        Route::get('audit-logs', [\App\Http\Controllers\Api\Corporate\CorporateAuditLogController::class, 'index']);

        // Reports
        Route::get('reports/booking-history', [\App\Http\Controllers\Api\Corporate\CorporateReportController::class, 'bookingHistory']);
        Route::get('reports/summary-stats', [\App\Http\Controllers\Api\Corporate\CorporateReportController::class, 'summaryStats']);
        Route::get('reports/export', [\App\Http\Controllers\Api\Corporate\CorporateReportController::class, 'exportCsv']);

        // Vehicle Groups (read-only for corporate users)
        Route::get('vehicle-groups', function (\Illuminate\Http\Request $request) {
            $corporate = \App\Models\Corporate\Corporate::findOrFail($request->corporate_id);
            $vehicleGroups = $corporate->vehicleGroups()->get();

            return response()->json([
                'status' => 'success',
                'data' => ['vehicle_groups' => $vehicleGroups],
            ]);
        });
    });

    /*
    |--------------------------------------------------------------------------
    | System Admin — Corporate Management Routes
    |--------------------------------------------------------------------------
    */

    Route::prefix('admin/corporates')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\Corporate\CorporateController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\Api\Corporate\CorporateController::class, 'store']);
        Route::get('{corporate}', [\App\Http\Controllers\Api\Corporate\CorporateController::class, 'show']);
        Route::put('{corporate}', [\App\Http\Controllers\Api\Corporate\CorporateController::class, 'update']);
        Route::post('{corporate}/activate', [\App\Http\Controllers\Api\Corporate\CorporateController::class, 'activate']);
        Route::post('{corporate}/deactivate', [\App\Http\Controllers\Api\Corporate\CorporateController::class, 'deactivate']);
        Route::post('{corporate}/vehicle-groups', [\App\Http\Controllers\Api\Corporate\CorporateController::class, 'assignVehicleGroups']);
        Route::delete('{corporate}/vehicle-groups/{vehicleGroupId}', [\App\Http\Controllers\Api\Corporate\CorporateController::class, 'removeVehicleGroup']);
        Route::get('{corporate}/service-types', [\App\Http\Controllers\Api\Corporate\CorporateController::class, 'serviceTypes'])
            ->middleware('permission:corporates.view');
        Route::post('{corporate}/service-types', [\App\Http\Controllers\Api\Corporate\CorporateController::class, 'assignServiceTypes'])
            ->middleware('permission:corporates.manage');
        Route::post('{corporate}/initial-admin', [\App\Http\Controllers\Api\Corporate\CorporateController::class, 'createInitialAdmin']);

        Route::get('{corporate}/distance-pricing-policy', [\App\Http\Controllers\Api\Corporate\CorporateDistancePolicyController::class, 'show']);
        Route::put('{corporate}/distance-pricing-policy', [\App\Http\Controllers\Api\Corporate\CorporateDistancePolicyController::class, 'update']);
        Route::get('{corporate}/distance-pricing-policy/services', [\App\Http\Controllers\Api\Corporate\CorporateDistancePolicyController::class, 'services']);
        Route::post('{corporate}/distance-pricing-policy/preview', [\App\Http\Controllers\Api\Corporate\CorporateDistancePolicyController::class, 'preview']);
        Route::put('{corporate}/distance-pricing-policy/services/{serviceType}', [\App\Http\Controllers\Api\Corporate\CorporateDistancePolicyController::class, 'updateService']);

        // Admin sub-resource routes for departments, divisions, employees, bookings
        Route::get('{corporate}/departments', [\App\Http\Controllers\Api\Corporate\AdminCorporateDepartmentController::class, 'index']);
        Route::post('{corporate}/departments', [\App\Http\Controllers\Api\Corporate\AdminCorporateDepartmentController::class, 'store']);
        Route::get('{corporate}/departments/{department}', [\App\Http\Controllers\Api\Corporate\AdminCorporateDepartmentController::class, 'show']);
        Route::put('{corporate}/departments/{department}', [\App\Http\Controllers\Api\Corporate\AdminCorporateDepartmentController::class, 'update']);
        Route::delete('{corporate}/departments/{department}', [\App\Http\Controllers\Api\Corporate\AdminCorporateDepartmentController::class, 'destroy']);

        Route::get('{corporate}/departments/{department}/divisions', [\App\Http\Controllers\Api\Corporate\AdminCorporateDivisionController::class, 'index']);
        Route::post('{corporate}/departments/{department}/divisions', [\App\Http\Controllers\Api\Corporate\AdminCorporateDivisionController::class, 'store']);
        Route::put('{corporate}/divisions/{division}', [\App\Http\Controllers\Api\Corporate\AdminCorporateDivisionController::class, 'update']);
        Route::delete('{corporate}/divisions/{division}', [\App\Http\Controllers\Api\Corporate\AdminCorporateDivisionController::class, 'destroy']);

        Route::get('{corporate}/employees', [\App\Http\Controllers\Api\Corporate\AdminCorporateEmployeeController::class, 'index']);
        Route::post('{corporate}/employees', [\App\Http\Controllers\Api\Corporate\AdminCorporateEmployeeController::class, 'store']);
        Route::get('{corporate}/employees/{id}', [\App\Http\Controllers\Api\Corporate\AdminCorporateEmployeeController::class, 'show']);
        Route::put('{corporate}/employees/{id}', [\App\Http\Controllers\Api\Corporate\AdminCorporateEmployeeController::class, 'update']);
        Route::post('{corporate}/employees/{id}/activate', [\App\Http\Controllers\Api\Corporate\AdminCorporateEmployeeController::class, 'activate']);
        Route::post('{corporate}/employees/{id}/deactivate', [\App\Http\Controllers\Api\Corporate\AdminCorporateEmployeeController::class, 'deactivate']);
        Route::post('{corporate}/employees/{id}/role', [\App\Http\Controllers\Api\Corporate\AdminCorporateEmployeeController::class, 'assignRole']);
        Route::delete('{corporate}/employees/{id}', [\App\Http\Controllers\Api\Corporate\AdminCorporateEmployeeController::class, 'destroy']);

        Route::post('{corporate}/bookings/for-employee', [\App\Http\Controllers\Api\Corporate\AdminCorporateBookingController::class, 'storeForEmployee']);

        Route::prefix('{corporate}/staff-transport')->group(function () {
            Route::get('programs', [\App\Http\Controllers\Api\Corporate\CorporateStaffTransportController::class, 'programs']);
            Route::post('programs', [\App\Http\Controllers\Api\Corporate\CorporateStaffTransportController::class, 'storeProgram']);
            Route::put('programs/{program}', [\App\Http\Controllers\Api\Corporate\CorporateStaffTransportController::class, 'updateProgram']);
            Route::get('programs/{program}/shifts', [\App\Http\Controllers\Api\Corporate\CorporateStaffTransportController::class, 'shifts']);
            Route::post('programs/{program}/shifts', [\App\Http\Controllers\Api\Corporate\CorporateStaffTransportController::class, 'storeShift']);
            Route::put('programs/{program}/shifts/{shift}', [\App\Http\Controllers\Api\Corporate\CorporateStaffTransportController::class, 'updateShift']);
            Route::delete('programs/{program}/shifts/{shift}', [\App\Http\Controllers\Api\Corporate\CorporateStaffTransportController::class, 'deleteShift']);
            Route::get('programs/{program}/routes', [\App\Http\Controllers\Api\Corporate\CorporateStaffTransportController::class, 'routes']);
            Route::post('programs/{program}/routes', [\App\Http\Controllers\Api\Corporate\CorporateStaffTransportController::class, 'storeRoute']);
            Route::put('programs/{program}/routes/{route}', [\App\Http\Controllers\Api\Corporate\CorporateStaffTransportController::class, 'updateRoute']);
            Route::delete('programs/{program}/routes/{route}', [\App\Http\Controllers\Api\Corporate\CorporateStaffTransportController::class, 'deleteRoute']);
            Route::get('programs/{program}/routes/{route}/members', [\App\Http\Controllers\Api\Corporate\CorporateStaffTransportController::class, 'members']);
            Route::post('programs/{program}/routes/{route}/members', [\App\Http\Controllers\Api\Corporate\CorporateStaffTransportController::class, 'storeMember']);
            Route::put('programs/{program}/routes/{route}/members/{member}', [\App\Http\Controllers\Api\Corporate\CorporateStaffTransportController::class, 'updateMember']);
            Route::delete('programs/{program}/routes/{route}/members/{member}', [\App\Http\Controllers\Api\Corporate\CorporateStaffTransportController::class, 'deleteMember']);
            Route::post('roster/build', [\App\Http\Controllers\Api\Corporate\CorporateStaffTransportController::class, 'buildRoster']);
            Route::get('roster', [\App\Http\Controllers\Api\Corporate\CorporateStaffTransportController::class, 'roster']);
            Route::get('my-calendar', [\App\Http\Controllers\Api\Corporate\CorporateStaffTransportController::class, 'myCalendar']);
            Route::post('my-calendar/{participation}/status', [\App\Http\Controllers\Api\Corporate\CorporateStaffTransportController::class, 'setMyParticipation']);
            Route::post('roster/{participation}/status', [\App\Http\Controllers\Api\Corporate\CorporateStaffTransportController::class, 'setParticipation']);
            Route::post('generate', [\App\Http\Controllers\Api\Corporate\CorporateStaffTransportController::class, 'generate']);
            Route::get('logs', [\App\Http\Controllers\Api\Corporate\CorporateStaffTransportController::class, 'logs']);
            Route::get('generated-bookings/{booking}', [\App\Http\Controllers\Api\Corporate\CorporateStaffTransportController::class, 'generatedBooking']);
        });
    });
});

/*
|--------------------------------------------------------------------------
| Health Check & Status Routes
|--------------------------------------------------------------------------
|
| These routes provide system health checks and status information
|
*/

Route::get('health', \App\Http\Controllers\Api\HealthController::class);



/*
|--------------------------------------------------------------------------
| Fallback Routes
|--------------------------------------------------------------------------
|
| These routes handle any unmatched API requests
|
*/

// Admin FAQ Management Routes
Route::middleware(['auth:api'])->group(function () {
    Route::prefix('admin')->group(function () {
        Route::get('faq-categories', [\App\Http\Controllers\Api\Admin\FAQCategoryController::class, 'index'])
            ->middleware('permission:faq-categories.view');
        Route::get('faq-categories/{faqCategory}', [\App\Http\Controllers\Api\Admin\FAQCategoryController::class, 'show'])
            ->middleware('permission:faq-categories.view');
        Route::post('faq-categories', [\App\Http\Controllers\Api\Admin\FAQCategoryController::class, 'store'])
            ->middleware('permission:faq-categories.create');
        Route::put('faq-categories/{faqCategory}', [\App\Http\Controllers\Api\Admin\FAQCategoryController::class, 'update'])
            ->middleware('permission:faq-categories.edit');
        Route::delete('faq-categories/{faqCategory}', [\App\Http\Controllers\Api\Admin\FAQCategoryController::class, 'destroy'])
            ->middleware('permission:faq-categories.delete');
        Route::post('faq-categories/bulk-sort', [\App\Http\Controllers\Api\Admin\FAQCategoryController::class, 'bulkUpdateSort'])
            ->middleware('permission:faq-categories.edit');
        Route::get('faq-categories/{faqCategory}/faqs', [\App\Http\Controllers\Api\Admin\FAQCategoryController::class, 'faqs'])
            ->middleware('permission:faq-categories.view');

        Route::get('faqs/stats', [\App\Http\Controllers\Api\Admin\FAQController::class, 'stats'])
            ->middleware('permission:faqs.view');
        Route::get('faqs', [\App\Http\Controllers\Api\Admin\FAQController::class, 'index'])
            ->middleware('permission:faqs.view');
        Route::get('faqs/{faq}', [\App\Http\Controllers\Api\Admin\FAQController::class, 'show'])
            ->middleware('permission:faqs.view');
        Route::post('faqs', [\App\Http\Controllers\Api\Admin\FAQController::class, 'store'])
            ->middleware('permission:faqs.create');
        Route::put('faqs/{faq}', [\App\Http\Controllers\Api\Admin\FAQController::class, 'update'])
            ->middleware('permission:faqs.edit');
        Route::delete('faqs/{faq}', [\App\Http\Controllers\Api\Admin\FAQController::class, 'destroy'])
            ->middleware('permission:faqs.delete');
        Route::post('faqs/bulk-update', [\App\Http\Controllers\Api\Admin\FAQController::class, 'bulkUpdate'])
            ->middleware('permission:faqs.edit');
        Route::get('faqs/categories/list', [\App\Http\Controllers\Api\Admin\FAQController::class, 'getCategories'])
            ->middleware('permission:faqs.view');

        Route::middleware(['permission:settings.view'])->group(function () {
            Route::get('booking-form-tabs', [\App\Http\Controllers\Api\Admin\BookingFormTabController::class, 'index']);
            Route::get('booking-form-tabs/{id}', [\App\Http\Controllers\Api\Admin\BookingFormTabController::class, 'show']);
        });
        Route::put('booking-form-tabs/{id}', [\App\Http\Controllers\Api\Admin\BookingFormTabController::class, 'update'])
            ->middleware('permission:settings.edit');
        Route::post('booking-form-tabs/{id}/toggle', [\App\Http\Controllers\Api\Admin\BookingFormTabController::class, 'toggle'])
            ->middleware('permission:settings.edit');
        Route::post('booking-form-tabs/reorder', [\App\Http\Controllers\Api\Admin\BookingFormTabController::class, 'reorder'])
            ->middleware('permission:settings.edit');
        Route::post('booking-form-tabs/bulk-update', [\App\Http\Controllers\Api\Admin\BookingFormTabController::class, 'bulkUpdate'])
            ->middleware('permission:settings.edit');

        // Service Form Configs — DB-driven overrides for DynamicServiceConfigurationService
        Route::get('service-form-configs', [\App\Http\Controllers\Api\Admin\ServiceFormConfigController::class, 'index']);
        Route::get('service-form-configs/{serviceCode}', [\App\Http\Controllers\Api\Admin\ServiceFormConfigController::class, 'show']);
        Route::post('service-form-configs', [\App\Http\Controllers\Api\Admin\ServiceFormConfigController::class, 'store']);
        Route::put('service-form-configs/{serviceCode}', [\App\Http\Controllers\Api\Admin\ServiceFormConfigController::class, 'update']);
        Route::delete('service-form-configs/{serviceCode}', [\App\Http\Controllers\Api\Admin\ServiceFormConfigController::class, 'destroy']);
    });
});

// Admin Navigation & Footer Management Routes
Route::middleware(['auth:api'])->group(function () {
    Route::prefix('admin')->group(function () {
        // Navigation Menu Management
        Route::middleware(['permission:navigation-menus.view'])->group(function () {
            Route::get('navigation-menus', [NavigationMenuController::class, 'index']);
            Route::get('navigation-menus/{navigationMenu}', [NavigationMenuController::class, 'show']);
            Route::get('navigation-menus/tree/structure', [NavigationMenuController::class, 'tree']);
        });
        Route::post('navigation-menus', [NavigationMenuController::class, 'store'])
            ->middleware('permission:navigation-menus.create');
        Route::put('navigation-menus/{navigationMenu}', [NavigationMenuController::class, 'update'])
            ->middleware('permission:navigation-menus.edit');
        Route::delete('navigation-menus/{navigationMenu}', [NavigationMenuController::class, 'destroy'])
            ->middleware('permission:navigation-menus.delete');
        Route::post('navigation-menus/sort-order', [NavigationMenuController::class, 'updateSortOrder'])
            ->middleware('permission:navigation-menus.edit');
        Route::post('navigation-menus/{navigationMenu}/duplicate', [NavigationMenuController::class, 'duplicate'])
            ->middleware('permission:navigation-menus.create');

        // Footer Link Management  
        Route::middleware(['permission:footer-links.view'])->group(function () {
            Route::get('footer-links', [FooterLinkController::class, 'index']);
            Route::get('footer-links/{footerLink}', [FooterLinkController::class, 'show']);
            Route::get('footer-links/grouped/sections', [FooterLinkController::class, 'grouped']);
            Route::get('footer-links/sections/available', [FooterLinkController::class, 'sections']);
            Route::get('footer-links/social/list', [FooterLinkController::class, 'social']);
            Route::get('footer-links/legal/list', [FooterLinkController::class, 'legal']);
            Route::get('footer-links/contact/list', [FooterLinkController::class, 'contact']);
        });
        Route::post('footer-links', [FooterLinkController::class, 'store'])
            ->middleware('permission:footer-links.create');
        Route::put('footer-links/{footerLink}', [FooterLinkController::class, 'update'])
            ->middleware('permission:footer-links.edit');
        Route::delete('footer-links/{footerLink}', [FooterLinkController::class, 'destroy'])
            ->middleware('permission:footer-links.delete');
        Route::post('footer-links/sort-order', [FooterLinkController::class, 'updateSortOrder'])
            ->middleware('permission:footer-links.edit');
        Route::post('footer-links/{footerLink}/duplicate', [FooterLinkController::class, 'duplicate'])
            ->middleware('permission:footer-links.create');
    });
});

// Public Navigation & Footer Routes (No Authentication Required)
Route::prefix('public')->group(function () {
    Route::get('navigation/header', [NavigationMenuController::class, 'tree'])->name('api.navigation.header');
    Route::get('navigation/footer', [NavigationMenuController::class, 'tree'])->name('api.navigation.footer');
    Route::get('footer-links/grouped', [FooterLinkController::class, 'grouped'])->name('api.footer.grouped');
    Route::get('footer-links/social', [FooterLinkController::class, 'social'])->name('api.footer.social');
    Route::get('footer-links/legal', [FooterLinkController::class, 'legal'])->name('api.footer.legal');
    Route::get('footer-links/contact', [FooterLinkController::class, 'contact'])->name('api.footer.contact');
});

/*
|--------------------------------------------------------------------------
| Popup Management Routes
|--------------------------------------------------------------------------
|
| Admin routes for managing marketing popups and public routes for
| displaying popups on the website.
|
*/

// Admin Popup Management Routes (Requires Authentication and Authorization)
Route::middleware(['auth:api', 'permission:popup.view'])->group(function () {
    Route::prefix('admin')->group(function () {
        // Popup CRUD operations
        Route::get('popups/statistics', [\App\Http\Controllers\Api\Admin\PopupController::class, 'statistics'])
            ->name('api.admin.popups.statistics');
        Route::get('popups', [\App\Http\Controllers\Api\Admin\PopupController::class, 'index'])
            ->name('api.admin.popups.index');
        Route::get('popups/{popup}', [\App\Http\Controllers\Api\Admin\PopupController::class, 'show'])
            ->name('api.admin.popups.show');
        Route::post('popups', [\App\Http\Controllers\Api\Admin\PopupController::class, 'store'])
            ->middleware('permission:popup.create')
            ->name('api.admin.popups.store');
        Route::put('popups/{popup}', [\App\Http\Controllers\Api\Admin\PopupController::class, 'update'])
            ->middleware('permission:popup.edit')
            ->name('api.admin.popups.update');
        Route::delete('popups/{popup}', [\App\Http\Controllers\Api\Admin\PopupController::class, 'destroy'])
            ->middleware('permission:popup.delete')
            ->name('api.admin.popups.destroy');
        Route::patch('popups/{popup}/toggle', [\App\Http\Controllers\Api\Admin\PopupController::class, 'toggle'])
            ->middleware('permission:popup.update')
            ->name('api.admin.popups.toggle');

        // Terms & Conditions CRUD (admin)
        Route::get('terms', [\App\Http\Controllers\Api\TermsController::class, 'index'])
            ->middleware('permission:terms.view')
            ->name('api.admin.terms.index');
        Route::get('terms/{id}', [\App\Http\Controllers\Api\TermsController::class, 'show'])
            ->middleware('permission:terms.view')
            ->name('api.admin.terms.show');
        Route::post('terms', [\App\Http\Controllers\Api\TermsController::class, 'store'])
            ->middleware('permission:terms.create')
            ->name('api.admin.terms.store');
        Route::put('terms/{id}', [\App\Http\Controllers\Api\TermsController::class, 'update'])
            ->middleware('permission:terms.edit')
            ->name('api.admin.terms.update');
        Route::delete('terms/{id}', [\App\Http\Controllers\Api\TermsController::class, 'destroy'])
            ->middleware('permission:terms.delete')
            ->name('api.admin.terms.destroy');
    });
});

// Public Popup Routes (No Authentication Required)
Route::prefix('popups')->group(function () {
    Route::get('active', [\App\Http\Controllers\Api\Website\PopupController::class, 'getActivePopups'])
        ->name('api.popups.active');
    Route::get('highest-priority', [\App\Http\Controllers\Api\Website\PopupController::class, 'getHighestPriorityPopup'])
        ->name('api.popups.highest-priority');
});

/*
|--------------------------------------------------------------------------
| Promo Code Management Routes
|--------------------------------------------------------------------------
|
| Admin routes for managing promotional codes and public routes for
| applying/removing promo codes from cart.
|
*/

// Admin Promo Code Management Routes (Requires Authentication and Authorization)
Route::middleware(['auth:api', 'permission:promo-code.view'])->group(function () {
    Route::prefix('admin')->group(function () {
        // Promo Code CRUD operations
        Route::get('promo-codes/statistics', [\App\Http\Controllers\Api\Admin\PromoCodeController::class, 'statistics'])
            ->name('api.admin.promo-codes.statistics');
        Route::get('promo-codes', [\App\Http\Controllers\Api\Admin\PromoCodeController::class, 'index'])
            ->name('api.admin.promo-codes.index');
        Route::get('promo-codes/{promoCode}', [\App\Http\Controllers\Api\Admin\PromoCodeController::class, 'show'])
            ->name('api.admin.promo-codes.show');
        Route::post('promo-codes', [\App\Http\Controllers\Api\Admin\PromoCodeController::class, 'store'])
            ->middleware('permission:promo-code.create')
            ->name('api.admin.promo-codes.store');
        Route::put('promo-codes/{promoCode}', [\App\Http\Controllers\Api\Admin\PromoCodeController::class, 'update'])
            ->middleware('permission:promo-code.update')
            ->name('api.admin.promo-codes.update');
        Route::delete('promo-codes/{promoCode}', [\App\Http\Controllers\Api\Admin\PromoCodeController::class, 'destroy'])
            ->middleware('permission:promo-code.delete')
            ->name('api.admin.promo-codes.destroy');
        Route::patch('promo-codes/{promoCode}/toggle', [\App\Http\Controllers\Api\Admin\PromoCodeController::class, 'toggle'])
            ->middleware('permission:promo-code.update')
            ->name('api.admin.promo-codes.toggle');
        Route::get('promo-codes/{promoCode}/analytics', [\App\Http\Controllers\Api\Admin\PromoCodeController::class, 'analytics'])
            ->name('api.admin.promo-codes.analytics');
    });
});

// Public Promo Code Routes (Cart Integration)
Route::prefix('cart')->group(function () {
    Route::post('apply-promo-code', [\App\Http\Controllers\CartController::class, 'applyPromoCode'])
        ->name('api.cart.apply-promo-code');
    Route::post('remove-promo-code', [\App\Http\Controllers\CartController::class, 'removePromoCode'])
        ->name('api.cart.remove-promo-code');
});

/*
|--------------------------------------------------------------------------
| Email Template Testing Routes (Development Only)
|--------------------------------------------------------------------------
|
| These routes allow testing email templates with sample data
| without triggering the complete booking flow
| Access: /api/test-emails/[template-name]
|
*/

if (app()->environment(['local', 'testing', 'staging'])) {
    Route::prefix('test-emails')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\EmailTestController::class, 'listAll']);
        Route::get('checkout-confirmation', [\App\Http\Controllers\Api\EmailTestController::class, 'testCheckoutConfirmation']);
        Route::get('general', [\App\Http\Controllers\Api\EmailTestController::class, 'testGeneral']);
        Route::get('inquiry-confirmation', [\App\Http\Controllers\Api\EmailTestController::class, 'testInquiryConfirmation']);
        Route::get('payment-initiated', [\App\Http\Controllers\Api\EmailTestController::class, 'testPaymentInitiated']);
        Route::get('quotation-request-confirmation', [\App\Http\Controllers\Api\EmailTestController::class, 'testQuotationRequestConfirmation']);
        Route::get('quotation-request-notification', [\App\Http\Controllers\Api\EmailTestController::class, 'testQuotationRequestNotification']);
        Route::get('quotation-request', [\App\Http\Controllers\Api\EmailTestController::class, 'testQuotationRequest']);
    });
}

Route::fallback(function () {
    return response()->json([
        'status' => 'error',
        'message' => 'API endpoint not found',
        'code' => 404,
    ], 404);
});
