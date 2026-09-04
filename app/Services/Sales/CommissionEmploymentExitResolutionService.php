<?php

namespace App\Services\Sales;

use App\Contracts\Foundation\DomainEventPublisher;
use App\Models\Booking\BookingPaymentReceipt;
use App\Models\Sales\SalesCommissionDecision;
use App\Models\Sales\SalesCommissionHoldResolution;
use App\Models\Staff;
use App\Support\Foundation\CanonicalJson;
use Illuminate\Support\Facades\DB;

class CommissionEmploymentExitResolutionService
{
    public function __construct(private readonly DomainEventPublisher $events) {}

    public function resolve(SalesCommissionDecision $decision): ?SalesCommissionHoldResolution
    {
        if ($decision->hold_code !== 'employment_inactive'
            || ! in_array($decision->status, ['held', 'shadow_held'], true)) {
            return null;
        }

        return DB::transaction(function () use ($decision) {
            $decision = SalesCommissionDecision::query()->lockForUpdate()->findOrFail($decision->id);
            $existing = SalesCommissionHoldResolution::query()
                ->where('commission_decision_id', $decision->id)->first();
            if ($existing) return $existing;
            if ($decision->hold_code !== 'employment_inactive'
                || ! in_array($decision->status, ['held', 'shadow_held'], true)
                || ! $decision->company_id || ! $decision->beneficiary_staff_id) {
                return null;
            }
            if ($decision->holdRelease()->exists() || $decision->holdAdjustment()->exists()) {
                return null;
            }

            $receipt = BookingPaymentReceipt::query()->lockForUpdate()->find($decision->receipt_id);
            $staff = Staff::query()->withTrashed()->lockForUpdate()->find($decision->beneficiary_staff_id);
            if (! $receipt || ! $staff || $receipt->company_id !== $decision->company_id
                || $staff->company_id !== $decision->company_id || ! $staff->employment_ended_at
                || ! $staff->terminated_by || $staff->employment_ended_at->gt($receipt->received_at)) {
                return null;
            }

            $snapshot = [
                'commission_decision_id' => $decision->id,
                'commission_decision_version' => $decision->event_version,
                'original_hold_code' => $decision->hold_code,
                'company_id' => $decision->company_id,
                'booking_id' => $decision->booking_id,
                'receipt_id' => $receipt->id,
                'receipt_component_id' => $decision->receipt_component_id,
                'receipt_received_at' => $receipt->received_at?->toIso8601String(),
                'beneficiary_sales_profile_id' => $decision->beneficiary_sales_profile_id,
                'beneficiary_staff_id' => $staff->id,
                'employment_ended_at' => $staff->employment_ended_at->toIso8601String(),
                'employment_terminated_by' => $staff->terminated_by,
                'commission_entitlement_lkr' => '0.0000',
                'creates_metric_fact' => false,
                'creates_statement_line' => false,
                'creates_payout' => false,
                'creates_recovery' => false,
            ];
            $resolutionChecksum = hash('sha256', CanonicalJson::encode($snapshot));
            $idempotencyKey = 'commission-employment-exit-resolution:'.$decision->id;
            $requestChecksum = hash('sha256', CanonicalJson::encode([
                'commission_decision_id' => $decision->id,
                'employment_staff_id' => $staff->id,
                'employment_ended_at' => $snapshot['employment_ended_at'],
                'resolution_checksum' => $resolutionChecksum,
            ]));

            $resolution = SalesCommissionHoldResolution::create([
                'company_id' => $decision->company_id,
                'commission_decision_id' => $decision->id,
                'receipt_finality_event_id' => null,
                'employment_staff_id' => $staff->id,
                'employment_ended_at' => $staff->employment_ended_at,
                'employment_terminated_by' => $staff->terminated_by,
                'resolution_kind' => 'employment_exit_no_entitlement',
                'original_hold_code' => $decision->hold_code,
                'frozen_resolution_snapshot' => $snapshot,
                'resolution_checksum' => $resolutionChecksum,
                'resolution_reason' => 'The frozen collection beneficiary employment ended before this receipt.',
                'resolved_by' => $staff->terminated_by,
                'resolved_at' => $decision->decision_at,
                'idempotency_key' => $idempotencyKey,
                'request_payload_checksum' => $requestChecksum,
            ]);

            $this->events->record('sales', $resolution->company_id, 'commission_hold_resolution', $resolution->id,
                'sales.commission.hold_resolved_without_entitlement', 1, 1, [
                    'commission_decision_id' => $decision->id,
                    'employment_staff_id' => $staff->id,
                    'employment_ended_at' => $snapshot['employment_ended_at'],
                    'original_hold_code' => $decision->hold_code,
                    'resolution_kind' => $resolution->resolution_kind,
                    'resolution_checksum' => $resolution->resolution_checksum,
                ], $resolution->resolved_at, $resolution->idempotency_key, $decision->id);

            return $resolution;
        }, 3);
    }
}
