<?php
// app/Http/Controllers/Api/BusinessSettingController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessSetting;
use App\Models\Driver\Driver;
use App\Http\Requests\BusinessSetting\CreateBusinessSettingRequest;
use App\Http\Requests\BusinessSetting\UpdateBusinessSettingRequest;
use App\Http\Resources\BusinessSettingResource;
use App\Models\Website\WebsiteSetting;
use App\Services\WebsiteSettingsService;
use App\Services\Hr\Attendance\AttendanceIngestionActivationGuard;
use App\Services\Driver\NotificationTriggerService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Validator;

class BusinessSettingController extends Controller
{
    private const CATEGORY_KEYS = [
        'general' => [
            'site_name',
            'site_tagline',
            'company_name',
            'company_logo_path',
            'company_email',
            'company_phone',
            'company_whatsapp',
            'company_address',
            'company_website',
            'content_generation_url',
            'site_timezone',
            'default_currency',
        ],
        'branding' => [
            'portal_title',
            'portal_logo',
            'brand_color_primary',
            'brand_color_primary_light',
            'brand_color_primary_dark',
            'brand_color_secondary',
            'brand_color_secondary_light',
            'brand_color_secondary_dark',
            'brand_color_accent',
            'brand_color_accent_light',
            'brand_color_accent_dark',
            'portal_theme',
            'portal_scheme',
            'portal_sidebar_appearance',
            'portal_sidebar_style',
        ],
        'booking' => [
            'booking_base_currency',
            'booking_advance_hours',
            'customer_portal_booking_advance_hours',
            'corporate_portal_booking_advance_hours',
            'booking_max_days',
            'cancellation_allowed',
            'cancellation_hours',
            'auto_dispatch_enabled',
            'include_garage_distance_in_pricing',
            'pricing_holiday_dates',
            'pricing_recurring_holidays',
            'late_return_grace_hours',
            'late_return_grace_minutes',
            'late_return_fee_per_hour',
            'late_return_fee_per_minute',
            'feature_corporate_management_enabled',
            'feature_vehicle_return_management_enabled',
            'feature_hr_management_enabled',
            'feature_hr_attendance_ingestion_enabled',
            'feature_sales_management_enabled',
            'feature_respond_io_enabled',
            'feature_3cx_enabled',
            'feature_driver_management_enabled',
            'driver_form_public_enabled',
            'assignment_enable_qc_stage',
            'assignment_enable_maintenance_stage',
            'booking_signed_agreement_required',
        ],
        'pricing' => [
            'internal_pricing_mode',
        ],
        'numbering' => [
            'customer_code_prefix', 'customer_code_suffix', 'customer_code_digits', 'customer_code_start_number',
            'staff_code_prefix', 'staff_code_suffix', 'staff_code_digits', 'staff_code_start_number',
            'driver_code_prefix', 'driver_code_suffix', 'driver_code_digits', 'driver_code_start_number',
        ],
        'driverMobile' => [
            'driver_mobile_latest_version',
            'driver_mobile_mandatory_update',
            'driver_mobile_update_message',
        ],
        'security' => [
            'ssl_force',
            'security_headers_enabled',
            'content_security_policy',
            'rate_limiting_enabled',
            'rate_limit_per_minute',
            'maintenance_mode',
            'maintenance_message',
        ],
        'email' => [
            'mail_from_name',
            'mail_from_address',
            'booking_confirmation_enabled',
            'booking_reminder_enabled',
            'contact_form_notification',
            'corporate_booking_notification_email',
            'email_footer_text',
            'email_header_subtitle',
        ],
    ];

    public function __construct(
        private WebsiteSettingsService $websiteSettingsService,
        private AttendanceIngestionActivationGuard $attendanceActivation,
    )
    {
        $this->middleware('permission:business-settings.view')->only([
            'index',
            'show',
            'getAllCategorized',
        ]);
        $this->middleware('permission:business-settings.create')->only(['store']);
        $this->middleware('permission:business-settings.edit')->only([
            'update',
            'updateCategory',
        ]);
        $this->middleware('permission:business-settings.delete')->only(['destroy']);
    }

