<?php

namespace App\Services\Sales;

use App\Contracts\Foundation\DomainEventPublisher;
use App\Models\Booking\BookingPaymentAdjustment;
use App\Models\Sales\SalesCommissionDecision;
use App\Models\Sales\SalesCommissionHoldAdjustment;
use App\Models\Sales\SalesCommissionHoldRelease;
use App\Models\Sales\SalesCommissionPlanTier;
use App\Models\Sales\SalesCommissionPlanVersion;
use App\Models\Sales\SalesCommissionRecoveryCase;
use App\Models\Sales\SalesCommissionRecoveryDecision;
use App\Models\Sales\SalesCommissionStatement;
use App\Models\Sales\SalesCommissionStatementLine;
use Illuminate\Support\Facades\DB;
use App\Support\Foundation\CanonicalJson;

class CommissionRecoveryService
{
    public function __construct(
        private readonly DomainEventPublisher $events,
        private readonly SalesMetricFactService $metricFacts,
    ) {}

    public function openForCashDecrease(BookingPaymentAdjustment $adjustment): ?SalesCommissionRecoveryCase
    {
        if ($adjustment->impact_dimension !== 'cash_receipt' || $adjustment->direction !== 'decrease') return null;
        $earning = SalesCommissionDecision::query()
            ->where('receipt_component_id', $adjustment->receipt_component_id)
            ->first();
        if (! $earning || (float) $earning->eligible_source_amount <= 0) return null;

        $release = SalesCommissionHoldRelease::query()->where('commission_decision_id', $earning->id)->first();
        $holdAdjustment = SalesCommissionHoldAdjustment::query()->where('commission_decision_id', $earning->id)->first();
        $directEntitlement = $earning->status === 'earned';
        abort_if(($directEntitlement && ($release || $holdAdjustment)) || ($release && $holdAdjustment), 409,
            'The commission decision has multiple entitlement sources and requires reconciliation before refund recovery.');

        if ($directEntitlement) {
            if ($earning->commission_amount_lkr === null || (float) $earning->commission_amount_lkr <= 0) return null;
            $entitlement = [
                'source_type' => 'commission_decision', 'source_id' => $earning->id,
                'amount_lkr' => (float) $earning->commission_amount_lkr,
                'effective_at' => $earning->earned_at ?? $earning->decision_at,
                'beneficiary_staff_id' => $earning->beneficiary_staff_id,
                'beneficiary_sales_profile_id' => $earning->beneficiary_sales_profile_id,
                'commission_category' => $earning->commission_category,
                'collection_cohort' => $earning->collection_cohort,
                'formula_kind' => $earning->formula_kind, 'applied_rate' => $earning->applied_rate,
                'fixed_amount_lkr' => $earning->fixed_amount_lkr,
                'explanation' => 'Proportional cash decrease applied to the immutable original earning.',
            ];
        } elseif ($release && (float) $release->commission_amount_lkr > 0) {
            $entitlement = [
                'source_type' => 'commission_hold_release', 'source_id' => $release->id,
                'amount_lkr' => (float) $release->commission_amount_lkr, 'effective_at' => $release->released_at,
                'beneficiary_staff_id' => $release->beneficiary_staff_id,
                'beneficiary_sales_profile_id' => $release->beneficiary_sales_profile_id,
                'commission_category' => $earning->commission_category,
                'collection_cohort' => $earning->collection_cohort,
                'formula_kind' => $release->formula_kind, 'applied_rate' => $release->applied_rate,
                'fixed_amount_lkr' => $release->fixed_amount_lkr,
                'explanation' => 'Proportional cash decrease applied to the immutable released-hold entitlement.',
            ];
        } elseif ($holdAdjustment && (float) $holdAdjustment->commission_adjustment_lkr > 0) {
            $snapshot = $holdAdjustment->frozen_adjustment_snapshot ?? [];
            $entitlement = [
                'source_type' => 'commission_hold_adjustment', 'source_id' => $holdAdjustment->id,
                'amount_lkr' => (float) $holdAdjustment->commission_adjustment_lkr,
                'effective_at' => $holdAdjustment->adjustment_effective_at,
                'beneficiary_staff_id' => $holdAdjustment->beneficiary_staff_id,
                'beneficiary_sales_profile_id' => $holdAdjustment->beneficiary_sales_profile_id,
                'commission_category' => $snapshot['commission_category'] ?? null,
                'collection_cohort' => $snapshot['collection_cohort'] ?? null,
                'formula_kind' => $holdAdjustment->formula_kind, 'applied_rate' => $holdAdjustment->applied_rate,
                'fixed_amount_lkr' => $holdAdjustment->fixed_amount_lkr,
                'explanation' => 'Proportional cash decrease applied to the immutable late-attribution entitlement.',
            ];
        } else {
            return null;
        }

        abort_unless($entitlement['effective_at'] && $entitlement['beneficiary_staff_id']
            && $entitlement['beneficiary_sales_profile_id'], 422,
            'The commission entitlement source has incomplete effective-time or beneficiary evidence.');
        $ratio = min(1, (float) $adjustment->source_amount / (float) $earning->eligible_source_amount);
        $proposed = round($entitlement['amount_lkr'] * $ratio, 4);

        return SalesCommissionRecoveryCase::firstOrCreate(['payment_adjustment_id' => $adjustment->id], [
            'company_id' => $earning->company_id, 'commission_decision_id' => $earning->id,
            'entitlement_source_type' => $entitlement['source_type'], 'entitlement_source_id' => $entitlement['source_id'],
            'entitlement_amount_lkr' => $entitlement['amount_lkr'], 'entitlement_effective_at' => $entitlement['effective_at'],
            'beneficiary_staff_id' => $entitlement['beneficiary_staff_id'],
            'beneficiary_sales_profile_id' => $entitlement['beneficiary_sales_profile_id'],
            'recovery_kind' => 'cash_decrease', 'commission_category' => $entitlement['commission_category'],
            'collection_cohort' => $entitlement['collection_cohort'], 'source_currency' => $earning->source_currency,
            'adjusted_source_amount' => $adjustment->source_amount, 'proposed_recovery_lkr' => $proposed,
            'original_eligible_lkr_amount' => $earning->eligible_lkr_amount,
            'original_fx_rate_to_lkr' => $earning->fx_rate_to_lkr, 'original_fx_rate_at' => $earning->fx_rate_at,
            'original_fx_source' => $earning->fx_source, 'original_formula_kind' => $entitlement['formula_kind'],
            'original_applied_rate' => $entitlement['applied_rate'],
            'original_fixed_amount_lkr' => $entitlement['fixed_amount_lkr'],
            'original_commission_amount_lkr' => $entitlement['amount_lkr'],
            'proposed_commission_adjustment_lkr' => -$proposed, 'calculation_status' => 'ready',
            'calculation_explanation' => $entitlement['explanation'],
            'status' => 'pending_review', 'opened_at' => now(),
        ]);
    }

