<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Models\Sales\SalesPolicySetting;
use App\Models\Sales\SalesCompanyFeatureSetting;
use App\Models\Sales\SalesStaffCategoryDefinition;
use App\Services\Sales\SalesPolicySettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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
        $query = DB::table('companies')->whereNull('deleted_at')
            ->when($companyIds !== null, fn ($company) => $company->whereIn('id', $companyIds));
        if (! empty($data['selected_id'])) $query->where('id', $data['selected_id']);
        elseif (! empty($data['search'])) {
            $term = '%' . addcslashes($data['search'], '%_\\') . '%';
            $query->where(fn ($company) => $company->where('name', 'like', $term)->orWhere('city', 'like', $term));
        }
        $rows = $query->select(['id', 'name', 'city'])->orderBy('name')->orderBy('id')->paginate($data['per_page'] ?? 25);
        $rows->getCollection()->transform(fn ($company) => ['value' => (string) $company->id, 'label' => $company->name,
            'metadata' => ['city' => $company->city], 'status' => 'active']);
        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['company_id' => ['required', 'uuid', 'exists:companies,id']]);
        $this->assertCompany($request, $data['company_id']);
        $byKind = collect(SalesPolicySettingsService::KINDS)
            ->mapWithKeys(fn(string $kind) => [$kind => $this->settings->history($data['company_id'], $kind)]);

        return response()->json([
            'status' => 'success',
            'data' => [
                'policy_settings' => $byKind,
                'staff_categories' => SalesStaffCategoryDefinition::query()->where('company_id', $data['company_id'])->orderBy('category_name')->get(),
                'company_features' => $this->settings->featureHistory($data['company_id']),
                'deployment_feature_availability' => $this->settings->featureAvailability(),
            ]
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'uuid', 'exists:companies,id'],
            'policy_kind' => ['required', Rule::in(SalesPolicySettingsService::KINDS)],
            'fx_quote_base' => ['required_if:policy_kind,fx_corrections', 'nullable', 'string', 'max:40'],
            'fx_calculation_mode' => ['required_if:policy_kind,fx_corrections', 'nullable', Rule::in(['multiply_source_by_rate', 'divide_source_by_rate'])],
            'fx_max_rate_age_hours' => ['required_if:policy_kind,fx_corrections', 'nullable', 'integer', 'min:1', 'max:8760'],
            'fx_rounding_scale' => ['required_if:policy_kind,fx_corrections', 'nullable', 'integer', 'min:0', 'max:4'],
            'dispute_response_days' => ['required_if:policy_kind,commission_dispute', 'nullable', 'integer', 'min:1', 'max:365'],
            'profile_export_retention_days' => ['required_if:policy_kind,profile_export_retention', 'nullable', 'integer', 'min:1', 'max:3650'],
            'business_timezone' => ['required_if:policy_kind,business_timezone', 'nullable', 'timezone'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $this->assertCompany($request, $data['company_id']);
        $setting = $this->settings->create($data['company_id'], $data['policy_kind'], $data, (string) $request->user()->id);

        return response()->json(['status' => 'success', 'data' => $setting], 201);
    }

    public function approve(Request $request, SalesPolicySetting $setting): JsonResponse
    {
        $this->assertCompany($request, $setting->company_id);
        $updated = $this->settings->approve($setting->id, (string) $request->user()->id);

        return response()->json(['status' => 'success', 'data' => $updated]);
    }

    public function storeFeature(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'uuid', 'exists:companies,id'],
            'feature_key' => ['required', Rule::in(SalesPolicySettingsService::FEATURES)],
            'enabled' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);
        $this->assertCompany($request, $data['company_id']);
        $setting = $this->settings->createFeature($data['company_id'], $data['feature_key'], $data['enabled'], $data['reason'], (string) $request->user()->id);

        return response()->json(['status' => 'success', 'data' => $setting], 201);
    }

    public function approveFeature(Request $request, SalesCompanyFeatureSetting $feature): JsonResponse
    {
        $this->assertCompany($request, $feature->company_id);

        return response()->json(['status' => 'success', 'data' => $this->settings->approveFeature($feature->id, (string) $request->user()->id)]);
    }

    public function storeStaffCategory(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'uuid', 'exists:companies,id'],
            'category_name' => ['required', 'string', 'max:80'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $this->assertCompany($request, $data['company_id']);
        $category = $this->settings->createStaffCategory($data['company_id'], $data['category_name'], $data['reason'] ?? null, (string) $request->user()->id);

        return response()->json(['status' => 'success', 'data' => $category], 201);
    }

    public function approveStaffCategory(Request $request, SalesStaffCategoryDefinition $category): JsonResponse
    {
        $this->assertCompany($request, $category->company_id);
        $updated = $this->settings->approveStaffCategory($category->id, (string) $request->user()->id);

        return response()->json(['status' => 'success', 'data' => $updated]);
    }

    public function retireStaffCategory(Request $request, SalesStaffCategoryDefinition $category): JsonResponse
    {
        $this->assertCompany($request, $category->company_id);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $updated = $this->settings->retireStaffCategory($category->id, $data['reason'], (string) $request->user()->id);

        return response()->json(['status' => 'success', 'data' => $updated]);
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
