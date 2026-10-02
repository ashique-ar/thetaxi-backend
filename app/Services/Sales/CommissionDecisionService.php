<?php

namespace App\Services\Sales;

use App\Contracts\Foundation\DomainEventPublisher;
use App\Models\Booking\BookingPaymentReceipt;
use App\Models\Booking\BookingPaymentReceiptComponent;
use App\Models\Booking\BookingPaymentFinalityPolicy;
use App\Models\Sales\SalesBookingAttribution;
use App\Models\Sales\SalesCommissionDecision;
use App\Models\Sales\SalesCommissionPlanTier;
use App\Models\Sales\SalesCommissionPlanVersion;
use App\Models\Sales\SalesCommissionStaffOverride;
use App\Models\Sales\SalesProfile;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class CommissionDecisionService
{
    public function __construct(
        private readonly DomainEventPublisher $events,
        private readonly SalesMetricFactService $metricFacts,
        private readonly SalesProfileEligibilityService $profileEligibility,
        private readonly CommissionEmploymentExitResolutionService $employmentExitResolutions,
    ) {}

    public function decide(BookingPaymentReceipt $receipt, BookingPaymentReceiptComponent $component): ?SalesCommissionDecision
    {
        if (! config('sales.features.commission_shadow', false) && ! config('sales.features.commission_accrual', false)) {
            return null;
        }
        if (! $component->is_commission_eligible) {
            return null;
        }

        return DB::transaction(function () use ($receipt, $component) {
            $component = BookingPaymentReceiptComponent::query()->lockForUpdate()->findOrFail($component->id);
            $existing = SalesCommissionDecision::query()->where('receipt_component_id', $component->id)->first();
            if ($existing) {
                return $existing;
            }
            $receipt = BookingPaymentReceipt::query()->findOrFail($receipt->id);
            $finalityPolicy = $this->finalityPolicy($receipt);
            $attribution = SalesBookingAttribution::query()->where('booking_id', $receipt->booking_id)->first();
            $sourceBasis = max(0, round((float) $component->source_amount - (float) $component->adjusted_source_amount, 4));
            $lkrBasis = $component->lkr_amount !== null && (float) $component->source_amount > 0
                ? round((float) $component->lkr_amount * ($sourceBasis / (float) $component->source_amount), 4)
                : null;
            $base = [
                'company_id' => $attribution?->company_id ?? $receipt->company_id,
                'booking_id' => $receipt->booking_id,
                'root_attribution_id' => $attribution?->root_attribution_id,
                'booking_attribution_id' => $attribution?->id,
                'receipt_id' => $receipt->id,
                'receipt_component_id' => $component->id,
                'acquisition_sales_profile_id' => $attribution?->acquisition_sales_profile_id,
                'commission_category' => $attribution?->commission_category ?? 'unknown',
                'collection_cohort' => $this->cohort($attribution?->secured_at, $receipt->received_at),
                'eligible_source_amount' => $sourceBasis,
                'source_currency' => $receipt->source_currency ?: 'LKR',
                'fx_rate_to_lkr' => $receipt->fx_rate_to_lkr,
                'fx_rate_at' => $receipt->fx_rate_at,
                'fx_source' => $receipt->fx_source,
                'eligible_lkr_amount' => $lkrBasis,
                'plan_family_id' => $attribution?->commission_plan_family_id,
                'plan_assignment_id' => $attribution?->commission_plan_assignment_id,
                'receipt_finality_status' => $receipt->finality_status,
                'finality_policy_id' => $finalityPolicy?->id,
                'can_earn_before_final_snapshot' => $finalityPolicy?->can_earn_before_final,
                'hold_payout_until_final_snapshot' => $finalityPolicy?->hold_payout_until_final,
                'decision_at' => now(),
                'event_version' => 1,
                'idempotency_key' => 'commission-decision:'.$component->id,
            ];
            if (! $attribution) {
                return $this->hold($base, 'attribution_missing', 'Booking attribution is missing.');
            }
            if (! $attribution->company_id) {
                return $this->hold($base, 'legal_entity_missing', 'Legal entity is missing from booking attribution.');
            }
            $acquisitionProfile = $attribution->acquisition_sales_profile_id
                ? SalesProfile::query()->withTrashed()->find($attribution->acquisition_sales_profile_id)
                : null;
            if (! $acquisitionProfile) {
                return $this->hold($base, 'acquisition_profile_missing', 'The frozen acquisition Sales Profile is missing.');
            }
            if ($acquisitionProfile->company_id !== $attribution->company_id) {
                return $this->hold($base, 'legal_entity_mismatch', 'The acquisition Sales Profile belongs to a different legal entity.');
            }
            if (! $this->profileEligibility->isEligibleAt($acquisitionProfile, ['acquisition'], $attribution->secured_at)) {
                return $this->hold(
                    $base,
                    'acquisition_profile_ineligible',
                    'The acquisition Sales Profile lacked explicit acquisition eligibility or a governed reporting currency at the secured time.',
                );
            }
            if ($lkrBasis === null) {
                return $this->hold($base, 'fx_snapshot_missing', 'The governed LKR receipt snapshot is missing.');
            }
            if (! $attribution->commission_plan_family_id) {
                return $this->hold($base, 'plan_family_missing', 'No commission plan family was frozen at booking confirmation.');
            }

            $handlerId = $this->collectionProfileAt($attribution, $receipt->received_at);
            $profile = $handlerId ? SalesProfile::query()->withTrashed()
                ->with(['staff' => fn ($query) => $query->withTrashed()])->find($handlerId) : null;
            $base['collection_sales_profile_id'] = $handlerId;
            $base['beneficiary_sales_profile_id'] = $handlerId;
            $base['beneficiary_staff_id'] = $profile?->staff_id;
            if (! $profile?->staff_id || ! $profile->staff) {
                return $this->hold($base, 'beneficiary_missing', 'No eligible collection handler Staff record exists at receipt time.');
            }
            if ($profile->company_id !== $attribution->company_id) {
                return $this->hold($base, 'legal_entity_mismatch', 'The collection beneficiary belongs to a different legal entity.');
            }
            if (($profile->staff->employment_ended_at && $profile->staff->employment_ended_at->lte($receipt->received_at))
                || ($profile->staff->deleted_at && $profile->staff->deleted_at->lte($receipt->received_at))) {
                return $this->hold($base, 'employment_inactive', 'The collection handler Staff identity was inactive at receipt time.');
            }
            if (! $this->profileEligibility->isEligibleAt($profile, ['collection'], $receipt->received_at)) {
                return $this->hold(
                    $base,
                    'collection_handler_ineligible',
                    'The handler lacked explicit collection eligibility or a governed reporting currency at receipt time.',
                );
            }
            if (! $this->profileEligibility->isEligibleAt($profile, ['commission'], $receipt->received_at)) {
                return $this->hold(
                    $base,
                    'commission_beneficiary_ineligible',
                    'The collection handler was not explicitly commission-eligible at receipt time.',
                );
            }
            $overrides = SalesCommissionStaffOverride::query()
                ->where('company_id', $attribution->company_id)->where('staff_id', $profile->staff_id)
                ->where('status', 'approved')->where('effective_from', '<=', $receipt->received_at)
                ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $receipt->received_at))->get();
            if ($overrides->count() > 1) {
                return $this->hold($base, 'override_ambiguous', 'Multiple Staff percentage overrides overlap this receipt.');
            }
            if ($overrides->count() === 1) {
                $override = $overrides->first();
                $rate = (float) $override->percentage_rate;
                return $this->complete($base + [
                    'staff_override_id' => $override->id, 'formula_kind' => 'percentage', 'applied_rate' => $rate,
                    'rounding_mode_snapshot' => 'half_up', 'rounding_scale_snapshot' => 2,
                    'commission_amount_lkr' => round($lkrBasis * $rate / 100, 2),
                    'calculation_explanation' => "Approved Staff override {$rate}% applied to full eligible LKR receipt {$lkrBasis}.",
                ], $receipt, $finalityPolicy);
            }

            $versions = SalesCommissionPlanVersion::query()
                ->where('plan_family_id', $attribution->commission_plan_family_id)->where('status', 'approved')
                ->where('effective_from', '<=', $receipt->received_at)
                ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $receipt->received_at))->get();
            if ($versions->isEmpty()) {
                return $this->hold($base, 'rate_missing', 'No approved plan version is effective for this receipt.');
            }
            if ($versions->count() > 1) {
                return $this->hold($base, 'plan_version_ambiguous', 'Multiple approved plan versions overlap this receipt.');
            }
            $version = $versions->first();
            $calculation = [
                'plan_version_id' => $version->id, 'formula_kind' => $version->formula_kind,
                'rounding_mode_snapshot' => $version->rounding_mode,
                'rounding_scale_snapshot' => (int) $version->rounding_scale,
            ];
            if ($version->formula_kind === 'percentage') {
                $rate = (float) $version->percentage_rate;
                $calculation += ['applied_rate' => $rate, 'commission_amount_lkr' => $this->round($lkrBasis * $rate / 100, $version),
                    'calculation_explanation' => "Approved percentage {$rate}% applied to full eligible LKR receipt {$lkrBasis}."];
            } elseif ($version->formula_kind === 'fixed') {
                $fixed = (float) $version->fixed_amount_lkr;
                $calculation += ['fixed_amount_lkr' => $fixed, 'commission_amount_lkr' => $this->round($fixed, $version),
                    'calculation_explanation' => "Approved fixed commission LKR {$fixed} applied once to this eligible receipt."];
            } elseif ($version->formula_kind === 'tiered_percentage') {
                $tiers = SalesCommissionPlanTier::query()->where('plan_version_id', $version->id)->orderBy('sequence')->get()
                    ->filter(fn ($tier) => $this->tierMatches($tier, $lkrBasis))->values();
                if ($tiers->count() !== 1) {
                    return $this->hold($base + ['plan_version_id' => $version->id], 'tier_missing_or_ambiguous',
                        'The eligible LKR amount does not match exactly one approved tier.');
                }
                $tier = $tiers->first();
                $rate = (float) $tier->percentage_rate;
                $calculation += ['plan_tier_id' => $tier->id, 'applied_rate' => $rate,
                    'commission_amount_lkr' => $this->round($lkrBasis * $rate / 100, $version),
                    'calculation_explanation' => "Whole-payment tier {$rate}% applied to eligible LKR receipt {$lkrBasis}."];
            } else {
                return $this->hold($base + ['plan_version_id' => $version->id], 'formula_unsupported', 'The approved formula kind is unsupported.');
            }

            return $this->complete($base + $calculation, $receipt, $finalityPolicy);
        });
    }

    private function hold(array $facts, string $code, string $explanation): SalesCommissionDecision
    {
        return $this->persist($facts + [
            'status' => config('sales.features.commission_accrual', false) ? 'held' : 'shadow_held',
            'hold_code' => $code, 'calculation_explanation' => $explanation,
        ]);
    }

    private function earned(array $facts, CarbonInterface $earnedAt): SalesCommissionDecision
    {
        return $this->persist($facts + [
            'status' => config('sales.features.commission_accrual', false) ? 'earned' : 'shadow_earned',
            'hold_code' => null, 'earned_at' => $earnedAt,
        ]);
    }

    private function complete(
        array $facts,
        BookingPaymentReceipt $receipt,
        ?BookingPaymentFinalityPolicy $policy,
    ): SalesCommissionDecision {
        if ($receipt->finality_status === 'confirmed' && $policy) {
            return $this->earned($facts, $receipt->received_at);
        }
        if ($receipt->finality_status === 'policy_missing' || ! $policy) {
            return $this->hold($facts, 'finality_policy_missing',
                'No approved payment-method finality policy covers the original receipt timestamp.');
        }
        if ($receipt->finality_status === 'failed') {
            return $this->hold($facts, 'payment_finality_failed',
                'The official receipt failed finality and cannot create a payable commission entitlement.');
        }
        if ($receipt->finality_status !== 'pending_clearance') {
            return $this->hold($facts, 'payment_finality_unknown',
                'The receipt finality state is not recognized by the commission policy.');
        }
        if (! $policy->hold_payout_until_final) {
            return $this->hold($facts, 'finality_policy_invalid',
                'Pending-clearance policy does not explicitly hold payout until confirmation.');
        }

        $formulaExplanation = $facts['calculation_explanation'] ?? 'Approved formula evidence was resolved.';
        if (! $policy->can_earn_before_final) {
            $facts['commission_amount_lkr'] = null;
            $formulaExplanation = 'Commission amount calculation is deferred by the approved finality policy; formula evidence is frozen for confirmation.';
        }

        return $this->hold($facts, 'cash_clearance_pending',
            $formulaExplanation.' Payout remains held until an immutable confirmed finality event.');
    }

    private function persist(array $facts): SalesCommissionDecision
    {
        $decision = SalesCommissionDecision::create($facts);
        $this->events->record('sales', $decision->company_id, 'commission_decision', $decision->id,
            'sales.commission.decided', 1, 1, [
                'booking_id' => $decision->booking_id, 'receipt_id' => $decision->receipt_id,
                'receipt_component_id' => $decision->receipt_component_id, 'status' => $decision->status,
                'hold_code' => $decision->hold_code, 'commission_amount_lkr' => (string) $decision->commission_amount_lkr,
            ], $decision->decision_at, $decision->idempotency_key);
        $this->metricFacts->projectCommission($decision);
        $this->employmentExitResolutions->resolve($decision);
        return $decision;
    }

    private function collectionProfileAt(SalesBookingAttribution $attribution, CarbonInterface $at): ?string
    {
        $latest = DB::table('sales_booking_attribution_events')->where('attribution_id', $attribution->id)
            ->where('field_name', 'collection_sales_profile_id')->where('effective_at', '<=', $at)
            ->orderByDesc('effective_at')->orderByDesc('version')->first();
        if ($latest) {
            return $latest->to_sales_profile_id;
        }

        $initial = DB::table('sales_booking_attribution_events')->where('attribution_id', $attribution->id)
            ->where('event_type', 'confirmed')->where('effective_at', '<=', $at)
            ->orderBy('effective_at')->orderBy('version')->first();

        return $initial ? $initial->to_sales_profile_id : $attribution->collection_sales_profile_id;
    }

    private function finalityPolicy(BookingPaymentReceipt $receipt): ?BookingPaymentFinalityPolicy
    {
        return BookingPaymentFinalityPolicy::query()
            ->where('company_id', $receipt->company_id)
            ->where('payment_method', strtolower((string) $receipt->payment_method))
            ->where('status', 'approved')
            ->where('effective_from', '<=', $receipt->received_at)
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $receipt->received_at))
            ->orderByDesc('version')
            ->first();
    }

    private function cohort($securedAt, CarbonInterface $receivedAt): string
    {
        return $securedAt && $securedAt->format('Y-m') === $receivedAt->format('Y-m') ? 'current_period_booking' : 'prior_period_booking';
    }

    private function tierMatches($tier, float $basis): bool
    {
        $minimum = (float) $tier->minimum_lkr;
        $maximum = $tier->maximum_lkr !== null ? (float) $tier->maximum_lkr : null;
        $aboveMinimum = $tier->minimum_inclusive ? $basis >= $minimum : $basis > $minimum;
        $belowMaximum = $maximum === null || ($tier->maximum_inclusive ? $basis <= $maximum : $basis < $maximum);
        return $aboveMinimum && $belowMaximum;
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
