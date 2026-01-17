@extends('layouts.app')

@section('title', 'Checkout - TheTaxi')

@section('content')
    <!-- Popup Page Identifier for Popup Display Engine -->
    <div data-popup-page="checkout"></div>

    <!-- Breadcrumb section -->
    <div class="breadcrumb-section"
        style="background-image:linear-gradient(rgba(0, 0, 0, 0.3), rgba(0, 0, 0, 0.3)), url({{ asset('assets/img/innerpages/breadcrumb-bg.jpg') }});">
        <div class="container">
            <div class="banner-content">
                <h1>Checkout</h1>
                <ul class="breadcrumb-list">
                    <li><a href="{{ route('home') }}">Home</a></li>
                    <li><a href="{{ route('cart') }}">Cart</a></li>
                    <li>Checkout</li>
                </ul>
            </div>
        </div>
    </div>
    <!-- End Breadcrumb section -->

    @php
        // Cart data is passed from controller
        $cart = $cartData['items'] ?? [];
        $totals = $cartData['totals'] ?? [];
        $currencySymbol = $cartData['currency_symbol'] ?? getCurrencySymbol();

        $paymentType = request()->get('type', 'full');
        $subtotal = $totals['subtotal'] ?? 0;
        $serviceFee = $totals['service_fee'] ?? 0;
        $addonCharges = $totals['addon_charges'] ?? 0;
        $tax = $totals['tax'] ?? 0;
        $taxLabel = $totals['tax_label'] ?? 'NBT';
        $vat = $totals['vat'] ?? 0;
        $vatLabel = $totals['vat_label'] ?? 'VAT';
        $discount = $totals['coupon_discount'] ?? 0;
        $total = $totals['total'] ?? 0;

        $advancePercentage = $advancePercentage ?? 50;
        $advancePaymentEnabled = $advancePaymentEnabled ?? true;

        // Fetch settings from database
        try {
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
            \Log::error('Error fetching website settings: ' . $e->getMessage());
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

        // Payment amount based on type
        $paymentAmount = match ($paymentType) {
            'advance' => $total * ($advancePercentage / 100),
            'quotation' => 0,
            default => $total,
        };
    @endphp

    <!-- Checkout Page Start-->
    <div class="checkout-page pt-100 mb-100">
        <div class="container">
            @if (empty($cart))
                <div class="alert alert-warning text-center">
                    <h4>Your cart is empty!</h4>
                    <p>Please add some vehicles to your cart before proceeding to checkout.</p>
                    <a href="{{ route('search') }}" class="primary-btn1 mt-3">Browse Vehicles</a>
                </div>
            @else
                <form id="checkout-form" method="POST" action="{{ route('checkout.process') }}">
                    @csrf

                    <div class="row g-lg-4 gy-5">
                        <div class="col-lg-7">
                            <div class="checkout-form-wrapper">
                                <div class="checkout-form-title">
                                    <h4>Billing Information</h4>
                                </div>
                                <div class="checkout-form">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-inner two mb-25">
                                                <label>First Name*</label>
                                                <input type="text" name="first_name" placeholder="Enter your first name"
                                                    required value="{{ old('first_name') }}">
                                                @error('first_name')
                                                    <span class="text-danger">{{ $message }}</span>
                                                @enderror
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-inner two mb-25">
                                                <label>Last Name*</label>
                                                <input type="text" name="last_name" placeholder="Enter your last name"
                                                    required value="{{ old('last_name') }}">
                                                @error('last_name')
                                                    <span class="text-danger">{{ $message }}</span>
                                                @enderror
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-inner two mb-25">
                                                <label>Phone Number*</label>
                                                <input type="tel" id="phone-input" name="phone"
                                                    placeholder="+1 (201) 555-0123" autocomplete="phone" required
                                                    value="{{ old('phone') }}" style="padding-left: 48px;">
                                                <div id="phone-error" class="text-danger mt-2" style="display: none;">
                                                </div>
                                                <div id="phone-valid" class="text-success small mt-1"
                                                    style="display: none;"></div>
                                                <!-- Hidden fields for additional phone data -->
                                                <input type="hidden" id="phone-country-code" name="phone_country_code"
                                                    value="{{ old('phone_country_code') }}">
                                                <input type="hidden" id="phone-international" name="phone_international"
                                                    value="{{ old('phone_international') }}">
                                                @error('phone')
                                                    <span class="text-danger">{{ $message }}</span>
                                                @enderror
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-inner two mb-25">
                                                <label>Email Address*</label>
                                                <input type="email" name="email" placeholder="Enter email address"
                                                    required value="{{ old('email') }}">
                                                @error('email')
                                                    <span class="text-danger">{{ $message }}</span>
                                                @enderror
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-inner two mb-25">
                                                <label>Street Address*</label>
                                                <input type="text" name="address" placeholder="Enter street address"
                                                    required value="{{ old('address') }}">
                                                @error('address')
                                                    <span class="text-danger">{{ $message }}</span>
                                                @enderror
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-inner two mb-25">
                                                <label>City*</label>
                                                <input type="text" name="city" placeholder="Enter city" required
                                                    value="{{ old('city') }}">
                                                @error('city')
                                                    <span class="text-danger">{{ $message }}</span>
                                                @enderror
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-inner two mb-25">
                                                <label>Country*</label>
                                                <select id="country-select" name="country" required class="no-nice">
                                                    <option value="">Select Country</option>
                                                    @foreach ($countries as $country)
                                                        <option value="{{ $country->name }}"
                                                            {{ old('country', 'Sri Lanka') === $country->name ? 'selected' : '' }}>
                                                            {{ $country->name }}
                                                            @if ($country->callcode)
                                                                (+{{ $country->callcode }})
                                                            @endif
                                                        </option>
                                                    @endforeach
                                                </select>
                                                @error('country')
                                                    <span class="text-danger">{{ $message }}</span>
                                                @enderror
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-inner two mb-25">
                                                <label>National ID / Passport</label>
                                                <input type="text" name="identification"
                                                    placeholder="ID/Passport number (optional)"
                                                    value="{{ old('identification') }}">
                                                @error('identification')
                                                    <span class="text-danger">{{ $message }}</span>
                                                @enderror
                                            </div>
                                        </div>
                                        <div class="col-md-12">
                                            <div class="form-inner two mb-25">
                                                <label>Special Requirements</label>
                                                <textarea name="special_notes" placeholder="Any special requests or requirements...">{{ old('special_notes') }}</textarea>
                                            </div>
                                        </div>

                                        <!-- Flight Details Section -->
                                        <div class="col-md-12">
                                            <div class="form-section-divider">
                                                <h6>Flight Details (Optional)</h6>
                                                <p class="text-muted">If arriving by flight, provide details for airport
                                                    pickup</p>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-inner two mb-25">
                                                <label>Airline</label>
                                                <input type="text" name="flight_airline"
                                                    placeholder="e.g., Sri Lankan Airlines"
                                                    value="{{ old('flight_airline') }}">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-inner two mb-25">
                                                <label>Flight Number</label>
                                                <input type="text" name="flight_number" placeholder="e.g., UL123"
                                                    value="{{ old('flight_number') }}">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-inner two mb-25">
                                                <label>Arrival Date</label>
                                                <input type="date" name="flight_arrival_date"
                                                    value="{{ old('flight_arrival_date') }}">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-inner two mb-25">
                                                <label>Arrival Time</label>
                                                <input type="time" name="flight_arrival_time"
                                                    value="{{ old('flight_arrival_time') }}">
                                            </div>
                                        </div>
                                        <div class="col-md-12">
                                            <div class="form-inner two mb-25">
                                                <label>Additional Notes</label>
                                                <textarea name="additional_notes" placeholder="Any other information you'd like to share...">{{ old('additional_notes') }}</textarea>
                                            </div>
                                        </div>

                                        <!-- Dynamic Terms and Conditions grouped by service type -->
                                        @if (!empty($termsByService))
                                            <div class="col-md-12">
                                                <div class="terms-conditions-section">
                                                    <h6>Service Terms & Conditions</h6>
                                                    <div class="terms-content">
                                                        @foreach ($termsByService as $service => $terms)
                                                            <div class="terms-group mb-4">
                                                                <h6 class="service-heading mb-2">
                                                                    {{ ucfirst(str_replace('_', ' ', $service)) }}</h6>
                                                                @foreach ($terms as $tc)
                                                                    <div class="term-item mb-3">
                                                                        <div class="form-check">
                                                                            <input class="form-check-input"
                                                                                type="checkbox"
                                                                                name="terms_accepted[{{ $tc->id }}]"
                                                                                value="{{ $tc->version }}"
                                                                                id="tc_{{ $tc->id }}"
                                                                                {{ old('terms_accepted.' . $tc->id) ? 'checked' : '' }}>
                                                                            <label class="form-check-label"
                                                                                for="tc_{{ $tc->id }}">
                                                                                <strong>{{ $tc->title }}</strong>
                                                                            </label>
                                                                        </div>
                                                                        <div class="term-body mt-2">
                                                                            {!! $tc->content !!}
                                                                        </div>
                                                                    </div>
                                                                @endforeach
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                </div>
                                            </div>
                                        @endif

                                        <!-- Payment Type Terms -->
                                        @if (!empty($termsByPaymentType))
                                            @php
                                                $paymentTypeLabels = [
                                                    'full' => 'Full Payment',
                                                    'advance' => 'Advance Payment',
                                                    'checkin' => 'Pay on Check-in',
                                                    'quotation' => 'Quotation Request',
                                                ];
                                            @endphp
                                            <div class="col-md-12">
                                                <div class="terms-conditions-section payment-terms-section">
                                                    <h6>Payment Terms & Conditions</h6>
                                                    <div class="terms-content">
                                                        @foreach ($termsByPaymentType as $type => $terms)
                                                            <div class="terms-group mb-4 payment-terms-group"
                                                                data-payment-type="{{ $type }}"
                                                                style="{{ $paymentType === $type ? '' : 'display:none;' }}">
                                                                <h6 class="service-heading mb-2">
                                                                    {{ $paymentTypeLabels[$type] ?? ucfirst($type) }}</h6>
                                                                @foreach ($terms as $tc)
                                                                    <div class="term-item mb-3">
                                                                        <div class="form-check">
                                                                            <input class="form-check-input"
                                                                                type="checkbox"
                                                                                name="terms_accepted[{{ $tc->id }}]"
                                                                                value="{{ $tc->version }}"
                                                                                id="tc_{{ $tc->id }}"
                                                                                {{ old('terms_accepted.' . $tc->id) ? 'checked' : '' }}>
                                                                            <label class="form-check-label"
                                                                                for="tc_{{ $tc->id }}">
                                                                                <strong>{{ $tc->title }}</strong>
                                                                            </label>
                                                                        </div>
                                                                        <div class="term-body mt-2">
                                                                            {!! $tc->content !!}
                                                                        </div>
                                                                    </div>
                                                                @endforeach
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                </div>
                                            </div>
                                        @endif

                                        <div class="col-md-12">
                                            <div class="form-inner2">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="save_info"
                                                        value="1" id="saveInfo"
                                                        {{ old('save_info') ? 'checked' : '' }}>
                                                    <label class="form-check-label" for="saveInfo">
                                                        Save my information for future bookings
                                                    </label>
                                                </div>
                                            </div>
                                        </div>

                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-5">
                            <div class="checkout-form-wrapper">
                                <div class="checkout-form-title">
                                    <h4>Order Summary</h4>
                                </div>
                                <div class="order-sum-area">
                                    <div class="cart-menu">
                                        <div class="cart-body">
                                            <ul>
                                                @foreach ($cart as $key => $item)
                                                    @php
                                                        // Calculate days from pickup and return dates - day-based calculation
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
                                                    <li class="single-item">
                                                        <div class="item-area">
                                                            <div class="main-item">
                                                                <div class="item-img">
                                                                    @if (isset($item['image']) && $item['image'])
                                                                        <img src="{{ s3_asset($item['image']) }}"
                                                                            alt="{{ $item['name'] ?? 'Vehicle' }}">
                                                                    @else
                                                                        <img src="{{ asset('assets/img/innerpages/cart-img1.png') }}"
                                                                            alt="{{ $item['name'] ?? 'Vehicle' }}">
                                                                    @endif
                                                                </div>
                                                                <div class="content-and-quantity">
                                                                    <div class="content">
                                                                        <span>{{ $currencySymbol }}
                                                                            {{ number_format($item['price'] ?? 0, 2) }}/day
                                                                            × {{ $calculatedDays }}
                                                                            day{{ $calculatedDays !== 1 ? 's' : '' }}</span>
                                                                        <h6>
                                                                            <a
                                                                                href="#">{{ $item['name'] ?? '' }}</a>
                                                                            <span
                                                                                class="service-type-badge">{{ $item['service_type_data']['name'] ?? ($item['service_type'] ?? 'Service') }}</span>
                                                                        </h6>
                                                                        <p><small>{{ $pickupDate ? $pickupDate->format('M d') : '' }}
                                                                                -
                                                                                {{ $returnDate ? $returnDate->format('M d, Y') : '' }}</small>
                                                                        </p>

                                                                        @php
                                                                            // Distance details for km information display
                                                                            $distanceDetails =
                                                                                $item['distance_details'] ?? [];

                                                                            // If distance_details is empty, try to get from service package info or calculate
                                                                            if (
                                                                                empty($distanceDetails) &&
                                                                                isset($item['service_package_info'])
                                                                            ) {
                                                                                $servicePackageInfo =
                                                                                    $item['service_package_info'];
                                                                                // Build fallback distance_details from service package
                                                                                $distanceDetails = [
                                                                                    'allowed_total_km' => isset(
                                                                                        $servicePackageInfo[
                                                                                            'max_km_per_day'
                                                                                        ],
                                                                                    )
                                                                                        ? $servicePackageInfo[
                                                                                                'max_km_per_day'
                                                                                            ] * $calculatedDays
                                                                                        : null,
                                                                                    'free_km_per_day' =>
                                                                                        $servicePackageInfo[
                                                                                            'max_km_per_day'
                                                                                        ] ?? null,
                                                                                    'extra_km_price' => null, // Will be fetched from service separately
                                                                                    'free_km_per_package' =>
                                                                                        $servicePackageInfo[
                                                                                            'max_km_per_package'
                                                                                        ] ?? null,
                                                                                ];
                                                                            }

                                                                            $allowedTotalKm =
                                                                                $distanceDetails['allowed_total_km'] ??
                                                                                null;
                                                                            $extraKmPrice =
                                                                                $distanceDetails['extra_km_price'] ??
                                                                                null;
                                                                            $freeKmPerDay =
                                                                                $distanceDetails['free_km_per_day'] ??
                                                                                null;
                                                                        @endphp

                                                                        @if ($allowedTotalKm || $freeKmPerDay)
                                                                            <p><small style="color: #0066cc;">
                                                                                    <i class="bi bi-speedometer2"></i>
                                                                                    @if ($freeKmPerDay && $calculatedDays > 1)
                                                                                        {{ number_format($freeKmPerDay, 0) }}
                                                                                        km/day
                                                                                        ({{ number_format($allowedTotalKm ?? $freeKmPerDay * $calculatedDays, 0) }}
                                                                                        km total)
                                                                                    @elseif($allowedTotalKm)
                                                                                        {{ number_format($allowedTotalKm, 0) }}
                                                                                        km included
                                                                                    @else
                                                                                        {{ number_format($freeKmPerDay, 0) }}
                                                                                        km included
                                                                                    @endif

                                                                                    @if ($extraKmPrice)
                                                                                        <span style="color: #999;"> |
                                                                                            Extra:
                                                                                            {{ $currencySymbol }}{{ number_format($extraKmPrice, 2) }}/km</span>
                                                                                    @endif
                                                                                </small></p>
                                                                        @endif
                                                                        @php
                                                                            $pickupLoc = is_array(
                                                                                $item['pickup_location'] ?? null,
                                                                            )
                                                                                ? $item['pickup_location']['address'] ??
                                                                                    ''
                                                                                : $item['pickup_location'] ?? '';
                                                                            $dropoffLoc = is_array(
                                                                                $item['dropoff_location'] ?? null,
                                                                            )
                                                                                ? $item['dropoff_location'][
                                                                                        'address'
                                                                                    ] ?? ''
                                                                                : $item['dropoff_location'] ?? '';
                                                                            if (
                                                                                ($item['service_type'] ?? '') ===
                                                                                'airport_transfers'
                                                                            ) {
                                                                                $pickupLoc =
                                                                                    $pickupLoc ?:
                                                                                    $item['pickup_airport'] ??
                                                                                        ($item['flight_details'][
                                                                                            'arrival_airport'
                                                                                        ] ??
                                                                                            '');
                                                                                $dropoffLoc =
                                                                                    $dropoffLoc ?:
                                                                                    $item['dropoff_airport'] ??
                                                                                        ($item['flight_details'][
                                                                                            'departure_airport'
                                                                                        ] ??
                                                                                            '');
                                                                            }
                                                                        @endphp
                                                                        <p><small><i class="bi bi-geo-alt"></i>
                                                                                {{ $pickupLoc ?: 'N/A' }}</small></p>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            <div class="item-total">
                                                                {{ $currencySymbol }} {{ number_format($itemTotal, 2) }}
                                                            </div>
                                                        </div>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </div>

                                        <div class="cart-footer">
                                            <div class="pricing-area mb-40">
                                                <ul>
                                                    <li>
                                                        <strong>Subtotal</strong>
                                                        <strong>{{ $currencySymbol }}
                                                            {{ number_format($subtotal, 2) }}</strong>
                                                    </li>
                                                    @php
                                                        $addonCharges = $totals['addon_charges'] ?? 0;
                                                        $extraKmCharges = $totals['extra_km_charges'] ?? 0;
                                                    @endphp
                                                    @if ($addonCharges > 0)
                                                        <li>
                                                            Addon Charges
                                                            <div class="order-info text-success">
                                                                <span>{{ $currencySymbol }}
                                                                    {{ number_format($addonCharges, 2) }}</span>
                                                            </div>
                                                        </li>
                                                    @endif
                                                    @if ($extraKmCharges > 0)
                                                        <li>
                                                            Extra KM Charges
                                                            <div class="order-info text-info">
                                                                <span>{{ $currencySymbol }}
                                                                    {{ number_format($extraKmCharges, 2) }}</span>
                                                            </div>
                                                        </li>
                                                    @endif
                                                    @if ($serviceFee > 0)
                                                        <li>
                                                            Service Fee
                                                            <div class="order-info">
                                                                <span>{{ $currencySymbol }}
                                                                    {{ number_format($serviceFee, 2) }}</span>
                                                            </div>
                                                        </li>
                                                    @endif
                                                    @if ($tax > 0)
                                                        <li>
                                                            {{ $taxLabel }}
                                                            ({{ $taxPercentageLabel }}%)
                                                            <div class="order-info">
                                                                <span>{{ $currencySymbol }}
                                                                    {{ number_format($tax, 2) }}</span>
                                                            </div>
                                                        </li>
                                                    @endif
                                                    @if ($vatPercentage > 0 && $vat > 0)
                                                        <li>
                                                            {{ $vatLabel }}
                                                            ({{ $vatPercentageLabel }}%)
                                                            <div class="order-info">
                                                                <span>{{ $currencySymbol }}
                                                                    {{ number_format($vat, 2) }}</span>
                                                            </div>
                                                        </li>
                                                    @endif

                                                    {{-- Promo Code Section --}}
                                                    <li class="promo-code-checkout-section">
                                                        <div class="promo-code-checkout-wrapper">
                                                            <div class="promo-code-header">
                                                                <i class="bi bi-tag"></i>
                                                                <span>Promo Code</span>
                                                            </div>
                                                            @php
                                                                $appliedPromoCode = $cartData['coupon_code'] ?? null;
                                                                $promoDiscount = $cartData['coupon_discount'] ?? 0;
                                                            @endphp
                                                            @if ($appliedPromoCode)
                                                                {{-- Promo code is applied --}}
                                                                <div class="applied-promo-checkout">
                                                                    <div class="promo-badge-checkout">
                                                                        <i
                                                                            class="bi bi-check-circle-fill text-success"></i>
                                                                        <span
                                                                            class="promo-code-value">{{ $appliedPromoCode }}</span>
                                                                        <button type="button"
                                                                            class="remove-promo-checkout-btn"
                                                                            title="Remove promo code">
                                                                            <i class="bi bi-x-lg"></i>
                                                                        </button>
                                                                    </div>
                                                                </div>
                                                            @else
                                                                {{-- No promo code - show input --}}
                                                                <div class="promo-input-checkout">
                                                                    <input type="text" id="checkout-promo-input"
                                                                        placeholder="Enter code" autocomplete="off">
                                                                    <button type="button" id="apply-promo-checkout-btn"
                                                                        class="apply-promo-checkout-btn">
                                                                        <span class="btn-text">Apply</span>
                                                                        <span class="btn-loading"
                                                                            style="display: none;"><i
                                                                                class="bi bi-hourglass-split"></i></span>
                                                                    </button>
                                                                </div>
                                                            @endif
                                                            <div id="checkout-promo-message"
                                                                class="promo-message-checkout"></div>
                                                        </div>
                                                    </li>

                                                    @if ($discount > 0)
                                                        <li class="discount-checkout-row">
                                                            <strong class="text-success"><i class="bi bi-tag-fill"></i>
                                                                Discount</strong>
                                                            <div class="order-info text-success">
                                                                <span>-{{ $currencySymbol }}
                                                                    {{ number_format($discount, 2) }}</span>
                                                            </div>
                                                        </li>
                                                    @endif
                                                    <li class="total-row">
                                                        <strong>Total</strong>
                                                        <strong>{{ $currencySymbol }}
                                                            {{ number_format($total, 2) }}</strong>
                                                    </li>
                                                    @if ($paymentType !== 'full')
                                                        <li class="payment-amount-row">
                                                            <strong>
                                                                @if ($paymentType === 'advance')
                                                                    Amount to Pay
                                                                    ({{ $advancePercentage }}%)
                                                                @elseif($paymentType === 'checkin')
                                                                    Pay on Check-in
                                                                @elseif($paymentType === 'quotation')
                                                                    Quotation Request
                                                                @endif
                                                            </strong>
                                                            <strong class="text-primary">
                                                                @if ($paymentType === 'quotation')
                                                                    No Payment Required
                                                                @elseif($paymentType === 'checkin')
                                                                    {{ $currencySymbol }}
                                                                    {{ number_format($total, 2) }}
                                                                @else
                                                                    {{ $currencySymbol }}
                                                                    {{ number_format($paymentAmount, 2) }}
                                                                @endif
                                                            </strong>
                                                        </li>
                                                    @endif
                                                </ul>
                                            </div>

                                            <!-- Payment Type Selection Section -->
                                            <div class="payment-type-selection mb-4">
                                                <div class="card">
                                                    <div class="card-header">
                                                        <h6 class="mb-0"><i class="bi bi-credit-card"></i> Payment
                                                            Option
                                                        </h6>
                                                    </div>
                                                    <div class="card-body">
                                                        <div class="row g-3">
                                                            <div class="col-6">
                                                                <div class="payment-option">
                                                                    <input type="radio" name="payment_type"
                                                                        value="full" id="payment_full"
                                                                        {{ $paymentType === 'full' ? 'checked' : '' }}
                                                                        class="payment-radio">
                                                                    <label for="payment_full" class="payment-label">
                                                                        <div class="payment-card">
                                                                            <i
                                                                                class="bi bi-credit-card-fill text-success"></i>
                                                                            <h6>Pay Full Amount</h6>
                                                                            <p class="mb-0">Complete payment now</p>
                                                                            <small class="text-muted">Total:
                                                                                {{ $currencySymbol }}
                                                                                {{ number_format($total, 2) }}</small>
                                                                        </div>
                                                                    </label>
                                                                </div>
                                                            </div>
                                                            @if ($advancePaymentEnabled)
                                                                <div class="col-6">
                                                                    <div class="payment-option">
                                                                        <input type="radio" name="payment_type"
                                                                            value="advance" id="payment_advance"
                                                                            {{ $paymentType === 'advance' ? 'checked' : '' }}
                                                                            class="payment-radio">
                                                                        <label for="payment_advance"
                                                                            class="payment-label">
                                                                            <div class="payment-card">
                                                                                <i
                                                                                    class="bi bi-credit-card text-warning"></i>
                                                                                <h6>Pay {{ $advancePercentage }}% Advance
                                                                                </h6>
                                                                                <p class="mb-0">Pay remaining on pickup
                                                                                </p>
                                                                                <small class="text-muted">Now:
                                                                                    {{ $currencySymbol }}
                                                                                    {{ number_format($total * ($advancePercentage / 100), 2) }}</small>
                                                                            </div>
                                                                        </label>
                                                                    </div>
                                                                </div>
                                                            @endif
                                                            @if ($offlinePaymentEnabled)
                                                                <div class="col-6">
                                                                    <div class="payment-option">
                                                                        <input type="radio" name="payment_type"
                                                                            value="checkin" id="payment_checkin"
                                                                            {{ $paymentType === 'checkin' ? 'checked' : '' }}
                                                                            class="payment-radio">
                                                                        <label for="payment_checkin"
                                                                            class="payment-label">
                                                                            <div class="payment-card">
                                                                                <i
                                                                                    class="bi bi-cash-coin text-primary"></i>
                                                                                <h6>Pay on Check-in</h6>
                                                                                <p class="mb-0">Pay when you collect</p>
                                                                                <small class="text-muted">Due:
                                                                                    {{ $currencySymbol }}
                                                                                    {{ number_format($total, 2) }}</small>
                                                                            </div>
                                                                        </label>
                                                                    </div>
                                                                </div>
                                                            @endif
                                                            <div class="col-6">
                                                                <div class="payment-option">
                                                                    <input type="radio" name="payment_type"
                                                                        value="quotation" id="payment_quotation"
                                                                        {{ $paymentType === 'quotation' ? 'checked' : '' }}
                                                                        class="payment-radio">
                                                                    <label for="payment_quotation" class="payment-label">
                                                                        <div class="payment-card">
                                                                            <i class="bi bi-file-text text-info"></i>
                                                                            <h6>Request Quotation</h6>
                                                                            <p class="mb-0">Get detailed pricing</p>
                                                                            <small class="text-muted">No payment
                                                                                now</small>
                                                                        </div>
                                                                    </label>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Payment Type Alert -->
                                            <div class="alert alert-info mb-4" id="payment-type-alert">
                                                <div id="alert-content">
                                                    @switch($paymentType)
                                                        @case('advance')
                                                            <h6><i class="bi bi-info-circle"></i> Advance Payment
                                                                ({{ $advancePercentage }}%)</h6>
                                                            <p class="mb-0">You are paying {{ $advancePercentage }}%
                                                                advance. The remaining amount will be collected at the time
                                                                of vehicle pickup.</p>
                                                        @break

                                                        @case('checkin')
                                                            <h6><i class="bi bi-cash-coin"></i> Pay on Check-in</h6>
                                                            <p class="mb-0">No payment is required now. You will pay the full
                                                                amount when you check-in to collect the vehicle.</p>
                                                        @break

                                                        @case('quotation')
                                                            <h6><i class="bi bi-file-text"></i> Request Quotation</h6>
                                                            <p class="mb-0">You are requesting a quotation. Our team will
                                                                contact you with detailed pricing and booking information.</p>
                                                        @break

                                                        @default
                                                            <h6><i class="bi bi-credit-card"></i> Full Payment</h6>
                                                            <p class="mb-0">You are making full payment for your booking.</p>
                                                    @endswitch
                                                </div>
                                            </div>


                                            <button type="submit" class="primary-btn1 w-100" id="checkout-submit-btn">
                                                <span>
                                                    @if ($paymentType === 'quotation')
                                                        Submit Quotation Request
                                                    @elseif ($paymentType === 'checkin')
                                                        Confirm Booking - Pay on Check-in
                                                    @else
                                                        Complete Booking -
                                                        {{ $currencySymbol }} {{ number_format($paymentAmount, 2) }}
                                                    @endif
                                                    <svg width="10" height="10" viewBox="0 0 10 10"
                                                        xmlns="http://www.w3.org/2000/svg">
                                                        <path
                                                            d="M9.73535 1.14746C9.57033 1.97255 9.32924 3.26406 9.24902 4.66797C9.16817 6.08312 9.25559 7.5453 9.70214 8.73633C9.84754 9.12406 9.65129 9.55659 9.26367 9.70215C8.9001 9.83849 8.4969 9.67455 8.32812 9.33398L8.29785 9.26367L8.19921 8.98438C7.73487 7.5758 7.67054 5.98959 7.75097 4.58203C7.77875 4.09598 7.82525 3.62422 7.87988 3.17969L1.53027 9.53027C1.23738 9.82317 0.762615 9.82317 0.469722 9.53027C0.176829 9.23738 0.176829 8.76262 0.469722 8.46973L6.83593 2.10254C6.3319 2.16472 5.79596 2.21841 5.25 2.24902C3.8302 2.32862 2.2474 2.26906 0.958003 1.79102L0.704097 1.68945L0.635738 1.65527C0.303274 1.47099 0.157578 1.06102 0.310542 0.704102C0.463655 0.347333 0.860941 0.170391 1.22363 0.28418L1.29589 0.310547L1.48828 0.387695C2.47399 0.751207 3.79966 0.827571 5.16601 0.750977C6.60111 0.670504 7.97842 0.428235 8.86132 0.262695L9.95312 0.0585938L9.73535 1.14746Z">
                                                        </path>
                                                    </svg>
                                                </span>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            @endif
        </div>
    </div>
    <!--Checkout Page End-->
@endsection

@push('styles')
    <style>
        /* Payment type selection styles */
        .payment-type-selection .payment-option {
            position: relative;
        }

        .payment-radio {
            display: none;
        }

        .payment-label {
            cursor: pointer;
            display: block;
            margin: 0;
        }

        .payment-card {
            border: 2px solid #e9ecef;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            transition: all 0.3s ease;
            background: white;
        }

        .payment-card:hover {
            border-color: var(--primary-color1);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .payment-radio:checked+.payment-label .payment-card {
            border-color: var(--primary-color1);
            background: rgba(201, 28, 35, 0.05);
        }

        .payment-card i {
            font-size: 2rem;
            margin-bottom: 10px;
            display: block;
        }

        .payment-card h6 {
            margin: 10px 0 5px 0;
            font-weight: 600;
            color: #333;
        }

        .payment-card p {
            font-size: 14px;
            color: #666;
            margin-bottom: 5px;
        }

        .payment-card small {
            font-weight: 500;
        }

        /* Existing payment option styles */
        .payment-option ul {
            display: flex;
            gap: 15px;
            list-style: none;
            padding: 0;
            margin: 15px 0;
        }

        .payment-option li {
            flex: 1;
            position: relative;
            border: 2px solid #ddd;
            border-radius: 8px;
            overflow: hidden;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .payment-option li:hover {
            border-color: var(--primary-color1);
        }

        .payment-option li.active {
            border-color: var(--primary-color1);
            background-color: #f8f9fa;
        }

        .payment-option li input[type="radio"] {
            display: none;
        }

        .payment-option li label {
            display: block;
            padding: 15px;
            text-align: center;
            cursor: pointer;
            margin: 0;
        }

        .payment-option li label img {
            max-height: 30px;
            max-width: 100%;
        }

        .payment-option li .checked {
            position: absolute;
            top: 5px;
            right: 5px;
            background: var(--primary-color1);
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .payment-option li.active .checked {
            opacity: 1;
        }

        .cart-footer .pricing-area ul li {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #eee;
        }

        .cart-footer .pricing-area ul li.total-row {
            border-top: 2px solid #ddd;
            margin-top: 10px;
            padding-top: 15px;
            font-size: 18px;
        }

        .cart-footer .pricing-area ul li.payment-amount-row {
            background: #f8f9fa;
            padding: 15px;
            margin: 15px -20px 0;
            border-radius: 8px;
            border: none;
        }

        .single-item .item-area {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px 0;
            border-bottom: 1px solid #eee;
        }

        .single-item .main-item {
            display: flex;
            align-items: flex-start;
            gap: 15px;
            flex: 1;
        }

        .single-item .item-img {
            width: 60px;
            height: 60px;
            border-radius: 8px;
            overflow: hidden;
            flex-shrink: 0;
        }

        .single-item .item-img img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .single-item .content h6 {
            margin-bottom: 5px;
            font-size: 14px;
        }

        .single-item .content span {
            font-size: 12px;
            color: #666;
            font-weight: 600;
        }

        .single-item .content p {
            margin: 2px 0;
            font-size: 12px;
            color: #888;
        }

        .single-item .item-total {
            font-weight: 600;
            color: var(--primary-color1);
            text-align: right;
        }

        .form-inner.two {
            margin-bottom: 20px;
        }

        .form-inner.two label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }

        .form-inner.two input,
        .form-inner.two textarea,
        .form-inner.two select {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
            transition: border-color 0.3s ease;
        }

        .form-inner.two input:focus,
        .form-inner.two textarea:focus,
        .form-inner.two select:focus {
            outline: none;
            border-color: var(--primary-color1);
        }

        .form-inner.two textarea {
            min-height: 100px;
            resize: vertical;
        }

        .form-check {
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }

        .form-check-input {
            margin-top: 3px;
        }

        .form-check-label {
            font-size: 14px;
            line-height: 1.4;
        }

        /* Promo Code Checkout Section Styles */
        .promo-code-checkout-section {
            flex-direction: column !important;
            align-items: stretch !important;
            padding: 15px 0 !important;
        }

        .promo-code-checkout-wrapper {
            width: 100%;
        }

        .promo-code-header {
            display: flex;
            align-items: center;
            gap: 6px;
            font-weight: 600;
            color: #333;
            margin-bottom: 10px;
        }

        .promo-code-header i {
            color: var(--primary-color1);
        }

        .promo-input-checkout {
            display: flex;
            gap: 0;
        }

        .promo-input-checkout input {
            flex: 1;
            padding: 8px 12px;
            border: 2px solid #ddd;
            border-radius: 6px 0 0 6px;
            font-size: 13px;
            transition: border-color 0.3s ease;
        }

        .promo-input-checkout input:focus {
            outline: none;
            border-color: var(--primary-color1);
        }

        .apply-promo-checkout-btn {
            padding: 8px 16px;
            background: var(--primary-color1);
            color: white;
            border: 2px solid var(--primary-color1);
            border-radius: 0 6px 6px 0;
            cursor: pointer;
            font-weight: 600;
            font-size: 13px;
            transition: all 0.3s ease;
            min-width: 70px;
        }

        .apply-promo-checkout-btn:hover:not(:disabled) {
            background: #a81820;
            border-color: #a81820;
        }

        .apply-promo-checkout-btn:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }

        .applied-promo-checkout {
            margin-top: 0;
        }

        .promo-badge-checkout {
            display: flex;
            align-items: center;
            gap: 8px;
            background: #e8f5e9;
            border: 1px solid #c8e6c9;
            border-radius: 6px;
            padding: 8px 12px;
        }

        .promo-badge-checkout i.text-success {
            font-size: 16px;
        }

        .promo-code-value {
            font-weight: 700;
            color: #2e7d32;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            flex: 1;
        }

        .remove-promo-checkout-btn {
            background: none;
            border: none;
            color: #dc3545;
            cursor: pointer;
            padding: 2px 6px;
            border-radius: 4px;
            transition: all 0.2s ease;
            font-size: 12px;
        }

        .remove-promo-checkout-btn:hover {
            background: #ffebee;
            color: #c62828;
        }

        .promo-message-checkout {
            margin-top: 8px;
        }

        .promo-message-checkout .alert {
            font-size: 12px;
            padding: 6px 10px;
            margin-bottom: 0;
            border-radius: 4px;
        }

        .discount-checkout-row {
            background: #f1f8e9;
            margin: 0 -20px;
            padding: 12px 20px !important;
            border-radius: 0;
        }

        .discount-checkout-row strong {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .alert-info {
            background-color: #d1ecf1;
            border-color: #bee5eb;
            color: #0c5460;
        }

        .alert-warning {
            background-color: #fff3cd;
            border-color: #ffeaa7;
            color: #856404;
        }

        .text-danger {
            color: #dc3545 !important;
            font-size: 12px;
            margin-top: 5px;
            display: block;
        }

        .form-section-divider {
            padding: 20px 0 15px 0;
            border-top: 2px solid #eee;
            margin-bottom: 15px;
        }

        .form-section-divider h6 {
            margin-bottom: 5px;
            color: #333;
            font-weight: 600;
        }

        .form-section-divider .text-muted {
            font-size: 13px;
            color: #999;
        }

        .terms-conditions-section {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .terms-conditions-section h6 {
            margin-bottom: 15px;
            color: #333;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 14px;
        }

        .terms-content {
            max-height: 400px;
            overflow-y: auto;
            padding-right: 10px;
        }

        .term-item {
            margin-bottom: 20px;
        }

        .term-item:last-child {
            margin-bottom: 0;
        }

        .term-title {
            color: var(--primary-color1);
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .term-body {
            font-size: 13px;
            line-height: 1.6;
            color: #555;
        }

        .term-body p {
            margin-bottom: 10px;
        }

        .term-body ul,
        .term-body ol {
            margin-left: 20px;
            margin-bottom: 10px;
        }

        .term-body li {
            margin-bottom: 5px;
        }

        @media (max-width: 768px) {
            .payment-option ul {
                flex-direction: column;
                gap: 10px;
            }

            .payment-option li label {
                padding: 12px;
            }

            .single-item .main-item {
                flex-direction: column;
                text-align: center;
            }

            .terms-content {
                max-height: 250px;
            }
        }

        /* International Phone Input Styles */
        .iti {
            width: 100%;
        }

        .iti__flag-container {
            background-color: #f8f9fa;
            border-right: 1px solid #dee2e6;
        }

        .iti__flag {
            background-image: url('https://cdn.jsdelivr.net/npm/intl-tel-input@17/build/img/flags.png');
        }

        #phone-input {
            width: 100% !important;
            border: 1px solid #dee2e6;
            border-radius: 4px;
        }

        #phone-input:focus {
            border-color: #80bdff;
            outline: 0;
            box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, .25);
        }

        .iti__dropdown-content {
            max-height: 200px;
            overflow-y: auto;
        }

        /* Select2 custom styling to match form inputs */
        .select2-container--default .select2-selection--single {
            min-height: 44px;
            border: 1px solid #ddd;
            border-radius: 5px;
            background: #fff;
            font-size: 14px;
            display: flex;
            align-items: center;
            padding: 0;
        }

        .select2-container--default .select2-selection--single .select2-selection__rendered {
            color: #333;
            padding-left: 12px;
            padding-right: 20px;
            line-height: 1.2;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .select2-container--default .select2-selection--single .select2-selection__placeholder {
            color: #6c757d;
            padding-left: 12px;
        }

        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 100%;
            right: 10px;
            top: 0;
            display: flex;
            align-items: center;
        }

        .select2-container--default.select2-container--open .select2-selection--single {
            border-color: var(--primary-color1);
            box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, .15);
        }

        .select2-dropdown {
            border: 1px solid #e1e5e9;
            border-radius: 5px;
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
        }

        .select2-container--default .select2-results__option {
            padding: 8px 12px;
            font-size: 14px;
        }

        .select2-container--default .select2-results__option--highlighted[aria-selected] {
            background-color: var(--primary-color1);
            color: white;
        }
    </style>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/intl-tel-input@17/build/css/intlTelInput.css">
    <!-- Select2 CSS for searchable country dropdown -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
@endpush

@push('scripts')
    <script>
        $(document).ready(function() {
            // Get PHP variables from blade
            const currencySymbol = '{{ $currencySymbol }}';
            const total = {{ $total }};
            const advancePercentage = {{ $advancePercentage }};
            const paymentTermsGroups = $('.payment-terms-group');

            // Initialize Select2 for country dropdown
            $('#country-select').select2({
                placeholder: 'Select Country',
                allowClear: true,
                width: '100%'
            });

            function updatePaymentTerms(type) {
                if (!paymentTermsGroups.length) {
                    return;
                }
                paymentTermsGroups.hide();
                const match = paymentTermsGroups.filter(`[data-payment-type="${type}"]`);
                if (match.length) {
                    match.show();
                }
            }

            // Payment type selection handling
            $('input[name="payment_type"]').on('change', function() {
                const paymentType = $(this).val();
                const alertContent = $('#alert-content');
                const submitBtn = $('#checkout-submit-btn span');

                // Calculate payment amounts
                const advanceAmount = total * (advancePercentage / 100);
                const fullAmount = total;

                // Update the alert content based on payment type
                switch (paymentType) {
                    case 'advance':
                        alertContent.html(`
                    <h6><i class="bi bi-info-circle"></i> Advance Payment (${advancePercentage}%)</h6>
                    <p class="mb-0">You are paying ${advancePercentage}% advance. The remaining amount will be collected at the time of vehicle pickup.</p>
                `);
                        submitBtn.html(
                            `Complete Booking - ${currencySymbol}${advanceAmount.toFixed(2)} <svg width="10" height="10" viewBox="0 0 10 10" xmlns="http://www.w3.org/2000/svg"><path d="M9.73535 1.14746C9.57033 1.97255 9.32924 3.26406 9.24902 4.66797C9.16817 6.08312 9.25559 7.5453 9.70214 8.73633C9.84754 9.12406 9.65129 9.55659 9.26367 9.70215C8.9001 9.83849 8.4969 9.67455 8.32812 9.33398L8.29785 9.26367L8.19921 8.98438C7.73487 7.5758 7.67054 5.98959 7.75097 4.58203C7.77875 4.09598 7.82525 3.62422 7.87988 3.17969L1.53027 9.53027C1.23738 9.82317 0.762615 9.82317 0.469722 9.53027C0.176829 9.23738 0.176829 8.76262 0.469722 8.46973L6.83593 2.10254C6.3319 2.16472 5.79596 2.21841 5.25 2.24902C3.8302 2.32862 2.2474 2.26906 0.958003 1.79102L0.704097 1.68945L0.635738 1.65527C0.303274 1.47099 0.157578 1.06102 0.310542 0.704102C0.463655 0.347333 0.860941 0.170391 1.22363 0.28418L1.29589 0.310547L1.48828 0.387695C2.47399 0.751207 3.79966 0.827571 5.16601 0.750977C6.60111 0.670504 7.97842 0.428235 8.86132 0.262695L9.95312 0.0585938L9.73535 1.14746Z"></path></svg>`
                        );
                        break;
                    case 'quotation':
                        alertContent.html(`
                    <h6><i class="bi bi-file-text"></i> Request Quotation</h6>
                    <p class="mb-0">You are requesting a quotation. Our team will contact you with detailed pricing and booking information.</p>
                `);
                        submitBtn.html(
                            'Submit Quotation Request <svg width="10" height="10" viewBox="0 0 10 10" xmlns="http://www.w3.org/2000/svg"><path d="M9.73535 1.14746C9.57033 1.97255 9.32924 3.26406 9.24902 4.66797C9.16817 6.08312 9.25559 7.5453 9.70214 8.73633C9.84754 9.12406 9.65129 9.55659 9.26367 9.70215C8.9001 9.83849 8.4969 9.67455 8.32812 9.33398L8.29785 9.26367L8.19921 8.98438C7.73487 7.5758 7.67054 5.98959 7.75097 4.58203C7.77875 4.09598 7.82525 3.62422 7.87988 3.17969L1.53027 9.53027C1.23738 9.82317 0.762615 9.82317 0.469722 9.53027C0.176829 9.23738 0.176829 8.76262 0.469722 8.46973L6.83593 2.10254C6.3319 2.16472 5.79596 2.21841 5.25 2.24902C3.8302 2.32862 2.2474 2.26906 0.958003 1.79102L0.704097 1.68945L0.635738 1.65527C0.303274 1.47099 0.157578 1.06102 0.310542 0.704102C0.463655 0.347333 0.860941 0.170391 1.22363 0.28418L1.29589 0.310547L1.48828 0.387695C2.47399 0.751207 3.79966 0.827571 5.16601 0.750977C6.60111 0.670504 7.97842 0.428235 8.86132 0.262695L9.95312 0.0585938L9.73535 1.14746Z"></path></svg>'
                        );
                        break;
                    case 'checkin':
                        alertContent.html(`
                    <h6><i class="bi bi-cash-coin"></i> Pay on Check-in</h6>
                    <p class="mb-0">No payment is required now. You will pay the full amount when you check-in to collect the vehicle.</p>
                `);
                        submitBtn.html(
                            `Confirm Booking - Pay on Check-in <svg width="10" height="10" viewBox="0 0 10 10" xmlns="http://www.w3.org/2000/svg"><path d="M9.73535 1.14746C9.57033 1.97255 9.32924 3.26406 9.24902 4.66797C9.16817 6.08312 9.25559 7.5453 9.70214 8.73633C9.84754 9.12406 9.65129 9.55659 9.26367 9.70215C8.9001 9.83849 8.4969 9.67455 8.32812 9.33398L8.29785 9.26367L8.19921 8.98438C7.73487 7.5758 7.67054 5.98959 7.75097 4.58203C7.77875 4.09598 7.82525 3.62422 7.87988 3.17969L1.53027 9.53027C1.23738 9.82317 0.762615 9.82317 0.469722 9.53027C0.176829 9.23738 0.176829 8.76262 0.469722 8.46973L6.83593 2.10254C6.3319 2.16472 5.79596 2.21841 5.25 2.24902C3.8302 2.32862 2.2474 2.26906 0.958003 1.79102L0.704097 1.68945L0.635738 1.65527C0.303274 1.47099 0.157578 1.06102 0.310542 0.704102C0.463655 0.347333 0.860941 0.170391 1.22363 0.28418L1.29589 0.310547L1.48828 0.387695C2.47399 0.751207 3.79966 0.827571 5.16601 0.750977C6.60111 0.670504 7.97842 0.428235 8.86132 0.262695L9.95312 0.0585938L9.73535 1.14746Z"></path></svg>`
                        );
                        break;
                    default:
                        alertContent.html(`
                    <h6><i class="bi bi-credit-card"></i> Full Payment</h6>
                    <p class="mb-0">You are making full payment for your booking.</p>
                `);
                        submitBtn.html(
                            `Complete Booking - ${currencySymbol}${fullAmount.toFixed(2)} <svg width="10" height="10" viewBox="0 0 10 10" xmlns="http://www.w3.org/2000/svg"><path d="M9.73535 1.14746C9.57033 1.97255 9.32924 3.26406 9.24902 4.66797C9.16817 6.08312 9.25559 7.5453 9.70214 8.73633C9.84754 9.12406 9.65129 9.55659 9.26367 9.70215C8.9001 9.83849 8.4969 9.67455 8.32812 9.33398L8.29785 9.26367L8.19921 8.98438C7.73487 7.5758 7.67054 5.98959 7.75097 4.58203C7.77875 4.09598 7.82525 3.62422 7.87988 3.17969L1.53027 9.53027C1.23738 9.82317 0.762615 9.82317 0.469722 9.53027C0.176829 9.23738 0.176829 8.76262 0.469722 8.46973L6.83593 2.10254C6.3319 2.16472 5.79596 2.21841 5.25 2.24902C3.8302 2.32862 2.2474 2.26906 0.958003 1.79102L0.704097 1.68945L0.635738 1.65527C0.303274 1.47099 0.157578 1.06102 0.310542 0.704102C0.463655 0.347333 0.860941 0.170391 1.22363 0.28418L1.29589 0.310547L1.48828 0.387695C2.47399 0.751207 3.79966 0.827571 5.16601 0.750977C6.60111 0.670504 7.97842 0.428235 8.86132 0.262695L9.95312 0.0585938L9.73535 1.14746Z"></path></svg>`
                        );
                        break;
                }
                updatePaymentTerms(paymentType);
            });


            // Trigger change event on page load if a payment method is already selected
            $('input[name="payment_method"]:checked').trigger('change');
            updatePaymentTerms($('input[name="payment_type"]:checked').val());

            // Form validation
            $('#checkout-form').on('submit', function(e) {
                const paymentType = $('input[name="payment_type"]:checked').val();

                // Disable submit button to prevent double submission
                $('#checkout-submit-btn').prop('disabled', true).html('<span>Processing...</span>');
            });

            // ==========================================
            // Promo Code Management for Checkout
            // ==========================================

            // Apply promo code button click
            $('#apply-promo-checkout-btn').on('click', function() {
                const promoCode = $('#checkout-promo-input').val().trim();
                if (!promoCode) {
                    showCheckoutPromoError('Please enter a promo code');
                    return;
                }

                // Show loading state
                const btn = $(this);
                btn.prop('disabled', true);
                btn.find('.btn-text').hide();
                btn.find('.btn-loading').show();
                clearCheckoutPromoMessage();

                $.ajax({
                    url: '{{ route('cart.apply-promo-code') }}',
                    method: 'POST',
                    data: {
                        _token: '{{ csrf_token() }}',
                        promo_code: promoCode
                    },
                    success: function(response) {
                        if (response.success) {
                            showCheckoutPromoSuccess(response.message || 'Promo code applied!');
                            setTimeout(function() {
                                location.reload();
                            }, 1000);
                        } else {
                            showCheckoutPromoError(response.message || 'Invalid promo code');
                            resetCheckoutApplyButton();
                        }
                    },
                    error: function(xhr) {
                        const errorMsg = xhr.responseJSON?.message ||
                            'Error applying promo code';
                        showCheckoutPromoError(errorMsg);
                        resetCheckoutApplyButton();
                    }
                });
            });

            // Allow Enter key to apply promo code
            $('#checkout-promo-input').on('keypress', function(e) {
                if (e.which === 13) {
                    e.preventDefault();
                    $('#apply-promo-checkout-btn').click();
                }
            });

            // Remove promo code button click
            $(document).on('click', '.remove-promo-checkout-btn', function() {
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
                            showCheckoutPromoSuccess('Promo code removed');
                            setTimeout(function() {
                                location.reload();
                            }, 500);
                        } else {
                            showCheckoutPromoError(response.message ||
                                'Error removing promo code');
                            btn.prop('disabled', false);
                            btn.html('<i class="bi bi-x-lg"></i>');
                        }
                    },
                    error: function(xhr) {
                        const errorMsg = xhr.responseJSON?.message ||
                            'Error removing promo code';
                        showCheckoutPromoError(errorMsg);
                        btn.prop('disabled', false);
                        btn.html('<i class="bi bi-x-lg"></i>');
                    }
                });
            });

            // Helper functions for checkout promo code UI
            function showCheckoutPromoError(message) {
                $('#checkout-promo-message').html(
                    '<div class="alert alert-danger py-1 px-2 mb-0"><i class="bi bi-exclamation-circle"></i> ' +
                    message + '</div>'
                );
            }

            function showCheckoutPromoSuccess(message) {
                $('#checkout-promo-message').html(
                    '<div class="alert alert-success py-1 px-2 mb-0"><i class="bi bi-check-circle"></i> ' +
                    message + '</div>'
                );
            }

            function clearCheckoutPromoMessage() {
                $('#checkout-promo-message').empty();
            }

            function resetCheckoutApplyButton() {
                const btn = $('#apply-promo-checkout-btn');
                btn.prop('disabled', false);
                btn.find('.btn-text').show();
                btn.find('.btn-loading').hide();
            }
        });
    </script>

    <!-- Intl Tel Input Library -->
    <script src="https://cdn.jsdelivr.net/npm/intl-tel-input@17/build/js/intlTelInput.js"></script>
    <!-- LibPhoneNumber for validation -->
    <script src="https://cdn.jsdelivr.net/npm/libphonenumber-js@1/bundle/libphonenumber-js.min.js"></script>

    <script>
        $(document).ready(function() {
            // Country to calling code mapping - dynamically generated
            const countryCodeMap = {
                @foreach ($countries as $country)
                    '{{ $country->name }}': '{{ strtolower($country->code ?? 'us') }}',
                @endforeach
            };

            // Initialize intl-tel-input
            const phoneInput = document.querySelector('#phone-input');
            const phoneCountrySelect = document.querySelector('#country-select');
            const phoneCountryCodeField = document.querySelector('#phone-country-code');
            const phoneInternationalField = document.querySelector('#phone-international');
            const phoneErrorDiv = document.querySelector('#phone-error');
            const phoneValidDiv = document.querySelector('#phone-valid');

            // Generate preferred countries from available countries
            const preferredCountryCodes = ['lk', 'in', 'us', 'gb', 'ca', 'au'];
            const availablePreferredCountries = preferredCountryCodes.filter(code =>
                @json($countries->pluck('code')->map(fn($c) => strtolower($c))->toArray()).includes(code.toLowerCase())
            );

            const iti = window.intlTelInput(phoneInput, {
                initialCountry: 'lk',
                preferredCountries: availablePreferredCountries,
                separateDialCode: true,
                formatAsYouType: true,
                utilsScript: 'https://cdn.jsdelivr.net/npm/intl-tel-input@17/build/js/utils.js'
            });

            // Function to update country and validate
            function validatePhoneNumber() {
                const phoneNumber = phoneInput.value.trim();

                if (!phoneNumber) {
                    phoneErrorDiv.style.display = 'none';
                    phoneValidDiv.style.display = 'none';
                    return false;
                }

                // Check if number is valid
                if (iti.isValidNumber()) {
                    const countryData = iti.getSelectedCountryData();
                    const internationalNumber = iti.getNumber(intlTelInputUtils.numberFormat.INTERNATIONAL);
                    const e164Number = iti.getNumber(intlTelInputUtils.numberFormat.E164);

                    // Store formatted numbers
                    phoneCountryCodeField.value = countryData.dialCode;
                    phoneInternationalField.value = e164Number; // Store E164 format (+94771234567)

                    phoneErrorDiv.style.display = 'none';
                    phoneValidDiv.textContent = `✓ Valid ${countryData.name} number`;
                    phoneValidDiv.style.display = 'block';

                    return true;
                } else {
                    phoneCountryCodeField.value = '';
                    phoneInternationalField.value = '';

                    const countryData = iti.getSelectedCountryData();
                    const errorMsg = iti.getValidationError();
                    const errorMessages = {
                        0: 'Invalid number',
                        1: 'Too short',
                        2: 'Too long',
                        3: 'Not a number'
                    };

                    phoneErrorDiv.textContent =
                        `✗ ${errorMessages[errorMsg] || 'Invalid phone number for ' + countryData.name}`;
                    phoneErrorDiv.style.display = 'block';
                    phoneValidDiv.style.display = 'none';

                    return false;
                }
            }

            // Update country selection when select dropdown changes
            $(phoneCountrySelect).on('change', function() {
                const selectedCountry = $(this).val();
                const countryCode = countryCodeMap[selectedCountry];

                if (countryCode) {
                    iti.setCountry(countryCode);
                    // Focus and validate
                    setTimeout(() => {
                        validatePhoneNumber();
                    }, 100);
                }
            });

            // Validate on input
            $(phoneInput).on('input change blur', function() {
                validatePhoneNumber();
            });

            // Set default country from select
            const defaultCountry = $(phoneCountrySelect).val();
            if (defaultCountry && countryCodeMap[defaultCountry]) {
                const defaultCountryCode = countryCodeMap[defaultCountry];
                iti.setCountry(defaultCountryCode);
            }

            // Restore phone value if it exists (for form re-submission)
            if (phoneInput.value) {
                setTimeout(() => {
                    validatePhoneNumber();
                }, 200);
            }
        });
    </script>

    <!-- Select2 JS for searchable country dropdown -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <!-- Checkout Payment Type Handler -->
    <script src="{{ asset('assets/js/checkout-payment-type.js') }}"></script>
@endpush
