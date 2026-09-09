@php
    $theme04Phone = $settings['company_phone'] ?? '';
    $theme04PhoneHref = preg_replace('/[^0-9+]/', '', $theme04Phone);
    $theme04Brand = $settings['site_name'] ?? $settings['brand_name'] ?? 'Company';
@endphp

<header class="t4-header" data-t4-header>
    <div class="t4-header__frame">
        <a href="{{ route('home') }}" class="t4-header__logo" aria-label="{{ $theme04Brand }} home">
            <img
                src="{{ s3_asset($settings['logo_header'] ?? 'assets/img/header-logo.png') }}"
                alt="{{ $settings['logo_header_alt'] ?? $theme04Brand }}">
        </a>

        <button
            class="t4-header__menu-toggle"
            type="button"
            aria-expanded="false"
            aria-controls="t4-primary-navigation"
            data-t4-menu-open>
            <span class="visually-hidden">{{ $settings['header_menu_label'] ?? 'Open navigation' }}</span>
            <i aria-hidden="true"></i><i aria-hidden="true"></i><i aria-hidden="true"></i>
        </button>

        <nav class="t4-navigation" id="t4-primary-navigation" aria-label="Primary navigation" data-t4-navigation>
            <div class="t4-navigation__mobile-head">
                <a href="{{ route('home') }}">{{ $theme04Brand }}</a>
                <button type="button" aria-label="Close navigation" data-t4-menu-close><span aria-hidden="true"></span></button>
            </div>

            <ul class="t4-navigation__links">
                @include('partials.header-navigation', ['themeHeader' => 't4'])
            </ul>

            <div class="t4-navigation__utilities">
                <details class="t4-currency">
                    <summary aria-label="Change currency">{{ getSelectedCurrency() }}</summary>
                    <ul>
                        @foreach (getAvailableCurrencies() as $currency)
                            <li>
                                <a href="#" class="currency-option {{ getSelectedCurrency() === $currency['code'] ? 'is-active' : '' }}" data-currency="{{ $currency['code'] }}">
                                    <span>{{ $currency['symbol'] ?? $currency['code'] }}</span>
                                    {{ $currency['name'] }}
                                    <small>{{ $currency['code'] }}</small>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </details>

                @if ($theme04PhoneHref !== '')
                    <a href="tel:{{ $theme04PhoneHref }}" class="t4-header__phone">
                        <i class="bi bi-telephone" aria-hidden="true"></i>
                        <span><small>{{ $settings['header_help_label'] ?? 'Need help?' }}</small><strong>{{ $theme04Phone }}</strong></span>
                    </a>
                @endif

                <a href="{{ route('inquiry') }}" class="t4-header__contact">{{ $settings['header_contact_label'] ?? 'Contact Us' }}</a>

                <a href="{{ route('checkout') }}" class="t4-header__cart" aria-label="Open booking cart">
                    <i class="bi bi-bag" aria-hidden="true"></i>
                    <b class="cart-badge" id="cartBadge">0</b>
                </a>

                <a href="{{ route('checkout') }}" class="t4-navigation__mobile-cart">
                    {{ $settings['header_mobile_cart_label'] ?? 'View booking' }}
                    <b class="cart-badge" id="mobileCartBadge">0</b>
                </a>
            </div>
        </nav>

        <button class="t4-navigation__backdrop" type="button" aria-label="Close navigation" tabindex="-1" data-t4-menu-backdrop></button>
    </div>
</header>
