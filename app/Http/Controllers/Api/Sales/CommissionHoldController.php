<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Models\Sales\SalesCommissionDecision;
use App\Services\Sales\CommissionHoldService;
use App\Services\Sales\CommissionHoldAdjustmentService;
use App\Services\Sales\CommissionHoldRemediationService;
use App\Services\Sales\SalesCommissionNotificationService;
use App\Services\Sales\SalesAccessScope;
use App\Services\SingleCompanyScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CommissionHoldController extends Controller
{
    public function __construct(
        private readonly SalesAccessScope $scope,
        private readonly CommissionHoldService $holds,
        private readonly CommissionHoldAdjustmentService $adjustments,
        private readonly CommissionHoldRemediationService $remediation,
        private readonly SalesCommissionNotificationService $notifications,
    ) {}

    public function companyOptions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:120'], 'selected_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $companyIds = $this->scope->companyIds($request->user(), 'sales.commission-decisions.view-all');
        $defaultCompanyId = app(SingleCompanyScope::class)->activeDefaultCompany()?->id;
        if ($defaultCompanyId && $companyIds !== null && ! in_array($defaultCompanyId, $companyIds, true)) $defaultCompanyId = null;
        $query = DB::table('companies')->whereNull('deleted_at')->where('is_active', true)
            ->when($companyIds !== null, fn ($query) => $query->whereIn('id', $companyIds))
            ->select(['id', 'name', 'is_default']);
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
            'hold_code' => ['nullable', 'string', 'max:80'],
            'category' => ['nullable', Rule::in($this->remediation->categories())],
            'resolution' => ['nullable', Rule::in(['open', 'released', 'all'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = SalesCommissionDecision::query()->whereIn('status', ['held', 'shadow_held'])
            ->select([
                'id', 'booking_id', 'company_id', 'beneficiary_sales_profile_id', 'beneficiary_staff_id',
                'eligible_lkr_amount', 'status', 'hold_code', 'calculation_explanation', 'decision_at', 'event_version',
            ])
            ->with(['booking:id,booking_number', 'holdRelease' => fn ($q) => $q->select([
                'id', 'commission_decision_id', 'release_kind', 'commission_amount_lkr', 'released_at',
            ]), 'holdAdjustment' => fn ($q) => $q->select([
                'id', 'commission_decision_id', 'commission_adjustment_lkr', 'adjustment_effective_at',
            ]), 'holdResolution' => fn ($q) => $q->select([
                'id', 'commission_decision_id', 'resolution_kind', 'employment_ended_at', 'resolution_checksum', 'resolved_at',
            ])]);
        $this->scope($query, $request, $data['company_id'] ?? null);
        $resolution = $data['resolution'] ?? 'open';
        $query->when($data['company_id'] ?? null, fn ($q, $id) => $q->where('company_id', $id))
            ->when($data['hold_code'] ?? null, fn ($q, $code) => $q->where('hold_code', $code))
            ->when($data['category'] ?? null, function ($q, $category) {
                return $category === 'unclassified'
                    ? $q->whereNotIn('hold_code', $this->remediation->knownCodes())
                    : $q->whereIn('hold_code', $this->remediation->codesForCategory($category));
            })
            ->when($resolution === 'open', fn ($q) => $q->whereDoesntHave('holdRelease')
                ->whereDoesntHave('holdAdjustment')->whereDoesntHave('holdResolution'))
            ->when($resolution === 'released', fn ($q) => $q->where(fn ($resolved) => $resolved
                ->whereHas('holdRelease')->orWhereHas('holdAdjustment')->orWhereHas('holdResolution')));

        $rows = $query->latest('decision_at')->paginate($request->integer('per_page', 25));
        $rows->getCollection()->transform(function (SalesCommissionDecision $decision) use ($request): array {
            return $decision->only([
                'id', 'eligible_lkr_amount', 'status', 'hold_code', 'calculation_explanation', 'decision_at', 'event_version',
            ]) + [
                'booking' => ['booking_number' => $decision->booking?->booking_number],
                'remediation' => Arr::only($this->remediation->describe($decision, $request->user()), [
                    'category', 'canonical_owner', 'action_authorized', 'action_path', 'action_label',
                    'release_preview_applicable', 'linked_adjustment_preview_applicable', 'guidance',
                ]),
                'notification_delivery' => $this->notifications->latestStatus($decision->id),
                'hold_release' => $decision->holdRelease?->only(['release_kind', 'commission_amount_lkr', 'released_at']),
                'hold_adjustment' => $decision->holdAdjustment?->only(['commission_adjustment_lkr', 'adjustment_effective_at']),
                'hold_resolution' => $decision->holdResolution?->only([
                    'resolution_kind', 'employment_ended_at', 'resolution_checksum', 'resolved_at',
                ]),
            ];
        });

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function preview(Request $request, string $earning): JsonResponse
    {
        $row = $this->scopedDecision($request, $earning);
        return response()->json(['status' => 'success', 'data' => $this->holds->preview($row)]);
    }

    public function release(Request $request, string $earning): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'expected_version' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'string', 'max:160'],
        ]);
        $row = $this->scopedDecision($request, $earning);
        $this->holds->release($row->id, $data['expected_version'], $data['reason'], $data['idempotency_key'], $request->user()->id);
        return response()->json(['status' => 'success', 'data' => $row->only(['id'])], 201);
    }

    public function adjustmentPreview(Request $request, string $earning): JsonResponse
    {
        $row = $this->scopedDecision($request, $earning);
        return response()->json(['status' => 'success', 'data' => $this->adjustments->preview($row)]);
    }

    public function appendAdjustment(Request $request, string $earning): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'expected_version' => ['required', 'integer', 'min:1'],
            'preview_checksum' => ['required', 'string', 'size:64'],
            'idempotency_key' => ['required', 'string', 'max:160'],
        ]);
        $row = $this->scopedDecision($request, $earning);
        $adjustment = $this->adjustments->append($row->id, $data['expected_version'], $data['preview_checksum'],
            $data['reason'], $data['idempotency_key'], (string) $request->user()->id);
        return response()->json(['status' => 'success', 'data' => $adjustment->only(['id'])], 201);
    }

    private function scopedDecision(Request $request, string $id): SalesCommissionDecision
    {
        $query = SalesCommissionDecision::query()->whereKey($id)->whereIn('status', ['held', 'shadow_held']);
        $this->scope($query, $request, null);
        return $query->firstOrFail();
    }

    private function scope($query, Request $request, ?string $companyId): void
    {
        $ids = $this->scope->profileIds($request->user(), 'sales.commission-decisions.view-all',
            'sales.commission-decisions.view-team', $companyId);
        if ($ids !== null) $query->whereIn('beneficiary_sales_profile_id', $ids);
    }
}
