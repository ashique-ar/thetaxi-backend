<?php

namespace App\Services;

use App\Models\Booking\Booking;
use App\Models\Booking\BookingPaymentReceipt;
use App\Models\Booking\BookingDepositRefund;
use App\Models\Booking\BookingCollectionCommission;
use App\Models\Booking\BookingPaymentSchedule;
use App\Models\Booking\BookingPaymentScheduleAllocation;
use App\Models\Staff;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Carbon;
use App\Models\Corporate\Corporate;
use App\Models\Customer;
use App\Models\Finance\FinancialSettlementItem;
use App\Models\Finance\FinancialPaymentAllocation;
use App\Models\Finance\FinancialAuditEvent;
use App\Models\Finance\FinancialAdjustment;
use App\Models\Finance\FinancialSettlementDocument;

class BookingPaymentLedgerService
{
    public function accountSummaryFor(string $ownerType, string $ownerId): array
    {
        $booking = Booking::query()
            ->when($ownerType === 'corporate', fn($q) => $q->where('is_corporate_booking', true)->where('corporate_account_id', $ownerId))
            ->when($ownerType === 'customer', fn($q) => $q->where('customer_id', $ownerId)->where(fn($b) => $b->where('is_corporate_booking', false)->orWhereNull('is_corporate_booking')))
            ->latest('created_at')->first();
        if ($booking) return $this->accountSummary($booking);
        $ownerName = $ownerType === 'corporate'
            ? (Corporate::whereKey($ownerId)->value('name') ?: 'Corporate account')
            : (($customer=Customer::with('user')->find($ownerId)) ? (trim(($customer->user?->first_name??'').' '.($customer->user?->last_name??'')) ?: ($customer->code ?: 'Customer account')) : 'Customer account');
        return ['owner_type'=>$ownerType,'owner_id'=>$ownerId,'owner_name'=>$ownerName,'total_charged'=>0,'total_received'=>0,'total_due'=>0,'overdue_amount'=>0,'bookings_with_due'=>0,'settlements'=>[]];
    }

    public function accountSummary(Booking $booking): array
    {
        $isCorporate = (bool) $booking->is_corporate_booking && !empty($booking->corporate_account_id);
        $ownerType = $isCorporate ? 'corporate' : 'customer';
        $ownerId = $isCorporate ? $booking->corporate_account_id : $booking->customer_id;

        $query = Booking::query()
            ->whereNotIn('status', ['cancelled', 'canceled', 'rejected', 'expired']);
        if ($isCorporate) {
            // Corporate debt always belongs to the company account, never its employee/passenger.
            $query->where('is_corporate_booking', true)
                ->where('corporate_account_id', $ownerId);
            $ownerName = Corporate::query()->whereKey($ownerId)->value('name') ?: 'Corporate account';
        } else {
            // Individual debt belongs to the customer record, never a staff or driver user.
            $query->where(function ($builder) {
                $builder->where('is_corporate_booking', false)->orWhereNull('is_corporate_booking');
            })->where('customer_id', $ownerId);
            $customer = Customer::with('user')->find($ownerId);
            $ownerName = trim((string) (($customer?->user?->first_name ?? '') . ' ' . ($customer?->user?->last_name ?? '')))
                ?: ($customer?->code ?: 'Customer account');
        }

        $bookingSummaries = $query->get()->map(fn (Booking $accountBooking) => [
            'booking_id' => $accountBooking->id,
            'booking_number' => $accountBooking->booking_number,
            ...$this->summary($accountBooking),
        ]);

        return [
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'owner_name' => $ownerName,
            'total_charged' => round((float) $bookingSummaries->sum('total_amount'), 2),
            'total_received' => round((float) $bookingSummaries->sum('paid_amount'), 2),
            'total_due' => round((float) $bookingSummaries->sum('due_amount'), 2),
            'bookings_with_due' => $bookingSummaries->where('due_amount', '>', 0)->count(),
            'overdue_amount' => round((float)$bookingSummaries->filter(fn($row)=>$row['due_amount']>0 && !empty($row['settlement_due_date']) && $row['settlement_due_date'] < now()->toDateString())->sum('due_amount'),2),
            'settlements' => \App\Models\Finance\FinancialAccountSettlement::where('owner_type',$ownerType)->where('owner_id',$ownerId)->latest('period_end')->limit(10)->get(['id','settlement_number','period_start','period_end','due_date','status','invoice_number','charges_total','payments_total','outstanding_total','settled_at']),
        ];
    }

