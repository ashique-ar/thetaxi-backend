<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Models\Booking\Booking;
use App\Models\Booking\BookingPaymentReceipt;
use App\Models\Booking\BookingPaymentReceiptComponent;
use App\Services\BookingPaymentLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PaymentLedgerReconciliationController extends Controller
{
    public function __construct(private readonly BookingPaymentLedgerService $ledger) {}

    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'booking_id' => ['nullable', 'uuid', 'exists:bookings,id'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);
        $paidWithoutReceipt = Booking::query()
            ->whereIn('payment_status', ['paid', 'success', 'online_paid'])
            ->whereDoesntHave('paymentReceipts')
            ->when($data['booking_id'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->limit($data['limit'] ?? 250)
            ->get(['id', 'booking_number', 'payment_status', 'payment_method', 'payment_reference',
                'payment_collected_amount', 'total_actual', 'total_estimated', 'updated_at']);
        $receiptExceptions = BookingPaymentReceipt::query()
            ->where(function ($q) {
                $q->whereNull('company_id')->orWhereNull('source_currency')
                    ->orWhere(fn ($fx) => $fx->where('source_currency', '!=', 'LKR')->whereNull('fx_rate_to_lkr'))
                    ->orWhereDoesntHave('components');
            })
            ->when($data['booking_id'] ?? null, fn ($q, $id) => $q->where('booking_id', $id))
            ->limit($data['limit'] ?? 250)
            ->get();

        return response()->json(['status' => 'success', 'data' => [
            'write_performed' => false,
            'paid_without_receipt' => $paidWithoutReceipt,
            'receipt_exceptions' => $receiptExceptions,
            'counts' => ['paid_without_receipt' => $paidWithoutReceipt->count(), 'receipt_exceptions' => $receiptExceptions->count()],
            'warning' => 'Preview never creates attribution, FX, finality, commission, or payout facts.',
        ]]);
    }

    public function repairLegacyBooking(Request $request, Booking $booking): JsonResponse
    {
        $data = $request->validate([
            'source_amount' => ['required', 'numeric', 'gt:0'],
            'source_currency' => ['required', 'string', 'size:3'],
            'fx_rate_to_lkr' => ['nullable', 'numeric', 'gt:0'],
            'fx_rate_at' => ['nullable', 'date'],
            'fx_source' => ['nullable', 'string', 'max:120'],
            'payment_method' => ['required', 'string', 'max:50'],
            'reference' => ['required', 'string', 'max:160'],
            'provider_event_id' => ['nullable', 'string', 'max:160'],
            'provider_payload_checksum' => ['nullable', 'string', 'size:64'],
            'received_at' => ['required', 'date', 'before_or_equal:now'],
            'received_via' => ['required', Rule::in(['company', 'driver'])],
            'notes' => ['required', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:160'],
        ]);

        return response()->json(['status' => 'success', 'data' => $this->ledger
            ->repairLegacyPaidBooking($booking, $data, (string) $request->user()->id)], 201);
    }

    public function repairComponents(Request $request, BookingPaymentReceipt $receipt): JsonResponse
    {
        $data = $request->validate([
            'component_type' => ['required', Rule::in(['booking_payment', 'service_deposit', 'security_deposit'])],
            'is_allocatable' => ['required', 'boolean'],
            'is_collection_target_eligible' => ['required', 'boolean'],
            'is_commission_eligible' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        abort_if($receipt->components()->exists(), 422, 'Receipt components already exist; create an adjustment instead of rewriting classification.');
        abort_if($data['component_type'] === 'security_deposit'
            && ($data['is_collection_target_eligible'] || $data['is_commission_eligible']), 422,
            'Security deposits cannot be target- or commission-eligible.');

        $component = DB::transaction(function () use ($receipt, $data, $request) {
            $receipt = BookingPaymentReceipt::query()->lockForUpdate()->findOrFail($receipt->id);
            $component = BookingPaymentReceiptComponent::create([
                'receipt_id' => $receipt->id,
                'component_type' => $data['component_type'],
                'source_amount' => $receipt->source_amount ?? $receipt->amount,
                'lkr_amount' => $receipt->lkr_amount,
                'is_allocatable' => $data['is_allocatable'],
                'is_collection_target_eligible' => $data['is_collection_target_eligible'],
                'is_commission_eligible' => $data['is_commission_eligible'],
            ]);
            DB::table('domain_audit_events')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(), 'domain' => 'sales', 'company_id' => $receipt->company_id,
                'subject_type' => 'booking_payment_receipt_component', 'subject_id' => $component->id,
                'event_type' => 'sales.payment.component.repaired', 'actor_user_id' => $request->user()->id,
                'actor_type' => 'user', 'correlation_id' => null, 'source_ip' => $request->ip(),
                'before_checksum' => null,
                'after_checksum' => hash('sha256', json_encode($component->getAttributes(), JSON_THROW_ON_ERROR)),
                'reason' => $data['reason'].' Receipt '.$receipt->id,
                'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            return $component;
        });

        return response()->json(['status' => 'success', 'data' => $component], 201);
    }
}
