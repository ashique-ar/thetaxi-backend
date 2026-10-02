<?php

namespace App\Services\Sales;

use App\Models\Sales\SalesCommissionPlanTier;
use App\Models\Sales\SalesCommissionPlanVersion;
use App\Models\Sales\SalesCommissionStaffOverride;
use Carbon\CarbonInterface;

class CommissionFormulaReplayService
{
    public function calculate(
        string $companyId,
        string $staffId,
        string $planFamilyId,
        float $eligibleLkrAmount,
        CarbonInterface $effectiveAt,
    ): array {
        $overrides = SalesCommissionStaffOverride::query()->where('company_id', $companyId)
            ->where('staff_id', $staffId)->where('status', 'approved')
            ->where('effective_from', '<=', $effectiveAt)
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $effectiveAt))->get();
        if ($overrides->count() > 1) return ['blocker' => 'Multiple approved Staff overrides overlap the original receipt timestamp.'];
        if ($overrides->count() === 1) {
            $override = $overrides->first();
            $rate = (float) $override->percentage_rate;
            return ['staff_override_id' => $override->id, 'formula_kind' => 'percentage', 'applied_rate' => $rate,
                'rounding_mode' => 'half_up', 'rounding_scale' => 2,
                'commission_amount_lkr' => round($eligibleLkrAmount * $rate / 100, 2),
                'calculation_explanation' => "Approved Staff override {$rate}% replayed against frozen eligible LKR receipt {$eligibleLkrAmount}."];
        }

        $versions = SalesCommissionPlanVersion::query()->where('plan_family_id', $planFamilyId)
            ->where('status', 'approved')->where('effective_from', '<=', $effectiveAt)
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $effectiveAt))->get();
        if ($versions->isEmpty()) return ['blocker' => 'No approved plan version covers the original receipt timestamp.'];
        if ($versions->count() > 1) return ['blocker' => 'Multiple approved plan versions overlap the original receipt timestamp.'];
        $version = $versions->first();
        if ($version->eligible_basis !== 'full_eligible_receipt_lkr') return ['blocker' => 'The approved plan uses an unsupported eligible basis.'];
        $result = ['plan_version_id' => $version->id, 'formula_kind' => $version->formula_kind,
            'rounding_mode' => $version->rounding_mode, 'rounding_scale' => (int) $version->rounding_scale];
        if ($version->formula_kind === 'percentage') {
            $rate = (float) $version->percentage_rate;
            return $result + ['applied_rate' => $rate, 'commission_amount_lkr' => $this->round($eligibleLkrAmount * $rate / 100, $version),
                'calculation_explanation' => "Approved percentage {$rate}% replayed against frozen eligible LKR receipt {$eligibleLkrAmount}."];
        }
        if ($version->formula_kind === 'fixed') {
            $fixed = (float) $version->fixed_amount_lkr;
            return $result + ['fixed_amount_lkr' => $fixed, 'commission_amount_lkr' => $this->round($fixed, $version),
                'calculation_explanation' => "Approved fixed commission LKR {$fixed} replayed once for the frozen eligible receipt."];
        }
        if ($version->formula_kind === 'tiered_percentage') {
            $tiers = SalesCommissionPlanTier::query()->where('plan_version_id', $version->id)->orderBy('sequence')->get()
                ->filter(fn ($tier) => $this->tierMatches($tier, $eligibleLkrAmount))->values();
            if ($tiers->count() !== 1) return ['blocker' => 'The frozen eligible LKR amount does not match exactly one approved tier.'];
            $tier = $tiers->first();
            $rate = (float) $tier->percentage_rate;
            return $result + ['plan_tier_id' => $tier->id, 'applied_rate' => $rate,
                'commission_amount_lkr' => $this->round($eligibleLkrAmount * $rate / 100, $version),
                'calculation_explanation' => "Approved whole-payment tier {$rate}% replayed against frozen eligible LKR receipt {$eligibleLkrAmount}."];
        }
        return ['blocker' => 'The approved formula kind remains unsupported.'];
    }

    private function tierMatches(SalesCommissionPlanTier $tier, float $basis): bool
    {
        $minimum = (float) $tier->minimum_lkr;
        $maximum = $tier->maximum_lkr !== null ? (float) $tier->maximum_lkr : null;
        return ($tier->minimum_inclusive ? $basis >= $minimum : $basis > $minimum)
            && ($maximum === null || ($tier->maximum_inclusive ? $basis <= $maximum : $basis < $maximum));
    }

    private function round(float $amount, SalesCommissionPlanVersion $version): float
    {
        $mode = match ($version->rounding_mode) {
            'half_down' => PHP_ROUND_HALF_DOWN, 'half_even' => PHP_ROUND_HALF_EVEN,
            'half_odd' => PHP_ROUND_HALF_ODD, default => PHP_ROUND_HALF_UP,
        };
        return round($amount, (int) $version->rounding_scale, $mode);
    }
}
