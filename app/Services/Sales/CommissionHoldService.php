<?php

namespace App\Services\Sales;

use App\Contracts\Foundation\DomainEventPublisher;
use App\Models\Booking\BookingPaymentReceipt;
use App\Models\Booking\BookingPaymentReceiptFinalityEvent;
use App\Models\Staff;
use App\Models\Sales\SalesCommissionDecision;
use App\Models\Sales\SalesCommissionHoldRelease;
use App\Models\Sales\SalesCommissionHoldResolution;
use App\Models\Sales\SalesCommissionPlanFamily;
use App\Models\Sales\SalesCommissionPlanVersion;
use Illuminate\Support\Facades\DB;

class CommissionHoldService
{
    public function __construct(
        private readonly DomainEventPublisher $events,
        private readonly SalesMetricFactService $metricFacts,
        private readonly CommissionFormulaReplayService $formulaReplay,
        private readonly CommissionRecoveryService $recoveries,
    ) {}

    public function preview(SalesCommissionDecision $decision): array
    {
        $blocker = $this->structuralBlocker($decision);
        if ($blocker) return ['release_allowed' => false, 'blocker' => $blocker, 'decision' => $decision->only(['id', 'status', 'hold_code']), 'write_performed' => false];

        $receipt = BookingPaymentReceipt::query()->find($decision->receipt_id);
        if (! $receipt) return $this->blocked($decision, 'The frozen payment receipt is unavailable.');
        if ($decision->receipt_finality_status !== 'confirmed' || $receipt->finality_status !== 'confirmed') {
            return $this->blocked($decision, 'The payment receipt is not confirmed final and cannot create a payable release.');
        }

        $calculation = $this->formulaReplay->calculate(
            $decision->company_id,
            $decision->beneficiary_staff_id,
            $decision->plan_family_id,
            (float) $decision->eligible_lkr_amount,
            $receipt->received_at,
        );
        if (isset($calculation['blocker'])) return $this->blocked($decision, $calculation['blocker']);

        $snapshot = $this->snapshot($decision, $receipt, $calculation);

        return [
            'release_allowed' => true,
            'blocker' => null,
            'decision' => $decision->only(['id', 'company_id', 'booking_id', 'receipt_id', 'beneficiary_sales_profile_id', 'beneficiary_staff_id', 'hold_code']),
            'calculation' => $calculation,
            'frozen_calculation_snapshot' => $snapshot,
            'calculation_checksum' => $this->checksum($snapshot),
            'write_performed' => false,
        ];
    }

