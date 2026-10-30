<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Models\Sales\SalesPolicySetting;
use App\Models\Sales\SalesFeatureSetting;
use App\Models\Sales\SalesStaffCategoryDefinition;
use App\Services\Sales\SalesPolicySettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SalesPolicySettingsController extends Controller
{
    public function __construct(
        private readonly SalesPolicySettingsService $settings,
    )
    {
    }

    public function index(Request $request): JsonResponse
    {
        $canReviewRationale = $request->user()->can('sales.policy-settings.manage')
            || $request->user()->can('sales.policy-settings.approve');
        $policyColumns = [
            'id', 'policy_kind', 'version', 'status', 'fx_quote_base', 'fx_calculation_mode', 'fx_max_rate_age_hours',
            'fx_rounding_scale', 'dispute_response_days', 'profile_export_retention_days', 'business_timezone', 'inquiry_first_response_minutes', 'booking_assignment_target_minutes', 'repeat_booking_followup_enabled', 'repeat_booking_followup_days', 'approved_at',
        ];
        if (Schema::hasColumn('sales_policy_settings', 'inquiry_response_business_hours')) $policyColumns[] = 'inquiry_response_business_hours';
        $featureColumns = ['id', 'feature_key', 'version', 'enabled', 'status'];
        if (Schema::hasColumn('sales_feature_settings', 'stage_label')) $featureColumns[] = 'stage_label';
        $categoryColumns = ['id', 'category_name', 'status'];
        if ($canReviewRationale) {
            $policyColumns[] = 'reason';
            $featureColumns[] = 'reason';
            $categoryColumns[] = 'reason';
        }
        $byKind = collect(SalesPolicySettingsService::KINDS)
            ->mapWithKeys(fn(string $kind) => [$kind => SalesPolicySetting::query()
                ->where('policy_kind', $kind)->orderByDesc('version')->orderByRaw('CASE WHEN approved_at IS NULL THEN 1 ELSE 0 END')
                ->orderByDesc('approved_at')->orderByDesc('id')->get($policyColumns)]);

        return response()->json([
            'status' => 'success',
            'data' => [
                'policy_settings' => $byKind,
                'staff_categories' => SalesStaffCategoryDefinition::query()
                    ->orderBy('category_name')->get($categoryColumns),
                'feature_settings' => SalesFeatureSetting::query()
                    ->orderBy('feature_key')->orderByRaw('CASE WHEN approved_at IS NULL THEN 1 ELSE 0 END')
                    ->orderByDesc('approved_at')->orderByDesc('version')->orderByDesc('id')->get($featureColumns),
                'deployment_feature_availability' => $this->settings->featureAvailability(),
            ]
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $businessHours = $request->input('inquiry_response_business_hours');
        if (is_array($businessHours) && isset($businessHours['opens_at'], $businessHours['closes_at']) && is_array($businessHours['days'] ?? null)) {
            $businessHours['days'] = array_map(fn ($day) => is_numeric($day)
                ? ['day' => (int) $day, 'opens_at' => $businessHours['opens_at'], 'closes_at' => $businessHours['closes_at']]
                : $day, $businessHours['days']);
            unset($businessHours['opens_at'], $businessHours['closes_at']);
            $request->merge(['inquiry_response_business_hours' => $businessHours]);
        }
        $data = $request->validate([
            'policy_kind' => ['required', Rule::in(SalesPolicySettingsService::KINDS)],
            'fx_quote_base' => ['required_if:policy_kind,fx_corrections', 'nullable', 'string', 'max:40'],
            'fx_calculation_mode' => ['required_if:policy_kind,fx_corrections', 'nullable', Rule::in(['multiply_source_by_rate', 'divide_source_by_rate'])],
            'fx_max_rate_age_hours' => ['required_if:policy_kind,fx_corrections', 'nullable', 'integer', 'min:1', 'max:8760'],
            'fx_rounding_scale' => ['required_if:policy_kind,fx_corrections', 'nullable', 'integer', 'min:0', 'max:4'],
            'dispute_response_days' => ['required_if:policy_kind,commission_dispute', 'nullable', 'integer', 'min:1', 'max:365'],
            'profile_export_retention_days' => ['required_if:policy_kind,profile_export_retention', 'nullable', 'integer', 'min:1', 'max:3650'],
            'business_timezone' => ['required_if:policy_kind,business_timezone', 'nullable', 'timezone'],
            'inquiry_first_response_minutes' => ['required_if:policy_kind,inquiry_response', 'nullable', 'integer', 'min:1', 'max:10080'],
            'inquiry_response_business_hours' => ['nullable', 'prohibited_unless:policy_kind,inquiry_response', 'array:timezone,days'],
            'inquiry_response_business_hours.timezone' => ['required_with:inquiry_response_business_hours', 'timezone'],
            'inquiry_response_business_hours.days' => ['required_with:inquiry_response_business_hours', 'array', 'min:1'],
            'inquiry_response_business_hours.days.*' => ['required', 'array:day,opens_at,closes_at'],
            'inquiry_response_business_hours.days.*.day' => ['required', 'integer', 'between:1,7', 'distinct'],
            'inquiry_response_business_hours.days.*.opens_at' => ['required', 'date_format:H:i'],
            'inquiry_response_business_hours.days.*.closes_at' => ['required', 'date_format:H:i'],
            'booking_assignment_target_minutes' => ['required_if:policy_kind,booking_assignment', 'nullable', 'integer', 'min:1', 'max:10080'],
            'repeat_booking_followup_enabled' => ['required_if:policy_kind,repeat_booking_followup', 'nullable', 'boolean'],
            'repeat_booking_followup_days' => ['required_if:repeat_booking_followup_enabled,1', 'nullable', 'integer', 'min:1', 'max:3650'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        foreach ($data['inquiry_response_business_hours']['days'] ?? [] as $index => $day) {
            if ($day['closes_at'] <= $day['opens_at']) {
                throw ValidationException::withMessages(["inquiry_response_business_hours.days.$index.closes_at" => ['Closing time must be after opening time.']]);
            }
        }
        if (! empty($data['inquiry_response_business_hours'])
            && (! Schema::hasColumn('sales_policy_settings', 'inquiry_response_business_hours')
                || ! Schema::hasColumn('inquiries', 'first_response_due_at'))) {
            throw ValidationException::withMessages(['inquiry_response_business_hours' => ['Business-hour reply targets need the latest database update. Ask your system administrator to apply the pending update.']]);
        }
        $setting = $this->settings->create($data['policy_kind'], $data, (string) $request->user()->id);

        return response()->json(['status' => 'success', 'data' => $this->writeConfirmation($setting)], 201);
    }

    public function approve(Request $request, SalesPolicySetting $setting): JsonResponse
    {
        $updated = $this->settings->approve($setting->id, (string) $request->user()->id);

        return response()->json(['status' => 'success', 'data' => $this->writeConfirmation($updated)]);
    }

    public function storeFeature(Request $request): JsonResponse
    {
        $data = $request->validate([
            'feature_key' => ['required', Rule::in(SalesPolicySettingsService::FEATURES)],
            'enabled' => ['required', 'boolean'],
            'stage_label' => ['nullable', 'string', 'max:80'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);
        $setting = $this->settings->createFeature($data['feature_key'], $data['enabled'], $data['reason'], (string) $request->user()->id, $data['stage_label'] ?? null);

        return response()->json(['status' => 'success', 'data' => $this->writeConfirmation($setting)], 201);
    }

    public function approveFeature(Request $request, SalesFeatureSetting $feature): JsonResponse
    {
        return response()->json(['status' => 'success', 'data' => $this->writeConfirmation($this->settings->approveFeature($feature->id, (string) $request->user()->id))]);
    }

    public function storeStaffCategory(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category_name' => ['required', 'string', 'max:80'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $category = $this->settings->createStaffCategory($data['category_name'], $data['reason'] ?? null, (string) $request->user()->id);

        return response()->json(['status' => 'success', 'data' => $this->writeConfirmation($category)], 201);
    }

    public function approveStaffCategory(Request $request, SalesStaffCategoryDefinition $category): JsonResponse
    {
        $updated = $this->settings->approveStaffCategory($category->id, (string) $request->user()->id);

        return response()->json(['status' => 'success', 'data' => $this->writeConfirmation($updated)]);
    }

    public function retireStaffCategory(Request $request, SalesStaffCategoryDefinition $category): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $updated = $this->settings->retireStaffCategory($category->id, $data['reason'], (string) $request->user()->id);

        return response()->json(['status' => 'success', 'data' => $this->writeConfirmation($updated)]);
    }

    private function writeConfirmation(object $row): array
    {
        $confirmation = ['id' => (string) $row->id, 'status' => (string) $row->status];
        if (isset($row->version)) $confirmation['version'] = (int) $row->version;

        return $confirmation;
    }

}
