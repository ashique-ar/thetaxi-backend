<!-- Top Offer Text Slider -->
{{-- <div class="top-offer-text-slider-section">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="top-offer-text-slider-wrap">
                    <div class="slider-btn top-offer-text-slider-prev">
                        <svg width="11" height="12" viewBox="0 0 11 12" xmlns="http://www.w3.org/2000/svg">
                            <path
                                d="M9.42865 10.4085C8.69396 8.57179 5.02049 6.73505 2.81641 6.00036C5.02049 5.26567 8.32661 4.16363 9.42865 1.5922" stroke-width="2" stroke-linecap="round" />
                        </svg>
                    </div>
                    <div class="swiper top-offer-text-slider">
                        <div class="swiper-wrapper">
                            <div class="swiper-slide">
                                <a href="{{ route('home') }}">One-Click Booking, Upto <strong>FLAT 30%</strong> Discount on Taxi Rides</a>
                            </div>
                            <div class="swiper-slide">
                                <a href="{{ route('home') }}">Book Your Taxi and Get <strong>Special Discounts</strong> Instantly</a>
                            </div>
                            <div class="swiper-slide">
                                <a href="{{ route('home') }}">Enjoy Safe & Comfortable Rides with <strong>Flexible Payment Options</strong></a>
                            </div>
                        </div>
                    </div>
                    <div class="slider-btn top-offer-text-slider-next">
                        <svg width="11" height="12" viewBox="0 0 11 12" xmlns="http://www.w3.org/2000/svg">
                            <path
                                d="M1.57141 10.4085C2.3061 8.57179 5.97957 6.73505 8.18366 6.00036C5.97957 5.26567 2.67345 4.16363 1.57141 1.5922" stroke-width="2" stroke-linecap="round" />
                        </svg>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div> --}}

