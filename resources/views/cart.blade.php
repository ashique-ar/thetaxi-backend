@extends('layouts.app')

@section('title', 'Shopping Cart - TheTaxi')

@section('content')
    <!-- Breadcrumb section -->
    <div class="breadcrumb-section"
        style="background-image:linear-gradient(rgba(0, 0, 0, 0.3), rgba(0, 0, 0, 0.3)), url({{ asset('assets/img/innerpages/breadcrumb-bg.jpg') }});">
        <div class="container">
            <div class="banner-content">
                <h1>Shopping Cart</h1>
                <ul class="breadcrumb-list">
                    <li><a href="{{ route('home') }}">Home</a></li>
                    <li>Cart</li>
                </ul>
            </div>
        </div>
    </div>
    <!-- End Breadcrumb section -->

    <!-- Booking Form Section -->
    <div class="filter-wrapper text-center hotel mb-40">
        <div class="container">
            @include('components.booking-form')
        </div>
    </div>
    <!-- End Booking Form Section -->

    <!-- Cart Page Start-->
    <div class="cart-page pt-100 mb-100">
        <div class="container">
            @php
                // Get cart items from database via CartService with currency conversion
                try {
                    $cartService = app(\App\Services\CartService::class);
                    $cartModel = $cartService->getOrCreateCart();
                    $cartData = $cartService->toArray($cartModel); // This applies currency conversion
                    $cart = $cartData['items'] ?? [];
                    $cartTotals = $cartData['totals'] ?? [];
                    $currencySymbol = $cartData['currency_symbol'] ?? getCurrencySymbol();
                    $selectedCurrency = $cartData['currency'] ?? getSelectedCurrency();

                    // Fetch settings from database with updated defaults
                    $taxPercentage = \App\Models\Website\WebsiteSetting::getValue('tax_rate', null);
                    if ($taxPercentage === null) {
                        $taxPercentage = \App\Models\Website\WebsiteSetting::getValue('tax_percentage', 18);
                    }
                    $vatPercentage = \App\Models\Website\WebsiteSetting::getValue('vat_rate', null);
                    if ($vatPercentage === null) {
                        $vatPercentage = \App\Models\Website\WebsiteSetting::getValue('vat_percentage', 0);
                    }

                    // Normalize for display (support 0.18 or 18 formats)
                    $taxPercentage = (float) $taxPercentage;
                    $taxPercentageLabel =
                        $taxPercentage > 0 && $taxPercentage <= 1 ? round($taxPercentage * 100, 2) : $taxPercentage;
                    $vatPercentage = (float) $vatPercentage;
                    $vatPercentageLabel =
                        $vatPercentage > 0 && $vatPercentage <= 1 ? round($vatPercentage * 100, 2) : $vatPercentage;
                } catch (Exception $e) {
                    \Log::error('Error loading cart: ' . $e->getMessage());
                    $cart = [];
                    $cartTotals = [];
                    $currencySymbol = getCurrencySymbol();
                    $selectedCurrency = getSelectedCurrency();
                    $taxPercentage = 18;
                    $vatPercentage = 0;

                    // Normalize fallback labels
                    $taxPercentage = (float) $taxPercentage;
                    $taxPercentageLabel =
                        $taxPercentage > 0 && $taxPercentage <= 1 ? round($taxPercentage * 100, 2) : $taxPercentage;
                    $vatPercentage = (float) $vatPercentage;
                    $vatPercentageLabel =
                        $vatPercentage > 0 && $vatPercentage <= 1 ? round($vatPercentage * 100, 2) : $vatPercentage;
                }
            @endphp

            @if (!empty($cart))
                <div class="row g-lg-4 gy-5">
                    <div class="col-xl-8 col-lg-7">
                        <div class="cart-shopping-wrapper">
                            <div class="cart-widget-title d-flex justify-content-between align-items-center mb-4">
                                <h4>My Shopping Cart</h4>
                                <div class="d-flex">
                                    <a href="{{ route('search') }}" class="details-button">
                                        Continue Shopping
                                        <svg width="10" height="10" viewBox="0 0 10 10"
                                            xmlns="http://www.w3.org/2000/svg">
                                            <path
                                                d="M1 9L9 1M9 1C7.22222 1.33333 3.33333 2 1 1M9 1C8.66667 2.66667 8 6.33333 9 9"
                                                stroke-width="1.5" stroke-linecap="round" />
                                        </svg>
                                    </a>
                                    <button id="update-all-addons-btn" class="details-button ms-3">
                                        Update All Addons
                                        <i class="bi bi-arrow-repeat"></i>
                                    </button>
                                    <button class="details-button clear-cart ms-3">
                                        Clear Cart <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                            {{-- Vehicle Items - Card Layout --}}
                            @foreach ($cart as $key => $item)
                                @php
                                    // Calculate days from pickup and return dates
                                    $pickupDate = isset($item['pickup_date'])
                                        ? \Carbon\Carbon::parse($item['pickup_date'])
                                        : null;
                                    $returnDate = isset($item['return_date'])
                                        ? \Carbon\Carbon::parse($item['return_date'])
                                        : null;
                                    $calculatedDays =
                                        $pickupDate && $returnDate
                                            ? max(1, $pickupDate->diffInDays($returnDate) + 1)
                                            : 1;

                                    // Use total_price if available (already calculated for all days in LKR)
                                    // Otherwise calculate from per-day price and calculated days
                                    $itemTotal = isset($item['total_price'])
                                        ? $item['total_price']
                                        : ($item['price'] ?? 0) * $calculatedDays;
                                @endphp
                                <div class="cart-item-card" data-cart-key="{{ $key }}">
                                    {{-- Vehicle Info Section --}}
                                    <div class="cart-item-header">
                                        <div class="cart-item-main">
                                            <div class="product-info-wrapper">
                                                <div class="product-info-img">
                                                    @if (isset($item['image']) && $item['image'])
                                                        <img src="{{ s3_asset($item['image']) }}"
                                                            alt="{{ $item['name'] ?? 'Vehicle' }}">
                                                    @else
                                                        <img src="{{ asset('assets/img/innerpages/cart-img1.png') }}"
                                                            alt="{{ $item['name'] ?? 'Vehicle' }}">
                                                    @endif
                                                </div>
                                                <div class="product-info-content">
                                                    <span class="service-type-badge"
                                                        style="display: inline-block; background-color: #e8f4f8; color: #0066cc; padding: 4px 10px; border-radius: 12px; font-size: 14px; font-weight: 700; margin-bottom: 8px; border: 1px solid #0066cc;">
                                                        <i class="bi bi-tag"></i>
                                                        {{ $item['service_type_data']['name'] ?? ($item['service_type'] ?? 'Service') }}
                                                    </span>
                                                    <h6>
                                                        {{ $item['name'] ?? 'Vehicle Rental' }}
                                                    </h6>
                                                    @php
                                                        // Normalize pickup/dropoff display: support arrays and missing fields
                                                        $pickupLoc = is_array($item['pickup_location'] ?? null)
                                                            ? $item['pickup_location']['address'] ?? ''
                                                            : $item['pickup_location'] ?? '';
                                                        $dropoffLoc = is_array($item['dropoff_location'] ?? null)
                                                            ? $item['dropoff_location']['address'] ?? ''
                                                            : $item['dropoff_location'] ?? '';

                                                        // For airport transfers, fallback to airport fields or flight details
                                                        if (($item['service_type'] ?? '') === 'airport_transfers') {
                                                            $pickupLoc =
                                                                $pickupLoc ?:
                                                                $item['pickup_airport'] ??
                                                                    ($item['flight_details']['arrival_airport'] ?? '');
                                                            $dropoffLoc =
                                                                $dropoffLoc ?:
                                                                $item['dropoff_airport'] ??
                                                                    ($item['flight_details']['departure_airport'] ??
                                                                        '');
                                                        }
                                                    @endphp

                                                    <div class="booking-details">
                                                        @if (isset($item['pickup_date']) && isset($item['return_date']))
                                                            <!-- Pickup and Dropoff Locations - Always Displayed -->
                                                            <p style="margin-bottom: 6px;">
                                                                <i class="bi bi-geo-alt-fill" style="color: #0066cc;"></i>
                                                                <strong>{{ $pickupLoc ?: 'Pickup Location' }}</strong>
                                                                <i class="bi bi-arrow-right"
                                                                    style="margin: 0 4px; color: #999;"></i>
                                                                <strong>{{ $dropoffLoc ?: 'Dropoff Location' }}</strong>
                                                            </p>

                                                            <p style="margin-bottom: 6px;">
                                                                <i class="bi bi-calendar-event" style="color: #666;"></i>
                                                                {{ $pickupDate->format('M d, Y') }}
                                                                @if (isset($item['from_time']))
                                                                    <span class="text-muted">@
                                                                        {{ $item['from_time'] }}</span>
                                                                @endif
                                                                <span style="margin: 0 4px; color: #999;">→</span>
                                                                {{ $returnDate->format('M d, Y') }}
                                                                @if (isset($item['to_time']))
                                                                    <span class="text-muted">@
                                                                        {{ $item['to_time'] }}</span>
                                                                @endif
                                                            </p>
                                                            <p class="text-muted" style="margin-top: 8px;">
                                                                <i class="bi bi-hourglass-split"></i>
                                                                <strong>{{ getServiceDurationLabel($item['service_type'] ?? null, $calculatedDays) }}</strong>
                                                            </p>
                                                        @endif

                                                        {{-- Display included km information --}}
                                                        @php
                                                            $distanceDetails = $item['distance_details'] ?? [];

                                                            // If distance_details is empty, try to get from service package info or calculate
                                                            if (empty($distanceDetails)) {
                                                                $servicePackageInfo =
                                                                    $item['service_package_info'] ?? [];
                                                                if (!empty($servicePackageInfo)) {
                                                                    $distanceDetails = [
                                                                        'free_km_per_day' =>
                                                                            $servicePackageInfo['max_km_per_day'] ??
                                                                            null,
                                                                        'free_km_per_package' =>
                                                                            $servicePackageInfo['max_km_per_package'] ??
                                                                            null,
                                                                        'allowed_total_km' => isset(
                                                                            $servicePackageInfo['max_km_per_day'],
                                                                        )
                                                                            ? $servicePackageInfo['max_km_per_day'] *
                                                                                $calculatedDays
                                                                            : $servicePackageInfo[
                                                                                    'max_km_per_package'
                                                                                ] ?? null,
                                                                    ];
                                                                }
                                                            }

                                                            $freeKmPerDay = $distanceDetails['free_km_per_day'] ?? null;
                                                            $freeKmPerPackage =
                                                                $distanceDetails['free_km_per_package'] ?? null;
                                                            $allowedTotalKm =
                                                                $distanceDetails['allowed_total_km'] ?? null;
                                                            $extraKmPrice = $distanceDetails['extra_km_price'] ?? null;

                                                            // Calculate per-day km if only total is available
                                                            if (
                                                                !$freeKmPerDay &&
                                                                !$freeKmPerPackage &&
                                                                $allowedTotalKm &&
                                                                $calculatedDays > 0
                                                            ) {
                                                                $freeKmPerDay = $allowedTotalKm / $calculatedDays;
                                                            }
                                                        @endphp
                                                        @if ($freeKmPerDay || $freeKmPerPackage || $allowedTotalKm)
                                                            <p class="text-muted" style="margin-top: 6px;">
                                                                <i class="bi bi-speedometer2" style="color: #28a745;"></i>
                                                                @if ($freeKmPerDay)
                                                                    <strong>{{ number_format($freeKmPerDay, 0) }}
                                                                        km/day</strong> included
                                                                    @if ($calculatedDays > 1)
                                                                        <span
                                                                            class="text-muted">({{ number_format($freeKmPerDay * $calculatedDays, 0) }}
                                                                            km total)</span>
                                                                    @endif
                                                                @elseif ($freeKmPerPackage)
                                                                    <strong>{{ number_format($freeKmPerPackage, 0) }}
                                                                        km</strong> included
                                                                @elseif ($allowedTotalKm)
                                                                    <strong>{{ number_format($allowedTotalKm, 0) }}
                                                                        km</strong> included
                                                                @endif
                                                                @if ($extraKmPrice)
                                                                    <span class="ms-2 text-warning">
                                                                        <i class="bi bi-lightning-fill"></i>
                                                                        Extra:
                                                                        {{ $currencySymbol }}{{ number_format($extraKmPrice, 2) }}/km
                                                                    </span>
                                                                @endif
                                                            </p>
                                                        @endif
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="cart-item-pricing">
                                                @php
                                                    $serviceType = $item['service_type'] ?? null;
                                                    $pricingLabel = getServicePricingLabel($serviceType);
                                                    $isFixedRate = isServiceFixedRate($serviceType);
                                                @endphp
                                                @if (!$isFixedRate)
                                                    <div class="price-row">
                                                        <span class="price-label">{{ $pricingLabel }}:</span>
                                                        <span class="price-value">{{ $currencySymbol }}
                                                            {{ number_format($item['price'] ?? 0, 2) }}</span>
                                                    </div>
                                                @endif
                                                <div class="price-row total-row">
                                                    <span
                                                        class="price-label">{{ $isFixedRate ? $pricingLabel : 'Total' }}:</span>
                                                    <span class="price-value item-total">{{ $currencySymbol }}
                                                        {{ number_format($itemTotal, 2) }}</span>
                                                </div>
                                            </div>
                                            <div class="cart-item-actions">
                                                <button class="remove-item btn btn-sm btn-outline-danger"
                                                    data-cart-key="{{ $key }}">
                                                    <i class="bi bi-trash"></i> Remove
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    {{-- Addons Section - Outside table, below vehicle info --}}
                                    <div class="cart-item-addons" data-cart-key="{{ $key }}">
                                        <div class="unified-addons-container">
                                            <div class="addons-header-unified">
                                                <div class="header-left">
                                                    <h6 class="mb-0"><i class="bi bi-gift"></i> Customize with
                                                        Services</h6>
                                                    <small class="text-muted">Selected: <span
                                                            class="selected-count">0</span> service(s)</small>
                                                </div>
                                                <button type="button"
                                                    class="btn btn-sm btn-outline-secondary toggle-addons-section collapsed"
                                                    data-cart-key="{{ $key }}" title="Toggle addons section">
                                                    <i class="bi bi-chevron-down"></i> Show
                                                </button>
                                            </div>
                                            <div class="addons-grid-unified collapsed" data-cart-key="{{ $key }}"
                                                data-service-type="{{ $item['service_type'] ?? '' }}">
                                                <div class="text-center py-3">
                                                    <div class="spinner-border spinner-border-sm" role="status">
                                                        <span class="visually-hidden">Loading...</span>
                                                    </div>
                                                    <small class="d-block mt-2 text-muted">Loading
                                                        services...</small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    {{-- Extra KM Section - Outside table, below addons --}}
                                    <div class="cart-item-extra-km" data-cart-key="{{ $key }}">
                                        <div class="extra-km-container">
                                            <div class="extra-km-header">
                                                <div class="header-left">
                                                    <h6 class="mb-0"><i class="bi bi-speedometer2"></i> Purchase
                                                        Extra Kilometers</h6>
                                                    <small class="text-muted">Add more km to your package
                                                        allowance</small>
                                                </div>
                                                <button type="button"
                                                    class="btn btn-sm btn-outline-secondary toggle-extra-km-section collapsed"
                                                    data-cart-key="{{ $key }}" title="Toggle extra km section">
                                                    <i class="bi bi-chevron-down"></i> Show
                                                </button>
                                            </div>
                                            <div class="extra-km-content collapsed" data-cart-key="{{ $key }}">
                                                <div class="extra-km-loading text-center py-3">
                                                    <div class="spinner-border spinner-border-sm" role="status">
                                                        <span class="visually-hidden">Loading...</span>
                                                    </div>
                                                    <small class="d-block mt-2 text-muted">Loading extra km
                                                        rate...</small>
                                                </div>
                                                <div class="extra-km-form" style="display: none;">
                                                    <div class="extra-km-rate-info mb-3">
                                                        <span class="rate-label">Rate per km:</span>
                                                        <span class="rate-value">{{ $currencySymbol }}<span
                                                                class="extra-km-rate">0.00</span></span>
                                                    </div>
                                                    <div class="extra-km-input-group">
                                                        <label for="extra-km-input-{{ $key }}">Extra
                                                            Kilometers:</label>
                                                        <div class="km-qty-control">
                                                            <button type="button" class="km-qty-btn km-minus"
                                                                data-cart-key="{{ $key }}">−</button>
                                                            <input type="number" id="extra-km-input-{{ $key }}"
                                                                class="extra-km-input"
                                                                data-cart-key="{{ $key }}"
                                                                value="{{ $item['extra_km']['km'] ?? 0 }}" min="0"
                                                                max="10000" step="10" placeholder="0">
                                                            <button type="button" class="km-qty-btn km-plus"
                                                                data-cart-key="{{ $key }}">+</button>
                                                        </div>
                                                    </div>
                                                    <div class="extra-km-total mt-3">
                                                        <span class="total-label">Extra KM Cost:</span>
                                                        <span class="total-value">{{ $currencySymbol }}<span
                                                                class="extra-km-total-amount">{{ number_format($item['extra_km']['total_cost'] ?? 0, 2) }}</span></span>
                                                    </div>
                                                    <div class="extra-km-actions mt-3">
                                                        <button type="button"
                                                            class="btn btn-sm btn-primary apply-extra-km"
                                                            data-cart-key="{{ $key }}">
                                                            <i class="bi bi-check-lg"></i> Apply Extra KM
                                                        </button>
                                                        @if (isset($item['extra_km']) && ($item['extra_km']['km'] ?? 0) > 0)
                                                            <button type="button"
                                                                class="btn btn-sm btn-outline-danger remove-extra-km"
                                                                data-cart-key="{{ $key }}">
                                                                <i class="bi bi-x-lg"></i> Remove
                                                            </button>
                                                        @endif
                                                    </div>
                                                </div>
                                                <div class="extra-km-unavailable" style="display: none;">
                                                    <p class="text-muted mb-0"><i class="bi bi-info-circle"></i>
                                                        Extra km purchase is not available for this vehicle.</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach

                        </div>
                    </div>
                    <div class="col-xl-4 col-lg-5">
                        <div class="cart-order-sum-area">
                            <div class="cart-widget-title">
                                <h4>Order Summary</h4>
                            </div>
                            <div class="order-summary-wrap">
                                <ul class="order-summary-list">
                                    <li>
                                        <strong>Subtotal</strong>
                                        <strong class="cart-subtotal">
                                            {{ $currencySymbol }} {{ number_format($cartTotals['subtotal'] ?? 0, 2) }}
                                        </strong>
                                    </li>

                                    @if (($cartTotals['addon_charges'] ?? 0) > 0)
                                        <li>
                                            Addon Charges
                                            <div class="order-info">
                                                <p>Additional Services</p>
                                                <span class="addon-charges-amount">
                                                    {{ $currencySymbol }}
                                                    {{ number_format($cartTotals['addon_charges'] ?? 0, 2) }}
                                                </span>
                                            </div>
                                        </li>
                                    @endif
                                    @if (($cartTotals['extra_km_charges'] ?? 0) > 0)
                                        <li>
                                            Extra KM Charges
                                            <div class="order-info">
                                                <p>Additional Kilometers</p>
                                                <span class="extra-km-charges-amount">
                                                    {{ $currencySymbol }}
                                                    {{ number_format($cartTotals['extra_km_charges'] ?? 0, 2) }}
                                                </span>
                                            </div>
                                        </li>
                                    @endif
                                    @if (($cartTotals['service_fee'] ?? 0) > 0)
                                        <li>
                                            Service Charges
                                            <div class="order-info">
                                                <p>Processing Fee</p>
                                                <span class="service-fee">{{ $currencySymbol }}
                                                    {{ number_format($cartTotals['service_fee'] ?? 0, 2) }}</span>
                                            </div>
                                        </li>
                                    @endif
                                    @if (($cartTotals['tax'] ?? 0) > 0)
                                        <li>
                                            {{ $cartTotals['tax_label'] ?? 'Gov. Tax' }} ({{ $taxPercentageLabel }}%)
                                            <div class="order-info">
                                                <p>Government Tax</p>
                                                <span class="tax-amount">
                                                    {{ $currencySymbol }} {{ number_format($cartTotals['tax'] ?? 0, 2) }}
                                                </span>
                                            </div>
                                        </li>
                                    @endif
                                    @if ($vatPercentage > 0 && ($cartTotals['vat'] ?? 0) > 0)
                                        <li>
                                            {{ $cartTotals['vat_label'] ?? 'VAT' }} ({{ $vatPercentageLabel }}%)
                                            <div class="order-info">
                                                <p>Value Added Tax</p>
                                                <span class="vat-amount">
                                                    {{ $currencySymbol }} {{ number_format($cartTotals['vat'] ?? 0, 2) }}
                                                </span>
                                            </div>
                                        </li>
                                    @endif
                                    <li>
                                        <div class="promo-code-area">
                                            <span><i class="bi bi-tag"></i> Promo Code</span>
                                            @if (!empty($cartModel->coupon_code))
                                                {{-- Promo code is applied - show applied state --}}
                                                <div class="applied-promo-code">
                                                    <div class="promo-code-badge">
                                                        <i class="bi bi-check-circle-fill text-success"></i>
                                                        <span class="promo-code-text">{{ $cartModel->coupon_code }}</span>
                                                        <button type="button" class="remove-promo-btn"
                                                            id="remove-promo-btn" title="Remove promo code">
                                                            <i class="bi bi-x-lg"></i>
                                                        </button>
                                                    </div>
                                                    <div class="promo-discount-info">
                                                        <small class="text-success">
                                                            You save
                                                            {{ $currencySymbol }}
                                                            {{ number_format($cartTotals['coupon_discount'] ?? 0, 2) }}
                                                        </small>
                                                    </div>
                                                </div>
                                            @else
                                                {{-- No promo code - show input form --}}
                                                <form id="promo-code-form">
                                                    @csrf
                                                    <div class="form-inner promo-input-group">
                                                        <input type="text" name="promo_code"
                                                            placeholder="Enter promo code" id="promo-code-input"
                                                            autocomplete="off">
                                                        <button type="submit" class="apply-btn" id="apply-promo-btn">
                                                            <span class="btn-text">Apply</span>
                                                            <span class="btn-loading" style="display: none;">
                                                                <i class="bi bi-hourglass-split"></i>
                                                            </span>
                                                        </button>
                                                    </div>
                                                </form>
                                            @endif
                                            <div id="promo-code-message" class="mt-2"></div>
                                        </div>
                                    </li>
                                    @if (($cartTotals['coupon_discount'] ?? 0) > 0)
                                        <li class="discount-row">
                                            <strong class="text-success"><i class="bi bi-tag-fill"></i> Discount</strong>
                                            <strong class="discount-amount text-success">-{{ $currencySymbol }}
                                                {{ number_format($cartTotals['coupon_discount'] ?? 0, 2) }}</strong>
                                        </li>
                                    @endif
                                    <li>
                                        <strong>Total</strong>
                                        <strong class="cart-total">
                                            {{ $currencySymbol }} {{ number_format($cartTotals['total'] ?? 0, 2) }}
                                        </strong>
                                    </li>
                                </ul>

                                <div class="checkout-buttons mt-4">
                                    <a href="{{ route('checkout') }}" class="primary-btn1 mt-20 w-100">
                                        <span>
                                            Proceed to Checkout
                                            <svg width="10" height="10" viewBox="0 0 10 10"
                                                xmlns="http://www.w3.org/2000/svg">
                                                <path
                                                    d="M9.73535 1.14746C9.57033 1.97255 9.32924 3.26406 9.24902 4.66797C9.16817 6.08312 9.25559 7.5453 9.70214 8.73633C9.84754 9.12406 9.65129 9.55659 9.26367 9.70215C8.9001 9.83849 8.4969 9.67455 8.32812 9.33398L8.29785 9.26367L8.19921 8.98438C7.73487 7.5758 7.67054 5.98959 7.75097 4.58203C7.77875 4.09598 7.82525 3.62422 7.87988 3.17969L1.53027 9.53027C1.23738 9.82317 0.762615 9.82317 0.469722 9.53027C0.176829 9.23738 0.176829 8.76262 0.469722 8.46973L6.83593 2.10254C6.3319 2.16472 5.79596 2.21841 5.25 2.24902C3.8302 2.32862 2.2474 2.26906 0.958003 1.79102L0.704097 1.68945L0.635738 1.65527C0.303274 1.47099 0.157578 1.06102 0.310542 0.704102C0.463655 0.347333 0.860941 0.170391 1.22363 0.28418L1.29589 0.310547L1.48828 0.387695C2.47399 0.751207 3.79966 0.827571 5.16601 0.750977C6.60111 0.670504 7.97842 0.428235 8.86132 0.262695L9.95312 0.0585938L9.73535 1.14746Z">
                                                </path>
                                            </svg>
                                        </span>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            @else
                <!-- Empty Cart State -->
                <div class="empty-cart-area text-center py-5">
                    <div class="empty-cart-content">
                        <img src="{{ asset('assets/img/innerpages/empty-cart.png') }}" alt="Empty Cart" class="mb-4"
                            style="max-width: 300px;">
                        <h3>Your cart is empty</h3>
                        <p class="mb-4">Looks like you haven't added any vehicles to your cart yet.</p>
                        <a href="{{ route('search') }}" class="primary-btn1">
                            <span>
                                Start Shopping
                                <svg width="10" height="10" viewBox="0 0 10 10"
                                    xmlns="http://www.w3.org/2000/svg">
                                    <path d="M1 9L9 1M9 1C7.22222 1.33333 3.33333 2 1 1M9 1C8.66667 2.66667 8 6.33333 9 9"
                                        stroke-width="1.5" stroke-linecap="round" />
                                </svg>
                            </span>
                        </a>
                    </div>
                </div>
            @endif
        </div>
    </div>
    <!--Cart Page End-->
