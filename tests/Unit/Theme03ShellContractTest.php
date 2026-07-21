<?php

beforeEach(function () {
    $projectRoot = dirname(__DIR__, 2);

    $this->header = file_get_contents($projectRoot . '/resources/views/partials/themes/theme-03/header.blade.php');
    $this->footer = file_get_contents($projectRoot . '/resources/views/partials/themes/theme-03/footer.blade.php');
    $this->script = file_get_contents($projectRoot . '/public/assets/js/themes/theme-03/shell.js');
    $this->styles = file_get_contents($projectRoot . '/public/assets/css/themes/theme-03/theme-03.css');
    $this->notFound = file_get_contents($projectRoot . '/resources/views/errors/404.blade.php');
    $this->maintenance = file_get_contents($projectRoot . '/resources/views/errors/maintenance.blade.php');
    $this->securityMiddleware = file_get_contents($projectRoot . '/app/Http/Middleware/WebsiteSettingsSecurity.php');
});

it('preserves the live header navigation and utility owners', function () {
    expect($this->header)
        ->toContain("route('home')")
        ->toContain("route('cms.show'")
        ->toContain("'contentType' => 'services'")
        ->toContain("route('corporate-transfers')")
        ->toContain("route('rate-chart')")
        ->toContain("route('about')")
        ->toContain("route('inquiry')")
        ->toContain("route('checkout')")
        ->toContain('getAvailableCurrencies()')
        ->toContain('class="currency-option')
        ->toContain('id="cartBadge"')
        ->toContain('id="mobileCartBadge"')
        ->toContain('$settings[\'company_phone\']')
        ->toContain('$headerServices');
});

it('renders every existing optional footer family from shared settings', function () {
    expect($this->footer)
        ->toContain("footer_newsletter_enabled")
        ->toContain("footer_newsletter_action")
        ->toContain("'services' => 'theme03ServicesLinks'")
        ->toContain("'routes' => 'theme03RoutesLinks'")
        ->toContain("'support' => 'theme03SupportLinks'")
        ->toContain('footer_{$group}_link_{$index}_text')
        ->toContain("company_phone")
        ->toContain("company_whatsapp")
        ->toContain("company_email")
        ->toContain("company_address")
        ->toContain("social_facebook")
        ->toContain("social_tiktok")
        ->toContain("footer_payment_methods_enabled")
        ->toContain("footer_copyright_text");
});

it('provides an accessible mobile navigation state machine', function () {
    expect($this->header)
        ->toContain('aria-controls="t3-primary-navigation"')
        ->toContain('aria-expanded="false"')
        ->toContain('data-t3-menu-backdrop')
        ->and($this->script)
        ->toContain("event.key === 'Escape'")
        ->toContain("event.key !== 'Tab'")
        ->toContain("document.body.classList.add('t3-menu-open')")
        ->toContain("document.body.classList.remove('t3-menu-open')")
        ->toContain("openButton.setAttribute('aria-expanded'")
        ->toContain('returnFocusTo.focus()')
        ->toContain("window.matchMedia('(min-width: 1200px)')");
});

it('keeps the shell presentation isolated to Theme 03', function () {
    expect($this->styles)
        ->toContain('body.theme-theme-03 .t3-header')
        ->toContain('body.theme-theme-03.t3-menu-open')
        ->toContain('body.theme-theme-03 .t3-navigation__submenu')
        ->toContain('body.theme-theme-03 .t3-currency')
        ->toContain('body.theme-theme-03 .t3-footer')
        ->toContain('@media (max-width: 1199px)')
        ->not->toContain('body:not(.theme-theme-03)');
});

it('defines the shared Theme 03 page and state primitive families', function () {
    expect($this->styles)
        ->toContain('body.theme-theme-03 .breadcrumb-section')
        ->toContain('body.theme-theme-03 :where(.primary-btn1, .primary-btn2, .primary-btn3, .btn-primary)')
        ->toContain('body.theme-theme-03 :where(.form-control, .form-select')
        ->toContain('body.theme-theme-03 .alert')
        ->toContain('body.theme-theme-03 .modal-content')
        ->toContain('body.theme-theme-03 :where(.paginations, .pagination)')
        ->toContain('body.theme-theme-03 .accordion-button')
        ->toContain('body.theme-theme-03 :where(.swiper-button-next, .swiper-button-prev, .slick-arrow)')
        ->toContain('body.theme-theme-03 :where(.table, table)')
        ->toContain('body.theme-theme-03 :where(.content-body, .inquiry-content-body, .blog-details-content)')
        ->toContain('.checkout-addon-loading')
        ->toContain('.invalid-feedback');
});

it('gives Theme 03 distinct not-found and maintenance states without replacing released-theme markup', function () {
    expect($this->notFound)
        ->toContain("@if (is_theme('theme-03'))")
        ->toContain('class="t3-error-page"')
        ->toContain('<!-- Error Page Start-->')
        ->and($this->maintenance)
        ->toContain("(\$theme ?? 'default') === 'theme-03'")
        ->toContain('class="t3-maintenance"')
        ->toContain('class="wrap"')
        ->and($this->securityMiddleware)
        ->toContain("'theme' => get_active_theme()");
});
