<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Models\Sales\SalesPolicySetting;
use App\Models\Sales\SalesCompanyFeatureSetting;
use App\Models\Sales\SalesStaffCategoryDefinition;
use App\Services\Sales\SalesPolicySettingsService;
use App\Services\SingleCompanyScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    public function context(Request $request): JsonResponse
    {
        $companyIds = $this->actorCompanyIds($request);
        $companies = DB::table('companies')->whereNull('deleted_at')
            ->when($companyIds !== null, fn($query) => $query->whereIn('id', $companyIds))
            ->orderBy('name')->get(['id', 'name']);

        return response()->json(['status' => 'success', 'data' => ['companies' => $companies]]);
    }

    public function companyOptions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:120'], 'selected_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $companyIds = $this->actorCompanyIds($request);
        $defaultCompanyId = app(SingleCompanyScope::class)->activeDefaultCompany()?->id;
        if ($defaultCompanyId && $companyIds !== null && ! in_array($defaultCompanyId, $companyIds, true)) $defaultCompanyId = null;
        $query = DB::table('companies')->whereNull('deleted_at')
            ->when($companyIds !== null, fn ($company) => $company->whereIn('id', $companyIds));
        if (! empty($data['selected_id'])) $query->where('id', $data['selected_id']);
        elseif (! empty($data['search'])) {
            $term = '%' . addcslashes($data['search'], '%_\\') . '%';
            $query->where(fn ($company) => $company->where('name', 'like', $term)->orWhere('city', 'like', $term));
        }
        $rows = $query->select(['id', 'name', 'city', 'is_active', 'is_default'])->orderByDesc('is_active')->orderByDesc('is_default')->orderBy('name')->orderBy('id')->paginate($data['per_page'] ?? 25);
        $rows->getCollection()->transform(fn ($company) => ['value' => (string) $company->id, 'label' => $company->name,
            'metadata' => array_filter(['city' => $company->city, 'availability' => $company->is_active ? null : 'Inactive']) + ['is_default' => (bool) $company->is_default],
            'status' => $company->is_active ? 'active' : 'inactive']);
        return response()->json(['status' => 'success', 'data' => $rows, 'default_company_id' => $defaultCompanyId]);
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['company_id' => ['nullable', 'uuid', 'exists:companies,id']]);
        $data['company_id'] = $this->resolveCompanyId($data['company_id'] ?? null);
        $this->assertCompany($request, $data['company_id']);
        $canReviewRationale = $request->user()->can('sales.policy-settings.manage')
            || $request->user()->can('sales.policy-settings.approve');
        $policyColumns = [
            'id', 'policy_kind', 'version', 'status', 'fx_quote_base', 'fx_calculation_mode', 'fx_max_rate_age_hours',
            'fx_rounding_scale', 'dispute_response_days', 'profile_export_retention_days', 'business_timezone', 'inquiry_first_response_minutes', 'booking_assignment_target_minutes', 'repeat_booking_followup_enabled', 'repeat_booking_followup_days', 'approved_at',
        ];
        if (Schema::hasColumn('sales_policy_settings', 'inquiry_response_business_hours')) $policyColumns[] = 'inquiry_response_business_hours';
        $featureColumns = ['id', 'feature_key', 'version', 'enabled', 'status'];
        if (Schema::hasColumn('sales_company_feature_settings', 'stage_label')) $featureColumns[] = 'stage_label';
        $categoryColumns = ['id', 'category_name', 'status'];
        if ($canReviewRationale) {
            $policyColumns[] = 'reason';
            $featureColumns[] = 'reason';
            $categoryColumns[] = 'reason';
        }
        $byKind = collect(SalesPolicySettingsService::KINDS)
            ->mapWithKeys(fn(string $kind) => [$kind => SalesPolicySetting::query()->where('company_id', $data['company_id'])
                ->where('policy_kind', $kind)->orderByDesc('version')->get($policyColumns)]);

        return response()->json([
            'status' => 'success',
            'data' => [
                'policy_settings' => $byKind,
                'staff_categories' => SalesStaffCategoryDefinition::query()->where('company_id', $data['company_id'])
                    ->orderBy('category_name')->get($categoryColumns),
                'company_features' => SalesCompanyFeatureSetting::query()->where('company_id', $data['company_id'])
                    ->orderBy('feature_key')->orderByDesc('version')->get($featureColumns),
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
            'company_id' => ['nullable', 'uuid', 'exists:companies,id'],
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
        $data['company_id'] = $this->resolveCompanyId($data['company_id'] ?? null);
        $this->assertCompany($request, $data['company_id']);
        $setting = $this->settings->create($data['company_id'], $data['policy_kind'], $data, (string) $request->user()->id);

        return response()->json(['status' => 'success', 'data' => $this->writeConfirmation($setting)], 201);
    }

    public function approve(Request $request, SalesPolicySetting $setting): JsonResponse
    {
        $this->assertCompany($request, $setting->company_id);
        $updated = $this->settings->approve($setting->id, (string) $request->user()->id);

        return response()->json(['status' => 'success', 'data' => $this->writeConfirmation($updated)]);
    }

    public function storeFeature(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['nullable', 'uuid', 'exists:companies,id'],
            'feature_key' => ['required', Rule::in(SalesPolicySettingsService::FEATURES)],
            'enabled' => ['required', 'boolean'],
            'stage_label' => ['nullable', 'string', 'max:80'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);
        $data['company_id'] = $this->resolveCompanyId($data['company_id'] ?? null);
        $this->assertCompany($request, $data['company_id']);
        $setting = $this->settings->createFeature($data['company_id'], $data['feature_key'], $data['enabled'], $data['reason'], (string) $request->user()->id, $data['stage_label'] ?? null);

        return response()->json(['status' => 'success', 'data' => $this->writeConfirmation($setting)], 201);
    }

    public function approveFeature(Request $request, SalesCompanyFeatureSetting $feature): JsonResponse
    {
        $this->assertCompany($request, $feature->company_id);

        return response()->json(['status' => 'success', 'data' => $this->writeConfirmation($this->settings->approveFeature($feature->id, (string) $request->user()->id))]);
    }

    public function storeStaffCategory(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['nullable', 'uuid', 'exists:companies,id'],
            'category_name' => ['required', 'string', 'max:80'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $data['company_id'] = $this->resolveCompanyId($data['company_id'] ?? null);
        $this->assertCompany($request, $data['company_id']);
        $category = $this->settings->createStaffCategory($data['company_id'], $data['category_name'], $data['reason'] ?? null, (string) $request->user()->id);

        return response()->json(['status' => 'success', 'data' => $this->writeConfirmation($category)], 201);
    }

    public function approveStaffCategory(Request $request, SalesStaffCategoryDefinition $category): JsonResponse
    {
        $this->assertCompany($request, $category->company_id);
        $updated = $this->settings->approveStaffCategory($category->id, (string) $request->user()->id);

        return response()->json(['status' => 'success', 'data' => $this->writeConfirmation($updated)]);
    }

    public function retireStaffCategory(Request $request, SalesStaffCategoryDefinition $category): JsonResponse
    {
        $this->assertCompany($request, $category->company_id);
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

    private function resolveCompanyId(?string $companyId): string
    {
        $resolved = $companyId ?: app(SingleCompanyScope::class)->activeDefaultCompany()?->id;
        abort_unless($resolved, 409, 'No active default legal entity is configured.');

        return (string) $resolved;
    }

    private function assertCompany(Request $request, string $companyId): void
    {
        abort_unless(DB::table('companies')->where('id', $companyId)->whereNull('deleted_at')->exists(), 422, 'Select an available legal entity.');
        abort_unless(
            $request->user()->can('sales.policy-settings.manage-all') || in_array($companyId, $this->actorCompanyIds($request) ?? [], true),
            403,
            'Sales policy setting is outside your legal entity.',
        );
    }

    /** @return array<int, string>|null */
    private function actorCompanyIds(Request $request): ?array
    {
        if ($request->user()->can('sales.policy-settings.manage-all')) {
            return null;
        }

        return DB::table('staff')->where('user_id', $request->user()->id)->whereNull('deleted_at')
            ->where(fn ($staff) => $staff->whereNull('employment_ended_at')->orWhere('employment_ended_at', '>', now()))
            ->whereNotNull('company_id')->pluck('company_id')->unique()->values()->all();
    }
}
