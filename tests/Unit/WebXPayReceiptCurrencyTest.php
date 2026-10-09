<?php

use App\Http\Controllers\CheckoutController;
use App\Models\Booking\Booking;
use App\Services\BookingPaymentLedgerService;

function webxpayReceiptCurrencyFacts(array $snapshot): array
{
    $controller = (new ReflectionClass(CheckoutController::class))->newInstanceWithoutConstructor();
    return (new ReflectionMethod($controller, 'gatewayReceiptCurrencyFacts'))->invoke($controller, $snapshot);
}

it('records the exact LKR charge for an older foreign-currency booking snapshot', function () {
    $facts = webxpayReceiptCurrencyFacts([
        'booking_amount' => 1200.25, 'booking_currency' => 'INR',
        'amount' => 5138, 'currency' => 'LKR',
    ]);
    expect($facts['source_currency'])->toBe('INR')
        ->and($facts['source_amount'])->toBe(1200.25)
        ->and(round($facts['source_amount'] * $facts['fx_rate_to_lkr'], 4))->toBe(5138.0)
        ->and($facts['fx_source'])->toBe('webxpay_charge_snapshot');
});

it('keeps identity conversion for an LKR booking', function () {
    expect(webxpayReceiptCurrencyFacts([
        'booking_amount' => 750, 'booking_currency' => 'LKR', 'amount' => 750, 'currency' => 'LKR',
    ])['fx_rate_to_lkr'])->toBe(1.0);
});

it('uses the saved initiation rate for non-LKR gateway charges', function () {
    config(['booking.webxpay.currency' => 'LKR']);
    $facts = webxpayReceiptCurrencyFacts([
        'booking_amount' => 25, 'booking_currency' => 'USD', 'amount' => 23, 'currency' => 'EUR',
        'fx_rate_to_lkr' => 300, 'fx_rate_at' => '2026-10-09T09:00:00Z',
    ]);
    expect($facts['fx_rate_to_lkr'])->toBe(300.0)
        ->and($facts['fx_rate_at'])->toBe('2026-10-09T09:00:00Z');
});

it('rejects missing rate or malformed evidence instead of inventing a conversion', function (array $snapshot) {
    webxpayReceiptCurrencyFacts($snapshot);
})->with([
    [['booking_amount' => 25, 'booking_currency' => 'USD', 'amount' => 23, 'currency' => 'EUR']],
    [['booking_amount' => 0, 'booking_currency' => 'INR', 'amount' => 5138, 'currency' => 'LKR']],
    [['booking_amount' => 100, 'booking_currency' => '₹', 'amount' => 5138, 'currency' => 'LKR']],
])->throws(RuntimeException::class);

it('passes saved currency evidence and advance stage into the canonical receipt writer', function () {
    $booking = new Booking();
    $booking->currency = 'INR';
    $booking->payment_type = 'advance';
    $booking->workflow_data = ['gateway_payment_attempts' => [[
        'order_id' => 'BK-TEST-123', 'booking_amount' => 100, 'booking_currency' => 'INR',
        'amount' => 400, 'currency' => 'LKR',
    ]]];
    $ledger = Mockery::mock(BookingPaymentLedgerService::class);
    $ledger->shouldReceive('receive')->once()->withArgs(function ($receivedBooking, $data, $actor) use ($booking) {
        expect($receivedBooking)->toBe($booking)
            ->and($data['amount'])->toBe(100.0)
            ->and($data['source_currency'])->toBe('INR')
            ->and($data['source_amount'])->toBe(100.0)
            ->and($data['fx_rate_to_lkr'])->toBe(4.0)
            ->and($data['payment_stage'])->toBe('advance')
            ->and($data['idempotency_key'])->toBe('webxpay:TX-TEST')
            ->and($actor)->toBeNull();
        return true;
    });
    app()->instance(BookingPaymentLedgerService::class, $ledger);
    $controller = (new ReflectionClass(CheckoutController::class))->newInstanceWithoutConstructor();
    (new ReflectionMethod($controller, 'recordVerifiedWebXPayReceipt'))->invoke($controller, $booking, [
        'order_id' => 'BK-TEST-123', 'transaction_id' => 'TX-TEST', 'payment_type' => 'advance',
    ]);
});
