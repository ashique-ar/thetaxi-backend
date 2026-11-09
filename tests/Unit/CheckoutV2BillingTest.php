<?php

use Illuminate\Support\ViewErrorBag;

it('renders the v2 billing controls and deduplicates applicable terms', function () {
    $this->app['events']->forget('composing: *');
    $term = (object) ['id' => 7, 'version' => 2, 'title' => 'Booking terms', 'content' => '<p>Booking policy</p>'];
    $html = view('checkout.v2.billing', [
        'customerLookupEnabled' => false,
        'guestBookingEnabled' => true,
        'verifiedCheckoutCustomer' => null,
        'tourCheckoutCustomer' => null,
        'hasAirportTransfer' => true,
        'countries' => collect([(object) ['name' => 'Sri Lanka', 'callcode' => '94']]),
        'checkoutDefaultCountryName' => 'Sri Lanka',
        'termsByService' => ['airport_transfers' => [$term]],
        'termsByPaymentType' => ['full' => [$term]],
        'errors' => new ViewErrorBag(),
    ])->render();

    foreach (['first_name', 'last_name', 'phone', 'email', 'address', 'city', 'country', 'identification', 'flight_airline', 'flight_number', 'flight_arrival_date', 'flight_arrival_time', 'additional_notes', 'save_info', 'marketing_consent'] as $field) {
        expect($html)->toContain('name="' . $field . '"');
    }
    expect(substr_count($html, 'data-term-id="7"'))->toBe(1)
        ->and($html)->toContain('data-payment-types="full"', 'id="accept_all_terms"', 'id="phone-international"');
});

it('renders customer verification controls only when lookup is enabled', function () {
    $this->app['events']->forget('composing: *');
    $data = ['customerLookupEnabled' => true, 'canBookAsNewCustomer' => true, 'verifiedCheckoutCustomer' => null, 'showCheckoutDetails' => false];
    $html = view('checkout.v2.customer', $data)->render();
    expect($html)->toContain('name="checkout_customer_kind"', 'id="customer-lookup-email"', 'id="customer-lookup-mobile"', 'id="verify-customer-email-code"');
    $data['customerLookupEnabled'] = false;
    expect(trim(view('checkout.v2.customer', $data)->render()))->toBe('');
});

it('renders only enabled payment types and keeps the selected type bound to the form', function () {
    $this->app['events']->forget('composing: *');
    $data = ['paymentMethods' => collect(['online' => true]), 'advancePaymentEnabled' => true, 'offlinePaymentEnabled' => false, 'paymentType' => 'advance', 'currencySymbol' => 'LKR', 'total' => 5000, 'advanceActualPercentage' => 30, 'advancePaymentAmount' => 1500, 'tourOnlyCheckout' => false];
    $html = view('checkout.v2.payment-options', $data)->render();
    expect($html)->toContain('value="full"', 'value="advance"', 'value="quotation"', 'form="checkout-form"')
        ->not->toContain('value="checkin"');
    expect($html)->toMatch('/id="payment_advance"[^>]*checked/');
    $data['paymentMethods'] = collect();
    $data['advancePaymentEnabled'] = false;
    $data['offlinePaymentEnabled'] = true;
    $data['paymentType'] = 'checkin';
    $html = view('checkout.v2.payment-options', $data)->render();
    expect($html)->toContain('value="checkin"', 'value="quotation"')->not->toContain('value="full"', 'value="advance"');
});

it('renders trip details and eligible option hooks in the v2 cart', function () {
    $this->app['events']->forget('composing: *');
    $this->mock(\App\Services\CurrencyService::class, fn ($mock) => $mock->shouldReceive('isValidCurrency')->with('LKR')->andReturn(true));
    $item = [
        'name' => 'Test vehicle', 'service_type' => 'airport_transfers',
        'service_type_data' => ['name' => 'Airport transfer', 'pricing_mode' => 'trip'],
        'pickup_date' => '2026-10-14', 'return_date' => '2026-10-14', 'total_price' => 5000,
        'pickup_location' => 'Airport', 'dropoff_location' => 'Colombo',
        'distance_details' => ['minimum_km' => 20, 'minimum_km_applied' => true, 'actual_journey_distance' => 7.5],
        'is_return_trip' => true, 'outbound_distance_km' => 7.5, 'return_distance_km' => 7.5,
        'addons' => [['id' => 9, 'name' => 'Child seat', 'qty' => 1, 'calculated_amount' => 500]],
        'extra_km' => ['km' => 10],
    ];
    $html = \Illuminate\Support\Facades\Blade::render("@foreach (\$cart as \$key => \$item) @include('checkout.v2.cart-item') @endforeach", ['cart' => ['a' => $item], 'currencySymbol' => 'LKR', 'displayCurrency' => 'LKR']);
    expect($html)->toContain('Test vehicle', 'Minimum 20 km', '7.5', 'Child seat', 'checkout-toggle-addons', 'checkout-toggle-extra-km', 'checkout-extra-km-count', 'data-cart-key="a"');
    $item['service_type'] = 'self_drive';
    $item['service_type_data']['pricing_mode'] = 'day';
    $html = \Illuminate\Support\Facades\Blade::render("@foreach (\$cart as \$key => \$item) @include('checkout.v2.cart-item') @endforeach", ['cart' => ['a' => $item], 'currencySymbol' => 'LKR', 'displayCurrency' => 'LKR']);
    expect($html)->not->toContain('Minimum 20 km');
});

