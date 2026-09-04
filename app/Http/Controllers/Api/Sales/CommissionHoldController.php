<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Models\Sales\SalesCommissionDecision;
use App\Services\Sales\CommissionHoldService;
use App\Services\Sales\CommissionHoldAdjustmentService;
use App\Services\Sales\CommissionHoldRemediationService;
use App\Services\Sales\SalesCommissionNotificationService;
use App\Services\Sales\SalesAccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['nullable', 'uuid'],
            'hold_code' => ['nullable', 'string', 'max:80'],
            'category' => ['nullable', Rule::in($this->remediation->categories())],
            'resolution' => ['nullable', Rule::in(['open', 'released', 'all'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = SalesCommissionDecision::query()->whereIn('status', ['held', 'shadow_held'])
            ->with(['holdRelease' => fn ($q) => $q->select([
                'id', 'commission_decision_id', 'release_kind', 'receipt_finality_event_id',
                'original_hold_code', 'formula_kind', 'commission_amount_lkr',
                'calculation_explanation', 'calculation_checksum', 'release_reason', 'released_at',
            ]), 'holdAdjustment' => fn ($q) => $q->select([
                'id', 'commission_decision_id', 'adjustment_kind', 'original_hold_code',
                'commission_adjustment_lkr', 'calculation_checksum', 'reason', 'adjustment_effective_at',
            ]), 'holdResolution' => fn ($q) => $q->select([
                'id', 'commission_decision_id', 'receipt_finality_event_id', 'resolution_kind',
                'original_hold_code', 'resolution_checksum', 'resolved_at',
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
        $rows->getCollection()->transform(function (SalesCommissionDecision $decision) use ($request) {
            $decision->setAttribute('remediation', $this->remediation->describe($decision, $request->user()));
            $decision->setAttribute('notification_delivery', $this->notifications->latestStatus($decision->id));
            return $decision;
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
        $earning = $row->fresh()->load(['holdRelease' => fn ($q) => $q->select([
            'id', 'commission_decision_id', 'release_kind', 'receipt_finality_event_id',
            'original_hold_code', 'formula_kind', 'commission_amount_lkr',
            'calculation_explanation', 'calculation_checksum', 'release_reason', 'released_at',
        ])]);
        return response()->json(['status' => 'success', 'data' => $earning], 201);
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
        return response()->json(['status' => 'success', 'data' => $adjustment], 201);
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
