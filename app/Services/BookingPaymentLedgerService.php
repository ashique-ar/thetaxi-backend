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
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Carbon;
use App\Models\Corporate\Corporate;
use App\Models\Customer;
use App\Models\Finance\FinancialSettlementItem;
use App\Models\Finance\FinancialPaymentAllocation;
use App\Models\Finance\FinancialAuditEvent;
use App\Models\Finance\FinancialAdjustment;
use App\Models\Finance\FinancialSettlementDocument;
use App\Models\Booking\BookingPaymentReceiptComponent;
use App\Models\Sales\SalesBookingAttribution;
use App\Models\Booking\BookingCollectionWorkItem;
use App\Models\Booking\BookingPaymentReceiptFinalityEvent;
use App\Models\Booking\BookingPaymentFinalityPolicy;
use App\Services\Sales\CommissionDecisionService;
use App\Services\Sales\CommissionHoldService;
use App\Services\Sales\SalesMetricFactService;
use App\Models\Booking\BookingPaymentScheduleRule;
use App\Services\Sales\RollingPaymentScheduleService;
use App\Services\Sales\SalesPolicySettingsService;
use Carbon\CarbonInterface;

class BookingPaymentLedgerService
{
    public function __construct(
        private readonly CommissionDecisionService $commissionDecisions,
        private readonly CommissionHoldService $commissionHolds,
        private readonly SalesMetricFactService $metricFacts,
        private readonly RollingPaymentScheduleService $rollingSchedules,
        private readonly SalesPolicySettingsService $policySettings,
    ) {}

    public function createRollingScheduleRule(Booking $booking, array $data, string $actorUserId): array
    {
        $result = $this->rollingSchedules->createRule($booking, $data, $actorUserId);
        $this->allocateConfirmedReceiptsToNewSchedules($booking, $actorUserId);

        return $result;
    }

    public function extendRollingScheduleRule(
        BookingPaymentScheduleRule $rule,
        CarbonInterface $asOf,
        ?string $actorUserId = null,
        bool $dryRun = false,
    ): array {
        $result = $this->rollingSchedules->extendRule($rule, $asOf, $actorUserId, $dryRun);
        if (! $dryRun && (int) $result['generated_count'] > 0) {
            $this->allocateConfirmedReceiptsToNewSchedules($rule->booking, $actorUserId);
        }

        return $result;
    }

    public function transitionRollingScheduleRule(Booking $booking, array $data, string $actorUserId): array
    {
        return $this->rollingSchedules->transitionRule($booking, $data, $actorUserId);
    }