    public function summary(Booking $booking): array
    {
        $receipts = BookingPaymentReceipt::query()
            ->where('booking_id', $booking->id)
            ->orderByDesc('received_at')
            ->orderByDesc('created_at')
            ->with('depositRefunds')
            ->get();
        $fareReceipts = $receipts->whereIn('payment_purpose', ['booking_payment', 'service_deposit']);
        $securityReceipts = $receipts->where('payment_purpose', 'security_deposit');
        $securityReceived = round((float) $securityReceipts->sum('amount'), 2);
        $securityRefunded = round((float) $securityReceipts->sum('refunded_amount'), 2);
        $securityHeld = max(0, round($securityReceived - $securityRefunded, 2));
        $requiredSecurity = (float) $booking->bookingItems()->with('vehicleGroup')->get()
            ->sum(fn ($item) => (float) ($item->vehicleGroup?->refundable_deposit ?? 0) * max(1, (int) ($item->quantity ?? 1)));
        $total = round((float) ($booking->total_actual ?? $booking->total_estimated ?? $booking->amount_to_pay ?? 0), 2);
        $ledgerPaid = round((float) $fareReceipts->sum(fn ($receipt) => (float) $receipt->amount - (float) $receipt->refunded_amount), 2);
        $legacyPaid = $this->legacyPaidAmount($booking, $total);
        $paid = $receipts->isEmpty() ? $legacyPaid : $ledgerPaid;
        $settlementItem = FinancialSettlementItem::with('settlement')->where('booking_id', $booking->id)->latest('created_at')->first();
        $settlementId = $settlementItem?->settlement_id;
        $allocations = FinancialPaymentAllocation::query()
            ->where('booking_id', $booking->id)
            ->orderByDesc('allocated_at')
            ->limit(50)
            ->get();
        $adjustmentRows = FinancialAdjustment::query()
            ->where('booking_id', $booking->id)
            ->latest('created_at')
            ->limit(50)
            ->get();
        $settlementDocument = $settlementId
            ? FinancialSettlementDocument::query()->where('settlement_id', $settlementId)->first()
            : null;
        $refunds = (float)($settlementItem?->refund_amount ?? 0);
        $adjustments = (float)($settlementItem?->adjustment_amount ?? 0);
        $arrangement = $booking->payment_arrangement_status ?: $this->resolveArrangementStatus($booking);
        $due = $arrangement === 'complimentary' ? 0.0 : max(0, round($total + $adjustments - $refunds - $paid, 2));
        $status = $this->resolvePaymentStatus($booking, $paid, $due);
        $payer = $this->resolvePayer($booking);
        $driverReceipts = $receipts->where('received_via', 'driver');
        $driverCashCollected = round((float) $driverReceipts->sum('amount'), 2);
        $driverCashHandedOver = round((float) $driverReceipts->sum('driver_company_settled_amount'), 2);
        $scheduleRows = BookingPaymentSchedule::query()
            ->where('booking_id', $booking->id)
            ->withSum('allocations', 'amount')
            ->orderBy('due_date')
            ->orderBy('sequence')
            ->get();
        $scheduledAmount = round((float) $scheduleRows->sum('amount'), 2);
        $scheduledPaid = round((float) $scheduleRows->sum('allocations_sum_amount'), 2);
        $today = now()->toDateString();
        $paymentSchedule = $scheduleRows->map(function (BookingPaymentSchedule $schedule) use ($today) {
            $allocated = round((float) ($schedule->allocations_sum_amount ?? 0), 2);
            $balance = max(0, round((float) $schedule->amount - $allocated, 2));
            $status = $balance <= 0 ? 'paid' : ($allocated > 0 ? 'partially_paid' : ($schedule->due_date->toDateString() < $today ? 'overdue' : 'scheduled'));
            return [
                'id' => $schedule->id,
                'sequence' => $schedule->sequence,
                'label' => $schedule->label,
                'period_start' => $schedule->period_start?->toDateString(),
                'period_end' => $schedule->period_end?->toDateString(),
                'due_date' => $schedule->due_date->toDateString(),
                'amount' => (float) $schedule->amount,
                'allocated_amount' => $allocated,
                'balance_amount' => $balance,
                'status' => $status,
                'notes' => $schedule->notes,
            ];
        })->values();

        return [
            'total_amount' => $total,
            'paid_amount' => $paid,
            'service_deposit_received' => round((float) $receipts->where('payment_purpose', 'service_deposit')->sum(fn ($receipt) => (float) $receipt->amount - (float) $receipt->refunded_amount), 2),
            'booking_payments_received' => round((float) $receipts->where('payment_purpose', 'booking_payment')->sum(fn ($receipt) => (float) $receipt->amount - (float) $receipt->refunded_amount), 2),
            'due_amount' => $due,
            'security_deposit_received' => $securityReceived,
            'security_deposit_refunded' => $securityRefunded,
            'security_deposit_held' => $securityHeld,
            'security_deposit_required' => round($requiredSecurity, 2),
            'security_deposit_due' => max(0, round($requiredSecurity - $securityReceived, 2)),
            'security_deposit_variance' => round($securityReceived - $requiredSecurity, 2),
            'refund_amount' => $refunds,
            'adjustment_amount' => $adjustments,
            'payment_status' => $status,
            'monetary_status' => $due <= 0 ? 'settled' : ($paid > 0 ? 'partially_paid' : 'unpaid'),
            'payment_arrangement_status' => $arrangement,
            'customer_settlement_status' => $booking->customer_settlement_status,
            'corporate_settlement_status' => $booking->corporate_settlement_status,
            'driver_collection_status' => $booking->driver_collection_status,
            'invoice_status' => $booking->invoice_status,
            'refund_status' => $booking->refund_status,
            'settlement_due_date' => $booking->settlement_due_date?->toDateString(),
            'settled_at' => $booking->settled_at?->toIso8601String(),
            'settlement' => $settlementItem?->settlement ? [
                'id' => $settlementItem->settlement->id,
                'number' => $settlementItem->settlement->settlement_number,
                'invoice_number' => $settlementItem->settlement->invoice_number,
                'status' => $settlementItem->settlement->status,
                'period_start' => $settlementItem->settlement->period_start?->toDateString(),
                'period_end' => $settlementItem->settlement->period_end?->toDateString(),
                'due_date' => $settlementItem->settlement->due_date?->toDateString(),
                'settled_at' => $settlementItem->settlement->settled_at?->toIso8601String(),
            ] : null,
            'financial_artifacts' => [
                'settlement_item' => $settlementItem ? [
                    'id' => $settlementItem->id,
                    'charge_amount' => (float) $settlementItem->charge_amount,
                    'paid_before_amount' => (float) $settlementItem->paid_before_amount,
                    'refund_amount' => (float) $settlementItem->refund_amount,
                    'adjustment_amount' => (float) $settlementItem->adjustment_amount,
                    'allocated_amount' => (float) $settlementItem->allocated_amount,
                    'outstanding_amount' => (float) $settlementItem->outstanding_amount,
                    'status' => $settlementItem->status,
                ] : null,
                'allocations' => $allocations->map(fn (FinancialPaymentAllocation $allocation) => [
                    'id' => $allocation->id,
                    'receipt_id' => $allocation->payment_receipt_id,
                    'amount' => (float) $allocation->amount,
                    'allocated_at' => $allocation->allocated_at?->toIso8601String(),
                ])->values(),
                'adjustments' => $adjustmentRows->map(fn (FinancialAdjustment $adjustment) => [
                    'id' => $adjustment->id,
                    'type' => $adjustment->type,
                    'amount' => (float) $adjustment->amount,
                    'reason' => $adjustment->reason,
                    'reference' => $adjustment->reference,
                    'created_at' => $adjustment->created_at?->toIso8601String(),
                ])->values(),
                'invoice' => $settlementDocument ? [
                    'id' => $settlementDocument->id,
                    'invoice_number' => $settlementDocument->invoice_number,
                    'status' => $settlementDocument->status,
                    'generated_at' => $settlementDocument->generated_at?->toIso8601String(),
                    'sent_at' => $settlementDocument->sent_at?->toIso8601String(),
                    'sent_to' => $settlementDocument->sent_to,
                    'download_available' => !empty($settlementDocument->pdf_path),
                    'has_error' => !empty($settlementDocument->last_error),
                ] : null,
            ],
            'financial_breakdown' => [
                'driver_earning' => round((float)($booking->driver_cost ?? 0),2),
                'company_commission' => round(max(0,$total-(float)($booking->driver_cost ?? 0)),2),
                'additional_charges' => round((float)($booking->addons_cost ?? 0)+(float)($booking->waiting_charge ?? 0),2),
                'discounts' => round((float)($booking->discount_amount ?? 0),2),
                'taxes' => round((float)($booking->tax_amount ?? 0),2),
                'refunds' => $refunds,
                'adjustments' => $adjustments,
                'company_collected' => round((float)$fareReceipts->where('received_via','company')->sum('amount'),2),
                'driver_collected' => round((float)$fareReceipts->where('received_via','driver')->sum('amount'),2),
            ],
            'collection_method' => $booking->payment_collection_method ?: 'cash_to_driver',
            'payment_responsibility' => $payer['type'],
            'payer_type' => $payer['type'],
            'payer_id' => $payer['id'],
            'payer_name' => $payer['name'],
            'is_corporate' => (bool) $booking->is_corporate_booking,
            'cash_custody' => [
                // These are custody views of receipt money, not additional revenue or payment.
                'driver_collected' => $driverCashCollected,
                'handed_over_to_company' => $driverCashHandedOver,
                'still_held_by_driver' => max(0, round($driverCashCollected - $driverCashHandedOver, 2)),
            ],
            'payment_schedule' => [
                'items' => $paymentSchedule,
                'scheduled_amount' => $scheduledAmount,
                'booking_total' => $total,
                'unscheduled_amount' => max(0, round($total - $scheduledAmount, 2)),
                'overscheduled_amount' => max(0, round($scheduledAmount - $total, 2)),
                'allocated_amount' => $scheduledPaid,
                'unallocated_received' => max(0, round($paid - $scheduledPaid, 2)),
                'overdue_amount' => round((float) $paymentSchedule->where('status', 'overdue')->sum('balance_amount'), 2),
                'next_due' => $paymentSchedule->whereIn('status', ['scheduled', 'partially_paid'])->first(),
            ],
            'receipts' => $receipts->map(fn (BookingPaymentReceipt $receipt) => [
                'id' => $receipt->id,
                'amount' => (float) $receipt->amount,
                'payment_method' => $receipt->payment_method,
                'payment_stage' => $receipt->payment_stage,
                'payment_purpose' => $receipt->payment_purpose,
                'refunded_amount' => (float) $receipt->refunded_amount,
                'refundable_balance' => $receipt->payment_purpose === 'security_deposit' ? max(0, (float) $receipt->amount - (float) $receipt->refunded_amount) : 0,
                'refunds' => $receipt->depositRefunds->map(fn (BookingDepositRefund $refund) => [
                    'id' => $refund->id,
                    'amount' => (float) $refund->amount,
                    'refund_method' => $refund->refund_method,
                    'reference' => $refund->reference,
                    'refunded_at' => $refund->refunded_at?->toIso8601String(),
                    'refunded_by' => $refund->refunded_by,
                    'notes' => $refund->notes,
                ])->values(),
                'reference' => $receipt->reference,
                'received_at' => $receipt->received_at?->toIso8601String(),
                'received_by' => $receipt->received_by,
                'notes' => $receipt->notes,
                'received_via' => $receipt->received_via,
                'driver_company_settlement_status' => $receipt->driver_company_settlement_status,
                'is_opening_balance' => (bool) data_get($receipt->metadata, 'opening_balance', false),
            ])->values(),
        ];
    }

