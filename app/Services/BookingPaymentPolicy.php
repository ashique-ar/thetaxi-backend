<?php

namespace App\Services;

class BookingPaymentPolicy
{
    public const ARRANGEMENTS = [
        'cash_to_driver', 'online', 'monthly_invoice', 'bank_transfer', 'card',
        'advance_then_balance', 'deposit_then_balance', 'pay_at_end',
        'account_credit', 'complimentary', 'other',
    ];

    private const DRIVER_COLLECTION_METHODS = [
        'cash_to_driver', 'cash', 'driver_cash', 'pay_to_driver',
        'advance_then_balance', 'deposit_then_balance', 'pay_at_end',
    ];

    private const SETTLED_STATUSES = [
        'driver_collected', 'online_paid', 'paid', 'success', 'refunded', 'waived',
    ];

    public function collectionMethod(object|null $booking): string
    {
        if (! $booking) {
            return '';
        }

        return strtolower(trim((string) (
            $booking->payment_collection_method
            ?? $booking->payment_method
            ?? $booking->payment_type
            ?? ''
        )));
    }

    public function requiresDriverCollection(object|null $booking): bool
    {
        if (! $booking) {
            return false;
        }

        $method = $this->collectionMethod($booking);
        if ($method === '') {
            return ! (bool) ($booking->is_corporate_booking ?? false)
                && empty($booking->corporate_account_id);
        }

        if (! $this->isDriverPricedMethod($booking)) {
            return false;
        }

        $collectionStatus = strtolower((string) ($booking->payment_collection_status ?? ''));
        $paymentStatus = strtolower((string) ($booking->payment_status ?? ''));

        return ! in_array($collectionStatus, self::SETTLED_STATUSES, true)
            && ! in_array($paymentStatus, ['paid', 'refunded', 'waived'], true);
    }

    public function driverCanViewPricing(object|null $booking): bool
    {
        return $this->isDriverPricedMethod($booking);
    }

    private function isDriverPricedMethod(object|null $booking): bool
    {
        if (! $booking) {
            return false;
        }

        $method = $this->collectionMethod($booking);
        if ($method === '') {
            return ! (bool) ($booking->is_corporate_booking ?? false)
                && empty($booking->corporate_account_id);
        }

        return in_array($method, self::DRIVER_COLLECTION_METHODS, true);
    }

    public function isMonthlyCorporateCredit(object|null $booking): bool
    {
        $method = $this->collectionMethod($booking);

        return in_array($method, ['monthly_invoice', 'corporate', 'company_billing', 'credit'], true)
            || str_contains($method, 'corp');
    }
}
