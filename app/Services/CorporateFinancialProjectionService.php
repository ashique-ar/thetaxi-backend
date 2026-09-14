<?php

namespace App\Services;

use App\Models\Booking\BookingPaymentReceipt;
use App\Models\Finance\FinancialSettlementItem;
use App\Models\Finance\FinancialAccountSettlement;
use App\Models\Booking\Booking;
use App\Models\Booking\BookingItem;
use App\Models\Invoice;
use Illuminate\Support\Collection;

class CorporateFinancialProjectionService
{
    public function accountSummary(string $corporateId): array
    {
        $settlements = FinancialAccountSettlement::query()
            ->where('owner_type', 'corporate')
            ->where('owner_id', $corporateId)
            ->whereNotIn('status', ['void', 'draft'])
            ->with(['document', 'items:id,settlement_id,booking_id,booking_item_ids,charge_amount,paid_before_amount,refund_amount,adjustment_amount,allocated_amount,outstanding_amount,status'])
            ->orderByDesc('period_end')
            ->orderByDesc('created_at')
            ->get();

        $bookingIds = Booking::query()
            ->where('is_corporate_booking', true)
            ->where('corporate_account_id', $corporateId)
            ->pluck('id');
        $receipts = BookingPaymentReceipt::query()
            ->whereIn('booking_id', $bookingIds)
            ->get(['payment_purpose', 'amount', 'refunded_amount', 'allocated_amount']);
        $fareReceipts = $receipts->whereIn('payment_purpose', ['booking_payment', 'service_deposit']);
        $securityReceipts = $receipts->where('payment_purpose', 'security_deposit');
        $billedItemIds = $settlements->flatMap(fn($settlement) => $settlement->items)
            ->flatMap(fn($item) => $item->booking_item_ids ?? [])->map(fn($id) => (string) $id)->unique();
        $monthlyItems = BookingItem::query()
            ->whereHas('booking', fn($query) => $query->where('is_corporate_booking', true)
                ->where('corporate_account_id', $corporateId)->where('payment_collection_method', 'monthly_invoice'))
            ->where('status', 'completed')->get(['id', 'total_price', 'final_priced_at']);
        $unbilledItems = $monthlyItems->whereNotNull('final_priced_at')->reject(fn($item) => $billedItemIds->contains((string) $item->id));
        $pricingPendingItems = $monthlyItems->whereNull('final_priced_at');

        $aging = ['current' => 0.0, 'days_1_30' => 0.0, 'days_31_60' => 0.0, 'days_61_90' => 0.0, 'days_91_plus' => 0.0];
        $disputedOutstanding = 0.0;
        foreach ($settlements as $settlement) {
            $outstanding = (float) $settlement->outstanding_total;
            if ($outstanding <= 0) {
                continue;
            }
            if ($settlement->status === 'disputed') {
                $disputedOutstanding += $outstanding;
                continue;
            }
            $days = $settlement->due_date ? max(0, $settlement->due_date->diffInDays(today(), false)) : 0;
            $bucket = $days <= 0 ? 'current' : ($days <= 30 ? 'days_1_30' : ($days <= 60 ? 'days_31_60' : ($days <= 90 ? 'days_61_90' : 'days_91_plus')));
            $aging[$bucket] += $outstanding;
        }

        $rows = $settlements->map(fn(FinancialAccountSettlement $settlement) => [
            'id' => $settlement->id,
            'settlement_number' => $settlement->settlement_number,
            'billing_cycle' => $settlement->billing_cycle,
            'period_start' => $settlement->period_start?->toDateString(),
            'period_end' => $settlement->period_end?->toDateString(),
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

        return [
            'summary' => [
                'charges_total' => round((float) $settlements->sum('charges_total'), 2),
                'payments_total' => round((float) $settlements->sum('payments_total'), 2),
                'refunds_total' => round((float) $settlements->sum('refunds_total'), 2),
                'adjustments_total' => round((float) $settlements->sum('adjustments_total'), 2),
                'outstanding_total' => round((float) $settlements->sum('outstanding_total'), 2),
                'disputed_outstanding' => round($disputedOutstanding, 2),
                'unapplied_receipts' => round((float) $fareReceipts->sum(fn($receipt) => max(0, (float) $receipt->amount - (float) $receipt->refunded_amount - (float) $receipt->allocated_amount)), 2),
                'unbilled_monthly_total' => round((float) $unbilledItems->sum('total_price'), 2),
                'unbilled_monthly_trip_count' => $unbilledItems->count(),
                'final_pricing_pending_trip_count' => $pricingPendingItems->count(),
                'security_deposit_received' => round((float) $securityReceipts->sum('amount'), 2),
                'security_deposit_refunded' => round((float) $securityReceipts->sum('refunded_amount'), 2),
                'security_deposit_held' => round((float) $securityReceipts->sum(fn($receipt) => max(0, (float) $receipt->amount - (float) $receipt->refunded_amount)), 2),
            ],
            'aging' => collect($aging)->map(fn($value) => round((float) $value, 2))->all(),
            'settlements' => $rows,
        ];
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
                $summary[$key] = round((float) $value, 2);
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
