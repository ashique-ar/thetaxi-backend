<?php

use App\Services\WebXPayService;

it('accepts the documented WebXPay approved response and reads its fields in order', function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $privateKey);
    $publicKey = openssl_pkey_get_details($key)['key'];

    $service = (new ReflectionClass(WebXPayService::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(WebXPayService::class, 'publicKey'))->setValue($service, $publicKey);

    $payment = 'BK123-1790742818|TX123|2026-10-07 12:00:00|0|Transaction Approved|1';
    openssl_private_encrypt($payment, $signature, $privateKey);
    $result = $service->verifyPayment([
        'payment' => base64_encode($payment),
        'signature' => base64_encode($signature),
        'custom_fields' => base64_encode('booking-id|full|BK123|customer-id'),
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['status_code'])->toBe('0')
        ->and($result['payment_gateway'])->toBe('1')
        ->and($result['booking_number'])->toBe('BK123');
});
