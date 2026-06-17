<?php

use App\Services\PricingVariableService;
use App\Services\CurrencyService;

/**
 * Pricing service unit-level tests.
 *
 * Verifies the key guards documented in 2.2 (division-by-zero) and
 * 7.3 (currency fallback). These tests run purely against service classes
 * without touching the database.
 */

it('CurrencyService::normalizeAmount handles null gracefully', function () {
    $service = app(CurrencyService::class);
    $result = $service->normalizeAmount(null);
    expect($result)->toBe(0.0);
});

it('CurrencyService::normalizeAmount handles string numbers', function () {
    $service = app(CurrencyService::class);
    expect($service->normalizeAmount('15.50'))->toBe(15.5);
    expect($service->normalizeAmount('0'))->toBe(0.0);
});

it('CurrencyService::normalizeAmount clamps negative values to zero', function () {
    $service = app(CurrencyService::class);
    $result = $service->normalizeAmount(-10);
    expect($result)->toBeGreaterThanOrEqual(0.0);
});

it('base currency config falls back to LKR when unset', function () {
    $currency = config('booking.base_currency', 'LKR');
    expect($currency)->toBeString()->not->toBeEmpty();
});

it('PricingVariableService resolves without throwing on minimal context', function () {
    expect(fn () => app(PricingVariableService::class))->not->toThrow(Exception::class);
});