    public function allocateConfirmedReceiptsToSchedules(Booking $booking, ?string $actorUserId = null): void
    {
        $this->allocateConfirmedReceiptsToNewSchedules($booking, $actorUserId);
    }

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
            'total_charged' => round((float) $bookingSummaries->sum(fn (array $summary) =>
                $summary['contract_basis'] === 'open_ended'
                    ? (float) $summary['generated_horizon_value']
                    : (float) $summary['total_amount']
            ), 2),
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
        $confirmedFareReceipts = $fareReceipts->where('finality_status', 'confirmed');
        $pendingFareReceipts = $fareReceipts->whereIn('finality_status', ['pending_clearance', 'policy_missing']);
        $securityReceipts = $receipts->where('payment_purpose', 'security_deposit');
        $confirmedSecurityReceipts = $securityReceipts->where('finality_status', 'confirmed');
        $securityReceived = round((float) $confirmedSecurityReceipts->sum('amount'), 2);
        $securityRefunded = round((float) $confirmedSecurityReceipts->sum('refunded_amount'), 2);
        $securityHeld = max(0, round($securityReceived - $securityRefunded, 2));
        $requiredSecurity = (float) $booking->bookingItems()->with('vehicleGroup')->get()
            ->sum(fn ($item) => (float) ($item->vehicleGroup?->refundable_deposit ?? 0) * max(1, (int) ($item->quantity ?? 1)));
        $total = round((float) ($booking->total_actual ?? $booking->total_estimated ?? $booking->amount_to_pay ?? 0), 2);
        $ledgerPaid = round((float) $confirmedFareReceipts->sum(fn ($receipt) => (float) $receipt->amount - (float) $receipt->refunded_amount), 2);
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
        $driverReceipts = $receipts->where('received_via', 'driver')->where('finality_status', 'confirmed');
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
        $openEndedRule = Schema::hasTable('booking_payment_schedule_rules')
            ? BookingPaymentScheduleRule::query()->where('booking_id', $booking->id)->first()
            : null;
        if ($openEndedRule?->contract_basis === 'open_ended') {
            $due = $arrangement === 'complimentary'
                ? 0.0
                : max(0, round($scheduledAmount + $adjustments - $refunds - $paid, 2));
            $status = $this->resolvePaymentStatus($booking, $paid, $due);
        }
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
            'total_amount_basis' => $openEndedRule ? 'legacy_first_month_compatibility' : 'fixed_contract',
            'contract_basis' => $openEndedRule?->contract_basis ?? 'fixed_term',
            'lifetime_contract_value' => $openEndedRule ? null : $total,
            'monthly_run_rate' => $openEndedRule ? (float) $openEndedRule->source_amount : null,
            'generated_horizon_value' => $openEndedRule ? $scheduledAmount : null,
            'generated_horizon_start' => $openEndedRule ? $scheduleRows->min(fn ($row) => $row->due_date?->toDateString()) : null,
            'generated_horizon_end' => $openEndedRule ? $scheduleRows->max(fn ($row) => $row->due_date?->toDateString()) : null,
            'paid_amount' => $paid,
            'service_deposit_received' => round((float) $confirmedFareReceipts->where('payment_purpose', 'service_deposit')->sum(fn ($receipt) => (float) $receipt->amount - (float) $receipt->refunded_amount), 2),
            'booking_payments_received' => round((float) $confirmedFareReceipts->where('payment_purpose', 'booking_payment')->sum(fn ($receipt) => (float) $receipt->amount - (float) $receipt->refunded_amount), 2),
            'pending_collection_amount' => round((float) $pendingFareReceipts->sum(fn ($receipt) => (float) $receipt->amount - (float) $receipt->refunded_amount), 2),
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
                'company_collected' => round((float)$confirmedFareReceipts->where('received_via','company')->sum(fn ($receipt) => (float) $receipt->amount - (float) $receipt->refunded_amount),2),
                'driver_collected' => round((float)$confirmedFareReceipts->where('received_via','driver')->sum(fn ($receipt) => (float) $receipt->amount - (float) $receipt->refunded_amount),2),
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
                'booking_total' => $openEndedRule ? null : $total,
                'unscheduled_amount' => $openEndedRule ? 0.0 : max(0, round($total - $scheduledAmount, 2)),
                'overscheduled_amount' => $openEndedRule ? 0.0 : max(0, round($scheduledAmount - $total, 2)),
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
                'finality_status' => $receipt->finality_status,
                'finalized_at' => $receipt->finalized_at?->toIso8601String(),
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
                    $incomingChecksum = $this->receiptPayloadChecksum($booking, $data);
                    if ($duplicate->request_payload_checksum
                        && ! hash_equals((string) $duplicate->request_payload_checksum, $incomingChecksum)) {
                        throw ValidationException::withMessages([
                            'idempotency_key' => ['This payment submission key was already used with different receipt facts.'],
                        ]);
                    }
                    return $this->summary($booking);
                }
            }
            if (! empty($data['provider_event_id'])) {
                $providerDuplicate = BookingPaymentReceipt::query()
                    ->where('payment_method', $data['payment_method'])
                    ->where('provider_event_id', $data['provider_event_id'])
                    ->first();
                if ($providerDuplicate) {
                    $sameBooking = $providerDuplicate->booking_id === $booking->id;
                    $samePayload = ! $providerDuplicate->provider_payload_checksum
                        || ! isset($data['provider_payload_checksum'])
                        || hash_equals((string) $providerDuplicate->provider_payload_checksum, (string) $data['provider_payload_checksum']);
                    abort_unless($sameBooking && $samePayload, 422,
                        'This provider event was already recorded with different booking or payload facts.');
                    return $this->summary($booking);
                }
            }
            $existing = BookingPaymentReceipt::query()->where('booking_id', $booking->id)
                ->whereIn('payment_purpose', ['booking_payment', 'service_deposit'])->exists();
            if (!$existing) {
                $legacy = $this->legacyPaidAmount($booking, $this->total($booking));
                if ($legacy > 0) {
                    if (config('sales.features.canonical_receipts_v2', false)) {
                        throw ValidationException::withMessages([
                            'booking' => ['Legacy paid-state evidence must be reconciled through the controlled repair workflow before recording another receipt.'],
                        ]);
                    }
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
                        ...$this->canonicalReceiptFields($booking, [
                            'amount' => $legacy,
                            'payment_method' => $booking->payment_method ?: 'legacy',
                            'received_at' => $booking->payment_collected_at ?: $booking->updated_at,
                        ]),
                    ]);
                    $this->createReceiptComponent($openingReceipt, 'booking_payment', $legacy);
                    $this->allocateReceiptToSchedule($booking, $openingReceipt, $legacy, $userId);
                }
            }

            $current = $this->summary($booking);
            $amount = round((float) $data['amount'], 2);
            $purpose = $data['payment_purpose'] ?? 'booking_payment';
            $availableToSubmit = max(0, round($current['due_amount'] - (float) ($current['pending_collection_amount'] ?? 0), 2));
            if ($purpose !== 'security_deposit' && $amount > $availableToSubmit) {
                throw ValidationException::withMessages([
                    'amount' => ['The received amount cannot exceed the outstanding balance after pending-clearance receipts.'],
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
                ...$this->canonicalReceiptFields($booking, $data + ['amount' => $amount]),
            ]);
            $component = $this->createReceiptComponent($receipt, $purpose, $amount);

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
            if (! config('sales.features.commission_accrual', false)
                && $staff && $staff->collection_commission_enabled
                && $component->is_commission_eligible && $receipt->finality_status === 'confirmed') {
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
            if ($component->is_commission_eligible) {
                $this->commissionDecisions->decide($receipt, $component);
            }
            if ($receipt->finality_status === 'confirmed') {
                $this->metricFacts->projectConfirmedCollection($receipt, $component);
            }
            FinancialAuditEvent::create(['subject_type'=>'booking_payment','subject_id'=>$receipt->id,'booking_id'=>$booking->id,'event_type'=>'payment_received','from_status'=>$booking->payment_status,'amount'=>$amount,'metadata'=>['method'=>$data['payment_method'],'stage'=>$data['payment_stage'],'purpose'=>$purpose,'received_via'=>$data['received_via']??'company','reference'=>$data['reference']??null],'performed_by'=>$userId,'occurred_at'=>now()]);

            if ($purpose !== 'security_deposit' && $receipt->finality_status === 'confirmed') {
                $this->allocateReceiptToSchedule($booking, $receipt, $amount, $userId);
            }
            if ($purpose !== 'security_deposit' && $receipt->finality_status === 'confirmed' && !($data['skip_settlement_allocation'] ?? false)) {
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

    public function repairLegacyPaidBooking(Booking $booking, array $data, string $actorUserId): array
    {
        return DB::transaction(function () use ($booking, $data, $actorUserId) {
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            $duplicate = BookingPaymentReceipt::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($duplicate) {
                abort_unless($duplicate->booking_id === $booking->id, 422, 'This repair key belongs to another booking.');
                return $this->summary($booking);
            }
            abort_if(BookingPaymentReceipt::query()->where('booking_id', $booking->id)->exists(), 422,
                'The booking already has receipt evidence. Use receipt-component reconciliation instead of creating an opening receipt.');
            $legacyAmount = $this->legacyPaidAmount($booking, $this->total($booking));
            abort_if($legacyAmount <= 0, 422, 'The booking has no legacy paid balance to reconcile.');
            if (abs($legacyAmount - round((float) $data['source_amount'], 2)) > 0.01) {
                throw ValidationException::withMessages([
                    'source_amount' => ["The repair amount must equal the legacy paid balance of {$legacyAmount}."],
                ]);
            }
            $attribution = SalesBookingAttribution::query()->where('booking_id', $booking->id)->first();
            abort_unless($attribution?->company_id, 422, 'Resolve Sales attribution and legal entity before repairing payment evidence.');
            if (strtoupper((string) $data['source_currency']) !== 'LKR' && empty($data['fx_rate_to_lkr'])) {
                throw ValidationException::withMessages(['fx_rate_to_lkr' => ['A verified LKR rate is required for a non-LKR repair.']]);
            }

            $receiptData = [
                ...$data,
                'amount' => $legacyAmount,
                'payment_stage' => 'opening_balance',
                'payment_purpose' => 'booking_payment',
                'received_via' => $data['received_via'] ?? ($booking->payment_collected_by_driver_id ? 'driver' : 'company'),
            ];
            $receipt = BookingPaymentReceipt::create([
                'booking_id' => $booking->id,
                'amount' => $legacyAmount,
                'payment_method' => $data['payment_method'],
                'payment_stage' => 'opening_balance',
                'payment_purpose' => 'booking_payment',
                'reference' => $data['reference'],
                'idempotency_key' => $data['idempotency_key'],
                'received_at' => $data['received_at'],
                'received_by' => $actorUserId,
                'notes' => $data['notes'],
                'metadata' => ['opening_balance' => true, 'reconciliation_repair' => true, 'commission_backfill_allowed' => false],
                'received_via' => $receiptData['received_via'],
                'payer_type' => (bool) $booking->is_corporate_booking ? 'corporate' : 'customer',
                'payer_id' => (bool) $booking->is_corporate_booking ? $booking->corporate_account_id : $booking->customer_id,
                'driver_id' => $booking->payment_collected_by_driver_id,
                'driver_company_settlement_status' => $booking->payment_collected_by_driver_id ? 'unsettled' : 'not_applicable',
                ...$this->canonicalReceiptFields($booking, $receiptData),
            ]);
            $this->createReceiptComponent($receipt, 'booking_payment', $legacyAmount);
            if ($receipt->finality_status === 'confirmed') {
                $this->allocateReceiptToSchedule($booking, $receipt, $legacyAmount, $actorUserId);
            }
            FinancialAuditEvent::create([
                'subject_type' => 'booking_payment', 'subject_id' => $receipt->id, 'booking_id' => $booking->id,
                'event_type' => 'legacy_payment_receipt_repaired', 'amount' => $legacyAmount,
                'metadata' => ['reference' => $data['reference'], 'commission_backfill_allowed' => false],
                'performed_by' => $actorUserId, 'occurred_at' => now(),
            ]);

            return $this->summary($booking);
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
                ...$this->canonicalScheduleFields($booking, $newAmount, 'custom'),
            ]);
            BookingPaymentReceipt::query()
                ->where('booking_id', $booking->id)
                ->whereIn('payment_purpose', ['booking_payment', 'service_deposit'])
                ->where('finality_status', 'confirmed')
                ->orderBy('received_at')
                ->each(fn (BookingPaymentReceipt $receipt) =>
                    $this->allocateReceiptToSchedule($booking, $receipt, max(0, (float) $receipt->amount - (float) $receipt->refunded_amount), $userId)
                );
            $this->ensureCollectionWorkItems($booking);
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
                    ...$this->canonicalScheduleFields(
                        $booking,
                        $amount,
                        $frequency === 'full_payment' ? 'initial' : ($frequency === 'monthly' ? 'monthly' : 'custom')
                    ),
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
                ->where('finality_status', 'confirmed')
                ->orderBy('received_at')
                ->each(fn (BookingPaymentReceipt $receipt) =>
                    $this->allocateReceiptToSchedule($booking, $receipt, max(0, (float) $receipt->amount - (float) $receipt->refunded_amount), $userId)
                );
            $this->ensureCollectionWorkItems($booking, (int) ($data['reminder_days'] ?? 3));
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

        $allocatedTotal = round($amount - $remaining, 4);
        $receipt->components()->where('is_allocatable', true)->orderBy('id')->each(function (BookingPaymentReceiptComponent $component) use (&$allocatedTotal) {
            if ($allocatedTotal <= 0) {
                return;
            }
            $available = max(0, round((float) $component->source_amount - (float) $component->adjusted_source_amount, 4));
            $componentAllocated = min($available, $allocatedTotal);
            $component->update(['allocated_source_amount' => $componentAllocated]);
            $allocatedTotal = round($allocatedTotal - $componentAllocated, 4);
        });
    }

    private function allocateConfirmedReceiptsToNewSchedules(Booking $booking, ?string $actorUserId): void
    {
        DB::transaction(function () use ($booking, $actorUserId): void {
            $booking = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            BookingPaymentReceipt::query()
                ->where('booking_id', $booking->id)
                ->whereIn('payment_purpose', ['booking_payment', 'service_deposit'])
                ->where('finality_status', 'confirmed')
                ->orderBy('received_at')
                ->orderBy('id')
                ->each(fn (BookingPaymentReceipt $receipt) => $this->allocateReceiptToSchedule(
                    $booking,
                    $receipt,
                    max(0, (float) $receipt->amount - (float) $receipt->refunded_amount),
                    $actorUserId,
                ));
        });
    }

    private function canonicalScheduleFields(Booking $booking, float $amount, string $kind): array
    {
        $attribution = SalesBookingAttribution::query()->where('booking_id', $booking->id)->first();
        $currency = strtoupper((string) ($booking->currency ?? 'LKR'));

        return [
            'company_id' => $attribution?->company_id,
            'schedule_kind' => $kind,
            'source_amount' => round($amount, 4),
            'source_currency' => $currency,
            'lkr_amount' => $currency === 'LKR' ? round($amount, 4) : null,
            'is_collection_target_eligible' => true,
            'collection_sales_profile_id' => $attribution?->collection_sales_profile_id,
            'revision_number' => 1,
        ];
    }

    private function ensureCollectionWorkItems(Booking $booking, int $reminderDays = 3): void
    {
        BookingPaymentSchedule::query()->where('booking_id', $booking->id)->whereNull('superseded_at')
            ->each(function (BookingPaymentSchedule $schedule) use ($reminderDays): void {
                BookingCollectionWorkItem::firstOrCreate([
                    'idempotency_key' => 'schedule-collection:'.$schedule->id,
                ], [
                    'company_id' => $schedule->company_id,
                    'booking_id' => $schedule->booking_id,
                    'booking_payment_schedule_id' => $schedule->id,
                    'assigned_sales_profile_id' => $schedule->collection_sales_profile_id,
                    'work_type' => 'collect_installment',
                    'status' => 'open',
                    'due_at' => $schedule->due_date->endOfDay(),
                    'reminder_offset_days' => $reminderDays,
                ]);
            });
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

    private function canonicalReceiptFields(Booking $booking, array $data): array
    {
        $attribution = SalesBookingAttribution::query()->where('booking_id', $booking->id)->first();
        $sourceAmount = round((float) ($data['source_amount'] ?? $data['amount']), 4);
        $sourceCurrency = strtoupper((string) ($data['source_currency'] ?? $booking->currency ?? 'LKR'));
        $fxRate = $sourceCurrency === 'LKR' ? 1.0 : (isset($data['fx_rate_to_lkr']) ? (float) $data['fx_rate_to_lkr'] : null);
        $finality = $this->resolveFinality($attribution?->company_id, strtolower((string) $data['payment_method']), $data['received_at']);
        if (config('sales.features.canonical_receipts_v2', false) && ! $attribution?->company_id) {
            throw ValidationException::withMessages(['booking' => ['Sales attribution and legal entity are required before a canonical receipt can be activated.']]);
        }
        if (config('sales.features.canonical_receipts_v2', false) && empty($data['idempotency_key'])) {
            throw ValidationException::withMessages([
                'idempotency_key' => ['A persistent payment source identity is required for canonical receipts.'],
            ]);
        }
        if ($sourceCurrency !== 'LKR' && $fxRate === null && config('sales.features.canonical_receipts_v2', false)) {
            throw ValidationException::withMessages(['fx_rate_to_lkr' => ['An approved LKR conversion rate is required for non-LKR collections.']]);
        }

        return [
            'company_id' => $attribution?->company_id,
            'source_amount' => $sourceAmount,
            'source_currency' => $sourceCurrency,
            'lkr_amount' => $fxRate !== null ? round($sourceAmount * $fxRate, 4) : null,
            'fx_rate_to_lkr' => $fxRate,
            'fx_rate_at' => $fxRate !== null ? ($data['fx_rate_at'] ?? $data['received_at']) : null,
            'fx_source' => $sourceCurrency === 'LKR' ? 'identity' : ($data['fx_source'] ?? null),
            'finality_status' => $finality['status'],
            'initial_finality_status' => $finality['status'],
            'finalized_at' => $finality['status'] === 'confirmed' ? ($data['finalized_at'] ?? $data['received_at']) : null,
            'provider_event_id' => $data['provider_event_id'] ?? null,
            'provider_payload_checksum' => $data['provider_payload_checksum'] ?? null,
            'request_payload_checksum' => $this->receiptPayloadChecksum($booking, $data),
            'event_version' => 1,
        ];
    }

    private function receiptPayloadChecksum(Booking $booking, array $data): string
    {
        $facts = [
            'booking_id' => $booking->id,
            'amount' => number_format((float) ($data['source_amount'] ?? $data['amount']), 4, '.', ''),
            'currency' => strtoupper((string) ($data['source_currency'] ?? $booking->currency ?? 'LKR')),
            'payment_method' => (string) $data['payment_method'],
            'payment_stage' => (string) ($data['payment_stage'] ?? 'part_payment'),
            'payment_purpose' => (string) ($data['payment_purpose'] ?? 'booking_payment'),
            'reference' => $data['reference'] ?? null,
            'received_via' => (string) ($data['received_via'] ?? 'company'),
            'provider_event_id' => $data['provider_event_id'] ?? null,
            'received_at' => (string) $data['received_at'],
            'fx_rate_to_lkr' => isset($data['fx_rate_to_lkr'])
                ? number_format((float) $data['fx_rate_to_lkr'], 10, '.', '') : null,
            'fx_rate_at' => isset($data['fx_rate_at']) ? (string) $data['fx_rate_at'] : null,
            'fx_source' => $data['fx_source'] ?? null,
        ];

        return hash('sha256', json_encode($facts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function createReceiptComponent(BookingPaymentReceipt $receipt, string $purpose, float $amount): BookingPaymentReceiptComponent
    {
        $eligible = in_array($purpose, ['booking_payment', 'service_deposit'], true);

        return BookingPaymentReceiptComponent::create([
            'receipt_id' => $receipt->id,
            'component_type' => $purpose,
            'source_amount' => $receipt->source_amount ?? $amount,
            'lkr_amount' => $receipt->lkr_amount,
            'is_allocatable' => true,
            'is_collection_target_eligible' => $eligible,
            'is_commission_eligible' => $eligible,
        ]);
    }

    private function resolveFinality(?string $companyId, string $paymentMethod, $receivedAt): array
    {
        $policy = $companyId ? DB::table('booking_payment_finality_policies')
            ->where('company_id', $companyId)
            ->where('payment_method', $paymentMethod)
            ->where('status', 'approved')
            ->where('effective_from', '<=', $receivedAt)
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $receivedAt))
            ->orderByDesc('version')
            ->first() : null;

        if ($policy) {
            return ['status' => $policy->official_collection_state];
        }

        return ['status' => $companyId && $this->policySettings->featureEnabled($companyId, 'enforce_payment_finality')
            ? 'policy_missing' : 'confirmed'];
    }

    public function transitionReceiptFinality(
        BookingPaymentReceipt $receipt,
        string $toStatus,
        string $reason,
        ?string $evidenceReference,
        string $idempotencyKey,
        string $actorUserId
    ): BookingPaymentReceipt {
        return DB::transaction(function () use ($receipt, $toStatus, $reason, $evidenceReference, $idempotencyKey, $actorUserId) {
            $receipt = BookingPaymentReceipt::query()->lockForUpdate()->findOrFail($receipt->id);
            $duplicate = BookingPaymentReceiptFinalityEvent::query()
                ->where('booking_payment_receipt_id', $receipt->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($duplicate) {
                abort_unless($duplicate->to_status === $toStatus, 422, 'This finality key was already used for another transition.');
                return $receipt;
            }
            abort_if($receipt->finality_status === $toStatus, 422, 'The receipt already has the requested finality status.');
            $knownStatuses = ['pending_clearance', 'policy_missing', 'confirmed', 'failed'];
            $allowed = [
                'pending_clearance' => ['confirmed', 'failed'],
                'policy_missing' => ['pending_clearance', 'confirmed', 'failed'],
                // Confirmed cash is corrected only through a typed cash adjustment,
                // preserving original-credit and commission-reversal lineage.
                'confirmed' => [],
                'failed' => [],
            ];
            // A receipt whose current status is not one of the four states this state machine
            // recognizes (a payment_finality_unknown commission hold's exact trigger) has no key
            // in $allowed above and could otherwise never be governed-transitioned out of it at all.
            // Treat it as permissively as the most-open known state so it can be reclassified.
            $allowedTargets = in_array($receipt->finality_status, $knownStatuses, true)
                ? ($allowed[$receipt->finality_status] ?? [])
                : ['pending_clearance', 'confirmed', 'failed'];
            abort_unless(in_array($toStatus, $allowedTargets, true), 422, 'The requested receipt-finality transition is not allowed.');

            $finalityPolicy = null;
            if ((in_array($receipt->finality_status, ['policy_missing', 'pending_clearance'], true)
                    || ! in_array($receipt->finality_status, $knownStatuses, true))
                && $toStatus === 'confirmed') {
                $policies = BookingPaymentFinalityPolicy::query()
                    ->where('company_id', $receipt->company_id)
                    ->where('payment_method', strtolower((string) $receipt->payment_method))
                    ->where('status', 'approved')
                    ->whereNotNull('approved_by')->whereNotNull('approved_at')
                    ->where('effective_from', '<=', $receipt->received_at)
                    ->where(fn ($query) => $query->whereNull('effective_until')
                        ->orWhere('effective_until', '>', $receipt->received_at))
                    ->lockForUpdate()->get();
                abort_unless($policies->count() === 1, 422,
                    'Exactly one approved payment-finality policy must cover the original receipt timestamp.');
                $finalityPolicy = $policies->first();
                abort_if($finalityPolicy->created_by === $finalityPolicy->approved_by, 422,
                    'The payment-finality policy must have separate maker and checker evidence.');
            }

            $event = BookingPaymentReceiptFinalityEvent::create([
                'company_id' => $receipt->company_id,
                'booking_id' => $receipt->booking_id,
                'booking_payment_receipt_id' => $receipt->id,
                'finality_policy_id' => $finalityPolicy?->id,
                'from_status' => $receipt->finality_status,
                'to_status' => $toStatus,
                'reason' => $reason,
                'evidence_reference' => $evidenceReference,
                'idempotency_key' => $idempotencyKey,
                'performed_by' => $actorUserId,
                'occurred_at' => now(),
            ]);
            $receipt->update([
                'finality_status' => $toStatus,
                'finalized_at' => $toStatus === 'confirmed' ? now() : null,
                'event_version' => (int) $receipt->event_version + 1,
            ]);

            $booking = Booking::query()->lockForUpdate()->findOrFail($receipt->booking_id);
            if ($toStatus === 'confirmed' && $receipt->payment_purpose !== 'security_deposit') {
                $netAmount = max(0, round((float) $receipt->amount - (float) $receipt->refunded_amount, 2));
                $this->allocateReceiptToSchedule($booking, $receipt, $netAmount, $actorUserId);
                $receipt->components()->where('is_commission_eligible', true)->each(
                    function (BookingPaymentReceiptComponent $component) use ($receipt, $event): void {
                        $this->metricFacts->projectConfirmedCollection($receipt, $component);
                        $decision = $this->commissionDecisions->decide($receipt, $component);
                        if ($decision) $this->commissionHolds->releaseForFinality($decision, $event);
                    }
                );
            } elseif ($toStatus === 'failed' && $receipt->payment_purpose !== 'security_deposit') {
                $receipt->components()->where('is_commission_eligible', true)->each(
                    function (BookingPaymentReceiptComponent $component) use ($receipt, $event): void {
                        $decision = $this->commissionDecisions->decide($receipt, $component);
                        if ($decision) $this->commissionHolds->resolveForFailedFinality($decision, $event);
                    }
                );
            }
            $summary = $this->summary($booking);
            $booking->update([
                'payment_status' => $summary['payment_status'],
                'payment_collection_status' => $summary['due_amount'] <= 0 ? 'paid' : ($summary['paid_amount'] > 0 ? 'partially_paid' : 'pending'),
                'payment_collected_amount' => $summary['paid_amount'],
                'amount_to_pay' => $summary['due_amount'],
                'settled_at' => $summary['due_amount'] <= 0 ? ($booking->settled_at ?? now()) : null,
            ]);
            FinancialAuditEvent::create([
                'subject_type' => 'booking_payment_finality', 'subject_id' => $event->id,
                'booking_id' => $booking->id, 'event_type' => 'payment_finality_changed',
                'from_status' => $event->from_status, 'to_status' => $event->to_status,
                'metadata' => ['receipt_id' => $receipt->id, 'finality_policy_id' => $event->finality_policy_id,
                    'reason' => $reason, 'evidence_reference' => $evidenceReference],
                'performed_by' => $actorUserId, 'occurred_at' => now(),
            ]);

            return $receipt->fresh();
        });
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
