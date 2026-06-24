<?php
// app/Http/Controllers/Api/Website/WebsiteSettingController.php
namespace App\Http\Controllers\Api\Website;

use App\Http\Controllers\Controller;
use App\Models\Website\WebsiteSetting;
use App\Http\Requests\Website\WebsiteSetting\CreateWebsiteSettingRequest;
use App\Http\Requests\Website\WebsiteSetting\UpdateWebsiteSettingRequest;
use App\Http\Resources\Website\WebsiteSettingResource;
use App\Services\WebsiteSettingsService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class WebsiteSettingController extends Controller
{
    protected WebsiteSettingsService $settingsService;

    public function __construct(WebsiteSettingsService $settingsService)
    {
        $this->settingsService = $settingsService;
        $this->middleware('permission:website-settings.view')->only([
            'index',
            'show',
            'getByKey',
            'homepage',
            'getCategory',
            'getAllCategorized',
            'general',
            'seo',
            'socialMedia',
            'payment',
            'security',
            'email',
            'booking',
            'driverMobile',
            'appearance',
        ]);
        $this->middleware('permission:website-settings.create')->only(['store']);
        $this->middleware('permission:website-settings.edit')->only([
            'update',
            'updateByKey',
            'updateMultiple',
            'updateCategory',
            'optimizeClear',
        ]);
        $this->middleware('permission:website-settings.delete')->only(['destroy']);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $companyId = $this->settingsService->resolveCurrentCompanyId();
        $q = WebsiteSetting::query()
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId))
            ->when(!$companyId, fn ($query) => $query->whereNull('company_id'));
        if ($request->filled('search')) {
            $q->where('type', 'like', '%' . $request->search . '%');
        }
        return WebsiteSettingResource::collection(
            $q->paginate($request->per_page ?? 15)
        );
    }

    public function store(CreateWebsiteSettingRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['value'] = WebsiteSetting::normalizeValue($data['value'] ?? null);
        $data['company_id'] = $this->settingsService->resolveCurrentCompanyId();
        $data['created_user_id'] = $request->user()->id;
        $setting = WebsiteSetting::create($data);
        $this->settingsService->clearCache($setting->type, $setting->company_id);

        return response()->json([
            'status' => 'success',
            'message' => 'Website setting created',
            'data' => ['setting' => new WebsiteSettingResource($setting)]
        ], 201);
    }

    public function show(WebsiteSetting $websiteSetting): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => ['setting' => new WebsiteSettingResource($websiteSetting)]
        ]);
    }

    public function update(UpdateWebsiteSettingRequest $request, WebsiteSetting $websiteSetting): JsonResponse
    {
        $data = $request->validated();
        if (array_key_exists('value', $data)) {
            $data['value'] = WebsiteSetting::normalizeValue($data['value']);
        }
        $data['updated_user_id'] = $request->user()->id;
        $websiteSetting->update($data);

        $this->settingsService->clearCache($websiteSetting->type, $websiteSetting->company_id);

        return response()->json([
            'status' => 'success',
            'message' => 'Website setting updated',
            'data' => ['setting' => new WebsiteSettingResource($websiteSetting)]
        ]);
    }

    public function getByKey(string $section, string $key): JsonResponse
    {
        $type = "{$section}.{$key}";
        $companyId = $this->settingsService->resolveCurrentCompanyId();
        $setting = WebsiteSetting::where('type', $type)
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId))
            ->when(!$companyId, fn ($query) => $query->whereNull('company_id'))
            ->first();

        return response()->json([
            'status' => 'success',
            'data' => [
                'setting' => $setting ? new WebsiteSettingResource($setting) : null,
                'value' => $setting?->value,
            ],
        ]);
    }

    public function updateByKey(Request $request, string $section, string $key): JsonResponse
    {
        $data = $request->validate([
            'setting_value' => ['nullable'],
            'value' => ['nullable'],
        ]);

        $type = "{$section}.{$key}";
        $companyId = $this->settingsService->resolveCurrentCompanyId();
        $value = array_key_exists('setting_value', $data) ? $data['setting_value'] : ($data['value'] ?? null);
        $setting = WebsiteSetting::updateOrCreate(
            ['type' => $type, 'company_id' => $companyId],
            [
                'value' => WebsiteSetting::normalizeValue($value),
                'updated_user_id' => $request->user()->id,
            ]
        );

        $this->settingsService->clearCache($type, $companyId);

        return response()->json([
            'status' => 'success',
            'message' => 'Website setting updated',
            'data' => ['setting' => new WebsiteSettingResource($setting)]
        ]);
    }

    public function destroy(WebsiteSetting $websiteSetting): JsonResponse
    {
        $type = $websiteSetting->type;
        $websiteSetting->delete();

        // Clear cache
        $this->settingsService->clearCache($type, $websiteSetting->company_id);

        return response()->json([
            'status' => 'success',
            'message' => 'Website setting deleted'
        ]);
    }

    /**
     * Update multiple settings at once.
     */
    public function updateMultiple(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'settings' => 'required|array',
            'settings.*.type' => 'required|string|max:255',
            'settings.*.value' => 'nullable',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $updatedSettings = [];
        $types = [];
        $companyId = $this->settingsService->resolveCurrentCompanyId();

        $settings = $this->normalizeBookingWorkflowSettingList($request->settings);

        foreach ($settings as $settingData) {
            $setting = WebsiteSetting::updateOrCreate(
                ['type' => $settingData['type'], 'company_id' => $companyId],
                [
                    'value' => WebsiteSetting::normalizeValue($settingData['value'] ?? null),
                    'updated_user_id' => $request->user()->id,
                ]
            );

            $updatedSettings[] = new WebsiteSettingResource($setting);
            $types[] = $settingData['type'];
        }

        // Clear cache for all updated types
        foreach ($types as $type) {
            $this->settingsService->clearCache($type, $companyId);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Website settings updated successfully',
            'data' => $updatedSettings
        ]);
    }

    /**
     * Get all homepage settings.
     */
    public function homepage(): JsonResponse
    {
        $settings = $this->settingsService->getHomepageSettings();

        return response()->json([
            'status' => 'success',
            'data' => $settings
        ]);
    }

    /**
     * Get settings by category
     */
    public function getCategory(string $category): JsonResponse
    {
        try {
            $settings = $this->settingsService->getCategorySettings($category);

            return response()->json([
                'status' => 'success',
                'data' => $settings
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Update settings for a specific category
     */
    public function updateCategory(Request $request, string $category): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'settings' => 'required|array',
            'settings.*' => 'nullable',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $settings = $category === 'booking'
                ? $this->normalizeBookingWorkflowSettings($request->settings)
                : $request->settings;
            $validSettings = array_flip($this->settingsService->getCategoryKeys($category));
            $invalidSettings = array_values(array_diff(array_keys($settings), array_keys($validSettings)));

            if (!empty($invalidSettings)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid settings for category: ' . $category,
                    'errors' => ['settings' => $invalidSettings],
                ], 422);
            }

            $updatedSettings = [];
            $companyId = $this->settingsService->resolveCurrentCompanyId();

            foreach ($settings as $type => $value) {
                $setting = WebsiteSetting::updateOrCreate(
                    ['type' => $type, 'company_id' => $companyId],
                    [
                        'value' => WebsiteSetting::normalizeValue($value),
                        'updated_user_id' => $request->user()->id,
                    ]
                );

                $updatedSettings[] = new WebsiteSettingResource($setting);
                $this->settingsService->clearCache($type, $companyId);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Settings updated successfully',
                'data' => $updatedSettings
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Get all categorized settings
     */
    public function getAllCategorized(Request $request): JsonResponse
    {
        $categories = match ($request->query('area')) {
            'website' => [
                'general',
                'branding',
                'homepage',
                'header',
                'booking',
                'footer',
                'about',
                'faq',
                'corporate',
                'pointToPoint',
                'seo',
                'social_media',
                'contact',
                'payment',
                'appearance',
            ],
            'application' => [
                'branding',
                'booking',
                'driverMobile',
                'security',
                'email',
            ],
            default => null,
        };

        $settings = $this->settingsService->getAllCategorizedSettings($categories);

        return response()->json([
            'status' => 'success',
            'data' => $settings
        ]);
    }

    /**
     * Get general settings
     */
    public function general(): JsonResponse
    {
        $settings = $this->settingsService->getGeneralSettings();

        return response()->json([
            'status' => 'success',
            'data' => $settings
        ]);
    }

    /**
     * Get SEO settings
     */
    public function seo(): JsonResponse
    {
        $settings = $this->settingsService->getSeoSettings();

        return response()->json([
            'status' => 'success',
            'data' => $settings
        ]);
    }

    /**
     * Get social media settings
     */
    public function socialMedia(): JsonResponse
    {
        $settings = $this->settingsService->getSocialMediaSettings();

        return response()->json([
            'status' => 'success',
            'data' => $settings
        ]);
    }

    /**
     * Get payment settings
     */
    public function payment(): JsonResponse
    {
        $settings = $this->settingsService->getPaymentSettings();

        return response()->json([
            'status' => 'success',
            'data' => $settings
        ]);
    }

    /**
     * Get security settings
     */
    public function security(): JsonResponse
    {
        $settings = $this->settingsService->getSecuritySettings();

        return response()->json([
            'status' => 'success',
            'data' => $settings
        ]);
    }

    /**
     * Get email settings
     */
    public function email(): JsonResponse
    {
        $settings = $this->settingsService->getEmailSettings();

        return response()->json([
            'status' => 'success',
            'data' => $settings
        ]);
    }

    /**
     * Get booking settings
     */
    public function booking(): JsonResponse
    {
        $settings = $this->settingsService->getBookingSettings();

        return response()->json([
            'status' => 'success',
            'data' => $settings
        ]);
    }

    /**
     * Get driver mobile app settings
     */
    public function driverMobile(): JsonResponse
    {
        $settings = $this->settingsService->getDriverMobileSettings();

        return response()->json([
            'status' => 'success',
            'data' => $settings
        ]);
    }

    /**
     * Get appearance settings
     */
    public function appearance(): JsonResponse
    {
        $settings = $this->settingsService->getAppearanceSettings();

        return response()->json([
            'status' => 'success',
            'data' => $settings
        ]);
    }

    /**
     * Get branding settings (public endpoint - no auth required)
     */
    public function branding(): JsonResponse
    {
        $settings = $this->settingsService->getBrandingSettings();

        return response()->json([
            'status' => 'success',
            'data' => $settings
        ]);
    }

    /**
     * Run php artisan optimize:clear and clear website settings cache
     */
    public function optimizeClear(Request $request): JsonResponse
    {
        try {
            Artisan::call('optimize:clear');
            $this->settingsService->clearAllCache();

            Log::info('Optimize clear triggered by user: ' . $request->user()->id);

            return response()->json([
                'status' => 'success',
                'message' => 'Server caches cleared (optimize:clear executed)'
            ]);
        } catch (\Exception $e) {
            Log::error('Optimize clear failed: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to clear caches'
            ], 500);
        }
    }

    /**
     * QC is a post-return stage and cannot be enabled without return management.
     */
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

    private function normalizeBookingWorkflowSettingList(array $settings): array
    {
        $returnSetting = collect($settings)->firstWhere(
            'type',
            'feature_vehicle_return_management_enabled'
        );

        if (!$returnSetting || $this->settingIsEnabled($returnSetting['value'] ?? null)) {
            return $settings;
        }

        $qcFound = false;
        foreach ($settings as &$setting) {
            if (($setting['type'] ?? null) === 'assignment_enable_qc_stage') {
                $setting['value'] = false;
                $qcFound = true;
            }
        }
        unset($setting);

        if (!$qcFound) {
            $settings[] = [
                'type' => 'assignment_enable_qc_stage',
                'value' => false,
            ];
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
