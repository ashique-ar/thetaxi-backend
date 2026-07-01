@extends('layouts.app')

@section('title', 'Checkout')

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
                    <li>Checkout</li>
                </ul>
            </div>
        </div>
    </div>
    <!-- End Breadcrumb section -->

    <!-- Booking Form Section -->
    <div class="filter-wrapper text-center hotel mb-5">
        <div class="container">
            @include('components.booking-form')
        </div>
    </div>
    <!-- End Booking Form Section -->

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
        $paymentAmount = floor(max(0, $paymentAmount));
    @endphp

    <!-- Checkout Page Start-->
    <div class="checkout-page" id="checkoutContentStart">
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
                                                    placeholder="Phone number" autocomplete="phone" required
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

                                        @php
                                            // Build a deduplicated map of applicable terms with metadata
                                            $uniqueTerms = [];

                                            // Terms grouped by service
                                            foreach ($termsByService as $service => $terms) {
                                                foreach ($terms as $tc) {
                                                    if (!isset($uniqueTerms[$tc->id])) {
                                                        $uniqueTerms[$tc->id] = [
                                                            'term' => $tc,
                                                            'services' => [],
                                                            'payment_types' => [],
                                                        ];
                                                    }
                                                    $uniqueTerms[$tc->id]['services'][] = ucfirst(
                                                        str_replace('_', ' ', $service),
                                                    );
                                                }
                                            }

                                            // Merge payment-type terms, record which payment types each term applies to
                                            foreach ($termsByPaymentType as $ptype => $terms) {
                                                foreach ($terms as $tc) {
                                                    if (!isset($uniqueTerms[$tc->id])) {
                                                        $uniqueTerms[$tc->id] = [
                                                            'term' => $tc,
                                                            'services' => [],
                                                            'payment_types' => [],
                                                        ];
                                                    }
                                                    $uniqueTerms[$tc->id]['payment_types'][] = $ptype;
                                                }
                                            }

                                            // Convert service and payment type arrays to unique lists
                                            foreach ($uniqueTerms as $id => $meta) {
                                                $uniqueTerms[$id]['services'] = array_values(
                                                    array_unique($meta['services']),
                                                );
                                                $uniqueTerms[$id]['payment_types'] = array_values(
                                                    array_unique($meta['payment_types']),
                                                );
                                            }
                                        @endphp

                                        @if (!empty($uniqueTerms))
                                            <div class="col-md-12">
                                                <div class="terms-conditions-section">
                                                    <h6>Applicable Terms & Conditions</h6>
                                                    <div class="terms-content">
                                                        @foreach ($uniqueTerms as $meta)
                                                            @php
                                                                $tc = $meta['term'];
                                                                $services = $meta['services'];
                                                                $pTypes = $meta['payment_types'];
                                                            @endphp
                                                            <div class="term-item mb-3"
                                                                data-payment-types="{{ implode(',', $pTypes) }}"
                                                                data-term-id="{{ $tc->id }}"
                                                                data-term-version="{{ $tc->version }}">
                                                                <div class="term-header">
                                                                    <div class="term-meta">
                                                                        <strong>{{ $tc->title }}</strong>
                                                                        @if (!empty($services))
                                                                            <small class="text-muted"> — Applies to:
                                                                                {{ implode(', ', $services) }}</small>
                                                                        @endif
                                                                        @if (!empty($pTypes))
                                                                            <small class="text-muted">
                                                                                <em>(Payment-specific:
                                                                                    {{ implode(', ', $pTypes) }})</em></small>
                                                                        @endif
                                                                    </div>
                                                                    <button type="button" class="term-toggle"
                                                                        aria-expanded="true">Hide</button>
                                                                </div>
                                                                <div class="term-body mt-2">
                                                                    {!! $tc->content !!}
                                                                </div>
                                                            </div>
                                                        @endforeach
                                                    </div>

                                                    <div class="terms-accept-all-container mt-3" id="termsAcceptancePanel">
                                                        <div class="form-check terms-accept-check">
                                                            <input class="form-check-input" type="checkbox"
                                                                id="accept_all_terms" aria-describedby="termsAcceptHelp">
                                                            <label class="form-check-label" for="accept_all_terms">
                                                                <span class="terms-accept-title">I agree to the above
                                                                    <strong>Terms & Conditions</strong></span>
                                                                <span class="terms-accept-copy" id="termsAcceptHelp">
                                                                    Required before placing your booking.
                                                                </span>
                                                            </label>
                                                        </div>
                                                        <div class="terms-accept-error" id="termsAcceptError" role="alert">
                                                            Please tick this box to continue with your booking.
                                                        </div>
                                                        <div id="termsAcceptedHiddenInputs"></div>
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

                                        <!-- Marketing Consent Checkbox -->
                                        <div class="col-md-12">
                                            <div class="form-inner2">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="marketing_consent"
                                                        value="1" id="marketingConsent"
                                                        {{ old('marketing_consent') ? 'checked' : '' }}>
                                                    <label class="form-check-label" for="marketingConsent">
                                                        I would like to receive marketing communications, special offers, and promotional emails. 
                                                        You can unsubscribe at any time.
                                                    </label>
                                                </div>
                                            </div>
                                        </div>

                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-5">
                            @include('checkout.partials.cart-summary')
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
                                                                                {{ number_format(floor(max(0, $total)), 0) }}</small>
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
                                                                                    {{ number_format(floor(max(0, $total * ($advancePercentage / 100))), 0) }}</small>
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
                                                                                    {{ number_format(floor(max(0, $total)), 0) }}</small>
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

                                            <!-- Hidden payment method field - set automatically based on payment type -->
                                            <input type="hidden" name="payment_method" id="payment_method_field"
                                                value="">

                                            <button type="submit" class="primary-btn1 w-100" id="checkout-submit-btn">
                                                <span>
                                                    @if ($paymentType === 'quotation')
                                                        Submit Quotation Request
                                                    @elseif ($paymentType === 'checkin')
                                                        Confirm Booking - Pay on Check-in
                                                    @else
                                                        Complete Booking -
                                                        <small class="currency-symbol">{{ $currencySymbol }}</small>
                                                        {{ number_format(floor(max(0, $paymentAmount)), 0) }}
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

    <div class="modal fade checkout-addon-modal" id="checkoutAddonModal" tabindex="-1"
        aria-labelledby="checkoutAddonModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title" id="checkoutAddonModalLabel">Manage add-ons</h5>
                        <small class="checkout-addon-modal-vehicle"></small>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="checkout-addon-panel" id="checkoutAddonModalPanel"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="checkout-addon-modal-done" data-bs-dismiss="modal">Done</button>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('styles')
    <style>
        /* Checkout Item Discount Styles */
        .item-total {
            text-align: right;
            min-width: 100px;
        }

        .checkout-item-discount-badge {
            display: inline-block;
            background: linear-gradient(135deg, #28a745, #20c997);
            color: #fff;
            font-size: 10px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 12px;
            margin-bottom: 4px;
        }

        .checkout-original-price {
            font-size: 12px;
            margin-bottom: 2px;
        }

        .checkout-original-price del {
            color: #888;
            text-decoration: line-through;
            text-decoration-color: #dc3545;
            text-decoration-thickness: 1px;
        }

        .checkout-final-price {
            font-weight: 600;
            font-size: 14px;
        }

        .checkout-final-price.discounted {
            color: #28a745;
        }

        .checkout-savings {
            margin-top: 2px;
        }

        .checkout-savings small {
            color: #28a745;
            font-size: 10px;
            font-weight: 600;
        }

        .price-adjustment-discount-row {
            background: rgba(40, 167, 69, 0.05);
            padding: 8px 12px;
            border-radius: 6px;
            margin-bottom: 8px;
        }

        .price-adjustment-discount-row span:first-child {
            font-weight: 500;
        }


        .checkout-remove-item-btn,
        .checkout-clear-cart-btn {
            border: 1px solid #dc3545;
            background: #fff;
            color: #dc3545;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            padding: 5px 9px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            transition: all 0.2s ease;
        }

        .checkout-remove-item-btn {
            margin-top: 8px;
        }

        .checkout-remove-item-btn:hover,
        .checkout-clear-cart-btn:hover {
            background: #dc3545;
            color: #fff;
        }

        .checkout-remove-item-btn:disabled,
        .checkout-clear-cart-btn:disabled {
            opacity: 0.65;
            cursor: not-allowed;
        }

        .checkout-item-addons {
            margin: 0 0 12px 75px;
            padding: 12px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            background: #f8fafc;
        }

        .checkout-item-extra-km {
            margin: 0 0 12px 75px;
            padding: 12px;
            border: 1px solid #dbeafe;
            border-radius: 8px;
            background: #eff6ff;
        }

        .checkout-addon-header,
        .checkout-extra-km-header,
        .checkout-addon-card,
        .checkout-addon-actions,
        .checkout-addon-qty,
        .checkout-extra-km-actions,
        .checkout-extra-km-input {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .checkout-addon-header,
        .checkout-extra-km-header {
            justify-content: space-between;
        }

        .checkout-addon-header small,
        .checkout-extra-km-header small {
            display: block;
            color: #64748b;
            margin-top: 2px;
        }

        .checkout-toggle-addons,
        .checkout-toggle-extra-km,
        .checkout-addon-apply,
        .checkout-addon-remove,
        .checkout-extra-km-apply,
        .checkout-extra-km-remove {
            border: 1px solid #d1d5db;
            background: #fff;
            color: #111827;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 700;
            padding: 6px 10px;
        }

        .checkout-addon-apply {
            border-color: var(--primary-color1);
            color: var(--primary-color1);
        }

        .checkout-addon-remove,
        .checkout-extra-km-remove {
            border-color: #dc3545;
            color: #dc3545;
        }

        .checkout-addon-panel,
        .checkout-extra-km-panel {
            margin-top: 12px;
        }

        .checkout-addon-card {
            justify-content: space-between;
            padding: 10px 0;
            border-top: 1px solid #e5e7eb;
        }

        .checkout-addon-info {
            min-width: 0;
        }

        .checkout-addon-info strong,
        .checkout-addon-info small {
            display: block;
        }

        .checkout-addon-info small {
            color: #64748b;
        }

        .checkout-addon-qty input {
            width: 58px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            padding: 5px 6px;
            text-align: center;
        }

        .checkout-addon-loading,
        .checkout-addon-empty,
        .checkout-addon-error,
        .checkout-extra-km-loading,
        .checkout-extra-km-empty,
        .checkout-extra-km-error {
            color: #64748b;
            font-size: 13px;
            padding: 8px 0;
        }

        .checkout-extra-km-input {
            margin: 10px 0;
        }

        .checkout-extra-km-input input {
            width: 90px;
            border: 1px solid #bfdbfe;
            border-radius: 6px;
            padding: 6px 8px;
        }
        /* Currency Formatting */
        .currency-symbol,
        .currency-code {
            font-size: 0.8em;
            font-weight: normal;
            opacity: 0.8;
            margin-right: 0.25rem;
        }

        .checkout-page {
            background: linear-gradient(180deg, #f8fafc 0%, #ffffff 44%);
            padding: 56px 0 84px;
        }

        .checkout-form-wrapper,
        .checkout-page .order-sum-area,
        .checkout-page .inquiry-form {
            border: 1px solid rgba(15, 23, 42, 0.08);
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.96);
            box-shadow: 0 20px 50px rgba(15, 23, 42, 0.08);
        }

        .checkout-form-wrapper {
            padding: 26px;
        }

        .checkout-form-title {
            padding-bottom: 16px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .checkout-form-title h4 {
            margin: 0;
            color: #111827;
            font-weight: 800;
        }

        .checkout-form .form-inner label {
            margin-bottom: 8px;
            color: #334155;
            font-weight: 700;
        }

        .checkout-form .form-inner input,
        .checkout-form .form-inner select,
        .checkout-form .form-inner textarea {
            border: 1px solid #dbe3ef;
            border-radius: 8px;
            background: #fff;
            min-height: 48px;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .checkout-form .form-inner input:focus,
        .checkout-form .form-inner select:focus,
        .checkout-form .form-inner textarea:focus {
            border-color: var(--primary-color1, #BF2629);
            box-shadow: 0 0 0 4px rgba(191, 38, 41, 0.1);
            outline: none;
        }

        .checkout-page .order-sum-area {
            position: sticky;
            top: 110px;
            padding: 24px;
        }

        .checkout-page .order-sum-area h4,
        .checkout-page .order-sum-area h5,
        .checkout-page .order-sum-area h6 {
            color: #111827;
            font-weight: 800;
        }

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
            border: 1px solid #dbe3ef;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            transition: all 0.3s ease;
            background: white;
            min-height: 154px;
        }

        .payment-card:hover {
            border-color: var(--primary-color1);
            transform: translateY(-2px);
            box-shadow: 0 16px 34px rgba(15, 23, 42, 0.1);
        }

        .payment-radio:checked+.payment-label .payment-card {
            border-color: var(--primary-color1);
            background: linear-gradient(180deg, rgba(191, 38, 41, 0.08), rgba(191, 38, 41, 0.02));
            box-shadow: inset 0 0 0 1px rgba(191, 38, 41, 0.12), 0 16px 34px rgba(191, 38, 41, 0.1);
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

        @media (max-width: 991px) {
            .checkout-page .order-sum-area {
                position: static;
            }
        }

        @media (max-width: 575px) {
            .checkout-page {
                padding: 36px 0 56px;
            }

            .checkout-form-wrapper,
            .checkout-page .order-sum-area {
                padding: 18px;
            }

            .payment-card {
                min-height: 0;
            }
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

        .content-and-quantity p {
            line-height: 18px;
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
            padding: 20px 0 0 0;
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
            max-width: 100%;
            min-width: 0;
            overflow: hidden;
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
            overflow-x: hidden;
            padding-right: 10px;
            max-width: 100%;
            min-width: 0;
            overscroll-behavior: contain;
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
            max-width: 100%;
            min-width: 0;
            overflow-wrap: anywhere;
            word-break: normal;
        }

        .term-meta {
            min-width: 0;
            overflow-wrap: anywhere;
        }

        .term-body img,
        .term-body video,
        .term-body iframe {
            max-width: 100% !important;
            height: auto;
        }

        .term-body table {
            width: 100% !important;
            max-width: 100% !important;
            table-layout: fixed;
        }

        .term-body pre {
            max-width: 100%;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }

        .term-body * {
            max-width: 100%;
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

        .terms-accept-all-container {
            background: #fff;
            border: 2px solid #d7eadc;
            border-radius: 8px;
            padding: 16px;
            box-shadow: 0 8px 24px rgba(33, 37, 41, 0.06);
            transition: border-color 0.2s ease, box-shadow 0.2s ease, background-color 0.2s ease;
        }

        .terms-accept-all-container:focus-within {
            border-color: #1fa64a;
            box-shadow: 0 0 0 4px rgba(31, 166, 74, 0.14);
        }

        .terms-accept-all-container.terms-accepted {
            background: #f7fff9;
            border-color: #1fa64a;
        }

        .terms-accept-all-container.terms-missing {
            background: #fff8f8;
            border-color: #dc3545;
            box-shadow: 0 0 0 4px rgba(220, 53, 69, 0.12);
        }

        .terms-accept-check {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin: 0;
            min-height: 44px;
        }

        .terms-accept-check .form-check-input {
            width: 24px;
            height: 24px;
            flex: 0 0 24px;
            margin: 2px 0 0;
            border: 2px solid #1fa64a;
            cursor: pointer;
        }

        .terms-accept-check .form-check-input:checked {
            background-color: #1fa64a;
            border-color: #1fa64a;
        }

        .terms-accept-check .form-check-label {
            cursor: pointer;
            margin: 0;
            color: #1f2933;
            line-height: 1.35;
        }

        .terms-accept-title {
            display: block;
            font-size: 16px;
            font-weight: 600;
        }

        .terms-accept-copy {
            display: block;
            margin-top: 2px;
            color: #6c757d;
            font-size: 13px;
        }

        .terms-accept-error {
            display: none;
            margin-top: 10px;
            color: #b02a37;
            font-size: 13px;
            font-weight: 600;
        }

        .terms-missing .terms-accept-error {
            display: block;
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

            .terms-accept-all-container {
                padding: 14px;
            }

            .terms-accept-title {
                font-size: 15px;
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

        /* Terms collapse styles */
        .term-header {
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            min-width: 0;
        }

        .term-toggle {
            cursor: pointer;
            font-size: .95rem;
            color: #6c757d;
            border: none;
            background: transparent;
            padding: 0;
            flex: 0 0 auto;
        }

        /* Prevent intrinsic-width content from widening the checkout page. */
        .checkout-page {
            max-width: 100%;
            overflow-x: clip;
        }

        .checkout-page .row > *,
        .checkout-form-wrapper,
        .checkout-form,
        .checkout-page .order-sum-area,
        .checkout-page .single-item,
        .checkout-page .item-area,
        .checkout-page .main-item,
        .checkout-page .content {
            min-width: 0;
            max-width: 100%;
        }

        .term-body {
            transition: all .2s ease;
        }

        .term-body.collapsed {
            display: none !important;
        }
    </style>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/intl-tel-input@17/build/css/intlTelInput.css">
    <!-- Select2 CSS for searchable country dropdown -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet"
        href="{{ assetVersion(is_theme('theme-02') ? 'assets/css/checkout-theme-02.css' : 'assets/css/checkout-theme-01.css') }}">
@endpush

@push('scripts')
    <script>
        $(document).ready(function() {
            const mobileCheckoutTarget = document.getElementById('checkoutContentStart');

            if (window.innerWidth <= 767 && mobileCheckoutTarget && !window.location.hash) {
                window.requestAnimationFrame(function() {
                    setTimeout(function() {
                        const top = mobileCheckoutTarget.getBoundingClientRect().top + window.scrollY - 16;
                        window.scrollTo({
                            top: Math.max(top, 0),
                            behavior: 'smooth'
                        });
                    }, 250);
                });
            }

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
                if (!paymentTermsGroups.length && $('[data-payment-types]').length === 0) {
                    return;
                }

                // Hide/Show legacy payment-specific groups
                if (paymentTermsGroups.length) {
                    paymentTermsGroups.hide();
                    const match = paymentTermsGroups.filter(`[data-payment-type="${type}"]`);
                    if (match.length) {
                        match.show();
                    }
                }

                // Additionally, show/hide individual term items that declare payment-type applicability
                $('[data-payment-types]').each(function() {
                    const $el = $(this);
                    const allowed = ($el.data('paymentTypes') || '').toString();
                    if (!allowed || allowed === '') {
                        // No payment-type restriction; always visible
                        $el.show();
                        return;
                    }
                    const list = allowed.split(',').map(x => x.trim()).filter(Boolean);
                    if (list.indexOf(type) !== -1) {
                        $el.show();
                    } else {
                        $el.hide();
                    }
                });
            }

            // Payment type selection handling
            $('input[name="payment_type"]').on('change', function() {
                const paymentType = $(this).val();
                const alertContent = $('#alert-content');
                const paymentMethodSection = $('.choose-payment-method');
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
                        paymentMethodSection.show();
                        submitBtn.html(
                            `Complete Booking - ${currencySymbol}${Math.floor(Math.max(0, advanceAmount)).toFixed(0)} <svg width="10" height="10" viewBox="0 0 10 10" xmlns="http://www.w3.org/2000/svg"><path d="M9.73535 1.14746C9.57033 1.97255 9.32924 3.26406 9.24902 4.66797C9.16817 6.08312 9.25559 7.5453 9.70214 8.73633C9.84754 9.12406 9.65129 9.55659 9.26367 9.70215C8.9001 9.83849 8.4969 9.67455 8.32812 9.33398L8.29785 9.26367L8.19921 8.98438C7.73487 7.5758 7.67054 5.98959 7.75097 4.58203C7.77875 4.09598 7.82525 3.62422 7.87988 3.17969L1.53027 9.53027C1.23738 9.82317 0.762615 9.82317 0.469722 9.53027C0.176829 9.23738 0.176829 8.76262 0.469722 8.46973L6.83593 2.10254C6.3319 2.16472 5.79596 2.21841 5.25 2.24902C3.8302 2.32862 2.2474 2.26906 0.958003 1.79102L0.704097 1.68945L0.635738 1.65527C0.303274 1.47099 0.157578 1.06102 0.310542 0.704102C0.463655 0.347333 0.860941 0.170391 1.22363 0.28418L1.29589 0.310547L1.48828 0.387695C2.47399 0.751207 3.79966 0.827571 5.16601 0.750977C6.60111 0.670504 7.97842 0.428235 8.86132 0.262695L9.95312 0.0585938L9.73535 1.14746Z"></path></svg>`
                        );
                        break;
                    case 'quotation':
                        alertContent.html(`
                    <h6><i class="bi bi-file-text"></i> Request Quotation</h6>
                    <p class="mb-0">You are requesting a quotation. Our team will contact you with detailed pricing and booking information.</p>
                `);
                        paymentMethodSection.hide();
                        submitBtn.html(
                            'Submit Quotation Request <svg width="10" height="10" viewBox="0 0 10 10" xmlns="http://www.w3.org/2000/svg"><path d="M9.73535 1.14746C9.57033 1.97255 9.32924 3.26406 9.24902 4.66797C9.16817 6.08312 9.25559 7.5453 9.70214 8.73633C9.84754 9.12406 9.65129 9.55659 9.26367 9.70215C8.9001 9.83849 8.4969 9.67455 8.32812 9.33398L8.29785 9.26367L8.19921 8.98438C7.73487 7.5758 7.67054 5.98959 7.75097 4.58203C7.77875 4.09598 7.82525 3.62422 7.87988 3.17969L1.53027 9.53027C1.23738 9.82317 0.762615 9.82317 0.469722 9.53027C0.176829 9.23738 0.176829 8.76262 0.469722 8.46973L6.83593 2.10254C6.3319 2.16472 5.79596 2.21841 5.25 2.24902C3.8302 2.32862 2.2474 2.26906 0.958003 1.79102L0.704097 1.68945L0.635738 1.65527C0.303274 1.47099 0.157578 1.06102 0.310542 0.704102C0.463655 0.347333 0.860941 0.170391 1.22363 0.28418L1.29589 0.310547L1.48828 0.387695C2.47399 0.751207 3.79966 0.827571 5.16601 0.750977C6.60111 0.670504 7.97842 0.428235 8.86132 0.262695L9.95312 0.0585938L9.73535 1.14746Z"></path></svg>'
                        );
                        break;
                    case 'checkin':
                        alertContent.html(`
                    <h6><i class="bi bi-cash-coin"></i> Pay on Check-in</h6>
                    <p class="mb-0">No payment is required now. You will pay the full amount when you check-in to collect the vehicle.</p>
                `);
                        paymentMethodSection.hide();
                        submitBtn.html(
                            `Confirm Booking - Pay on Check-in <svg width="10" height="10" viewBox="0 0 10 10" xmlns="http://www.w3.org/2000/svg"><path d="M9.73535 1.14746C9.57033 1.97255 9.32924 3.26406 9.24902 4.66797C9.16817 6.08312 9.25559 7.5453 9.70214 8.73633C9.84754 9.12406 9.65129 9.55659 9.26367 9.70215C8.9001 9.83849 8.4969 9.67455 8.32812 9.33398L8.29785 9.26367L8.19921 8.98438C7.73487 7.5758 7.67054 5.98959 7.75097 4.58203C7.77875 4.09598 7.82525 3.62422 7.87988 3.17969L1.53027 9.53027C1.23738 9.82317 0.762615 9.82317 0.469722 9.53027C0.176829 9.23738 0.176829 8.76262 0.469722 8.46973L6.83593 2.10254C6.3319 2.16472 5.79596 2.21841 5.25 2.24902C3.8302 2.32862 2.2474 2.26906 0.958003 1.79102L0.704097 1.68945L0.635738 1.65527C0.303274 1.47099 0.157578 1.06102 0.310542 0.704102C0.463655 0.347333 0.860941 0.170391 1.22363 0.28418L1.29589 0.310547L1.48828 0.387695C2.47399 0.751207 3.79966 0.827571 5.16601 0.750977C6.60111 0.670504 7.97842 0.428235 8.86132 0.262695L9.95312 0.0585938L9.73535 1.14746Z"></path></svg>`
                        );
                        break;
                    default:
                        alertContent.html(`
                    <h6><i class="bi bi-credit-card"></i> Full Payment</h6>
                    <p class="mb-0">You are making full payment for your booking.</p>
                `);
                        paymentMethodSection.show();
                        submitBtn.html(
                            `Complete Booking - ${currencySymbol}${Math.floor(Math.max(0, fullAmount)).toFixed(0)} <svg width="10" height="10" viewBox="0 0 10 10" xmlns="http://www.w3.org/2000/svg"><path d="M9.73535 1.14746C9.57033 1.97255 9.32924 3.26406 9.24902 4.66797C9.16817 6.08312 9.25559 7.5453 9.70214 8.73633C9.84754 9.12406 9.65129 9.55659 9.26367 9.70215C8.9001 9.83849 8.4969 9.67455 8.32812 9.33398L8.29785 9.26367L8.19921 8.98438C7.73487 7.5758 7.67054 5.98959 7.75097 4.58203C7.77875 4.09598 7.82525 3.62422 7.87988 3.17969L1.53027 9.53027C1.23738 9.82317 0.762615 9.82317 0.469722 9.53027C0.176829 9.23738 0.176829 8.76262 0.469722 8.46973L6.83593 2.10254C6.3319 2.16472 5.79596 2.21841 5.25 2.24902C3.8302 2.32862 2.2474 2.26906 0.958003 1.79102L0.704097 1.68945L0.635738 1.65527C0.303274 1.47099 0.157578 1.06102 0.310542 0.704102C0.463655 0.347333 0.860941 0.170391 1.22363 0.28418L1.29589 0.310547L1.48828 0.387695C2.47399 0.751207 3.79966 0.827571 5.16601 0.750977C6.60111 0.670504 7.97842 0.428235 8.86132 0.262695L9.95312 0.0585938L9.73535 1.14746Z"></path></svg>`
                        );
                        break;
                }
                updatePaymentTerms(paymentType);
            });


            // Trigger change event on page load if a payment type is already selected
            updatePaymentTerms($('input[name="payment_type"]:checked').val());

            // Form submission - disable submit button to prevent double submission
            $('#checkout-form').on('submit', function(e) {
                const $termsPanel = $('#termsAcceptancePanel');
                const $acceptTerms = $('#accept_all_terms');
                if ($acceptTerms.length && !$acceptTerms.is(':checked')) {
                    e.preventDefault();
                    $termsPanel.addClass('terms-missing').removeClass('terms-accepted');
                    $acceptTerms.trigger('focus');
                    $('html, body').animate({
                        scrollTop: Math.max($termsPanel.offset().top - 110, 0)
                    }, 250);
                    return false;
                }

                // Payment method is now set automatically, server validates
                $('#checkout-submit-btn').prop('disabled', true).html('<span>Processing...</span>');
            });

            // Terms acceptance sync - single checkbox controls hidden per-term inputs expected by server
            function syncAcceptedTerms() {
                const $container = $('#termsAcceptedHiddenInputs').empty();
                const checked = $('#accept_all_terms').is(':checked');
                if (!checked) return;
                // find visible term items and create hidden inputs
                $('[data-term-id]').each(function() {
                    const $item = $(this);
                    if ($item.is(':visible')) {
                        const id = $item.data('termId');
                        const version = $item.data('termVersion');
                        if (id) {
                            const input = $('<input>').attr({
                                type: 'hidden',
                                name: `terms_accepted[${id}]`,
                                value: version || '1',
                                id: `terms_accepted_${id}`
                            });
                            $container.append(input);

                            // Collapse the term body to keep UI compact
                            const $body = $item.find('.term-body');
                            if ($body.is(':visible')) {
                                $body.slideUp(150);
                                $item.find('.term-toggle').text('Show').attr('aria-expanded', 'false');
                            }
                        }
                    }
                });
            }

            $('#accept_all_terms').on('change', function() {
                $('#termsAcceptancePanel')
                    .toggleClass('terms-accepted', $(this).is(':checked'))
                    .removeClass('terms-missing');
                syncAcceptedTerms();
                // Collapse or expand visible terms depending on checked state
                if ($(this).is(':checked')) {
                    $('[data-term-id]:visible').each(function() {
                        const $item = $(this);
                        const $body = $item.find('.term-body');
                        if ($body.is(':visible')) {
                            $body.slideUp(150);
                        }
                        $item.find('.term-toggle').text('Show').attr('aria-expanded', 'false');
                    });
                } else {
                    $('[data-term-id]:visible').each(function() {
                        const $item = $(this);
                        const $body = $item.find('.term-body');
                        if ($body.is(':hidden')) {
                            $body.slideDown(150);
                        }
                        $item.find('.term-toggle').text('Hide').attr('aria-expanded', 'true');
                    });
                }
            });

            // Re-sync when payment type changes (terms visibility updated elsewhere)
            $('input[name="payment_type"]').on('change', function() {
                if ($('#accept_all_terms').is(':checked')) {
                    // small debounce
                    setTimeout(syncAcceptedTerms, 50);
                }
            });

            // Term toggle handlers (allow clicking the header or button to show/hide body)
            $(document).on('click', '.term-toggle', function(e) {
                e.preventDefault();
                const $btn = $(this);
                const $item = $btn.closest('.term-item');
                const $body = $item.find('.term-body');
                if ($body.is(':visible')) {
                    $body.slideUp(150);
                    $btn.text('Show').attr('aria-expanded', 'false');
                } else {
                    $body.slideDown(150);
                    $btn.text('Hide').attr('aria-expanded', 'true');
                }
            });

            $(document).on('click', '.term-header', function(e) {
                if ($(e.target).closest('.term-toggle').length) return; // avoid double handling
                const $item = $(this).closest('.term-item');
                $item.find('.term-toggle').trigger('click');
            });

            // On page load ensure state if old inputs were present
            if ($('#accept_all_terms').length) {
                // if server-side old inputs indicate prior acceptance, check global box
                const hasPreviouslyAccepted = Object.keys(@json(old('terms_accepted', []))).length > 0;
                if (hasPreviouslyAccepted) {
                    $('#accept_all_terms').prop('checked', true);
                    $('#termsAcceptancePanel').addClass('terms-accepted');
                    syncAcceptedTerms();
                }
            }
            // ==========================================

            function checkoutRedirectHome() {
                window.location.href = '{{ route('home') }}';
            }

            function formatCheckoutAmount(value) {
                const amount = Math.max(0, Math.floor(Number(value) || 0));
                return '{{ $currencySymbol }} ' + amount.toLocaleString('en-US');
            }

            function updateCheckoutCartTotals(cart) {
                if (!cart || !cart.totals) {
                    return;
                }

                const totals = cart.totals;
                const normalFields = [
                    'subtotal',
                    'addon_charges',
                    'extra_km_charges',
                    'service_fee',
                    'tax',
                    'vat',
                    'total'
                ];
                const discountFields = [
                    'price_adjustment_discount',
                    'coupon_discount'
                ];

                normalFields.forEach(function(field) {
                    const value = Number(totals[field] || 0);
                    $('[data-summary-field="' + field + '"]').text(formatCheckoutAmount(value));
                    $('[data-summary-row="' + field + '"]').toggle(value > 0);
                });

                discountFields.forEach(function(field) {
                    const value = Number(totals[field] || 0);
                    $('[data-summary-field="' + field + '"]').text('-' + formatCheckoutAmount(value));
                    $('[data-summary-row="' + field + '"]').toggle(value > 0);
                });

                const paymentType = $('input[name="payment_type"]:checked').val() || '{{ $paymentType }}';
                if (paymentType === 'advance') {
                    const advanceAmount = Number(totals.total || 0) * (Number('{{ $advancePercentage }}') / 100);
                    $('[data-summary-field="payment_amount"]').text(formatCheckoutAmount(advanceAmount));
                } else if (paymentType === 'checkin') {
                    $('[data-summary-field="payment_amount"]').text(formatCheckoutAmount(totals.total));
                }

                const itemCount = Number(cart.item_count || Object.keys(cart.items || {}).length);
                if (cart.is_empty || itemCount === 0) {
                    checkoutRedirectHome();
                }
            }

            function checkoutEscapeHtml(value) {
                return $('<div>').text(value || '').html();
            }

            function checkoutAddonAmount(addon) {
                const amount = Math.max(0, Math.floor(Number(addon.amount || addon.calculated_amount || 0)));
                return formatCheckoutAmount(amount);
            }

            function selectedAddonMap(selectedAddons) {
                const map = {};
                if (Array.isArray(selectedAddons)) {
                    selectedAddons.forEach(function(addon) {
                        map[addon.addon_id] = Number(addon.qty || 0);
                    });
                }
                return map;
            }

            function updateCheckoutAddonCount(cartKey, selectedCount) {
                $('.checkout-item-addons[data-cart-key="' + cartKey + '"] .checkout-addon-count').text(selectedCount);
            }

            function renderSelectedAddonSummary(cartKey, selectedAddons) {
                const summary = $('.checkout-item-addons[data-cart-key="' + cartKey + '"] .checkout-selected-addons');
                if (!summary.length) {
                    return;
                }

                const selected = Array.isArray(selectedAddons) ? selectedAddons.filter(function(addon) {
                    return Number(addon.qty || 0) > 0;
                }) : [];

                updateCheckoutAddonCount(cartKey, selected.length);
                if (!selected.length) {
                    summary.html('<div class="checkout-no-addons">No add-ons selected</div>');
                    return;
                }

                summary.html(selected.map(function(addon) {
                    return `
                        <div class="checkout-selected-addon-row" data-addon-id="${checkoutEscapeHtml(addon.addon_id || addon.id)}">
                            <div>
                                <strong>${checkoutEscapeHtml(addon.name || 'Add-on')}</strong>
                                <small>Qty: ${Number(addon.qty || 1)}</small>
                            </div>
                            <span>${checkoutAddonAmount(addon)}</span>
                        </div>
                    `;
                }).join(''));
            }

            function loadCheckoutAddons(cartKey, serviceType, forceReload) {
                const panel = $('#checkoutAddonModalPanel');
                if (!panel.length) {
                    return;
                }
                if (panel.data('loaded') && panel.data('cart-key') === cartKey && !forceReload) {
                    return;
                }

                panel.data('cart-key', cartKey).data('service-type', serviceType || '');
                panel.html('<div class="checkout-addon-loading">Loading add-ons...</div>');

                $.when(
                    $.ajax({
                        url: '{{ route('cart.addons.available') }}',
                        method: 'GET',
                        data: {
                            service_type: serviceType || '',
                            cart_key: cartKey
                        }
                    }),
                    $.ajax({
                        url: '{{ route('cart.addons.get', ['cartKey' => ':cartKey']) }}'.replace(':cartKey', encodeURIComponent(cartKey)),
                        method: 'GET'
                    })
                ).done(function(availableResponse, selectedResponse) {
                    const available = availableResponse[0]?.data || [];
                    const selected = selectedResponse[0]?.data || [];
                    renderCheckoutAddons(cartKey, available, selected);
                    renderSelectedAddonSummary(cartKey, selected);
                    panel.data('loaded', true);
                }).fail(function() {
                    panel.html('<div class="checkout-addon-error">Unable to load add-ons.</div>');
                });
            }

            function initializeCheckoutAddonAvailability() {
                $('.checkout-item-addons').each(function() {
                    const wrapper = $(this);
                    const serviceType = wrapper.data('service-type') || '';
                    const selectedCount = Number(wrapper.find('.checkout-addon-count').text() || 0);

                    $.ajax({
                        url: '{{ route('cart.addons.available') }}',
                        method: 'GET',
                        data: {
                            service_type: serviceType,
                            cart_key: wrapper.data('cart-key')
                        },
                        success: function(response) {
                            const hasAvailableAddons = response.success && Array.isArray(response.data) && response.data.length > 0;
                            wrapper.toggle(hasAvailableAddons || selectedCount > 0)
                                .removeClass('checkout-option-pending');
                        },
                        error: function() {
                            // Preserve already-selected add-ons, but do not offer an
                            // unavailable manager when eligibility cannot be verified.
                            wrapper.toggle(selectedCount > 0)
                                .removeClass('checkout-option-pending');
                        }
                    });
                });
            }

            function renderCheckoutAddons(cartKey, addons, selectedAddons) {
                const panel = $('#checkoutAddonModalPanel');
                const selected = selectedAddonMap(selectedAddons);
                let selectedCount = 0;

                if (!Array.isArray(addons) || addons.length === 0) {
                    panel.html('<div class="checkout-addon-empty">No add-ons available for this item.</div>');
                    renderSelectedAddonSummary(cartKey, selectedAddons);
                    return;
                }

                const html = addons.map(function(addon) {
                    const qty = selected[addon.id] || 0;
                    if (qty > 0) {
                        selectedCount++;
                    }
                    const maxQty = Number(addon.max_qty || 999);
                    return `
                        <div class="checkout-addon-card" data-addon-id="${addon.id}">
                            <div class="checkout-addon-info">
                                <strong>${checkoutEscapeHtml(addon.name)}</strong>
                                <small>${checkoutEscapeHtml(addon.description || 'Add-on')}</small>
                                <small>${checkoutAddonAmount(addon)}</small>
                            </div>
                            <div class="checkout-addon-actions">
                                <div class="checkout-addon-qty">
                                    <input type="number" min="0" max="${maxQty}" value="${qty}" data-selected-qty="${qty}"
                                        data-addon-id="${addon.id}" data-cart-key="${cartKey}">
                                </div>
                                <button type="button" class="checkout-addon-apply"
                                    data-addon-id="${addon.id}" data-cart-key="${cartKey}">
                                    ${qty > 0 ? 'Update' : 'Add'}
                                </button>
                                ${qty > 0 ? `<button type="button" class="checkout-addon-remove"
                                    data-addon-id="${addon.id}" data-cart-key="${cartKey}">Remove</button>` : ''}
                            </div>
                        </div>
                    `;
                }).join('');

                panel.html(html + `
                    <div class="checkout-addon-bulk">
                        <button type="button" class="checkout-addon-update-all" data-cart-key="${cartKey}">
                            Apply all add-on quantities
                        </button>
                    </div>
                `);
                renderSelectedAddonSummary(cartKey, selectedAddons);
            }

            function mutateCheckoutAddon(url, data, button) {
                button.prop('disabled', true);

                $.ajax({
                    url: url,
                    method: 'POST',
                    data: Object.assign({
                        _token: '{{ csrf_token() }}'
                    }, data),
                    success: function(response) {
                        if (!response.success) {
                            showCheckoutPromoError(response.message || 'Unable to update add-on');
                            button.prop('disabled', false);
                            return;
                        }

                        updateCheckoutCartTotals(response.cart);
                        const panel = $('#checkoutAddonModalPanel');
                        loadCheckoutAddons(data.cart_key, panel.data('service-type'), true);
                    },
                    error: function(xhr) {
                        showCheckoutPromoError(xhr.responseJSON?.message || 'Unable to update add-on');
                        button.prop('disabled', false);
                    }
                });
            }

            $(document).on('click', '.checkout-toggle-addons', function() {
                const button = $(this);
                const cartKey = button.data('cart-key');
                const serviceType = button.data('service-type');
                $('.checkout-addon-modal-vehicle').text(button.data('vehicle-name') || 'Selected vehicle');
                bootstrap.Modal.getOrCreateInstance(document.getElementById('checkoutAddonModal')).show();
                loadCheckoutAddons(cartKey, serviceType, false);
            });

            $(document).on('click', '.checkout-addon-apply', function() {
                const button = $(this);
                const cartKey = button.data('cart-key');
                const addonId = button.data('addon-id');
                const input = $('.checkout-addon-qty input[data-cart-key="' + cartKey + '"][data-addon-id="' + addonId + '"]');
                const qty = Number(input.val() || 0);
                const selectedQty = Number(input.data('selected-qty') || 0);

                if (qty <= 0) {
                    mutateCheckoutAddon('{{ route('cart.addon.remove') }}', {
                        cart_key: cartKey,
                        addon_id: addonId
                    }, button);
                    return;
                }

                mutateCheckoutAddon(selectedQty > 0 ? '{{ route('cart.addon.update-qty') }}' : '{{ route('cart.addon.add') }}', {
                    cart_key: cartKey,
                    addon_id: addonId,
                    qty: qty
                }, button);
            });

            $(document).on('click', '.checkout-addon-remove', function() {
                const button = $(this);
                mutateCheckoutAddon('{{ route('cart.addon.remove') }}', {
                    cart_key: button.data('cart-key'),
                    addon_id: button.data('addon-id')
                }, button);
            });

            $(document).on('click', '.checkout-addon-update-all', function() {
                const button = $(this);
                const cartKey = button.data('cart-key');
                const updates = $('.checkout-addon-qty input[data-cart-key="' + cartKey + '"]').map(function() {
                    return {
                        cart_key: cartKey,
                        addon_id: $(this).data('addon-id'),
                        qty: Number($(this).val() || 0)
                    };
                }).get();

                if (!updates.length) {
                    return;
                }

                mutateCheckoutAddon('{{ route('cart.addons.update-all') }}', {
                    cart_key: cartKey,
                    updates: updates
                }, button);
            });

            function loadCheckoutExtraKm(cartKey, forceReload) {
                const panel = $('.checkout-extra-km-panel[data-cart-key="' + cartKey + '"]');
                const wrapper = $('.checkout-item-extra-km[data-cart-key="' + cartKey + '"]');
                if (!panel.length) {
                    return;
                }
                if (panel.data('loaded') && !forceReload) {
                    return;
                }

                panel.html('<div class="checkout-extra-km-loading">Loading extra KM options...</div>');

                $.ajax({
                    url: '{{ url('/cart/extra-km') }}/' + encodeURIComponent(cartKey),
                    method: 'GET',
                    success: function(response) {
                        if (!response.success || !response.data || !response.data.has_slab || !response.data.rate) {
                            wrapper.hide().removeClass('checkout-option-pending');
                            return;
                        }

                        wrapper.removeClass('checkout-option-pending').show();
                        renderCheckoutExtraKm(cartKey, response.data);
                        panel.data('loaded', true);
                    },
                    error: function() {
                        wrapper.hide().removeClass('checkout-option-pending');
                    }
                });
            }

            function renderCheckoutExtraKm(cartKey, data) {
                const panel = $('.checkout-extra-km-panel[data-cart-key="' + cartKey + '"]');
                const rate = Number(data.rate?.rate || 0);
                const currentKm = Number(data.current_extra_km?.km || 0);
                const total = currentKm * rate;

                panel.data('rate', rate);
                panel.html(`
                    <div class="checkout-extra-km-rate">
                        Rate: <strong>${formatCheckoutAmount(rate)}</strong> per km
                    </div>
                    <div class="checkout-extra-km-input">
                        <label for="checkout-extra-km-${cartKey}">Extra kilometers</label>
                        <input type="number" id="checkout-extra-km-${cartKey}" class="checkout-extra-km-value"
                            min="0" max="10000" step="1" value="${currentKm}" data-cart-key="${cartKey}">
                    </div>
                    <div class="checkout-extra-km-total">
                        Total: <strong class="checkout-extra-km-total-value">${formatCheckoutAmount(total)}</strong>
                    </div>
                    <div class="checkout-extra-km-actions">
                        <button type="button" class="checkout-extra-km-apply" data-cart-key="${cartKey}">
                            Apply
                        </button>
                        ${currentKm > 0 ? `<button type="button" class="checkout-extra-km-remove" data-cart-key="${cartKey}">Remove</button>` : ''}
                    </div>
                `);
                $('.checkout-item-extra-km[data-cart-key="' + cartKey + '"] .checkout-extra-km-count').text(currentKm.toLocaleString('en-US'));
            }

            function mutateCheckoutExtraKm(url, data, button) {
                button.prop('disabled', true);

                $.ajax({
                    url: url,
                    method: 'POST',
                    data: Object.assign({
                        _token: '{{ csrf_token() }}'
                    }, data),
                    success: function(response) {
                        if (!response.success) {
                            showCheckoutPromoError(response.message || 'Unable to update extra KM');
                            button.prop('disabled', false);
                            return;
                        }

                        updateCheckoutCartTotals(response.cart);
                        loadCheckoutExtraKm(data.cart_key, true);
                    },
                    error: function(xhr) {
                        showCheckoutPromoError(xhr.responseJSON?.message || 'Unable to update extra KM');
                        button.prop('disabled', false);
                    }
                });
            }

            $(document).on('click', '.checkout-toggle-extra-km', function() {
                const cartKey = $(this).data('cart-key');
                const panel = $('.checkout-extra-km-panel[data-cart-key="' + cartKey + '"]');
                panel.slideToggle(150);
                loadCheckoutExtraKm(cartKey, false);
            });

            $(document).on('input', '.checkout-extra-km-value', function() {
                const input = $(this);
                const cartKey = input.data('cart-key');
                const panel = $('.checkout-extra-km-panel[data-cart-key="' + cartKey + '"]');
                const rate = Number(panel.data('rate') || 0);
                const km = Number(input.val() || 0);
                panel.find('.checkout-extra-km-total-value').text(formatCheckoutAmount(km * rate));
            });

            $(document).on('click', '.checkout-extra-km-apply', function() {
                const button = $(this);
                const cartKey = button.data('cart-key');
                const km = Number($('.checkout-extra-km-value[data-cart-key="' + cartKey + '"]').val() || 0);

                mutateCheckoutExtraKm('{{ route('cart.extra-km.add') }}', {
                    cart_key: cartKey,
                    extra_km: km
                }, button);
            });

            $(document).on('click', '.checkout-extra-km-remove', function() {
                const button = $(this);
                mutateCheckoutExtraKm('{{ route('cart.extra-km.remove') }}', {
                    cart_key: button.data('cart-key')
                }, button);
            });

            function initializeCheckoutOptionalServices() {
                initializeCheckoutAddonAvailability();

                $('.checkout-item-extra-km').each(function() {
                    loadCheckoutExtraKm($(this).data('cart-key'), false);
                });
            }

            initializeCheckoutOptionalServices();

            function setCheckoutItemExpanded(item, expanded) {
                item.toggleClass('is-expanded', expanded).toggleClass('is-collapsed', !expanded);
                item.find('> .checkout-item-toggle')
                    .attr('aria-expanded', expanded ? 'true' : 'false')
                    .attr('title', expanded ? 'Hide booking details' : 'Show booking details')
                    .find('.checkout-item-toggle-label')
                    .text(expanded ? 'Less' : 'Details');
            }

            $(document).on('click', '.checkout-item-toggle', function() {
                const item = $(this).closest('.checkout-cart-item');
                const willExpand = !item.hasClass('is-expanded');

                if (willExpand) {
                    $('.checkout-cart-item.is-expanded').not(item).each(function() {
                        setCheckoutItemExpanded($(this), false);
                    });
                }

                setCheckoutItemExpanded(item, willExpand);
            });

            $(document).on('click', '.checkout-remove-item-btn', function() {
                if (!confirm('Remove this item from checkout?')) {
                    return;
                }

                const btn = $(this);
                const cartKey = btn.data('cart-key');
                btn.prop('disabled', true);
                btn.html('<i class="bi bi-hourglass-split"></i><span>Removing</span>');

                $.ajax({
                    url: '{{ route('cart.remove') }}',
                    method: 'POST',
                    data: {
                        _token: '{{ csrf_token() }}',
                        cart_key: cartKey
                    },
                    success: function(response) {
                        if (!response.success) {
                            showCheckoutPromoError(response.message || 'Error removing item');
                            btn.prop('disabled', false);
                            btn.html('<i class="bi bi-trash"></i><span>Remove</span>');
                            return;
                        }

                        $('.checkout-cart-item[data-cart-key="' + cartKey + '"]').slideUp(150, function() {
                            const removedExpandedItem = $(this).hasClass('is-expanded');
                            $(this).remove();
                            if (removedExpandedItem && !$('.checkout-cart-item.is-expanded').length) {
                                setCheckoutItemExpanded($('.checkout-cart-item').first(), true);
                            }
                            updateCheckoutCartTotals(response.cart);
                        });
                    },
                    error: function(xhr) {
                        showCheckoutPromoError(xhr.responseJSON?.message || 'Error removing item');
                        btn.prop('disabled', false);
                        btn.html('<i class="bi bi-trash"></i><span>Remove</span>');
                    }
                });
            });

            $(document).on('click', '.checkout-clear-cart-btn', function() {
                if (!confirm('Clear your entire cart?')) {
                    return;
                }

                const btn = $(this);
                btn.prop('disabled', true);
                btn.html('<i class="bi bi-hourglass-split"></i><span>Clearing</span>');

                $.ajax({
                    url: '{{ route('cart.clear') }}',
                    method: 'POST',
                    data: {
                        _token: '{{ csrf_token() }}'
                    },
                    success: function(response) {
                        if (response.success) {
                            checkoutRedirectHome();
                            return;
                        }

                        showCheckoutPromoError(response.message || 'Error clearing cart');
                        btn.prop('disabled', false);
                        btn.html('<i class="bi bi-trash"></i><span>Clear</span>');
                    },
                    error: function(xhr) {
                        showCheckoutPromoError(xhr.responseJSON?.message || 'Error clearing cart');
                        btn.prop('disabled', false);
                        btn.html('<i class="bi bi-trash"></i><span>Clear</span>');
                    }
                });
            });

            // Apply promo code button click
            $(document).on('click', '#apply-promo-checkout-btn', function() {
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
                            updateCheckoutCartTotals(response.cart);
                            renderCheckoutAppliedPromo(response.promo_code || promoCode);
                            showCheckoutPromoSuccess(response.message || 'Promo code applied!');
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
            $(document).on('keypress', '#checkout-promo-input', function(e) {
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
                            updateCheckoutCartTotals(response.cart);
                            renderCheckoutPromoInput();
                            showCheckoutPromoSuccess('Promo code removed');
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
            function renderCheckoutAppliedPromo(promoCode) {
                $('#checkout-promo-body').html(
                    '<div class="applied-promo-checkout">' +
                    '<div class="promo-badge-checkout">' +
                    '<i class="bi bi-check-circle-fill text-success"></i> ' +
                    '<span class="promo-code-value">' + checkoutEscapeHtml(promoCode) + '</span>' +
                    '<button type="button" class="remove-promo-checkout-btn" title="Remove promo code">' +
                    '<i class="bi bi-x-lg"></i>' +
                    '</button>' +
                    '</div>' +
                    '</div>'
                );
            }

            function renderCheckoutPromoInput() {
                $('#checkout-promo-body').html(
                    '<div class="promo-input-checkout">' +
                    '<input type="text" id="checkout-promo-input" placeholder="Enter code" autocomplete="off">' +
                    '<button type="button" id="apply-promo-checkout-btn" class="apply-promo-checkout-btn">' +
                    '<span class="btn-text">Apply</span>' +
                    '<span class="btn-loading" style="display: none;"><i class="bi bi-hourglass-split"></i></span>' +
                    '</button>' +
                    '</div>'
                );
            }

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
                    phoneInternationalField.value = e164Number; // Store E164 international format.

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
    <script src="{{ assetVersion('assets/js/checkout-payment-type.js') }}"></script>
@endpush