    public function openForExistingCashDecreases(SalesCommissionDecision $decision): int
    {
        $adjustments = BookingPaymentAdjustment::query()
            ->where('receipt_component_id', $decision->receipt_component_id)
            ->where('impact_dimension', 'cash_receipt')
            ->where('direction', 'decrease')
            ->orderBy('adjustment_effective_at')
            ->orderBy('id')
            ->get();

        $opened = 0;
        foreach ($adjustments as $adjustment) {
            if ($this->openForCashDecrease($adjustment)) $opened++;
        }

        return $opened;
    }

    public function openForFxCorrection(BookingPaymentAdjustment $adjustment, ?SalesCommissionDecision $earning): ?SalesCommissionRecoveryCase
    {
        if ($adjustment->impact_dimension !== 'reporting_fx' || ! $earning || $earning->status !== 'earned') return null;

        $priorRecovery = $adjustment->corrects_adjustment_id
            ? SalesCommissionRecoveryCase::query()->where('payment_adjustment_id', $adjustment->corrects_adjustment_id)->firstOrFail()
            : null;
        $calculation = $this->previewFxCalculation($earning, (float) $adjustment->source_amount,
            (float) $adjustment->lkr_amount, (float) $adjustment->original_lkr_amount,
            $priorRecovery?->recalculated_commission_amount_lkr !== null
                ? (float) $priorRecovery->recalculated_commission_amount_lkr : null);
        $status = $calculation['calculation_status'];
        $proposed = $calculation['proposed_commission_adjustment_lkr'];
        $explanation = $calculation['calculation_explanation'];
        return SalesCommissionRecoveryCase::firstOrCreate(['payment_adjustment_id' => $adjustment->id], [
            'company_id' => $earning->company_id, 'commission_decision_id' => $earning->id,
            'entitlement_source_type' => 'commission_decision', 'entitlement_source_id' => $earning->id,
            'entitlement_amount_lkr' => $earning->commission_amount_lkr,
            'entitlement_effective_at' => $earning->earned_at ?? $earning->decision_at,
            'corrects_recovery_case_id' => $priorRecovery?->id,
            'beneficiary_staff_id' => $earning->beneficiary_staff_id,
            'beneficiary_sales_profile_id' => $earning->beneficiary_sales_profile_id,
            'recovery_kind' => 'reporting_fx', 'commission_category' => $earning->commission_category,
            'collection_cohort' => $earning->collection_cohort,
            'adjusted_source_amount' => $adjustment->source_amount, 'source_currency' => $earning->source_currency,
            'original_eligible_lkr_amount' => $earning->eligible_lkr_amount,
            'original_fx_rate_to_lkr' => $earning->fx_rate_to_lkr, 'original_fx_rate_at' => $earning->fx_rate_at,
            'original_fx_source' => $earning->fx_source, 'corrected_lkr_amount' => $adjustment->lkr_amount,
            'corrected_fx_rate_to_lkr' => $adjustment->fx_rate_to_lkr, 'corrected_fx_rate_at' => $adjustment->fx_rate_at,
            'corrected_fx_source' => $adjustment->fx_source,
            'corrected_fx_calculation_mode' => $adjustment->fx_calculation_mode,
            'reporting_lkr_delta' => $adjustment->lkr_delta,
            'corrected_eligible_lkr_amount' => $calculation['corrected_eligible_lkr_amount'],
            'recalculated_plan_tier_id' => $calculation['recalculated_plan_tier_id'],
            'recalculated_tier_sequence' => $calculation['recalculated_tier_sequence'],
            'recalculated_tier_minimum_lkr' => $calculation['recalculated_tier_minimum_lkr'],
            'recalculated_tier_maximum_lkr' => $calculation['recalculated_tier_maximum_lkr'],
            'recalculated_tier_minimum_inclusive' => $calculation['recalculated_tier_minimum_inclusive'],
            'recalculated_tier_maximum_inclusive' => $calculation['recalculated_tier_maximum_inclusive'],
            'recalculated_rate' => $calculation['recalculated_rate'],
            'recalculated_commission_amount_lkr' => $calculation['recalculated_commission_amount_lkr'],
            'original_formula_kind' => $earning->formula_kind, 'original_applied_rate' => $earning->applied_rate,
            'original_fixed_amount_lkr' => $earning->fixed_amount_lkr,
            'original_commission_amount_lkr' => $earning->commission_amount_lkr,
            'prior_recalculated_commission_amount_lkr' => $priorRecovery?->recalculated_commission_amount_lkr,
            'proposed_commission_adjustment_lkr' => $proposed,
            'proposed_recovery_lkr' => $proposed !== null && $proposed < 0 ? abs($proposed) : 0,
            'calculation_status' => $status, 'calculation_explanation' => $explanation,
            'calculation_checksum' => $calculation['calculation_checksum'],
            'status' => 'pending_review', 'opened_at' => now(),
        ]);
    }