    /**
     * Project the contractual payer without exposing the broader customer or
     * corporate record. Booking origin and payment responsibility are separate:
     * a corporate-origin booking may still be customer- or company-paid.
     *
     * @return array{type:string,id:?string,name:string}
     */
    private function resolvePayer(Booking $booking): array
    {
        $type = in_array($booking->payment_responsibility, ['customer', 'corporate', 'company'], true)
            ? $booking->payment_responsibility
            : ($booking->is_corporate_booking ? 'corporate' : 'customer');

        if ($type === 'corporate') {
            $id = $booking->corporate_account_id ? (string) $booking->corporate_account_id : null;

            return [
                'type' => 'corporate',
                'id' => $id,
                'name' => $id
                    ? (Corporate::query()->whereKey($id)->value('name') ?: 'Corporate account')
                    : 'Corporate account',
            ];
        }

        if ($type === 'company') {
            return ['type' => 'company', 'id' => null, 'name' => 'Company account'];
        }

        $id = $booking->customer_id ? (string) $booking->customer_id : null;
        $customer = $id ? Customer::with('user')->find($id) : null;
        $name = trim((string) (($customer?->user?->first_name ?? '') . ' ' . ($customer?->user?->last_name ?? '')))
            ?: ($customer?->code ?: 'Customer account');

        return ['type' => 'customer', 'id' => $id, 'name' => $name];
    }