    public function release(string $decisionId, int $expectedVersion, string $reason, string $idempotencyKey, string $actorUserId): SalesCommissionHoldRelease
    {
        $requestChecksum = $this->checksum(['decision_id' => $decisionId, 'expected_version' => $expectedVersion,
            'reason' => $reason, 'actor_user_id' => $actorUserId]);
        return DB::transaction(function () use ($decisionId, $expectedVersion, $reason, $idempotencyKey, $actorUserId, $requestChecksum) {
            $byKey = SalesCommissionHoldRelease::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($byKey) {
                abort_unless(hash_equals($byKey->request_payload_checksum, $requestChecksum), 409,
                    'The commission hold release idempotency key was reused with different evidence.');
                return $byKey;
            }

            $decision = SalesCommissionDecision::query()->lockForUpdate()->findOrFail($decisionId);
            abort_unless($decision->event_version === $expectedVersion, 409, 'The commission decision version changed; refresh the release preview.');
            $existing = SalesCommissionHoldRelease::query()->where('commission_decision_id', $decision->id)->first();
            abort_if($existing, 409, 'This commission hold already has an immutable release.');
            $beneficiaryStaff = $decision->beneficiary_staff_id
                ? Staff::query()->whereKey($decision->beneficiary_staff_id)->lockForUpdate()->first()
                : null;
            abort_if($beneficiaryStaff?->user_id === $actorUserId, 403, 'A beneficiary cannot release their own commission hold.');
            if ($decision->plan_family_id) SalesCommissionPlanFamily::query()->whereKey($decision->plan_family_id)->lockForUpdate()->first();
            $preview = $this->preview($decision);
            abort_unless($preview['release_allowed'], 422, $preview['blocker'] ?? 'This commission hold cannot be released.');
            $calculation = $preview['calculation'];

            $release = SalesCommissionHoldRelease::create([
                'company_id' => $decision->company_id,
                'commission_decision_id' => $decision->id,
                'release_kind' => 'manual_formula',
                'receipt_finality_event_id' => null,
                'beneficiary_sales_profile_id' => $decision->beneficiary_sales_profile_id,
                'beneficiary_staff_id' => $decision->beneficiary_staff_id,
                'original_hold_code' => $decision->hold_code,
                'plan_version_id' => $calculation['plan_version_id'] ?? null,
                'plan_tier_id' => $calculation['plan_tier_id'] ?? null,
                'staff_override_id' => $calculation['staff_override_id'] ?? null,
                'formula_kind' => $calculation['formula_kind'],
                'applied_rate' => $calculation['applied_rate'] ?? null,
                'fixed_amount_lkr' => $calculation['fixed_amount_lkr'] ?? null,
                'commission_amount_lkr' => $calculation['commission_amount_lkr'],
                'calculation_explanation' => $calculation['calculation_explanation'],
                'frozen_calculation_snapshot' => $preview['frozen_calculation_snapshot'],
                'calculation_checksum' => $preview['calculation_checksum'],
                'release_reason' => $reason,
                'released_by' => $actorUserId,
                'released_at' => now(),
                'idempotency_key' => $idempotencyKey,
                'request_payload_checksum' => $requestChecksum,
            ]);

            $this->events->record('sales', $release->company_id, 'commission_hold_release', $release->id,
                'sales.commission.hold_released', 1, 1, [
                    'commission_decision_id' => $decision->id, 'original_hold_code' => $decision->hold_code,
                    'commission_amount_lkr' => (string) $release->commission_amount_lkr,
                    'calculation_checksum' => $release->calculation_checksum,
                ], $release->released_at, $release->idempotency_key, $decision->id);
            $this->metricFacts->projectCommissionHoldRelease($decision, $release);
            $this->recoveries->openForExistingCashDecreases($decision);

            return $release;
        }, 3);
    }

    public function releaseForFinality(
        SalesCommissionDecision $decision,
        BookingPaymentReceiptFinalityEvent $finalityEvent,
    ): ?SalesCommissionHoldRelease {
        if (! config('sales.features.commission_accrual', false) || $finalityEvent->to_status !== 'confirmed') {
            return null;
        }

        return DB::transaction(function () use ($decision, $finalityEvent) {
            $decision = SalesCommissionDecision::query()->lockForUpdate()->findOrFail($decision->id);
            $existing = SalesCommissionHoldRelease::query()->where('commission_decision_id', $decision->id)->first();
            if ($existing) return $existing;
            if ($decision->status !== 'held' || $decision->hold_code !== 'cash_clearance_pending'
                || ! $decision->finality_policy_id || ! $decision->hold_payout_until_final_snapshot) {
                return null;
            }

            $receipt = BookingPaymentReceipt::query()->lockForUpdate()->findOrFail($decision->receipt_id);
            abort_unless($receipt->finality_status === 'confirmed'
                && $finalityEvent->booking_payment_receipt_id === $receipt->id
                && $finalityEvent->finality_policy_id === $decision->finality_policy_id, 409,
                'The commission hold release is not bound to this confirmed finality event.');
            abort_unless($decision->company_id && $decision->beneficiary_sales_profile_id && $decision->beneficiary_staff_id, 422,
                'The frozen legal entity and beneficiary evidence is incomplete.');

            $calculation = $this->frozenCalculation($decision);
            abort_if(isset($calculation['blocker']), 422, $calculation['blocker']);
            $snapshot = $this->snapshot($decision, $receipt, $calculation) + [
                'release_kind' => 'finality_confirmation',
                'receipt_finality_event_id' => $finalityEvent->id,
                'confirmed_finality_at' => $finalityEvent->occurred_at?->toIso8601String(),
                'finality_evidence_reference' => $finalityEvent->evidence_reference,
            ];
            $calculationChecksum = $this->checksum($snapshot);
            $idempotencyKey = 'commission-finality-release:'.$finalityEvent->id.':'.$decision->id;
            $requestChecksum = $this->checksum(['decision_id' => $decision->id,
                'receipt_finality_event_id' => $finalityEvent->id, 'calculation_checksum' => $calculationChecksum]);

            $release = SalesCommissionHoldRelease::create([
                'company_id' => $decision->company_id,
                'commission_decision_id' => $decision->id,
                'release_kind' => 'finality_confirmation',
                'receipt_finality_event_id' => $finalityEvent->id,
                'beneficiary_sales_profile_id' => $decision->beneficiary_sales_profile_id,
                'beneficiary_staff_id' => $decision->beneficiary_staff_id,
                'original_hold_code' => $decision->hold_code,
                'plan_version_id' => $calculation['plan_version_id'] ?? null,
                'plan_tier_id' => $calculation['plan_tier_id'] ?? null,
                'staff_override_id' => $calculation['staff_override_id'] ?? null,
                'formula_kind' => $calculation['formula_kind'],
                'applied_rate' => $calculation['applied_rate'] ?? null,
                'fixed_amount_lkr' => $calculation['fixed_amount_lkr'] ?? null,
                'commission_amount_lkr' => $calculation['commission_amount_lkr'],
                'calculation_explanation' => $calculation['calculation_explanation'],
                'frozen_calculation_snapshot' => $snapshot,
                'calculation_checksum' => $calculationChecksum,
                'release_reason' => 'Receipt finality confirmed: '.$finalityEvent->reason,
                'released_by' => $finalityEvent->performed_by,
                'released_at' => $finalityEvent->occurred_at,
                'idempotency_key' => $idempotencyKey,
                'request_payload_checksum' => $requestChecksum,
            ]);

            $this->events->record('sales', $release->company_id, 'commission_hold_release', $release->id,
                'sales.commission.hold_released', 1, 1, [
                    'commission_decision_id' => $decision->id, 'release_kind' => $release->release_kind,
                    'receipt_finality_event_id' => $finalityEvent->id, 'original_hold_code' => $decision->hold_code,
                    'commission_amount_lkr' => (string) $release->commission_amount_lkr,
                    'calculation_checksum' => $release->calculation_checksum,
                ], $release->released_at, $release->idempotency_key, $finalityEvent->id);
            $this->metricFacts->projectCommissionHoldRelease($decision, $release);
            $this->recoveries->openForExistingCashDecreases($decision);

            return $release;
        }, 3);
    }