    public function previewFxCalculation(
        SalesCommissionDecision $earning,
        float $affectedSourceAmount,
        float $correctedAffectedLkr,
        float $originalAffectedLkr,
        ?float $priorRecalculatedCommission = null,
    ): array {
        abort_unless((float) $earning->eligible_source_amount > 0 && $affectedSourceAmount > 0
            && $affectedSourceAmount <= (float) $earning->eligible_source_amount
            && $earning->eligible_lkr_amount !== null && $earning->commission_amount_lkr !== null, 422,
            'The FX correction source basis is outside the immutable original earning.');
        $ratio = $affectedSourceAmount / (float) $earning->eligible_source_amount;
        $correctedBasis = round((float) $earning->eligible_lkr_amount - $originalAffectedLkr + $correctedAffectedLkr, 4);
        abort_unless($correctedBasis > 0, 422, 'The corrected eligible LKR basis must remain positive.');

        $status = 'manual_review'; $tierId = null; $tierEvidence = null; $rate = null; $newCommission = null;
        $version = $earning->plan_version_id ? SalesCommissionPlanVersion::query()->find($earning->plan_version_id) : null;
        $explanation = 'The frozen formula cannot be reproduced automatically after an FX basis correction.';
        if ($earning->formula_kind === 'percentage' && $earning->applied_rate !== null) {
            $rate = (float) $earning->applied_rate;
            $newCommission = $version
                ? $this->roundForVersion($correctedBasis * $rate / 100, $version)
                : round($correctedBasis * $rate / 100, 2);
            $status = 'ready';
            $explanation = 'Frozen percentage rate and rounding applied to the corrected whole-payment LKR basis.';
        } elseif ($earning->formula_kind === 'fixed') {
            $newCommission = (float) $earning->commission_amount_lkr;
            $status = 'no_change';
            $explanation = 'The frozen fixed commission remains payable once for the eligible receipt.';
        } elseif ($earning->formula_kind === 'tiered_percentage' && $earning->plan_version_id) {
            abort_unless($version && $version->formula_kind === 'tiered_percentage', 422,
                'The immutable original tiered plan version is unavailable.');
            $tiers = SalesCommissionPlanTier::query()->where('plan_version_id', $version->id)->orderBy('sequence')->get()
                ->filter(fn ($tier) => $this->tierMatches($tier, $correctedBasis))->values();
            abort_unless($tiers->count() === 1, 422,
                'The corrected whole-payment basis does not match exactly one tier in the immutable original plan version.');
            $tier = $tiers->first(); $tierId = $tier->id; $rate = (float) $tier->percentage_rate;
            $tierEvidence = [
                'sequence' => $tier->sequence, 'minimum_lkr' => (float) $tier->minimum_lkr,
                'maximum_lkr' => $tier->maximum_lkr !== null ? (float) $tier->maximum_lkr : null,
                'minimum_inclusive' => (bool) $tier->minimum_inclusive,
                'maximum_inclusive' => (bool) $tier->maximum_inclusive,
            ];
            $newCommission = $this->roundForVersion($correctedBasis * $rate / 100, $version);
            $status = 'ready';
            $explanation = "Corrected whole-payment LKR basis {$correctedBasis} matches frozen plan tier {$tier->sequence} at {$rate}%.";
        }
        abort_unless($newCommission !== null, 422, $explanation);
        $comparisonCommission = $priorRecalculatedCommission ?? (float) $earning->commission_amount_lkr;
        $proposed = round($newCommission - $comparisonCommission, 4);
        if ($priorRecalculatedCommission !== null) {
            $explanation .= ' The incremental delta compares with the frozen recalculated commission from the prior correction.';
        }
        if ($proposed === 0.0) $status = 'no_change';
        $facts = [
            'commission_decision_id' => $earning->id, 'plan_version_id' => $earning->plan_version_id,
            'original_plan_tier_id' => $earning->plan_tier_id, 'recalculated_plan_tier_id' => $tierId,
            'recalculated_tier_evidence' => $tierEvidence,
            'formula_kind' => $earning->formula_kind, 'affected_source_amount' => number_format($affectedSourceAmount, 4, '.', ''),
            'rounding_mode' => $version?->rounding_mode ?? 'half_up',
            'rounding_scale' => $version?->rounding_scale ?? 2,
            'source_ratio' => number_format($ratio, 10, '.', ''),
            'original_eligible_lkr_amount' => number_format((float) $earning->eligible_lkr_amount, 4, '.', ''),
            'original_affected_lkr_amount' => number_format($originalAffectedLkr, 4, '.', ''),
            'corrected_affected_lkr_amount' => number_format($correctedAffectedLkr, 4, '.', ''),
            'corrected_eligible_lkr_amount' => number_format($correctedBasis, 4, '.', ''),
            'original_commission_amount_lkr' => number_format((float) $earning->commission_amount_lkr, 4, '.', ''),
            'prior_recalculated_commission_amount_lkr' => $priorRecalculatedCommission !== null
                ? number_format($priorRecalculatedCommission, 4, '.', '') : null,
            'comparison_commission_amount_lkr' => number_format($comparisonCommission, 4, '.', ''),
            'recalculated_rate' => $rate !== null ? number_format($rate, 6, '.', '') : null,
            'recalculated_commission_amount_lkr' => number_format($newCommission, 4, '.', ''),
            'proposed_commission_adjustment_lkr' => number_format($proposed, 4, '.', ''),
            'calculation_status' => $status, 'calculation_explanation' => $explanation,
        ];
        $checksum = hash('sha256', CanonicalJson::encode($facts));
        return array_merge($facts, [
            'recalculated_plan_tier_id' => $tierId, 'recalculated_rate' => $rate,
            'recalculated_tier_sequence' => $tierEvidence['sequence'] ?? null,
            'recalculated_tier_minimum_lkr' => $tierEvidence['minimum_lkr'] ?? null,
            'recalculated_tier_maximum_lkr' => $tierEvidence['maximum_lkr'] ?? null,
            'recalculated_tier_minimum_inclusive' => $tierEvidence['minimum_inclusive'] ?? null,
            'recalculated_tier_maximum_inclusive' => $tierEvidence['maximum_inclusive'] ?? null,
            'corrected_eligible_lkr_amount' => $correctedBasis,
            'recalculated_commission_amount_lkr' => $newCommission,
            'proposed_commission_adjustment_lkr' => $proposed,
            'calculation_checksum' => $checksum, 'preview_checksum' => $checksum,
        ]);
    }

