<?php

use App\Services\CurrencyService;

function bookingSummaryConverter(string $component, string $currency, ?string $source_currency): Closure
{
    $template = file_get_contents(resource_path("views/components/{$component}.blade.php"));
    $start = strpos($template, '@php') + strlen('@php');
    $end = strpos($template, $component === 'booking-payment-summary' ? '$bookingAddons =' : '$pickupLoc =', $start);
    $code = substr($template, $start, $end - $start);
    if ($component === 'booking-item-email') {
        $start = strpos($template, '$convertDisplayAmount =');
        $end = strpos($template, '$displayUnitPrice =', $start);
        $code .= substr($template, $start, $end - $start);
    }
    return eval($code . 'return $convertDisplayAmount;');
}

beforeEach(function () {
    $this->currencyService = Mockery::mock(CurrencyService::class);
    $this->currencyService->shouldReceive('isValidCurrency')->andReturnUsing(fn ($code) => in_array($code, ['INR', 'USD'], true));
    app()->instance(CurrencyService::class, $this->currencyService);
});

it('keeps same-currency booking amounts unchanged', function (string $component) {
    $this->currencyService->shouldNotReceive('convert');
    $convert = bookingSummaryConverter($component, ' inr ', 'INR');
    expect($convert(123.45))->toBe(123.45);
})->with(['booking-payment-summary', 'booking-item-email']);

it('converts using the source and selected currency codes', function (string $component) {
    $this->currencyService->shouldReceive('convert')->once()->with(100.0, 'INR', 'USD')->andReturn(25.0);
    $convert = bookingSummaryConverter($component, 'usd', 'inr');
    expect($convert(100))->toBe(25.0);
})->with(['booking-payment-summary', 'booking-item-email']);

it('rejects display symbols without guessing another currency', function (string $component) {
    $this->currencyService->shouldNotReceive('convert');
    $this->currencyService->shouldNotReceive('getBookingBaseCurrency');
    bookingSummaryConverter($component, '₹', 'INR');
})->with(['booking-payment-summary', 'booking-item-email'])->throws(InvalidArgumentException::class);
