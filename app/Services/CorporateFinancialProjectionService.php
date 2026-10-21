<?php

namespace App\Services;

use App\Models\Booking\BookingPaymentReceipt;
use App\Models\Finance\FinancialSettlementItem;
use App\Models\Finance\FinancialAccountSettlement;
use App\Models\Booking\Booking;
use App\Models\Booking\BookingItem;
use App\Models\Finance\CorporateRemittance;
use App\Models\Finance\FinancialAuditEvent;
use App\Models\Corporate\CorporateBillingTerm;
use App\Models\Invoice;
use Illuminate\Support\Collection;

class CorporateFinancialProjectionService
{
    public function isCreditLimitExceeded(string $corporateId): bool
    {
        $terms = CorporateBillingTerm::withInactive()->where('corporate_id', $corporateId)
            ->whereDate('effective_from', '<=', today())
            ->where(fn($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', today()))
            ->orderByDesc('effective_from')->first();
        if ($terms?->credit_limit === null) {
            return false;
        }
        $currency = strtoupper((string) $terms->currency);
        $settlements = FinancialAccountSettlement::query()->where('owner_type', 'corporate')
            ->where('owner_id', $corporateId)->whereNotIn('status', ['void', 'draft'])
            ->with('items.booking:id,currency')->get(['id', 'outstanding_total', 'billing_terms_snapshot']);
        $outstanding = (float) $settlements->filter(fn($row) => strtoupper((string) (data_get($row->billing_terms_snapshot, 'currency')
            ?: $row->items->first()?->booking?->currency)) === $currency)->sum('outstanding_total');

        $billedItemIds = FinancialSettlementItem::query()
            ->whereHas('settlement', fn($query) => $query->where('owner_type', 'corporate')
                ->where('owner_id', $corporateId)->where('status', '!=', 'void'))
            ->get(['booking_item_ids'])->flatMap(fn($row) => $row->booking_item_ids ?? [])
            ->map(fn($id) => (string) $id)->unique();
        $unbilled = BookingItem::query()
            ->whereHas('booking', fn($query) => $query->where('is_corporate_booking', true)
                ->where('corporate_account_id', $corporateId)->where('payment_collection_method', 'monthly_invoice'))
            ->where('status', 'completed')->whereNotNull('final_priced_at')
            ->with('booking:id,currency')->get(['id', 'booking_id', 'total_price', 'currency'])
            ->reject(fn($item) => $billedItemIds->contains((string) $item->id))
            ->filter(fn($item) => strtoupper((string) ($item->currency ?: $item->booking?->currency)) === $currency);

        $bookings = Booking::query()->where('is_corporate_booking', true)->where('corporate_account_id', $corporateId)->get(['id', 'currency']);
        $bookingCurrency = $bookings->mapWithKeys(fn($booking) => [(string) $booking->id => strtoupper((string) $booking->currency)]);
        $unappliedReceipts = BookingPaymentReceipt::query()->whereIn('booking_id', $bookings->pluck('id'))
            ->whereNull('corporate_remittance_id')->whereIn('payment_purpose', ['booking_payment', 'service_deposit'])
            ->get(['booking_id', 'amount', 'source_amount', 'source_currency', 'refunded_amount', 'allocated_amount'])
            ->filter(fn($row) => strtoupper((string) ($row->source_currency ?: $bookingCurrency->get((string) $row->booking_id))) === $currency)
            ->sum(fn($row) => max(0, (float) ($row->source_amount ?? $row->amount) - (float) $row->refunded_amount - (float) $row->allocated_amount));
        $unappliedRemittances = CorporateRemittance::query()->where('corporate_id', $corporateId)
            ->where('currency', $currency)->sum('unapplied_amount');
        $creditUsed = app(CurrencyService::class)->roundAmount(max(0, $outstanding + (float) $unbilled->sum('total_price') - (float) $unappliedReceipts - (float) $unappliedRemittances), $currency);

        return $creditUsed >= (float) $terms->credit_limit;
    }

    public function accountSummary(string $corporateId): array
    {
        $settlements = FinancialAccountSettlement::query()
            ->where('owner_type', 'corporate')
            ->where('owner_id', $corporateId)
            ->whereNotIn('status', ['void', 'draft'])
            ->with(['document', 'items:id,settlement_id,booking_id,booking_item_ids,charge_amount,paid_before_amount,refund_amount,adjustment_amount,allocated_amount,outstanding_amount,status', 'items.booking:id,currency'])
            ->orderByDesc('period_end')
            ->orderByDesc('created_at')
            ->get();

        $bookings = Booking::query()
            ->where('is_corporate_booking', true)
            ->where('corporate_account_id', $corporateId)
            ->get(['id', 'currency']);
        $bookingIds = $bookings->pluck('id');
        $bookingCurrency = $bookings->mapWithKeys(fn($booking) => [(string) $booking->id => strtoupper((string) $booking->currency ?: 'UNKNOWN')]);
        $receipts = BookingPaymentReceipt::query()
            ->whereIn('booking_id', $bookingIds)
            ->get(['booking_id', 'payment_purpose', 'amount', 'source_amount', 'source_currency', 'refunded_amount', 'allocated_amount', 'corporate_remittance_id', 'reference', 'received_at', 'created_at']);
        $fareReceipts = $receipts->whereNull('corporate_remittance_id')->whereIn('payment_purpose', ['booking_payment', 'service_deposit']);
        $securityReceipts = $receipts->where('payment_purpose', 'security_deposit');
        $billedItemIds = FinancialSettlementItem::query()
            ->whereHas('settlement', fn($query) => $query->where('owner_type', 'corporate')
                ->where('owner_id', $corporateId)->where('status', '!=', 'void'))
            ->get(['booking_item_ids'])->flatMap(fn($item) => $item->booking_item_ids ?? [])
            ->map(fn($id) => (string) $id)->unique();
        $monthlyItems = BookingItem::query()
            ->whereHas('booking', fn($query) => $query->where('is_corporate_booking', true)
                ->where('corporate_account_id', $corporateId)->where('payment_collection_method', 'monthly_invoice'))
            ->where('status', 'completed')->with('booking:id,currency')->get(['id', 'booking_id', 'total_price', 'currency', 'final_priced_at']);
        $unbilledItems = $monthlyItems->whereNotNull('final_priced_at')->reject(fn($item) => $billedItemIds->contains((string) $item->id));
        $pricingPendingItems = $monthlyItems->whereNull('final_priced_at');
        $itemCurrency = fn($item) => strtoupper((string) ($item->currency ?: $item->booking?->currency) ?: 'UNKNOWN');
        $remittances = CorporateRemittance::query()->where('corporate_id', $corporateId)->latest('received_at')->get();
        $legacyReceipts = BookingPaymentReceipt::query()->whereIn('booking_id', $bookingIds)
            ->whereNull('corporate_remittance_id')->whereIn('payment_purpose', ['booking_payment', 'service_deposit'])->get();
        $terms = CorporateBillingTerm::withInactive()->where('corporate_id', $corporateId)
            ->whereDate('effective_from', '<=', today())
            ->where(fn($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', today()))
            ->orderByDesc('effective_from')->first();
        $settlementCurrency = fn($settlement) => strtoupper((string) (data_get($settlement->billing_terms_snapshot, 'currency')
            ?: $settlement->items->first()?->booking?->currency) ?: 'UNKNOWN');
        $settlementsByCurrency = $settlements->groupBy($settlementCurrency);
        $itemsByCurrency = $monthlyItems->groupBy($itemCurrency);
        $receiptCurrency = fn($receipt) => strtoupper((string) ($receipt->source_currency ?: $bookingCurrency->get((string) $receipt->booking_id, 'UNKNOWN')) ?: 'UNKNOWN');
        $receiptsByCurrency = $fareReceipts->groupBy($receiptCurrency);
        $remittancesByCurrency = $remittances->groupBy(fn($row) => strtoupper((string) $row->currency ?: 'UNKNOWN'));
        $securityByCurrency = $securityReceipts->groupBy($receiptCurrency);
        $currencies = $settlementsByCurrency->keys()
            ->merge($itemsByCurrency->keys())->merge($receiptsByCurrency->keys())
            ->merge($remittancesByCurrency->keys())->merge($securityByCurrency->keys())
            ->unique()->values();
        $moneyKeys = ['charges_total', 'payments_total', 'refunds_total', 'adjustments_total', 'outstanding_total', 'disputed_outstanding', 'unapplied_receipts', 'unbilled_monthly_total', 'credit_used', 'security_deposit_received', 'security_deposit_refunded', 'security_deposit_held'];
        $summaryByCurrency = $currencies->mapWithKeys(function ($currency) use ($settlementsByCurrency, $itemsByCurrency, $unbilledItems, $pricingPendingItems, $receiptsByCurrency, $remittancesByCurrency, $securityByCurrency, $itemCurrency, $moneyKeys) {
            $roundMoney = fn(float $amount): float => app(CurrencyService::class)->roundAmount($amount, $currency);
            $rows = $settlementsByCurrency->get($currency, collect());
            $unbilled = $unbilledItems->filter(fn($item) => $itemCurrency($item) === $currency);
            $pending = $pricingPendingItems->filter(fn($item) => $itemCurrency($item) === $currency);
            $receipts = $receiptsByCurrency->get($currency, collect());
            $remittances = $remittancesByCurrency->get($currency, collect());
            $security = $securityByCurrency->get($currency, collect());
            $outstanding = (float) $rows->sum('outstanding_total');
            $unbilledTotal = (float) $unbilled->sum('total_price');
            $unapplied = (float) $receipts->sum(fn($receipt) => max(0, (float) ($receipt->source_amount ?? $receipt->amount) - (float) $receipt->refunded_amount - (float) $receipt->allocated_amount))
                + (float) $remittances->sum('unapplied_amount');
            $values = array_fill_keys($moneyKeys, 0.0);
            $values = [
                ...$values,
                'charges_total' => $roundMoney((float) $rows->sum('charges_total')),
                'payments_total' => $roundMoney((float) $rows->sum('payments_total')),
                'refunds_total' => $roundMoney((float) $rows->sum('refunds_total')),
                'adjustments_total' => $roundMoney((float) $rows->sum('adjustments_total')),
                'outstanding_total' => $roundMoney($outstanding),
                'disputed_outstanding' => $roundMoney((float) $rows->where('status', 'disputed')->sum('outstanding_total')),
                'unapplied_receipts' => $roundMoney($unapplied),
                'unbilled_monthly_total' => $roundMoney($unbilledTotal),
                'unbilled_monthly_trip_count' => $unbilled->count(),
                'final_pricing_pending_trip_count' => $pending->count(),
                'credit_used' => $roundMoney(max(0, $outstanding + $unbilledTotal - $unapplied)),
                'security_deposit_received' => $roundMoney((float) $security->sum(fn($receipt) => (float) ($receipt->source_amount ?? $receipt->amount))),
                'security_deposit_refunded' => $roundMoney((float) $security->sum('refunded_amount')),
                'security_deposit_held' => $roundMoney((float) $security->sum(fn($receipt) => max(0, (float) ($receipt->source_amount ?? $receipt->amount) - (float) $receipt->refunded_amount))),
                'currency' => $currency,
            ];
            return [$currency => $values];
        });
        $currentCurrency = strtoupper((string) ($terms?->currency ?: 'UNKNOWN'));
        $activeCurrencySummary = $summaryByCurrency->get($currentCurrency, [...array_fill_keys($moneyKeys, 0.0), 'unbilled_monthly_trip_count' => 0, 'final_pricing_pending_trip_count' => 0, 'currency' => $currentCurrency]);
        $creditUsed = $activeCurrencySummary['credit_used'];

        $agingByCurrency = $settlements->groupBy($settlementCurrency)->map(function ($rows, $currency) {
            $aging = ['current' => 0.0, 'days_1_30' => 0.0, 'days_31_60' => 0.0, 'days_61_90' => 0.0, 'days_91_plus' => 0.0];
            foreach ($rows as $settlement) {
            $outstanding = (float) $settlement->outstanding_total;
            if ($outstanding <= 0) {
                continue;
            }
            if ($settlement->status === 'disputed') {
                continue;
            }
            $days = $settlement->due_date ? max(0, $settlement->due_date->diffInDays(today(), false)) : 0;
            $bucket = $days <= 0 ? 'current' : ($days <= 30 ? 'days_1_30' : ($days <= 60 ? 'days_31_60' : ($days <= 90 ? 'days_61_90' : 'days_91_plus')));
            $aging[$bucket] += $outstanding;
            }
            return collect($aging)->map(fn($value) => app(CurrencyService::class)->roundAmount((float) $value, $currency))->all();
        });
        $aging = $agingByCurrency->get($currentCurrency, ['current' => 0.0, 'days_1_30' => 0.0, 'days_31_60' => 0.0, 'days_61_90' => 0.0, 'days_91_plus' => 0.0]);

        $rows = $settlements->map(fn(FinancialAccountSettlement $settlement) => [
            'id' => $settlement->id,
            'settlement_number' => $settlement->settlement_number,
            'billing_cycle' => $settlement->billing_cycle,
            'period_start' => $settlement->period_start?->toDateString(),
            'period_end' => $settlement->period_end?->toDateString(),
            'currency' => $settlementCurrency($settlement),
            'due_date' => $settlement->due_date?->toDateString(),
            'status' => $settlement->status,
            'invoice_number' => $settlement->invoice_number,
            'charges_total' => (float) $settlement->charges_total,
            'payments_total' => (float) $settlement->payments_total,
            'refunds_total' => (float) $settlement->refunds_total,
            'adjustments_total' => (float) $settlement->adjustments_total,
            'outstanding_total' => (float) $settlement->outstanding_total,
            'issued_at' => $settlement->issued_at?->toIso8601String(),
            'settled_at' => $settlement->settled_at?->toIso8601String(),
            'booking_count' => $settlement->items->count(),
            'document' => $settlement->document ? [
                'status' => $settlement->document->status,
                'generated_at' => $settlement->document->generated_at?->toIso8601String(),
                'sent_at' => $settlement->document->sent_at?->toIso8601String(),
                'sent_to' => $settlement->document->sent_to,
                'download_available' => !empty($settlement->document->pdf_path),
                'has_error' => !empty($settlement->document->last_error),
            ] : null,
            'statement' => [
                'available' => !empty($settlement->statement_pdf_path),
                'snapshot' => $settlement->statement_snapshot,
                'sent_at' => $settlement->statement_sent_at?->toIso8601String(),
                'sent_to' => $settlement->statement_sent_to,
                'has_error' => !empty($settlement->statement_last_error),
            ],
        ])->values();
        $settlementCurrencies = $settlements->mapWithKeys(fn($row) => [(string) $row->id => $settlementCurrency($row)]);
        $runningBalances = [];
        $adjustmentEvents = FinancialAuditEvent::query()
            ->where('subject_type', 'account_settlement')
            ->whereIn('subject_id', $settlements->pluck('id'))
            ->whereIn('event_type', ['additional_charge', 'refund', 'credit_note', 'waiver'])
            ->orderBy('occurred_at')->get();
        $activity = $settlements->map(fn($settlement) => [
            'date' => $settlement->issued_at?->toIso8601String(), 'type' => 'invoice',
            'reference' => $settlement->invoice_number ?: $settlement->settlement_number,
            'currency' => $settlementCurrency($settlement),
            'amount' => app(CurrencyService::class)->roundAmount((float) $settlement->charges_total, $settlementCurrency($settlement)),
        ])->concat($adjustmentEvents->map(fn($event) => [
            'date' => $event->occurred_at?->toIso8601String(),
            'type' => $event->event_type,
            'reference' => data_get($event->metadata, 'reference'),
            'currency' => $settlementCurrencies->get((string) $event->subject_id, 'UNKNOWN'),
            'amount' => in_array($event->event_type, ['refund', 'credit_note', 'waiver'], true)
                ? -(float) $event->amount : (float) $event->amount,
        ]))->concat($remittances->map(fn($remittance) => [
            'date' => $remittance->received_at?->toIso8601String(), 'type' => 'remittance',
            'reference' => $remittance->reference, 'currency' => strtoupper((string) $remittance->currency ?: 'UNKNOWN'),
            'amount' => -(float) $remittance->amount,
        ]))->concat($legacyReceipts->map(fn($receipt) => [
            'date' => ($receipt->received_at ?: $receipt->created_at ?: $settlements->min('issued_at'))?->toIso8601String(), 'type' => 'payment',
            'reference' => $receipt->reference, 'currency' => $receiptCurrency($receipt),
            'amount' => -max(0, (float) ($receipt->source_amount ?? $receipt->amount) - (float) $receipt->refunded_amount),
        ]))->filter(fn($row) => $row['date'])->sortBy('date')->values()->map(function ($row) use (&$runningBalances) {
            $currency = $row['currency'] ?: 'UNKNOWN';
            $runningBalances[$currency] = app(CurrencyService::class)->roundAmount(($runningBalances[$currency] ?? 0) + (float) $row['amount'], $currency);
            return [...$row, 'running_balance' => $runningBalances[$currency]];
        });

        return [
            'summary' => [
                ...$activeCurrencySummary,
                'draft_invoice_count' => FinancialAccountSettlement::query()->where('owner_type', 'corporate')
                    ->where('owner_id', $corporateId)->where('status', 'draft')->count(),
                'credit_limit' => $terms?->credit_limit !== null ? (float) $terms->credit_limit : null,
                'credit_available' => $terms?->credit_limit !== null ? app(CurrencyService::class)->roundAmount((float) $terms->credit_limit - $creditUsed, $currentCurrency) : null,
                'credit_limit_exceeded' => $terms?->credit_limit !== null && $creditUsed >= (float) $terms->credit_limit,
            ],
            'summary_by_currency' => $summaryByCurrency,
            'aging_by_currency' => $agingByCurrency,
            'aging' => $aging,
            'settlements' => $rows,
            'remittances' => $remittances->load('allocations.reversal', 'allocations.settlement')->map(fn($remittance) => [
                'id' => $remittance->id, 'reference' => $remittance->reference, 'currency' => $remittance->currency,
                'payment_method' => $remittance->payment_method, 'amount' => (float) $remittance->amount,
                'allocated_amount' => (float) $remittance->allocated_amount, 'unapplied_amount' => (float) $remittance->unapplied_amount,
                'received_at' => $remittance->received_at?->toIso8601String(),
                'allocations' => $remittance->allocations->map(fn($allocation) => [
                    'id' => $allocation->id,
                    'settlement_id' => $allocation->settlement_id,
                    'invoice_number' => $allocation->settlement?->invoice_number ?: $allocation->settlement?->settlement_number,
                    'amount' => (float) $allocation->amount,
                    'reversed_at' => $allocation->reversal?->reversed_at?->toIso8601String(),
                    'reversal_reason' => $allocation->reversal?->reason,
                ])->values(),
            ])->values(),
            'account_activity' => $activity,
        ];
    }

    public function billingPeriods(string $corporateId): array
    {
        return FinancialAccountSettlement::query()
            ->where('owner_type', 'corporate')
            ->where('owner_id', $corporateId)
            ->whereNotIn('status', ['void', 'draft'])
            ->with('items.booking:id,currency')
            ->orderByDesc('period_end')
            ->orderByDesc('created_at')
            ->get(['id', 'settlement_number', 'billing_terms_snapshot', 'period_start', 'period_end', 'due_date', 'status', 'invoice_number', 'charges_total', 'payments_total', 'refunds_total', 'adjustments_total', 'outstanding_total'])
            ->map(fn (FinancialAccountSettlement $settlement) => [
                'id' => $settlement->id,
                'settlement_number' => $settlement->settlement_number,
                'period_start' => $settlement->period_start?->toDateString(),
                'period_end' => $settlement->period_end?->toDateString(),
                'due_date' => $settlement->due_date?->toDateString(),
                'status' => $settlement->status,
                'invoice_number' => $settlement->invoice_number,
                'currency' => strtoupper((string) (data_get($settlement->billing_terms_snapshot, 'currency')
                    ?: $settlement->items->first()?->booking?->currency
                    ?: 'UNKNOWN')),
                'charges_total' => (float) $settlement->charges_total,
                'payments_total' => (float) $settlement->payments_total,
                'refunds_total' => (float) $settlement->refunds_total,
                'adjustments_total' => (float) $settlement->adjustments_total,
                'outstanding_total' => (float) $settlement->outstanding_total,
            ])
            ->values()
            ->all();
    }

    public function settlementDetail(string $corporateId, string $settlementId): array
    {
        $settlement = FinancialAccountSettlement::query()
            ->where('owner_type', 'corporate')
            ->where('owner_id', $corporateId)
            ->whereNotIn('status', ['void', 'draft'])
            ->with(['document', 'items.booking:id,booking_number,total_estimated,total_actual,status'])
            ->findOrFail($settlementId);
        $summary = $this->accountSummary($corporateId);
        $row = collect($summary['settlements'])->firstWhere('id', $settlement->id) ?? [];

        return [
            ...$row,
            'dispute_reason' => $settlement->dispute_reason,
            'items' => $settlement->items->map(fn($item) => [
                'id' => $item->id,
                'booking_id' => $item->booking_id,
                'booking_number' => $item->booking?->booking_number,
                'booking_status' => $item->booking?->status,
                'charge_amount' => (float) $item->charge_amount,
                'paid_before_amount' => (float) $item->paid_before_amount,
                'allocated_amount' => (float) $item->allocated_amount,
                'refund_amount' => (float) $item->refund_amount,
                'adjustment_amount' => (float) $item->adjustment_amount,
                'outstanding_amount' => (float) $item->outstanding_amount,
                'status' => $item->status,
            ])->values(),
        ];
    }

    public function summarizeBookings(Collection $bookings, ?Collection $bookingItemIds = null): array
    {
        $currencyGroups = $bookings->groupBy(fn($booking) => strtoupper(trim((string) ($booking->currency ?: 'UNKNOWN'))));
        if ($currencyGroups->count() > 1) {
            $byCurrency = $currencyGroups->mapWithKeys(fn(Collection $rows, string $currency) => [
                $currency => $this->summarizeBookingSet($rows, $bookingItemIds),
            ]);
            $summary = $this->emptySummary();
            foreach ($summary as $key => $value) {
                if (str_ends_with($key, '_value')) {
                    $summary[$key] = null;
                } elseif (str_ends_with($key, '_count')) {
                    $summary[$key] = (int) $byCurrency->sum($key);
                }
            }

            return [...$summary, 'financial_by_currency' => $byCurrency];
        }

        return $this->summarizeBookingSet($bookings, $bookingItemIds);
    }

    private function summarizeBookingSet(Collection $bookings, ?Collection $bookingItemIds): array
    {
        $bookingIds = $bookings->pluck('id')->map(fn($id) => (string) $id)->values();
        if ($bookingIds->isEmpty()) {
            return $this->emptySummary();
        }

        $receipts = BookingPaymentReceipt::query()
            ->whereIn('booking_id', $bookingIds)
            ->whereIn('payment_purpose', ['booking_payment', 'service_deposit'])
            ->get(['booking_id', 'amount', 'refunded_amount'])
            ->groupBy(fn($receipt) => (string) $receipt->booking_id);

        $settlementItems = FinancialSettlementItem::query()
            ->whereIn('booking_id', $bookingIds)
            ->whereHas('settlement', fn($query) => $query->whereNotIn('status', ['void', 'draft']))
            ->with(['settlement:id,status,due_date,invoice_number,issued_at'])
            ->get()
            ->when($bookingItemIds !== null, fn(Collection $items) => $items->filter(function ($item) use ($bookingItemIds) {
                $itemIds = collect($item->booking_item_ids ?? []);
                return $itemIds->isEmpty() || $itemIds->intersect($bookingItemIds)->isNotEmpty();
            }))
            ->groupBy(fn($item) => (string) $item->booking_id);

        $directInvoices = Invoice::query()
            ->whereIn('booking_id', $bookingIds)
            ->where('status', '!=', 'void')
            ->orderByDesc('created_at')
            ->get(['id', 'booking_id', 'status', 'total_amount', 'due_date', 'invoice_number'])
            ->unique(fn($invoice) => (string) $invoice->booking_id)
            ->keyBy(fn($invoice) => (string) $invoice->booking_id);

        $summary = $this->emptySummary();
        foreach ($bookings as $booking) {
            $id = (string) $booking->id;
            $bookingReceipts = $receipts->get($id, collect());
            $receiptGross = (float) $bookingReceipts->sum('amount');
            $receiptRefunded = (float) $bookingReceipts->sum('refunded_amount');
            $receiptNet = max(0, $receiptGross - $receiptRefunded);
            $bookingSettlementItems = $settlementItems->get($id, collect());
            $directInvoice = $directInvoices->get($id);

            if ($bookingSettlementItems->isNotEmpty()) {
                $issuedItems = $bookingSettlementItems->filter(fn($item) => !in_array((string) $item->settlement?->status, ['draft', 'void', ''], true));
                $summary['invoiced_value'] += (float) $issuedItems->sum('charge_amount');
                $summary['invoiced_booking_count'] += $issuedItems->isNotEmpty() ? 1 : 0;
                $summary['paid_value'] += (float) $issuedItems->sum(fn($item) => (float) $item->paid_before_amount + (float) $item->allocated_amount);
                $summary['refunded_value'] += (float) $issuedItems->sum('refund_amount');
                $summary['credited_value'] += (float) $issuedItems->sum(fn($item) => min(0, (float) $item->adjustment_amount) * -1);
                $summary['positive_adjustment_value'] += (float) $issuedItems->sum(fn($item) => max(0, (float) $item->adjustment_amount));
                $summary['outstanding_value'] += (float) $issuedItems->sum('outstanding_amount');
                $disputed = $issuedItems->filter(fn($item) => (string) $item->settlement?->status === 'disputed');
                $summary['disputed_value'] += (float) $disputed->sum('outstanding_amount');
                $summary['disputed_booking_count'] += $disputed->isNotEmpty() ? 1 : 0;
                continue;
            }

            if ($directInvoice) {
                $invoiceTotal = (float) $directInvoice->total_amount;
                $summary['invoiced_value'] += $invoiceTotal;
                $summary['invoiced_booking_count']++;
                $summary['paid_value'] += $receiptNet;
                $summary['refunded_value'] += $receiptRefunded;
                $summary['outstanding_value'] += max(0, $invoiceTotal - $receiptNet);
                continue;
            }

            // Receipts remain visible even before grouped/direct invoice generation.
            $summary['paid_value'] += $receiptNet;
            $summary['refunded_value'] += $receiptRefunded;
            $summary['uninvoiced_finalized_value'] += (float) ($booking->total_actual ?? 0);
        }

        foreach ($summary as $key => $value) {
            if (str_ends_with($key, '_value')) {
                $summary[$key] = app(CurrencyService::class)->roundAmount((float) $value, strtoupper((string) ($bookings->first()?->currency ?: 'LKR')));
            }
        }

        return $summary;
    }

    private function emptySummary(): array
    {
        return [
            'invoiced_value' => 0.0,
            'uninvoiced_finalized_value' => 0.0,
            'paid_value' => 0.0,
            'refunded_value' => 0.0,
            'credited_value' => 0.0,
            'positive_adjustment_value' => 0.0,
            'outstanding_value' => 0.0,
            'disputed_value' => 0.0,
            'invoiced_booking_count' => 0,
            'disputed_booking_count' => 0,
        ];
    }
}