<!-- Header Section -->
<header class="header-area style-2 travel-agency3">
    <div class="container-fluid d-flex flex-nowrap align-items-center justify-content-between">
        <div class="logo-and-menu-area">
            <a href="{{ route('home') }}" class="header-logo">
                <img src="{{ s3_asset($settings['logo_header'] ?? 'assets/img/header-logo.png') }}"
                    alt="{{ $settings['logo_header_alt'] ?? ($settings['site_name'] ?? 'TheTaxi') }}">
            </a>
            <div class="main-menu">
                <div class="mobile-logo-area d-xl-none d-flex align-items-center justify-content-between">
                    <a href="{{ route('home') }}" class="mobile-logo-wrap">
                        <img src="{{ s3_asset($settings['logo_mobile'] ?? ($settings['logo_header'] ?? 'assets/img/header-logo.png')) }}"
                            alt="{{ $settings['logo_mobile_alt'] ?? ($settings['site_name'] ?? 'TheTaxi') }}">
                    </a>
                    <div class="menu-close-btn">
                        <i class="bi bi-x"></i>
                    </div>
                </div>
                <ul class="menu-list">
                    <li class="{{ Request::routeIs('home') ? 'active' : '' }}"><a href="{{ route('home') }}">Home</a>
                    </li>
                    @if (isset($headerServices) && $headerServices->count() > 0)
                        <li class="menu-item-has-children">
                            <a href="#" class="drop-down">
                                Services
                                <i class="bi bi-caret-down-fill"></i>
                            </a>
                            <i class="bi bi-plus dropdown-icon"></i>
                            <ul class="sub-menu">
                                @foreach ($headerServices as $service)
                                    @if(!empty($service->slug))
                                    <li><a
                                            href="{{ route('cms.show', ['contentType' => 'services', 'content' => $service->slug]) }}">{{ $service->title }}</a>
                                    </li>
                                    @endif
                                @endforeach
                            </ul>
                        </li>
                    @endif
                    {{-- <li><a href="{{ route('corporate-transfers') }}">Corporate Transport</a></li> --}}
                    <li><a href="{{ route('about') }}">About</a></li>
                    <li><a href="{{ route('contact') }}">Contact</a></li>
                </ul>
                <div class="contact-area d-xl-none d-flex">
                    <div class="icon">
                        <svg width="16" height="16" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg">
                            <g>
                                <path
                                    d="M15.5646 11.7424L13.3317 9.50954C12.5343 8.7121 11.1786 9.03111 10.8596 10.0678C10.6204 10.7855 9.82296 11.1842 9.10526 11.0247C7.51037 10.626 5.35726 8.55261 4.95854 6.87797C4.71931 6.16024 5.19778 5.36279 5.91548 5.12359C6.95216 4.80461 7.27113 3.44895 6.47369 2.65151L4.24084 0.418659C3.60288 -0.139553 2.64595 -0.139553 2.08774 0.418659L0.572591 1.93381C-0.942555 3.5287 0.73208 7.75516 4.48007 11.5032C8.22807 15.2512 12.4545 17.0056 14.0494 15.4106L15.5646 13.8955C16.1228 13.2575 16.1228 12.3006 15.5646 11.7424Z" />
                            </g>
                        </svg>
                    </div>
                    <div class="content">
                        <span>{{ $settings['header_help_label'] ?? 'Need Help?' }}</span>
                        <a
                            href="tel:{{ preg_replace('/[^0-9+]/', '', $settings['company_phone'] ?? '+1234567890') }}">{{ $settings['company_phone'] ?? '+1 234 567 890' }}</a>
                    </div>
                </div>

                <!-- Mobile Cart Link -->
                <div class="mobile-cart-area d-xl-none d-flex align-items-center mt-3">
                    <a href="{{ route('cart') }}"
                        class="cart-icon-link position-relative d-flex align-items-center text-decoration-none">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none"
                            xmlns="http://www.w3.org/2000/svg">
                            <path
                                d="M7 18C5.9 18 5.01 18.9 5.01 20C5.01 21.1 5.9 22 7 22C8.1 22 9 21.1 9 20C9 18.9 8.1 18 7 18ZM1 2V4H3L6.6 11.59L5.25 14.04C5.09 14.32 5 14.65 5 15C5 16.1 5.9 17 7 17H19V15H7.42C7.28 15 7.17 14.89 7.17 14.75L7.2 14.63L8.1 13H15.55C16.3 13 16.96 12.59 17.3 11.97L20.88 5H5.21L4.27 3H1ZM17 18C15.9 18 15.01 18.9 15.01 20C15.01 21.1 15.9 22 17 22C18.1 22 19 21.1 19 20C19 18.9 18.1 18 17 18Z"
                                fill="currentColor" />
                        </svg>
                        <span class="ms-2">{{ $settings['header_cart_label'] ?? 'My Cart' }}</span>
                        <span class="cart-badge position-absolute" id="mobileCartBadge" style="display: none;">0</span>
                    </a>
                </div>
                {{-- <a href="#" class="primary-btn1 black-bg d-xl-none d-flex">
                    <span>
                        <svg width="15" height="15" viewBox="0 0 15 15" xmlns="http://www.w3.org/2000/svg">
                            <g>
                                <path
                                    d="M7.50105 7.78913C9.64392 7.78913 11.3956 6.03744 11.3956 3.89456C11.3956 1.75169 9.64392 0 7.50105 0C5.35818 0 3.60652 1.75169 3.60652 3.89456C3.60652 6.03744 5.35821 7.78913 7.50105 7.78913ZM14.1847 10.9014C14.0827 10.6463 13.9467 10.4082 13.7936 10.1871C13.0113 9.0306 11.8038 8.2653 10.4433 8.07822C10.2732 8.06123 10.0861 8.09522 9.95007 8.19727C9.23578 8.72448 8.38546 8.99658 7.50108 8.99658C6.61671 8.99658 5.76638 8.72448 5.05209 8.19727C4.91603 8.09522 4.72895 8.04421 4.5589 8.07822C3.19835 8.2653 1.97387 9.0306 1.20857 10.1871C1.05551 10.4082 0.919443 10.6633 0.817424 10.9014C0.766415 11.0034 0.783407 11.1225 0.834416 11.2245C0.970484 11.4626 1.14054 11.7007 1.2936 11.9048C1.53168 12.2279 1.78679 12.517 2.07592 12.7891C2.31401 13.0272 2.58611 13.2483 2.85824 13.4694C4.20177 14.4728 5.81742 15 7.48409 15C9.15076 15 10.7664 14.4728 12.1099 13.4694C12.382 13.2653 12.6541 13.0272 12.8923 12.7891C13.1644 12.517 13.4365 12.2279 13.6746 11.9048C13.8446 11.6837 13.9977 11.4626 14.1338 11.2245C14.2188 11.1225 14.2358 11.0034 14.1847 10.9014Z"/>
                            </g>
                        </svg>
                        Book Now
                    </span>
                    <span>
                        <svg width="15" height="15" viewBox="0 0 15 15" xmlns="http://www.w3.org/2000/svg">
                            <g>
                                <path
                                    d="M7.50105 7.78913C9.64392 7.78913 11.3956 6.03744 11.3956 3.89456C11.3956 1.75169 9.64392 0 7.50105 0C5.35818 0 3.60652 1.75169 3.60652 3.89456C3.60652 6.03744 5.35821 7.78913 7.50105 7.78913ZM14.1847 10.9014C14.0827 10.6463 13.9467 10.4082 13.7936 10.1871C13.0113 9.0306 11.8038 8.2653 10.4433 8.07822C10.2732 8.06123 10.0861 8.09522 9.95007 8.19727C9.23578 8.72448 8.38546 8.99658 7.50108 8.99658C6.61671 8.99658 5.76638 8.72448 5.05209 8.19727C4.91603 8.09522 4.72895 8.04421 4.5589 8.07822C3.19835 8.2653 1.97387 9.0306 1.20857 10.1871C1.05551 10.4082 0.919443 10.6633 0.817424 10.9014C0.766415 11.0034 0.783407 11.1225 0.834416 11.2245C0.970484 11.4626 1.14054 11.7007 1.2936 11.9048C1.53168 12.2279 1.78679 12.517 2.07592 12.7891C2.31401 13.0272 2.58611 13.2483 2.85824 13.4694C4.20177 14.4728 5.81742 15 7.48409 15C9.15076 15 10.7664 14.4728 12.1099 13.4694C12.382 13.2653 12.6541 13.0272 12.8923 12.7891C13.1644 12.517 13.4365 12.2279 13.6746 11.9048C13.8446 11.6837 13.9977 11.4626 14.1338 11.2245C14.2188 11.1225 14.2358 11.0034 14.1847 10.9014Z"/>
                            </g>
                        </svg>
                        Book Now
                    </span>
                </a>     --}}
            </div>
        </div>
        <div class="nav-right">
            <div class="contact-and-search-area">
                <!-- Currency Selector -->
                <div class="currency-selector align-items-center me-3">
                    <div class="dropdown">
                        <button class="btn btn-link dropdown-toggle p-0 text-decoration-none" type="button"
                            id="currencyDropdown" data-bs-toggle="dropdown" aria-expanded="false"
                            style="color: #333; font-weight: 500;">
                            <span class="currency-symbol">{{ getCurrencySymbol() }}</span>
                            {{-- <span class="currency-code ms-1">{{ getSelectedCurrency() }}</span> --}}
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm" aria-labelledby="currencyDropdown">
                            @foreach (getAvailableCurrencies() as $currency)
                                <li>
                                    <a class="dropdown-item currency-option {{ getSelectedCurrency() === $currency['code'] ? 'active' : '' }}"
                                        href="#" data-currency="{{ $currency['code'] }}"
                                        style="{{ getSelectedCurrency() === $currency['code'] ? 'background-color: var(--primary-color1);' : '' }}">
                                        <span
                                            class="currency-symbol me-2">{{ $currency['symbol'] ?? $currency['code'] }}</span>
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
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none"
                            xmlns="http://www.w3.org/2000/svg">
                            <path
                                d="M7 18C5.9 18 5.01 18.9 5.01 20C5.01 21.1 5.9 22 7 22C8.1 22 9 21.1 9 20C9 18.9 8.1 18 7 18ZM1 2V4H3L6.6 11.59L5.25 14.04C5.09 14.32 5 14.65 5 15C5 16.1 5.9 17 7 17H19V15H7.42C7.28 15 7.17 14.89 7.17 14.75L7.2 14.63L8.1 13H15.55C16.3 13 16.96 12.59 17.3 11.97L20.88 5H5.21L4.27 3H1ZM17 18C15.9 18 15.01 18.9 15.01 20C15.01 21.1 15.9 22 17 22C18.1 22 19 21.1 19 20C19 18.9 18.1 18 17 18Z"
                                fill="currentColor" />
                        </svg>
                        <span class="cart-badge position-absolute" id="cartBadge" style="display: none;">0</span>
                    </a>
                </div>

                <div class="contact-area d-xl-flex d-none">
                    <div class="icon">
                        <svg width="16" height="16" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg">
                            <g>
                                <path
                                    d="M15.5646 11.7424L13.3317 9.50954C12.5343 8.7121 11.1786 9.03111 10.8596 10.0678C10.6204 10.7855 9.82296 11.1842 9.10526 11.0247C7.51037 10.626 5.35726 8.55261 4.95854 6.87797C4.71931 6.16024 5.19778 5.36279 5.91548 5.12359C6.95216 4.80461 7.27113 3.44895 6.47369 2.65151L4.24084 0.418659C3.60288 -0.139553 2.64595 -0.139553 2.08774 0.418659L0.572591 1.93381C-0.942555 3.5287 0.73208 7.75516 4.48007 11.5032C8.22807 15.2512 12.4545 17.0056 14.0494 15.4106L15.5646 13.8955C16.1228 13.2575 16.1228 12.3006 15.5646 11.7424Z" />
                            </g>
                        </svg>
                    </div>
                    <div class="content">
                        <span>{{ $settings['header_help_label'] ?? 'Need Help?' }}</span>
                        <a
                            href="tel:{{ preg_replace('/[^0-9+]/', '', $settings['company_phone'] ?? '+1234567890') }}">{{ $settings['company_phone'] ?? '+1 234 567 890' }}</a>
                    </div>
                </div>
                {{-- <div class="search-bar">
                    <div class="search-btn">
                        <svg width="16" height="16" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg">
                            <g>
                                <path
                                    d="M15.7417 14.6098L13.486 12.3621C14.7088 10.8514 15.3054 8.9291 15.1526 6.99153C14.9998 5.05396 14.1093 3.24888 12.6648 1.94851C11.2203 0.648146 9.33193 -0.0483622 7.38901 0.00261294C5.44609 0.0535881 3.59681 0.84816 2.22248 2.22248C0.84816 3.59681 0.0535881 5.44609 0.00261294 7.38901C-0.0483622 9.33193 0.648146 11.2203 1.94851 12.6648C3.24888 14.1093 5.05396 14.9998 6.99153 15.1526C8.9291 15.3054 10.8514 14.7088 12.3621 13.486L14.6098 15.7417C14.6839 15.8164 14.7721 15.8757 14.8692 15.9161C14.9664 15.9566 15.0705 15.9774 15.1758 15.9774C15.281 15.9774 15.3852 15.9566 15.4823 15.9161C15.5794 15.8757 15.6676 15.8164 15.7417 15.7417C15.8164 15.6676 15.8757 15.5794 15.9161 15.4823C15.9566 15.3852 15.9774 15.281 15.9774 15.1758C15.9774 15.0705 15.9566 14.9664 15.9161 14.8692C15.8757 14.7721 15.8164 14.6839 15.7417 14.6098ZM1.62572 7.60368C1.62572 6.42135 1.97632 5.26557 2.63319 4.2825C3.29005 3.29943 4.22368 2.53322 5.31601 2.08076C6.40834 1.62831 7.61031 1.50992 8.76992 1.74058C9.92953 1.97124 10.9947 2.54059 11.8307 3.37662C12.6668 4.21266 13.2361 5.27783 13.4668 6.43744C13.6974 7.59705 13.579 8.79902 13.1266 9.89134C12.6741 10.9837 11.9079 11.9173 10.9249 12.5742C9.94178 13.231 8.78601 13.5816 7.60368 13.5816C6.01822 13.5816 4.49771 12.9518 3.37662 11.8307C2.25554 10.7096 1.62572 9.18913 1.62572 7.60368Z"/>
                            </g>
                        </svg>
                    </div>
                    <div class="search-input">
                        <div class="search-close"></div>
                        <form>
                            <div class="search-group">
                                <div class="form-inner2">
                                    <input type="text" placeholder="{{ $settings['header_search_placeholder'] ?? 'Find Your Perfect Taxi Service' }}">
                                    <button type="submit"><i class="bi bi-search"></i></button>
                                </div>
                            </div>
                            <div class="quick-search">
                                <ul>
                                    <li>{{ $settings['header_quick_search_label'] ?? 'Quick Search :' }}</li>
                                    <li><a href="{{ route('home') }}">{{ $settings['header_quick_search_1'] ?? 'Airport Transfer' }},</a></li>
                                    <li><a href="{{ route('home') }}">{{ $settings['header_quick_search_2'] ?? 'City Rides' }},</a></li>
                                    <li><a href="{{ route('home') }}">{{ $settings['header_quick_search_3'] ?? 'Outstation' }},</a></li>
                                    <li><a href="{{ route('home') }}">{{ $settings['header_quick_search_4'] ?? 'Rental' }},</a></li>
                                </ul>
                            </div>
                        </form>
                    </div>
                </div> --}}
            </div>
            {{-- <a href="#" class="primary-btn1 black-bg d-xl-flex d-none">
                <span>
                    <svg width="15" height="15" viewBox="0 0 15 15" xmlns="http://www.w3.org/2000/svg">
                        <g>
                            <path
                                d="M7.50105 7.78913C9.64392 7.78913 11.3956 6.03744 11.3956 3.89456C11.3956 1.75169 9.64392 0 7.50105 0C5.35818 0 3.60652 1.75169 3.60652 3.89456C3.60652 6.03744 5.35821 7.78913 7.50105 7.78913ZM14.1847 10.9014C14.0827 10.6463 13.9467 10.4082 13.7936 10.1871C13.0113 9.0306 11.8038 8.2653 10.4433 8.07822C10.2732 8.06123 10.0861 8.09522 9.95007 8.19727C9.23578 8.72448 8.38546 8.99658 7.50108 8.99658C6.61671 8.99658 5.76638 8.72448 5.05209 8.19727C4.91603 8.09522 4.72895 8.04421 4.5589 8.07822C3.19835 8.2653 1.97387 9.0306 1.20857 10.1871C1.05551 10.4082 0.919443 10.6633 0.817424 10.9014C0.766415 11.0034 0.783407 11.1225 0.834416 11.2245C0.970484 11.4626 1.14054 11.7007 1.2936 11.9048C1.53168 12.2279 1.78679 12.517 2.07592 12.7891C2.31401 13.0272 2.58611 13.2483 2.85824 13.4694C4.20177 14.4728 5.81742 15 7.48409 15C9.15076 15 10.7664 14.4728 12.1099 13.4694C12.382 13.2653 12.6541 13.0272 12.8923 12.7891C13.1644 12.517 13.4365 12.2279 13.6746 11.9048C13.8446 11.6837 13.9977 11.4626 14.1338 11.2245C14.2188 11.1225 14.2358 11.0034 14.1847 10.9014Z"/>
                        </g>
                    </svg>
                    Book Now
                </span>
                <span>
                    <svg width="15" height="15" viewBox="0 0 15 15" xmlns="http://www.w3.org/2000/svg">
                        <g>
                            <path
                                d="M7.50105 7.78913C9.64392 7.78913 11.3956 6.03744 11.3956 3.89456C11.3956 1.75169 9.64392 0 7.50105 0C5.35818 0 3.60652 1.75169 3.60652 3.89456C3.60652 6.03744 5.35821 7.78913 7.50105 7.78913ZM14.1847 10.9014C14.0827 10.6463 13.9467 10.4082 13.7936 10.1871C13.0113 9.0306 11.8038 8.2653 10.4433 8.07822C10.2732 8.06123 10.0861 8.09522 9.95007 8.19727C9.23578 8.72448 8.38546 8.99658 7.50108 8.99658C6.61671 8.99658 5.76638 8.72448 5.05209 8.19727C4.91603 8.09522 4.72895 8.04421 4.5589 8.07822C3.19835 8.2653 1.97387 9.0306 1.20857 10.1871C1.05551 10.4082 0.919443 10.6633 0.817424 10.9014C0.766415 11.0034 0.783407 11.1225 0.834416 11.2245C0.970484 11.4626 1.14054 11.7007 1.2936 11.9048C1.53168 12.2279 1.78679 12.517 2.07592 12.7891C2.31401 13.0272 2.58611 13.2483 2.85824 13.4694C4.20177 14.4728 5.81742 15 7.48409 15C9.15076 15 10.7664 14.4728 12.1099 13.4694C12.382 13.2653 12.6541 13.0272 12.8923 12.7891C13.1644 12.517 13.4365 12.2279 13.6746 11.9048C13.8446 11.6837 13.9977 11.4626 14.1338 11.2245C14.2188 11.1225 14.2358 11.0034 14.1847 10.9014Z"/>
                        </g>
                    </svg>
                    Book Now
                </span>
            </a> --}}
            <div class="sidebar-button mobile-menu-btn">

                <svg width="20" height="18" viewBox="0 0 20 18" xmlns="http://www.w3.org/2000/svg">
                    <path
                        d="M1.29445 2.8421H10.5237C11.2389 2.8421 11.8182 2.2062 11.8182 1.42105C11.8182 0.635903 11.2389 0 10.5237 0H1.29445C0.579249 0 0 0.635903 0 1.42105C0 2.2062 0.579249 2.8421 1.29445 2.8421Z">
                    </path>
                    <path
                        d="M1.23002 10.421H18.77C19.4496 10.421 20 9.78506 20 8.99991C20 8.21476 19.4496 7.57886 18.77 7.57886H1.23002C0.550421 7.57886 0 8.21476 0 8.99991C0 9.78506 0.550421 10.421 1.23002 10.421Z">
                    </path>
                    <path
                        d="M18.8052 15.1579H10.2858C9.62563 15.1579 9.09094 15.7938 9.09094 16.5789C9.09094 17.3641 9.62563 18 10.2858 18H18.8052C19.4653 18 20 17.3641 20 16.5789C20 15.7938 19.4653 15.1579 18.8052 15.1579Z">
                    </path>
                </svg>
            </div>
        </div>
    </div>
</header>