    public function resolveForFailedFinality(
        SalesCommissionDecision $decision,
        BookingPaymentReceiptFinalityEvent $finalityEvent,
    ): ?SalesCommissionHoldResolution {
        if ((! config('sales.features.commission_shadow', false)
                && ! config('sales.features.commission_accrual', false))
            || $finalityEvent->to_status !== 'failed') {
            return null;
        }

        return DB::transaction(function () use ($decision, $finalityEvent) {
            $decision = SalesCommissionDecision::query()->lockForUpdate()->findOrFail($decision->id);
            $existing = SalesCommissionHoldResolution::query()
                ->where('commission_decision_id', $decision->id)->first();
            if ($existing) return $existing;
            if (! in_array($decision->status, ['held', 'shadow_held'], true)) return null;

            abort_if($decision->holdRelease()->exists() || $decision->holdAdjustment()->exists(), 409,
                'A payable release or entitlement adjustment already exists for this immutable commission decision.');
            $receipt = BookingPaymentReceipt::query()->lockForUpdate()->findOrFail($decision->receipt_id);
            abort_unless($receipt->finality_status === 'failed'
                && $receipt->company_id !== null
                && $finalityEvent->booking_payment_receipt_id === $receipt->id
                && $finalityEvent->company_id === $receipt->company_id
                && $decision->receipt_component_id !== null
                && ($decision->company_id === null || $decision->company_id === $receipt->company_id), 409,
                'The commission hold resolution is not bound to this failed receipt-finality event.');

            $snapshot = [
                'commission_decision_id' => $decision->id,
                'commission_decision_version' => $decision->event_version,
                'original_hold_code' => $decision->hold_code,
                'receipt_id' => $receipt->id,
                'receipt_component_id' => $decision->receipt_component_id,
                'receipt_initial_finality_status' => $receipt->initial_finality_status,
                'receipt_finality_status' => $receipt->finality_status,
                'receipt_finality_event_id' => $finalityEvent->id,
                'finality_from_status' => $finalityEvent->from_status,
                'finality_to_status' => $finalityEvent->to_status,
                'finality_evidence_reference' => $finalityEvent->evidence_reference,
                'commission_entitlement_lkr' => '0.0000',
                'creates_metric_fact' => false,
                'creates_statement_line' => false,
                'creates_payout' => false,
            ];
            $resolutionChecksum = $this->checksum($snapshot);
            $idempotencyKey = 'commission-failed-finality-resolution:'.$finalityEvent->id.':'.$decision->id;
            $requestChecksum = $this->checksum([
                'commission_decision_id' => $decision->id,
                'receipt_finality_event_id' => $finalityEvent->id,
                'resolution_checksum' => $resolutionChecksum,
            ]);

            $resolution = SalesCommissionHoldResolution::create([
                'company_id' => $receipt->company_id,
                'commission_decision_id' => $decision->id,
                'receipt_finality_event_id' => $finalityEvent->id,
                'resolution_kind' => 'failed_finality_no_entitlement',
                'original_hold_code' => $decision->hold_code,
                'frozen_resolution_snapshot' => $snapshot,
                'resolution_checksum' => $resolutionChecksum,
                'resolution_reason' => 'Receipt finality failed: '.$finalityEvent->reason,
                'resolved_by' => $finalityEvent->performed_by,
                'resolved_at' => $finalityEvent->occurred_at,
                'idempotency_key' => $idempotencyKey,
                'request_payload_checksum' => $requestChecksum,
            ]);

            $this->events->record('sales', $resolution->company_id, 'commission_hold_resolution', $resolution->id,
                'sales.commission.hold_resolved_without_entitlement', 1, 1, [
                    'commission_decision_id' => $decision->id,
                    'receipt_finality_event_id' => $finalityEvent->id,
                    'original_hold_code' => $decision->hold_code,
                    'resolution_kind' => $resolution->resolution_kind,
                    'resolution_checksum' => $resolution->resolution_checksum,
                ], $resolution->resolved_at, $resolution->idempotency_key, $finalityEvent->id);

            return $resolution;
        }, 3);
    }

