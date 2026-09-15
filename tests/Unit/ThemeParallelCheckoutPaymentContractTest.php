<?php

beforeEach(function () {
    $root = dirname(__DIR__, 2);
    $this->root = $root;
    $this->checkout = file_get_contents($root . '/resources/views/checkout.blade.php');
    $this->cartItem = file_get_contents($root . '/resources/views/checkout/partials/cart-item.blade.php');
    $this->cartSummary = file_get_contents($root . '/resources/views/checkout/partials/cart-summary.blade.php');
    $this->success = file_get_contents($root . '/resources/views/checkout/success.blade.php');
    $this->resume = file_get_contents($root . '/resources/views/checkout/payment-resume.blade.php');
    $this->callback = file_get_contents($root . '/resources/views/checkout/callback-error.blade.php');
    $this->redirect = file_get_contents($root . '/resources/views/checkout/webxpay-redirect.blade.php');
    $this->status = file_get_contents($root . '/resources/views/booking/status.blade.php');
    $this->routes = file_get_contents($root . '/routes/web.php');
    $this->controller = file_get_contents($root . '/app/Http/Controllers/CheckoutController.php');
    $this->theme03Checkout = file_get_contents($root . '/public/assets/css/themes/theme-03/checkout.css');
    $this->theme04Checkout = file_get_contents($root . '/public/assets/css/themes/theme-04/checkout.css');
    $this->theme03Pages = file_get_contents($root . '/public/assets/css/themes/theme-03/pages.css');
    $this->theme04Pages = file_get_contents($root . '/public/assets/css/themes/theme-04/pages.css');
});

it('keeps checkout fields, submission and empty-cart behavior shared', function () {
    expect($this->checkout)
        ->toContain("theme_class('checkout-workflow')")
        ->toContain("route('checkout.process')")
        ->toContain('id="checkout-form"')
        ->toContain('name="phone_country_code"')
        ->toContain('name="phone_international"')
        ->toContain('name="country"')
        ->toContain('id="accept_all_terms"')
        ->toContain('name="save_info"')
        ->toContain('name="marketing_consent"')
        ->toContain('Browse Vehicles');
});

it('places the existing dynamic Theme 04 booking form beside checkout', function () {
    $themeBooking = "@include('partials.themes.theme-04.booking-form', ['embedded' => true])";

    expect($this->checkout)
        ->toContain("@unless (is_theme('theme-04'))")
        ->toContain($themeBooking)
        ->toContain('form="checkout-form"')
        ->and(strpos($this->checkout, $themeBooking))->toBeLessThan(strpos($this->checkout, "@include('checkout.partials.cart-summary')"));

    expect($this->theme04Checkout)
        ->toContain('.t4-checkout-booking { min-width: 0; margin-bottom: 24px; }')
        ->toContain('.t4-checkout-booking .t4-booking-panel__card { width: 100%; }')
        ->toContain('grid-template-columns: 64px minmax(0, 1fr)')
        ->toContain('.checkout-cart-item .item-total { min-width: 88px; }');
});

it('preserves cart item, addon, extra-km, promotion and payment-selection hooks', function () {
    expect($this->cartItem)
        ->toContain('checkout-cart-item')
        ->toContain('checkout-remove-item-btn')
        ->toContain('checkout-item-addons')
        ->toContain('checkout-toggle-addons')
        ->toContain('checkout-item-extra-km')
        ->toContain('checkout-toggle-extra-km')
        ->toContain('return-trip-breakdown');

    expect($this->cartSummary)
        ->toContain('data-summary-field="subtotal"')
        ->toContain('data-summary-field="total"')
        ->toContain('id="checkout-promo-input"')
        ->toContain('id="apply-promo-checkout-btn"')
        ->toContain('price_adjustment_discount')
        ->toContain('coupon_discount');

    foreach (['full', 'advance', 'checkin', 'quotation'] as $selection) {
        expect($this->checkout)->toContain('value="' . $selection . '"');
    }
});

