<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Models\Sales\SalesCommissionRecoveryCase;
use App\Services\Sales\CommissionRecoveryService;
use App\Services\Sales\SalesAccessScope;
use App\Services\PermissionEvaluator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CommissionRecoveryController extends Controller
{
    public function __construct(
        private readonly SalesAccessScope $access,
        private readonly PermissionEvaluator $permissions,
    ) {}

    public function companyOptions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:120'], 'selected_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $companyIds = $this->access->companyIds($request->user(), 'sales.commission-recoveries.view-all');
        $query = DB::table('companies')->whereNull('deleted_at')->where('is_active', true)
            ->when($companyIds !== null, fn ($query) => $query->whereIn('id', $companyIds))
            ->select(['id', 'name', 'is_default']);
        $defaultCompanyId = (clone $query)->where('is_default', true)->value('id');
        $options = (clone $query)
            ->when($data['selected_id'] ?? null, fn ($q, $id) => $q->where('id', $id))
            ->when(empty($data['selected_id']) && ! empty($data['search']), function ($q) use ($data) {
                $term = '%' . addcslashes($data['search'], '%_\\') . '%';
                $q->where('name', 'like', $term);
            })
            ->orderByDesc('is_default')->orderBy('name')->paginate($data['per_page'] ?? 25);
        $options->getCollection()->transform(fn ($company) => [
            'value' => (string) $company->id, 'label' => $company->name,
            'is_default' => (bool) $company->is_default, 'is_active' => true, 'status' => 'active',
        ]);

        return response()->json(['status' => 'success', 'data' => $options, 'default_company_id' => $defaultCompanyId]);
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['nullable', 'uuid', Rule::exists('companies', 'id')->whereNull('deleted_at')->where('is_active', true)],
            'status' => ['nullable', Rule::in(['pending_review', 'deduct_approved', 'credit_approved', 'no_change_acknowledged', 'waived'])],
            'recovery_kind' => ['nullable', Rule::in(['cash_decrease', 'reporting_fx'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        if (! empty($data['company_id'])) {
            $this->access->assertCompany($request->user(), $data['company_id'], 'sales.commission-recoveries.view-all');
        }
        $query = SalesCommissionRecoveryCase::query()->with([
            'decision', 'beneficiarySalesProfile:id,company_id,sales_code',
        ])->select([
            'id', 'company_id', 'beneficiary_sales_profile_id', 'event_version', 'recovery_kind', 'status',
            'commission_category', 'collection_cohort', 'entitlement_source_type', 'entitlement_amount_lkr',
            'reporting_lkr_delta', 'proposed_commission_adjustment_lkr', 'original_formula_kind',
            'calculation_status', 'calculation_explanation',
        ]);
        $profileIds = $this->profileIds($request, $data['company_id'] ?? null);
        if ($profileIds !== null) $query->whereIn('beneficiary_sales_profile_id', $profileIds);
        $cases = $query
            ->when($data['company_id'] ?? null, fn ($q, $id) => $q->where('company_id', $id))
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($data['recovery_kind'] ?? null, fn ($q, $kind) => $q->where('recovery_kind', $kind))
            ->latest('opened_at')->paginate($request->integer('per_page', 25));
        $cases->getCollection()->transform(static function (SalesCommissionRecoveryCase $case): array {
            $profile = $case->beneficiarySalesProfile;
            return $case->only([
                'id', 'event_version', 'recovery_kind', 'status', 'commission_category', 'collection_cohort',
                'entitlement_source_type', 'entitlement_amount_lkr', 'reporting_lkr_delta',
                'proposed_commission_adjustment_lkr', 'original_formula_kind', 'calculation_status',
                'calculation_explanation',
            ]) + [
                'beneficiary_sales_code' => $profile?->company_id === $case->company_id ? $profile->sales_code : null,
                'decision' => $case->decision?->only(['resolution_disposition', 'commission_adjustment_lkr']),
            ];
        });

        return response()->json(['status' => 'success', 'data' => $cases]);
    }

    public function previewDecision(
        Request $request,
        SalesCommissionRecoveryCase $case,
        CommissionRecoveryService $recoveries,
    ): JsonResponse {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['deduct', 'credit', 'waive', 'acknowledge_no_change'])],
        ]);
        $this->authorizeCaseScope($request, $case);
        return response()->json(['status' => 'success', 'data' => $recoveries->previewDecision($case, $data['decision'])]);
    }

    public function decide(Request $request, SalesCommissionRecoveryCase $case, CommissionRecoveryService $recoveries): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['deduct', 'credit', 'waive', 'acknowledge_no_change'])],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'expected_version' => ['required', 'integer', 'min:1'],
            'preview_checksum' => ['required', 'string', 'size:64'],
            'idempotency_key' => ['required', 'string', 'max:160'],
        ]);
        $this->authorizeCaseScope($request, $case);
        $permission = $case->recovery_kind === 'cash_decrease'
            ? ($data['decision'] === 'waive' ? 'sales.refunds.waive' : 'sales.refunds.decide')
            : 'sales.commission-recoveries.decide';
        abort_unless($this->permissions->userHasAnyForInternalContext($request->user(), [$permission]), 403,
            'This commission refund decision requires its dedicated internal authority.');
        $decision = $recoveries->decide(
            $case, $data['decision'], $data['reason'], $data['expected_version'], $data['preview_checksum'],
            $data['idempotency_key'], (string) $request->user()->id,
        );
        return response()->json(['status' => 'success', 'data' => $decision->only(['id'])]);
    }

    private function authorizeCaseScope(Request $request, SalesCommissionRecoveryCase $case): void
    {
        $profileIds = $this->profileIds($request, $case->company_id);
        abort_unless($profileIds === null || in_array($case->beneficiary_sales_profile_id, $profileIds, true), 403,
            'Recovery case is outside your permitted Sales scope.');
    }

    private function profileIds(Request $request, ?string $companyId = null): ?array
    {
        return $this->access->profileIds($request->user(), 'sales.commission-recoveries.view-all',
            'sales.commission-recoveries.view-team', $companyId);
    }
}
