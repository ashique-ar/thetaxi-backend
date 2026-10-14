<?php

namespace App\Services\Sales;

use App\Support\Foundation\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class CommissionAccountingOutboxReconciliationService
{
    public function reconcilePayout(string $companyId, string $payoutId): ?array
    {
        $payout = DB::table('sales_commission_payouts')
            ->where('company_id', $companyId)->where('id', $payoutId)
            ->whereIn('status', ['confirmed', 'reversal', 'reversed'])->first();

        return $payout ? $this->reconcile($payout) : null;
    }

    public function preview(string $companyId, int $perPage = 50): array
    {
        $page = DB::table('sales_commission_payouts')
            ->where('company_id', $companyId)
            ->whereIn('status', ['confirmed', 'reversal', 'reversed'])
            ->orderByDesc('paid_at')->orderByDesc('id')
            ->paginate($perPage);

        $items = $page->getCollection()->map(fn (object $payout): array => $this->reconcile($payout))->values();
        $page->setCollection($items);

        return [
            'summary' => [
                'page_count' => $items->count(),
                'page_pending_delivery_count' => $items->where('accounting_status', '!=', 'delivered')->count(),
                'page_issue_count' => $items->filter(fn (array $item): bool => $item['issues'] !== [])->count(),
                'total' => $page->total(),
            ],
            'data' => $page,
        ];
    }

    private function reconcile(object $payout): array
    {
        $isReversal = (bool) $payout->reverses_payout_id;
        $sourceEventType = $isReversal ? 'sales.commission.payout_reversed' : 'sales.commission.paid';
        $deliveryEventType = $isReversal ? 'payout_reversal' : 'payout';
        $events = DB::table('domain_outbox_events')
            ->where('domain', 'sales')->where('aggregate_type', 'commission_payout')
            ->where('aggregate_id', $payout->id)->orderBy('event_version')->get();
        $source = $events->first(fn (object $event): bool => $event->event_type === $sourceEventType && (int) $event->event_version === 1);
        $accounting = $events->first(fn (object $event): bool => $event->event_type === 'sales.commission.accounting_ready' && (int) $event->event_version === 2);
        $issues = [];

        if (! $source) $issues[] = 'source_event_missing';
        if (! $accounting) $issues[] = 'accounting_event_missing';

        $sourcePayload = $source ? json_decode($source->payload, true) : null;
        $sourceChecksumValid = $source && is_array($sourcePayload)
            && hash_equals($source->payload_checksum, hash('sha256', CanonicalJson::encode($sourcePayload)));
        if ($source && ! $sourceChecksumValid) $issues[] = 'source_event_checksum_invalid';
        if ($source && (string) $source->company_id !== (string) $payout->company_id) $issues[] = 'source_event_company_mismatch';
        if ($accounting && (string) $accounting->company_id !== (string) $payout->company_id) $issues[] = 'accounting_event_company_mismatch';

        $payload = $accounting ? json_decode($accounting->payload, true) : null;
        $checksumValid = $accounting && is_array($payload)
            && hash_equals($accounting->payload_checksum, hash('sha256', CanonicalJson::encode($payload)));
        if ($accounting && ! $checksumValid) $issues[] = 'accounting_event_checksum_invalid';

        $expectedAmount = ($isReversal ? '-' : '').number_format(abs((float) $payout->amount_lkr), 4, '.', '');
        $expectedAllocations = DB::table('sales_commission_payout_allocations as allocation')
            ->join('sales_commission_statements as statement', 'statement.id', '=', 'allocation.statement_id')
            ->where('statement.company_id', $payout->company_id)
            ->where('allocation.payout_id', $payout->id)
            ->orderBy('statement.period_start')->orderBy('statement.id')
            ->get(['statement.statement_number', 'allocation.amount_lkr'])
            ->map(static function (object $row): array {
                $amount = (float) $row->amount_lkr;

                return [
                    'amount_lkr' => ($amount < 0 ? '-' : '').number_format(abs($amount), 4, '.', ''),
                    'statement_number' => $row->statement_number,
                ];
            })->all();
        $allocationTotal = round(array_sum(array_map(static fn (array $row): float => (float) $row['amount_lkr'], $expectedAllocations)), 4);
        $signedAmount = round($isReversal ? -abs((float) $payout->amount_lkr) : abs((float) $payout->amount_lkr), 4);
        if ($expectedAllocations === []) $issues[] = 'payout_allocations_missing';
        if ($allocationTotal !== $signedAmount) $issues[] = 'payout_allocation_total_mismatch';

        if ($accounting && $checksumValid) {
            $expected = [
                'document_type' => $isReversal ? 'payout_reversal' : 'payout',
                'document_id' => (string) $payout->id,
                'document_number' => $payout->payout_number,
                'amount_lkr' => $expectedAmount,
                'currency' => 'LKR',
                'payment_reference' => $payout->payment_reference,
                'reverses_document_id' => $payout->reverses_payout_id,
                'evidence_file_id' => $payout->evidence_file_id,
                'statement_allocations' => $expectedAllocations,
                'accounting_status' => 'pending_delivery',
            ];
            foreach ($expected as $key => $value) {
                if (($payload[$key] ?? null) !== $value) $issues[] = 'accounting_event_facts_mismatch';
            }
            if (! $source || $accounting->causation_id !== $source->id) $issues[] = 'accounting_event_causation_mismatch';
            try {
                if (! isset($payload['effective_at'])
                    || ! CarbonImmutable::parse($payload['effective_at'])->equalTo(CarbonImmutable::parse($payout->paid_at))) {
                    $issues[] = 'accounting_event_effective_at_mismatch';
                }
            } catch (\Throwable) {
                $issues[] = 'accounting_event_effective_at_mismatch';
            }
        }

        $deliveryRecords = DB::table('sales_commission_accounting_deliveries')
            ->where('payout_id', $payout->id)->where('event_type', $deliveryEventType)->where('status', 'accepted')->get();
        if ($deliveryRecords->contains(static fn (object $delivery): bool => (string) $delivery->company_id !== (string) $payout->company_id)) {
            $issues[] = 'delivery_company_mismatch';
        }
        $verified = $deliveryRecords->contains(static fn (object $delivery): bool => $delivery->verification_source === 'quickbooks'
            && (string) $delivery->company_id === (string) $payout->company_id
            && $delivery->source_outbox_event_id === $accounting?->id
            && trim((string) $delivery->provider_transaction_id) !== ''
            && preg_match('/^[a-f0-9]{64}$/i', (string) $delivery->provider_response_checksum) === 1);
        $manualAcknowledgement = $deliveryRecords->contains(static fn (object $delivery): bool => $delivery->verification_source !== 'quickbooks');
        if (($payout->accounting_status === 'delivered') !== $verified) $issues[] = 'delivery_status_mismatch';
        if ($manualAcknowledgement) $issues[] = 'manual_reference_unverified';

        $salesSubscribers = config('foundation.outbox_subscribers.sales', []);
        $subscriber = is_array($salesSubscribers) ? ($salesSubscribers['sales.commission.accounting_ready'] ?? null) : null;
        $subscriberRegistered = is_string($subscriber)
            && is_subclass_of($subscriber, \App\Contracts\Foundation\DomainOutboxSubscriber::class);

        return [
            'payout_number' => $payout->payout_number,
            'document_type' => $isReversal ? 'payout_reversal' : 'payout',
            'amount_lkr' => $expectedAmount,
            'currency' => 'LKR',
            'paid_at' => $payout->paid_at,
            'payment_reference' => $payout->payment_reference,
            'accounting_status' => $payout->accounting_status,
            'integration_status' => $verified ? 'verified_delivered'
                : (($payout->accounting_status === 'delivered' || in_array('delivery_company_mismatch', $issues, true))
                    ? 'delivery_integrity_issue'
                    : (! $subscriberRegistered ? 'awaiting_subscriber' : ($accounting?->published_at ? 'relayed' : 'pending_relay'))),
            'delivery_verification' => $verified ? 'quickbooks_verified' : ($manualAcknowledgement ? 'manual_unverified' : 'not_delivered'),
            'source_event_id' => $source?->id,
            'accounting_event_id' => $accounting?->id,
            'accounting_event_checksum_valid' => (bool) $checksumValid,
            'issues' => array_values(array_unique($issues)),
        ];
    }
}
