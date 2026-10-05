<?php

use App\Http\Controllers\CheckoutController;
use App\Models\Booking\Booking;
use App\Services\WebXPayService;

it('serializes the discounted payable amount passed through checkout for WebXPay', function () {
    config([
        'booking.webxpay.enabled' => true,
        'booking.webxpay.merchant_secret' => 'test-secret',
        'booking.webxpay.public_key' => 'test-public-key',
        'booking.webxpay.checkout_url' => 'https://webxpay.test/checkout',
        'booking.webxpay.currency' => 'LKR',
    ]);

    $service = new class extends WebXPayService {
        protected function getSettingValue($key, $default = null)
        {
            return $default;
        }

        protected function encryptWithPublicKey(string $plaintext): ?string
        {
            return base64_encode($plaintext);
        }
    };

    $booking = new Booking();
    $booking->id = 'booking-id';
    $booking->booking_number = 'BK-DISCOUNT';
    $booking->currency = 'LKR';
    $booking->workflow_data = ['display_currency' => 'LKR'];
    $booking->setRelation('customer', (object) [
        'id' => 'customer-id',
        'address' => 'Test address',
        'city' => 'Colombo',
        'user' => (object) [
            'first_name' => 'Test',
            'last_name' => 'Customer',
            'full_name' => 'Test Customer',
            'email' => 'test@example.com',
            'phone' => '0771234567',
            'address' => 'Test address',
            'city' => 'Colombo',
        ],
    ]);

    $result = $service->createPayment($booking, 750.99, 'full', 'LKR');
    expect($result['success'])->toBeTrue()
        ->and($result['amount'])->toBe(750.0)
        ->and(base64_decode($result['encrypted_payment']))->toBe($result['order_id'] . '|750');

    $controller = file_get_contents(dirname(__DIR__, 2) . '/app/Http/Controllers/CheckoutController.php');
    expect($controller)
        ->toContain("'discount_amount' => \$discount")
        ->toContain("'total_estimated' => \$total")
        ->toContain("'amount_to_pay' => \$paymentAmount")
        ->toContain('$this->processPayment($booking, $validated, $paymentAmount)')
        ->toContain('$this->webxPayService->createPayment($booking, $amount, $paymentType)');
});