    public function receive(Booking $booking, array $data, ?string $userId): array
    {
        return DB::transaction(function () use ($booking, $data, $userId) {
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            if (!empty($data['idempotency_key'])) {
                $duplicate = BookingPaymentReceipt::query()
                    ->where('idempotency_key', $data['idempotency_key'])
                    ->first();
                if ($duplicate) {
                    if ($duplicate->booking_id !== $booking->id) {
                        throw ValidationException::withMessages([
                            'idempotency_key' => ['This payment submission key already belongs to another booking.'],
                        ]);
                    }
                    return $this->summary($booking);
                }
            }
            $existing = BookingPaymentReceipt::query()->where('booking_id', $booking->id)
                ->whereIn('payment_purpose', ['booking_payment', 'service_deposit'])->exists();
            if (!$existing) {
                $legacy = $this->legacyPaidAmount($booking, $this->total($booking));
                if ($legacy > 0) {
                    $openingReceipt = BookingPaymentReceipt::create([
                        'booking_id' => $booking->id,
                        'amount' => $legacy,
                        'payment_method' => $booking->payment_method ?: 'legacy',
                        'payment_stage' => 'opening_balance',
                        'reference' => $booking->payment_reference,
                        'received_at' => $booking->payment_collected_at ?: $booking->updated_at,
                        'received_by' => $userId,
                        'notes' => 'Opening balance from the existing booking payment record.',
                        'metadata' => ['opening_balance' => true],
                        'received_via' => $booking->payment_collected_by_driver_id ? 'driver' : 'company',
                        'driver_id' => $booking->payment_collected_by_driver_id,
                        'driver_company_settlement_status' => $booking->payment_collected_by_driver_id ? 'unsettled' : 'not_applicable',
                    ]);
                    $this->allocateReceiptToSchedule($booking, $openingReceipt, $legacy, $userId);
                }
            }

            $current = $this->summary($booking);
            $amount = round((float) $data['amount'], 2);
            $purpose = $data['payment_purpose'] ?? 'booking_payment';
            if ($purpose !== 'security_deposit' && $amount > $current['due_amount']) {
                throw ValidationException::withMessages([
                    'amount' => ['The received amount cannot exceed the outstanding balance.'],
                ]);
            }
            if ($purpose === 'security_deposit') {
                $requiredSecurity = (float) $booking->bookingItems()->with('vehicleGroup')->get()
                    ->sum(fn ($item) => (float) ($item->vehicleGroup?->refundable_deposit ?? 0) * max(1, (int) ($item->quantity ?? 1)));
                if ($requiredSecurity <= 0) {
                    throw ValidationException::withMessages(['amount' => ['The selected vehicle group does not require a refundable security deposit.']]);
                }
            }

            $receipt = BookingPaymentReceipt::create([
                'booking_id' => $booking->id,
                'amount' => $amount,
                'payment_method' => $data['payment_method'],
                'payment_stage' => $data['payment_stage'],
                'payment_purpose' => $purpose,
                'reference' => $data['reference'] ?? null,
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'received_at' => $data['received_at'],
                'received_by' => $userId,
                'notes' => $data['notes'] ?? null,
                'received_via' => $data['received_via'] ?? 'company',
                'payer_type' => (bool) $booking->is_corporate_booking ? 'corporate' : 'customer',
                'payer_id' => (bool) $booking->is_corporate_booking ? $booking->corporate_account_id : $booking->customer_id,
                'corporate_remittance_id' => $data['corporate_remittance_id'] ?? null,
                'driver_id' => $data['driver_id'] ?? null,
                'driver_company_settlement_status' => ($data['received_via'] ?? 'company') === 'driver' ? 'unsettled' : 'not_applicable',
            ]);

            // Refundable security deposits are liabilities and never enter the commission ledger.
            if ($purpose !== 'security_deposit') {
                $staff = $booking->commission_owner_staff_id
                    ? Staff::query()->find($booking->commission_owner_staff_id)
                    : ($booking->created_user_id
                        ? Staff::query()->where('user_id', $booking->created_user_id)->first()
                        : null);
            } else {
                $staff = null;
            }
            if ($staff && $staff->collection_commission_enabled) {
                $rate = (float) $staff->collection_commission_rate;
                BookingCollectionCommission::create([
                    'booking_id' => $booking->id,
                    'booking_payment_receipt_id' => $receipt->id,
                    'staff_id' => $staff->id,
                    'receipt_amount' => $amount,
                    'eligible_amount' => $amount,
                    'commission_rate' => $rate,
                    'commission_amount' => round($amount * $rate / 100, 2),
                    'status' => 'earned',
                    'ineligibility_reason' => null,
                    'earned_at' => $data['received_at'],
                ]);
            }
            FinancialAuditEvent::create(['subject_type'=>'booking_payment','subject_id'=>$receipt->id,'booking_id'=>$booking->id,'event_type'=>'payment_received','from_status'=>$booking->payment_status,'amount'=>$amount,'metadata'=>['method'=>$data['payment_method'],'stage'=>$data['payment_stage'],'purpose'=>$purpose,'received_via'=>$data['received_via']??'company','reference'=>$data['reference']??null],'performed_by'=>$userId,'occurred_at'=>now()]);

            if ($purpose !== 'security_deposit') {
                $this->allocateReceiptToSchedule($booking, $receipt, $amount, $userId);
            }
            if ($purpose !== 'security_deposit' && !($data['skip_settlement_allocation'] ?? false)) {
                $item = FinancialSettlementItem::with('settlement')->where('booking_id', $booking->id)
                    ->whereHas('settlement', fn($query) => $query->whereNotIn('status', ['paid','void']))
                    ->latest('created_at')->first();
                if ($item && (float)$item->outstanding_amount > 0) {
                    $allocated = min($amount, (float)$item->outstanding_amount);
                    $allocation = FinancialPaymentAllocation::create([
                        'settlement_id'=>$item->settlement_id,'settlement_item_id'=>$item->id,'booking_id'=>$booking->id,
                        'payment_receipt_id'=>$receipt->id,'amount'=>$allocated,'allocated_by'=>$userId,'allocated_at'=>now(),
                    ]);
                    FinancialAuditEvent::create([
                        'subject_type' => 'payment_allocation', 'subject_id' => $allocation->id,
                        'booking_id' => $booking->id, 'event_type' => 'payment_allocated', 'amount' => $allocated,
                        'metadata' => ['settlement_id' => $item->settlement_id, 'settlement_item_id' => $item->id, 'receipt_id' => $receipt->id],
                        'performed_by' => $userId, 'occurred_at' => now(),
                    ]);
                    $newOutstanding=round((float)$item->outstanding_amount-$allocated,2);
                    $item->update(['allocated_amount'=>(float)$item->allocated_amount+$allocated,'outstanding_amount'=>$newOutstanding,'status'=>$newOutstanding<=0?'paid':'partial']);
                    $receipt->update(['allocated_amount'=>$allocated,'allocation_status'=>$allocated >= $amount?'allocated':'partially_allocated']);
                    $settlement=$item->settlement;
                    $settlementItems=$settlement->items()->get();
                    $settlementOutstanding=round((float)$settlementItems->sum('outstanding_amount'),2);
                    $settlementPayments=round((float)$settlementItems->sum(fn($row)=>(float)$row->paid_before_amount+(float)$row->allocated_amount),2);
                    $settlement->update(['payments_total'=>$settlementPayments,'outstanding_total'=>$settlementOutstanding,'status'=>$settlementOutstanding<=0?'paid':'partial','settled_at'=>$settlementOutstanding<=0?now():null]);
                }
            }

            $summary = $this->summary($booking);
            if ($purpose === 'security_deposit') {
                return $summary;
            }
            $booking->update([
                'payment_status' => $summary['payment_status'],
                'payment_collection_status' => $summary['due_amount'] <= 0 ? 'paid' : 'partially_paid',
                'payment_collected_amount' => $summary['paid_amount'],
                'payment_collected_at' => $data['received_at'],
                'payment_method' => $data['payment_method'],
                'payment_reference' => $data['reference'] ?? $booking->payment_reference,
                'payment_notes' => $data['notes'] ?? $booking->payment_notes,
                'amount_to_pay' => $summary['due_amount'],
                'payment_arrangement_status' => $summary['payment_arrangement_status'],
                'customer_settlement_status' => !$booking->is_corporate_booking ? ($summary['due_amount'] <= 0 ? 'settled' : 'open') : 'not_applicable',
                'corporate_settlement_status' => $booking->is_corporate_booking ? ($summary['due_amount'] <= 0 ? 'settled' : 'open') : 'not_applicable',
                'driver_collection_status' => ($data['received_via'] ?? 'company') === 'driver' ? 'collected_unsettled' : $booking->driver_collection_status,
                'settled_at' => $summary['due_amount'] <= 0 ? $data['received_at'] : null,
                'invoice_status' => $summary['due_amount'] <= 0 && $booking->invoice_status !== 'not_required' ? 'paid' : $booking->invoice_status,
            ]);

            return $summary;
        });
    }

