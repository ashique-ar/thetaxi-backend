<?php

use App\Services\WebXPayService;

it('accepts both WebXPay response field orders used by the guide and team sample', function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $privateKey);
    $publicKey = openssl_pkey_get_details($key)['key'];

    $service = (new ReflectionClass(WebXPayService::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(WebXPayService::class, 'publicKey'))->setValue($service, $publicKey);

    $verify = function (string $payment) use ($service, $privateKey) {
        openssl_private_encrypt($payment, $signature, $privateKey);
        return $service->verifyPayment([
            'payment' => base64_encode($payment),
            'signature' => base64_encode($signature),
            'custom_fields' => base64_encode('booking-id|full|BK123|customer-id'),
        ]);
    };

    $documented = $verify('BK123-1790742818|TX123|2026-10-07 12:00:00|0|Transaction Approved|1');
    $teamSample = $verify('BK123-1790742818|TX123|2026-10-07 12:00:00|1|00|Transaction Approved');

    expect($documented['success'])->toBeTrue()
        ->and($documented['status_code'])->toBe('0')
        ->and($documented['payment_gateway'])->toBe('1')
        ->and($documented['booking_number'])->toBe('BK123')
        ->and($teamSample['success'])->toBeTrue()
        ->and($teamSample['status_code'])->toBe('00')
        ->and($teamSample['payment_gateway'])->toBe('1');
});

it('records each verified WebXPay callback through one canonical receipt write', function () {
    $controller = file_get_contents(dirname(__DIR__, 2) . '/app/Http/Controllers/CheckoutController.php');
    $callback = substr($controller, strpos($controller, 'public function webxpayCallback'), strpos($controller, 'public function webxpayNotify') - strpos($controller, 'public function webxpayCallback'));
    $notify = substr($controller, strpos($controller, 'public function webxpayNotify'), strpos($controller, 'public function mockGateway') - strpos($controller, 'public function webxpayNotify'));

    expect($callback)->toContain("'idempotency_key' => 'webxpay:'")
        ->and(substr_count($callback, '$this->recordGatewayReceipt('))->toBe(1)
        ->and($notify)->toContain('$this->recordVerifiedWebXPayReceipt(')
        ->and($notify)->not->toContain('$this->recordGatewayReceipt(');
});
