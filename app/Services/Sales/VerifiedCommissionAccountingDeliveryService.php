<?php

namespace App\Services\Sales;

use App\Contracts\Foundation\DomainEventPublisher;
use App\Models\Sales\SalesCommissionAccountingDelivery;
use App\Models\Sales\SalesCommissionPayout;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class VerifiedCommissionAccountingDeliveryService
{
    public function __construct(
        private readonly DomainEventPublisher $events,
        private readonly CommissionAccountingOutboxReconciliationService $reconciliation,
    ) {}

    public function recordQuickBooksAcceptance(
        SalesCommissionPayout $payout,
        string $sourceEventId,
        string $providerTransactionId,
        string $providerResponseChecksum,
        string $idempotencyKey,
    ): SalesCommissionAccountingDelivery {
        abort_unless(preg_match('/^[a-f0-9]{64}$/i', $providerResponseChecksum) === 1, 422,
            'A SHA-256 checksum of the QuickBooks response is required.');
        abort_unless(trim($providerTransactionId) !== '' && strlen($providerTransactionId) <= 160, 422,
            'A QuickBooks transaction identifier is required.');
        abort_unless(trim($idempotencyKey) !== '' && strlen($idempotencyKey) <= 160, 422,
            'An idempotency key is required.');

        return DB::transaction(function () use ($payout, $sourceEventId, $providerTransactionId, $providerResponseChecksum, $idempotencyKey) {
            $payout = SalesCommissionPayout::query()->lockForUpdate()->findOrFail($payout->id);
            $eventType = $payout->reverses_payout_id ? 'payout_reversal' : 'payout';
            $facts = [
                'payout_id' => (string) $payout->id,
                'source_event_id' => $sourceEventId,
                'provider_transaction_id' => $providerTransactionId,
                'provider_response_checksum' => strtolower($providerResponseChecksum),
                'idempotency_key' => $idempotencyKey,
            ];
            $requestChecksum = hash('sha256', json_encode($facts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            $duplicate = SalesCommissionAccountingDelivery::query()
                ->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($duplicate) {
                abort_unless($duplicate->verification_source === 'quickbooks'
                    && $duplicate->request_payload_checksum
                    && hash_equals($duplicate->request_payload_checksum, $requestChecksum), 409,
                    'This accounting-delivery key is already bound to different provider evidence.');

                return $duplicate;
            }

            abort_unless(in_array($payout->status, ['confirmed', 'reversal'], true), 409,
                'QuickBooks delivery can only be accepted for a confirmed payout or its reversal.');
            abort_unless($payout->accounting_status !== 'delivered', 409,
                'A QuickBooks delivery is already verified for this payout.');

            $integrity = $this->reconciliation->reconcilePayout((string) $payout->id);
            abort_unless($integrity && $integrity['issues'] === []
                && hash_equals((string) $integrity['accounting_event_id'], $sourceEventId), 409,
                'The payout source and accounting outbox event must reconcile before QuickBooks acceptance is recorded.');

            $accountingDelivery = SalesCommissionAccountingDelivery::create([
                'payout_id' => $payout->id,
                'event_type' => $eventType,
                'status' => 'accepted',
                'external_reference' => $providerTransactionId,
                'message' => null,
                'idempotency_key' => $idempotencyKey,
                'request_payload_checksum' => $requestChecksum,
                'verification_source' => 'quickbooks',
                'source_outbox_event_id' => $sourceEventId,
                'provider_transaction_id' => $providerTransactionId,
                'provider_response_checksum' => strtolower($providerResponseChecksum),
                'recorded_by' => null,
                'recorded_at' => now(),
            ]);
            $payout->update(['accounting_status' => 'delivered']);
            $this->events->record('sales', 'commission_payout', $payout->id,
                'sales.commission.accounting_delivery_verified', 3, 1, [
                    'payout_id' => $payout->id,
                    'event_type' => $eventType,
                    'source_outbox_event_id' => $sourceEventId,
                    'provider' => 'quickbooks',
                    'provider_transaction_id' => $providerTransactionId,
                    'provider_response_checksum' => strtolower($providerResponseChecksum),
                    'accounting_delivery_id' => $accountingDelivery->id,
                ], $accountingDelivery->recorded_at, $idempotencyKey, $sourceEventId);

            return $accountingDelivery;
        });
    }

}