@endsection

@push('styles')
    <style>
        /* Cart Item Card Layout */
        .cart-item-card {
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 12px;
            margin-bottom: 20px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        }

        .cart-item-header {
            padding: 20px;
            border-bottom: 1px solid #f0f0f0;
        }

        .cart-item-main {
            display: flex;
            align-items: flex-start;
            gap: 20px;
            flex-wrap: wrap;
        }

        .cart-item-main .product-info-wrapper {
            flex: 1;
            min-width: 300px;
        }

        .cart-item-pricing {
            display: flex;
            flex-direction: column;
            gap: 8px;
            min-width: 150px;
            text-align: right;
        }

        .cart-item-pricing .price-row {
            display: flex;
            justify-content: space-between;
            gap: 15px;
        }

        .cart-item-pricing .price-label {
            color: #666;
            font-size: 14px;
        }

        .cart-item-pricing .price-value {
            font-weight: 600;
            color: #333;
        }

        .cart-item-pricing .total-row .price-value {
            font-size: 18px;
            color: var(--primary-color1, #c91c23);
            font-weight: 700;
        }

        .cart-item-actions {
            display: flex;
            align-items: flex-start;
        }

        /* Addons and Extra KM sections */
        .cart-item-addons,
        .cart-item-extra-km {
            border-top: 1px solid #f0f0f0;
        }

        .cart-item-addons .unified-addons-container,
        .cart-item-extra-km .extra-km-container {
            padding: 15px 20px;
            background: #fafbfc;
        }

        .cart-table {
            width: 100%;
            margin-bottom: 30px;
        }

        .cart-table th,
        .cart-table td {
            padding: 20px 15px;
            vertical-align: middle;
            border-bottom: 1px solid #eee;
        }

        .cart-table th {
            background-color: #f8f9fa;
            font-weight: 600;
            text-align: left;
        }

        .product-info-wrapper {
            display: flex;
            align-items: flex-start;
            gap: 15px;
        }

        .product-info-img {
            flex-shrink: 0;
            width: 80px;
            height: 80px;
            border-radius: 8px;
            overflow: hidden;
        }

        .product-info-img img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .product-info-content h6 {
            margin-bottom: 5px;
            font-weight: 600;
        }

        .booking-details p {
            margin-bottom: 3px;
            font-size: 13px;
            color: #666;
        }

        .quantity-area {
            margin-top: 10px;
        }


        .remove-item {
            background: none;
            border: none;
            color: #dc3545;
            cursor: pointer;
            font-size: 12px;
        }

        .cart-actions {
            border-top: 1px solid #eee;
            padding-top: 20px;
        }

        .order-summary-list li {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 0;
            border-bottom: 1px solid #eee;
        }

        .coupon-area .form-inner {
            display: flex;
            margin-top: 10px;
        }

        .coupon-area input {
            flex: 1;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 5px 0 0 5px;
        }

        /* Promo Code Section Styles */
        .promo-code-area {
            width: 100%;
        }

        .promo-code-area>span {
            display: flex;
            align-items: center;
            gap: 6px;
            font-weight: 600;
            color: #333;
            margin-bottom: 10px;
        }

        .promo-code-area>span i {
            color: var(--primary-color1);
        }

        .promo-input-group {
            display: flex;
            margin-top: 0 !important;
        }

        .promo-input-group input {
            flex: 1;
            padding: 10px 14px;
            border: 2px solid #ddd;
            border-radius: 6px 0 0 6px;
            font-size: 14px;
            transition: border-color 0.3s ease;
        }

        .promo-input-group input:focus {
            outline: none;
            border-color: var(--primary-color1);
        }

        .promo-input-group input::placeholder {
            color: #999;
        }

        .promo-input-group .apply-btn {
            padding: 10px 20px;
            background: var(--primary-color1);
            color: white;
            border: 2px solid var(--primary-color1);
            border-radius: 0 6px 6px 0;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s ease;
            min-width: 80px;
        }

        .promo-input-group .apply-btn:hover:not(:disabled) {
            background: #a81820;
            border-color: #a81820;
        }

        .promo-input-group .apply-btn:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }

        /* Applied Promo Code State */
        .applied-promo-code {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .promo-code-badge {
            display: flex;
            align-items: center;
            gap: 8px;
            background: #e8f5e9;
            border: 1px solid #c8e6c9;
            border-radius: 6px;
            padding: 10px 14px;
        }

        .promo-code-badge i.text-success {
            font-size: 18px;
        }

        .promo-code-text {
            font-weight: 700;
            color: #2e7d32;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            flex: 1;
        }

        .remove-promo-btn {
            background: none;
            border: none;
            color: #dc3545;
            cursor: pointer;
            padding: 4px 8px;
            border-radius: 4px;
            transition: all 0.2s ease;
        }

        .remove-promo-btn:hover {
            background: #ffebee;
            color: #c62828;
        }

        .promo-discount-info {
            padding-left: 4px;
        }

        .promo-discount-info small {
            font-weight: 600;
        }

        /* Discount Row */
        .discount-row {
            background: #f1f8e9;
            margin: 0 -15px;
            padding: 15px !important;
            border-radius: 6px;
        }

        .discount-row strong {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        #promo-code-message .alert {
            font-size: 13px;
            border-radius: 6px;
        }

        #promo-code-message .alert i {
            margin-right: 6px;
        }

        .apply-btn {
            padding: 8px 16px;
            background: var(--primary-color1);
            color: white;
            border: none;
            border-radius: 0 5px 5px 0;
            cursor: pointer;
        }

        .payment-buttons .primary-btn1 {
            display: block;
            text-align: center;
            text-decoration: none;
        }

        .btn-outline {
            background: transparent !important;
            color: var(--primary-color1) !important;
            border: 2px solid var(--primary-color1) !important;
        }

        .btn-secondary {
            background: #6c757d !important;
            border-color: #6c757d !important;
        }

        .empty-cart-area {
            min-height: 400px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Addon Styles */
        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .add-addons-btn {
            background-color: #28a745 !important;
            color: white !important;
            border-color: #28a745 !important;
        }

        .add-addons-btn:hover {
            background-color: #218838 !important;
            border-color: #218838 !important;
        }

        .addon-row {
            background-color: #f8f9fa;
        }

        .addon-item-display {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            padding: 12px 0;
        }

        .addon-info {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 1;
        }

        .addon-info i {
            font-size: 18px;
            color: #28a745;
        }

        .addon-info div {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .addon-info strong {
            font-size: 14px;
            color: #333;
        }

        .addon-pricing {
            display: flex;
            align-items: center;
            gap: 15px;
            white-space: nowrap;
        }

        .addon-qty {
            font-size: 13px;
            color: #666;
            background: white;
            padding: 4px 8px;
            border-radius: 4px;
        }

        .addon-price {
            font-weight: 600;
            color: #28a745;
            min-width: 80px;
            text-align: right;
        }

        .remove-addon-btn {
            color: #dc3545 !important;
            padding: 4px 8px;
            font-size: 16px;
        }

        .remove-addon-btn:hover {
            color: #c82333 !important;
        }

        @media (max-width: 768px) {

            /* Cart Layout Stack */
            .row.g-lg-4.gy-5 {
                flex-direction: column;
            }

            .col-xl-8.col-lg-7 {
                max-width: 100% !important;
                flex-basis: 100% !important;
            }

            .col-xl-4.col-lg-5 {
                max-width: 100% !important;
                flex-basis: 100% !important;
            }

            /* Cart Item Card Mobile */
            .cart-item-card {
                margin-bottom: 15px;
            }

            .cart-item-header {
                padding: 15px;
            }

            .cart-item-main {
                flex-direction: column;
                gap: 15px;
            }

            .cart-item-main .product-info-wrapper {
                min-width: 100%;
            }

            .cart-item-pricing {
                width: 100%;
                flex-direction: row;
                justify-content: space-between;
                padding: 10px;
                background: #f8f9fa;
                border-radius: 8px;
            }

            .cart-item-pricing .price-row {
                flex-direction: column;
                gap: 2px;
                text-align: center;
            }

            .cart-item-actions {
                width: 100%;
                justify-content: flex-end;
            }

            .cart-item-addons .unified-addons-container,
            .cart-item-extra-km .extra-km-container {
                padding: 12px 15px;
            }

            /* Cart Table Mobile (kept for backwards compatibility) */
            .cart-table {
                display: block;
                overflow-x: visible;
                white-space: normal;
                width: 100%;
            }

            .cart-table thead {
                display: none;
            }

            .cart-table tbody {
                display: block;
                width: 100%;
            }

            .cart-table tr {
                display: block;
                border: 1px solid #ddd;
                margin-bottom: 15px;
                border-radius: 8px;
                padding: 12px;
                background: white;
            }

            .cart-table td {
                display: block;
                padding: 8px 0;
                border: none;
                text-align: left !important;
                width: 100%;
                word-wrap: break-word;
                overflow-wrap: break-word;
            }

            .cart-table td:before {
                content: attr(data-label) ": ";
                font-weight: 700;
                margin-right: 5px;
                color: #333;
                display: inline;
            }

            .cart-table td[data-label="Vehicle Info"]:before {
                display: none;
            }

            .cart-table td[data-label="Vehicle Info"] {
                padding: 0;
            }

            /* Product Info - Stack vertically */
            .product-info-wrapper {
                flex-direction: column;
                text-align: left;
                align-items: flex-start;
                gap: 10px;
            }

            .product-info-img {
                width: 100%;
                height: auto;
                max-height: 200px;
            }

            .product-info-content {
                width: 100%;
            }

            .product-info-content h6 {
                font-size: 16px;
                margin: 8px 0;
                line-height: 1.4;
                word-break: break-word;
            }

            .service-type-badge {
                display: inline-block;
                margin-bottom: 8px !important;
                font-size: 12px !important;
            }

            .booking-details {
                width: 100%;
            }

            .booking-details p {
                font-size: 12px;
                line-height: 1.5;
                margin-bottom: 6px;
                word-break: break-word;
            }

            .booking-details i {
                margin-right: 4px;
            }

            /* Action buttons stacking */
            .cart-widget-title {
                flex-direction: column;
                gap: 10px;
            }

            .cart-widget-title .d-flex {
                flex-direction: column;
                width: 100%;
                gap: 8px;
            }

            .cart-widget-title .d-flex a,
            .cart-widget-title .d-flex button {
                width: 100%;
                font-size: 13px;
            }

            .details-button {
                display: flex;
                align-items: center;
                justify-content: center;
                width: 100%;
                padding: 10px;
            }

            /* Order Summary */
            .order-summary-list li {
                flex-direction: column;
                align-items: flex-start;
                gap: 5px;
                padding: 10px 0;
            }

            .order-info {
                width: 100%;
                text-align: right;
            }

            .payment-buttons {
                flex-direction: column;
                gap: 10px;
            }

            .payment-buttons .primary-btn1,
            .payment-buttons .outline-btn {
                width: 100%;
                font-size: 13px;
            }
        }

        @media (max-width: 576px) {
            .cart-page {
                padding-top: 50px;
                padding-bottom: 50px;
            }

            .cart-table tr {
                padding: 10px;
                margin-bottom: 12px;
            }

            .cart-table td {
                padding: 6px 0;
                font-size: 13px;
            }

            .product-info-wrapper {
                gap: 8px;
            }

            .product-info-img {
                width: 100%;
                height: auto;
                max-height: 150px;
            }

            .product-info-content h6 {
                font-size: 14px;
            }

            .booking-details p {
                font-size: 11px;
                margin-bottom: 4px;
            }

            .remove-item {
                font-size: 11px;
                padding: 6px 8px;
            }

            .promo-input-group {
                flex-direction: column;
            }

            .promo-input-group input {
                border-radius: 5px;
                margin-bottom: 8px;
            }

            .apply-btn {
                border-radius: 5px;
                width: 100%;
            }

            .order-summary-list li strong {
                font-size: 12px;
            }

            .cart-order-sum-area {
                position: relative;
            }

            .payment-buttons {
                flex-direction: column;
            }

            .payment-buttons button,
            .payment-buttons a {
                width: 100%;
                font-size: 12px;
            }
        }
    </style>
@endpush

@push('scripts')
    <script>
        $(document).ready(function() {
            // Success notification helper function
            function showSuccessNotification(message, duration = 3000) {
                const alert = $(`
                    <div class="alert alert-success alert-dismissible fade show" role="alert" style="position: fixed; top: 20px; right: 20px; z-index: 1100; min-width: 300px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
                        <i class="bi bi-check-circle-fill me-2"></i>
                        ${message}
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                `);
                $('body').append(alert);
                setTimeout(function() {
                    alert.fadeOut(300, function() {
                        $(this).remove();
                    });
                }, duration);
            }

            // Update cart totals from server
            function updateCartTotals() {
                $.ajax({
                    url: '{{ route('cart.summary') }}',
                    method: 'GET',
                    success: function(response) {
                        if (response.success) {
                            // Update order summary with fresh data from server
                            $('.cart-subtotal').text(getCurrencySymbol() + response.subtotal);
                            $('.service-fee').text(getCurrencySymbol() + response.service_fee);
                            $('.tax-amount').text(getCurrencySymbol() + response.tax);
                            $('.vat-amount').text(getCurrencySymbol() + response.vat);
                            $('.addon-charges-amount').text(getCurrencySymbol() + response
                                .addon_charges);
                            $('.extra-km-charges-amount').text(getCurrencySymbol() + (response
                                .extra_km_charges || '0.00'));
                            $('.discount-amount').text('-' + getCurrencySymbol() + response.discount);
                            $('.cart-total').text(getCurrencySymbol() + response.total);

                            console.log('Cart totals updated from server', response);
                        }
                    },
                    error: function(xhr) {
                        console.error('Error updating cart totals:', xhr);
                    }
                });
            }

            function getCurrencySymbol() {
                // Detect from page
                return '{{ $currencySymbol ?? 'LKR' }}';
            }

            // Remove item from cart (with loading state)
            $(document).on('click', '.remove-item', function() {
                const btn = $(this);
                if (!confirm('Are you sure you want to remove this item?')) return;

                const cartKey = btn.data('cart-key');
                const originalHtml = btn.html();

                // Set loading UI
                btn.prop('disabled', true);
                btn.html(
                    '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Removing...'
                );

                $.ajax({
                    url: '{{ route('cart.remove') }}',
                    method: 'POST',
                    data: {
                        _token: '{{ csrf_token() }}',
                        cart_key: cartKey
                    },
                    success: function(response) {
                        if (response.success) {
                            // Remove the entire cart item card
                            $(`.cart-item-card[data-cart-key="${cartKey}"]`).fadeOut(300,
                                function() {
                                    $(this).remove();

                                    // If there are no more cart items, show empty state
                                    if ($('.cart-item-card[data-cart-key]').length === 0) {
                                        $('.cart-shopping-wrapper').html(
                                            '<div class="text-center py-5"><em>Your cart is empty</em></div>'
                                        );
                                    }
                                });

                            // Dispatch cart updated event and refresh totals
                            window.dispatchEvent(new CustomEvent('cartUpdated'));
                            updateCartTotals();

                            showSuccessNotification(response.message ||
                                'Item removed from cart successfully!', 3000);
                        } else {
                            alert('Error: ' + response.message);
                            // Restore button on error
                            btn.prop('disabled', false);
                            btn.html(originalHtml);
                        }
                    },
                    error: function(xhr) {
                        console.error('Error removing item:', xhr);
                        alert('Error removing item. Please try again.');
                        // Restore button on error
                        btn.prop('disabled', false);
                        btn.html(originalHtml);
                    }
                });
            });

            // Clear entire cart
            $('.clear-cart').on('click', function() {
                if (confirm('Are you sure you want to clear your entire cart?')) {
                    $.ajax({
                        url: '{{ route('cart.clear') }}',
                        method: 'POST',
                        data: {
                            _token: '{{ csrf_token() }}'
                        },
                        success: function(response) {
                            if (response.success) {
                                // Clear cart display and dispatch event
                                $('.cart-table tbody').empty().append(
                                    '<tr><td colspan="5" class="text-center py-5"><em>Your cart is empty</em></td></tr>'
                                );
                                window.dispatchEvent(new CustomEvent('cartUpdated'));
                                showSuccessNotification('Cart cleared successfully!', 3000);
                            }
                        },
                        error: function() {
                            alert('Error clearing cart. Please try again.');
                        }
                    });
                }
            });

            // Promo Code Management
            // Apply promo code form submission
            $('#promo-code-form').on('submit', function(e) {
                e.preventDefault();

                let promoCode = $('#promo-code-input').val().trim();
                if (!promoCode) {
                    showPromoCodeError('Please enter a promo code');
                    return;
                }

                // Show loading state
                const applyBtn = $('#apply-promo-btn');
                applyBtn.prop('disabled', true);
                applyBtn.find('.btn-text').hide();
                applyBtn.find('.btn-loading').show();
                clearPromoCodeMessage();

                $.ajax({
                    url: '{{ route('cart.apply-promo-code') }}',
                    method: 'POST',
                    data: {
                        _token: '{{ csrf_token() }}',
                        promo_code: promoCode
                    },
                    success: function(response) {
                        if (response.success) {
                            // Reload page to show updated totals with promo code applied
                            showPromoCodeSuccess(response.message ||
                                'Promo code applied successfully!');
                            setTimeout(function() {
                                location.reload();
                            }, 1000);
                        } else {
                            showPromoCodeError(response.message || 'Invalid promo code');
                            resetApplyButton();
                        }
                    },
                    error: function(xhr) {
                        const errorMsg = xhr.responseJSON?.message ||
                            'Error applying promo code. Please try again.';
                        showPromoCodeError(errorMsg);
                        resetApplyButton();
                    }
                });
            });

            // Remove promo code button click
            $(document).on('click', '#remove-promo-btn', function() {
                const btn = $(this);
                btn.prop('disabled', true);
                btn.html('<i class="bi bi-hourglass-split"></i>');

                $.ajax({
                    url: '{{ route('cart.remove-promo-code') }}',
                    method: 'POST',
                    data: {
                        _token: '{{ csrf_token() }}'
                    },
                    success: function(response) {
                        if (response.success) {
                            showSuccessNotification(response.message || 'Promo code removed',
                                2000);
                            setTimeout(function() {
                                location.reload();
                            }, 500);
                        } else {
                            showPromoCodeError(response.message || 'Error removing promo code');
                            btn.prop('disabled', false);
                            btn.html('<i class="bi bi-x-lg"></i>');
                        }
                    },
                    error: function(xhr) {
                        const errorMsg = xhr.responseJSON?.message ||
                            'Error removing promo code. Please try again.';
                        showPromoCodeError(errorMsg);
                        btn.prop('disabled', false);
                        btn.html('<i class="bi bi-x-lg"></i>');
                    }
                });
            });

            // Helper functions for promo code UI
            function showPromoCodeError(message) {
                $('#promo-code-message').html(
                    '<div class="alert alert-danger py-2 px-3 mb-0"><i class="bi bi-exclamation-circle"></i> ' +
                    message + '</div>'
                );
            }

            function showPromoCodeSuccess(message) {
                $('#promo-code-message').html(
                    '<div class="alert alert-success py-2 px-3 mb-0"><i class="bi bi-check-circle"></i> ' +
                    message + '</div>'
                );
            }

            function clearPromoCodeMessage() {
                $('#promo-code-message').empty();
            }

            function resetApplyButton() {
                const applyBtn = $('#apply-promo-btn');
                applyBtn.prop('disabled', false);
                applyBtn.find('.btn-text').show();
                applyBtn.find('.btn-loading').hide();
            }

            // Addon Management - Unified Section

            // Load addons for each cart item on page load
            $(document).ready(function() {
                loadAddonsForAllItems();
            });

            function loadAddonsForAllItems() {
                $('.cart-item-addons').each(function() {
                    const cartKey = $(this).data('cart-key');
                    const serviceType = $(this).find('.addons-grid-unified').data('service-type');
                    loadUnifiedAddonsForItem(cartKey, serviceType);
                });
            }

            function loadUnifiedAddonsForItem(cartKey, serviceType) {
                const container = $(`.addons-grid-unified[data-cart-key="${cartKey}"]`);
                if (!container.length) return Promise.resolve();
                if (container.data('addons-loaded')) return Promise.resolve();

                return new Promise(function(resolve, reject) {
                    $.ajax({
                        url: '{{ route('cart.addons.available') }}',
                        method: 'GET',
                        data: {
                            service_type_id: serviceType || ''
                        },
                        success: function(response) {
                            if (response.success && response.data) {
                                // Fetch currently selected addons for this cart item
                                $.ajax({
                                    url: '{{ route('cart.addons.get', ['cartKey' => ':cartKey']) }}'
                                        .replace(':cartKey', cartKey),
                                    method: 'GET',
                                    success: function(resp2) {
                                        if (resp2.success && resp2.data) {
                                            displayUnifiedAddonsForItem(cartKey,
                                                response.data, resp2.data,
                                                serviceType);
                                        } else {
                                            displayUnifiedAddonsForItem(cartKey,
                                                response.data, {}, serviceType);
                                        }
                                        container.data('addons-loaded', true);
                                        resolve();
                                    },
                                    error: function() {
                                        displayUnifiedAddonsErrorForItem(cartKey,
                                            'Error loading services');
                                        container.data('addons-loaded', true);
                                        reject(new Error(
                                            'Error fetching selected addons'
                                        ));
                                    }
                                });
                            } else {
                                displayUnifiedAddonsErrorForItem(cartKey,
                                    'No services available');
                                container.data('addons-loaded', true);
                                resolve();
                            }
                        },
                        error: function(xhr) {
                            console.error('Error loading addons:', xhr);
                            displayUnifiedAddonsErrorForItem(cartKey, 'Error loading services');
                            container.data('addons-loaded', true);
                            reject(new Error('Error loading addons'));
                        }
                    });
                });
            }

            // Preload addons in the background with limited concurrency to avoid hammering the API
            async function preloadAddonsInBackground(concurrency = 3, staggerMs = 150) {
                const rows = $('.cart-item-addons').toArray();
                let index = 0;
                const errors = [];

                async function worker() {
                    while (true) {
                        const i = index++;
                        if (i >= rows.length) break;
                        const row = rows[i];
                        const cartKey = $(row).data('cart-key');
                        const serviceType = $(row).find('.addons-grid-unified').data('service-type');
                        try {
                            await loadUnifiedAddonsForItem(cartKey, serviceType);
                        } catch (e) {
                            console.warn('Preload addon error for', cartKey, e);
                            errors.push({
                                cartKey,
                                error: e
                            });
                        }
                        // Small delay between requests
                        await new Promise(r => setTimeout(r, staggerMs));
                    }
                }

                const workers = [];
                for (let w = 0; w < Math.max(1, Math.min(concurrency, rows.length)); w++) workers.push(
                    worker());
                await Promise.all(workers);
                if (errors.length) console.warn('Some addon preloads failed', errors);
            }

            // Schedule background preload after full window load so assets and scripts settle
            window.addEventListener('load', function() {
                // Delay slightly to prioritize critical resources
                setTimeout(function() {
                    preloadAddonsInBackground(3, 150).catch(e => console.warn(
                        'Addon preload failed', e));
                }, 500);
            });

            function fetchSelectedAddonsForItem(cartKey, allAddons, serviceType) {
                $.ajax({
                    url: '{{ route('cart.addons.get', ['cartKey' => ':cartKey']) }}'.replace(':cartKey',
                        cartKey),
                    method: 'GET',
                    success: function(response) {
                        if (response.success && response.data) {
                            displayUnifiedAddonsForItem(cartKey, allAddons, response.data, serviceType);
                        } else {
                            displayUnifiedAddonsForItem(cartKey, allAddons, {}, serviceType);
                        }
                    },
                    error: function() {
                        displayUnifiedAddonsForItem(cartKey, allAddons, {}, serviceType);
                    }
                });
            }

            function displayUnifiedAddonsForItem(cartKey, addons, selectedAddons, serviceType) {
                const container = $(`.unified-addons-row[data-cart-key="${cartKey}"] .addons-grid-unified`);
                container.empty();

                if (addons.length === 0) {
                    container.html(
                        `<p class="text-center text-muted py-3">No services available for ${serviceType || 'this rental'}</p>`
                    );
                    return;
                }

                const grid = $('<div class="unified-addons-grid"></div>');
                let selectedCount = 0;

                // selectedAddons is an array of {addon_id, qty, ...}
                const selectedAddonsMap = {};
                if (Array.isArray(selectedAddons)) {
                    selectedAddons.forEach(function(addon) {
                        selectedAddonsMap[addon.addon_id] = addon.qty || 0;
                    });
                } else if (typeof selectedAddons === 'object') {
                    selectedAddonsMap = selectedAddons;
                }

                addons.forEach(function(addon) {
                    // Get current quantity from selected addons
                    const currentQty = selectedAddonsMap[addon.id] || 0;
                    const isSelected = currentQty > 0;

                    if (isSelected) selectedCount++;

                    const addonCard = `
                        <div class="unified-addon-card ${isSelected ? 'addon-selected' : 'addon-not-selected'}" data-addon-id="${addon.id}" data-cart-key="${cartKey}">
                            <div class="addon-card-header-unified">
                                <div class="addon-status-badge ${isSelected ? 'badge-selected' : 'badge-available'}">
                                    <i class="bi ${isSelected ? 'bi-check-circle-fill' : 'bi-plus-circle'}"></i>
                                    <span class="badge-text">${isSelected ? 'SELECTED' : 'AVAILABLE'}</span>
                                </div>
                                ${addon.thumbnail ? `<img src="${addon.thumbnail}" alt="${addon.name}" class="addon-card-img-unified">` : '<div class="addon-card-img-unified bg-light"><i class="bi bi-gift"></i></div>'}
                            </div>
                            <div class="addon-card-body-unified">
                                <h6 class="addon-title-unified">${addon.name}</h6>
                                <small class="addon-desc-unified">${addon.description || 'Service'}</small>
                                <div class="addon-price-unified">
                                    <strong>${parseFloat(addon.amount).toFixed(2)}</strong>
                                    <span>${addon.rate_type === 'percentage' ? '%/day' : 'LKR/one-time'}</span>
                                </div>
                            </div>
                            <div class="addon-controls-unified">
                                <div class="qty-control-unified">
                                    <button class="qty-btn-unified qty-minus-unified" data-addon-id="${addon.id}" data-cart-key="${cartKey}" title="Decrease">−</button>
                                    <input type="number" class="qty-input-unified" value="${currentQty}" data-original-qty="${currentQty}" min="0" max="${addon.max_qty || 999}" data-addon-id="${addon.id}" data-cart-key="${cartKey}">
                                    <button class="qty-btn-unified qty-plus-unified" data-addon-id="${addon.id}" data-cart-key="${cartKey}" title="Increase">+</button>
                                </div>
                                <div class="addon-action-buttons">
                                    <button class="btn-apply-addon-unified ${isSelected ? 'btn-addon-update' : 'btn-addon-add'}" data-addon-id="${addon.id}" data-cart-key="${cartKey}">
                                        ${isSelected ? '<i class="bi bi-arrow-clockwise"></i> Update' : '<i class="bi bi-plus-lg"></i> Add'}
                                    </button>
                                    ${isSelected ? `<button class="btn-remove-addon-unified remove-addon-btn" data-addon-id="${addon.id}" data-cart-key="${cartKey}" title="Remove this addon">
                                                                                                    <i class="bi bi-trash"></i> Remove
                                                                                                </button>` : ''}
                                </div>
                            </div>
                        </div>
                    `;
                    grid.append(addonCard);
                });

                container.html(grid);
                updateSelectedCount(cartKey, selectedCount);
            }

            function displayUnifiedAddonsErrorForItem(cartKey, message) {
                const container = $(`.cart-item-addons[data-cart-key="${cartKey}"] .addons-grid-unified`);
                container.html(`<p class="text-center text-muted py-3">${message}</p>`);
            }

            function updateSelectedCount(cartKey, count) {
                const countElement = $(`.cart-item-addons[data-cart-key="${cartKey}"] .selected-count`);
                countElement.text(count);

                // Update the small text to indicate removal is possible
                const parentSmall = countElement.parent();
                if (count > 0) {
                    parentSmall.html(
                        `Selected: <span class="selected-count">${count}</span> service(s) <small class="text-success">(Remove available)</small>`
                    );
                } else {
                    parentSmall.html(`Selected: <span class="selected-count">0</span> service(s)`);
                }
            }

            // Qty controls for unified addons
            $(document).on('click', '.qty-plus-unified', function() {
                const input = $(this).siblings('.qty-input-unified');
                const max = parseInt(input.attr('max')) || 999;
                const current = parseInt(input.val()) || 0;
                input.val(Math.min(current + 1, max)).trigger('change');
            });

            $(document).on('click', '.qty-minus-unified', function() {
                const input = $(this).siblings('.qty-input-unified');
                const current = parseInt(input.val()) || 1;
                input.val(Math.max(0, current - 1)).trigger('change');
            });

            $(document).on('change', '.qty-input-unified', function() {
                updateAddonCardStatus($(this).closest('.unified-addon-card'));
            });

            function updateAddonCardStatus(card) {
                const qty = parseInt(card.find('.qty-input-unified').val()) || 0;
                const badge = card.find('.addon-status-badge');
                const button = card.find('.btn-apply-addon-unified');
                const icon = badge.find('i');
                const text = badge.find('.badge-text');

                if (qty > 0) {
                    card.removeClass('addon-not-selected').addClass('addon-selected');
                    badge.removeClass('badge-available').addClass('badge-selected');
                    icon.removeClass('bi-plus-circle').addClass('bi-check-circle-fill');
                    text.text('SELECTED');
                    button.removeClass('btn-addon-add').addClass('btn-addon-update')
                        .html('<i class="bi bi-arrow-clockwise"></i> Update');
                } else {
                    card.removeClass('addon-selected').addClass('addon-not-selected');
                    badge.removeClass('badge-selected').addClass('badge-available');
                    icon.removeClass('bi-check-circle-fill').addClass('bi-plus-circle');
                    text.text('AVAILABLE');
                    button.removeClass('btn-addon-update').addClass('btn-addon-add')
                        .html('<i class="bi bi-plus-lg"></i> Add');
                }
            }

            // Apply/Update addon (single-click also performs bulk update if other changes exist)
            $(document).on('click', '.btn-apply-addon-unified', function() {
                const btn = $(this);
                const addonId = btn.data('addon-id');
                const cartKey = btn.data('cart-key');
                const qty = parseInt(btn.closest('.unified-addon-card').find('.qty-input-unified').val()) ||
                    0;

                // Build updates list by comparing current qty with data-original-qty
                const updates = [];
                $('.qty-input-unified').each(function() {
                    const $el = $(this);
                    const original = parseInt($el.attr('data-original-qty') || 0);
                    const current = parseInt($el.val() || 0);
                    const aCartKey = $el.data('cart-key');
                    const aAddonId = $el.data('addon-id');
                    if (!aCartKey || !aAddonId) return;

                    // Include if changed OR if this is the clicked addon (user expects it to be applied)
                    if (current !== original || (aAddonId === addonId && aCartKey === cartKey)) {
                        updates.push({
                            cart_key: aCartKey,
                            addon_id: aAddonId,
                            qty: current
                        });
                    }
                });

                if (updates.length > 1) {
                    // Perform bulk update for all changed addons
                    btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Updating...');
                    $.ajax({
                        url: '{{ route('cart.addons.update-all') }}',
                        method: 'POST',
                        data: {
                            _token: '{{ csrf_token() }}',
                            updates: updates
                        },
                        success: function(response) {
                            if (response.success) {
                                // On success, reload to show updated prices and states
                                location.reload();
                            } else {
                                alert(response.message || 'Error updating addons');
                                btn.prop('disabled', false).html(btn.hasClass(
                                        'btn-addon-update') ?
                                    '<i class="bi bi-arrow-clockwise"></i> Update' :
                                    '<i class="bi bi-plus-lg"></i> Add');
                            }
                        },
                        error: function(xhr) {
                            const msg = xhr.responseJSON?.message || 'Error updating addons';
                            alert(msg);
                            btn.prop('disabled', false).html(btn.hasClass('btn-addon-update') ?
                                '<i class="bi bi-arrow-clockwise"></i> Update' :
                                '<i class="bi bi-plus-lg"></i> Add');
                        }
                    });
                } else {
                    // Fallback to single-item behavior
                    if (qty === 0) {
                        removeAddonFromCart(cartKey, addonId);
                    } else {
                        addOrUpdateAddonToCart(cartKey, addonId, qty);
                    }
                }
            });

            function addOrUpdateAddonToCart(cartKey, addonId, qty) {
                $.ajax({
                    url: '{{ route('cart.addon.add') }}',
                    method: 'POST',
                    data: {
                        _token: '{{ csrf_token() }}',
                        cart_key: cartKey,
                        addon_id: addonId,
                        qty: qty
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert(response.message || 'Error updating addon');
                        }
                    },
                    error: function(xhr) {
                        console.error('Error:', xhr);
                        const errorMsg = xhr.responseJSON?.message || 'Error updating addon';
                        alert(errorMsg);
                    }
                });
            }

            // Remove addon from cart
            function removeAddonFromCart(cartKey, addonId) {
                $.ajax({
                    url: '{{ route('cart.addon.remove') }}',
                    method: 'POST',
                    data: {
                        _token: '{{ csrf_token() }}',
                        cart_key: cartKey,
                        addon_id: addonId
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert(response.message || 'Error removing addon');
                        }
                    },
                    error: function() {
                        alert('Error removing addon');
                    }
                });
            }

            // Remove addon from cart via button click
            $(document).on('click', '.remove-addon-btn', function() {
                const cartKey = $(this).data('cart-key');
                const addonId = $(this).data('addon-id');

                if (confirm('Remove this addon?')) {
                    removeAddonFromCart(cartKey, addonId);
                }
            });

            // Update All Addons - batch update all changed addon quantities
            $('#update-all-addons-btn').on('click', function() {
                const btn = $(this);
                const updates = [];

                $('.qty-input-unified').each(function() {
                    const $el = $(this);
                    const original = parseInt($el.attr('data-original-qty') || 0);
                    const current = parseInt($el.val() || 0);
                    if (current !== original) {
                        updates.push({
                            cart_key: $el.data('cart-key'),
                            addon_id: $el.data('addon-id'),
                            qty: current
                        });
                    }
                });

                if (updates.length === 0) {
                    alert('No addon changes detected.');
                    return;
                }

                btn.prop('disabled', true).html('Updating...');

                $.ajax({
                    url: '{{ route('cart.addons.update-all') }}',
                    method: 'POST',
                    data: {
                        _token: '{{ csrf_token() }}',
                        updates: updates
                    },
                    success: function(response) {
                        if (response.success) {
                            showSuccessNotification(response.message ||
                                'Addons updated for all items');
                            setTimeout(function() {
                                location.reload();
                            }, 600);
                        } else {
                            alert(response.message || 'Error updating addons');
                            btn.prop('disabled', false).html('Update All Addons');
                        }
                    },
                    error: function(xhr) {
                        alert(xhr.responseJSON?.message || 'Error updating addons');
                        btn.prop('disabled', false).html('Update All Addons');
                    }
                });
            });

            // Toggle addons section visibility
            $(document).on('click', '.toggle-addons-section', function() {
                const btn = $(this);
                const cartKey = btn.data('cart-key');
                const addonGrid = $(`.addons-grid-unified[data-cart-key="${cartKey}"]`);

                btn.toggleClass('collapsed');
                addonGrid.toggleClass('collapsed');

                // Update button text and icon
                if (btn.hasClass('collapsed')) {
                    btn.html('<i class="bi bi-chevron-down"></i> Show');
                } else {
                    btn.html('<i class="bi bi-chevron-up"></i> Hide');
                }
            });

            // ==========================================
            // Extra KM Purchase Management
            // ==========================================

            // Load extra km rate for all items on page load
            $(document).ready(function() {
                loadExtraKmForAllItems();
            });

            function loadExtraKmForAllItems() {
                $('.cart-item-extra-km').each(function() {
                    const cartKey = $(this).data('cart-key');
                    loadExtraKmRateForItem(cartKey);
                });
            }

            function loadExtraKmRateForItem(cartKey) {
                const container = $(`.cart-item-extra-km[data-cart-key="${cartKey}"] .extra-km-content`);
                const loadingEl = container.find('.extra-km-loading');
                const formEl = container.find('.extra-km-form');
                const unavailableEl = container.find('.extra-km-unavailable');
                $(`.cart-item-extra-km[data-cart-key="${cartKey}"]`).hide();
                $.ajax({
                    url: '{{ url('/cart/extra-km') }}/' + cartKey,
                    method: 'GET',
                    success: function(response) {
                        loadingEl.hide();

                        // Show extra-km UI only when vehicle group has slab pricing configured
                        const hasSlab = Boolean(response.success && response.data && response.data
                            .has_slab);

                        if (!hasSlab) {
                            // Hide the entire extra-km section for this cart item when not available
                            $(`.cart-item-extra-km[data-cart-key="${cartKey}"]`).hide();
                            return;
                        } else {
                            $(`.cart-item-extra-km[data-cart-key="${cartKey}"]`).show();
                        }

                        const rateObj = response.data.rate || null;
                        const currentExtraKm = response.data.current_extra_km || null;

                        // If rate info present, update rate display
                        if (rateObj && rateObj.rate) {
                            const rate = rateObj.rate;
                            container.find('.extra-km-rate').text(parseFloat(rate).toFixed(2));
                            container.data('rate', rate);
                        }

                        // Update current values if extra km already added
                        if (currentExtraKm && currentExtraKm.km > 0) {
                            container.find('.extra-km-input').val(currentExtraKm.km);
                            container.find('.extra-km-total-amount').text(parseFloat(currentExtraKm
                                .total_cost).toFixed(2));
                            container.find('.remove-extra-km').show();
                        }

                        // Show the form for slab-priced items
                        formEl.show();
                    },

                    error: function(xhr) {
                        console.error('Error loading extra km rate:', xhr);
                        loadingEl.hide();
                        // Hide the extra-km section on error to avoid showing unavailable placeholder
                        $(`.cart-item-extra-km[data-cart-key="${cartKey}"]`).hide();
                    }
                });
            }

            // Toggle extra km section visibility
            $(document).on('click', '.toggle-extra-km-section', function() {
                const btn = $(this);
                const cartKey = btn.data('cart-key');
                const content = $(`.extra-km-content[data-cart-key="${cartKey}"]`);

                btn.toggleClass('collapsed');
                content.toggleClass('collapsed');

                // Update button text and icon
                if (btn.hasClass('collapsed')) {
                    btn.html('<i class="bi bi-chevron-down"></i> Show');
                } else {
                    btn.html('<i class="bi bi-chevron-up"></i> Hide');
                }
            });

            // Extra KM quantity controls
            $(document).on('click', '.km-plus', function() {
                const cartKey = $(this).data('cart-key');
                const input = $(`.extra-km-input[data-cart-key="${cartKey}"]`);
                const current = parseInt(input.val()) || 0;
                const max = parseInt(input.attr('max')) || 10000;
                const step = parseInt(input.attr('step')) || 10;
                input.val(Math.min(current + step, max)).trigger('input');
            });

            $(document).on('click', '.km-minus', function() {
                const cartKey = $(this).data('cart-key');
                const input = $(`.extra-km-input[data-cart-key="${cartKey}"]`);
                const current = parseInt(input.val()) || 0;
                const step = parseInt(input.attr('step')) || 10;
                input.val(Math.max(0, current - step)).trigger('input');
            });

            // Update total when km input changes
            $(document).on('input', '.extra-km-input', function() {
                const cartKey = $(this).data('cart-key');
                const km = parseInt($(this).val()) || 0;
                const container = $(`.extra-km-content[data-cart-key="${cartKey}"]`);
                const rate = parseFloat(container.data('rate')) || 0;
                const total = km * rate;

                container.find('.extra-km-total-amount').text(total.toFixed(2));
            });

            // Apply extra km
            $(document).on('click', '.apply-extra-km', function() {
                const cartKey = $(this).data('cart-key');
                const km = parseInt($(`.extra-km-input[data-cart-key="${cartKey}"]`).val()) || 0;

                const btn = $(this);
                btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Applying...');

                $.ajax({
                    url: '{{ route('cart.extra-km.add') }}',
                    method: 'POST',
                    data: {
                        _token: '{{ csrf_token() }}',
                        cart_key: cartKey,
                        extra_km: km
                    },
                    success: function(response) {
                        if (response.success) {
                            showSuccessNotification(response.message, 3000);
                            location.reload();
                        } else {
                            alert(response.message || 'Error applying extra km');
                            btn.prop('disabled', false).html(
                                '<i class="bi bi-check-lg"></i> Apply Extra KM');
                        }
                    },
                    error: function(xhr) {
                        console.error('Error:', xhr);
                        const errorMsg = xhr.responseJSON?.message || 'Error applying extra km';
                        alert(errorMsg);
                        btn.prop('disabled', false).html(
                            '<i class="bi bi-check-lg"></i> Apply Extra KM');
                    }
                });
            });

            // Remove extra km
            $(document).on('click', '.remove-extra-km', function() {
                const cartKey = $(this).data('cart-key');

                if (!confirm('Remove extra km from this item?')) {
                    return;
                }

                const btn = $(this);
                btn.prop('disabled', true);

                $.ajax({
                    url: '{{ route('cart.extra-km.remove') }}',
                    method: 'POST',
                    data: {
                        _token: '{{ csrf_token() }}',
                        cart_key: cartKey
                    },
                    success: function(response) {
                        if (response.success) {
                            showSuccessNotification('Extra km removed', 3000);
                            location.reload();
                        } else {
                            alert(response.message || 'Error removing extra km');
                            btn.prop('disabled', false);
                        }
                    },
                    error: function(xhr) {
                        console.error('Error:', xhr);
                        alert('Error removing extra km');
                        btn.prop('disabled', false);
                    }
                });
            });
        });
    </script>

    <style>
        /* Addon Toggle Button Styles */
        .addons-header-unified {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
        }

        .header-left {
            display: flex;
            flex-direction: column;
            gap: 5px;
            flex: 1;
        }

        .toggle-addons-section {
            padding: 6px 12px !important;
            font-size: 12px !important;
            white-space: nowrap;
            transition: all 0.3s ease;
        }

        .toggle-addons-section i {
            margin-right: 4px;
            transition: transform 0.3s ease;
        }

        .toggle-addons-section.collapsed i {
            transform: rotate(180deg);
        }

        .toggle-addons-section.collapsed {
            background-color: #e8f5e9 !important;
            color: #2e7d32 !important;
            border-color: #2e7d32 !important;
        }

        .addons-grid-unified {
            transition: max-height 0.3s ease, opacity 0.3s ease, padding 0.3s ease;
            overflow: hidden;
            max-height: 2000px;
            opacity: 1;
            padding: 15px 0;
        }

        .addons-grid-unified.collapsed {
            max-height: 0;
            opacity: 0;
            padding: 0;
            overflow: hidden;
        }

        /* Unified Addons Row Styling */
        .unified-addons-row {
            background-color: #f9f9f9;
            border-top: 2px solid #e0e0e0;
        }

        .unified-addons-container {
            padding: 20px 0;
        }

        .addons-header-unified {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 18px;
            padding-bottom: 12px;
            border-bottom: 1px solid #ddd;
        }

        .header-left {
            display: flex;
            align-items: baseline;
            gap: 12px;
        }

        .addons-header-unified h6 {
            font-size: 15px;
            font-weight: 700;
            color: #333;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .addons-header-unified h6 i {
            color: #c91c23;
            font-size: 18px;
        }

        .addons-header-unified small {
            font-size: 12px;
            color: #666;
            font-weight: 500;
        }

        .selected-count {
            color: #c91c23;
            font-weight: 700;
            font-size: 14px;
        }

        /* Unified Addon Grid */
        .unified-addons-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 16px;
        }

        /* Unified Addon Card */
        .unified-addon-card {
            background: white;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            padding: 16px;
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            gap: 12px;
            position: relative;
        }

        .unified-addon-card.addon-selected {
            border-color: #c91c23;
            background-color: #fff8f8;
            box-shadow: 0 2px 10px rgba(201, 28, 35, 0.15);
        }

        .unified-addon-card.addon-not-selected:hover {
            border-color: #ddd;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        .addon-card-header-unified {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 10px;
        }

        .addon-status-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            white-space: nowrap;
        }

        .addon-status-badge.badge-selected {
            background-color: #28a745;
            color: white;
        }

        .addon-status-badge.badge-available {
            background-color: #f0f0f0;
            color: #666;
        }

        .addon-status-badge i {
            font-size: 13px;
        }

        .badge-text {
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .addon-card-img-unified {
            width: 60px;
            height: 60px;
            border-radius: 8px;
            object-fit: cover;
            flex-shrink: 0;
            background-color: #f0f0f0;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #999;
            font-size: 28px;
        }

        /* Addon Card Body */
        .addon-card-body-unified {
            flex-grow: 1;
        }

        .addon-title-unified {
            margin: 0;
            font-size: 14px;
            font-weight: 700;
            color: #333;
            line-height: 1.3;
        }

        .addon-desc-unified {
            display: block;
            color: #888;
            font-size: 12px;
            line-height: 1.4;
            margin-top: 4px;
        }

        .addon-price-unified {
            display: flex;
            align-items: baseline;
            gap: 6px;
            margin-top: 8px;
            font-size: 13px;
        }

        .addon-price-unified strong {
            font-size: 16px;
            color: #c91c23;
            font-weight: 700;
        }

        .addon-price-unified span {
            color: #999;
            font-size: 11px;
        }

        /* Addon Controls */
        .addon-controls-unified {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .qty-control-unified {
            display: flex;
            align-items: center;
            gap: 2px;
            border: 1px solid #ddd;
            border-radius: 6px;
            padding: 2px;
            background-color: #f9f9f9;
        }

        .qty-btn-unified {
            width: 30px;
            height: 30px;
            padding: 0;
            border: none;
            background: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 16px;
            color: #333;
            transition: all 0.2s ease;
        }

        .qty-btn-unified:hover {
            background-color: #e0e0e0;
            color: #c91c23;
        }

        .qty-input-unified {
            width: 40px;
            text-align: center;
            border: none;
            background: none;
            font-size: 12px;
            font-weight: 700;
            padding: 4px;
        }

        .qty-input-unified::-webkit-outer-spin-button,
        .qty-input-unified::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }

        .qty-input-unified[type=number] {
            -moz-appearance: textfield;
        }

        .btn-apply-addon-unified {
            padding: 6px 12px;
            font-size: 11px;
            font-weight: 700;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .btn-addon-add {
            background-color: #007bff;
            color: white;
        }

        .btn-addon-add:hover {
            background-color: #0056b3;
        }

        .btn-addon-update {
            background-color: #28a745;
            color: white;
        }

        .btn-addon-update:hover {
            background-color: #218838;
        }

        .addon-action-buttons {
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex: 1;
        }

        .btn-remove-addon-unified {
            padding: 4px 8px;
            font-size: 10px;
            font-weight: 600;
            border-radius: 4px;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            white-space: nowrap;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 3px;
            background-color: #dc3545;
            color: white;
        }

        .btn-remove-addon-unified:hover {
            background-color: #c82333;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .unified-addons-grid {
                grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            }

            .addons-header-unified {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
                width: 100%;
            }

            .addons-header-unified .header-left {
                width: 100%;
            }

            .addons-header-unified .header-left h6 {
                font-size: 14px;
                word-break: break-word;
            }

            .addon-card-header-unified {
                flex-wrap: wrap;
            }

            .addon-controls-unified {
                flex-wrap: wrap;
            }

            .addon-action-buttons {
                flex-direction: row;
                flex: 1;
                gap: 4px;
                width: 100%;
            }

            .btn-apply-addon-unified {
                flex: 1;
                min-width: 60px;
                font-size: 12px;
                padding: 4px 6px;
            }

            .btn-remove-addon-unified {
                flex: 0 0 auto;
                padding: 4px 6px;
                font-size: 9px;
            }

            .unified-addons-container {
                padding: 10px;
            }

            .addon-item-unified {
                padding: 10px;
            }
        }

        @media (max-width: 480px) {
            .unified-addons-grid {
                grid-template-columns: 1fr;
            }

            .addon-card-header-unified {
                flex-direction: column;
            }

            .addon-controls-unified {
                width: 100%;
                justify-content: space-between;
            }

            .addon-action-buttons {
                flex-direction: row;
                gap: 4px;
                min-width: 100%;
            }

            .btn-apply-addon-unified,
            .btn-remove-addon-unified {
                font-size: 9px;
                padding: 3px 6px;
            }
        }

        /* ==========================================
                                                                               Extra KM Purchase Section Styles
                                                                               ========================================== */

        /* Service type badge */
        .service-type-badge {
            display: inline-block;
            background: #e3f2fd;
            color: #1976d2;
            padding: 4px 8px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 12px;
            margin-left: 8px;
            vertical-align: middle;
        }

        .extra-km-row {
            background-color: #f5f8ff;
            border-top: 2px solid #d0d8e8;
        }

        .extra-km-container {
            padding: 20px 0;
        }

        .extra-km-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 12px;
            border-bottom: 1px solid #ddd;
        }

        .extra-km-header h6 {
            font-size: 15px;
            font-weight: 700;
            color: #333;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .extra-km-header h6 i {
            color: #007bff;
            font-size: 18px;
        }

        .toggle-extra-km-section {
            padding: 6px 12px !important;
            font-size: 12px !important;
            white-space: nowrap;
            transition: all 0.3s ease;
        }

        .toggle-extra-km-section i {
            margin-right: 4px;
            transition: transform 0.3s ease;
        }

        .toggle-extra-km-section.collapsed i {
            transform: rotate(180deg);
        }

        .toggle-extra-km-section.collapsed {
            background-color: #e3f2fd !important;
            color: #1976d2 !important;
            border-color: #1976d2 !important;
        }

        .extra-km-content {
            transition: max-height 0.3s ease, opacity 0.3s ease, padding 0.3s ease;
            overflow: hidden;
            max-height: 500px;
            opacity: 1;
            padding: 15px 0;
        }

        .extra-km-content.collapsed {
            max-height: 0;
            opacity: 0;
            padding: 0;
            overflow: hidden;
        }

        .extra-km-form {
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            padding: 20px;
            max-width: 400px;
        }

        .extra-km-rate-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 15px;
            background: #f8f9fa;
            border-radius: 6px;
        }

        .rate-label {
            font-weight: 600;
            color: #666;
        }

        .rate-value {
            font-size: 18px;
            font-weight: 700;
            color: #007bff;
        }

        .extra-km-input-group {
            margin-top: 15px;
        }

        .extra-km-input-group label {
            display: block;
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
        }

        .km-qty-control {
            display: flex;
            align-items: center;
            gap: 2px;
            border: 2px solid #ddd;
            border-radius: 8px;
            padding: 4px;
            background-color: #f9f9f9;
            width: fit-content;
        }

        .km-qty-btn {
            width: 40px;
            height: 40px;
            padding: 0;
            border: none;
            background: #fff;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 20px;
            color: #333;
            border-radius: 6px;
            transition: all 0.2s ease;
        }

        .km-qty-btn:hover {
            background-color: #007bff;
            color: white;
        }

        .extra-km-input {
            width: 80px;
            text-align: center;
            border: none;
            background: white;
            font-size: 16px;
            font-weight: 700;
            padding: 8px;
            border-radius: 4px;
        }

        .extra-km-input::-webkit-outer-spin-button,
        .extra-km-input::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }

        .extra-km-input[type=number] {
            -moz-appearance: textfield;
        }

        .extra-km-total {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 15px;
            background: #e8f5e9;
            border-radius: 6px;
            border: 1px solid #c8e6c9;
        }

        .total-label {
            font-weight: 600;
            color: #2e7d32;
        }

        .total-value {
            font-size: 20px;
            font-weight: 700;
            color: #2e7d32;
        }

        .extra-km-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .apply-extra-km {
            flex: 1;
            min-width: 120px;
        }

        .extra-km-unavailable {
            padding: 15px;
            background: #fff3cd;
            border-radius: 8px;
            border: 1px solid #ffc107;
        }

        .extra-km-unavailable i {
            color: #856404;
        }

        @media (max-width: 768px) {
            .extra-km-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
                width: 100%;
            }

            .extra-km-header .header-left {
                width: 100%;
            }

            .extra-km-header .header-left h6 {
                font-size: 14px;
                word-break: break-word;
            }

            .extra-km-form {
                max-width: 100%;
                width: 100%;
            }

            .km-qty-control {
                width: 100%;
                justify-content: center;
            }

            .extra-km-rate-info {
                flex-direction: column;
                align-items: flex-start;
                width: 100%;
            }

            .extra-km-rate-info .rate-label {
                display: block;
                margin-bottom: 4px;
            }

            .extra-km-actions {
                flex-direction: column;
                gap: 8px;
            }

            .apply-extra-km,
            .remove-extra-km {
                width: 100%;
                font-size: 13px;
            }

            .extra-km-input-group {
                width: 100%;
            }

            .extra-km-total {
                flex-direction: column;
                align-items: flex-start;
            }
        }

        @media (max-width: 480px) {
            .extra-km-rate-info {
                font-size: 12px;
            }

            .total-value {
                font-size: 16px;
            }

            .km-qty-btn {
                width: 32px;
                height: 32px;
                font-size: 14px;
            }

            .extra-km-input {
                font-size: 12px;
            }
        }
    </style>
@endpush