    private function structuralBlocker(SalesCommissionDecision $decision): ?string
    {
        if ($decision->status === 'shadow_held' || ! config('sales.features.commission_accrual', false)) return 'Commission accrual is disabled; shadow holds cannot create payable releases.';
        if ($decision->status !== 'held') return 'Only an immutable held commission decision can be reviewed.';
        if (! CommissionHoldRemediationService::isFormulaReplayable($decision->hold_code)) return 'This hold requires governed attribution, identity, eligibility, finality, or FX correction and cannot be released here.';
        if (! $decision->company_id || ! $decision->beneficiary_sales_profile_id || ! $decision->beneficiary_staff_id) return 'The frozen legal entity and beneficiary evidence is incomplete.';
        if (! $decision->plan_family_id || $decision->eligible_lkr_amount === null) return 'The frozen plan family or eligible LKR basis is incomplete.';
        if (SalesCommissionHoldRelease::query()->where('commission_decision_id', $decision->id)->exists()) return 'This hold already has an immutable release.';
        return null;
    }

    private function frozenCalculation(SalesCommissionDecision $decision): array
    {
        $basis = (float) $decision->eligible_lkr_amount;
        if ($decision->staff_override_id && $decision->formula_kind === 'percentage' && $decision->applied_rate !== null) {
            $rate = (float) $decision->applied_rate;
            return ['staff_override_id' => $decision->staff_override_id, 'formula_kind' => 'percentage',
                'applied_rate' => $rate, 'commission_amount_lkr' => $decision->commission_amount_lkr !== null
                    ? (float) $decision->commission_amount_lkr : $this->roundSnapshot($basis * $rate / 100, $decision),
                'calculation_explanation' => "Frozen approved Staff override {$rate}% released against eligible LKR receipt {$basis}."];
        }
        if (! $decision->plan_version_id) return ['blocker' => 'The held decision has no frozen approved plan version.'];
        $version = SalesCommissionPlanVersion::query()->find($decision->plan_version_id);
        if (! $version || $version->eligible_basis !== 'full_eligible_receipt_lkr') return ['blocker' => 'The frozen approved plan evidence is unavailable or unsupported.'];
        if ($decision->rounding_mode_snapshot === null || $decision->rounding_scale_snapshot === null) {
            return ['blocker' => 'The held decision has no frozen rounding policy.'];
        }
        $base = [
            'plan_version_id' => $version->id, 'formula_kind' => $decision->formula_kind,
            'rounding_mode' => $decision->rounding_mode_snapshot,
            'rounding_scale' => $decision->rounding_scale_snapshot,
        ];
        if ($decision->formula_kind === 'percentage' && $decision->applied_rate !== null) {
            $rate = (float) $decision->applied_rate;
            return $base + ['applied_rate' => $rate, 'commission_amount_lkr' => $decision->commission_amount_lkr !== null
                ? (float) $decision->commission_amount_lkr : $this->roundSnapshot($basis * $rate / 100, $decision),
                'calculation_explanation' => "Frozen approved percentage {$rate}% released against eligible LKR receipt {$basis}."];
        }
        if ($decision->formula_kind === 'fixed' && $decision->fixed_amount_lkr !== null) {
            $fixed = (float) $decision->fixed_amount_lkr;
            return $base + ['fixed_amount_lkr' => $fixed, 'commission_amount_lkr' => $decision->commission_amount_lkr !== null
                ? (float) $decision->commission_amount_lkr : $this->roundSnapshot($fixed, $decision),
                'calculation_explanation' => "Frozen approved fixed commission LKR {$fixed} released once for this receipt."];
        }
        if ($decision->formula_kind === 'tiered_percentage' && $decision->plan_tier_id && $decision->applied_rate !== null) {
            $tier = SalesCommissionPlanTier::query()->whereKey($decision->plan_tier_id)
                ->where('plan_version_id', $version->id)->first();
            if (! $tier) return ['blocker' => 'The frozen approved tier evidence is unavailable.'];
            $rate = (float) $decision->applied_rate;
            return $base + ['plan_tier_id' => $tier->id, 'applied_rate' => $rate,
                'commission_amount_lkr' => $decision->commission_amount_lkr !== null
                    ? (float) $decision->commission_amount_lkr : $this->roundSnapshot($basis * $rate / 100, $decision),
                'calculation_explanation' => "Frozen approved whole-payment tier {$rate}% released against eligible LKR receipt {$basis}."];
        }
        return ['blocker' => 'The held decision does not contain a reproducible frozen formula.'];
    }

