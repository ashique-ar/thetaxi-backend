<?php

use App\Http\Controllers\CheckoutController;
use App\Models\Booking\Booking;
use App\Services\WebXPayService;
use App\Services\CurrencyService;

it('uses the configured gateway currency while retaining website currency and receipt evidence', function (string $bookingCurrency, string $gatewayCurrency, float $expectedGatewayAmount) {
    $currencyService = Mockery::mock(CurrencyService::class)->makePartial();
    $currencyService->shouldReceive('isValidCurrency')->andReturnUsing(fn ($code) => in_array($code, ['LKR', 'USD'], true));
    $currencyService->shouldReceive('getExchangeRate')->with('INR', 'LKR')->andReturn(4.0);
    $currencyService->shouldReceive('getExchangeRate')->with('INR', 'USD')->andReturn(4 / 300);
    $currencyService->shouldReceive('getExchangeRate')->with('USD', 'LKR')->andReturn(300.0);
    app()->instance(CurrencyService::class, $currencyService);
    config([
        'booking.webxpay.enabled' => true,
        'booking.webxpay.merchant_secret' => 'test-secret',
        'booking.webxpay.public_key' => 'test-public-key',
        'booking.webxpay.checkout_url' => 'https://webxpay.test/checkout',
        'booking.webxpay.currency' => 'LKR',
    ]);

    $service = new class($gatewayCurrency) extends WebXPayService {
        public function __construct(private string $settingCurrency)
        {
            parent::__construct();
        }

        protected function getSettingValue($key, $default = null)
        {
            return $key === 'webxpay_currency' ? $this->settingCurrency : $default;
        }

        protected function encryptWithPublicKey(string $plaintext): ?string
        {
            return base64_encode($plaintext);
        }
    };

    $booking = new Booking();
    $booking->id = 'booking-id';
    $booking->booking_number = 'BK-DISCOUNT';
    $booking->currency = $bookingCurrency;
    $booking->workflow_data = ['display_currency' => $bookingCurrency];
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

    $result = $service->createPayment($booking, 750.99, 'full', $bookingCurrency);
    $gatewayFxRate = $gatewayCurrency === 'LKR' ? 1.0 : 300.0;
    expect($result['success'])->toBeTrue()
        ->and($result['amount'])->toBe($expectedGatewayAmount)
        ->and($result['currency'])->toBe($gatewayCurrency)
        ->and($result['customer_data']['process_currency'])->toBe($gatewayCurrency)
        ->and($result['booking_currency'])->toBe($bookingCurrency)
        ->and($booking->workflow_data['display_currency'])->toBe($bookingCurrency)
        ->and($result['fx_rate_to_lkr'])->toBe($bookingCurrency === 'LKR' ? 1.0 : ($expectedGatewayAmount * $gatewayFxRate) / 750.99)
        ->and($result['fx_rate_at'])->not->toBeEmpty()
        ->and(base64_decode($result['encrypted_payment']))->toBe($result['order_id'] . '|' . (int) $expectedGatewayAmount);

    $controller = file_get_contents(dirname(__DIR__, 2) . '/app/Http/Controllers/CheckoutController.php');
    expect($controller)
        ->toContain("'discount_amount' => \$discount")
        ->toContain("'total_estimated' => \$total")
        ->toContain("'amount_to_pay' => \$paymentAmount")
        ->toContain('$this->processPayment($booking, $validated, $paymentAmount)')
        ->toContain('$this->webxPayService->createPayment($booking, $amount, $paymentType)');
})->with([
    ['LKR', 'LKR', 750.0],
    ['INR', 'LKR', 3003.0],
    ['INR', 'USD', 10.0],
    ['USD', 'USD', 750.0],
]);
