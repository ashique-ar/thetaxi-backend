<?php

namespace App\Services\Sales;

use App\Contracts\Foundation\DomainEventPublisher;
use App\Models\Booking\Booking;
use App\Models\Booking\BookingPaymentAdjustment;
use App\Models\Booking\BookingPaymentReceiptComponent;
use App\Models\Sales\SalesCommissionDecision;
use App\Models\Sales\SalesCommissionRecoveryCase;
use App\Models\Sales\SalesBookingAttribution;
use App\Services\BookingPaymentLedgerService;
use App\Support\Foundation\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BookingPaymentAdjustmentService
{
    public function __construct(
        private readonly DomainEventPublisher $events,
        private readonly BookingPaymentLedgerService $ledger,
        private readonly CommissionRecoveryService $commissionRecoveries,
        private readonly SalesMetricFactService $metricFacts,
        private readonly SalesPolicySettingsService $policySettings,
    )
    {
    }

    public function record(Booking $booking, array $data, string $actorUserId): BookingPaymentAdjustment
    {
        return DB::transaction(function () use ($booking, $data, $actorUserId) {
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            $payloadChecksum = $this->payloadChecksum($booking->id, $data);
            $duplicate = BookingPaymentAdjustment::query()
                ->where('booking_id', $booking->id)
                ->where('idempotency_key', $data['idempotency_key'])
                ->first();
            if ($duplicate) {
                if (!hash_equals((string) $duplicate->request_payload_checksum, $payloadChecksum)) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => ['This adjustment key was already used with different facts.'],
                    ]);
                }

                $recovery = SalesCommissionRecoveryCase::query()->where('payment_adjustment_id', $duplicate->id)->first();
                if ($recovery)
                    $duplicate->setRelation('commission_recovery_case', $recovery);
                return $duplicate;
            }
            $component = !empty($data['receipt_component_id'])
                ? BookingPaymentReceiptComponent::query()->whereKey($data['receipt_component_id'])
                    ->whereHas('receipt', fn($q) => $q->where('booking_id', $booking->id))
                    ->lockForUpdate()->firstOrFail()
                : null;

            if ($component) {
                $component->load('receipt');
            }

            $earning = $this->validateDimensions($component, $data);
            if ($data['impact_dimension'] === 'reporting_fx') {
                $data = $this->freezeFxEvidence($component, $earning, $data, true);
                $data['booking_id'] = $booking->id;
                $preview = $this->fxPreview($earning, $data);
                if (!hash_equals($preview['preview_checksum'], (string) ($data['preview_checksum'] ?? ''))) {
                    throw ValidationException::withMessages(['preview_checksum' => ['The FX correction preview is missing or stale.']]);
                }
            }
            $data['source_currency'] = strtoupper((string) $data['source_currency']);
            if (isset($data['fx_source']))
                $data['fx_source'] = trim((string) $data['fx_source']);
            if (isset($data['fx_quote_base']))
                $data['fx_quote_base'] = trim((string) $data['fx_quote_base']);
            $adjustment = BookingPaymentAdjustment::create([
                ...$data,
                'booking_id' => $booking->id,
                'receipt_id' => $component?->receipt_id,
                'company_id' => $component?->receipt?->company_id,
                'approved_by' => $actorUserId,
                'approved_at' => now(),
                'created_user_id' => $actorUserId,
                'request_payload_checksum' => $payloadChecksum,
            ]);

            if ($component && $data['impact_dimension'] === 'cash_receipt') {
                $amount = round((float) $data['source_amount'], 4);
                $delta = $data['direction'] === 'decrease' ? $amount : -$amount;
                $component->update([
                    'adjusted_source_amount' => round((float) $component->adjusted_source_amount + $delta, 4),
                ]);
                $legacyDelta = $data['direction'] === 'decrease' ? $amount : -$amount;
                $component->receipt->update([
                    'refunded_amount' => max(0, round((float) $component->receipt->refunded_amount + $legacyDelta, 2)),
                ]);

                $summary = $this->ledger->summary($booking->fresh());
                $booking->update([
                    'payment_status' => $summary['payment_status'],
                    'payment_collection_status' => $summary['due_amount'] <= 0 ? 'paid' : ($summary['paid_amount'] > 0 ? 'partially_paid' : 'pending'),
                    'payment_collected_amount' => $summary['paid_amount'],
                    'amount_to_pay' => $summary['due_amount'],
                    'settled_at' => $summary['due_amount'] <= 0 ? ($booking->settled_at ?? now()) : null,
                ]);
            }

            $this->events->record(
                'sales',
                $adjustment->company_id,
                'booking_payment_adjustment',
                $adjustment->id,
                'sales.payment.adjusted',
                1,
                1,
                [
                    'booking_id' => $booking->id,
                    'receipt_id' => $adjustment->receipt_id,
                    'receipt_component_id' => $adjustment->receipt_component_id,
                    'impact_dimension' => $adjustment->impact_dimension,
                    'adjustment_type' => $adjustment->adjustment_type,
                    'direction' => $adjustment->direction,
                    'source_amount' => (string) $adjustment->source_amount,
                    'source_currency' => $adjustment->source_currency,
                    'original_lkr_amount' => (string) $adjustment->original_lkr_amount,
                    'corrected_lkr_amount' => (string) $adjustment->lkr_amount,
                    'lkr_delta' => (string) $adjustment->lkr_delta,
                    'fx_rate_to_lkr' => (string) $adjustment->fx_rate_to_lkr,
                    'fx_rate_at' => $adjustment->fx_rate_at?->toISOString(),
                    'fx_source' => $adjustment->fx_source,
                    'fx_quote_base' => $adjustment->fx_quote_base,
                    'fx_calculation_mode' => $adjustment->fx_calculation_mode,
                    'commission_decision_id' => $adjustment->commission_decision_id,
                    'corrects_adjustment_id' => $adjustment->corrects_adjustment_id,
                    'lineage_root_adjustment_id' => $adjustment->lineage_root_adjustment_id,
                    'correction_sequence' => $adjustment->correction_sequence,
                    'prior_corrected_lkr_amount' => (string) $adjustment->prior_corrected_lkr_amount,
                    'cumulative_lkr_delta' => (string) $adjustment->cumulative_lkr_delta,
                    'adjustment_effective_at' => $adjustment->adjustment_effective_at->toISOString(),
                ],
                $adjustment->adjustment_effective_at,
                $adjustment->idempotency_key,
            );
            $recovery = $data['impact_dimension'] === 'reporting_fx'
                ? $this->commissionRecoveries->openForFxCorrection($adjustment, $earning)
                : $this->commissionRecoveries->openForCashDecrease($adjustment);
            if ($recovery) {
                $adjustment->setRelation('commission_recovery_case', $recovery);
            }
            $this->metricFacts->projectCollectionAdjustment($adjustment);

            return $adjustment;
        }, 3);
    }

    public function previewReportingFx(Booking $booking, array $data): array
    {
        $component = BookingPaymentReceiptComponent::query()->whereKey($data['receipt_component_id'])
            ->whereHas('receipt', fn($q) => $q->where('booking_id', $booking->id))->firstOrFail();
        $component->load('receipt');
        $earning = $this->validateDimensions($component, $data);
        $facts = $this->freezeFxEvidence($component, $earning, $data, false);
        $facts['booking_id'] = $booking->id;
        return $this->fxPreview($earning, $facts);
    }

    /**
     * Establishes the FX/LKR snapshot for a receipt component whose evidence was never captured
     * (the fx_snapshot_missing commission hold), as opposed to previewReportingFx()/record()'s
     * 'reporting_fx' dimension, which only corrects a component that already has complete evidence
     * and is tied to an existing earned decision — neither precondition holds here. This write is
     * purely evidentiary: it never mutates the original component/receipt row, and the resulting
     * commission entitlement is created only through CommissionHoldAdjustmentService's own
     * checksum-bound, maker-checker-separated preview/append gate.
     */
    public function previewFxSnapshotEstablishment(Booking $booking, array $data): array
    {
        $component = BookingPaymentReceiptComponent::query()->whereKey($data['receipt_component_id'])
            ->whereHas('receipt', fn($q) => $q->where('booking_id', $booking->id))->firstOrFail();
        $component->load('receipt');
        $facts = $this->validateFxEstablishment($component, $data, $booking);

        return $this->fxEstablishmentPreview($facts);
    }

    public function establishFxSnapshot(Booking $booking, array $data, string $actorUserId): BookingPaymentAdjustment
    {
        return DB::transaction(function () use ($booking, $data, $actorUserId) {
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            $payloadChecksum = $this->payloadChecksum($booking->id, $data);
            $duplicate = BookingPaymentAdjustment::query()
                ->where('booking_id', $booking->id)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($duplicate) {
                if (!hash_equals((string) $duplicate->request_payload_checksum, $payloadChecksum)) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => ['This establishment key was already used with different facts.'],
                    ]);
                }

                return $duplicate;
            }

            $component = BookingPaymentReceiptComponent::query()->whereKey($data['receipt_component_id'])
                ->whereHas('receipt', fn($q) => $q->where('booking_id', $booking->id))
                ->lockForUpdate()->firstOrFail();
            $component->load('receipt');
            $facts = $this->validateFxEstablishment($component, $data, $booking);
            $preview = $this->fxEstablishmentPreview($facts);
            if (!hash_equals($preview['preview_checksum'], (string) ($data['preview_checksum'] ?? ''))) {
                throw ValidationException::withMessages(['preview_checksum' => ['The FX establishment preview is missing or stale.']]);
            }
            if (
                BookingPaymentAdjustment::query()->where('receipt_component_id', $component->id)
                    ->where('impact_dimension', 'fx_establishment')->lockForUpdate()->exists()
            ) {
                throw ValidationException::withMessages(['receipt_component_id' => ['This receipt component already has an immutable established FX snapshot.']]);
            }

            $adjustment = BookingPaymentAdjustment::create([
                'booking_id' => $booking->id,
                'receipt_id' => $component->receipt_id,
                'receipt_component_id' => $component->id,
                'company_id' => $component->receipt->company_id,
                'impact_dimension' => 'fx_establishment',
                'adjustment_type' => 'fx_establishment',
                'direction' => 'establish',
                'source_amount' => $facts['source_amount'],
                'source_currency' => $facts['source_currency'],
                'lkr_amount' => $facts['lkr_amount'],
                'fx_rate_to_lkr' => $facts['fx_rate_to_lkr'],
                'fx_rate_at' => $facts['fx_rate_at'],
                'fx_source' => $facts['fx_source'],
                'fx_quote_base' => $facts['fx_quote_base'],
                'fx_calculation_mode' => $facts['fx_calculation_mode'],
                'adjustment_effective_at' => $facts['adjustment_effective_at'],
                'reason' => $facts['reason'],
                'reference' => $facts['reference'],
                'idempotency_key' => $data['idempotency_key'],
                'preview_checksum' => $preview['preview_checksum'],
                'request_payload_checksum' => $payloadChecksum,
                'approved_by' => $actorUserId,
                'approved_at' => now(),
                'created_user_id' => $actorUserId,
            ]);

            $this->events->record(
                'sales',
                $adjustment->company_id,
                'booking_payment_adjustment',
                $adjustment->id,
                'sales.payment.fx_established',
                1,
                1,
                [
                    'booking_id' => $booking->id,
                    'receipt_id' => $adjustment->receipt_id,
                    'receipt_component_id' => $adjustment->receipt_component_id,
                    'source_amount' => (string) $adjustment->source_amount,
                    'source_currency' => $adjustment->source_currency,
                    'lkr_amount' => (string) $adjustment->lkr_amount,
                    'fx_rate_to_lkr' => (string) $adjustment->fx_rate_to_lkr,
                    'fx_rate_at' => $adjustment->fx_rate_at?->toISOString(),
                    'fx_source' => $adjustment->fx_source,
                ],
                $adjustment->adjustment_effective_at,
                $adjustment->idempotency_key,
            );

            return $adjustment;
        }, 3);
    }

    private function validateFxEstablishment(BookingPaymentReceiptComponent $component, array $data, Booking $booking): array
    {
        if (!$this->policySettings->featureEnabled((string) $component->receipt->company_id, 'fx_corrections')) {
            throw ValidationException::withMessages(['impact_dimension' => ['FX establishment is disabled until Finance approves the operating policy.']]);
        }
        $policy = $this->policySettings->fxCorrectionsPolicy((string) $component->receipt->company_id);
        if (
            !$policy['approved_quote_base'] || !in_array(
                $policy['calculation_mode'] ?? null,
                ['multiply_source_by_rate', 'divide_source_by_rate'],
                true
            )
            || $policy['max_rate_age_hours'] === null || $policy['rounding_scale'] === null
        ) {
            throw ValidationException::withMessages(['impact_dimension' => ['Reporting-FX correction policy is incomplete.']]);
        }
        if (($data['fx_quote_base'] ?? null) !== $policy['approved_quote_base']) {
            throw ValidationException::withMessages(['fx_quote_base' => ['The quote/base convention is not the Finance-approved convention.']]);
        }
        if (($data['fx_calculation_mode'] ?? null) !== $policy['calculation_mode']) {
            throw ValidationException::withMessages(['fx_calculation_mode' => ['The conversion operation is not the Finance-approved operation.']]);
        }
        if (!$component->receipt || $component->receipt->booking_id !== $booking->id) {
            throw ValidationException::withMessages(['receipt_component_id' => ['The receipt component does not belong to this booking.']]);
        }
        if ($component->receipt->finality_status !== 'confirmed') {
            throw ValidationException::withMessages(['receipt_component_id' => ['Only a confirmed receipt component can establish a missing FX snapshot.']]);
        }
        if (!$component->receipt->company_id) {
            throw ValidationException::withMessages(['receipt_component_id' => ['The original receipt has no canonical legal-entity identity.']]);
        }
        $attributionCompanyId = SalesBookingAttribution::query()->where('booking_id', $component->receipt->booking_id)->value('company_id');
        if (!$attributionCompanyId || $attributionCompanyId !== $component->receipt->company_id) {
            throw ValidationException::withMessages(['receipt_component_id' => ['Receipt and booking attribution legal entities do not match.']]);
        }
        $hasOpenFxHold = SalesCommissionDecision::query()->where('receipt_component_id', $component->id)
            ->whereIn('status', ['held', 'shadow_held'])->where('hold_code', 'fx_snapshot_missing')->exists();
        if (!$hasOpenFxHold) {
            throw ValidationException::withMessages(['receipt_component_id' => ['This receipt component has no open fx_snapshot_missing commission hold to establish evidence for.']]);
        }
        if ($component->lkr_amount !== null || $component->receipt->fx_rate_to_lkr !== null) {
            throw ValidationException::withMessages(['receipt_component_id' => ['The component already has FX/LKR evidence; use the reporting-FX correction command instead.']]);
        }
        if (
            BookingPaymentAdjustment::query()->where('receipt_component_id', $component->id)
                ->where('impact_dimension', 'fx_establishment')->exists()
        ) {
            throw ValidationException::withMessages(['receipt_component_id' => ['This receipt component already has an immutable established FX snapshot.']]);
        }
        if (strtoupper((string) $data['source_currency']) !== strtoupper((string) $component->receipt->source_currency)) {
            throw ValidationException::withMessages(['source_currency' => ['The establishment must retain the original receipt currency.']]);
        }
        $sourceBasis = max(0, round((float) $component->source_amount - (float) $component->adjusted_source_amount, 4));
        if ($sourceBasis <= 0 || round((float) $data['source_amount'], 4) !== $sourceBasis) {
            throw ValidationException::withMessages(['source_amount' => ['The established source amount must equal the exact current unreversed component balance.']]);
        }
        $rateAt = CarbonImmutable::parse($data['fx_rate_at']);
        $effectiveAt = CarbonImmutable::parse($component->receipt->received_at);
        if ($rateAt->greaterThan($effectiveAt) || $rateAt->diffInHours($effectiveAt) > (int) $policy['max_rate_age_hours']) {
            throw ValidationException::withMessages(['fx_rate_at' => ['The established rate is future-dated relative to the original receipt or older than the approved maximum age.']]);
        }
        $scale = (int) $policy['rounding_scale'];
        $suppliedLkr = (float) $data['lkr_amount'];
        $expectedLkr = round($policy['calculation_mode'] === 'multiply_source_by_rate'
            ? $sourceBasis * (float) $data['fx_rate_to_lkr']
            : $sourceBasis / (float) $data['fx_rate_to_lkr'], $scale);
        if (abs($suppliedLkr - round($suppliedLkr, $scale)) > 0.00005 || abs($expectedLkr - $suppliedLkr) > 0.00005) {
            throw ValidationException::withMessages(['lkr_amount' => ['The established LKR amount does not reproduce from the approved quote/base and rounding policy.']]);
        }

        return [
            'booking_id' => $booking->id,
            'receipt_component_id' => $component->id,
            'source_amount' => $sourceBasis,
            'source_currency' => strtoupper((string) $data['source_currency']),
            'lkr_amount' => round($suppliedLkr, 4),
            'fx_rate_to_lkr' => (float) $data['fx_rate_to_lkr'],
            'fx_rate_at' => $rateAt,
            'fx_source' => trim((string) $data['fx_source']),
            'fx_quote_base' => trim((string) $data['fx_quote_base']),
            'fx_calculation_mode' => $data['fx_calculation_mode'],
            'adjustment_effective_at' => $effectiveAt,
            'reason' => trim((string) $data['reason']),
            'reference' => isset($data['reference']) ? trim((string) $data['reference']) : null,
        ];
    }

    private function fxEstablishmentPreview(array $facts): array
    {
        $previewFacts = [
            'booking_id' => $facts['booking_id'],
            'receipt_component_id' => $facts['receipt_component_id'],
            'source_amount' => number_format((float) $facts['source_amount'], 4, '.', ''),
            'source_currency' => $facts['source_currency'],
            'lkr_amount' => number_format((float) $facts['lkr_amount'], 4, '.', ''),
            'fx_rate_to_lkr' => number_format((float) $facts['fx_rate_to_lkr'], 10, '.', ''),
            'fx_rate_at' => $facts['fx_rate_at']->toIso8601String(),
            'fx_source' => $facts['fx_source'],
            'fx_quote_base' => $facts['fx_quote_base'],
            'fx_calculation_mode' => $facts['fx_calculation_mode'],
            'adjustment_effective_at' => $facts['adjustment_effective_at']->toIso8601String(),
            'reference' => $facts['reference'],
        ];

        return [
            'establishment_allowed' => true,
            'frozen_facts' => $facts,
            'preview_facts' => $previewFacts,
            'preview_checksum' => hash('sha256', CanonicalJson::encode($previewFacts)),
            'write_performed' => false,
        ];
    }

    private function validateDimensions(?BookingPaymentReceiptComponent $component, array $data): ?SalesCommissionDecision
    {
        $dimension = $data['impact_dimension'];
        if ($dimension === 'cash_receipt' && !$component) {
            throw ValidationException::withMessages(['receipt_component_id' => ['Cash/receipt adjustments must identify the original receipt component.']]);
        }
        if ($dimension === 'reporting_fx') {
            if ($data['adjustment_type'] !== 'fx_correction' || !$component) {
                throw ValidationException::withMessages(['receipt_component_id' => ['Reporting-FX corrections must identify an original receipt component.']]);
            }
            if (!$this->policySettings->featureEnabled((string) $component->receipt->company_id, 'fx_corrections')) {
                throw ValidationException::withMessages(['impact_dimension' => ['Reporting-FX corrections are disabled until Finance approves the operating policy.']]);
            }
            $policy = $this->policySettings->fxCorrectionsPolicy((string) $component->receipt->company_id);
            if (
                !$policy['approved_quote_base'] || !in_array(
                    $policy['calculation_mode'] ?? null,
                    ['multiply_source_by_rate', 'divide_source_by_rate'],
                    true
                )
                || $policy['max_rate_age_hours'] === null || $policy['rounding_scale'] === null
            ) {
                throw ValidationException::withMessages(['impact_dimension' => ['Reporting-FX correction policy is incomplete.']]);
            }
            if (($data['fx_quote_base'] ?? null) !== $policy['approved_quote_base']) {
                throw ValidationException::withMessages(['fx_quote_base' => ['The quote/base convention is not the Finance-approved convention.']]);
            }
            if (($data['fx_calculation_mode'] ?? null) !== $policy['calculation_mode']) {
                throw ValidationException::withMessages(['fx_calculation_mode' => ['The conversion operation is not the Finance-approved operation.']]);
            }
            if ($component->receipt->finality_status !== 'confirmed') {
                throw ValidationException::withMessages(['receipt_component_id' => ['Only a confirmed receipt component can receive a reporting-FX correction.']]);
            }
            if (!$component->receipt->company_id) {
                throw ValidationException::withMessages(['receipt_component_id' => ['The original receipt has no canonical legal-entity identity.']]);
            }
            $attributionCompanyId = SalesBookingAttribution::query()->where('booking_id', $component->receipt->booking_id)->value('company_id');
            if (!$attributionCompanyId || $attributionCompanyId !== $component->receipt->company_id) {
                throw ValidationException::withMessages(['receipt_component_id' => ['Receipt and booking attribution legal entities do not match.']]);
            }
            if (
                $component->receipt->fx_rate_to_lkr === null || $component->receipt->fx_rate_at === null
                || blank($component->receipt->fx_source) || $component->lkr_amount === null
            ) {
                throw ValidationException::withMessages(['receipt_component_id' => ['The original component does not have complete immutable FX evidence.']]);
            }
            if (strtoupper((string) $data['source_currency']) !== strtoupper((string) $component->receipt->source_currency)) {
                throw ValidationException::withMessages(['source_currency' => ['The correction must retain the original source currency.']]);
            }
            $available = max(0, round((float) $component->source_amount - (float) $component->adjusted_source_amount, 4));
            if ((float) $data['source_amount'] > $available) {
                throw ValidationException::withMessages(['source_amount' => ['The correction exceeds the unreversed receipt-component source balance.']]);
            }
            $rateAt = CarbonImmutable::parse($data['fx_rate_at']);
            $effectiveAt = CarbonImmutable::parse($data['adjustment_effective_at']);
            if (
                DB::table('domain_period_locks')->where('domain', 'sales')->where('company_id', $component->receipt->company_id)
                    ->where('state', 'locked')->where('period_start', '<=', $effectiveAt)->where('period_end', '>', $effectiveAt)->exists()
            ) {
                throw ValidationException::withMessages(['adjustment_effective_at' => ['The Sales period is locked; use the governed period-reopen and new-snapshot workflow first.']]);
            }
            if ($rateAt->greaterThan($effectiveAt) || $rateAt->diffInHours($effectiveAt) > (int) $policy['max_rate_age_hours']) {
                throw ValidationException::withMessages(['fx_rate_at' => ['The replacement rate is future-dated or older than the approved maximum age.']]);
            }
            $scale = (int) $policy['rounding_scale'];
            $suppliedLkr = (float) $data['lkr_amount'];
            $expectedLkr = round($policy['calculation_mode'] === 'multiply_source_by_rate'
                ? (float) $data['source_amount'] * (float) $data['fx_rate_to_lkr']
                : (float) $data['source_amount'] / (float) $data['fx_rate_to_lkr'], $scale);
            if (abs($suppliedLkr - round($suppliedLkr, $scale)) > 0.00005 || abs($expectedLkr - $suppliedLkr) > 0.00005) {
                throw ValidationException::withMessages(['lkr_amount' => ['The corrected LKR amount does not reproduce from the approved quote/base and rounding policy.']]);
            }

            if (!$component->is_commission_eligible) {
                return null;
            }
            $earnings = SalesCommissionDecision::query()->where('receipt_component_id', $component->id)
                ->whereIn('status', ['earned', 'shadow_earned'])->get();
            if ($earnings->count() !== 1) {
                throw ValidationException::withMessages(['receipt_component_id' => ['A commission-eligible FX correction requires exactly one immutable original earning.']]);
            }
            return $earnings->first();
        }
        if ($component && $dimension === 'cash_receipt') {
            if ($component->receipt->finality_status !== 'confirmed') {
                throw ValidationException::withMessages(['receipt_component_id' => ['Only a confirmed collection can receive a cash adjustment.']]);
            }
            if (strtoupper((string) $data['source_currency']) !== strtoupper((string) $component->receipt->source_currency)) {
                throw ValidationException::withMessages(['source_currency' => ['Cash adjustments must use the original receipt currency.']]);
            }
            $limit = $data['direction'] === 'decrease'
                ? max(0, round((float) $component->source_amount - (float) $component->adjusted_source_amount, 4))
                : max(0, round((float) $component->adjusted_source_amount, 4));
            if ((float) $data['source_amount'] > $limit) {
                $message = $data['direction'] === 'decrease'
                    ? 'The adjustment exceeds the unreversed receipt-component balance.'
                    : 'The counter-adjustment exceeds the previously reversed receipt-component balance.';
                throw ValidationException::withMessages(['source_amount' => [$message]]);
            }
        }
        return null;
    }

    private function freezeFxEvidence(
        BookingPaymentReceiptComponent $component,
        ?SalesCommissionDecision $earning,
        array $data,
        bool $lockLineage,
    ): array
    {
        if ((float) $component->source_amount <= 0) {
            throw ValidationException::withMessages(['receipt_component_id' => ['The original component has no positive source basis.']]);
        }
        $ratio = (float) $data['source_amount'] / (float) $component->source_amount;
        $originalLkr = round((float) $component->lkr_amount * $ratio, 4);
        $lineage = $this->resolveFxLineage($component, $earning, $data, $lockLineage);
        $prior = $lineage['prior_adjustment'];
        $baselineLkr = $prior ? (float) $prior->lkr_amount : $originalLkr;
        $delta = round((float) $data['lkr_amount'] - $baselineLkr, 4);
        if ($delta === 0.0 || ($delta > 0 ? 'increase' : 'decrease') !== $data['direction']) {
            throw ValidationException::withMessages(['direction' => ['Direction must match the non-zero corrected-minus-prior-effective LKR delta.']]);
        }

        return $data + [
            'original_lkr_amount' => $originalLkr,
            'original_fx_rate_to_lkr' => $component->receipt->fx_rate_to_lkr,
            'original_fx_rate_at' => $component->receipt->fx_rate_at,
            'original_fx_source' => $component->receipt->fx_source,
            'lkr_delta' => $delta,
            'cumulative_lkr_delta' => round((float) $data['lkr_amount'] - $originalLkr, 4),
            'commission_decision_id' => $earning?->id,
            'corrects_adjustment_id' => $prior?->id,
            'lineage_root_adjustment_id' => $prior ? ($prior->lineage_root_adjustment_id ?: $prior->id) : null,
            'correction_sequence' => $prior ? ((int) $prior->correction_sequence + 1) : 1,
            'prior_corrected_lkr_amount' => $prior?->lkr_amount,
            'prior_fx_rate_to_lkr' => $prior?->fx_rate_to_lkr,
            'prior_fx_rate_at' => $prior?->fx_rate_at,
            'prior_fx_source' => $prior?->fx_source,
            'prior_recovery_case_id' => $lineage['prior_recovery_case']?->id,
            'prior_recalculated_commission_amount_lkr' => $lineage['prior_recovery_case']?->recalculated_commission_amount_lkr,
        ];
    }

    private function resolveFxLineage(
        BookingPaymentReceiptComponent $component,
        ?SalesCommissionDecision $earning,
        array $data,
        bool $lock,
    ): array
    {
        $query = BookingPaymentAdjustment::query()->where('receipt_component_id', $component->id)
            ->where('impact_dimension', 'reporting_fx')->orderBy('correction_sequence')->orderBy('created_at');
        if ($lock)
            $query->lockForUpdate();
        $corrections = $query->get();
        $requestedPriorId = $data['corrects_adjustment_id'] ?? null;
        if ($corrections->isEmpty()) {
            if ($requestedPriorId) {
                throw ValidationException::withMessages(['corrects_adjustment_id' => ['The referenced FX correction does not belong to this receipt component.']]);
            }
            return ['prior_adjustment' => null, 'prior_recovery_case' => null];
        }

        $prior = $corrections->last();
        if (!$requestedPriorId || $prior->id !== $requestedPriorId) {
            throw ValidationException::withMessages(['corrects_adjustment_id' => ['Reference the current FX correction leaf; stale or branching correction lineages are not allowed.']]);
        }
        if ((float) $data['source_amount'] !== (float) $prior->source_amount) {
            throw ValidationException::withMessages(['source_amount' => ['A counter-adjustment must retain the affected source amount frozen by the prior correction.']]);
        }
        if (CarbonImmutable::parse($data['adjustment_effective_at'])->lessThan($prior->adjustment_effective_at)) {
            throw ValidationException::withMessages(['adjustment_effective_at' => ['A counter-adjustment cannot take effect before the correction it supersedes.']]);
        }

        $recovery = null;
        if ($earning) {
            $recoveryQuery = SalesCommissionRecoveryCase::query()->where('payment_adjustment_id', $prior->id);
            if ($lock)
                $recoveryQuery->lockForUpdate();
            $recovery = $recoveryQuery->first();
            if (
                !$recovery || $recovery->commission_decision_id !== $earning->id
                || $recovery->status === 'pending_review' || $recovery->recalculated_commission_amount_lkr === null
            ) {
                throw ValidationException::withMessages(['corrects_adjustment_id' => ['Resolve and freeze the prior linked commission correction before creating its successor.']]);
            }
        }

        return ['prior_adjustment' => $prior, 'prior_recovery_case' => $recovery];
    }

    private function fxPreview(?SalesCommissionDecision $earning, array $facts): array
    {
        $commission = $earning ? $this->commissionRecoveries->previewFxCalculation(
            $earning,
            (float) $facts['source_amount'],
            (float) $facts['lkr_amount'],
            (float) $facts['original_lkr_amount'],
            isset($facts['prior_recalculated_commission_amount_lkr'])
            ? (float) $facts['prior_recalculated_commission_amount_lkr'] : null,
        ) : [
            'commission_decision_id' => null,
            'calculation_status' => 'not_commission_eligible',
            'calculation_explanation' => 'The original receipt component is not commission eligible.',
            'proposed_commission_adjustment_lkr' => 0.0,
        ];
        unset($commission['preview_checksum']);
        $previewFacts = [
            'booking_id' => $facts['booking_id'] ?? null,
            'receipt_component_id' => $facts['receipt_component_id'],
            'source_amount' => number_format((float) $facts['source_amount'], 4, '.', ''),
            'source_currency' => strtoupper((string) $facts['source_currency']),
            'original_lkr_amount' => number_format((float) $facts['original_lkr_amount'], 4, '.', ''),
            'corrected_lkr_amount' => number_format((float) $facts['lkr_amount'], 4, '.', ''),
            'lkr_delta' => number_format((float) $facts['lkr_delta'], 4, '.', ''),
            'fx_rate_to_lkr' => number_format((float) $facts['fx_rate_to_lkr'], 10, '.', ''),
            'fx_rate_at' => (string) $facts['fx_rate_at'],
            'fx_source' => trim((string) $facts['fx_source']),
            'fx_quote_base' => trim((string) $facts['fx_quote_base']),
            'fx_calculation_mode' => $facts['fx_calculation_mode'],
            'adjustment_effective_at' => (string) $facts['adjustment_effective_at'],
            'reference' => trim((string) $facts['reference']),
            'commission' => $commission,
            'corrects_adjustment_id' => $facts['corrects_adjustment_id'],
            'lineage_root_adjustment_id' => $facts['lineage_root_adjustment_id'],
            'correction_sequence' => $facts['correction_sequence'],
            'prior_corrected_lkr_amount' => $facts['prior_corrected_lkr_amount'] !== null
                ? number_format((float) $facts['prior_corrected_lkr_amount'], 4, '.', '') : null,
            'cumulative_lkr_delta' => number_format((float) $facts['cumulative_lkr_delta'], 4, '.', ''),
        ];
        $checksum = hash('sha256', CanonicalJson::encode($previewFacts));
        return $previewFacts + ['preview_checksum' => $checksum];
    }

    private function payloadChecksum(string $bookingId, array $data): string
    {
        $facts = [
            'booking_id' => $bookingId,
            'receipt_component_id' => $data['receipt_component_id'] ?? null,
            'impact_dimension' => $data['impact_dimension'],
            'adjustment_type' => $data['adjustment_type'],
            'direction' => $data['direction'],
            'source_amount' => number_format((float) $data['source_amount'], 4, '.', ''),
            'source_currency' => strtoupper((string) $data['source_currency']),
            'lkr_amount' => isset($data['lkr_amount']) ? number_format((float) $data['lkr_amount'], 4, '.', '') : null,
            'fx_rate_to_lkr' => isset($data['fx_rate_to_lkr']) ? number_format((float) $data['fx_rate_to_lkr'], 10, '.', '') : null,
            'fx_rate_at' => $data['fx_rate_at'] ?? null,
            'fx_source' => trim((string) ($data['fx_source'] ?? '')),
            'fx_quote_base' => $data['fx_quote_base'] ?? null,
            'fx_calculation_mode' => $data['fx_calculation_mode'] ?? null,
            'adjustment_effective_at' => (string) $data['adjustment_effective_at'],
            'reason' => trim((string) $data['reason']),
            'reference' => $data['reference'] ?? null,
            'preview_checksum' => $data['preview_checksum'] ?? null,
            'corrects_adjustment_id' => $data['corrects_adjustment_id'] ?? null,
        ];

        return hash('sha256', json_encode($facts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