    private function tierMatches(SalesCommissionPlanTier $tier, float $basis): bool
    {
        $aboveMinimum = $tier->minimum_inclusive ? $basis >= (float) $tier->minimum_lkr : $basis > (float) $tier->minimum_lkr;
        return $aboveMinimum && ($tier->maximum_lkr === null
            || ($tier->maximum_inclusive ? $basis <= (float) $tier->maximum_lkr : $basis < (float) $tier->maximum_lkr));
    }

    private function roundForVersion(float $amount, SalesCommissionPlanVersion $version): float
    {
        $mode = match ($version->rounding_mode) {
            'half_down' => PHP_ROUND_HALF_DOWN, 'half_even' => PHP_ROUND_HALF_EVEN,
            'half_odd' => PHP_ROUND_HALF_ODD, default => PHP_ROUND_HALF_UP,
        };
        return round($amount, (int) $version->rounding_scale, $mode);
    }

    private function entitlementSource(SalesCommissionRecoveryCase $case, bool $lock = false): mixed
    {
        $query = match ($case->entitlement_source_type) {
            'commission_decision' => SalesCommissionDecision::query(),
            'commission_hold_release' => SalesCommissionHoldRelease::query(),
            'commission_hold_adjustment' => SalesCommissionHoldAdjustment::query(),
            default => null,
        };
        if (! $query || ! $case->entitlement_source_id) return null;
        if ($lock) $query->lockForUpdate();
        $source = $query->find($case->entitlement_source_id);
        if (! $source || $source->company_id !== $case->company_id) return null;

        $sourceDecisionId = $case->entitlement_source_type === 'commission_decision'
            ? $source->id : $source->commission_decision_id;
        $sourceAmount = match ($case->entitlement_source_type) {
            'commission_hold_adjustment' => (float) $source->commission_adjustment_lkr,
            default => (float) $source->commission_amount_lkr,
        };
        $sourceEffectiveAt = match ($case->entitlement_source_type) {
            'commission_decision' => $source->earned_at ?? $source->decision_at,
            'commission_hold_release' => $source->released_at,
            'commission_hold_adjustment' => $source->adjustment_effective_at,
        };
        if ($sourceDecisionId !== $case->commission_decision_id
            || abs($sourceAmount - (float) $case->entitlement_amount_lkr) > 0.00005
            || ! $sourceEffectiveAt || ! $case->entitlement_effective_at
            || ! $sourceEffectiveAt->equalTo($case->entitlement_effective_at)
            || $source->beneficiary_staff_id !== $case->beneficiary_staff_id
            || $source->beneficiary_sales_profile_id !== $case->beneficiary_sales_profile_id) {
            return null;
        }

        return $source;
    }