it('renders the v2 summary with live total hooks and refundable deposits', function () {
    $this->app['events']->forget('composing: *');
    $this->mock(\App\Services\CurrencyService::class, fn ($mock) => $mock->shouldReceive('isValidCurrency')->with('LKR')->andReturn(true));
    $item = ['name' => 'Test vehicle', 'service_type' => 'self_drive', 'service_type_data' => ['pricing_mode' => 'day'], 'pickup_date' => '2026-10-14', 'return_date' => '2026-10-14', 'total_price' => 5000, 'refundable_deposit' => 1000];
    $html = view('checkout.v2.order-summary', [
        'cart' => ['a' => $item], 'cartData' => ['coupon_code' => 'SAVE'], 'currencySymbol' => 'LKR', 'displayCurrency' => 'LKR',
        'subtotal' => 5000, 'totals' => ['addon_charges' => 0, 'extra_km_charges' => 0], 'serviceFee' => 0,
        'taxLabel' => 'NBT', 'taxPercentageLabel' => 2, 'tax' => 100, 'vatLabel' => 'VAT', 'vatPercentageLabel' => 0, 'vat' => 0,
        'discount' => 100, 'total' => 5000,
    ])->render();
    expect($html)->toContain('Test vehicle', 'SAVE', 'data-summary-row="addon_charges"', 'data-summary-row="tax"', 'data-summary-field="total"', 'data-summary-field="pickup_total"', 'Refundable deposit');
});

it('assembles a v2 checkout with the form, summary and dialogs', function () {
    $this->app['events']->forget('composing: *');
    $this->mock(\App\Services\CurrencyService::class, fn ($mock) => $mock->shouldReceive('isValidCurrency')->with('LKR')->andReturn(true));
    $item = ['name' => 'Test vehicle', 'service_type' => 'self_drive', 'service_type_data' => ['pricing_mode' => 'day'], 'pickup_date' => '2026-10-14', 'return_date' => '2026-10-14', 'total_price' => 5000];
    $html = view('checkout.v2.layout', [
        'checkoutDesign' => '04', 'cart' => ['a' => $item], 'cartData' => [], 'currencySymbol' => 'LKR', 'displayCurrency' => 'LKR',
        'subtotal' => 5000, 'totals' => [], 'serviceFee' => 0, 'taxLabel' => 'NBT', 'taxPercentageLabel' => 0, 'tax' => 0,
        'vatLabel' => 'VAT', 'vatPercentageLabel' => 0, 'vat' => 0, 'discount' => 0, 'total' => 5000,
        'guestBookingEnabled' => true, 'customerLookupEnabled' => false, 'verifiedCheckoutCustomer' => null, 'tourCheckoutCustomer' => null,
        'hasAirportTransfer' => false, 'countries' => collect(), 'checkoutDefaultCountryName' => 'Sri Lanka', 'termsByService' => [], 'termsByPaymentType' => [],
        'paymentMethods' => collect(['online' => true]), 'advancePaymentEnabled' => true, 'offlinePaymentEnabled' => false,
        'paymentType' => 'full', 'advanceActualPercentage' => 50, 'advancePaymentAmount' => 2500, 'paymentAmount' => 5000, 'tourOnlyCheckout' => false,
        'errors' => new ViewErrorBag(),
    ])->render();
    expect($html)->toContain('Finalize Your Reservation', 'id="checkout-form"', 'id="checkout-payment-layout"', 'id="checkoutAddonModal"', 'id="checkoutExtraKmModal"', 'Test vehicle');
});

it('renders the full v2 page and pushes its behavior and scoped stylesheet', function () {
    $this->app['events']->forget('composing: *');
    view()->getFinder()->prependLocation(base_path('tests/fixtures/checkout-v2'));
    view()->getFinder()->flush();
    $this->app['events']->forget('composing: partials.header');
    $this->mock(\App\Services\WebsiteSettingsService::class, fn ($mock) => $mock->shouldReceive('get')->andReturn('default')->byDefault());
    $this->mock(\App\Services\CurrencyService::class, fn ($mock) => $mock->shouldReceive('isValidCurrency')->with('LKR')->andReturn(true)->byDefault());
    $item = ['name' => 'Test vehicle', 'service_type' => 'self_drive', 'service_type_data' => ['pricing_mode' => 'day'], 'pickup_date' => '2026-10-14', 'return_date' => '2026-10-14', 'total_price' => 5000];
    $html = view('checkout.v2.page', [
        'settings' => [], 'cartData' => ['items' => ['a' => $item], 'totals' => ['subtotal' => 5000, 'total' => 5000], 'currency_symbol' => 'LKR', 'currency' => 'LKR'],
        'guestBookingEnabled' => true, 'customerLookupEnabled' => false, 'verifiedCheckoutCustomer' => null, 'tourCheckoutCustomer' => null,
        'hasAirportTransfer' => false, 'countries' => collect(), 'checkoutDefaultCountryName' => 'Sri Lanka', 'termsByService' => [], 'termsByPaymentType' => [],
        'paymentMethods' => collect(['online' => true]), 'advancePaymentEnabled' => true, 'offlinePaymentEnabled' => false,
        'paymentType' => 'full', 'advancePercentage' => 50, 'advanceMinAmount' => 0, 'errors' => new ViewErrorBag(),
        'checkoutDefaultCountry' => 'lk', 'verificationResendAfter' => 60,
    ])->render();
    expect($html)->toContain('checkout-v2.css', 'id="checkout-v2"', 'id="checkout-form"', 'id="checkoutAddonModal"', 'function loadCheckoutAddons');
    if ($snapshot = getenv('CHECKOUT_V2_SNAPSHOT')) {
        file_put_contents($snapshot, str_replace('http://localhost/assets/', 'file:///' . str_replace('\\', '/', public_path('assets')) . '/', $html));
    }
});
