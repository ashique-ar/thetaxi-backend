<?php

namespace App\Services;

use App\Models\Finance\StaffPaymentLink;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class StaffPaymentLinkService
{
    public function __construct(private readonly WebXPayService $webXPay) {}

    public function create(string $userId, float $amount): array
    {
        abort_unless($this->webXPay->isEnabled(), 503, 'WebXPay is not available. Contact an administrator.');
        abort_unless($this->webXPay->currency() === 'LKR', 503, 'Staff payment links require WebXPay to be configured for LKR.');
        $currencyService = app(CurrencyService::class);
        $amount = $currencyService->roundAmount($amount, 'LKR');
        abort_unless(is_finite($amount) && $amount >= 1 && floor($amount) === $amount, 422, 'Enter a positive whole-number LKR amount.');
        $currency = 'LKR';

        return DB::transaction(function () use ($userId, $amount): array {
            $id = (string) Str::uuid();
            $orderId = 'PL'.str_replace('-', '', $id);
            $redirect = URL::temporarySignedRoute('checkout.staff-payment-link.redirect', now()->addDays(30), ['link' => $id]);
            $cancel = URL::temporarySignedRoute('checkout.staff-payment-link.cancel', now()->addDays(30), ['link' => $id]);
            $gateway = $this->webXPay->createCorporateInvoicePayment(
                $orderId, $id, $orderId, 'staff', $amount, 'LKR', [],
                route('checkout.staff-payment-link.callback'), $cancel,
                route('checkout.staff-payment-link.notify'), implode('|', [$id, 'staff_payment_link', $orderId, $userId]),
            );
            StaffPaymentLink::query()->create([
                'id' => $id, 'created_user_id' => $userId, 'order_id' => $orderId,
                'amount' => $amount, 'currency' => $currency,
                'gateway_amount' => $gateway['amount'], 'gateway_currency' => $gateway['currency'],
                'fx_rate_to_lkr' => $gateway['fx_rate_to_lkr'],
                'gateway_fx_rate_to_lkr' => $gateway['gateway_fx_rate_to_lkr'],
                'fx_rate_at' => $gateway['fx_rate_at'],
                'gateway_payload' => Crypt::encryptString(json_encode($gateway, JSON_THROW_ON_ERROR)),
                'status' => 'initiated',
            ]);

            return ['url' => $redirect, 'amount' => $amount, 'currency' => $currency,
                'gateway_amount' => $gateway['amount'], 'gateway_currency' => $gateway['currency']];
        });
    }

    public function redirectPayload(string $id): array
    {
        $link = StaffPaymentLink::query()->where('status', 'initiated')->findOrFail($id);
        abort_unless($link->gateway_payload, 410, 'This payment link is no longer available.');
        return json_decode(Crypt::decryptString($link->gateway_payload), true, 512, JSON_THROW_ON_ERROR);
    }

    public function cancel(string $id): void
    {
        StaffPaymentLink::query()->whereKey($id)->where('status', 'initiated')->update(['status' => 'cancelled', 'gateway_payload' => null]);
    }

    public function complete(array $verification): ?bool
    {
        $id = StaffPaymentLink::query()->where('order_id', $verification['order_id'] ?? '')->value('id');
        if (!$id) return null;

        return DB::transaction(function () use ($id, $verification): bool {
            $link = StaffPaymentLink::query()->lockForUpdate()->findOrFail($id);
            if ($link->status === 'paid') return true;
            if ($link->status !== 'initiated') return false;
            if (($verification['success'] ?? false) !== true) {
                $link->update(['status' => 'failed', 'gateway_payload' => null]);
                return false;
            }
            $link->update([
                'status' => 'paid', 'gateway_transaction_id' => $verification['transaction_id'] ?? null,
                'paid_at' => now(), 'gateway_payload' => null,
            ]);
            return true;
        });
    }
}
