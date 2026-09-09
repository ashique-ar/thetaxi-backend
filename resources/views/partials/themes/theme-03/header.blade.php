@php
    $theme03Phone = $settings['company_phone'] ?? '';
    $theme03PhoneHref = preg_replace('/[^0-9+]/', '', $theme03Phone);
    $theme03Brand = $settings['site_name'] ?? $settings['brand_name'] ?? 'Company';
@endphp

<header class="t3-header" data-t3-header>
    <div class="t3-header__frame">
        <a href="{{ route('home') }}" class="t3-header__logo" aria-label="{{ $theme03Brand }} home">
            <img
                src="{{ s3_asset($settings['logo_header'] ?? 'assets/img/header-logo.png') }}"
                alt="{{ $settings['logo_header_alt'] ?? $theme03Brand }}">
        </a>

        <button
            class="t3-header__menu-toggle"
            type="button"
            aria-expanded="false"
            aria-controls="t3-primary-navigation"
            data-t3-menu-open>
            <span>{{ $settings['header_menu_label'] ?? 'Menu' }}</span>
            <span class="t3-header__menu-mark" aria-hidden="true"><i></i><i></i></span>
        </button>

        <nav class="t3-navigation" id="t3-primary-navigation" aria-label="Primary navigation" data-t3-navigation>
            <div class="t3-navigation__mobile-head">
                <span class="t3-kicker">Navigate</span>
                <button class="t3-navigation__close" type="button" aria-label="Close navigation" data-t3-menu-close>
                    <span aria-hidden="true"></span>
                </button>
            </div>

            <ul class="t3-navigation__links">
                @include('partials.header-navigation', ['themeHeader' => 't3'])
            </ul>

            <div class="t3-navigation__utilities">
                <details class="t3-currency">
                    <summary aria-label="Change currency">
                        <span>{{ getCurrencySymbol() }}</span>
                        <small>{{ getSelectedCurrency() }}</small>
                    </summary>
                    <ul>
                        @foreach (getAvailableCurrencies() as $currency)
                            <li>
                                <a
                                    href="#"
                                    class="currency-option {{ getSelectedCurrency() === $currency['code'] ? 'is-active' : '' }}"
                                    data-currency="{{ $currency['code'] }}">
                                    <span>{{ $currency['symbol'] ?? $currency['code'] }}</span>
                                    {{ $currency['name'] }}
                                    <small>{{ $currency['code'] }}</small>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </details>

                @if ($theme03PhoneHref !== '')
                    <a class="t3-header__contact" href="tel:{{ $theme03PhoneHref }}">
                        <span>{{ $settings['header_help_label'] ?? 'Need help?' }}</span>
                        <strong>{{ $theme03Phone }}</strong>
                    </a>
                @endif

                <a href="{{ route('checkout') }}" class="t3-header__cart" aria-label="Open booking cart">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M3 4h2l2.1 10.1a2 2 0 0 0 2 1.6h7.8a2 2 0 0 0 2-1.6L20.4 7H6.1M9.5 20a.5.5 0 1 1-1 0 .5.5 0 0 1 1 0Zm8 0a.5.5 0 1 1-1 0 .5.5 0 0 1 1 0Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span>{{ $settings['header_cart_label'] ?? 'Journey' }}</span>
                    <b class="cart-badge" id="cartBadge">0</b>
                </a>

                <a href="{{ route('checkout') }}" class="t3-navigation__mobile-cart">
                    {{ $settings['header_mobile_cart_label'] ?? 'View journey' }}
                    <b class="cart-badge" id="mobileCartBadge">0</b>
                </a>
            </div>
        </nav>

        <button class="t3-navigation__backdrop" type="button" aria-label="Close navigation" tabindex="-1" data-t3-menu-backdrop></button>
    </div>
</header>
