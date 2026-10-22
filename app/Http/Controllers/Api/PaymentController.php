<?php

namespace App\Http\Controllers\Api;

use App\Contracts\PaymentGatewayInterface;
use App\Http\Controllers\Controller;
use App\Models\Booking\Booking;
use App\Models\Booking\BookingPaymentReceipt;
use App\Models\Finance\FinancialAuditEvent;
use App\Services\Sales\BookingPaymentAdjustmentService;
use App\Services\Sales\SalesAccessScope;
use App\Services\Sales\SalesCollectionCompanyIntegrity;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Sms\SmsAutomationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use App\Services\BookingPaymentLedgerService;
use App\Services\CurrencyService;

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
        $this->middleware('permission:payments.transactions')->only([
            'getPaymentTransactions', 'getRefundReconciliationQueue', 'getPaymentInitiationReconciliationQueue', 'getTransactionDetails',
        ]);
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
            'payment_method' => 'required|string|in:webxpay,credit_card,debit_card,paypal,stripe,bank_transfer',
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
            $initialBooking = Booking::query()->findOrFail($request->booking_id);
            $initialCollectionMethod = strtolower((string) ($initialBooking->payment_collection_method ?? $initialBooking->payment_method ?? ''));
            if ($initialCollectionMethod !== 'online') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Online payment can only be initiated for bookings with online payment method.',
                ], 422);
            }
            $tenantCompanyId = DB::table('sales_booking_attributions')->where('booking_id', $initialBooking->id)
                ->whereNull('deleted_at')->value('company_id');
            $tenantCompanyIsActive = $tenantCompanyId && DB::table('companies')->where('id', $tenantCompanyId)
                ->where('is_active', true)->whereNull('deleted_at')->exists();
            if (! $tenantCompanyIsActive) {
                abort(409, 'The booking needs a reviewed active legal-entity attribution before payment initiation.');
            }
            app(SalesAccessScope::class)->assertCompany(
                $request->user(),
                (string) $tenantCompanyId,
                'sales.payment-adjustments.create-all',
            );
            try {
                $gateway = $this->gatewayManager->resolve((string) $request->payment_method);
            } catch (\InvalidArgumentException) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'This payment method has no configured gateway.',
                ], 422);
            }
            if (! $gateway->isEnabled()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No configured online gateway is available for this payment method.',
                ], 503);
            }
            $reservation = DB::transaction(function () use ($request, $tenantCompanyId): array {
                $booking = Booking::query()->lockForUpdate()->findOrFail($request->booking_id);
                $collectionMethod = strtolower((string) ($booking->payment_collection_method ?? $booking->payment_method ?? ''));
                abort_unless($collectionMethod === 'online', 422,
                    'Online payment can only be initiated for bookings with online payment method.');

                $attribution = DB::table('sales_booking_attributions')->where('booking_id', $booking->id)
                    ->whereNull('deleted_at')->lockForUpdate()->first(['company_id']);
                $currentCompanyId = $attribution?->company_id;
                abort_unless((string) $currentCompanyId === (string) $tenantCompanyId, 409,
                    'The booking legal entity changed before payment initiation.');
                $company = DB::table('companies')->where('id', $tenantCompanyId)->where('is_active', true)
                    ->whereNull('deleted_at')->lockForUpdate()->first(['id']);
                abort_unless($company, 409, 'The booking legal entity is inactive; reconcile its attribution before payment initiation.');
                app(SalesCollectionCompanyIntegrity::class)->assertConsistent($booking, (string) $company->id);
                app(SalesAccessScope::class)->assertCompany(
                    $request->user(),
                    (string) $tenantCompanyId,
                    'sales.payment-adjustments.create-all',
                );

                $workflowData = is_array($booking->workflow_data)
                    ? $booking->workflow_data
                    : (json_decode((string) $booking->workflow_data, true) ?: []);
                $currencyService = app(CurrencyService::class);
                $paymentCurrency = strtoupper(trim((string) (
                    $workflowData['display_currency']
                    ?? $booking->currency
                    ?? $currencyService->getDefaultCurrency()
                )));
                abort_unless(strtoupper(trim((string) $request->currency)) === $paymentCurrency, 422,
                    'Payment currency must match the booking currency.');

                $summary = $this->paymentLedger->summary($booking);
                $pending = DB::table('payment_transactions')
                    ->where('booking_id', $booking->id)
                    ->where('status', 'pending')
                    ->get(['requested_amount', 'requested_currency']);
                foreach ($pending as $attempt) {
                    abort_unless($attempt->requested_amount !== null
                        && strtoupper((string) $attempt->requested_currency) === $paymentCurrency, 409,
                        'An earlier payment attempt lacks a verified booking-currency reservation; reconcile it before retrying.');
                }

                $reservedAmount = (float) $pending->sum('requested_amount');
                $availableAmount = max(0, $currencyService->roundAmount((float) $summary['due_amount'] - $reservedAmount, $paymentCurrency));
                $requestedAmount = $currencyService->roundAmount((float) $request->amount, $paymentCurrency);
                abort_if($requestedAmount <= 0 || $requestedAmount > $availableAmount, 422,
                    'Payment amount cannot exceed the remaining canonical booking balance.');

                $transactionId = $this->generateTransactionId();
                $recordId = (string) Str::uuid();
                DB::table('payment_transactions')->insert([
                    'id' => $recordId,
                    'attempt_id' => $recordId,
                    'booking_id' => $booking->id,
                    'company_id' => $tenantCompanyId,
                    'amount' => $requestedAmount,
                    'currency' => $paymentCurrency,
                    'requested_amount' => $requestedAmount,
                    'requested_currency' => $paymentCurrency,
                    'payment_method' => $request->payment_method,
                    'gateway' => $request->payment_method,
                    'status' => 'pending',
                    'transaction_id' => $transactionId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return compact('booking', 'transactionId', 'recordId', 'requestedAmount', 'paymentCurrency');
            }, 3);

            $booking = $reservation['booking'];
            $transactionId = $reservation['transactionId'];
            $recordId = $reservation['recordId'];
            $requestedAmount = $reservation['requestedAmount'];
            $paymentCurrency = $reservation['paymentCurrency'];

            $transaction = DB::table('payment_transactions')->where('id', $recordId)->first();

            // Delegate to the already-resolved and enabled payment gateway.
            try {
                $gatewayResult = $gateway->initiatePayment(
                    $booking,
                    (float) $requestedAmount,
                    $paymentCurrency,
                    ['payment_type' => $request->get('payment_type', 'full')]
                );
            } catch (\Throwable $e) {
                Log::warning('Payment gateway initiation failed after reserving the booking balance.', [
                    'booking_id' => $booking->id,
                    'transaction_id' => $transactionId,
                    'error' => $e->getMessage(),
                ]);
                $gatewayResult = ['success' => false, 'payment_url' => ''];
            }

            $chargedAmount = data_get($gatewayResult, 'gateway_data.amount', $requestedAmount);
            $chargedCurrency = data_get($gatewayResult, 'gateway_data.currency', $paymentCurrency);
            $providerData = is_array($gatewayResult['gateway_data'] ?? null) ? $gatewayResult['gateway_data'] : [];
            $hasProviderSourceFacts = array_key_exists('booking_amount', $providerData)
                || array_key_exists('booking_currency', $providerData);
            $providerSourceAmount = data_get($providerData, 'booking_amount');
            $providerSourceCurrency = strtoupper(trim((string) data_get($providerData, 'booking_currency', '')));
            $sourceFactsMatchReservation = ! $hasProviderSourceFacts
                || (is_numeric($providerSourceAmount)
                    && is_finite((float) $providerSourceAmount)
                    && abs((float) $providerSourceAmount - (float) $requestedAmount) < 0.005
                    && $providerSourceCurrency === $paymentCurrency);
            $snapshot = [
                'source_amount' => (float) $requestedAmount,
                'source_currency' => $paymentCurrency,
                'source_evidence_valid' => $sourceFactsMatchReservation,
                'provider_amount' => (float) $chargedAmount,
                'provider_currency' => strtoupper((string) $chargedCurrency),
                'order_id' => data_get($providerData, 'order_id', $gatewayResult['transaction_id'] ?? null),
                'fx_rate_to_lkr' => data_get($providerData, 'fx_rate_to_lkr'),
                'fx_rate_at' => data_get($providerData, 'fx_rate_at'),
                'gateway_fx_rate_to_lkr' => data_get($providerData, 'gateway_fx_rate_to_lkr'),
            ];

            DB::table('payment_transactions')->where('id', $recordId)->update([
                'gateway_transaction_id' => $gatewayResult['transaction_id'] ?? null,
                'amount' => $chargedAmount,
                'currency' => $chargedCurrency,
                'gateway_payment_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);

            if (empty($gatewayResult['success']) || empty($gatewayResult['payment_url']) || ! $sourceFactsMatchReservation) {
                $message = $sourceFactsMatchReservation
                    ? 'The gateway did not return a checkout session. The pending amount remains reserved until the provider outcome is reconciled.'
                    : 'The gateway charge details conflict with the reserved booking amount. The pending attempt requires manual reconciliation.';
                return response()->json([
                    'status' => 'error',
                    'message' => $message,
                    'data' => [
                        'transaction_id' => $transactionId,
                        'record_id' => $recordId,
                        'status' => 'pending',
                        'gateway_success' => false,
                    ],
                ], 502);
            }

            return response()->json([
                'status'  => 'success',
                'message' => 'Payment initiated successfully',
                'data'    => [
                    'transaction_id'         => $transactionId,
                    'record_id'              => $recordId,
                    'payment_url'            => $gatewayResult['payment_url'] ?? '',
                    'gateway_data'           => $gatewayResult['gateway_data'] ?? null,
                    'amount'                 => $requestedAmount,
                    'currency'               => $paymentCurrency,
                    'requested_amount'       => $requestedAmount,
                    'requested_currency'     => $paymentCurrency,
                    'gateway_amount'         => $chargedAmount,
                    'gateway_currency'       => $chargedCurrency,
                    'status'                 => 'pending',
                    'gateway_success'        => $gatewayResult['success'] ?? false,
                ],
            ]);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            throw $e;
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
        $callbackSecret = config('booking.payment_callback_secret');
        if (! is_string($callbackSecret) || strlen($callbackSecret) < 32) {
            return response()->json([
                'status' => 'error',
                'message' => 'Secure payment callback verification is not configured.',
            ], 503);
        }

        $validator = Validator::make($request->all(), [
            'transaction_id' => 'required|string',
            'status' => 'required|string|in:success,failed,cancelled',
            'payment_id' => 'nullable|string',
            'signature' => 'required|string'
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

            if (! $this->verifyPaymentSignature($request->all())) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid payment signature'
                ], 400);
            }

            $callbackOutcome = DB::transaction(function () use ($request, $transaction): array {
                $lockedTransaction = DB::table('payment_transactions')
                    ->where('id', $transaction->id)
                    ->lockForUpdate()
                    ->first();
                abort_unless($lockedTransaction, 404, 'Transaction not found.');
                $requestedStatus = (string) $request->input('status');
                if (in_array($lockedTransaction->status, ['success', 'failed', 'cancelled'], true)) {
                    $sameOutcome = $lockedTransaction->status === $requestedStatus
                        && (string) ($lockedTransaction->payment_id ?? '') === (string) ($request->input('payment_id') ?? '');
                    abort_unless($sameOutcome, 409, 'The payment transaction already has a different final outcome.');

                    return ['applied' => false, 'sms_confirmation' => null];
                }
                abort_unless($lockedTransaction->status === 'pending', 409,
                    'The payment transaction is not awaiting a provider outcome.');

                $booking = Booking::query()->whereKey($lockedTransaction->booking_id)->lockForUpdate()->first();
                abort_unless($booking, 409, 'The payment booking no longer exists; reconcile this callback manually.');
                $attribution = DB::table('sales_booking_attributions')->where('booking_id', $booking->id)
                    ->whereNull('deleted_at')->lockForUpdate()->first(['company_id']);
                abort_unless($attribution?->company_id, 409,
                    'The booking has no reviewed legal-entity attribution; reconcile this callback manually.');
                abort_unless($lockedTransaction->company_id
                    && (string) $lockedTransaction->company_id === (string) $attribution->company_id, 409,
                    'The payment attempt has no matching company snapshot; reconcile this callback manually.');
                $company = DB::table('companies')->where('id', $attribution->company_id)->where('is_active', true)
                    ->whereNull('deleted_at')->lockForUpdate()->first(['id']);
                abort_unless($company, 409, 'The booking legal entity is inactive; reconcile this callback manually.');
                abort_unless((string) $attribution->company_id === (string) $company->id, 409,
                    'The booking legal entity changed before payment could be recorded.');
                DB::table('payment_transactions')
                    ->where('id', $lockedTransaction->id)
                    ->update([
                        'status' => $request->input('status'),
                        'payment_id' => $request->input('payment_id'),
                        'updated_at' => now(),
                    ]);

                if ($request->input('status') === 'success') {
                    $snapshot = json_decode((string) ($lockedTransaction->gateway_payment_snapshot ?? ''), true);
                    abort_unless(is_array($snapshot), 409, 'The provider settlement snapshot is missing; reconcile this payment manually.');
                    $amount = (float) ($snapshot['source_amount'] ?? 0);
                    $currency = strtoupper((string) ($snapshot['source_currency'] ?? ''));
                    $providerAmount = (float) ($snapshot['provider_amount'] ?? 0);
                    $providerCurrency = strtoupper((string) ($snapshot['provider_currency'] ?? ''));
                    abort_unless(is_finite($amount) && $amount > 0
                        && ($snapshot['source_evidence_valid'] ?? false) === true
                        && abs($amount - (float) $lockedTransaction->requested_amount) < 0.005
                        && $currency === strtoupper((string) $lockedTransaction->requested_currency)
                        && is_finite($providerAmount) && abs($providerAmount - (float) $lockedTransaction->amount) < 0.005
                        && $providerCurrency === strtoupper((string) $lockedTransaction->currency)
                        && preg_match('/^[A-Z]{3}$/', $currency) && preg_match('/^[A-Z]{3}$/', $providerCurrency),
                        409, 'The provider settlement snapshot does not match the reserved payment; reconcile manually.');
                    $fxRate = $currency === 'LKR' ? 1.0 : (float) ($snapshot['fx_rate_to_lkr'] ?? 0);
                    abort_unless(is_finite($fxRate) && $fxRate > 0, 409,
                        'The settlement snapshot has no approved LKR conversion rate; reconcile manually.');
                    $reference = (string) ($request->input('payment_id') ?? $request->input('transaction_id'));
                    $this->paymentLedger->receive($booking, [
                        'amount' => $amount,
                        'source_amount' => $amount,
                        'source_currency' => $currency,
                        'fx_rate_to_lkr' => $fxRate,
                        'fx_rate_at' => $snapshot['fx_rate_at'] ?? now(),
                        'fx_source' => $currency === 'LKR' ? 'gateway_payment_snapshot' : 'configured_currency_rate_at_payment',
                        'payment_method' => (string) ($transaction->payment_method ?? 'online'),
                        'payment_stage' => 'gateway_settlement',
                        'payment_purpose' => 'booking_payment',
                        'reference' => $reference,
                        'idempotency_key' => 'gateway:'.(string) $request->input('transaction_id'),
                        'provider_event_id' => $reference,
                        'provider_payload_checksum' => hash('sha256', json_encode([
                            'callback' => $request->except(['signature']), 'snapshot' => $snapshot,
                            'gateway_fx_rate_to_lkr' => $snapshot['gateway_fx_rate_to_lkr'] ?? null,
                        ], JSON_THROW_ON_ERROR)),
                        'received_at' => now(),
                        'received_via' => 'company',
                        'finalized_at' => now(),
                        'notes' => 'Canonically recorded from verified payment callback.',
                    ], $request->user()?->id);

                    return [
                        'applied' => true,
                        'sms_confirmation' => compact('booking', 'amount', 'currency', 'reference'),
                    ];
                }

                if (in_array($request->input('status'), ['failed', 'cancelled'], true)) {
                    $booking->update([
                        'payment_collection_method' => 'online',
                        'payment_collection_status' => 'failed',
                        'payment_status' => 'failed',
                        'payment_reference' => $request->input('payment_id') ?? $request->input('transaction_id'),
                    ]);
                }

                return ['applied' => true, 'sms_confirmation' => null];
            }, 3);
            if ($callbackOutcome['sms_confirmation']) {
                $this->smsAutomation->queuePaymentConfirmation(
                    $callbackOutcome['sms_confirmation']['booking'], $callbackOutcome['sms_confirmation']['amount'],
                    $callbackOutcome['sms_confirmation']['currency'], $callbackOutcome['sms_confirmation']['reference']
                );
            }

            return response()->json([
                'status' => 'success',
                'message' => $callbackOutcome['applied']
                    ? 'Payment callback processed successfully.'
                    : 'Payment callback was already applied.',
                'data' => [
                    'transaction_id' => $request->input('transaction_id'),
                    'status' => $request->input('status')
                ]
            ]);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            throw $e;
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
    public function getPaymentStatus(Request $request, string $id): JsonResponse
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

            $companyId = DB::table('sales_booking_attributions')
                ->where('booking_id', $transaction->booking_id)
                ->whereNull('deleted_at')
                ->value('company_id');
            abort_unless($companyId, 404, 'Transaction not found');
            app(SalesAccessScope::class)->assertCompany(
                $request->user(),
                (string) $companyId,
                'sales.payment-adjustments.create-all',
            );

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
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            throw $e;
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
        $data = $request->validate([
            'amount' => ['nullable', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'max:500'],
            'idempotency_key' => ['required', 'string', 'max:160'],
        ]);

        // Reserve the amount before calling a provider. Pending and uncertain
        // outcomes continue to reserve funds until explicitly reconciled.
        $reservation = DB::transaction(function () use ($id, $data, $request): array {
            $transaction = DB::table('payment_transactions')
                ->where('transaction_id', $id)
                ->lockForUpdate()
                ->first();
            abort_unless($transaction && $transaction->status === 'success', 404,
                'Transaction not found or not eligible for refund.');

            $companyId = DB::table('sales_booking_attributions')->where('booking_id', $transaction->booking_id)
                ->whereNull('deleted_at')->value('company_id');
            abort_unless($companyId, 409, 'This payment has no canonical legal-entity attribution.');
            abort_unless(DB::table('companies')->where('id', $companyId)->where('is_active', true)
                ->whereNull('deleted_at')->exists(), 409, 'This payment legal entity is inactive.');
            app(SalesAccessScope::class)->assertCompany(
                $request->user(),
                (string) $companyId,
                'sales.payment-adjustments.create-all',
            );

            $existing = DB::table('payment_refunds')
                ->where('transaction_id', $transaction->id)
                ->where('idempotency_key', $data['idempotency_key'])
                ->first();
            if ($existing) {
                $amount = round((float) ($data['amount'] ?? $existing->amount), 2);
                $checksum = $this->refundRequestChecksum($amount, (string) $data['reason']);
                abort_unless(hash_equals((string) $existing->request_payload_checksum, $checksum), 409,
                    'This refund key was already used with different facts.');

                return ['row' => $existing, 'replayed' => true];
            }

            $booking = Booking::query()->find($transaction->booking_id);
            $receipt = BookingPaymentReceipt::query()
                ->with('components')
                ->where('booking_id', $transaction->booking_id)
                ->where(function ($query) use ($transaction): void {
                    $query->where('provider_event_id', $transaction->gateway_transaction_id ?? $transaction->transaction_id)
                        ->orWhere('provider_event_id', $transaction->transaction_id)
                        ->orWhere('idempotency_key', 'gateway:'.$transaction->transaction_id);
                })
                ->lockForUpdate()
                ->first();
            $component = $receipt?->components->firstWhere('component_type', 'booking_payment');
            abort_unless($booking && $component, 409,
                'Canonical payment receipt is missing. Reconcile this transaction before refunding it.');
            abort_unless((string) $receipt->company_id === (string) $companyId, 409,
                'The payment receipt legal entity does not match its booking attribution.');
            abort_unless(is_string($transaction->currency) && $transaction->currency !== ''
                && strtoupper($transaction->currency) === strtoupper((string) $receipt->source_currency), 409,
                'The payment transaction currency does not match its canonical receipt. Reconcile before refunding it.');

            $reservedAmount = (float) DB::table('payment_refunds')
                ->where('transaction_id', $transaction->id)
                ->whereIn('status', ['pending', 'completed', 'manual_required'])
                ->sum('amount');
            $transactionRemaining = max(0, round((float) $transaction->amount - $reservedAmount, 2));
            $componentRemaining = max(0, round(
                (float) $component->source_amount - (float) $component->adjusted_source_amount,
                2,
            ));
            // A gateway transaction can exceed or drift from its canonical receipt.
            // Never reserve provider cash that the receipt ledger cannot represent.
            $remaining = min($transactionRemaining, $componentRemaining);
            $amount = round((float) ($data['amount'] ?? $remaining), 2);
            abort_if($amount <= 0, 422, 'This transaction has no remaining refundable amount.');
            abort_if($amount > $remaining, 422,
                "Refund amount cannot exceed the remaining refundable amount of {$remaining}.");

            $refundId = (string) Str::uuid();
            $checksum = $this->refundRequestChecksum($amount, (string) $data['reason']);
            DB::table('payment_refunds')->insert([
                'id' => $refundId,
                'transaction_id' => $transaction->id,
                'amount' => $amount,
                'reason' => $data['reason'],
                'status' => 'pending',
                'idempotency_key' => $data['idempotency_key'],
                'request_payload_checksum' => $checksum,
                'created_by' => $request->user()?->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->recordRefundAudit($refundId, $booking->id, null, 'pending', $amount, $request->user()?->id, [
                'transaction_id' => $transaction->id,
                'request_payload_checksum' => $checksum,
            ]);

            return [
                'row' => (object) [
                    'id' => $refundId,
                    'transaction_id' => $transaction->id,
                    'amount' => $amount,
                    'reason' => $data['reason'],
                    'status' => 'pending',
                    'created_by' => $request->user()?->id,
                ],
                'replayed' => false,
                'transaction' => $transaction,
                'booking' => $booking,
                'receipt' => $receipt,
                'component' => $component,
            ];
        });

        $refund = $reservation['row'];
        if ($reservation['replayed']) {
            return response()->json([
                'status' => 'success',
                'message' => 'Refund request already recorded.',
                'data' => ['refund_id' => $refund->id, 'amount' => (float) $refund->amount, 'status' => $refund->status],
            ]);
        }

        $transaction = $reservation['transaction'];
        $gatewayRefundId = null;
        $refundStatus = 'pending';
        $refundNotes = null;
        try {
            $gateway = $this->gatewayManager->resolve($transaction->payment_method ?? 'webxpay');
            $gatewayResult = $gateway->refund(
                $transaction->gateway_transaction_id ?? $transaction->transaction_id,
                (float) $refund->amount,
                (string) $refund->reason,
            );
            if (! empty($gatewayResult['success'])) {
                $gatewayRefundId = $gatewayResult['refund_id'] ?? null;
                $refundStatus = 'completed';
            } elseif (! empty($gatewayResult['manual'])) {
                $refundStatus = 'manual_required';
                $refundNotes = $gatewayResult['message'] ?? null;
            } else {
                $refundStatus = 'failed';
                $refundNotes = $gatewayResult['message'] ?? null;
            }
        } catch (\Throwable $e) {
            Log::error('Gateway refund outcome is uncertain; keeping the amount reserved.', [
                'transaction_id' => $id,
                'refund_id' => $refund->id,
                'error' => $e->getMessage(),
            ]);
            $refundStatus = 'manual_required';
            $refundNotes = 'Provider outcome is uncertain; reconcile before retrying with a new key.';
        }

        if ($refundStatus === 'completed') {
            try {
                DB::transaction(function () use ($reservation, $refund, $refundStatus, $gatewayRefundId): void {
                    DB::table('payment_refunds')->where('id', $refund->id)->lockForUpdate()->first();
                    DB::table('payment_refunds')->where('id', $refund->id)->update([
                        'status' => $refundStatus,
                        'gateway_refund_id' => $gatewayRefundId,
                        'updated_at' => now(),
                    ]);

                    $receipt = $reservation['receipt'];
                    $fxRate = $receipt->fx_rate_to_lkr !== null ? (float) $receipt->fx_rate_to_lkr : null;
                    $this->paymentAdjustments->record($reservation['booking'], [
                        'receipt_component_id' => $reservation['component']->id,
                        'impact_dimension' => 'cash_receipt',
                        'adjustment_type' => 'refund',
                        'direction' => 'decrease',
                        'source_amount' => (float) $refund->amount,
                        'source_currency' => strtoupper((string) ($receipt->source_currency ?: $reservation['transaction']->currency ?: 'LKR')),
                        'lkr_amount' => $fxRate !== null ? round((float) $refund->amount * $fxRate, 4) : null,
                        'fx_rate_to_lkr' => $fxRate,
                        'adjustment_effective_at' => now(),
                        'reason' => $refund->reason,
                        'reference' => $gatewayRefundId ?: $refund->id,
                        'idempotency_key' => 'gateway-refund:'.$refund->id,
                    ], (string) $refund->created_by);
                    $this->recordRefundAudit(
                        (string) $refund->id,
                        (string) $reservation['booking']->id,
                        'pending',
                        'completed',
                        (float) $refund->amount,
                        $refund->created_by,
                        ['gateway_refund_id' => $gatewayRefundId],
                    );
                });
            } catch (\Throwable $e) {
                // The provider has confirmed cash movement. Keep the amount
                // reserved and flag the ledger mismatch for reconciliation.
                DB::transaction(function () use ($refund, $gatewayRefundId, $reservation): void {
                    DB::table('payment_refunds')->where('id', $refund->id)->update([
                        'status' => 'manual_required',
                        'gateway_refund_id' => $gatewayRefundId,
                        'notes' => 'Provider confirmed refund, but canonical ledger adjustment failed; reconcile before retrying.',
                        'updated_at' => now(),
                    ]);
                    $this->recordRefundAudit(
                        (string) $refund->id,
                        (string) $reservation['booking']->id,
                        'pending',
                        'manual_required',
                        (float) $refund->amount,
                        $refund->created_by,
                        ['gateway_refund_id' => $gatewayRefundId, 'reconciliation_required' => true],
                    );
                });
                Log::error('Confirmed gateway refund could not be reflected in the canonical receipt ledger.', [
                    'refund_id' => $refund->id,
                    'error' => $e->getMessage(),
                ]);
                $refundStatus = 'manual_required';
                $refundNotes = 'Provider confirmed refund; canonical ledger adjustment requires reconciliation.';
            }
        } else {
            DB::transaction(function () use ($refund, $refundStatus, $refundNotes, $reservation): void {
                DB::table('payment_refunds')->where('id', $refund->id)->update([
                    'status' => $refundStatus,
                    'notes' => $refundNotes,
                    'updated_at' => now(),
                ]);
                $this->recordRefundAudit(
                    (string) $refund->id,
                    (string) $reservation['booking']->id,
                    'pending',
                    $refundStatus,
                    (float) $refund->amount,
                    $refund->created_by,
                    ['message' => $refundNotes],
                );
            });
        }

        return response()->json([
            'status' => 'success',
            'message' => $refundStatus === 'completed'
                ? 'Payment refund completed and the collection ledger was adjusted.'
                : 'Refund request recorded; the amount remains reserved until its outcome is reconciled.',
            'data' => ['refund_id' => $refund->id, 'amount' => (float) $refund->amount, 'status' => $refundStatus],
        ], 201);
    }

    private function refundRequestChecksum(float $amount, string $reason): string
    {
        return hash('sha256', json_encode([
            'amount' => number_format($amount, 2, '.', ''),
            'reason' => trim($reason),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function recordRefundAudit(
        string $refundId,
        string $bookingId,
        ?string $fromStatus,
        string $toStatus,
        float $amount,
        ?string $actorId,
        array $metadata = [],
    ): void {
        FinancialAuditEvent::create([
            'subject_type' => 'payment_refund',
            'subject_id' => $refundId,
            'booking_id' => $bookingId,
            'event_type' => $fromStatus === null ? 'payment_refund_reserved' : 'payment_refund_status_changed',
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'amount' => $amount,
            'metadata' => $metadata,
            'performed_by' => $actorId,
            'occurred_at' => now(),
        ]);
    }

    /**
     * Get payment methods
     * GET /api/payments/methods
     */
    public function getPaymentMethods(): JsonResponse
    {
        $enabled = $this->gatewayManager->available();

        $methods = array_map(fn ($gw) => [
            'id' => $gw['method'],
            'name' => $gw['gateway'],
            'description' => "Pay via {$gw['gateway']}",
            'icon' => strtolower(str_replace(' ', '-', $gw['method'])),
            'is_active' => true,
        ], $enabled);

        return response()->json([
            'status' => 'success',
            'message' => empty($methods) ? 'No online payment gateway is currently configured.' : null,
            'data'   => ['payment_methods' => $methods],
        ]);
    }

    /**
     * Get payment transactions
     * GET /api/payment-transactions
     */
    public function getPaymentTransactions(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status' => ['sometimes', 'nullable', 'string', 'max:32'],
            'booking_id' => ['sometimes', 'nullable', 'uuid'],
        ]);
        $limit = (int) ($filters['limit'] ?? 20);
        $status = $filters['status'] ?? null;
        $bookingId = $filters['booking_id'] ?? null;
        $companyIds = app(SalesAccessScope::class)->companyIds(
            $request->user(),
            'sales.payment-adjustments.create-all',
        );

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

        $query->whereExists(function ($attribution) use ($companyIds): void {
            $attribution->selectRaw('1')->from('sales_booking_attributions')
                ->whereColumn('sales_booking_attributions.booking_id', 'payment_transactions.booking_id')
                ->whereNull('sales_booking_attributions.deleted_at')
                ->when($companyIds !== null, fn ($scope) => $scope->whereIn('sales_booking_attributions.company_id', $companyIds));
        });

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
     * List online refunds that need an operator to reconcile provider evidence.
     * GET /api/payment-transactions/refunds/reconciliation
     */
    public function getRefundReconciliationQueue(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:pending,manual_required'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $companyIds = app(SalesAccessScope::class)->companyIds(
            $request->user(),
            'sales.payment-adjustments.create-all',
        );

        $refunds = DB::table('payment_refunds as refunds')
            ->join('payment_transactions as transactions', 'transactions.id', '=', 'refunds.transaction_id')
            ->join('bookings', 'bookings.id', '=', 'transactions.booking_id')
            ->join('sales_booking_attributions as attribution', function ($join): void {
                $join->on('attribution.booking_id', '=', 'bookings.id')->whereNull('attribution.deleted_at');
            })
            ->join('companies', 'companies.id', '=', 'attribution.company_id')
            ->whereIn('refunds.status', ($data['status'] ?? null) ? [$data['status']] : ['pending', 'manual_required'])
            ->where('companies.is_active', true)
            ->whereNull('companies.deleted_at')
            ->when($companyIds !== null, fn ($query) => $query->whereIn('attribution.company_id', $companyIds))
            ->orderByRaw("CASE refunds.status WHEN 'manual_required' THEN 0 ELSE 1 END")
            ->orderBy('refunds.created_at')
            ->select([
                'refunds.id', 'refunds.amount', 'refunds.reason', 'refunds.status',
                'refunds.gateway_refund_id', 'refunds.notes', 'refunds.created_at', 'refunds.updated_at',
                'transactions.id as payment_transaction_id', 'transactions.transaction_id',
                'transactions.gateway_transaction_id', 'transactions.currency', 'bookings.id as booking_id',
                'bookings.booking_number', 'companies.id as company_id', 'companies.name as company_name',
            ])
            ->paginate($data['per_page'] ?? 25);

        return response()->json([
            'status' => 'success',
            'data' => [
                'data' => $refunds->items(),
                'current_page' => $refunds->currentPage(),
                'last_page' => $refunds->lastPage(),
                'per_page' => $refunds->perPage(),
                'total' => $refunds->total(),
            ],
        ]);
    }

    /**
     * List pending online payment attempts with missing or known source reservation facts.
     * GET /api/payment-transactions/initiations/reconciliation
     */
    public function getPaymentInitiationReconciliationQueue(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reservation_state' => ['nullable', 'in:unverified,reserved'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $companyIds = app(SalesAccessScope::class)->companyIds(
            $request->user(),
            'sales.payment-adjustments.create-all',
        );

        $attempts = DB::table('payment_transactions as transactions')
            ->join('bookings', 'bookings.id', '=', 'transactions.booking_id')
            ->join('sales_booking_attributions as attribution', function ($join): void {
                $join->on('attribution.booking_id', '=', 'bookings.id')->whereNull('attribution.deleted_at');
            })
            ->join('companies', 'companies.id', '=', 'attribution.company_id')
            ->where('transactions.status', 'pending')
            ->where('companies.is_active', true)
            ->whereNull('companies.deleted_at')
            ->when($companyIds !== null, fn ($query) => $query->whereIn('attribution.company_id', $companyIds))
            ->when(($data['reservation_state'] ?? 'unverified') === 'unverified', fn ($query) => $query
                ->where(function ($reservation): void {
                    $reservation->whereNull('transactions.requested_amount')
                        ->orWhereNull('transactions.requested_currency');
                }))
            ->when(($data['reservation_state'] ?? 'unverified') === 'reserved', fn ($query) => $query
                ->whereNotNull('transactions.requested_amount')->whereNotNull('transactions.requested_currency'))
            ->orderByRaw('CASE WHEN transactions.requested_amount IS NULL OR transactions.requested_currency IS NULL THEN 0 ELSE 1 END')
            ->orderBy('transactions.created_at')
            ->select([
                'transactions.id', 'transactions.transaction_id', 'transactions.gateway_transaction_id',
                'transactions.payment_method', 'transactions.amount', 'transactions.currency',
                'transactions.requested_amount', 'transactions.requested_currency', 'transactions.created_at',
                'bookings.id as booking_id', 'bookings.booking_number',
                'companies.id as company_id', 'companies.name as company_name',
            ])
            ->selectRaw("CASE WHEN transactions.requested_amount IS NULL OR transactions.requested_currency IS NULL THEN 'unverified' ELSE 'reserved' END as reservation_state")
            ->paginate($data['per_page'] ?? 25);

        return response()->json([
            'status' => 'success',
            'data' => [
                'data' => $attempts->items(),
                'current_page' => $attempts->currentPage(),
                'last_page' => $attempts->lastPage(),
                'per_page' => $attempts->perPage(),
                'total' => $attempts->total(),
            ],
        ]);
    }

    /**
     * Get transaction details
     * GET /api/payment-transactions/{id}
     */
    public function getTransactionDetails(Request $request, string $id): JsonResponse
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

            $companyId = DB::table('sales_booking_attributions')
                ->where('booking_id', $transaction->booking_id)
                ->whereNull('deleted_at')
                ->value('company_id');
            abort_unless($companyId, 404, 'Transaction not found');
            app(SalesAccessScope::class)->assertCompany(
                $request->user(),
                (string) $companyId,
                'sales.payment-adjustments.create-all',
            );

            $transaction->refunds = DB::table('payment_refunds')
                ->where('transaction_id', $transaction->id)
                ->orderBy('created_at')
                ->get(['id', 'amount', 'reason', 'status', 'gateway_refund_id', 'notes', 'created_by', 'created_at', 'updated_at']);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'transaction' => $transaction
                ]
            ]);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            throw $e;
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
        if (! is_string($secret) || strlen($secret) < 32) {
            return false;
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

