{{-- Theme-02 Header - Tailwind CSS - Glass-morphism transparent header --}}

<!-- Header Section -->
<header class="t2-header header-area style-3">
    <div class="container d-flex flex-nowrap align-items-center justify-content-between">
        <div class="logo-and-menu-area">
            <a href="{{ route('home') }}" class="header-logo">
                <img src="{{ s3_asset($settings['logo_header'] ?? 'assets/img/header-logo3.svg') }}"
                     alt="{{ $settings['logo_header_alt'] ?? $settings['site_name'] ?? 'TheTaxi' }}">
            </a>
            <div class="main-menu">
                <div class="mobile-logo-area d-xl-none d-flex align-items-center justify-content-between">
                    <a href="{{ route('home') }}" class="mobile-logo-wrap">
                        <img src="{{ s3_asset($settings['logo_mobile'] ?? $settings['logo_header'] ?? 'assets/img/header-logo2.svg') }}"
                             alt="{{ $settings['logo_mobile_alt'] ?? $settings['site_name'] ?? 'TheTaxi' }}">
                    </a>
                    <div class="menu-close-btn">
                        <i class="bi bi-x"></i>
                    </div>
                </div>
                <ul class="menu-list">
                    <li class="{{ Request::routeIs('home') ? 'active' : '' }}"><a href="{{ route('home') }}">Home</a></li>
                    @if(isset($headerServices) && $headerServices->count() > 0)
                    <li class="menu-item-has-children">
                        <a href="#" class="drop-down">Services <i class="bi bi-caret-down-fill"></i></a>
                        <i class="bi bi-plus dropdown-icon"></i>
                        <ul class="sub-menu scrollable-submenu">
                            @foreach($headerServices as $service)
                                @if(!empty($service->slug))
                                <li><a href="{{ route('cms.show', ['contentType' => 'services', 'content' => $service->slug]) }}">{{ $service->title }}</a></li>
                                @endif
                            @endforeach
                        </ul>
                    </li>
                    @endif
                    <li><a href="{{ route('about') }}">About</a></li>
                    <li><a href="{{ route('contact') }}">Contact</a></li>
                </ul>
                <div class="contact-area d-xl-none d-flex">
                    <div class="icon">
                        <svg width="16" height="16" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg">
                            <g><path d="M15.5646 11.7424L13.3317 9.50954C12.5343 8.7121 11.1786 9.03111 10.8596 10.0678C10.6204 10.7855 9.82296 11.1842 9.10526 11.0247C7.51037 10.626 5.35726 8.55261 4.95854 6.87797C4.71931 6.16024 5.19778 5.36279 5.91548 5.12359C6.95216 4.80461 7.27113 3.44895 6.47369 2.65151L4.24084 0.418659C3.60288 -0.139553 2.64595 -0.139553 2.08774 0.418659L0.572591 1.93381C-0.942555 3.5287 0.73208 7.75516 4.48007 11.5032C8.22807 15.2512 12.4545 17.0056 14.0494 15.4106L15.5646 13.8955C16.1228 13.2575 16.1228 12.3006 15.5646 11.7424Z"/></g>
                        </svg>
                    </div>
                    <div class="content">
                        <span>{{ $settings['header_help_label'] ?? 'Need Help?' }}</span>
                        <a href="tel:{{ preg_replace('/[^0-9+]/', '', $settings['company_phone'] ?? '+1234567890') }}">{{ $settings['company_phone'] ?? '+1 234 567 890' }}</a>
                    </div>
                </div>
                <!-- Mobile Cart -->
                <div class="mobile-cart-area d-xl-none d-flex align-items-center mt-3">
                    <a href="{{ route('cart') }}" class="cart-icon-link position-relative d-flex align-items-center text-decoration-none">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M7 18C5.9 18 5.01 18.9 5.01 20C5.01 21.1 5.9 22 7 22C8.1 22 9 21.1 9 20C9 18.9 8.1 18 7 18ZM1 2V4H3L6.6 11.59L5.25 14.04C5.09 14.32 5 14.65 5 15C5 16.1 5.9 17 7 17H19V15H7.42C7.28 15 7.17 14.89 7.17 14.75L7.2 14.63L8.1 13H15.55C16.3 13 16.96 12.59 17.3 11.97L20.88 5H5.21L4.27 3H1ZM17 18C15.9 18 15.01 18.9 15.01 20C15.01 21.1 15.9 22 17 22C18.1 22 19 21.1 19 20C19 18.9 18.1 18 17 18Z" fill="currentColor"/>
                        </svg>
                        <span class="ms-2">{{ $settings['header_cart_label'] ?? 'My Cart' }}</span>
                        <span class="cart-badge position-absolute" id="mobileCartBadge" style="display: none;">0</span>
                    </a>
                </div>
            </div>
        </div>
        <div class="nav-right">
            <div class="contact-and-wishlist-area">
                <!-- Currency Selector -->
                <div class="currency-selector align-items-center me-3">
                    <div class="dropdown">
                        <button class="btn btn-link dropdown-toggle p-0 text-decoration-none" type="button"
                                id="currencyDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <span class="currency-symbol">{{ getCurrencySymbol() }}</span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm" aria-labelledby="currencyDropdown">
                            @foreach(getAvailableCurrencies() as $currency)
                                <li>
                                    <a class="dropdown-item currency-option {{ getSelectedCurrency() === $currency['code'] ? 'active' : '' }}"
                                       href="#" data-currency="{{ $currency['code'] }}"
                                       style="{{ getSelectedCurrency() === $currency['code'] ? 'background-color: var(--primary-color1);' : '' }}">
                                        <span class="currency-symbol me-2">{{ $currency['symbol'] ?? $currency['code'] }}</span>
                                        <span class="currency-name">{{ $currency['name'] }}</span>
                                        <small class="text-muted ms-auto">({{ $currency['code'] }})</small>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
                <!-- Cart Icon -->
                <div class="cart-icon-container d-flex align-items-center me-3">
                    <a href="{{ route('cart') }}" class="cart-icon-link position-relative">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M7 18C5.9 18 5.01 18.9 5.01 20C5.01 21.1 5.9 22 7 22C8.1 22 9 21.1 9 20C9 18.9 8.1 18 7 18ZM1 2V4H3L6.6 11.59L5.25 14.04C5.09 14.32 5 14.65 5 15C5 16.1 5.9 17 7 17H19V15H7.42C7.28 15 7.17 14.89 7.17 14.75L7.2 14.63L8.1 13H15.55C16.3 13 16.96 12.59 17.3 11.97L20.88 5H5.21L4.27 3H1ZM17 18C15.9 18 15.01 18.9 15.01 20C15.01 21.1 15.9 22 17 22C18.1 22 19 21.1 19 20C19 18.9 18.1 18 17 18Z" fill="currentColor"/>
                        </svg>
                        <span class="cart-badge position-absolute" id="cartBadge" style="display: none;">0</span>
                    </a>
                </div>
                <div class="contact-area d-xl-flex d-none">
                    <div class="icon">
                        <svg width="16" height="16" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg">
                            <g><path d="M15.5646 11.7424L13.3317 9.50954C12.5343 8.7121 11.1786 9.03111 10.8596 10.0678C10.6204 10.7855 9.82296 11.1842 9.10526 11.0247C7.51037 10.626 5.35726 8.55261 4.95854 6.87797C4.71931 6.16024 5.19778 5.36279 5.91548 5.12359C6.95216 4.80461 7.27113 3.44895 6.47369 2.65151L4.24084 0.418659C3.60288 -0.139553 2.64595 -0.139553 2.08774 0.418659L0.572591 1.93381C-0.942555 3.5287 0.73208 7.75516 4.48007 11.5032C8.22807 15.2512 12.4545 17.0056 14.0494 15.4106L15.5646 13.8955C16.1228 13.2575 16.1228 12.3006 15.5646 11.7424Z"/></g>
                        </svg>
                    </div>
                    <div class="content">
                        <span>{{ $settings['header_help_label'] ?? 'Need Help?' }}</span>
                        <a href="tel:{{ preg_replace('/[^0-9+]/', '', $settings['company_phone'] ?? '+1234567890') }}">{{ $settings['company_phone'] ?? '+1 234 567 890' }}</a>
                    </div>
                </div>
            </div>
            <div class="sidebar-button mobile-menu-btn">
                <svg width="20" height="18" viewBox="0 0 20 18" xmlns="http://www.w3.org/2000/svg">
                    <path d="M1.29445 2.8421H10.5237C11.2389 2.8421 11.8182 2.2062 11.8182 1.42105C11.8182 0.635903 11.2389 0 10.5237 0H1.29445C0.579249 0 0 0.635903 0 1.42105C0 2.2062 0.579249 2.8421 1.29445 2.8421Z"></path>
                    <path d="M1.23002 10.421H18.77C19.4496 10.421 20 9.78506 20 8.99991C20 8.21476 19.4496 7.57886 18.77 7.57886H1.23002C0.550421 7.57886 0 8.21476 0 8.99991C0 9.78506 0.550421 10.421 1.23002 10.421Z"></path>
                    <path d="M18.8052 15.1579H10.2858C9.62563 15.1579 9.09094 15.7938 9.09094 16.5789C9.09094 17.3641 9.62563 18 10.2858 18H18.8052C19.4653 18 20 17.3641 20 16.5789C20 15.7938 19.4653 15.1579 18.8052 15.1579Z"></path>
                </svg>
            </div>
        </div>
    </div>
</header>