    private function snapshot(SalesCommissionDecision $decision, BookingPaymentReceipt $receipt, array $calculation): array
    {
        return ['commission_decision_id' => $decision->id, 'original_hold_code' => $decision->hold_code,
            'commission_decision_version' => $decision->event_version,
            'receipt_id' => $receipt->id, 'receipt_received_at' => $receipt->received_at?->toIso8601String(),
            'receipt_finality_status' => $decision->receipt_finality_status, 'company_id' => $decision->company_id,
            'beneficiary_sales_profile_id' => $decision->beneficiary_sales_profile_id,
            'beneficiary_staff_id' => $decision->beneficiary_staff_id, 'plan_family_id' => $decision->plan_family_id,
            'finality_policy_id' => $decision->finality_policy_id,
            'can_earn_before_final' => $decision->can_earn_before_final_snapshot,
            'hold_payout_until_final' => $decision->hold_payout_until_final_snapshot,
            'rounding_mode' => $decision->rounding_mode_snapshot,
            'rounding_scale' => $decision->rounding_scale_snapshot,
            'eligible_lkr_amount' => (string) $decision->eligible_lkr_amount, 'calculation' => $calculation];
    }

    private function blocked(SalesCommissionDecision $decision, string $message): array
    { return ['release_allowed' => false, 'blocker' => $message, 'decision' => $decision->only(['id', 'status', 'hold_code']), 'write_performed' => false]; }

    private function roundSnapshot(float $amount, SalesCommissionDecision $decision): float
    {
        $mode = match ($decision->rounding_mode_snapshot) {
            'half_down' => PHP_ROUND_HALF_DOWN, 'half_even' => PHP_ROUND_HALF_EVEN,
            'half_odd' => PHP_ROUND_HALF_ODD, default => PHP_ROUND_HALF_UP,
        };
        return round($amount, (int) $decision->rounding_scale_snapshot, $mode);
    }

    private function checksum(array $facts): string
    { return hash('sha256', json_encode($facts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); }
}