    public function addScheduleItem(Booking $booking, array $data, ?string $userId): array
    {
        DB::transaction(function () use ($booking, $data, $userId) {
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            $scheduled = (float) BookingPaymentSchedule::query()->where('booking_id', $booking->id)->sum('amount');
            $total = $this->total($booking);
            $newAmount = round((float) $data['amount'], 2);
            if (round($scheduled + $newAmount, 2) > $total) {
                throw ValidationException::withMessages([
                    'amount' => ['Scheduled payments cannot exceed the booking total. Adjust the booking total or remaining schedule first.'],
                ]);
            }
            $sequence = (int) BookingPaymentSchedule::query()->where('booking_id', $booking->id)->max('sequence') + 1;
            BookingPaymentSchedule::create([
                'booking_id' => $booking->id,
                'sequence' => $sequence,
                'label' => $data['label'] ?? null,
                'period_start' => $data['period_start'] ?? null,
                'period_end' => $data['period_end'] ?? null,
                'due_date' => $data['due_date'],
                'amount' => $newAmount,
                'notes' => $data['notes'] ?? null,
                'created_user_id' => $userId,
            ]);
            BookingPaymentReceipt::query()
                ->where('booking_id', $booking->id)
                ->whereIn('payment_purpose', ['booking_payment', 'service_deposit'])
                ->orderBy('received_at')
                ->each(fn (BookingPaymentReceipt $receipt) =>
                    $this->allocateReceiptToSchedule($booking, $receipt, (float) $receipt->amount, $userId)
                );
        });
        return $this->summary($booking->fresh());
    }