it('covers every checkout-success and payment-resume branch without changing owners', function () {
    expect($this->success)
        ->toContain("theme_class('checkout-success')")
        ->toContain("\$type === 'quotation'")
        ->toContain("\$booking->payment_type === 'advance'")
        ->toContain("\$booking->payment_type === 'full'")
        ->toContain("\$booking->payment_type === 'checkin'")
        ->toContain("\$booking->payment_status === 'pending'")
        ->toContain('<x-booking-payment-summary');

    expect($this->resume)
        ->toContain("theme_class('payment-resume')")
        ->toContain('id="payment-resume-form"')
        ->toContain("route('checkout.process-payment-resume')")
        ->toContain('status-{{ $paymentStatus }}')
        ->toContain('booking-item-email')
        ->toContain('addons-section')
        ->toContain('extra-km-section');

    expect($this->controller)
        ->toContain('Payment link expired or invalid.')
        ->toContain('Payment session expired. Please try again.')
        ->toContain("view('checkout.payment-resume'");
});

it('preserves callback, WebXPay and booking-status security contracts', function () {
    expect($this->callback)
        ->toContain("theme_class('payment-callback')")
        ->toContain("route('contact')")
        ->toContain('provide your transaction details');

    expect($this->redirect)
        ->toContain('<body class="theme-{{ get_active_theme() }}">')
        ->toContain('body.theme-theme-03')
        ->toContain('body.theme-theme-04')
        ->toContain('name="payment"')
        ->toContain('name="secret_key"')
        ->toContain('action="{{ $payment_url }}"')
        ->toContain('document.getElementById(\'webxpay-form\').submit()');

    expect($this->status)
        ->toContain("theme_class('booking-status')")
        ->toContain("route('booking.status.lookup')")
        ->toContain('name="booking_reference"')
        ->toContain('name="contact"')
        ->toContain('@isset($bookingStatus)')
        ->toContain('aria-live="polite"')
        ->toContain('role="progressbar"');
});

it('gives both themes complete transactional and mobile presentation ownership', function () {
    foreach (['03' => $this->theme03Checkout, '04' => $this->theme04Checkout] as $theme => $css) {
        expect($css)
            ->toContain(".checkout-workflow--theme-{$theme}")
            ->toContain('.checkout-sidebar-stack { position: sticky;')
            ->toContain('.checkout-cart-item')
            ->toContain('.checkout-item-addons')
            ->toContain('.checkout-item-extra-km')
            ->toContain('.promo-code-checkout-wrapper')
            ->toContain('.payment-option')
            ->toContain('@media (max-width: 991px)')
            ->toContain('.checkout-sidebar-stack { position: static; }')
            ->toContain('min-height: 44px');
    }

    foreach (['03' => $this->theme03Pages, '04' => $this->theme04Pages] as $theme => $css) {
        expect($css)
            ->toContain(".checkout-success--theme-{$theme}")
            ->toContain(".payment-resume--theme-{$theme}")
            ->toContain(".payment-callback--theme-{$theme}")
            ->toContain(".booking-status--theme-{$theme}")
            ->toContain('.reference-box')
            ->toContain('.status-badge')
            ->toContain('.progress-bar');
    }
});

it('documents legacy cart, booking success and mock gateway reachability', function () {
    expect($this->routes)
        ->toContain("Route::get('/cart', fn () => redirect()->route('checkout'))->name('cart')")
        ->not->toContain("view('cart'")
        ->not->toContain("view('booking.success'")
        ->not->toContain('mockGateway');

    expect($this->controller)->toContain("view('checkout.mock-gateway'")
        ->and(file_exists($this->root . '/resources/views/checkout/mock-gateway.blade.php'))->toBeFalse();
});

it('keeps production release gates closed', function () {
    $env = file_get_contents($this->root . '/.env.example');
    $phpunit = file_get_contents($this->root . '/phpunit.xml');

    foreach (['03', '04'] as $theme) {
        expect($env)->toContain("WEBSITE_THEME_{$theme}_ENABLED=false")
            ->and($phpunit)->toContain("<env name=\"WEBSITE_THEME_{$theme}_ENABLED\" value=\"false\"/>");
    }
});