    public function getAllCategorized(): JsonResponse
    {
        $businessValues = BusinessSetting::query()
            ->whereIn('type', collect(self::CATEGORY_KEYS)->flatten()->all())
            ->pluck('value', 'type')
            ->all();

        $categories = [];
        foreach (self::CATEGORY_KEYS as $category => $keys) {
            $legacyValues = $this->websiteSettingsService->getCategorySettings($category);
            $categories[$category] = [];

            foreach ($keys as $key) {
                $categories[$category][$key] = array_key_exists($key, $businessValues)
                    && ! in_array($key, BusinessSetting::INSTALLATION_WIDE_WEBSITE_KEYS, true)
                    ? $businessValues[$key]
                    : ($legacyValues[$key] ?? $businessValues[$key] ?? null);
            }
        }
        $categories['booking']['customer_portal_booking_advance_hours'] ??= $categories['booking']['booking_advance_hours'];
        $categories['booking']['corporate_portal_booking_advance_hours'] ??= $categories['booking']['booking_advance_hours'];
        $categories['booking']['feature_hr_attendance_ingestion_enabled'] =
            $this->websiteSettingsService->get('feature_hr_attendance_ingestion_enabled', false);
        $categories['booking']['feature_respond_io_enabled'] ??= false;
        $categories['booking']['feature_3cx_enabled'] ??= false;
        $categories['booking']['feature_driver_management_enabled'] ??= true;
        $categories['booking']['driver_form_public_enabled'] ??= false;
        $categories['booking']['booking_signed_agreement_required'] ??= true;
        return response()->json([
            'status' => 'success',
            'data' => $categories,
        ]);
    }