    public function generateSchedule(Booking $booking, array $data, ?string $userId): array
    {
        DB::transaction(function () use ($booking, $data, $userId) {
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            if (BookingPaymentSchedule::query()->where('booking_id', $booking->id)->exists()) {
                throw ValidationException::withMessages([
                    'frequency' => ['A payment schedule already exists. Revise the existing schedule instead of generating duplicates.'],
                ]);
            }
            $frequency = $data['frequency'];
            $months = match ($frequency) {
                'monthly' => 1,
                'every_two_months' => 2,
                'every_six_months' => 6,
                'full_payment' => 0,
                default => throw ValidationException::withMessages(['frequency' => ['Select a supported automatic frequency.']]),
            };
            $total = $this->total($booking);
            $installment = $frequency === 'full_payment'
                ? $total
                : round((float) ($data['installment_amount'] ?? 0), 2);
            if ($installment <= 0) {
                throw ValidationException::withMessages(['installment_amount' => ['Enter an installment amount above zero.']]);
            }
            $dueDate = Carbon::parse($data['start_date'])->startOfDay();
            $remaining = $total;
            $sequence = 1;
            while ($remaining > 0) {
                $amount = min($installment, $remaining);
                BookingPaymentSchedule::create([
                    'booking_id' => $booking->id,
                    'sequence' => $sequence,
                    'label' => $frequency === 'full_payment' ? 'Full payment' : "Installment {$sequence}",
                    'due_date' => $dueDate->toDateString(),
                    'amount' => $amount,
                    'created_user_id' => $userId,
                ]);
                $remaining = round($remaining - $amount, 2);
                $sequence++;
                if ($months === 0 || $sequence > 600) break;
                $dueDate = $dueDate->copy()->addMonthsNoOverflow($months);
            }
            $booking->update([
                'payment_schedule_frequency' => $frequency,
                'payment_schedule_start_date' => $data['start_date'],
                'payment_schedule_installment_amount' => $installment,
                'payment_schedule_reminder_days' => $data['reminder_days'] ?? 3,
            ]);
            BookingPaymentReceipt::query()
                ->where('booking_id', $booking->id)
                ->whereIn('payment_purpose', ['booking_payment', 'service_deposit'])
                ->orderBy('received_at')
                ->each(fn (BookingPaymentReceipt $receipt) =>
                    $this->allocateReceiptToSchedule($booking, $receipt, (float) $receipt->amount, $userId)
                );
        });
        return $this->summary($booking->fresh());
    }

