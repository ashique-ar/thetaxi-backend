<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Models\Sales\SalesCommissionRecoveryCase;
use App\Services\Sales\CommissionRecoveryService;
use App\Services\Sales\SalesAccessScope;
use App\Services\PermissionEvaluator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CommissionRecoveryController extends Controller
{
    public function __construct(
        private readonly SalesAccessScope $access,
        private readonly PermissionEvaluator $permissions,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['pending_review', 'deduct_approved', 'credit_approved', 'no_change_acknowledged', 'waived'])],
            'recovery_kind' => ['nullable', Rule::in(['cash_decrease', 'reporting_fx'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = SalesCommissionRecoveryCase::query()->with('decision');
        $profileIds = $this->profileIds($request);
        if ($profileIds !== null) $query->whereIn('beneficiary_sales_profile_id', $profileIds);
        return response()->json(['status' => 'success', 'data' => $query
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($data['recovery_kind'] ?? null, fn ($q, $kind) => $q->where('recovery_kind', $kind))
            ->latest('opened_at')->paginate($request->integer('per_page', 25))]);
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
        return response()->json(['status' => 'success', 'data' => $recoveries->decide(
            $case, $data['decision'], $data['reason'], $data['expected_version'], $data['preview_checksum'],
            $data['idempotency_key'], (string) $request->user()->id,
        )]);
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
