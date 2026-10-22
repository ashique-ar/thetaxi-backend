<?php

namespace App\Services;

use App\Models\Corporate\Corporate;
use App\Models\Finance\CorporateOnlinePaymentAttempt;
use App\Models\Finance\FinancialAccountSettlement;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CorporateOnlinePaymentService
{
    public function __construct(
        private readonly WebXPayService $webXPay,
        private readonly FinancialAccountSettlementService $settlements,
    ) {}

    public function initiate(string $corporateId, string $settlementId, ?string $userId, array $customer): array
    {
        if (!$this->webXPay->isEnabled()) {
            abort(503, 'Online invoice payment is not available. Contact finance for payment options.');
        }

        return DB::transaction(function () use ($corporateId, $settlementId, $userId, $customer): array {
            $settlement = FinancialAccountSettlement::query()
                ->where('owner_type', 'corporate')
                ->where('owner_id', $corporateId)
                ->whereIn('status', ['open', 'partial', 'overdue'])
                ->with('items.booking:id,currency')
                ->lockForUpdate()
                ->findOrFail($settlementId);
            $currency = strtoupper((string) (data_get($settlement->billing_terms_snapshot, 'currency')
                ?: $settlement->items->first()?->booking?->currency ?: ''));
            $currencyService = app(CurrencyService::class);
            $amount = $currencyService->roundAmount((float) $settlement->outstanding_total, $currency);
            if (!preg_match('/^[A-Z]{3}$/', $currency) || $amount <= 0
                || abs((float) $settlement->outstanding_total - $amount) > 0.00000001) {
                throw ValidationException::withMessages(['settlement' => ['This invoice has no payable balance or its balance exceeds the currency precision.']]);
            }

            $pending = CorporateOnlinePaymentAttempt::query()
                ->where('settlement_id', $settlement->id)
                ->where('status', 'initiated')
                ->lockForUpdate()
                ->latest()
                ->first();
            if ($pending) {
                if ($pending->invoice_currency === $currency && abs((float) $pending->invoice_amount - $amount) < 0.00000001) {
                    return $this->paymentSummary($pending);
                }
                $pending->update(['status' => 'superseded', 'gateway_payload' => null]);
            }

            $corporate = Corporate::query()->findOrFail($corporateId);
            $attemptId = (string) Str::uuid();
            $orderId = 'CI'.str_replace('-', '', $attemptId);
            $gateway = $this->webXPay->createCorporateInvoicePayment(
                $orderId,
                $attemptId,
                (string) ($settlement->invoice_number ?: $settlement->settlement_number),
                $corporateId,
                $amount,
                $currency,
                $customer,
            );
            $attempt = CorporateOnlinePaymentAttempt::create([
                'id' => $attemptId,
                'corporate_id' => $corporateId,
                'settlement_id' => $settlement->id,
                'order_id' => $orderId,
                'invoice_currency' => $currency,
                'invoice_amount' => $amount,
                'gateway_currency' => $gateway['currency'],
                'gateway_amount' => $gateway['amount'],
                'fx_rate_to_lkr' => $gateway['fx_rate_to_lkr'],
                'gateway_fx_rate_to_lkr' => $gateway['gateway_fx_rate_to_lkr'],
                'fx_rate_at' => $gateway['fx_rate_at'],
                'gateway_payload' => Crypt::encryptString(json_encode($gateway, JSON_THROW_ON_ERROR)),
                'status' => 'initiated',
                'created_user_id' => $userId,
            ]);

            return $this->paymentSummary($attempt);
        });
    }

    public function redirectUrl(string $attemptId): string
    {
        return URL::temporarySignedRoute(
            'checkout.corporate-webxpay.redirect',
            now()->addMinutes(20),
            ['attempt' => $attemptId],
        );
    }

    private function paymentSummary(CorporateOnlinePaymentAttempt $attempt): array
    {
        return [
            'redirect_url' => $this->redirectUrl($attempt->id),
            'invoice_amount' => (float) $attempt->invoice_amount,
            'invoice_currency' => $attempt->invoice_currency,
            'gateway_amount' => (float) $attempt->gateway_amount,
            'gateway_currency' => $attempt->gateway_currency,
            'fx_rate_to_lkr' => (float) $attempt->fx_rate_to_lkr,
            'fx_rate_at' => $attempt->fx_rate_at?->toIso8601String(),
        ];
    }

    public function redirectPayload(string $attemptId): array
    {
        $attempt = CorporateOnlinePaymentAttempt::query()->where('status', 'initiated')->findOrFail($attemptId);
        abort_unless($attempt->gateway_payload, 410, 'This payment attempt is no longer available.');
        return json_decode(Crypt::decryptString($attempt->gateway_payload), true, 512, JSON_THROW_ON_ERROR);
    }

    public function cancel(string $attemptId): array
    {
        return DB::transaction(function () use ($attemptId): array {
            $attempt = CorporateOnlinePaymentAttempt::query()->lockForUpdate()->findOrFail($attemptId);
            if (in_array($attempt->status, ['initiated', 'superseded'], true)) {
                $attempt->update(['status' => 'cancelled', 'gateway_payload' => null]);
            }

            return [
                'paid' => $attempt->status === 'paid',
                'settlement_id' => $attempt->settlement_id,
                'reference' => $attempt->gateway_transaction_id,
            ];
        });
    }

    public function completeFromCallback(array $verification): ?array
    {
        $orderId = (string) ($verification['order_id'] ?? '');
        if ($orderId === '') {
            return null;
        }
        $attemptId = CorporateOnlinePaymentAttempt::query()->where('order_id', $orderId)->value('id');
        if (!$attemptId) {
            return null;
        }

        return DB::transaction(function () use ($attemptId, $verification): array {
            $attempt = CorporateOnlinePaymentAttempt::query()->lockForUpdate()->findOrFail($attemptId);
            if ($attempt->status === 'paid') {
                return ['paid' => true, 'settlement_id' => $attempt->settlement_id, 'reference' => $attempt->gateway_transaction_id];
            }
            if (!in_array($attempt->status, ['initiated', 'superseded', 'cancelled'], true)) {
                return ['paid' => false, 'settlement_id' => $attempt->settlement_id, 'reference' => null];
            }
            if (($verification['success'] ?? false) !== true) {
                $attempt->update(['status' => 'failed', 'gateway_payload' => null]);
                return ['paid' => false, 'settlement_id' => $attempt->settlement_id, 'reference' => null];
            }

            $corporate = Corporate::query()->findOrFail($attempt->corporate_id);
            $this->settlements->receiveCorporateRemittance($corporate, [
                'idempotency_key' => 'webxpay-corporate:'.$attempt->id,
                'currency' => $attempt->invoice_currency,
                'payment_method' => 'webxpay',
                'amount' => (float) $attempt->invoice_amount,
                'reference' => (string) (($verification['transaction_id'] ?? null) ?: $attempt->order_id),
                'received_at' => now(),
                'settlement_ids' => [$attempt->settlement_id],
                'notes' => 'Verified WebXPay corporate invoice payment.',
            ], null, [
                'fx_rate_to_lkr' => (float) $attempt->fx_rate_to_lkr,
                'fx_rate_at' => $attempt->fx_rate_at,
                'fx_source' => 'webxpay_configured_rate_at_initiation',
            ]);
            $attempt->update([
                'status' => 'paid',
                'gateway_transaction_id' => filled($verification['transaction_id'] ?? null) ? $verification['transaction_id'] : null,
                'paid_at' => now(),
                'gateway_payload' => null,
            ]);

            return ['paid' => true, 'settlement_id' => $attempt->settlement_id, 'reference' => $attempt->gateway_transaction_id];
        });
    }

    public function resultUrl(array $result, bool $cancelled = false): string
    {
        $status = $cancelled ? 'cancelled' : ($result['paid'] ? 'success' : 'failed');
        $query = http_build_query([
            'payment' => $status,
            'settlement' => $result['settlement_id'],
            'reference' => $result['reference'] ?? null,
        ]);
        return rtrim((string) config('app.portal_url', config('app.url')), '/').'/corporate/finance?'.$query;
    }
}