    private function allocateReceiptToSchedule(
        Booking $booking,
        BookingPaymentReceipt $receipt,
        float $amount,
        ?string $userId
    ): void {
        $alreadyAllocated = (float) BookingPaymentScheduleAllocation::query()
            ->where('booking_payment_receipt_id', $receipt->id)->sum('amount');
        $remaining = max(0, round($amount - $alreadyAllocated, 2));
        $schedules = BookingPaymentSchedule::query()
            ->where('booking_id', $booking->id)
            ->withSum('allocations', 'amount')
            ->orderBy('due_date')
            ->orderBy('sequence')
            ->lockForUpdate()
            ->get();
        foreach ($schedules as $schedule) {
            if ($remaining <= 0) break;
            $balance = max(0, round((float) $schedule->amount - (float) ($schedule->allocations_sum_amount ?? 0), 2));
            if ($balance <= 0) continue;
            $allocated = min($remaining, $balance);
            BookingPaymentScheduleAllocation::create([
                'booking_payment_schedule_id' => $schedule->id,
                'booking_payment_receipt_id' => $receipt->id,
                'amount' => $allocated,
                'allocated_at' => $receipt->received_at,
                'allocated_by' => $userId,
            ]);
            $schedule->update([
                'status' => round((float) ($schedule->allocations_sum_amount ?? 0) + $allocated, 2) >= (float) $schedule->amount
                    ? 'paid'
                    : 'partially_paid',
            ]);
            $remaining = round($remaining - $allocated, 2);
        }
    }


