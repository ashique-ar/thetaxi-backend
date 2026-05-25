<?php

namespace App\Contracts;

use App\Models\Booking\Booking;

interface PaymentGatewayInterface
{
    /** Return true if the gateway is enabled and configured. */
    public function isEnabled(): bool;

    /**
     * Initiate a payment for a booking.
     * Must return at minimum:
     *   ['success' => bool, 'payment_url' => string, 'transaction_id' => string]
     */
    public function initiatePayment(Booking $booking, float $amount, string $currency, array $options = []): array;

    /**
     * Verify a payment callback payload.
     * Returns ['success' => bool, 'status' => string, 'transaction_id' => string, 'amount' => float].
     */
    public function verifyCallback(array $payload): array;

    /**
     * Execute a refund.
     * Returns ['success' => bool, 'refund_id' => string|null, 'message' => string].
     */
    public function refund(string $gatewayTransactionId, float $amount, string $reason = ''): array;

    /** Human-readable gateway name (e.g. 'WebXPay', 'Stripe'). */
    public function getName(): string;
}
