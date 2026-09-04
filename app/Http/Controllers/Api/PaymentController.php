<?php

namespace App\Http\Controllers\Api;

use App\Contracts\PaymentGatewayInterface;
use App\Http\Controllers\Controller;
use App\Models\Booking\Booking;
use App\Models\Booking\BookingPaymentReceipt;
use App\Services\Sales\BookingPaymentAdjustmentService;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Sms\SmsAutomationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use App\Services\BookingPaymentLedgerService;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentGatewayManager $gatewayManager,
        private readonly SmsAutomationService $smsAutomation,
        private readonly BookingPaymentLedgerService $paymentLedger,
        private readonly BookingPaymentAdjustmentService $paymentAdjustments,
    ) {
        $this->middleware('auth:api');
        $this->middleware('permission:payments.initiate')->only(['initiatePayment']);
        $this->middleware('permission:payments.callback')->only(['paymentCallback']);
        $this->middleware('permission:payments.view')->only(['getPaymentStatus']);
        $this->middleware('permission:payments.refund')->only(['refundPayment', 'refundTransaction']);
        $this->middleware('permission:payments.methods')->only(['getPaymentMethods']);
        $this->middleware('permission:payments.transactions')->only(['getPaymentTransactions', 'getTransactionDetails']);
    }

    /**
     * Initiate payment
     * POST /api/payments/initiate
     */
    public function initiatePayment(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'booking_id' => 'required|exists:bookings,id',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'required|string|size:3',
            'payment_method' => 'required|string|in:credit_card,debit_card,paypal,stripe,bank_transfer',
            'return_url' => 'nullable|url',
            'cancel_url' => 'nullable|url'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $booking = Booking::findOrFail($request->booking_id);
            $collectionMethod = strtolower((string) ($booking->payment_collection_method ?? $booking->payment_method ?? ''));
            if ($collectionMethod !== 'online') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Online payment can only be initiated for bookings with online payment method',
                ], 422);
            }

            $transactionId = $this->generateTransactionId();
            $recordId = (string) Str::uuid();

            DB::table('payment_transactions')->insert([
                'id'             => $recordId,
                'booking_id'     => $booking->id,
                'amount'         => $request->amount,
                'currency'       => $request->currency,
                'payment_method' => $request->payment_method,
                'status'         => 'pending',
                'transaction_id' => $transactionId,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);

            $transaction = DB::table('payment_transactions')->where('id', $recordId)->first();

            // Delegate to the appropriate payment gateway
            try {
                $gateway    = $this->gatewayManager->resolve($request->payment_method);
                $gatewayResult = $gateway->initiatePayment(
                    $booking,
                    (float) $request->amount,
                    $request->currency,
                    ['payment_type' => $request->get('payment_type', 'full')]
                );
            } catch (\InvalidArgumentException $e) {
                $gatewayResult = [
                    'success'        => false,
                    'payment_url'    => config('app.url') . "/payments/{$request->payment_method}/{$transactionId}",
                    'transaction_id' => $transactionId,
                    'message'        => $e->getMessage(),
                ];
            }

            // Persist gateway transaction ID if provided
            if (!empty($gatewayResult['transaction_id']) && $gatewayResult['transaction_id'] !== $transactionId) {
                DB::table('payment_transactions')
                    ->where('id', $recordId)
                    ->update(['gateway_transaction_id' => $gatewayResult['transaction_id'], 'updated_at' => now()]);
            }

            return response()->json([
                'status'  => 'success',
                'message' => 'Payment initiated successfully',
                'data'    => [
                    'transaction_id'         => $transactionId,
                    'record_id'              => $recordId,
                    'payment_url'            => $gatewayResult['payment_url'] ?? '',
                    'gateway_data'           => $gatewayResult['gateway_data'] ?? null,
                    'amount'                 => $request->amount,
                    'currency'               => $request->currency,
                    'status'                 => 'pending',
                    'gateway_success'        => $gatewayResult['success'] ?? false,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to initiate payment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Payment callback
     * POST /api/payments/callback
     */
    public function paymentCallback(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'transaction_id' => 'required|string',
            'status' => 'required|string|in:success,failed,cancelled',
            'payment_id' => 'nullable|string',
            'signature' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $transaction = DB::table('payment_transactions')
                ->where('transaction_id', $request->transaction_id)
                ->first();

            if (!$transaction) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Transaction not found'
                ], 404);
            }

            // Verify signature if provided
            if ($request->signature && !$this->verifyPaymentSignature($request->all())) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid payment signature'
                ], 400);
            }

            // Idempotency: skip if already processed with this status
            $idempotencyKey = 'payment.callback.' . $request->input('transaction_id') . '.' . $request->input('status');
            if (Cache::has($idempotencyKey)) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Payment callback already processed',
                    'data' => ['transaction_id' => $request->input('transaction_id'), 'status' => $request->input('status')]
                ]);
            }

            $smsConfirmation = DB::transaction(function () use ($request, $transaction): ?array {
                DB::table('payment_transactions')
                    ->where('transaction_id', $request->input('transaction_id'))
                    ->update([
                        'status' => $request->input('status'),
                        'payment_id' => $request->input('payment_id'),
                        'updated_at' => now(),
                    ]);

                $booking = Booking::query()->lockForUpdate()->find($transaction->booking_id);
                if (! $booking) {
                    return null;
                }
                if ($request->input('status') === 'success') {
                    $amount = (float) ($transaction->amount ?? 0);
                    $currency = (string) ($transaction->currency ?? 'LKR');
                    $reference = (string) ($request->input('payment_id') ?? $request->input('transaction_id'));
                    $this->paymentLedger->receive($booking, [
                        'amount' => $amount,
                        'source_amount' => $amount,
                        'source_currency' => strtoupper($currency),
                        'payment_method' => (string) ($transaction->payment_method ?? 'online'),
                        'payment_stage' => 'gateway_settlement',
                        'payment_purpose' => 'booking_payment',
                        'reference' => $reference,
                        'idempotency_key' => 'gateway:'.(string) $request->input('transaction_id'),
                        'provider_event_id' => $reference,
                        'provider_payload_checksum' => hash('sha256', json_encode($request->except(['signature']), JSON_THROW_ON_ERROR)),
                        'received_at' => now(),
                        'received_via' => 'company',
                        'finalized_at' => now(),
                        'notes' => 'Canonically recorded from verified payment callback.',
                    ], $request->user()?->id);

                    return compact('booking', 'amount', 'currency', 'reference');
                }

                if (in_array($request->input('status'), ['failed', 'cancelled'], true)) {
                    $booking->update([
                        'payment_collection_method' => 'online',
                        'payment_collection_status' => 'failed',
                        'payment_status' => 'failed',
                        'payment_reference' => $request->input('payment_id') ?? $request->input('transaction_id'),
                    ]);
                }

                return null;
            });
            if ($smsConfirmation) {
                $this->smsAutomation->queuePaymentConfirmation(
                    $smsConfirmation['booking'], $smsConfirmation['amount'],
                    $smsConfirmation['currency'], $smsConfirmation['reference']
                );
            }

            // Mark as processed for 24 hours to prevent duplicate handling
            Cache::put($idempotencyKey, true, now()->addHours(24));

            return response()->json([
                'status' => 'success',
                'message' => 'Payment callback processed successfully',
                'data' => [
                    'transaction_id' => $request->input('transaction_id'),
                    'status' => $request->input('status')
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to process payment callback',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get payment status
     * GET /api/payments/{id}/status
     */
    public function getPaymentStatus(string $id): JsonResponse
    {
        try {
            $transaction = DB::table('payment_transactions')
                ->where('transaction_id', $id)
                ->first();

            if (!$transaction) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Transaction not found'
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'data' => [
                    'transaction_id' => $transaction->transaction_id,
                    'booking_id' => $transaction->booking_id,
                    'amount' => $transaction->amount,
                    'currency' => $transaction->currency,
                    'payment_method' => $transaction->payment_method,
                    'status' => $transaction->status,
                    'created_at' => $transaction->created_at,
                    'updated_at' => $transaction->updated_at
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get payment status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Refund payment
     * POST /api/payments/{id}/refund
     */
    public function refundPayment(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'nullable|numeric|min:0.01',
            'reason' => 'required|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $transaction = DB::table('payment_transactions')
                ->where('transaction_id', $id)
                ->where('status', 'success')
                ->first();

            if (!$transaction) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Transaction not found or not eligible for refund'
                ], 404);
            }

            $booking = Booking::find($transaction->booking_id);
            $receipt = BookingPaymentReceipt::query()
                ->with('components')
                ->where('booking_id', $transaction->booking_id)
                ->where(function ($query) use ($transaction): void {
                    $query->where('provider_event_id', $transaction->gateway_transaction_id ?? $transaction->transaction_id)
                        ->orWhere('provider_event_id', $transaction->transaction_id)
                        ->orWhere('idempotency_key', 'gateway:'.$transaction->transaction_id);
                })
                ->first();
            $component = $receipt?->components->firstWhere('component_type', 'booking_payment');
            if (! $booking || ! $component) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Canonical payment receipt is missing. Reconcile this transaction before refunding it.',
                ], 409);
            }

            $alreadyRefunded = (float) DB::table('payment_refunds')
                ->where('transaction_id', $transaction->id)
                ->where('status', 'completed')
                ->sum('amount');
            $remainingRefundable = max(0, round((float) $transaction->amount - $alreadyRefunded, 2));
            $refundAmount = round((float) ($request->amount ?? $remainingRefundable), 2);
            if ($refundAmount <= 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'This transaction has no remaining refundable amount.',
                ], 422);
            }
            if ($refundAmount > $remainingRefundable) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Refund amount cannot exceed the remaining refundable amount of {$remainingRefundable}.",
                ], 422);
            }

            // Attempt gateway refund
            $gatewayRefundId = null;
            $refundStatus    = 'pending';
            $refundNotes     = null;

            try {
                $gateway      = $this->gatewayManager->resolve($transaction->payment_method ?? 'webxpay');
                $gatewayResult = $gateway->refund(
                    $transaction->gateway_transaction_id ?? $transaction->transaction_id,
                    (float) $refundAmount,
                    $request->reason
                );

                if ($gatewayResult['success']) {
                    $gatewayRefundId = $gatewayResult['refund_id'] ?? null;
                    $refundStatus    = 'completed';
                } elseif (!empty($gatewayResult['manual'])) {
                    $refundStatus = 'manual_required';
                    $refundNotes  = $gatewayResult['message'] ?? null;
                } else {
                    $refundStatus = 'failed';
                    $refundNotes  = $gatewayResult['message'] ?? null;
                }
            } catch (\Throwable $e) {
                Log::error('Gateway refund call failed', ['transaction_id' => $id, 'error' => $e->getMessage()]);
                $refundStatus = 'manual_required';
                $refundNotes  = $e->getMessage();
            }

            $refundId = (string) Str::uuid();
            DB::transaction(function () use (
                $refundId, $transaction, $refundAmount, $refundStatus, $gatewayRefundId,
                $refundNotes, $request, $booking, $receipt, $component
            ): void {
                DB::table('payment_refunds')->insert([
                    'id'                      => $refundId,
                    'transaction_id'          => $transaction->id,
                    'amount'                  => $refundAmount,
                    'reason'                  => $request->reason,
                    'status'                  => $refundStatus,
                    'gateway_refund_id'       => $gatewayRefundId,
                    'notes'                   => $refundNotes,
                    'created_at'              => now(),
                    'updated_at'              => now(),
                ]);

                // Only confirmed provider cash movement changes the canonical collection ledger.
                if ($refundStatus === 'completed') {
                    $sourceCurrency = strtoupper((string) ($receipt->source_currency ?: $transaction->currency ?: 'LKR'));
                    $fxRate = $receipt->fx_rate_to_lkr !== null ? (float) $receipt->fx_rate_to_lkr : null;
                    $this->paymentAdjustments->record($booking, [
                        'receipt_component_id' => $component->id,
                        'impact_dimension' => 'cash_receipt',
                        'adjustment_type' => 'refund',
                        'direction' => 'decrease',
                        'source_amount' => $refundAmount,
                        'source_currency' => $sourceCurrency,
                        'lkr_amount' => $fxRate !== null ? round($refundAmount * $fxRate, 4) : null,
                        'fx_rate_to_lkr' => $fxRate,
                        'adjustment_effective_at' => now(),
                        'reason' => $request->reason,
                        'reference' => $gatewayRefundId ?: $refundId,
                        'idempotency_key' => 'gateway-refund:'.$refundId,
                    ], (string) $request->user()->id);
                }
            });

            return response()->json([
                'status' => 'success',
                'message' => $refundStatus === 'completed'
                    ? 'Payment refund completed and the collection ledger was adjusted.'
                    : 'Refund request recorded; the collection ledger will remain unchanged until cash movement is confirmed.',
                'data' => [
                    'refund_id' => $refundId,
                    'amount' => $refundAmount,
                    'status' => $refundStatus,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to process refund',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get payment methods
     * GET /api/payments/methods
     */
    public function getPaymentMethods(): JsonResponse
    {
        $enabled = $this->gatewayManager->available();

        $methods = array_merge(
            // Gateways that are actually configured and enabled
            array_map(fn ($gw) => [
                'id'          => $gw['method'],
                'name'        => $gw['gateway'],
                'description' => "Pay via {$gw['gateway']}",
                'icon'        => strtolower(str_replace(' ', '-', $gw['method'])),
                'is_active'   => true,
            ], $enabled)
        );

        // Always include at least one method so UI doesn't break
        if (empty($methods)) {
            $methods = [['id' => 'bank_transfer', 'name' => 'Bank Transfer', 'description' => 'Pay via bank transfer', 'icon' => 'bank', 'is_active' => true]];
        }

        return response()->json([
            'status' => 'success',
            'data'   => ['payment_methods' => $methods],
        ]);
    }

    /**
     * Get payment transactions
     * GET /api/payment-transactions
     */
    public function getPaymentTransactions(Request $request): JsonResponse
    {
        $limit = $request->get('limit', 20);
        $status = $request->get('status');
        $bookingId = $request->get('booking_id');

        $query = DB::table('payment_transactions')
            ->join('bookings', 'payment_transactions.booking_id', '=', 'bookings.id')
            ->leftJoin('customers', 'bookings.customer_id', '=', 'customers.id')
            ->leftJoin('users', 'customers.user_id', '=', 'users.id')
            ->select([
                'payment_transactions.*',
                'bookings.booking_number',
                'users.first_name',
                'users.last_name',
                'users.email'
            ]);

        if ($status) {
            $query->where('payment_transactions.status', $status);
        }

        if ($bookingId) {
            $query->where('payment_transactions.booking_id', $bookingId);
        }

        $transactions = $query->orderBy('payment_transactions.created_at', 'desc')
            ->paginate($limit);

        return response()->json([
            'status' => 'success',
            'data' => [
                'transactions' => $transactions->items(),
                'pagination' => [
                    'current_page' => $transactions->currentPage(),
                    'last_page' => $transactions->lastPage(),
                    'per_page' => $transactions->perPage(),
                    'total' => $transactions->total()
                ]
            ]
        ]);
    }

    /**
     * Get transaction details
     * GET /api/payment-transactions/{id}
     */
    public function getTransactionDetails(string $id): JsonResponse
    {
        try {
            $transaction = DB::table('payment_transactions')
                ->join('bookings', 'payment_transactions.booking_id', '=', 'bookings.id')
                ->leftJoin('customers', 'bookings.customer_id', '=', 'customers.id')
                ->leftJoin('users', 'customers.user_id', '=', 'users.id')
                ->select([
                    'payment_transactions.*',
                    'bookings.booking_number',
                    'users.first_name',
                    'users.last_name',
                    'users.email'
                ])
                ->selectRaw('COALESCE(bookings.total_actual, bookings.total_estimated, 0) as booking_amount')
                ->where('payment_transactions.id', $id)
                ->first();

            if (!$transaction) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Transaction not found'
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'data' => [
                    'transaction' => $transaction
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get transaction details',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Refund transaction
     * POST /api/payment-transactions/{id}/refund
     */
    public function refundTransaction(Request $request, string $id): JsonResponse
    {
        return $this->refundPayment($request, $id);
    }

    /**
     * Generate transaction ID
     */
    private function generateTransactionId(): string
    {
        return 'TXN' . strtoupper(Str::random(12));
    }

    /**
     * Verify payment signature using HMAC-SHA256.
     * Expected header: X-Payment-Signature = HMAC-SHA256(secret, sorted_payload_json)
     */
    private function verifyPaymentSignature(array $data): bool
    {
        $secret = config('booking.payment_callback_secret');
        if (empty($secret)) {
            // If no secret configured, skip verification but log a warning.
            \Illuminate\Support\Facades\Log::warning('Payment signature verification skipped: payment_callback_secret not configured');
            return true;
        }

        $providedSignature = $data['signature'] ?? '';
        if (empty($providedSignature)) {
            return false;
        }

        // Rebuild expected signature from payload (exclude the signature itself).
        $payload = $data;
        unset($payload['signature']);
        ksort($payload);

        $expectedSignature = hash_hmac('sha256', json_encode($payload), $secret);
        return hash_equals($expectedSignature, $providedSignature);
    }
}
