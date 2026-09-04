<?php

namespace App\Services\Sales;

use App\Contracts\Foundation\DomainEventPublisher;
use App\Models\Booking\BookingPaymentAdjustment;
use App\Models\Booking\BookingPaymentFinalityPolicy;
use App\Models\Booking\BookingPaymentReceipt;
use App\Models\Booking\BookingPaymentReceiptComponent;
use App\Models\Booking\BookingPaymentReceiptFinalityEvent;
use App\Models\Sales\SalesBookingAttribution;
use App\Models\Sales\SalesCommissionDecision;
use App\Models\Sales\SalesCommissionHoldAdjustment;
use App\Models\Sales\SalesCommissionPlanFamily;
use App\Models\Sales\SalesCommissionPlanAssignment;
use App\Models\Sales\SalesCommissionPlanTier;
use App\Models\Sales\SalesCommissionPlanVersion;
use App\Models\Sales\SalesCommissionStaffOverride;
use App\Models\Sales\SalesProfile;
use App\Support\Foundation\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class CommissionHoldAdjustmentService
{
    public function __construct(
        private readonly CommissionFormulaReplayService $formulaReplay,
        private readonly SalesProfileEligibilityService $profileEligibility,
        private readonly DomainEventPublisher $events,
        private readonly SalesMetricFactService $metricFacts,
        private readonly CommissionRecoveryService $recoveries,
    ) {}

    public function preview(SalesCommissionDecision $decision): array
    {
        if ($decision->status !== 'held' || ! config('sales.features.commission_accrual', false)) {
            return $this->blocked($decision, 'Only an accrual-enabled immutable held decision can create a linked adjustment.');
        }
        if (! in_array($decision->hold_code, [
            'attribution_missing', 'legal_entity_missing', 'acquisition_profile_missing', 'acquisition_profile_ineligible',
            'legal_entity_mismatch', 'beneficiary_missing', 'plan_family_missing', 'collection_handler_ineligible',
            'commission_beneficiary_ineligible', 'finality_policy_missing', 'finality_policy_invalid', 'fx_snapshot_missing',
            'payment_finality_unknown',
        ], true)) {
            return $this->blocked($decision, 'No typed linked-adjustment command is implemented for this hold code.');
        }
        if (SalesCommissionHoldAdjustment::query()->where('commission_decision_id', $decision->id)->exists()) {
            return $this->blocked($decision, 'This commission hold already has an immutable linked adjustment.');
        }
        if ($decision->holdRelease()->exists()) {
            return $this->blocked($decision, 'This commission hold already has an immutable release.');
        }

        // fx_snapshot_missing is the hold that fires precisely because eligible_lkr_amount and the
        // decision's own frozen FX fields are null; it must be dispatched before the generic
        // completeness guards below, which would otherwise always block it circularly.
        if ($decision->hold_code === 'fx_snapshot_missing') {
            return $this->previewLateFxSnapshot($decision);
        }

        if ($decision->eligible_lkr_amount === null || (float) $decision->eligible_lkr_amount <= 0) {
            return $this->blocked($decision, 'The original decision has no positive governed eligible LKR receipt snapshot.');
        }
        if ($decision->eligible_source_amount === null || (float) $decision->eligible_source_amount <= 0
            || ! is_string($decision->source_currency) || preg_match('/^[A-Z]{3}$/', $decision->source_currency) !== 1
            || $decision->fx_rate_to_lkr === null || (float) $decision->fx_rate_to_lkr <= 0
            || $decision->fx_rate_at === null || ! is_string($decision->fx_source) || trim($decision->fx_source) === '') {
            return $this->blocked($decision, 'The original decision has no complete governed source-currency and frozen FX snapshot.');
        }

        if (in_array($decision->hold_code, ['finality_policy_missing', 'finality_policy_invalid'], true)) {
            return $this->previewLateFinalityPolicy($decision);
        }
        if ($decision->hold_code === 'payment_finality_unknown') {
            return $this->previewLateUnknownFinality($decision);
        }
        if ($decision->hold_code === 'legal_entity_missing') {
            return $this->previewLateLegalEntity($decision);
        }
        if ($decision->hold_code === 'plan_family_missing') {
            return $this->previewLatePlanFamily($decision);
        }
        if (in_array($decision->hold_code, [
            'beneficiary_missing', 'collection_handler_ineligible', 'commission_beneficiary_ineligible',
        ], true)
            || ($decision->hold_code === 'legal_entity_mismatch'
                && ($decision->beneficiary_sales_profile_id || $decision->beneficiary_staff_id))) {
            return $this->previewLateBeneficiaryCorrection($decision);
        }
        if (in_array($decision->hold_code, [
            'acquisition_profile_missing', 'acquisition_profile_ineligible', 'legal_entity_mismatch',
        ], true)) {
            return $this->previewLateAcquisitionOwner($decision);
        }

        $receipt = BookingPaymentReceipt::query()->find($decision->receipt_id);
        if (! $receipt || $receipt->company_id !== $decision->company_id
            || $receipt->finality_status !== 'confirmed' || $decision->receipt_finality_status !== 'confirmed') {
            return $this->blocked($decision, 'The original receipt does not have confirmed finality evidence.');
        }
        $policy = $decision->finality_policy_id
            ? BookingPaymentFinalityPolicy::query()->whereKey($decision->finality_policy_id)
                ->where('company_id', $decision->company_id)->where('status', 'approved')
                ->whereNotNull('approved_by')->whereNotNull('approved_at')
                ->where('effective_from', '<=', $receipt->received_at)
                ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $receipt->received_at))
                ->first()
            : null;
        if (! $policy) {
            return $this->blocked($decision, 'The original decision has no approved effective payment-finality policy snapshot.');
        }

        $attributions = SalesBookingAttribution::query()->where('booking_id', $decision->booking_id)->get();
        if ($attributions->count() !== 1) {
            return $this->blocked($decision, 'Exactly one canonical booking attribution must now exist.');
        }
        $attribution = $attributions->first();
        if ($attribution->version !== 1 || $attribution->status !== 'active' || ! $attribution->company_id
            || $attribution->company_id !== $decision->company_id || ! $attribution->commission_plan_family_id
            || ! $attribution->commission_plan_assignment_id || $attribution->secured_at->gt($receipt->received_at)
            || ! SalesCommissionPlanFamily::query()->whereKey($attribution->commission_plan_family_id)
                ->where('company_id', $decision->company_id)->where('status', 'approved')->exists()
            || ! SalesCommissionPlanAssignment::query()->whereKey($attribution->commission_plan_assignment_id)
                ->where('company_id', $decision->company_id)->where('plan_family_id', $attribution->commission_plan_family_id)
                ->where('status', 'approved')->where('effective_from', '<=', $attribution->secured_at)
                ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $attribution->secured_at))
                ->exists()) {
            return $this->blocked($decision, 'The late attribution must be an active version-one canonical fact with matching legal entity and frozen plan family.');
        }
        $sourceEvent = DB::table('sales_booking_attribution_events')->where('attribution_id', $attribution->id)
            ->where('version', 1)->where('event_type', 'confirmed')
            ->where('effective_at', '<=', $receipt->received_at)->first();
        if (! $sourceEvent || ! $sourceEvent->actor_user_id || ! $sourceEvent->to_sales_profile_id
            || $sourceEvent->to_sales_profile_id !== $attribution->collection_sales_profile_id) {
            return $this->blocked($decision, 'The late attribution lacks one actor-owned immutable confirmation event effective by the receipt time.');
        }

        $acquisitionProfile = $attribution->acquisition_sales_profile_id
            ? SalesProfile::query()->withTrashed()->find($attribution->acquisition_sales_profile_id)
            : null;
        $beneficiary = SalesProfile::query()->withTrashed()->with('staff')->find($sourceEvent->to_sales_profile_id);
        if (! $acquisitionProfile || ! $this->profileEligibility->isEligibleAt($acquisitionProfile, ['acquisition'], $attribution->secured_at)) {
            return $this->blocked($decision, 'The canonical acquisition Profile was not eligible at the secured time.');
        }
        if (! $beneficiary?->staff_id || $beneficiary->company_id !== $decision->company_id
            || ! $this->profileEligibility->isEligibleAt($beneficiary, ['collection', 'commission'], $receipt->received_at)) {
            return $this->blocked($decision, 'The canonical beneficiary was not collection- and commission-eligible at the receipt time.');
        }

        $calculation = $this->formulaReplay->calculate(
            $decision->company_id,
            $beneficiary->staff_id,
            $attribution->commission_plan_family_id,
            (float) $decision->eligible_lkr_amount,
            $receipt->received_at,
        );
        if (isset($calculation['blocker']) || (float) ($calculation['commission_amount_lkr'] ?? 0) <= 0) {
            return $this->blocked($decision, $calculation['blocker'] ?? 'The approved original-time formula did not produce a positive adjustment.');
        }

        $snapshot = [
            'adjustment_kind' => 'late_attribution_entitlement',
            'commission_decision_id' => $decision->id,
            'original_hold_code' => $decision->hold_code,
            'decision_version' => $decision->event_version,
            'company_id' => $decision->company_id,
            'booking_id' => $decision->booking_id,
            'receipt_id' => $receipt->id,
            'receipt_component_id' => $decision->receipt_component_id,
            'receipt_received_at' => $receipt->received_at?->toIso8601String(),
            'receipt_finality_status' => $receipt->finality_status,
            'finality_policy_id' => $policy->id,
            'source_attribution_id' => $attribution->id,
            'source_attribution_version' => $attribution->version,
            'source_attribution_event_id' => $sourceEvent->id,
            'source_attribution_effective_at' => $sourceEvent->effective_at,
            'source_prepared_by' => $sourceEvent->actor_user_id,
            'acquisition_sales_profile_id' => $acquisitionProfile->id,
            'beneficiary_sales_profile_id' => $beneficiary->id,
            'beneficiary_staff_id' => $beneficiary->staff_id,
            'commission_category' => $attribution->commission_category,
            'collection_cohort' => $this->cohort($attribution->secured_at, $receipt->received_at),
            'eligible_source_amount' => (string) $decision->eligible_source_amount,
            'source_currency' => $decision->source_currency,
            'fx_rate_to_lkr' => (string) $decision->fx_rate_to_lkr,
            'fx_rate_at' => $decision->fx_rate_at?->toIso8601String(),
            'fx_source' => $decision->fx_source,
            'eligible_lkr_amount' => (string) $decision->eligible_lkr_amount,
            'plan_family_id' => $attribution->commission_plan_family_id,
            'plan_assignment_id' => $attribution->commission_plan_assignment_id,
            'calculation' => $calculation,
        ];

        return [
            'adjustment_allowed' => true,
            'blocker' => null,
            'decision' => $decision->only(['id', 'status', 'hold_code', 'event_version']),
            'source' => ['attribution_id' => $attribution->id, 'attribution_event_id' => $sourceEvent->id,
                'source_prepared_by' => $sourceEvent->actor_user_id,
                'prohibited_approver_ids' => [$sourceEvent->actor_user_id]],
            'beneficiary' => ['sales_profile_id' => $beneficiary->id, 'staff_id' => $beneficiary->staff_id],
            'calculation' => $calculation,
            'frozen_adjustment_snapshot' => $snapshot,
            'calculation_checksum' => $this->checksum($snapshot),
            'write_performed' => false,
        ];
    }

    private function previewLateFinalityPolicy(SalesCommissionDecision $decision): array
    {
        $originalFinalityStatus = $decision->hold_code === 'finality_policy_invalid'
            ? 'pending_clearance' : 'policy_missing';
        $receipt = BookingPaymentReceipt::query()->find($decision->receipt_id);
        if (! $receipt || $receipt->company_id !== $decision->company_id
            || $receipt->initial_finality_status !== $originalFinalityStatus
            || $receipt->finality_status !== 'confirmed') {
            return $this->blocked($decision,
                'The original held receipt must now have one canonical confirmed finality state.');
        }

        $policies = BookingPaymentFinalityPolicy::query()
            ->where('company_id', $decision->company_id)
            ->where('payment_method', strtolower((string) $receipt->payment_method))
            ->where('status', 'approved')->whereNotNull('approved_by')->whereNotNull('approved_at')
            ->where('effective_from', '<=', $receipt->received_at)
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $receipt->received_at))
            ->get();
        if ($policies->count() !== 1) {
            return $this->blocked($decision,
                'Exactly one approved payment-finality policy must cover the original receipt timestamp.');
        }
        $policy = $policies->first();
        if ($decision->hold_code === 'finality_policy_invalid'
            && ($decision->finality_policy_id !== $policy->id || $policy->hold_payout_until_final)) {
            return $this->blocked($decision,
                'The confirmed receipt does not reconcile to the same frozen policy that caused the invalid-payout hold.');
        }
        if ($policy->created_by === $policy->approved_by) {
            return $this->blocked($decision, 'The finality policy lacks maker-checker approval evidence.');
        }

        $events = BookingPaymentReceiptFinalityEvent::query()
            ->where('booking_payment_receipt_id', $receipt->id)
            ->where('company_id', $decision->company_id)
            ->where('finality_policy_id', $policy->id)
            ->where('from_status', $originalFinalityStatus)->where('to_status', 'confirmed')
            ->whereNotNull('performed_by')->get();
        if ($events->count() !== 1) {
            return $this->blocked($decision,
                'Exactly one immutable original-state to confirmed finality event is required.');
        }
        $finalityEvent = $events->first();
        if ($finalityEvent->occurred_at->lt($receipt->received_at)) {
            return $this->blocked($decision, 'The confirmed finality event predates the original receipt.');
        }
        if ($policy->required_evidence_type) {
            $evidence = $finalityEvent->evidence_reference
                ? DB::table('domain_evidence_files')->whereKey($finalityEvent->evidence_reference)
                    ->whereNull('deleted_at')->where('domain', 'sales')
                    ->where('company_id', $decision->company_id)
                    ->where('subject_type', 'booking_payment_receipt')->where('subject_id', $receipt->id)
                    ->where('evidence_type', $policy->required_evidence_type)->first()
                : null;
            if (! $evidence) {
                return $this->blocked($decision,
                    'The confirmed finality event lacks the private evidence required by the approved policy.');
            }
        }

        $attribution = $decision->booking_attribution_id
            ? SalesBookingAttribution::query()->find($decision->booking_attribution_id) : null;
        if (! $attribution || $attribution->company_id !== $decision->company_id
            || $attribution->id !== $decision->booking_attribution_id
            || $attribution->commission_plan_family_id !== $decision->plan_family_id
            || $attribution->commission_plan_assignment_id !== $decision->plan_assignment_id
            || $attribution->secured_at->gt($receipt->received_at)) {
            return $this->blocked($decision,
                'The immutable decision no longer reconciles to its original legal-entity attribution and plan assignment.');
        }
        $sourceEvent = DB::table('sales_booking_attribution_events')
            ->where('attribution_id', $attribution->id)->where('event_type', 'confirmed')
            ->where('effective_at', '<=', $receipt->received_at)
            ->orderBy('version')->first();
        if (! $sourceEvent) {
            return $this->blocked($decision, 'The original canonical attribution confirmation event is unavailable.');
        }

        $acquisitionProfile = $decision->acquisition_sales_profile_id
            ? SalesProfile::query()->withTrashed()->find($decision->acquisition_sales_profile_id) : null;
        $beneficiary = $decision->beneficiary_sales_profile_id
            ? SalesProfile::query()->withTrashed()->with('staff')->find($decision->beneficiary_sales_profile_id) : null;
        if (! $acquisitionProfile || ! $this->profileEligibility->isEligibleAt(
            $acquisitionProfile, ['acquisition'], $attribution->secured_at
        )) {
            return $this->blocked($decision, 'The frozen acquisition Profile was not eligible at the secured time.');
        }
        if (! $beneficiary?->staff_id || $beneficiary->staff_id !== $decision->beneficiary_staff_id
            || $beneficiary->company_id !== $decision->company_id
            || ! $this->profileEligibility->isEligibleAt($beneficiary, ['collection', 'commission'], $receipt->received_at)) {
            return $this->blocked($decision,
                'The frozen beneficiary was not collection- and commission-eligible at the receipt time.');
        }

        $formulaComplete = match ($decision->formula_kind) {
            'percentage' => $decision->applied_rate !== null,
            'fixed' => $decision->fixed_amount_lkr !== null,
            'tiered_percentage' => $decision->plan_tier_id !== null && $decision->applied_rate !== null,
            default => false,
        };
        if (! $decision->plan_family_id || ! $decision->plan_assignment_id || ! $formulaComplete
            || $decision->commission_amount_lkr === null || (float) $decision->commission_amount_lkr <= 0) {
            return $this->blocked($decision,
                'The original decision lacks a positive reproducible frozen commission formula.');
        }
        if (! SalesCommissionPlanFamily::query()->whereKey($decision->plan_family_id)
            ->where('company_id', $decision->company_id)->where('status', 'approved')->exists()
            || ! SalesCommissionPlanAssignment::query()->whereKey($decision->plan_assignment_id)
                ->where('company_id', $decision->company_id)->where('plan_family_id', $decision->plan_family_id)
                ->where('status', 'approved')->exists()
            || ($decision->plan_version_id && ! SalesCommissionPlanVersion::query()->whereKey($decision->plan_version_id)
                ->where('plan_family_id', $decision->plan_family_id)->where('status', 'approved')->exists())
            || ($decision->plan_tier_id && ! SalesCommissionPlanTier::query()->whereKey($decision->plan_tier_id)
                ->where('plan_version_id', $decision->plan_version_id)->exists())
            || ($decision->staff_override_id && ! SalesCommissionStaffOverride::query()->whereKey($decision->staff_override_id)
                ->where('company_id', $decision->company_id)->where('staff_id', $decision->beneficiary_staff_id)
                ->where('status', 'approved')->exists())) {
            return $this->blocked($decision, 'The frozen approved plan, tier, assignment, or Staff override evidence is unavailable.');
        }
        $calculation = [
            'plan_version_id' => $decision->plan_version_id,
            'plan_tier_id' => $decision->plan_tier_id,
            'staff_override_id' => $decision->staff_override_id,
            'formula_kind' => $decision->formula_kind,
            'applied_rate' => $decision->applied_rate !== null ? (float) $decision->applied_rate : null,
            'fixed_amount_lkr' => $decision->fixed_amount_lkr !== null ? (float) $decision->fixed_amount_lkr : null,
            'commission_amount_lkr' => (float) $decision->commission_amount_lkr,
            'calculation_explanation' => 'Frozen original-time commission formula accepted after governed finality evidence became available.',
        ];
        $snapshot = [
            'adjustment_kind' => 'late_finality_policy_entitlement',
            'commission_decision_id' => $decision->id, 'original_hold_code' => $decision->hold_code,
            'decision_version' => $decision->event_version, 'company_id' => $decision->company_id,
            'booking_id' => $decision->booking_id, 'receipt_id' => $receipt->id,
            'receipt_component_id' => $decision->receipt_component_id,
            'receipt_received_at' => $receipt->received_at?->toIso8601String(),
            'receipt_initial_finality_status' => $receipt->initial_finality_status,
            'receipt_finality_status' => $receipt->finality_status,
            'finality_policy_id' => $policy->id, 'finality_policy_version' => $policy->version,
            'finality_policy_snapshot' => $policy->only([
                'company_id', 'payment_method', 'official_collection_state', 'can_earn_before_final',
                'hold_payout_until_final', 'clearance_timeout_hours', 'required_evidence_type',
                'effective_from', 'effective_until', 'created_by', 'approved_by', 'approved_at',
            ]),
            'receipt_finality_event_id' => $finalityEvent->id,
            'receipt_finality_event_at' => $finalityEvent->occurred_at?->toIso8601String(),
            'receipt_finality_evidence_reference' => $finalityEvent->evidence_reference,
            'source_attribution_id' => $attribution->id,
            'source_attribution_version' => $attribution->version,
            'source_attribution_event_id' => $sourceEvent->id,
            'source_prepared_by' => $finalityEvent->performed_by,
            'acquisition_sales_profile_id' => $acquisitionProfile->id,
            'beneficiary_sales_profile_id' => $beneficiary->id,
            'beneficiary_staff_id' => $beneficiary->staff_id,
            'commission_category' => $decision->commission_category,
            'collection_cohort' => $decision->collection_cohort,
            'eligible_source_amount' => (string) $decision->eligible_source_amount,
            'source_currency' => $decision->source_currency,
            'fx_rate_to_lkr' => (string) $decision->fx_rate_to_lkr,
            'fx_rate_at' => $decision->fx_rate_at?->toIso8601String(),
            'fx_source' => $decision->fx_source,
            'eligible_lkr_amount' => (string) $decision->eligible_lkr_amount,
            'plan_family_id' => $decision->plan_family_id,
            'plan_assignment_id' => $decision->plan_assignment_id,
            'calculation' => $calculation,
        ];

        return [
            'adjustment_allowed' => true, 'blocker' => null,
            'decision' => $decision->only(['id', 'status', 'hold_code', 'event_version']),
            'source' => [
                'source_prepared_by' => $finalityEvent->performed_by,
                'prohibited_approver_ids' => array_values(array_unique(array_filter([
                    $finalityEvent->performed_by, $policy->created_by, $policy->approved_by,
                ]))),
            ],
            'beneficiary' => ['sales_profile_id' => $beneficiary->id, 'staff_id' => $beneficiary->staff_id],
            'calculation' => $calculation, 'frozen_adjustment_snapshot' => $snapshot,
            'calculation_checksum' => $this->checksum($snapshot), 'write_performed' => false,
        ];
    }

    /**
     * payment_finality_unknown fires when the receipt's finality_status is not one of the four
     * values any current governed writer can ever produce (confirmed/pending_clearance/policy_missing/
     * failed) — unreachable through normal operation, so this only guards a corrupted or pre-existing
     * legacy row. Every other frozen decision fact (plan family, beneficiary, formula) was already
     * resolved before this hold fired, exactly like finality_policy_missing/invalid, so this mirrors
     * previewLateFinalityPolicy() but derives the original anomalous state from the decision's own
     * frozen receipt_finality_status instead of a fixed hold-code mapping.
     */
    private function previewLateUnknownFinality(SalesCommissionDecision $decision): array
    {
        $originalFinalityStatus = $decision->receipt_finality_status;
        $knownStatuses = ['confirmed', 'pending_clearance', 'policy_missing', 'failed'];
        if (! is_string($originalFinalityStatus) || trim($originalFinalityStatus) === ''
            || in_array($originalFinalityStatus, $knownStatuses, true)) {
            return $this->blocked($decision, 'The original decision does not carry a genuinely unrecognized frozen finality state.');
        }
        $receipt = BookingPaymentReceipt::query()->find($decision->receipt_id);
        if (! $receipt || $receipt->company_id !== $decision->company_id
            || $receipt->initial_finality_status !== $originalFinalityStatus
            || $receipt->finality_status !== 'confirmed') {
            return $this->blocked($decision,
                'The original held receipt must now have one canonical confirmed finality state.');
        }

        $policies = BookingPaymentFinalityPolicy::query()
            ->where('company_id', $decision->company_id)
            ->where('payment_method', strtolower((string) $receipt->payment_method))
            ->where('status', 'approved')->whereNotNull('approved_by')->whereNotNull('approved_at')
            ->where('effective_from', '<=', $receipt->received_at)
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $receipt->received_at))
            ->get();
        if ($policies->count() !== 1) {
            return $this->blocked($decision,
                'Exactly one approved payment-finality policy must cover the original receipt timestamp.');
        }
        $policy = $policies->first();
        if ($policy->created_by === $policy->approved_by) {
            return $this->blocked($decision, 'The finality policy lacks maker-checker approval evidence.');
        }

        $events = BookingPaymentReceiptFinalityEvent::query()
            ->where('booking_payment_receipt_id', $receipt->id)
            ->where('company_id', $decision->company_id)
            ->where('from_status', $originalFinalityStatus)->where('to_status', 'confirmed')
            ->whereNotNull('performed_by')->get();
        if ($events->count() !== 1) {
            return $this->blocked($decision,
                'Exactly one immutable original-unknown-state to confirmed finality event is required.');
        }
        $finalityEvent = $events->first();
        if ($finalityEvent->occurred_at->lt($receipt->received_at)) {
            return $this->blocked($decision, 'The confirmed finality event predates the original receipt.');
        }
        if ($policy->required_evidence_type) {
            $evidence = $finalityEvent->evidence_reference
                ? DB::table('domain_evidence_files')->whereKey($finalityEvent->evidence_reference)
                    ->whereNull('deleted_at')->where('domain', 'sales')
                    ->where('company_id', $decision->company_id)
                    ->where('subject_type', 'booking_payment_receipt')->where('subject_id', $receipt->id)
                    ->where('evidence_type', $policy->required_evidence_type)->first()
                : null;
            if (! $evidence) {
                return $this->blocked($decision,
                    'The confirmed finality event lacks the private evidence required by the approved policy.');
            }
        }

        $attribution = $decision->booking_attribution_id
            ? SalesBookingAttribution::query()->find($decision->booking_attribution_id) : null;
        if (! $attribution || $attribution->company_id !== $decision->company_id
            || $attribution->id !== $decision->booking_attribution_id
            || $attribution->commission_plan_family_id !== $decision->plan_family_id
            || $attribution->commission_plan_assignment_id !== $decision->plan_assignment_id
            || $attribution->secured_at->gt($receipt->received_at)) {
            return $this->blocked($decision,
                'The immutable decision no longer reconciles to its original legal-entity attribution and plan assignment.');
        }
        $sourceEvent = DB::table('sales_booking_attribution_events')
            ->where('attribution_id', $attribution->id)->where('event_type', 'confirmed')
            ->where('effective_at', '<=', $receipt->received_at)
            ->orderBy('version')->first();
        if (! $sourceEvent) {
            return $this->blocked($decision, 'The original canonical attribution confirmation event is unavailable.');
        }

        $acquisitionProfile = $decision->acquisition_sales_profile_id
            ? SalesProfile::query()->withTrashed()->find($decision->acquisition_sales_profile_id) : null;
        $beneficiary = $decision->beneficiary_sales_profile_id
            ? SalesProfile::query()->withTrashed()->with('staff')->find($decision->beneficiary_sales_profile_id) : null;
        if (! $acquisitionProfile || ! $this->profileEligibility->isEligibleAt(
            $acquisitionProfile, ['acquisition'], $attribution->secured_at
        )) {
            return $this->blocked($decision, 'The frozen acquisition Profile was not eligible at the secured time.');
        }
        if (! $beneficiary?->staff_id || $beneficiary->staff_id !== $decision->beneficiary_staff_id
            || $beneficiary->company_id !== $decision->company_id
            || ! $this->profileEligibility->isEligibleAt($beneficiary, ['collection', 'commission'], $receipt->received_at)) {
            return $this->blocked($decision,
                'The frozen beneficiary was not collection- and commission-eligible at the receipt time.');
        }

        $formulaComplete = match ($decision->formula_kind) {
            'percentage' => $decision->applied_rate !== null,
            'fixed' => $decision->fixed_amount_lkr !== null,
            'tiered_percentage' => $decision->plan_tier_id !== null && $decision->applied_rate !== null,
            default => false,
        };
        if (! $decision->plan_family_id || ! $decision->plan_assignment_id || ! $formulaComplete
            || $decision->commission_amount_lkr === null || (float) $decision->commission_amount_lkr <= 0) {
            return $this->blocked($decision,
                'The original decision lacks a positive reproducible frozen commission formula.');
        }
        if (! SalesCommissionPlanFamily::query()->whereKey($decision->plan_family_id)
            ->where('company_id', $decision->company_id)->where('status', 'approved')->exists()
            || ! SalesCommissionPlanAssignment::query()->whereKey($decision->plan_assignment_id)
                ->where('company_id', $decision->company_id)->where('plan_family_id', $decision->plan_family_id)
                ->where('status', 'approved')->exists()
            || ($decision->plan_version_id && ! SalesCommissionPlanVersion::query()->whereKey($decision->plan_version_id)
                ->where('plan_family_id', $decision->plan_family_id)->where('status', 'approved')->exists())
            || ($decision->plan_tier_id && ! SalesCommissionPlanTier::query()->whereKey($decision->plan_tier_id)
                ->where('plan_version_id', $decision->plan_version_id)->exists())
            || ($decision->staff_override_id && ! SalesCommissionStaffOverride::query()->whereKey($decision->staff_override_id)
                ->where('company_id', $decision->company_id)->where('staff_id', $decision->beneficiary_staff_id)
                ->where('status', 'approved')->exists())) {
            return $this->blocked($decision, 'The frozen approved plan, tier, assignment, or Staff override evidence is unavailable.');
        }
        $calculation = [
            'plan_version_id' => $decision->plan_version_id,
            'plan_tier_id' => $decision->plan_tier_id,
            'staff_override_id' => $decision->staff_override_id,
            'formula_kind' => $decision->formula_kind,
            'applied_rate' => $decision->applied_rate !== null ? (float) $decision->applied_rate : null,
            'fixed_amount_lkr' => $decision->fixed_amount_lkr !== null ? (float) $decision->fixed_amount_lkr : null,
            'commission_amount_lkr' => (float) $decision->commission_amount_lkr,
            'calculation_explanation' => 'Frozen original-time commission formula accepted after governed finality evidence became available.',
        ];
        $snapshot = [
            'adjustment_kind' => 'late_unknown_finality_entitlement',
            'commission_decision_id' => $decision->id, 'original_hold_code' => $decision->hold_code,
            'decision_version' => $decision->event_version, 'company_id' => $decision->company_id,
            'booking_id' => $decision->booking_id, 'receipt_id' => $receipt->id,
            'receipt_component_id' => $decision->receipt_component_id,
            'receipt_received_at' => $receipt->received_at?->toIso8601String(),
            'receipt_initial_finality_status' => $receipt->initial_finality_status,
            'receipt_finality_status' => $receipt->finality_status,
            'finality_policy_id' => $policy->id, 'finality_policy_version' => $policy->version,
            'finality_policy_snapshot' => $policy->only([
                'company_id', 'payment_method', 'official_collection_state', 'can_earn_before_final',
                'hold_payout_until_final', 'clearance_timeout_hours', 'required_evidence_type',
                'effective_from', 'effective_until', 'created_by', 'approved_by', 'approved_at',
            ]),
            'receipt_finality_event_id' => $finalityEvent->id,
            'receipt_finality_event_at' => $finalityEvent->occurred_at?->toIso8601String(),
            'receipt_finality_evidence_reference' => $finalityEvent->evidence_reference,
            'source_attribution_id' => $attribution->id,
            'source_attribution_version' => $attribution->version,
            'source_attribution_event_id' => $sourceEvent->id,
            'source_prepared_by' => $finalityEvent->performed_by,
            'acquisition_sales_profile_id' => $acquisitionProfile->id,
            'beneficiary_sales_profile_id' => $beneficiary->id,
            'beneficiary_staff_id' => $beneficiary->staff_id,
            'commission_category' => $decision->commission_category,
            'collection_cohort' => $decision->collection_cohort,
            'eligible_source_amount' => (string) $decision->eligible_source_amount,
            'source_currency' => $decision->source_currency,
            'fx_rate_to_lkr' => (string) $decision->fx_rate_to_lkr,
            'fx_rate_at' => $decision->fx_rate_at?->toIso8601String(),
            'fx_source' => $decision->fx_source,
            'eligible_lkr_amount' => (string) $decision->eligible_lkr_amount,
            'plan_family_id' => $decision->plan_family_id,
            'plan_assignment_id' => $decision->plan_assignment_id,
            'calculation' => $calculation,
        ];

        return [
            'adjustment_allowed' => true, 'blocker' => null,
            'decision' => $decision->only(['id', 'status', 'hold_code', 'event_version']),
            'source' => [
                'source_prepared_by' => $finalityEvent->performed_by,
                'prohibited_approver_ids' => array_values(array_unique(array_filter([
                    $finalityEvent->performed_by, $policy->created_by, $policy->approved_by,
                ]))),
            ],
            'beneficiary' => ['sales_profile_id' => $beneficiary->id, 'staff_id' => $beneficiary->staff_id],
            'calculation' => $calculation, 'frozen_adjustment_snapshot' => $snapshot,
            'calculation_checksum' => $this->checksum($snapshot), 'write_performed' => false,
        ];
    }

    private function previewLateAcquisitionOwner(SalesCommissionDecision $decision): array
    {
        if ($decision->hold_code === 'legal_entity_mismatch'
            && ($decision->beneficiary_sales_profile_id || $decision->beneficiary_staff_id)) {
            return $this->blocked($decision,
                'This legal-entity mismatch belongs to the receipt beneficiary and cannot use the acquisition-owner correction path.');
        }

        $receipt = BookingPaymentReceipt::query()->find($decision->receipt_id);
        if (! $receipt || $receipt->company_id !== $decision->company_id
            || $receipt->finality_status !== 'confirmed' || $decision->receipt_finality_status !== 'confirmed') {
            return $this->blocked($decision, 'The original receipt does not have confirmed finality evidence.');
        }

        $policies = BookingPaymentFinalityPolicy::query()
            ->where('company_id', $decision->company_id)
            ->where('payment_method', strtolower((string) $receipt->payment_method))
            ->where('status', 'approved')->whereNotNull('approved_by')->whereNotNull('approved_at')
            ->where('effective_from', '<=', $receipt->received_at)
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $receipt->received_at))
            ->get();
        if ($policies->count() !== 1 || $policies->first()->id !== $decision->finality_policy_id) {
            return $this->blocked($decision,
                'Exactly one approved payment-finality policy must match the original decision and receipt timestamp.');
        }
        $policy = $policies->first();
        if ($policy->created_by === $policy->approved_by) {
            return $this->blocked($decision, 'The finality policy lacks maker-checker approval evidence.');
        }

        $attribution = $decision->booking_attribution_id
            ? SalesBookingAttribution::query()->find($decision->booking_attribution_id) : null;
        if (! $attribution || $attribution->booking_id !== $decision->booking_id
            || $attribution->company_id !== $decision->company_id
            || ! $attribution->acquisition_sales_profile_id || $attribution->secured_at->gt($receipt->received_at)
            || $attribution->commission_plan_family_id !== $decision->plan_family_id
            || $attribution->commission_plan_assignment_id !== $decision->plan_assignment_id
            || $attribution->commission_category !== $decision->commission_category
            || ! $decision->plan_family_id || ! $decision->plan_assignment_id) {
            return $this->blocked($decision,
                'The corrected attribution must retain the original legal entity, booking, secured time, and frozen plan assignment.');
        }

        $corrections = DB::table('sales_booking_attribution_events')
            ->where('attribution_id', $attribution->id)
            ->where('event_type', 'acquisition_owner_corrected')
            ->where('field_name', 'acquisition_sales_profile_id')
            ->where('to_sales_profile_id', $attribution->acquisition_sales_profile_id)
            ->when($decision->acquisition_sales_profile_id,
                fn ($query, $profileId) => $query->where('from_sales_profile_id', $profileId),
                fn ($query) => $query->whereNull('from_sales_profile_id'))
            ->get();
        $allAcquisitionCorrections = DB::table('sales_booking_attribution_events')
            ->where('attribution_id', $attribution->id)
            ->where('field_name', 'acquisition_sales_profile_id')->count();
        if ($corrections->count() !== 1 || $allAcquisitionCorrections !== 1) {
            return $this->blocked($decision,
                'Exactly one immutable acquisition-owner correction must replace the original missing, ineligible, or wrong-entity Profile reference.');
        }
        $correction = $corrections->first();
        if (! $correction->actor_user_id
            || CarbonImmutable::parse($correction->effective_at)->getTimestamp() !== $attribution->secured_at->getTimestamp()) {
            return $this->blocked($decision,
                'The acquisition-owner correction must be actor-owned and effective at the original secured time.');
        }

        $beneficiaryEvent = $this->collectionProfileEvidenceAt($attribution, $receipt->received_at);
        if (! $beneficiaryEvent?->actor_user_id || ! $beneficiaryEvent->to_sales_profile_id) {
            return $this->blocked($decision,
                'The original receipt-time collection handler lacks actor-owned immutable attribution evidence.');
        }
        $acquisitionProfile = SalesProfile::query()->withTrashed()->find($attribution->acquisition_sales_profile_id);
        $beneficiary = SalesProfile::query()->withTrashed()->with('staff')->find($beneficiaryEvent->to_sales_profile_id);
        if (! $acquisitionProfile || $acquisitionProfile->company_id !== $decision->company_id
            || ! $this->profileEligibility->isEligibleAt($acquisitionProfile, ['acquisition'], $attribution->secured_at)) {
            return $this->blocked($decision, 'The corrected acquisition Profile was not eligible at the secured time.');
        }
        if (! $beneficiary?->staff_id || $beneficiary->company_id !== $decision->company_id
            || ! $this->profileEligibility->isEligibleAt($beneficiary, ['collection', 'commission'], $receipt->received_at)) {
            return $this->blocked($decision,
                'The frozen receipt-time beneficiary Staff/Profile was not collection- and commission-eligible.');
        }

        if (! SalesCommissionPlanFamily::query()->whereKey($decision->plan_family_id)
            ->where('company_id', $decision->company_id)->where('status', 'approved')->exists()
            || ! SalesCommissionPlanAssignment::query()->whereKey($decision->plan_assignment_id)
                ->where('company_id', $decision->company_id)->where('plan_family_id', $decision->plan_family_id)
                ->where('status', 'approved')->where('effective_from', '<=', $attribution->secured_at)
                ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $attribution->secured_at))
                ->exists()) {
            return $this->blocked($decision, 'The original approved plan family or assignment evidence is unavailable.');
        }
        $calculation = $this->formulaReplay->calculate(
            $decision->company_id,
            $beneficiary->staff_id,
            $decision->plan_family_id,
            (float) $decision->eligible_lkr_amount,
            $receipt->received_at,
        );
        if (isset($calculation['blocker']) || (float) ($calculation['commission_amount_lkr'] ?? 0) <= 0) {
            return $this->blocked($decision,
                $calculation['blocker'] ?? 'The approved original-time formula did not produce a positive adjustment.');
        }

        $snapshot = [
            'adjustment_kind' => 'late_acquisition_owner_entitlement',
            'commission_decision_id' => $decision->id, 'original_hold_code' => $decision->hold_code,
            'decision_version' => $decision->event_version, 'company_id' => $decision->company_id,
            'booking_id' => $decision->booking_id, 'receipt_id' => $receipt->id,
            'receipt_component_id' => $decision->receipt_component_id,
            'receipt_received_at' => $receipt->received_at?->toIso8601String(),
            'receipt_finality_status' => $receipt->finality_status,
            'finality_policy_id' => $policy->id, 'finality_policy_version' => $policy->version,
            'source_attribution_id' => $attribution->id,
            'source_attribution_version' => $attribution->version,
            'source_attribution_event_id' => $correction->id,
            'source_attribution_effective_at' => $correction->effective_at,
            'source_prepared_by' => $correction->actor_user_id,
            'beneficiary_attribution_event_id' => $beneficiaryEvent->id,
            'beneficiary_attribution_event_version' => $beneficiaryEvent->version,
            'beneficiary_attribution_prepared_by' => $beneficiaryEvent->actor_user_id,
            'acquisition_sales_profile_id' => $acquisitionProfile->id,
            'beneficiary_sales_profile_id' => $beneficiary->id,
            'beneficiary_staff_id' => $beneficiary->staff_id,
            'commission_category' => $decision->commission_category,
            'collection_cohort' => $decision->collection_cohort,
            'eligible_source_amount' => (string) $decision->eligible_source_amount,
            'source_currency' => $decision->source_currency,
            'fx_rate_to_lkr' => (string) $decision->fx_rate_to_lkr,
            'fx_rate_at' => $decision->fx_rate_at?->toIso8601String(),
            'fx_source' => $decision->fx_source,
            'eligible_lkr_amount' => (string) $decision->eligible_lkr_amount,
            'plan_family_id' => $decision->plan_family_id,
            'plan_assignment_id' => $decision->plan_assignment_id,
            'calculation' => $calculation,
        ];

        return [
            'adjustment_allowed' => true, 'blocker' => null,
            'decision' => $decision->only(['id', 'status', 'hold_code', 'event_version']),
            'source' => [
                'attribution_id' => $attribution->id, 'attribution_event_id' => $correction->id,
                'source_prepared_by' => $correction->actor_user_id,
                'prohibited_approver_ids' => array_values(array_unique(array_filter([
                    $correction->actor_user_id, $beneficiaryEvent->actor_user_id,
                    $policy->created_by, $policy->approved_by,
                ]))),
            ],
            'beneficiary' => ['sales_profile_id' => $beneficiary->id, 'staff_id' => $beneficiary->staff_id],
            'calculation' => $calculation, 'frozen_adjustment_snapshot' => $snapshot,
            'calculation_checksum' => $this->checksum($snapshot), 'write_performed' => false,
        ];
    }

    private function previewLateLegalEntity(SalesCommissionDecision $decision): array
    {
        $receipt = BookingPaymentReceipt::query()->find($decision->receipt_id);
        if (! $receipt || $receipt->company_id !== $decision->company_id
            || $receipt->finality_status !== 'confirmed' || $decision->receipt_finality_status !== 'confirmed') {
            return $this->blocked($decision, 'The original receipt does not have confirmed finality evidence.');
        }
        $policies = BookingPaymentFinalityPolicy::query()
            ->where('company_id', $decision->company_id)
            ->where('payment_method', strtolower((string) $receipt->payment_method))
            ->where('status', 'approved')->whereNotNull('approved_by')->whereNotNull('approved_at')
            ->where('effective_from', '<=', $receipt->received_at)
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $receipt->received_at))
            ->get();
        if ($policies->count() !== 1 || $policies->first()->id !== $decision->finality_policy_id) {
            return $this->blocked($decision, 'Exactly one approved payment-finality policy must match the original decision and receipt timestamp.');
        }
        $policy = $policies->first();
        if ($policy->created_by === $policy->approved_by) {
            return $this->blocked($decision, 'The finality policy lacks maker-checker approval evidence.');
        }

        $attribution = $decision->booking_attribution_id
            ? SalesBookingAttribution::query()->find($decision->booking_attribution_id) : null;
        if (! $attribution || $attribution->booking_id !== $decision->booking_id
            || ! $attribution->company_id || $attribution->company_id !== $decision->company_id
            || ! $attribution->acquisition_sales_profile_id || $attribution->secured_at->gt($receipt->received_at)) {
            return $this->blocked($decision,
                'The corrected attribution must now have one immutable legal entity matching the original receipt.');
        }

        $entityEvents = DB::table('sales_booking_attribution_events')
            ->where('attribution_id', $attribution->id)
            ->where('event_type', 'legal_entity_established')->where('field_name', 'company_id')
            ->whereNull('from_value')->where('to_value', $attribution->company_id)->get();
        $allEntityEvents = DB::table('sales_booking_attribution_events')
            ->where('attribution_id', $attribution->id)->where('field_name', 'company_id')->count();
        if ($entityEvents->count() !== 1 || $allEntityEvents !== 1) {
            return $this->blocked($decision,
                'Exactly one immutable legal-entity establishment event must replace the original missing legal entity.');
        }
        $entityEvent = $entityEvents->first();
        if (! $entityEvent->actor_user_id
            || CarbonImmutable::parse($entityEvent->effective_at)->getTimestamp() !== $attribution->secured_at->getTimestamp()) {
            return $this->blocked($decision, 'The legal-entity establishment must be actor-owned and effective at the original secured time.');
        }

        $ownerEvents = DB::table('sales_booking_attribution_events')
            ->where('attribution_id', $attribution->id)
            ->where('event_type', 'acquisition_owner_corrected')->where('field_name', 'acquisition_sales_profile_id')
            ->whereNull('from_sales_profile_id')->where('to_sales_profile_id', $attribution->acquisition_sales_profile_id)->get();
        $allOwnerEvents = DB::table('sales_booking_attribution_events')
            ->where('attribution_id', $attribution->id)->where('field_name', 'acquisition_sales_profile_id')->count();
        if ($ownerEvents->count() !== 1 || $allOwnerEvents !== 1) {
            return $this->blocked($decision,
                'Exactly one immutable acquisition-owner establishment event must accompany the legal-entity correction.');
        }
        $ownerEvent = $ownerEvents->first();
        if (! $ownerEvent->actor_user_id
            || CarbonImmutable::parse($ownerEvent->effective_at)->getTimestamp() !== $attribution->secured_at->getTimestamp()) {
            return $this->blocked($decision, 'The acquisition-owner establishment must be actor-owned and effective at the original secured time.');
        }

        if (! $attribution->commission_plan_family_id || ! $attribution->commission_plan_assignment_id) {
            return $this->blocked($decision,
                'The corrected attribution still lacks a uniquely approved historical commission plan family; resolve the plan family first.');
        }
        if (! SalesCommissionPlanFamily::query()->whereKey($attribution->commission_plan_family_id)
            ->where('company_id', $decision->company_id)->where('status', 'approved')
            ->where('commission_category', $attribution->commission_category)->exists()
            || ! SalesCommissionPlanAssignment::query()->whereKey($attribution->commission_plan_assignment_id)
                ->where('company_id', $decision->company_id)->where('plan_family_id', $attribution->commission_plan_family_id)
                ->where('status', 'approved')->where('effective_from', '<=', $attribution->secured_at)
                ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $attribution->secured_at))
                ->exists()) {
            return $this->blocked($decision, 'The corrected approved plan family or assignment evidence is unavailable.');
        }

        $acquisitionProfile = SalesProfile::query()->withTrashed()->find($attribution->acquisition_sales_profile_id);
        if (! $acquisitionProfile || $acquisitionProfile->company_id !== $decision->company_id
            || ! $this->profileEligibility->isEligibleAt($acquisitionProfile, ['acquisition'], $attribution->secured_at)) {
            return $this->blocked($decision, 'The established acquisition Profile was not eligible at the secured time.');
        }

        $beneficiaryEvent = $this->collectionProfileEvidenceAt($attribution, $receipt->received_at);
        $beneficiary = $beneficiaryEvent?->to_sales_profile_id
            ? SalesProfile::query()->withTrashed()->with('staff')->find($beneficiaryEvent->to_sales_profile_id) : null;
        if (! $beneficiaryEvent?->actor_user_id || ! $beneficiary?->staff_id
            || $beneficiary->company_id !== $decision->company_id
            || ! $this->profileEligibility->isEligibleAt($beneficiary, ['collection', 'commission'], $receipt->received_at)) {
            return $this->blocked($decision, 'The receipt-time beneficiary lacks actor-owned eligible Staff/Profile evidence.');
        }

        $calculation = $this->formulaReplay->calculate(
            $decision->company_id, $beneficiary->staff_id, $attribution->commission_plan_family_id,
            (float) $decision->eligible_lkr_amount, $receipt->received_at,
        );
        if (isset($calculation['blocker']) || (float) ($calculation['commission_amount_lkr'] ?? 0) <= 0) {
            return $this->blocked($decision,
                $calculation['blocker'] ?? 'The approved original-time formula did not produce a positive adjustment.');
        }

        $snapshot = [
            'adjustment_kind' => 'late_legal_entity_entitlement', 'commission_decision_id' => $decision->id,
            'original_hold_code' => $decision->hold_code, 'decision_version' => $decision->event_version,
            'company_id' => $decision->company_id, 'booking_id' => $decision->booking_id,
            'receipt_id' => $receipt->id, 'receipt_component_id' => $decision->receipt_component_id,
            'receipt_received_at' => $receipt->received_at?->toIso8601String(),
            'receipt_finality_status' => $receipt->finality_status,
            'finality_policy_id' => $policy->id, 'finality_policy_version' => $policy->version,
            'legal_entity_event_id' => $entityEvent->id,
            'legal_entity_event_effective_at' => $entityEvent->effective_at,
            'source_attribution_id' => $attribution->id, 'source_attribution_version' => $attribution->version,
            'source_attribution_event_id' => $ownerEvent->id,
            'source_attribution_effective_at' => $ownerEvent->effective_at,
            'source_prepared_by' => $ownerEvent->actor_user_id,
            'beneficiary_attribution_event_id' => $beneficiaryEvent->id,
            'beneficiary_attribution_event_version' => $beneficiaryEvent->version,
            'beneficiary_attribution_prepared_by' => $beneficiaryEvent->actor_user_id,
            'acquisition_sales_profile_id' => $acquisitionProfile->id,
            'beneficiary_sales_profile_id' => $beneficiary->id, 'beneficiary_staff_id' => $beneficiary->staff_id,
            'commission_category' => $decision->commission_category, 'collection_cohort' => $decision->collection_cohort,
            'eligible_source_amount' => (string) $decision->eligible_source_amount, 'source_currency' => $decision->source_currency,
            'fx_rate_to_lkr' => (string) $decision->fx_rate_to_lkr, 'fx_rate_at' => $decision->fx_rate_at?->toIso8601String(),
            'fx_source' => $decision->fx_source, 'eligible_lkr_amount' => (string) $decision->eligible_lkr_amount,
            'plan_family_id' => $attribution->commission_plan_family_id,
            'plan_assignment_id' => $attribution->commission_plan_assignment_id, 'calculation' => $calculation,
        ];

        return [
            'adjustment_allowed' => true, 'blocker' => null,
            'decision' => $decision->only(['id', 'status', 'hold_code', 'event_version']),
            'source' => [
                'attribution_id' => $attribution->id, 'attribution_event_id' => $ownerEvent->id,
                'source_prepared_by' => $ownerEvent->actor_user_id,
                'prohibited_approver_ids' => array_values(array_unique(array_filter([
                    $entityEvent->actor_user_id, $ownerEvent->actor_user_id, $beneficiaryEvent->actor_user_id,
                    $policy->created_by, $policy->approved_by,
                ]))),
            ],
            'beneficiary' => ['sales_profile_id' => $beneficiary->id, 'staff_id' => $beneficiary->staff_id],
            'calculation' => $calculation, 'frozen_adjustment_snapshot' => $snapshot,
            'calculation_checksum' => $this->checksum($snapshot), 'write_performed' => false,
        ];
    }

    /**
     * fx_snapshot_missing fires when the receipt component's own lkr_amount (and, in practice,
     * the receipt-level fx_rate_to_lkr/fx_rate_at/fx_source it was copied from) was never captured —
     * distinct from the reporting_fx correction path in BookingPaymentAdjustmentService, which only
     * corrects a component that already has complete evidence. This method never derives an amount
     * from the decision's own (null) FX fields; it sources every fact from one immutable,
     * Finance-policy-reproducible 'fx_establishment' evidence row keyed to the same receipt component.
     */
    private function previewLateFxSnapshot(SalesCommissionDecision $decision): array
    {
        $receipt = BookingPaymentReceipt::query()->find($decision->receipt_id);
        if (! $receipt || $receipt->company_id !== $decision->company_id
            || $receipt->finality_status !== 'confirmed' || $decision->receipt_finality_status !== 'confirmed') {
            return $this->blocked($decision, 'The original receipt does not have confirmed finality evidence.');
        }
        $policies = BookingPaymentFinalityPolicy::query()
            ->where('company_id', $decision->company_id)
            ->where('payment_method', strtolower((string) $receipt->payment_method))
            ->where('status', 'approved')->whereNotNull('approved_by')->whereNotNull('approved_at')
            ->where('effective_from', '<=', $receipt->received_at)
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $receipt->received_at))
            ->get();
        if ($policies->count() !== 1 || $policies->first()->id !== $decision->finality_policy_id) {
            return $this->blocked($decision, 'Exactly one approved payment-finality policy must match the original decision and receipt timestamp.');
        }
        $policy = $policies->first();
        if ($policy->created_by === $policy->approved_by) {
            return $this->blocked($decision, 'The finality policy lacks maker-checker approval evidence.');
        }

        $attribution = $decision->booking_attribution_id
            ? SalesBookingAttribution::query()->find($decision->booking_attribution_id) : null;
        if (! $attribution || $attribution->booking_id !== $decision->booking_id
            || $attribution->company_id !== $decision->company_id
            || ! $attribution->acquisition_sales_profile_id || $attribution->secured_at->gt($receipt->received_at)) {
            return $this->blocked($decision, 'The attribution must retain the original legal entity, booking, and secured time.');
        }
        if (! $attribution->commission_plan_family_id || ! $attribution->commission_plan_assignment_id) {
            return $this->blocked($decision,
                'The attribution still lacks a uniquely approved historical commission plan family; resolve the plan family first.');
        }
        if (! SalesCommissionPlanFamily::query()->whereKey($attribution->commission_plan_family_id)
            ->where('company_id', $decision->company_id)->where('status', 'approved')
            ->where('commission_category', $attribution->commission_category)->exists()
            || ! SalesCommissionPlanAssignment::query()->whereKey($attribution->commission_plan_assignment_id)
                ->where('company_id', $decision->company_id)->where('plan_family_id', $attribution->commission_plan_family_id)
                ->where('status', 'approved')->where('effective_from', '<=', $attribution->secured_at)
                ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $attribution->secured_at))
                ->exists()) {
            return $this->blocked($decision, 'The corrected approved plan family or assignment evidence is unavailable.');
        }

        $sourceEvent = DB::table('sales_booking_attribution_events')
            ->where('attribution_id', $attribution->id)->where('event_type', 'confirmed')
            ->where('effective_at', '<=', $receipt->received_at)->orderBy('version')->first();
        if (! $sourceEvent) {
            return $this->blocked($decision, 'The original canonical attribution confirmation event is unavailable.');
        }

        $component = BookingPaymentReceiptComponent::query()->find($decision->receipt_component_id);
        if (! $component || $component->receipt_id !== $receipt->id) {
            return $this->blocked($decision, 'The original receipt component evidence is unavailable.');
        }
        $establishments = BookingPaymentAdjustment::query()
            ->where('receipt_component_id', $component->id)->where('impact_dimension', 'fx_establishment')->get();
        if ($establishments->count() !== 1) {
            return $this->blocked($decision, 'Exactly one immutable established FX/LKR snapshot must exist for this receipt component.');
        }
        $establishment = $establishments->first();
        $currentBasis = max(0, round((float) $component->source_amount - (float) $component->adjusted_source_amount, 4));
        if (! $establishment->approved_by || (float) $establishment->lkr_amount <= 0
            || (float) $establishment->source_amount !== $currentBasis
            || strtoupper((string) $establishment->source_currency) !== strtoupper((string) $receipt->source_currency)) {
            return $this->blocked($decision,
                'The established FX/LKR snapshot must reconcile to the current receipt-component balance and currency.');
        }

        $acquisitionProfile = SalesProfile::query()->withTrashed()->find($attribution->acquisition_sales_profile_id);
        if (! $acquisitionProfile || $acquisitionProfile->company_id !== $decision->company_id
            || ! $this->profileEligibility->isEligibleAt($acquisitionProfile, ['acquisition'], $attribution->secured_at)) {
            return $this->blocked($decision, 'The frozen acquisition Profile was not eligible at the secured time.');
        }
        $beneficiaryEvent = $this->collectionProfileEvidenceAt($attribution, $receipt->received_at);
        $beneficiary = $beneficiaryEvent?->to_sales_profile_id
            ? SalesProfile::query()->withTrashed()->with('staff')->find($beneficiaryEvent->to_sales_profile_id) : null;
        if (! $beneficiaryEvent?->actor_user_id || ! $beneficiary?->staff_id
            || $beneficiary->company_id !== $decision->company_id
            || ! $this->profileEligibility->isEligibleAt($beneficiary, ['collection', 'commission'], $receipt->received_at)) {
            return $this->blocked($decision, 'The receipt-time beneficiary lacks actor-owned eligible Staff/Profile evidence.');
        }

        $calculation = $this->formulaReplay->calculate(
            $decision->company_id, $beneficiary->staff_id, $attribution->commission_plan_family_id,
            (float) $establishment->lkr_amount, $receipt->received_at,
        );
        if (isset($calculation['blocker']) || (float) ($calculation['commission_amount_lkr'] ?? 0) <= 0) {
            return $this->blocked($decision,
                $calculation['blocker'] ?? 'The approved original-time formula did not produce a positive adjustment.');
        }

        $snapshot = [
            'adjustment_kind' => 'late_fx_snapshot_entitlement', 'commission_decision_id' => $decision->id,
            'original_hold_code' => $decision->hold_code, 'decision_version' => $decision->event_version,
            'company_id' => $decision->company_id, 'booking_id' => $decision->booking_id,
            'receipt_id' => $receipt->id, 'receipt_component_id' => $decision->receipt_component_id,
            'receipt_received_at' => $receipt->received_at?->toIso8601String(),
            'receipt_finality_status' => $receipt->finality_status,
            'finality_policy_id' => $policy->id, 'finality_policy_version' => $policy->version,
            'fx_establishment_id' => $establishment->id,
            'fx_establishment_effective_at' => $establishment->adjustment_effective_at?->toIso8601String(),
            'fx_establishment_approved_by' => $establishment->approved_by,
            'source_attribution_id' => $attribution->id, 'source_attribution_version' => $attribution->version,
            'source_attribution_event_id' => $sourceEvent->id,
            'source_attribution_effective_at' => $sourceEvent->effective_at,
            'source_prepared_by' => $establishment->approved_by,
            'beneficiary_attribution_event_id' => $beneficiaryEvent->id,
            'beneficiary_attribution_event_version' => $beneficiaryEvent->version,
            'beneficiary_attribution_prepared_by' => $beneficiaryEvent->actor_user_id,
            'acquisition_sales_profile_id' => $acquisitionProfile->id,
            'beneficiary_sales_profile_id' => $beneficiary->id, 'beneficiary_staff_id' => $beneficiary->staff_id,
            'commission_category' => $decision->commission_category, 'collection_cohort' => $decision->collection_cohort,
            'eligible_source_amount' => (string) $establishment->source_amount, 'source_currency' => $establishment->source_currency,
            'fx_rate_to_lkr' => (string) $establishment->fx_rate_to_lkr, 'fx_rate_at' => $establishment->fx_rate_at?->toIso8601String(),
            'fx_source' => $establishment->fx_source, 'eligible_lkr_amount' => (string) $establishment->lkr_amount,
            'plan_family_id' => $attribution->commission_plan_family_id,
            'plan_assignment_id' => $attribution->commission_plan_assignment_id, 'calculation' => $calculation,
        ];

        return [
            'adjustment_allowed' => true, 'blocker' => null,
            'decision' => $decision->only(['id', 'status', 'hold_code', 'event_version']),
            'source' => [
                'attribution_id' => $attribution->id, 'attribution_event_id' => $sourceEvent->id,
                'source_prepared_by' => $establishment->approved_by,
                'prohibited_approver_ids' => array_values(array_unique(array_filter([
                    $establishment->approved_by, $beneficiaryEvent->actor_user_id,
                    $policy->created_by, $policy->approved_by,
                ]))),
            ],
            'beneficiary' => ['sales_profile_id' => $beneficiary->id, 'staff_id' => $beneficiary->staff_id],
            'calculation' => $calculation, 'frozen_adjustment_snapshot' => $snapshot,
            'calculation_checksum' => $this->checksum($snapshot), 'write_performed' => false,
        ];
    }

    private function previewLatePlanFamily(SalesCommissionDecision $decision): array
    {
        $receipt = BookingPaymentReceipt::query()->find($decision->receipt_id);
        if (! $receipt || $receipt->company_id !== $decision->company_id
            || $receipt->finality_status !== 'confirmed' || $decision->receipt_finality_status !== 'confirmed') {
            return $this->blocked($decision, 'The original receipt does not have confirmed finality evidence.');
        }
        $policies = BookingPaymentFinalityPolicy::query()
            ->where('company_id', $decision->company_id)
            ->where('payment_method', strtolower((string) $receipt->payment_method))
            ->where('status', 'approved')->whereNotNull('approved_by')->whereNotNull('approved_at')
            ->where('effective_from', '<=', $receipt->received_at)
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $receipt->received_at))
            ->get();
        if ($policies->count() !== 1 || $policies->first()->id !== $decision->finality_policy_id) {
            return $this->blocked($decision, 'Exactly one approved payment-finality policy must match the original decision and receipt timestamp.');
        }
        $policy = $policies->first();
        if ($policy->created_by === $policy->approved_by) {
            return $this->blocked($decision, 'The finality policy lacks maker-checker approval evidence.');
        }
        $attribution = $decision->booking_attribution_id
            ? SalesBookingAttribution::query()->find($decision->booking_attribution_id) : null;
        if (! $attribution || $attribution->booking_id !== $decision->booking_id
            || $attribution->company_id !== $decision->company_id
            || $attribution->secured_at->gt($receipt->received_at)
            || ! $attribution->commission_plan_family_id || ! $attribution->commission_plan_assignment_id
            || $attribution->commission_category !== $decision->commission_category) {
            return $this->blocked($decision, 'The corrected attribution must retain the original legal entity, booking, secured time, and category.');
        }
        $events = DB::table('sales_booking_attribution_events')
            ->where('attribution_id', $attribution->id)
            ->where('event_type', 'commission_plan_family_corrected')
            ->where('field_name', 'commission_plan_family_id')
            ->whereNull('from_value')->where('to_value', $attribution->commission_plan_family_id)->get();
        if ($events->count() !== 1 || ! $events->first()->actor_user_id
            || CarbonImmutable::parse($events->first()->effective_at)->getTimestamp() !== $attribution->secured_at->getTimestamp()) {
            return $this->blocked($decision, 'Exactly one actor-owned plan-family correction must be effective at the original secured time.');
        }
        $sourceEvent = $events->first();
        $assignment = SalesCommissionPlanAssignment::query()->whereKey($attribution->commission_plan_assignment_id)
            ->where('company_id', $decision->company_id)
            ->where('plan_family_id', $attribution->commission_plan_family_id)
            ->where('status', 'approved')->whereNotNull('created_by')->whereNotNull('approved_by')->whereNotNull('approved_at')
            ->whereColumn('created_by', '<>', 'approved_by')
            ->where('effective_from', '<=', $attribution->secured_at)
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $attribution->secured_at))
            ->first();
        if (! $assignment || ! SalesCommissionPlanFamily::query()->whereKey($attribution->commission_plan_family_id)
            ->where('company_id', $decision->company_id)->where('status', 'approved')
            ->where('commission_category', $decision->commission_category)->exists()) {
            return $this->blocked($decision, 'The corrected approved plan family or assignment evidence is unavailable.');
        }
        $acquisition = $decision->acquisition_sales_profile_id
            ? SalesProfile::query()->withTrashed()->find($decision->acquisition_sales_profile_id) : null;
        $beneficiaryEvent = $this->collectionProfileEvidenceAt($attribution, $receipt->received_at);
        $beneficiary = $beneficiaryEvent?->to_sales_profile_id
            ? SalesProfile::query()->withTrashed()->with('staff')->find($beneficiaryEvent->to_sales_profile_id) : null;
        if (! $acquisition || $acquisition->id !== $attribution->acquisition_sales_profile_id
            || ! $this->profileEligibility->isEligibleAt($acquisition, ['acquisition'], $attribution->secured_at)) {
            return $this->blocked($decision, 'The frozen acquisition Profile was not eligible at the secured time.');
        }
        if (! $beneficiaryEvent?->actor_user_id || ! $beneficiary?->staff_id
            || $beneficiary->company_id !== $decision->company_id
            || ! $this->profileEligibility->isEligibleAt($beneficiary, ['collection', 'commission'], $receipt->received_at)) {
            return $this->blocked($decision, 'The receipt-time beneficiary lacks actor-owned eligible Staff/Profile evidence.');
        }
        $calculation = $this->formulaReplay->calculate(
            $decision->company_id, $beneficiary->staff_id, $attribution->commission_plan_family_id,
            (float) $decision->eligible_lkr_amount, $receipt->received_at,
        );
        if (isset($calculation['blocker']) || (float) ($calculation['commission_amount_lkr'] ?? 0) <= 0) {
            return $this->blocked($decision, $calculation['blocker'] ?? 'The approved original-time formula did not produce a positive adjustment.');
        }
        $snapshot = [
            'adjustment_kind' => 'late_plan_family_entitlement', 'commission_decision_id' => $decision->id,
            'original_hold_code' => $decision->hold_code, 'decision_version' => $decision->event_version,
            'company_id' => $decision->company_id, 'booking_id' => $decision->booking_id,
            'receipt_id' => $receipt->id, 'receipt_component_id' => $decision->receipt_component_id,
            'receipt_received_at' => $receipt->received_at?->toIso8601String(),
            'receipt_finality_status' => $receipt->finality_status,
            'finality_policy_id' => $policy->id, 'finality_policy_version' => $policy->version,
            'source_attribution_id' => $attribution->id, 'source_attribution_version' => $attribution->version,
            'source_attribution_event_id' => $sourceEvent->id, 'source_attribution_effective_at' => $sourceEvent->effective_at,
            'source_prepared_by' => $sourceEvent->actor_user_id,
            'beneficiary_attribution_event_id' => $beneficiaryEvent->id,
            'beneficiary_attribution_event_version' => $beneficiaryEvent->version,
            'beneficiary_attribution_prepared_by' => $beneficiaryEvent->actor_user_id,
            'acquisition_sales_profile_id' => $acquisition->id,
            'beneficiary_sales_profile_id' => $beneficiary->id, 'beneficiary_staff_id' => $beneficiary->staff_id,
            'commission_category' => $decision->commission_category, 'collection_cohort' => $decision->collection_cohort,
            'eligible_source_amount' => (string) $decision->eligible_source_amount, 'source_currency' => $decision->source_currency,
            'fx_rate_to_lkr' => (string) $decision->fx_rate_to_lkr, 'fx_rate_at' => $decision->fx_rate_at?->toIso8601String(),
            'fx_source' => $decision->fx_source, 'eligible_lkr_amount' => (string) $decision->eligible_lkr_amount,
            'plan_family_id' => $attribution->commission_plan_family_id,
            'plan_assignment_id' => $attribution->commission_plan_assignment_id, 'calculation' => $calculation,
        ];
        return [
            'adjustment_allowed' => true, 'blocker' => null,
            'decision' => $decision->only(['id', 'status', 'hold_code', 'event_version']),
            'source' => ['attribution_id' => $attribution->id, 'attribution_event_id' => $sourceEvent->id,
                'source_prepared_by' => $sourceEvent->actor_user_id,
                'prohibited_approver_ids' => array_values(array_unique(array_filter([
                    $sourceEvent->actor_user_id, $beneficiaryEvent->actor_user_id,
                    $assignment->created_by, $assignment->approved_by, $policy->created_by, $policy->approved_by,
                ])))],
            'beneficiary' => ['sales_profile_id' => $beneficiary->id, 'staff_id' => $beneficiary->staff_id],
            'calculation' => $calculation, 'frozen_adjustment_snapshot' => $snapshot,
            'calculation_checksum' => $this->checksum($snapshot), 'write_performed' => false,
        ];
    }

    private function previewLateBeneficiaryCorrection(SalesCommissionDecision $decision): array
    {
        $receipt = BookingPaymentReceipt::query()->find($decision->receipt_id);
        if (! $receipt || $receipt->company_id !== $decision->company_id
            || $receipt->finality_status !== 'confirmed' || $decision->receipt_finality_status !== 'confirmed') {
            return $this->blocked($decision, 'The original receipt does not have confirmed finality evidence.');
        }

        $policies = BookingPaymentFinalityPolicy::query()
            ->where('company_id', $decision->company_id)
            ->where('payment_method', strtolower((string) $receipt->payment_method))
            ->where('status', 'approved')->whereNotNull('approved_by')->whereNotNull('approved_at')
            ->where('effective_from', '<=', $receipt->received_at)
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $receipt->received_at))
            ->get();
        if ($policies->count() !== 1 || $policies->first()->id !== $decision->finality_policy_id) {
            return $this->blocked($decision,
                'Exactly one approved payment-finality policy must match the original decision and receipt timestamp.');
        }
        $policy = $policies->first();
        if ($policy->created_by === $policy->approved_by) {
            return $this->blocked($decision, 'The finality policy lacks maker-checker approval evidence.');
        }

        $attribution = $decision->booking_attribution_id
            ? SalesBookingAttribution::query()->find($decision->booking_attribution_id) : null;
        if (! $attribution || $attribution->booking_id !== $decision->booking_id
            || $attribution->company_id !== $decision->company_id
            || $attribution->secured_at->gt($receipt->received_at)
            || $attribution->commission_plan_family_id !== $decision->plan_family_id
            || $attribution->commission_plan_assignment_id !== $decision->plan_assignment_id
            || $attribution->commission_category !== $decision->commission_category
            || ! $decision->plan_family_id || ! $decision->plan_assignment_id) {
            return $this->blocked($decision,
                'The attribution must retain the original legal entity, booking, secured time, and frozen plan assignment.');
        }

        $acquisitionProfile = $decision->acquisition_sales_profile_id
            ? SalesProfile::query()->withTrashed()->find($decision->acquisition_sales_profile_id) : null;
        if (! $acquisitionProfile || $acquisitionProfile->id !== $attribution->acquisition_sales_profile_id
            || $acquisitionProfile->company_id !== $decision->company_id
            || ! $this->profileEligibility->isEligibleAt($acquisitionProfile, ['acquisition'], $attribution->secured_at)) {
            return $this->blocked($decision, 'The frozen acquisition Profile was not eligible at the secured time.');
        }

        $winningEvent = $this->collectionProfileEvidenceAt($attribution, $receipt->received_at);
        if (! $winningEvent || $winningEvent->event_type !== 'collection_handler_corrected'
            || $winningEvent->field_name !== 'collection_sales_profile_id'
            || ! $winningEvent->actor_user_id || ! $winningEvent->to_sales_profile_id) {
            return $this->blocked($decision,
                'Exactly one immutable, actor-owned collection-handler correction must now govern this receipt timestamp.');
        }
        $beneficiary = SalesProfile::query()->withTrashed()->with('staff')->find($winningEvent->to_sales_profile_id);
        if (! $beneficiary?->staff_id || $beneficiary->company_id !== $decision->company_id
            || ! $this->profileEligibility->isEligibleAt($beneficiary, ['collection', 'commission'], $receipt->received_at)) {
            return $this->blocked($decision,
                'The corrected beneficiary Staff/Profile was not collection- and commission-eligible at the receipt time.');
        }

        if (! SalesCommissionPlanFamily::query()->whereKey($decision->plan_family_id)
            ->where('company_id', $decision->company_id)->where('status', 'approved')->exists()
            || ! SalesCommissionPlanAssignment::query()->whereKey($decision->plan_assignment_id)
                ->where('company_id', $decision->company_id)->where('plan_family_id', $decision->plan_family_id)
                ->where('status', 'approved')->where('effective_from', '<=', $attribution->secured_at)
                ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $attribution->secured_at))
                ->exists()) {
            return $this->blocked($decision, 'The original approved plan family or assignment evidence is unavailable.');
        }
        $calculation = $this->formulaReplay->calculate(
            $decision->company_id,
            $beneficiary->staff_id,
            $decision->plan_family_id,
            (float) $decision->eligible_lkr_amount,
            $receipt->received_at,
        );
        if (isset($calculation['blocker']) || (float) ($calculation['commission_amount_lkr'] ?? 0) <= 0) {
            return $this->blocked($decision,
                $calculation['blocker'] ?? 'The approved original-time formula did not produce a positive adjustment.');
        }

        $snapshot = [
            'adjustment_kind' => 'late_beneficiary_correction_entitlement',
            'commission_decision_id' => $decision->id, 'original_hold_code' => $decision->hold_code,
            'decision_version' => $decision->event_version, 'company_id' => $decision->company_id,
            'booking_id' => $decision->booking_id, 'receipt_id' => $receipt->id,
            'receipt_component_id' => $decision->receipt_component_id,
            'receipt_received_at' => $receipt->received_at?->toIso8601String(),
            'receipt_finality_status' => $receipt->finality_status,
            'finality_policy_id' => $policy->id, 'finality_policy_version' => $policy->version,
            'source_attribution_id' => $attribution->id,
            'source_attribution_version' => $attribution->version,
            'source_attribution_event_id' => $winningEvent->id,
            'source_attribution_effective_at' => $winningEvent->effective_at,
            'source_prepared_by' => $winningEvent->actor_user_id,
            'acquisition_sales_profile_id' => $acquisitionProfile->id,
            'beneficiary_sales_profile_id' => $beneficiary->id,
            'beneficiary_staff_id' => $beneficiary->staff_id,
            'commission_category' => $decision->commission_category,
            'collection_cohort' => $decision->collection_cohort,
            'eligible_source_amount' => (string) $decision->eligible_source_amount,
            'source_currency' => $decision->source_currency,
            'fx_rate_to_lkr' => (string) $decision->fx_rate_to_lkr,
            'fx_rate_at' => $decision->fx_rate_at?->toIso8601String(),
            'fx_source' => $decision->fx_source,
            'eligible_lkr_amount' => (string) $decision->eligible_lkr_amount,
            'plan_family_id' => $decision->plan_family_id,
            'plan_assignment_id' => $decision->plan_assignment_id,
            'calculation' => $calculation,
        ];

        return [
            'adjustment_allowed' => true, 'blocker' => null,
            'decision' => $decision->only(['id', 'status', 'hold_code', 'event_version']),
            'source' => [
                'attribution_id' => $attribution->id, 'attribution_event_id' => $winningEvent->id,
                'source_prepared_by' => $winningEvent->actor_user_id,
                'prohibited_approver_ids' => array_values(array_unique(array_filter([
                    $winningEvent->actor_user_id, $policy->created_by, $policy->approved_by,
                ]))),
            ],
            'beneficiary' => ['sales_profile_id' => $beneficiary->id, 'staff_id' => $beneficiary->staff_id],
            'calculation' => $calculation, 'frozen_adjustment_snapshot' => $snapshot,
            'calculation_checksum' => $this->checksum($snapshot), 'write_performed' => false,
        ];
    }

    public function append(
        string $decisionId,
        int $expectedVersion,
        string $previewChecksum,
        string $reason,
        string $idempotencyKey,
        string $actorUserId,
    ): SalesCommissionHoldAdjustment {
        $requestChecksum = $this->checksum(['decision_id' => $decisionId, 'expected_version' => $expectedVersion,
            'preview_checksum' => $previewChecksum, 'reason' => trim($reason), 'actor_user_id' => $actorUserId]);
        $receiptId = SalesCommissionDecision::query()->whereKey($decisionId)->value('receipt_id');
        abort_unless($receiptId, 404, 'Commission decision not found.');

        return DB::transaction(function () use ($decisionId, $receiptId, $expectedVersion, $previewChecksum, $reason, $idempotencyKey, $actorUserId, $requestChecksum) {
            $duplicate = SalesCommissionHoldAdjustment::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($duplicate) {
                abort_unless(hash_equals($duplicate->request_payload_checksum, $requestChecksum), 409,
                    'The hold-adjustment idempotency key was reused with different evidence.');
                return $duplicate;
            }
            BookingPaymentReceipt::query()->whereKey($receiptId)->lockForUpdate()->firstOrFail();
            $decision = SalesCommissionDecision::query()->lockForUpdate()->findOrFail($decisionId);
            abort_unless($decision->event_version === $expectedVersion, 409, 'The commission decision version changed; refresh the adjustment preview.');
            abort_if(SalesCommissionHoldAdjustment::query()->where('commission_decision_id', $decision->id)->exists(), 409,
                'This commission hold already has an immutable linked adjustment.');
            $preview = $this->preview($decision);
            abort_unless($preview['adjustment_allowed'], 422, $preview['blocker'] ?? 'This hold cannot create a linked adjustment.');
            abort_unless(hash_equals($preview['calculation_checksum'], $previewChecksum), 409,
                'The canonical attribution or formula evidence changed; refresh the adjustment preview.');
            abort_if(in_array($actorUserId, $preview['source']['prohibited_approver_ids'] ?? [], true), 403,
                'A source preparer or approver cannot approve the linked commission adjustment.');
            abort_if($preview['beneficiary']['staff_id'] === DB::table('staff')->where('user_id', $actorUserId)->value('id'), 403,
                'The beneficiary cannot approve their own linked commission adjustment.');

            $snapshot = $preview['frozen_adjustment_snapshot'];
            $calculation = $preview['calculation'];
            BookingPaymentFinalityPolicy::query()->whereKey($snapshot['finality_policy_id'])->lockForUpdate()->firstOrFail();
            if (isset($snapshot['receipt_finality_event_id'])) {
                BookingPaymentReceiptFinalityEvent::query()->whereKey($snapshot['receipt_finality_event_id'])
                    ->lockForUpdate()->firstOrFail();
            }
            SalesCommissionPlanFamily::query()->whereKey($snapshot['plan_family_id'])->lockForUpdate()->firstOrFail();
            SalesCommissionPlanAssignment::query()->whereKey($snapshot['plan_assignment_id'])->lockForUpdate()->firstOrFail();
            if (isset($calculation['plan_version_id'])) {
                SalesCommissionPlanVersion::query()->whereKey($calculation['plan_version_id'])->lockForUpdate()->firstOrFail();
            }
            if (isset($calculation['plan_tier_id'])) {
                SalesCommissionPlanTier::query()->whereKey($calculation['plan_tier_id'])->lockForUpdate()->firstOrFail();
            }
            if (isset($calculation['staff_override_id'])) {
                SalesCommissionStaffOverride::query()->whereKey($calculation['staff_override_id'])->lockForUpdate()->firstOrFail();
            }
            SalesBookingAttribution::query()->whereKey($snapshot['source_attribution_id'])->lockForUpdate()->firstOrFail();
            SalesProfile::query()->whereIn('id', [$snapshot['acquisition_sales_profile_id'], $snapshot['beneficiary_sales_profile_id']])
                ->orderBy('id')->lockForUpdate()->get();
            DB::table('sales_booking_attribution_events')->where('id', $snapshot['source_attribution_event_id'])->lockForUpdate()->firstOrFail();
            if (isset($snapshot['beneficiary_attribution_event_id'])) {
                DB::table('sales_booking_attribution_events')
                    ->where('id', $snapshot['beneficiary_attribution_event_id'])->lockForUpdate()->firstOrFail();
            }
            $lockedPreview = $this->preview($decision);
            abort_unless($lockedPreview['adjustment_allowed']
                && hash_equals($lockedPreview['calculation_checksum'], $previewChecksum), 409,
                'The locked attribution or formula evidence changed; refresh the adjustment preview.');
            $preview = $lockedPreview;
            $snapshot = $preview['frozen_adjustment_snapshot'];
            $calculation = $preview['calculation'];

            $adjustment = SalesCommissionHoldAdjustment::create([
                'company_id' => $decision->company_id,
                'commission_decision_id' => $decision->id,
                'source_attribution_id' => $snapshot['source_attribution_id'],
                'source_attribution_event_id' => $snapshot['source_attribution_event_id'],
                'beneficiary_attribution_event_id' => $snapshot['beneficiary_attribution_event_id'] ?? null,
                'finality_policy_id' => $snapshot['finality_policy_id'],
                'receipt_finality_event_id' => $snapshot['receipt_finality_event_id'] ?? null,
                'adjustment_kind' => $snapshot['adjustment_kind'],
                'original_hold_code' => $decision->hold_code,
                'beneficiary_sales_profile_id' => $snapshot['beneficiary_sales_profile_id'],
                'beneficiary_staff_id' => $snapshot['beneficiary_staff_id'],
                'plan_family_id' => $snapshot['plan_family_id'],
                'plan_assignment_id' => $snapshot['plan_assignment_id'],
                'plan_version_id' => $calculation['plan_version_id'] ?? null,
                'plan_tier_id' => $calculation['plan_tier_id'] ?? null,
                'staff_override_id' => $calculation['staff_override_id'] ?? null,
                'formula_kind' => $calculation['formula_kind'],
                'applied_rate' => $calculation['applied_rate'] ?? null,
                'fixed_amount_lkr' => $calculation['fixed_amount_lkr'] ?? null,
                'eligible_lkr_amount' => $decision->eligible_lkr_amount,
                'commission_adjustment_lkr' => $calculation['commission_amount_lkr'],
                'frozen_adjustment_snapshot' => $snapshot,
                'calculation_checksum' => $previewChecksum,
                'reason' => trim($reason),
                'source_prepared_by' => $preview['source']['source_prepared_by'],
                'approved_by' => $actorUserId,
                'adjustment_effective_at' => now(),
                'idempotency_key' => $idempotencyKey,
                'request_payload_checksum' => $requestChecksum,
            ]);
            $this->events->record('sales', $adjustment->company_id, 'commission_hold_adjustment', $adjustment->id,
                'sales.commission.hold_adjusted', 1, 1, [
                    'commission_decision_id' => $decision->id,
                    'source_attribution_event_id' => $adjustment->source_attribution_event_id,
                    'beneficiary_attribution_event_id' => $adjustment->beneficiary_attribution_event_id,
                    'finality_policy_id' => $adjustment->finality_policy_id,
                    'receipt_finality_event_id' => $adjustment->receipt_finality_event_id,
                    'original_hold_code' => $adjustment->original_hold_code,
                    'commission_adjustment_lkr' => (string) $adjustment->commission_adjustment_lkr,
                    'calculation_checksum' => $adjustment->calculation_checksum,
                ], $adjustment->adjustment_effective_at, $idempotencyKey, $decision->id);
            $this->metricFacts->projectCommissionHoldAdjustment($decision, $adjustment);
            $this->recoveries->openForExistingCashDecreases($decision);

            return $adjustment;
        }, 3);
    }

    private function blocked(SalesCommissionDecision $decision, string $blocker): array
    {
        return ['adjustment_allowed' => false, 'blocker' => $blocker,
            'decision' => $decision->only(['id', 'status', 'hold_code', 'event_version']), 'write_performed' => false];
    }

    private function collectionProfileEvidenceAt(SalesBookingAttribution $attribution, $at): ?object
    {
        $latest = DB::table('sales_booking_attribution_events')
            ->where('attribution_id', $attribution->id)
            ->where('field_name', 'collection_sales_profile_id')
            ->where('effective_at', '<=', $at)
            ->orderByDesc('effective_at')->orderByDesc('version')->first();
        if ($latest) {
            return $latest;
        }

        return DB::table('sales_booking_attribution_events')
            ->where('attribution_id', $attribution->id)
            ->where('event_type', 'confirmed')->where('effective_at', '<=', $at)
            ->orderBy('effective_at')->orderBy('version')->first();
    }

    private function cohort($securedAt, $receivedAt): string
    {
        return $securedAt && $receivedAt && $securedAt->format('Y-m') === $receivedAt->format('Y-m')
            ? 'current_period_secured' : 'prior_period_secured';
    }

    private function checksum(array $facts): string
    {
        return hash('sha256', CanonicalJson::encode($facts));
    }
}