    public function updateCategory(Request $request, string $category): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'settings' => ['required', 'array'],
            'settings.*' => ['nullable'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        if ($category === 'booking') {
            $timingValidator = Validator::make($request->input('settings', []), [
                'customer_portal_booking_advance_hours' => ['sometimes', 'required', 'integer', 'min:0', 'max:8760'],
                'corporate_portal_booking_advance_hours' => ['sometimes', 'required', 'numeric', 'min:0', 'max:8760'],
                'feature_vehicle_return_management_enabled' => ['sometimes', 'boolean'],
                'assignment_enable_qc_stage' => ['sometimes', 'boolean'],
                'assignment_enable_maintenance_stage' => ['sometimes', 'boolean'],
                'booking_signed_agreement_required' => ['sometimes', 'boolean'],
            ]);
            if ($timingValidator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Portal booking notice must be between 0 and 8760 hours; corporate settings may use decimals.',
                    'errors' => $timingValidator->errors(),
                ], 422);
            }
        }

        if ($category === 'email') {
            $emailValidator = Validator::make($request->input('settings', []), [
                'corporate_booking_notification_email' => ['sometimes', 'nullable', 'email:rfc', 'max:255'],
            ]);
            if ($emailValidator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Enter a valid corporate booking notification email address.',
                    'errors' => $emailValidator->errors(),
                ], 422);
            }
        }

        if ($category === 'numbering') {
            $numberingRules = [];
            foreach (['customer', 'staff', 'driver'] as $entity) {
                $numberingRules["{$entity}_code_prefix"] = ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9_-]*$/'];
                $numberingRules["{$entity}_code_suffix"] = ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9_-]*$/'];
                $numberingRules["{$entity}_code_digits"] = ['required', 'integer', 'min:1', 'max:12'];
                $numberingRules["{$entity}_code_start_number"] = ['required', 'integer', 'min:1'];
            }
            $numberingValidator = Validator::make($request->input('settings', []), $numberingRules);
            if ($numberingValidator->fails()) {
                return response()->json(['status' => 'error', 'message' => 'Invalid numbering settings.', 'errors' => $numberingValidator->errors()], 422);
            }
        }

        $validKeys = self::CATEGORY_KEYS[$category] ?? null;
        if ($validKeys === null) {
            return response()->json([
                'status' => 'error',
                'message' => "Invalid business settings category: {$category}",
            ], 404);
        }

        $settings = $category === 'booking'
            ? $this->normalizeBookingWorkflowSettings($request->input('settings', []))
            : $request->input('settings', []);
        if (array_key_exists('feature_hr_attendance_ingestion_enabled', $settings)) {
            $this->attendanceActivation->assertCanEnable(
                $request->user(), 'feature_hr_attendance_ingestion_enabled', $settings['feature_hr_attendance_ingestion_enabled'],
            );
        }

        if (
            $category === 'pricing'
            && isset($settings['internal_pricing_mode'])
            && !in_array($settings['internal_pricing_mode'], ['inherit_website', 'separate'], true)
        ) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid internal pricing mode.',
                'errors' => [
                    'settings.internal_pricing_mode' => [
                        'Choose Website inheritance or separate Internal pricing.',
                    ],
                ],
            ], 422);
        }
        $invalidKeys = array_values(array_diff(array_keys($settings), $validKeys));

        if ($invalidKeys !== []) {
            return response()->json([
                'status' => 'error',
                'message' => "Invalid settings for business category: {$category}",
                'errors' => ['settings' => $invalidKeys],
            ], 422);
        }

        $previousDriverVersion = $category === 'driverMobile'
            ? trim((string) $this->websiteSettingsService->get('driver_mobile_latest_version', ''))
            : '';
        $userId = $request->user()?->id;
        foreach ($settings as $type => $value) {
            $normalizedValue = WebsiteSetting::normalizeValue($value);
            if (in_array($type, BusinessSetting::INSTALLATION_WIDE_WEBSITE_KEYS, true)) {
                WebsiteSetting::updateOrCreate(['type' => $type], [
                    'value' => $normalizedValue,
                    'updated_user_id' => $userId,
                ]);
                $this->websiteSettingsService->clearCache($type);
                continue;
            }
            BusinessSetting::updateOrCreate(
                ['type' => $type],
                [
                    'value' => $normalizedValue,
                    'updated_user_id' => $userId,
                ]
            );

            // Keep existing runtime consumers working while business-owned reads migrate.
            if ($type === 'feature_hr_attendance_ingestion_enabled') {
                $this->websiteSettingsService->setGlobal($type, $normalizedValue);
            } else {
                $this->websiteSettingsService->set($type, $normalizedValue);
            }
        }

        if ($category === 'driverMobile' && array_key_exists('driver_mobile_latest_version', $settings)) {
            $this->notifyDriversAboutAppUpdate(
                $previousDriverVersion,
                (string) $settings['driver_mobile_latest_version'],
                (string) ($settings['driver_mobile_update_message'] ?? ''),
                $settings['driver_mobile_mandatory_update'] ?? false
            );
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Business settings updated successfully',
            'data' => $settings,
        ]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $q = BusinessSetting::query();
        if ($request->filled('search')) {
            $q->where('type','like','%'.$request->search.'%');
        }
        return BusinessSettingResource::collection(
            $q->paginate($request->per_page ?? 15)
        );
    }

    public function store(CreateBusinessSettingRequest $request): JsonResponse
    {
        $data = $request->validated();
        $this->validateInstallationWorkflowSetting($data['type'], $data['value'] ?? null);
        $this->attendanceActivation->assertCanEnable($request->user(), $data['type'], $data['value'] ?? null);
        $this->validateCorporateNotificationEmail($data['type'], $data['value'] ?? null);
        if (in_array($data['type'], BusinessSetting::INSTALLATION_WIDE_WEBSITE_KEYS, true)) {
            $setting = WebsiteSetting::updateOrCreate(['type' => $data['type']], [
                'value' => WebsiteSetting::normalizeValue($data['value'] ?? null),
                'created_user_id' => $request->user()->id,
                'updated_user_id' => $request->user()->id,
            ]);
            $this->websiteSettingsService->clearCache($setting->type);
            return response()->json(['status' => 'success', 'message' => 'Business setting created',
                'data' => ['business_setting' => new BusinessSettingResource($setting)]], 201);
        }
        $data['created_user_id'] = $request->user()->id;
        $setting = BusinessSetting::updateOrCreate(['type' => $data['type']], $data);
        $this->websiteSettingsService->set($setting->type, $setting->value);
        if ($setting->type === 'feature_hr_attendance_ingestion_enabled') {
            $this->websiteSettingsService->setGlobal($setting->type, $setting->value);
        }

        return response()->json([
            'status'=>'success',
            'message'=>'Business setting created',
            'data'=>['business_setting'=>new BusinessSettingResource($setting)]
        ], 201);
    }

    public function show(BusinessSetting $businessSetting): JsonResponse
    {
        return response()->json([
            'status'=>'success',
            'data'=>['business_setting'=>new BusinessSettingResource($businessSetting)]
        ]);
    }

    public function update(UpdateBusinessSettingRequest $request, BusinessSetting $businessSetting): JsonResponse
    {
        $previousValue = (string) $businessSetting->value;
        $data = $request->validated();
        $targetType = $data['type'] ?? $businessSetting->type;
        $this->validateInstallationWorkflowSetting(
            $targetType,
            array_key_exists('value', $data) ? $data['value'] : $businessSetting->value,
        );
        $this->validateCorporateNotificationEmail($targetType, $data['value'] ?? $businessSetting->value);
        $this->attendanceActivation->assertTypeCanChange($businessSetting->type, $data['type'] ?? $businessSetting->type);
        $this->attendanceActivation->assertCanEnable(
            $request->user(), $data['type'] ?? $businessSetting->type,
            array_key_exists('value', $data) ? $data['value'] : $businessSetting->value,
        );
        if (in_array($targetType, BusinessSetting::INSTALLATION_WIDE_WEBSITE_KEYS, true)) {
            $setting = WebsiteSetting::updateOrCreate(['type' => $targetType], [
                'value' => WebsiteSetting::normalizeValue($data['value'] ?? $businessSetting->value),
                'updated_user_id' => $request->user()->id,
            ]);
            $businessSetting->delete();
            $this->websiteSettingsService->clearCache($targetType);
            return response()->json(['status' => 'success', 'message' => 'Business setting updated',
                'data' => ['business_setting' => new BusinessSettingResource($setting)]]);
        }
        $data['updated_user_id'] = $request->user()->id;
        $businessSetting->update($data);
        $this->websiteSettingsService->set($businessSetting->type, $businessSetting->value);
        if ($businessSetting->type === 'feature_hr_attendance_ingestion_enabled') {
            $this->websiteSettingsService->setGlobal($businessSetting->type, $businessSetting->value);
        }
        if ($businessSetting->type === 'driver_mobile_latest_version') {
            $this->notifyDriversAboutAppUpdate(
                $previousValue,
                (string) $businessSetting->value,
                (string) $this->websiteSettingsService->get('driver_mobile_update_message', ''),
                $this->websiteSettingsService->get('driver_mobile_mandatory_update', false)
            );
        }

        return response()->json([
            'status'=>'success',
            'message'=>'Business setting updated',
            'data'=>['business_setting'=>new BusinessSettingResource($businessSetting)]
        ]);
    }

    public function destroy(BusinessSetting $businessSetting): JsonResponse
    {
        $this->attendanceActivation->assertNotDeletable($businessSetting->type);
        $businessSetting->delete();
        return response()->json([
            'status'=>'success',
            'message'=>'Business setting deleted'
        ]);
    }

    private function validateCorporateNotificationEmail(string $type, mixed $value): void
    {
        if ($type !== 'corporate_booking_notification_email') {
            return;
        }

        Validator::make(['value' => $value], ['value' => ['nullable', 'email:rfc', 'max:255']])->validate();
    }

    private function normalizeBookingWorkflowSettings(array $settings): array
    {
        if (
            array_key_exists('feature_vehicle_return_management_enabled', $settings)
            && !$this->settingIsEnabled($settings['feature_vehicle_return_management_enabled'])
        ) {
            $settings['assignment_enable_qc_stage'] = false;
        }

        return $settings;
    }

    private function settingIsEnabled(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        return in_array(strtolower(trim((string) $value)), [
            '1',
            'true',
            'yes',
            'on',
            'enabled',
        ], true);
    }

    private function validateInstallationWorkflowSetting(string $type, mixed $value): void
    {
        if (in_array($type, BusinessSetting::INSTALLATION_WIDE_WORKFLOW_KEYS, true)) {
            Validator::make(['value' => $value], ['value' => ['required', 'boolean']])->validate();
        }
    }

    private function notifyDriversAboutAppUpdate(string $previousVersion, string $configuredVersion, string $message, mixed $mandatory): void
    {
        $latestVersion = ltrim(trim($configuredVersion), 'vV');
        $previousVersion = ltrim(trim($previousVersion), 'vV');
        if ($latestVersion === '' || !version_compare($latestVersion, $previousVersion, '>')) {
            return;
        }

        $updateMessage = trim($message) ?: 'A new driver app version is available. Please update your app.';
        $mandatoryUpdate = in_array(strtolower((string) $mandatory), ['1', 'true', 'yes', 'on', 'enabled'], true);
        Driver::query()
            ->where('is_active', true)
            ->whereHas('activeDevices', fn ($query) => $query->whereNotNull('push_token')->whereNotNull('app_version'))
            ->with('activeDevices')
            ->chunkById(100, function ($drivers) use ($latestVersion, $updateMessage, $mandatoryUpdate): void {
                $notifications = app(NotificationTriggerService::class);
                foreach ($drivers as $driver) {
                    $hasOutdatedDevice = $driver->activeDevices->contains(fn ($device) =>
                        $device->push_token
                        && $device->app_version
                        && version_compare(ltrim(trim((string) $device->app_version), 'vV'), $latestVersion, '<')
                    );
                    if (!$hasOutdatedDevice) {
                        continue;
                    }
                    $notifications->sendDriverPushNotification(
                        $driver,
                        'driver_app_update_available',
                        'Driver app update available',
                        $updateMessage,
                        [
                            'latest_version' => $latestVersion,
                            'mandatory_update' => $mandatoryUpdate ? 'true' : 'false',
                            'action' => 'update_driver_app',
                        ]
                    );
                }
            });
    }
}
