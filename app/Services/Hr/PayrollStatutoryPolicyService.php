<?php

namespace App\Services\Hr;

use App\Models\Hr\HrEpfEtfContributionPolicy;
use App\Models\Hr\HrGratuityPolicy;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayrollStatutoryPolicyService
{
    private const EARNINGS_BASIS_FIELDS = [
        'include_basic_salary', 'include_cost_of_living_allowance', 'include_food_allowance',
        'include_holiday_pay', 'include_other_regular_allowances', 'exclude_overtime',
        'exclude_bonus', 'exclude_reimbursements',
    ];

    private const EARNINGS_FIELDS = [
        'basic_salary', 'cost_of_living_allowance', 'food_allowance', 'holiday_pay',
        'other_regular_allowances', 'overtime', 'bonus', 'reimbursements',
    ];

    public function listEpfEtfPolicies(string $companyId, ?string $status, int $perPage)
    {
        return HrEpfEtfContributionPolicy::query()
            ->where('company_id', $companyId)
            ->when($status, fn ($query, $value) => $query->where('status', $value))
            ->orderByDesc('version')
            ->paginate($perPage);
    }

    public function createEpfEtfPolicy(array $data, string $companyId, string $actorUserId): HrEpfEtfContributionPolicy
    {
        $data['earnings_basis'] = $this->normalizeEarningsBasis($data['earnings_basis'] ?? null)
            ?? throw ValidationException::withMessages(['earnings_basis' => ['Every earnings inclusion and exclusion must be explicitly selected.']]);

        return DB::transaction(function () use ($data, $companyId, $actorUserId) {
            DB::table('companies')->where('id', $companyId)->lockForUpdate()->first();
            $version = (int) HrEpfEtfContributionPolicy::query()
                ->where('company_id', $companyId)->lockForUpdate()->max('version') + 1;

            return HrEpfEtfContributionPolicy::create($data + [
                'company_id' => $companyId,
                'version' => $version,
                'status' => 'draft',
                'created_by' => $actorUserId,
            ]);
        });
    }

    public function approveEpfEtfPolicy(string $policyId, string $companyId, string $actorUserId): HrEpfEtfContributionPolicy
    {
        return DB::transaction(function () use ($policyId, $companyId, $actorUserId) {
            DB::table('companies')->where('id', $companyId)->lockForUpdate()->first();
            $policy = HrEpfEtfContributionPolicy::query()->where('company_id', $companyId)
                ->lockForUpdate()->findOrFail($policyId);
            abort_unless($policy->status === 'draft', 422, 'Only a draft EPF/ETF contribution policy can be approved.');
            abort_unless($this->epfEtfPolicyIsComplete($policy), 422, 'Explicit EPF/ETF rates, a complete earnings basis, and a statutory reference are required before approval.');
            abort_if($policy->created_by === $actorUserId, 409, 'The policy preparer cannot approve the same version.');
            $overlap = HrEpfEtfContributionPolicy::query()
                ->where('company_id', $companyId)->where('status', 'approved')->where('id', '!=', $policy->id)
                ->where('effective_from', '<', $policy->effective_until ?? '9999-12-31')
                ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $policy->effective_from))
                ->exists();
            abort_if($overlap, 422, 'An approved EPF/ETF contribution policy already overlaps this effective period.');
            $policy->update(['status' => 'approved', 'approved_by' => $actorUserId, 'approved_at' => now()]);

            return $policy->fresh();
        });
    }

    public function resolveEffectiveEpfEtfPolicy(string $companyId, CarbonInterface $at): ?HrEpfEtfContributionPolicy
    {
        return HrEpfEtfContributionPolicy::query()
            ->where('company_id', $companyId)->where('status', 'approved')
            ->where('effective_from', '<=', $at)
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $at))
            ->orderByDesc('version')->first();
    }

    public function previewContribution(string $companyId, array $earnings, CarbonInterface $at): array
    {
        $policy = $this->resolveEffectiveEpfEtfPolicy($companyId, $at);
        if (! $policy) {
            return [
                'blocked' => true,
                'blocker' => 'No approved EPF/ETF contribution policy is effective for this legal entity and date.',
            ];
        }

        $basis = $this->normalizeEarningsBasis($policy->earnings_basis);
        if (! $this->epfEtfPolicyIsComplete($policy) || $basis === null) {
            return [
                'blocked' => true,
                'blocker' => 'The approved EPF/ETF policy is missing explicit rates, earnings-basis choices, or a statutory reference.',
                'policy_id' => $policy->id,
                'policy_version' => $policy->version,
            ];
        }
        if (array_diff(array_keys($earnings), self::EARNINGS_FIELDS)) {
            throw ValidationException::withMessages(['earnings' => ['Only the defined earnings categories may be submitted.']]);
        }

        foreach (self::EARNINGS_FIELDS as $field) {
            if (! array_key_exists($field, $earnings) || ! is_numeric($earnings[$field])
                || ! is_finite((float) $earnings[$field]) || (float) $earnings[$field] < 0) {
                throw ValidationException::withMessages(["earnings.{$field}" => ['Provide a finite, non-negative amount for every earnings category.']]);
            }
        }

        $assessableEarnings = 0.0;
        foreach ([
            'include_basic_salary' => 'basic_salary',
            'include_cost_of_living_allowance' => 'cost_of_living_allowance',
            'include_food_allowance' => 'food_allowance',
            'include_holiday_pay' => 'holiday_pay',
            'include_other_regular_allowances' => 'other_regular_allowances',
        ] as $rule => $field) {
            if ($basis[$rule]) $assessableEarnings += (float) $earnings[$field];
        }
        foreach ([
            'exclude_overtime' => 'overtime',
            'exclude_bonus' => 'bonus',
            'exclude_reimbursements' => 'reimbursements',
        ] as $rule => $field) {
            if (! $basis[$rule]) $assessableEarnings += (float) $earnings[$field];
        }
        if (! is_finite($assessableEarnings)) {
            throw ValidationException::withMessages(['earnings' => ['The configured earnings basis exceeds the supported numeric range.']]);
        }

        $employeeEpf = round($assessableEarnings * (float) $policy->employee_epf_rate_percent / 100, 2);
        $employerEpf = round($assessableEarnings * (float) $policy->employer_epf_rate_percent / 100, 2);
        $employerEtf = round($assessableEarnings * (float) $policy->employer_etf_rate_percent / 100, 2);

        return [
            'blocked' => false,
            'blocker' => null,
            'policy_id' => $policy->id,
            'policy_version' => $policy->version,
            'assessable_earnings' => round($assessableEarnings, 2),
            'earnings_basis' => $basis,
            'employee_epf_amount' => $employeeEpf,
            'employer_epf_amount' => $employerEpf,
            'employer_etf_amount' => $employerEtf,
            'total_epf_amount' => round($employeeEpf + $employerEpf, 2),
            'net_employer_cost' => round($employerEpf + $employerEtf, 2),
            'statutory_reference' => $policy->statutory_reference,
        ];
    }

    public function listGratuityPolicies(string $companyId, ?string $status, int $perPage)
    {
        return HrGratuityPolicy::query()
            ->where('company_id', $companyId)
            ->when($status, fn ($query, $value) => $query->where('status', $value))
            ->orderByDesc('version')
            ->paginate($perPage);
    }

    public function createGratuityPolicy(array $data, string $companyId, string $actorUserId): HrGratuityPolicy
    {
        return DB::transaction(function () use ($data, $companyId, $actorUserId) {
            DB::table('companies')->where('id', $companyId)->lockForUpdate()->first();
            $version = (int) HrGratuityPolicy::query()
                ->where('company_id', $companyId)->lockForUpdate()->max('version') + 1;

            return HrGratuityPolicy::create($data + [
                'company_id' => $companyId,
                'version' => $version,
                'status' => 'draft',
                'created_by' => $actorUserId,
            ]);
        });
    }

    public function approveGratuityPolicy(string $policyId, string $companyId, string $actorUserId): HrGratuityPolicy
    {
        return DB::transaction(function () use ($policyId, $companyId, $actorUserId) {
            DB::table('companies')->where('id', $companyId)->lockForUpdate()->first();
            $policy = HrGratuityPolicy::query()->where('company_id', $companyId)
                ->lockForUpdate()->findOrFail($policyId);
            abort_unless($policy->status === 'draft', 422, 'Only a draft gratuity policy can be approved.');
            abort_unless($policy->tax_exempt_threshold_lkr !== null && $policy->tax_rate_above_threshold_percent !== null,
                422, 'An explicit statutory tax threshold and rate are required before approval.');
            abort_unless($this->gratuityPolicyIsComplete($policy), 422, 'Gratuity formula values and statutory reference must be complete and within accepted ranges before approval.');
            abort_if($policy->created_by === $actorUserId, 409, 'The policy preparer cannot approve the same version.');
            $overlap = HrGratuityPolicy::query()
                ->where('company_id', $companyId)->where('status', 'approved')->where('id', '!=', $policy->id)
                ->where('effective_from', '<', $policy->effective_until ?? '9999-12-31')
                ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $policy->effective_from))
                ->exists();
            abort_if($overlap, 422, 'An approved gratuity policy already overlaps this effective period.');
            $policy->update(['status' => 'approved', 'approved_by' => $actorUserId, 'approved_at' => now()]);

            return $policy->fresh();
        });
    }

    public function resolveEffectiveGratuityPolicy(string $companyId, CarbonInterface $at): ?HrGratuityPolicy
    {
        return HrGratuityPolicy::query()
            ->where('company_id', $companyId)->where('status', 'approved')
            ->where('effective_from', '<=', $at)
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $at))
            ->orderByDesc('version')->first();
    }

    public function previewGratuityEntitlement(
        string $companyId,
        string $payBasis,
        float $wageAmount,
        int $completedYears,
        ?int $currentEmployerHeadcount,
        CarbonInterface $at,
    ): array {
        if (! in_array($payBasis, ['monthly', 'non_monthly'], true)) {
            throw ValidationException::withMessages(['pay_basis' => ['pay_basis must be monthly or non_monthly.']]);
        }
        $policy = $this->resolveEffectiveGratuityPolicy($companyId, $at);
        if (! $policy) {
            return [
                'blocked' => true,
                'blocker' => 'No approved gratuity policy is effective for this legal entity and date.',
            ];
        }
        if ($completedYears < $policy->minimum_qualifying_service_years) {
            return [
                'blocked' => true,
                'blocker' => "Completed service ({$completedYears} years) is below the configured minimum qualifying service of {$policy->minimum_qualifying_service_years} years.",
                'policy_id' => $policy->id,
                'policy_version' => $policy->version,
            ];
        }
        if ($policy->tax_exempt_threshold_lkr === null || $policy->tax_rate_above_threshold_percent === null) {
            return [
                'blocked' => true,
                'blocker' => 'The approved gratuity policy is missing an explicit statutory tax threshold or rate.',
                'policy_id' => $policy->id,
                'policy_version' => $policy->version,
            ];
        }
        if (! $this->gratuityPolicyIsComplete($policy)) {
            return [
                'blocked' => true,
                'blocker' => 'The approved gratuity policy has incomplete or out-of-range formula values or statutory reference.',
                'policy_id' => $policy->id,
                'policy_version' => $policy->version,
            ];
        }
        if ($currentEmployerHeadcount === null) {
            return [
                'blocked' => true,
                'blocker' => 'Current employer headcount is required to evaluate the approved gratuity policy threshold.',
                'policy_id' => $policy->id,
                'policy_version' => $policy->version,
            ];
        }
        if ($currentEmployerHeadcount < $policy->minimum_employer_headcount_threshold) {
            return [
                'blocked' => true,
                'blocker' => 'Current employer headcount is below the approved gratuity policy threshold.',
                'policy_id' => $policy->id,
                'policy_version' => $policy->version,
            ];
        }

        $grossAmount = $payBasis === 'monthly'
            ? round($wageAmount / (float) $policy->monthly_paid_divisor * $completedYears, 2)
            : round($wageAmount * (float) $policy->non_monthly_daily_wage_multiplier * $completedYears, 2);

        $taxableAmount = max(0.0, $grossAmount - (float) $policy->tax_exempt_threshold_lkr);
        $taxAmount = round($taxableAmount * (float) $policy->tax_rate_above_threshold_percent / 100, 2);
        $netAmount = round($grossAmount - $taxAmount, 2);

        return [
            'blocked' => false,
            'blocker' => null,
            'policy_id' => $policy->id,
            'policy_version' => $policy->version,
            'pay_basis' => $payBasis,
            'completed_years' => $completedYears,
            'gross_gratuity_amount_lkr' => $grossAmount,
            'tax_amount_lkr' => $taxAmount,
            'net_gratuity_amount_lkr' => $netAmount,
            'employer_meets_headcount_threshold' => $currentEmployerHeadcount === null
                ? null
                : $currentEmployerHeadcount >= $policy->minimum_employer_headcount_threshold,
            'minimum_employer_headcount_threshold' => $policy->minimum_employer_headcount_threshold,
            'payment_deadline_days' => $policy->payment_deadline_days,
            'statutory_reference' => $policy->statutory_reference,
        ];
    }

    private function normalizeEarningsBasis(mixed $basis): ?array
    {
        if (! is_array($basis) || array_diff(array_keys($basis), self::EARNINGS_BASIS_FIELDS)) return null;
        $normalized = [];
        foreach (self::EARNINGS_BASIS_FIELDS as $field) {
            if (! array_key_exists($field, $basis) || ! in_array($basis[$field], [true, false, 1, 0, '1', '0'], true)) return null;
            $normalized[$field] = filter_var($basis[$field], FILTER_VALIDATE_BOOLEAN);
        }

        return $normalized;
    }

    private function epfEtfPolicyIsComplete(HrEpfEtfContributionPolicy $policy): bool
    {
        foreach (['employee_epf_rate_percent', 'employer_epf_rate_percent', 'employer_etf_rate_percent'] as $field) {
            if (! is_numeric($policy->{$field}) || (float) $policy->{$field} < 0 || (float) $policy->{$field} > 100) return false;
        }

        return $this->normalizeEarningsBasis($policy->earnings_basis) !== null
            && is_string($policy->statutory_reference) && trim($policy->statutory_reference) !== '';
    }

    private function gratuityPolicyIsComplete(HrGratuityPolicy $policy): bool
    {
        foreach ([
            'minimum_qualifying_service_years' => [1, 50],
            'minimum_employer_headcount_threshold' => [1, 100000],
            'monthly_paid_divisor' => [0.01, 12],
            'non_monthly_daily_wage_multiplier' => [0.01, 365],
            'non_monthly_lookback_months' => [1, 36],
            'payment_deadline_days' => [1, 365],
            'tax_exempt_threshold_lkr' => [0, null],
            'tax_rate_above_threshold_percent' => [0, 100],
        ] as $field => [$minimum, $maximum]) {
            $value = $policy->{$field};
            if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value < $minimum
                || ($maximum !== null && (float) $value > $maximum)) return false;
        }

        return is_string($policy->statutory_reference) && trim($policy->statutory_reference) !== '';
    }
}
