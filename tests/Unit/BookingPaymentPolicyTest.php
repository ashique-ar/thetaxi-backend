<?php

use App\Services\BookingPaymentPolicy;

function paymentPolicyBooking(string $method, string $collectionStatus = 'pending', string $paymentStatus = 'pending'): object
{
    return (object) [
        'payment_collection_method' => $method,
        'payment_collection_status' => $collectionStatus,
        'payment_status' => $paymentStatus,
        'is_corporate_booking' => $method === 'monthly_invoice',
        'corporate_account_id' => $method === 'monthly_invoice' ? 'corporate-a' : null,
    ];
}

it('exposes pricing to drivers only for cash-based driver arrangements', function (string $method, string $status, bool $visible) {
    expect(app(BookingPaymentPolicy::class)->driverCanViewPricing(
        paymentPolicyBooking($method, $status)
    ))->toBe($visible);
})->with([
    'cash unpaid' => ['cash_to_driver', 'pending', true],
    'cash partially paid' => ['cash_to_driver', 'partially_collected', true],
    'cash settled remains visible without another collection' => ['cash_to_driver', 'driver_collected', true],
    'advance balance due' => ['advance_then_balance', 'partially_collected', true],
    'monthly corporate credit' => ['monthly_invoice', 'billable', false],
    'account credit' => ['account_credit', 'pending', false],
    'online' => ['online', 'pending', false],
    'card' => ['card', 'pending', false],
    'bank transfer' => ['bank_transfer', 'pending', false],
    'complimentary' => ['complimentary', 'waived', false],
]);

it('keeps legacy blank methods cash for individuals and hidden for corporates', function () {
    $policy = app(BookingPaymentPolicy::class);

    expect($policy->driverCanViewPricing((object) [
        'is_corporate_booking' => false,
        'corporate_account_id' => null,
        'payment_collection_status' => 'pending',
        'payment_status' => 'pending',
    ]))->toBeTrue()->and($policy->driverCanViewPricing((object) [
        'is_corporate_booking' => true,
        'corporate_account_id' => 'corporate-a',
        'payment_collection_status' => 'pending',
        'payment_status' => 'pending',
    ]))->toBeFalse();
});

it('separates fare visibility from whether another collection is due', function () {
    $policy = app(BookingPaymentPolicy::class);
    $settledCash = paymentPolicyBooking('cash_to_driver', 'driver_collected', 'paid');
    $partialCash = paymentPolicyBooking('cash_to_driver', 'partially_collected', 'partially_paid');

    expect($policy->driverCanViewPricing($settledCash))->toBeTrue()
        ->and($policy->requiresDriverCollection($settledCash))->toBeFalse()
        ->and($policy->driverCanViewPricing($partialCash))->toBeTrue()
        ->and($policy->requiresDriverCollection($partialCash))->toBeTrue();
});