    public function refundSecurityDeposit(Booking $booking, BookingPaymentReceipt $receipt, array $data, ?string $userId): array
    {
        return DB::transaction(function () use ($booking, $receipt, $data, $userId) {
            $receipt = BookingPaymentReceipt::query()->lockForUpdate()->findOrFail($receipt->id);
            if ($receipt->booking_id !== $booking->id || $receipt->payment_purpose !== 'security_deposit') {
                throw ValidationException::withMessages(['receipt' => ['Only a refundable vehicle security deposit receipt can be refunded.']]);
            }
            $amount = round((float) $data['amount'], 2);
            $available = max(0, round((float) $receipt->amount - (float) $receipt->refunded_amount, 2));
            if ($amount > $available) {
                throw ValidationException::withMessages(['amount' => ["Only {$available} remains available to refund from this deposit."]]);
            }
            $refund = BookingDepositRefund::create([
                'booking_payment_receipt_id' => $receipt->id,
                'booking_id' => $booking->id,
                'amount' => $amount,
                'refund_method' => $data['refund_method'],
                'reference' => $data['reference'] ?? null,
                'refunded_at' => $data['refunded_at'],
                'refunded_by' => $userId,
                'notes' => $data['notes'] ?? null,
            ]);
            $receipt->increment('refunded_amount', $amount);
            FinancialAuditEvent::create([
                'subject_type' => 'security_deposit_refund', 'subject_id' => $refund->id, 'booking_id' => $booking->id,
                'event_type' => 'security_deposit_refunded', 'amount' => $amount,
                'metadata' => ['receipt_id' => $receipt->id, 'method' => $data['refund_method'], 'reference' => $data['reference'] ?? null],
                'performed_by' => $userId, 'occurred_at' => now(),
            ]);
            return $this->summary($booking->fresh());
        });
    }

    private function total(Booking $booking): float
    {
        return round((float) ($booking->total_actual ?? $booking->total_estimated ?? $booking->amount_to_pay ?? 0), 2);
    }

    private function legacyPaidAmount(Booking $booking, float $total): float
    {
        if ($booking->payment_collected_amount !== null) {
            return min($total, max(0, round((float) $booking->payment_collected_amount, 2)));
        }
        return in_array(strtolower((string) $booking->payment_status), ['paid', 'success', 'online_paid'], true) ? $total : 0.0;
    }

    private function resolveArrangementStatus(Booking $booking): string
    {
        $method = strtolower((string) $booking->payment_collection_method);
        return match ($method) {
            'online' => 'online_payment',
            'monthly_invoice' => 'corporate_credit',
            'account_credit' => 'customer_credit',
            'advance_then_balance' => 'advance_then_balance',
            'deposit_then_balance' => 'deposit_then_balance',
            'pay_at_end' => 'due_at_hire_end',
            'complimentary', 'waived' => 'complimentary',
            default => 'driver_collection',
        };
    }

    private function resolvePaymentStatus(Booking $booking, float $paid, float $due): string
    {
        if ($this->resolveArrangementStatus($booking) === 'complimentary') return 'waived';
        if ($due <= 0) return 'paid';
        if ($paid > 0) return 'partially_paid';
        return match ($this->resolveArrangementStatus($booking)) {
            'corporate_credit' => 'corporate_account',
            'customer_credit' => 'credit_terms',
            'online_payment' => 'online_payment_due',
            'advance_then_balance', 'deposit_then_balance' => 'advance_due',
            'due_at_hire_end' => 'due_at_hire_end',
            'complimentary' => 'waived',
            default => 'collection_due',
        };
    }
}
