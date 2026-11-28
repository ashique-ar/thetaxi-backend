<?php

use App\Services\BookingPaymentLedgerService;
use App\Services\CurrencyService;

it('converts LKR refundable security deposit to the booking currency', function () {
    $currencyService = Mockery::mock(CurrencyService::class);
    $currencyService->shouldReceive('convertFromLKR')
        ->once()
        ->with(50000.0, 'USD')
        ->andReturn(166.67);
    app()->instance(CurrencyService::class, $currencyService);

    $service = (new ReflectionClass(BookingPaymentLedgerService::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod($service, 'requiredSecurityDeposit');

    $item = (object) [
        'workflow_config_snapshot' => ['requires_refundable_deposit' => true],
        'metadata' => ['refundable_deposit_lkr' => 50000],
        'serviceType' => null,
        'vehicleGroup' => null,
    ];

    expect($method->invoke($service, [$item], 'USD'))->toBe(166.67);
});

it('keeps LKR refundable security deposit unchanged for LKR bookings', function () {
    $currencyService = Mockery::mock(CurrencyService::class);
    $currencyService->shouldReceive('convertFromLKR')
        ->once()
        ->with(50000.0, 'LKR')
        ->andReturn(50000.0);
    app()->instance(CurrencyService::class, $currencyService);

    $service = (new ReflectionClass(BookingPaymentLedgerService::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod($service, 'requiredSecurityDeposit');

    $item = (object) [
        'workflow_config_snapshot' => ['requires_refundable_deposit' => true],
        'metadata' => ['refundable_deposit_lkr' => 50000],
        'serviceType' => null,
        'vehicleGroup' => null,
    ];

    expect($method->invoke($service, [$item], 'LKR'))->toBe(50000.0);
});
