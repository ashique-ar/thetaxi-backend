<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\HrEpfEtfContributionPolicy;
use App\Models\Hr\HrGratuityPolicy;
use App\Services\Hr\PeopleAccessService;
use App\Services\Hr\PayrollStatutoryPolicyService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PayrollStatutoryController extends Controller
{
    public function __construct(
        private readonly PeopleAccessService $access,
        private readonly PayrollStatutoryPolicyService $policies,
    )
    {
    }

    public function context(Request $request): JsonResponse
    {
        $this->ensureEnabled();
        $companyId = $this->access->actorCompanyId($request->user());

        return response()->json([
            'status' => 'success',
            'data' => [
                'company' => DB::table('companies')->whereKey($companyId)->first(['id', 'name']),
            ]
        ]);
    }

    public function epfEtfPolicies(Request $request): JsonResponse
    {
        $this->ensureEnabled();
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['draft', 'approved', 'retired'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $companyId = $this->access->actorCompanyId($request->user());

        return response()->json([
            'status' => 'success',
            'data' => $this->policies
                ->listEpfEtfPolicies($companyId, $data['status'] ?? null, (int) ($data['per_page'] ?? 20))
        ]);
    }

    public function storeEpfEtfPolicy(Request $request): JsonResponse
    {
        $this->ensureEnabled();
        $data = $request->validate($this->epfEtfRules());
        $companyId = $this->access->actorCompanyId($request->user());
        $policy = $this->policies->createEpfEtfPolicy($data, $companyId, (string) $request->user()->id);

        return response()->json(['status' => 'success', 'data' => $policy], 201);
    }

    public function approveEpfEtfPolicy(Request $request, HrEpfEtfContributionPolicy $policy): JsonResponse
    {
        $this->ensureEnabled();
        $companyId = $this->access->actorCompanyId($request->user());
        $policy = $this->policies->approveEpfEtfPolicy($policy->id, $companyId, (string) $request->user()->id);

        return response()->json(['status' => 'success', 'data' => $policy]);
    }  

    public function previewEpfEtfContribution(Request $request): JsonResponse
    {
        $this->ensureEnabled();
        $data = $request->validate([
            'total_earnings' => ['required', 'numeric', 'min:0'],
            'as_of' => ['nullable', 'date'],
        ]);
        $companyId = $this->access->actorCompanyId($request->user());
        $at = isset($data['as_of']) ? Carbon::parse($data['as_of']) : now();

        return response()->json([
            'status' => 'success',
            'data' => $this->policies
                ->previewContribution($companyId, (float) $data['total_earnings'], $at)
        ]);
    }

    public function gratuityPolicies(Request $request): JsonResponse
    {
        $this->ensureEnabled();
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['draft', 'approved', 'retired'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $companyId = $this->access->actorCompanyId($request->user());

        return response()->json([
            'status' => 'success',
            'data' => $this->policies
                ->listGratuityPolicies($companyId, $data['status'] ?? null, (int) ($data['per_page'] ?? 20))
        ]);
    }

    public function storeGratuityPolicy(Request $request): JsonResponse
    {
        $this->ensureEnabled();
        $data = $request->validate($this->gratuityRules());
        $companyId = $this->access->actorCompanyId($request->user());
        $policy = $this->policies->createGratuityPolicy($data, $companyId, (string) $request->user()->id);

        return response()->json(['status' => 'success', 'data' => $policy], 201);
    }

    public function approveGratuityPolicy(Request $request, HrGratuityPolicy $policy): JsonResponse
    {
        $this->ensureEnabled();
        $companyId = $this->access->actorCompanyId($request->user());
        $policy = $this->policies->approveGratuityPolicy($policy->id, $companyId, (string) $request->user()->id);

        return response()->json(['status' => 'success', 'data' => $policy]);
    }

    public function previewGratuityEntitlement(Request $request): JsonResponse
    {
        $this->ensureEnabled();
        $data = $request->validate([
            'pay_basis' => ['required', Rule::in(['monthly', 'non_monthly'])],
            'wage_amount' => ['required', 'numeric', 'min:0'],
            'completed_years' => ['required', 'integer', 'min:0', 'max:80'],
            'current_employer_headcount' => ['nullable', 'integer', 'min:0'],
            'as_of' => ['nullable', 'date'],
        ]);
        $companyId = $this->access->actorCompanyId($request->user());
        $at = isset($data['as_of']) ? Carbon::parse($data['as_of']) : now();

        return response()->json([
            'status' => 'success',
            'data' => $this->policies->previewGratuityEntitlement(
                $companyId,
                $data['pay_basis'],
                (float) $data['wage_amount'],
                (int) $data['completed_years'],
                isset($data['current_employer_headcount']) ? (int) $data['current_employer_headcount'] : null,
                $at,
            )
        ]);
    }

    private function epfEtfRules(): array
    {
        return [
            'employee_epf_rate_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'employer_epf_rate_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'employer_etf_rate_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'earnings_basis' => ['required', 'array'],
            'earnings_basis.include_basic_salary' => ['required', 'boolean'],
            'earnings_basis.include_cost_of_living_allowance' => ['required', 'boolean'],
            'earnings_basis.include_food_allowance' => ['required', 'boolean'],
            'earnings_basis.include_holiday_pay' => ['required', 'boolean'],
            'earnings_basis.include_other_regular_allowances' => ['required', 'boolean'],
            'earnings_basis.exclude_overtime' => ['required', 'boolean'],
            'earnings_basis.exclude_bonus' => ['required', 'boolean'],
            'earnings_basis.exclude_reimbursements' => ['required', 'boolean'],
            'statutory_reference' => ['required', 'string', 'max:255'],
            'effective_from' => ['required', 'date'],
            'effective_until' => ['nullable', 'date', 'after:effective_from'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }

    private function gratuityRules(): array
    {
        return [
            'minimum_qualifying_service_years' => ['required', 'integer', 'min:1', 'max:50'],
            'minimum_employer_headcount_threshold' => ['required', 'integer', 'min:1', 'max:100000'],
            'monthly_paid_divisor' => ['required', 'numeric', 'min:0.01', 'max:12'],
            'non_monthly_daily_wage_multiplier' => ['required', 'numeric', 'min:0.01', 'max:365'],
            'non_monthly_lookback_months' => ['required', 'integer', 'min:1', 'max:36'],
            'payment_deadline_days' => ['required', 'integer', 'min:1', 'max:365'],
            'tax_exempt_threshold_lkr' => ['nullable', 'numeric', 'min:0'],
            'tax_rate_above_threshold_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'statutory_reference' => ['required', 'string', 'max:255'],
            'effective_from' => ['required', 'date'],
            'effective_until' => ['nullable', 'date', 'after:effective_from'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }

    private function ensureEnabled(): void
    {
        abort_unless(config('hr.features.payroll', false), 409, 'HR Payroll is not enabled.');
    }
}
