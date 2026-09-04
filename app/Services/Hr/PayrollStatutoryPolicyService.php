<?php

namespace App\Services\Hr;

use App\Models\Hr\HrEpfEtfContributionPolicy;
use App\Models\Hr\HrGratuityPolicy;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayrollStatutoryPolicyService
{
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

    public function previewContribution(string $companyId, float $totalEarnings, CarbonInterface $at): array
    {
        $policy = $this->resolveEffectiveEpfEtfPolicy($companyId, $at);
        if (! $policy) {
            return [
                'blocked' => true,
                'blocker' => 'No approved EPF/ETF contribution policy is effective for this legal entity and date.',
            ];
        }

        $employeeEpf = round($totalEarnings * (float) $policy->employee_epf_rate_percent / 100, 2);
        $employerEpf = round($totalEarnings * (float) $policy->employer_epf_rate_percent / 100, 2);
        $employerEtf = round($totalEarnings * (float) $policy->employer_etf_rate_percent / 100, 2);

        return [
            'blocked' => false,
            'blocker' => null,
            'policy_id' => $policy->id,
            'policy_version' => $policy->version,
            'total_earnings' => round($totalEarnings, 2),
            'employee_epf_amount' => $employeeEpf,
            'employer_epf_amount' => $employerEpf,
            'employer_etf_amount' => $employerEtf,
            'total_epf_amount' => round($employeeEpf + $employerEpf, 2),
            'net_employer_cost' => round($employerEpf + $employerEtf, 2),
            'earnings_basis' => $policy->earnings_basis,
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

        $grossAmount = $payBasis === 'monthly'
            ? round($wageAmount / (float) $policy->monthly_paid_divisor * $completedYears, 2)
            : round($wageAmount * (float) $policy->non_monthly_daily_wage_multiplier * $completedYears, 2);

        $taxAmount = null;
        $netAmount = $grossAmount;
        if ($policy->tax_exempt_threshold_lkr !== null && $policy->tax_rate_above_threshold_percent !== null) {
            $taxableAmount = max(0.0, $grossAmount - (float) $policy->tax_exempt_threshold_lkr);
            $taxAmount = round($taxableAmount * (float) $policy->tax_rate_above_threshold_percent / 100, 2);
            $netAmount = round($grossAmount - $taxAmount, 2);
        }

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
}
