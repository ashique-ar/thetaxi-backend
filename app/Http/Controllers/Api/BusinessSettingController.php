<?php
// app/Http/Controllers/Api/BusinessSettingController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessSetting;
use App\Http\Requests\BusinessSetting\CreateBusinessSettingRequest;
use App\Http\Requests\BusinessSetting\UpdateBusinessSettingRequest;
use App\Http\Resources\BusinessSettingResource;
use App\Models\Website\WebsiteSetting;
use App\Services\WebsiteSettingsService;
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
            'booking_max_days',
            'cancellation_allowed',
            'cancellation_hours',
            'auto_dispatch_enabled',
            'include_garage_distance_in_pricing',
            'feature_corporate_management_enabled',
            'feature_vehicle_return_management_enabled',
            'assignment_enable_qc_stage',
            'assignment_enable_maintenance_stage',
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
            'email_footer_text',
        ],
    ];

    public function __construct(private WebsiteSettingsService $websiteSettingsService)
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
                    ? $businessValues[$key]
                    : ($legacyValues[$key] ?? null);
            }
        }

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
        $invalidKeys = array_values(array_diff(array_keys($settings), $validKeys));

        if ($invalidKeys !== []) {
            return response()->json([
                'status' => 'error',
                'message' => "Invalid settings for business category: {$category}",
                'errors' => ['settings' => $invalidKeys],
            ], 422);
        }

        $userId = $request->user()?->id;
        foreach ($settings as $type => $value) {
            $normalizedValue = WebsiteSetting::normalizeValue($value);

            BusinessSetting::updateOrCreate(
                ['type' => $type],
                [
                    'value' => $normalizedValue,
                    'updated_user_id' => $userId,
                ]
            );

            // Keep existing runtime consumers working while business-owned reads migrate.
            $this->websiteSettingsService->set($type, $normalizedValue);
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
        $data['created_user_id'] = $request->user()->id;
        $setting = BusinessSetting::create($data);

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
        $data = $request->validated();
        $data['updated_user_id'] = $request->user()->id;
        $businessSetting->update($data);

        return response()->json([
            'status'=>'success',
            'message'=>'Business setting updated',
            'data'=>['business_setting'=>new BusinessSettingResource($businessSetting)]
        ]);
    }

    public function destroy(BusinessSetting $businessSetting): JsonResponse
    {
        $businessSetting->delete();
        return response()->json([
            'status'=>'success',
            'message'=>'Business setting deleted'
        ]);
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
}