    public function previewDecision(SalesCommissionRecoveryCase $case, string $decision): array
    {
        if ($case->status !== 'pending_review' || $case->decision()->exists()) {
            return $this->blockedDecisionPreview($case, 'The commission recovery case is already resolved.');
        }
        if ($case->calculation_status === 'manual_review') {
            return $this->blockedDecisionPreview($case, 'This case requires a governed recalculation before a commission decision can be recorded.');
        }
        $signed = (float) ($case->proposed_commission_adjustment_lkr ?? -(float) $case->proposed_recovery_lkr);
        if (! in_array($decision, $this->allowedDecisions($case, $signed), true)) {
            return $this->blockedDecisionPreview($case, 'The decision is incompatible with the frozen commission delta.');
        }

        $entitlement = $this->entitlementSource($case);
        if (! $entitlement) {
            return $this->blockedDecisionPreview($case,
                'The frozen commission entitlement source is unavailable or no longer reconciles.');
        }
        $sourceLines = SalesCommissionStatementLine::query()
            ->join('sales_commission_statements as source_statement', 'source_statement.id', '=', 'sales_commission_statement_lines.statement_id')
            ->where('sales_commission_statement_lines.source_type', $case->entitlement_source_type)
            ->where('sales_commission_statement_lines.source_id', $case->entitlement_source_id)
            ->where('source_statement.status', '!=', 'void')
            ->select([
                'sales_commission_statement_lines.id as line_id', 'source_statement.id as statement_id',
                'source_statement.status as statement_status', 'source_statement.paid_lkr as statement_paid_lkr',
                'source_statement.net_payable_lkr as statement_net_payable_lkr',
            ])->get();
        if ($sourceLines->count() > 1) {
            return $this->blockedDecisionPreview($case, 'The original entitlement appears in multiple non-void statements and requires reconciliation.');
        }
        $source = $sourceLines->first();
        $hasPaidExposure = $source && (float) $source->statement_paid_lkr > 0;
        $disposition = match (true) {
            $decision === 'waive' => 'waived_recovery',
            $decision === 'acknowledge_no_change' => 'no_change',
            $signed > 0 && $hasPaidExposure => 'post_payment_credit',
            $signed < 0 && $hasPaidExposure => 'paid_negative_carry_forward',
            $source !== null => 'unpaid_statement_liability_adjustment',
            default => 'unstatemented_liability_adjustment',
        };
        $adjustment = BookingPaymentAdjustment::query()->find($case->payment_adjustment_id);
        if (! $adjustment || $adjustment->company_id !== $case->company_id) {
            return $this->blockedDecisionPreview($case, 'The immutable source adjustment is unavailable in the recovery legal entity.');
        }
        $snapshot = [
            'recovery_case_id' => $case->id, 'case_version' => $case->event_version,
            'decision' => $decision, 'resolution_disposition' => $disposition,
            'company_id' => $case->company_id, 'commission_decision_id' => $case->commission_decision_id,
            'entitlement_source_type' => $case->entitlement_source_type,
            'entitlement_source_id' => $case->entitlement_source_id,
            'entitlement_amount_lkr' => (string) $case->entitlement_amount_lkr,
            'entitlement_effective_at' => $case->entitlement_effective_at?->toIso8601String(),
            'payment_adjustment_id' => $case->payment_adjustment_id,
            'adjustment_effective_at' => $adjustment->adjustment_effective_at?->toIso8601String(),
            'source_adjustment_prepared_by' => $adjustment->created_user_id,
            'source_adjustment_approved_by' => $adjustment->approved_by,
            'beneficiary_staff_id' => $case->beneficiary_staff_id,
            'beneficiary_sales_profile_id' => $case->beneficiary_sales_profile_id,
            'commission_category' => $case->commission_category, 'collection_cohort' => $case->collection_cohort,
            'source_currency' => $case->source_currency,
            'original_eligible_lkr_amount' => (string) $case->original_eligible_lkr_amount,
            'original_fx_rate_to_lkr' => (string) $case->original_fx_rate_to_lkr,
            'original_fx_rate_at' => $case->original_fx_rate_at?->toIso8601String(),
            'original_fx_source' => $case->original_fx_source,
            'original_formula_kind' => $case->original_formula_kind,
            'original_commission_amount_lkr' => (string) $case->original_commission_amount_lkr,
            'commission_adjustment_lkr' => in_array($decision, ['deduct', 'credit'], true) ? number_format($signed, 4, '.', '') : '0.0000',
            'waived_recovery_lkr' => $decision === 'waive' ? number_format(abs($signed), 4, '.', '') : '0.0000',
            'source_statement_id' => $source?->statement_id, 'source_statement_line_id' => $source?->line_id,
            'source_statement_status' => $source?->statement_status,
            'source_statement_paid_lkr' => $source ? number_format((float) $source->statement_paid_lkr, 4, '.', '') : null,
            'source_statement_net_payable_lkr' => $source ? number_format((float) $source->statement_net_payable_lkr, 4, '.', '') : null,
        ];

        return [
            'decision_allowed' => true, 'blocker' => null, 'write_performed' => false,
            'case' => $case->only(['id', 'status', 'event_version', 'recovery_kind', 'calculation_status']),
            'resolution_disposition' => $disposition, 'commission_adjustment_lkr' => $snapshot['commission_adjustment_lkr'],
            'waived_recovery_lkr' => $snapshot['waived_recovery_lkr'], 'source_statement' => $source,
            'decision_preview_snapshot' => $snapshot,
            'preview_checksum' => hash('sha256', CanonicalJson::encode($snapshot)),
        ];
    }

    public function decide(
        SalesCommissionRecoveryCase $case,
        string $decision,
        string $reason,
        int $expectedVersion,
        string $previewChecksum,
        string $key,
        string $actorUserId,
    ): SalesCommissionRecoveryDecision {
        return DB::transaction(function () use ($case, $decision, $reason, $expectedVersion, $previewChecksum, $key, $actorUserId) {
            $case = SalesCommissionRecoveryCase::query()->lockForUpdate()->findOrFail($case->id);
            $checksum = hash('sha256', CanonicalJson::encode([
                'recovery_case_id' => $case->id, 'decision' => $decision, 'reason' => trim($reason),
                'expected_version' => $expectedVersion, 'preview_checksum' => $previewChecksum,
                'actor_user_id' => $actorUserId,
            ]));
            $duplicate = SalesCommissionRecoveryDecision::query()->where('idempotency_key', $key)->first();
            if ($duplicate) {
                abort_unless($duplicate->recovery_case_id === $case->id && $duplicate->decision === $decision
                    && $duplicate->request_payload_checksum !== null
                    && hash_equals($duplicate->request_payload_checksum, $checksum), 422,
                    'This recovery key was already used for another decision.');
                return $duplicate;
            }
            abort_unless($case->event_version === $expectedVersion, 409,
                'The commission recovery case version changed; refresh the decision preview.');
            SalesCommissionDecision::query()->whereKey($case->commission_decision_id)->lockForUpdate()->firstOrFail();
            abort_unless($this->entitlementSource($case, true), 409,
                'The frozen commission entitlement source changed or is unavailable; refresh the recovery case.');
            $adjustment = BookingPaymentAdjustment::query()->whereKey($case->payment_adjustment_id)->lockForUpdate()->firstOrFail();
            $preview = $this->previewDecision($case, $decision);
            abort_unless($preview['decision_allowed'], 422, $preview['blocker'] ?? 'The recovery decision is not allowed.');
            abort_unless(hash_equals($preview['preview_checksum'], $previewChecksum), 409,
                'The recovery calculation or paid-state evidence changed; refresh the decision preview.');
            abort_if(in_array($actorUserId, array_filter([$adjustment->created_user_id, $adjustment->approved_by]), true), 403,
                'The source adjustment preparer or approver cannot decide its commission recovery.');
            abort_if($case->beneficiary_staff_id === DB::table('staff')->where('user_id', $actorUserId)->value('id'), 403,
                'The original beneficiary cannot decide their own commission recovery.');
            $snapshot = $preview['decision_preview_snapshot'];
            if ($snapshot['source_statement_id']) {
                SalesCommissionStatement::query()->whereKey($snapshot['source_statement_id'])->lockForUpdate()->firstOrFail();
                SalesCommissionStatementLine::query()->whereKey($snapshot['source_statement_line_id'])->lockForUpdate()->firstOrFail();
            }
            $lockedPreview = $this->previewDecision($case, $decision);
            abort_unless($lockedPreview['decision_allowed']
                && hash_equals($lockedPreview['preview_checksum'], $previewChecksum), 409,
                'The locked recovery calculation or paid-state evidence changed; refresh the decision preview.');
            $snapshot = $lockedPreview['decision_preview_snapshot'];
            $signed = (float) $snapshot['commission_adjustment_lkr'];
            $record = SalesCommissionRecoveryDecision::create([
                'recovery_case_id' => $case->id, 'decision' => $decision,
                'commission_adjustment_lkr' => $signed,
                'waived_recovery_lkr' => $snapshot['waived_recovery_lkr'],
                'resolution_disposition' => $snapshot['resolution_disposition'],
                'source_statement_id' => $snapshot['source_statement_id'],
                'source_statement_line_id' => $snapshot['source_statement_line_id'],
                'source_statement_status' => $snapshot['source_statement_status'],
                'source_statement_paid_lkr' => $snapshot['source_statement_paid_lkr'],
                'source_statement_net_payable_lkr' => $snapshot['source_statement_net_payable_lkr'],
                'decision_preview_snapshot' => $snapshot, 'decision_preview_checksum' => $previewChecksum,
                'reason' => trim($reason), 'approved_by' => $actorUserId, 'approved_at' => now(),
                'idempotency_key' => $key, 'request_payload_checksum' => $checksum,
            ]);
            $resolvedStatus = match ($decision) {
                'deduct' => 'deduct_approved', 'credit' => 'credit_approved',
                'acknowledge_no_change' => 'no_change_acknowledged', default => 'waived',
            };
            $fromVersion = $case->event_version;
            $case->update(['status' => $resolvedStatus, 'event_version' => $fromVersion + 1, 'resolved_at' => now()]);
            $this->events->record('sales', $case->company_id, 'commission_recovery', $case->id,
                'sales.commission.recovery_decided', $fromVersion, $fromVersion + 1, [
                    'commission_decision_id' => $case->commission_decision_id, 'payment_adjustment_id' => $case->payment_adjustment_id,
                    'decision' => $decision, 'commission_adjustment_lkr' => (string) $record->commission_adjustment_lkr,
                    'waived_recovery_lkr' => (string) $record->waived_recovery_lkr,
                    'resolution_disposition' => $record->resolution_disposition,
                    'source_statement_id' => $record->source_statement_id,
                    'entitlement_source_type' => $case->entitlement_source_type,
                    'entitlement_source_id' => $case->entitlement_source_id,
                    'decision_preview_checksum' => $record->decision_preview_checksum,
                ], now(), $key);
            $this->metricFacts->projectCommissionRecovery($case, $record);
            return $record;
        });
    }

    private function allowedDecisions(SalesCommissionRecoveryCase $case, float $signed): array
    {
        return $case->calculation_status === 'no_change'
            ? ['acknowledge_no_change']
            : ($signed > 0 ? ['credit', 'waive'] : ['deduct', 'waive']);
    }

    private function blockedDecisionPreview(SalesCommissionRecoveryCase $case, string $blocker): array
    {
        return [
            'decision_allowed' => false, 'blocker' => $blocker, 'write_performed' => false,
            'case' => $case->only(['id', 'status', 'event_version', 'recovery_kind', 'calculation_status']),
        ];
    }
}
