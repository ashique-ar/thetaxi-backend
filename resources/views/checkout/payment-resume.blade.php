@extends('layouts.app')

@section('title', 'Complete Payment - TheTaxi')

@section('content')
    @php
        $currencySymbol = getCurrencySymbol($context['pricing']['currency'] ?? 'LKR');
        $booking = $paymentData['booking'];
        $context = $paymentData['context'];
        $amountDue = $paymentData['amount_due'];

        // Fallback for invalid or zero amounts
        if ($amountDue <= 0) {
            $amountDue = $context['pricing']['total_estimated'] ?? 1000; // Use booking total or default
        }
    @endphp

    <!-- Breadcrumb section -->
    <div class="breadcrumb-section"
        style="background-image:linear-gradient(rgba(0, 0, 0, 0.3), rgba(0, 0, 0, 0.3)), url({{ asset('assets/img/innerpages/breadcrumb-bg.jpg') }});">
        <div class="container">
            <div class="banner-content">
                <h1>Complete Your Payment</h1>
                <p class="subtitle">Secure Payment Processing for Your Booking</p>
            </div>
        </div>
    </div>
    <!-- End Breadcrumb section -->


    <!-- Email-style Payment Resume Page -->
    <div class="checkout-success pt-100 mb-100">
        <div class="container">
            <div class="row justify-content-center">
                <!-- Content -->
                <div class="col-lg-10 card p-4">
                    <!-- Greeting -->
                    <p class="greeting">
                        Dear <strong>{{ $context['customer']['name'] ?? 'Valued Customer' }}</strong>,
                    </p>

                    <p class="intro-text">
                        Your booking has been received and we're ready to confirm it. Please complete your payment below
                        to
                        secure your reservation.
                    </p>

                    @if ($booking)
                        <!-- Reference Box -->
                        <div class="reference-box">
                            <div class="reference-label">Booking Reference</div>
                            <div class="reference-number">{{ $context['booking_number'] }}</div>
                        </div>

                        <!-- Vehicle Wise Trip Details Section -->
                        <div class="section">
                            <h2 class="section-title">
                                <span class="icon">🚗</span> Vehicle Wise Trip Details
                            </h2>
                            @if (!empty($context['booking_items']))
                                @foreach ($context['booking_items'] as $index => $item)
                                    @php
                                        // Get vehicle group images if available from context
                                        $vehicleGroupImages = $item['vehicle_group_images'] ?? [];
                                        $defaultImage = null;
                                        if (!empty($vehicleGroupImages)) {
                                            $defaultImage = is_array($vehicleGroupImages[0])
                                                ? $vehicleGroupImages[0]['url'] ??
                                                    ($vehicleGroupImages[0]['path'] ?? null)
                                                : $vehicleGroupImages[0];
                                        }

                                        // Process addons data consistently
                                        $itemAddons = $item['addons'] ?? [];
                                        $addonsList = [];
                                        if (is_array($itemAddons)) {
                                            foreach ($itemAddons as $addon) {
                                                $addonName =
                                                    $addon['name'] ??
                                                    ($addon['label'] ?? ($addon['addon_name'] ?? 'Unknown Add-on'));
                                                $addonQty = max(1, (int) ($addon['quantity'] ?? ($addon['qty'] ?? 1)));
                                                $addonRate =
                                                    (float) ($addon['rate'] ??
                                                        ($addon['unit_price'] ??
                                                            ($addon['price'] ?? ($addon['amount'] ?? 0))));
                                                $addonTotal =
                                                    (float) ($addon['total_price'] ??
                                                        ($addon['total'] ??
                                                            ($addon['calculated_amount'] ??
                                                                ($addon['amount'] ?? $addonRate * $addonQty))));

                                                if ($addonName && ($addonTotal > 0 || $addonRate > 0)) {
                                                    $addonsList[] = [
                                                        'name' => $addonName,
                                                        'qty' => $addonQty,
                                                        'rate' => $addonRate,
                                                        'total' => $addonTotal,
                                                    ];
                                                }
                                            }
                                        }

                                        // Check for extra kilometers
                                        $extraKilometers = $item['extra_kilometers'] ?? ($item['extra_km'] ?? 0);
                                        $extraKmRate = $item['extra_km_rate'] ?? ($item['km_rate'] ?? 0);
                                        $extraKmTotal = $item['extra_km_total'] ?? $extraKilometers * $extraKmRate;
                                    @endphp
                                    <div class="booking-item-email">
                                        <div
                                            style="display: flex; align-items: flex-start; gap: 15px; margin-bottom: 15px;">
                                            @if ($defaultImage)
                                                <div style="flex-shrink: 0;">
                                                    <img src="{{ asset($defaultImage) }}" alt="{{ $item['vehicle_group'] }}"
                                                        style="width: 120px; height: 80px; object-fit: cover; border-radius: 6px; border: 1px solid #ddd;">
                                                </div>
                                            @endif
                                            <div style="flex: 1;">
                                                <div class="vehicle-header">
                                                    <h3>Vehicle {{ $index + 1 }}: {{ $item['vehicle_group'] }}</h3>
                                                    <span class="service-type-badge">{{ $item['service_type'] }}</span>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="trip-details">
                                            <div class="location-info">
                                                <div class="pickup-info">
                                                    <span class="location-label">Pickup:</span>
                                                    <span class="location-details">
                                                        {{ \Carbon\Carbon::parse($item['from_date'])->format('M d, Y') }}
                                                        at
                                                        {{ $item['from_time'] ?? '00:00' }}
                                                        <br>{{ $item['pickup_location']['address'] ?? 'N/A' }}
                                                    </span>
                                                </div>
                                                <div class="dropoff-info">
                                                    <span class="location-label">Return:</span>
                                                    <span class="location-details">
                                                        {{ \Carbon\Carbon::parse($item['to_date'])->format('M d, Y') }}
                                                        at
                                                        {{ $item['to_time'] ?? '00:00' }}
                                                        <br>{{ $item['dropoff_location']['address'] ?? 'N/A' }}
                                                    </span>
                                                </div>
                                            </div>
                                            <div class="duration-info">
                                                <span class="duration-badge">{{ $item['duration_days'] ?? 'N/A' }}
                                                    Days</span>
                                            </div>
                                        </div>

                                        @php
                                            $oneWayPrice = $item['one_way_price'] ?? null;
                                            $returnPrice = $item['return_price'] ?? null;
                                            $returnDiscount = $item['return_discount_percentage'] ?? 0;
                                        @endphp

                                        @if (!empty($oneWayPrice) || !empty($returnPrice))
                                            <div class="return-pricing" style="margin-top:12px; padding-top:10px; border-top:1px solid #eee;">
                                                <div style="display:flex; justify-content:space-between; padding:4px 0;">
                                                    <div>Outbound Trip</div>
                                                    <div>{{ $currencySymbol }} {{ number_format((float) ($oneWayPrice ?? 0), 2) }}</div>
                                                </div>
                                                <div style="display:flex; justify-content:space-between; padding:4px 0;">
                                                    <div>Return Trip @if(!empty($returnDiscount) && $returnDiscount > 0) <small class="text-success">({{ $returnDiscount }}% off)</small>@endif</div>
                                                    <div>{{ $currencySymbol }} {{ number_format((float) ($returnPrice ?? 0), 2) }}</div>
                                                </div>
                                                <div style="display:flex; justify-content:space-between; font-weight:700; padding-top:6px; border-top:1px dashed #eee; margin-top:6px;">
                                                    <div>Combined</div>
                                                    <div>{{ $currencySymbol }} {{ number_format((float) (($oneWayPrice ?? 0) + ($returnPrice ?? 0)), 2) }}</div>
                                                </div>
                                            </div>
                                        @endif

                                        {{-- Selected Addons & Extra KM for context items (arrays) --}}
                                        @if (!empty($addonsList))
                                            <div class="addons-section"
                                                style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #eee;">
                                                <h4 style="margin: 0 0 10px 0; color: #333; font-size: 14px;">Selected
                                                    Add-ons:</h4>
                                                @foreach ($addonsList as $addon)
                                                    <div
                                                        style="display: flex; justify-content: space-between; align-items: center; padding: 5px 0; border-bottom: 1px solid #f0f0f0;">
                                                        <span>
                                                            <strong>{{ $addon['name'] }}</strong>
                                                            <small style="color: #777; margin-left: 4px;">
                                                                (Qty: {{ $addon['qty'] }}@if ($addon['rate'] > 0)
                                                                    ×
                                                                    {{ $currencySymbol }}{{ number_format($addon['rate'], 2) }}
                                                                @elseif($addon['total'] > 0 && $addon['qty'] > 0)
                                                                    - Avg:
                                                                    {{ $currencySymbol }}{{ number_format($addon['total'] / $addon['qty'], 2) }}
                                                                @endif)
                                                            </small>
                                                        </span>
                                                        <span
                                                            style="color: #BF2629; font-weight: 600;">{{ $currencySymbol }}{{ number_format($addon['total'], 2) }}</span>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif

                                        @if ($extraKilometers > 0)
                                            <div class="extra-km-section"
                                                style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #eee;">
                                                <h4 style="margin: 0 0 10px 0; color: #333; font-size: 14px;">Extra
                                                    Kilometers:</h4>
                                                <div
                                                    style="display: flex; justify-content: space-between; align-items: center; padding: 5px 0;">
                                                    <span>
                                                        <strong>{{ number_format($extraKilometers) }} km</strong>
                                                        @if ($extraKmRate > 0)
                                                            <small style="color: #777;"> @
                                                                {{ $currencySymbol }}{{ number_format($extraKmRate, 2) }}/km</small>
                                                        @endif
                                                    </span>
                                                    @if ($extraKmTotal > 0)
                                                        <span
                                                            style="color: #BF2629; font-weight: 600;">{{ $currencySymbol }}{{ number_format($extraKmTotal, 2) }}</span>
                                                    @endif
                                                </div>
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            @endif

                            @php
                                // Show addon/extra km totals if available in pricing context
                                $pricing = $context['pricing'] ?? [];
                                $contextAddonCharges = (float) ($pricing['addon_charges'] ?? 0);
                                $contextExtraKmCharges = (float) ($pricing['extra_km_charges'] ?? 0);
                            @endphp

                            @if ($contextAddonCharges > 0 || $contextExtraKmCharges > 0)
                                <div class="section" style="margin-top:12px;">
                                    <h2 class="section-title"><span class="icon">➕</span> Additional Charges</h2>
                                    <table class="info-table">
                                        @if ($contextAddonCharges > 0)
                                            <tr>
                                                <td>Addon Charges</td>
                                                <td>{{ $currencySymbol }} {{ number_format($contextAddonCharges, 2) }}
                                                </td>
                                            </tr>
                                        @endif
                                        @if ($contextExtraKmCharges > 0)
                                            <tr>
                                                <td>Extra KM Charges</td>
                                                <td>{{ $currencySymbol }} {{ number_format($contextExtraKmCharges, 2) }}
                                                </td>
                                            </tr>
                                        @endif
                                    </table>
                                </div>
                            @endif
                        </div>

                        <!-- Customer Information Section -->
                        <div class="section">
                            <h2 class="section-title">
                                <span class="icon">👤</span> Customer Information
                            </h2>
                            <table class="info-table">
                                <tr>
                                    <td>Name</td>
                                    <td>{{ $context['customer']['name'] }}</td>
                                </tr>
                                <tr>
                                    <td>Email</td>
                                    <td>{{ $context['customer']['email'] }}</td>
                                </tr>
                                <tr>
                                    <td>Phone</td>
                                    <td>{{ $context['customer']['phone'] }}</td>
                                </tr>
                                @if (!empty($booking->customer->address))
                                    <tr>
                                        <td>Address</td>
                                        <td>{{ $booking->customer->address }}</td>
                                    </tr>
                                @endif
                                @if (!empty($booking->customer->city))
                                    <tr>
                                        <td>City</td>
                                        <td>{{ $booking->customer->city }}</td>
                                    </tr>
                                @endif
                                @if (!empty($booking->customer->country))
                                    <tr>
                                        <td>Country</td>
                                        <td>{{ $booking->customer->country }}</td>
                                    </tr>
                                @endif
                            </table>
                        </div>

                        <!-- Payment Summary Section -->
                        <div class="section">
                            <h2 class="section-title">
                                <span class="icon">💳</span> Payment Summary
                            </h2>
                            <table class="info-table">
                                <tr>
                                    <td>Base Amount</td>
                                    <td>{{ $currencySymbol }}
                                        {{ number_format($context['pricing']['base_amount'], 2) }}
                                    </td>
                                </tr>
                                @if ($context['pricing']['service_fee'] > 0)
                                    <tr>
                                        <td>Service Fee</td>
                                        <td>{{ $currencySymbol }}
                                            {{ number_format($context['pricing']['service_fee'], 2) }}</td>
                                    </tr>
                                @endif
                                @if ($context['pricing']['tax_amount'] > 0)
                                    <tr>
                                        <td>Tax</td>
                                        <td>{{ $currencySymbol }}
                                            {{ number_format($context['pricing']['tax_amount'], 2) }}</td>
                                    </tr>
                                @endif
                                @if (($context['pricing']['vat_amount'] ?? 0) > 0)
                                    <tr>
                                        <td>VAT</td>
                                        <td>{{ $currencySymbol }}
                                            {{ number_format($context['pricing']['vat_amount'], 2) }}</td>
                                    </tr>
                                @endif
                                @if ($context['pricing']['discount_amount'] > 0)
                                    <tr style="color: #16a34a;">
                                        <td>Discount</td>
                                        <td>-{{ $currencySymbol }}
                                            {{ number_format($context['pricing']['discount_amount'], 2) }}</td>
                                    </tr>
                                @endif
                                <tr class="price-total">
                                    <td>Total Booking Amount</td>
                                    <td>{{ $currencySymbol }}
                                        {{ number_format($context['pricing']['total_estimated'], 2) }}</td>
                                </tr>
                                @if ($context['pricing']['amount_paid'] > 0)
                                    <tr style="background: #eff6ff;">
                                        <td><strong>Amount Already Paid</strong></td>
                                        <td><strong>{{ $currencySymbol }}
                                                {{ number_format($context['pricing']['amount_paid'], 2) }}</strong>
                                        </td>
                                    </tr>
                                @endif
                                <tr style="background: #fef3c7;">
                                    <td><strong>Amount Due Today</strong></td>
                                    <td><strong>{{ $currencySymbol }} {{ number_format($amountDue, 2) }}</strong></td>
                                </tr>
                                <tr>
                                    <td>Payment Status</td>
                                    <td>
                                        <span class="status-badge status-{{ $context['payment_status'] }}">
                                            {{ ucfirst(str_replace('_', ' ', $context['payment_status'])) }}
                                        </span>
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <div class="divider"></div>

                        <!-- Payment Instructions Section -->
                        <div class="section">
                            <h2 class="section-title">
                                <span class="icon">🔒</span> Complete Your Payment
                            </h2>

                            <div class="highlight-box warning">
                                <h3>⏳ Payment Required to Confirm Booking</h3>
                                <p>Your booking is currently
                                    <strong>{{ ucfirst(str_replace('_', ' ', $context['payment_status'])) }}</strong>.
                                    Complete your payment now to secure your reservation.
                                </p>

                                <div class="payment-benefits">
                                    <p style="margin-bottom: 8px;"><strong>Why pay now?</strong></p>
                                    <ul>
                                        <li>Instant booking confirmation</li>
                                        <li>Vehicle and driver assignment</li>
                                        <li>24/7 customer support access</li>
                                        <li>Secure payment processing</li>
                                    </ul>
                                </div>
                            </div>

                            <!-- Payment Form -->
                            <form id="payment-resume-form" method="POST"
                                action="{{ route('checkout.process-payment-resume') }}">
                                @csrf
                                <input type="hidden" name="payment_token" value="{{ $token }}">
                                <input type="hidden" name="booking_id" value="{{ $booking->id }}">
                                <input type="hidden" name="amount" value="{{ $amountDue }}">

                                <!-- Payment CTA -->
                                <div class="btn-container" style="margin: 30px 0;">
                                    <button type="submit" class="btn">
                                        🔒 Pay {{ $currencySymbol }} {{ number_format($amountDue, 2) }} with WebXPay
                                    </button>
                                    <p style="text-align: center; color: #717171; font-size: 13px; margin: 10px 0;">
                                        <span style="color: #28a745;">✓ Secure SSL Encryption</span> •
                                        <span style="color: #28a745;">✓ All Major Cards Accepted</span> •
                                        <span style="color: #28a745;">✓ Instant Confirmation</span>
                                    </p>
                                </div>
                            </form>
                        </div>

                        <!-- Need Assistance Section -->
                        <div class="section">
                            <h2 class="section-title">
                                <span class="icon">📞</span> Need Assistance?
                            </h2>
                            <p style="color: #555; margin-bottom: 15px;">If you have any questions about your payment or
                                booking, please contact us:</p>
                            <table class="info-table">
                                <tr>
                                    <td>📞 Phone</td>
                                    <td><a href="tel:+94711615615" style="color: #BF2629; text-decoration: none;">+94 11
                                            234
                                            5678</a></td>
                                </tr>
                                <tr>
                                    <td>📧 Email</td>
                                    <td><a href="mailto:{{ config('mail.from.address', 'bookings@casonsrentacar.lk') }}"
                                            style="color: #BF2629; text-decoration: none;">{{ config('mail.from.address', 'bookings@casonsrentacar.lk') }}</a>
                                    </td>
                                </tr>
                                <tr>
                                    <td>🌐 Website</td>
                                    <td><a href="{{ config('app.url') }}"
                                            style="color: #BF2629; text-decoration: none;">{{ config('app.url') }}</a>
                                    </td>
                                </tr>
                                <tr>
                                    <td>💬 WhatsApp</td>
                                    <td><a href="https://wa.me/9471615615"
                                            style="color: #BF2629; text-decoration: none;">+94 71 1 615 615</a></td>
                                </tr>
                            </table>
                            <p style="text-align: center; color: #717171; margin-top: 15px; font-size: 13px;">Our
                                customer
                                support team is available 24/7 to assist you.</p>
                        </div>

                        <!-- Alternative Actions -->
                        <div class="btn-container">
                            <a href="{{ route('home') }}" class="btn btn-secondary">Visit Our Website</a>
                        </div>

                        <p style="text-align: center; color: #555; font-size: 15px;">Thank you for choosing
                            {{ env('COMPANY_NAME', 'TheTaxi Company') }}. We look forward to serving you!</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection

@push('styles')
    <style>
        /* Content */
        .email-content {
            padding: 40px 35px;
        }

        .greeting {
            font-size: 16px;
            color: #333333;
            margin-bottom: 20px;
            line-height: 1.6;
        }

        .greeting strong {
            color: #BF2629;
        }

        .intro-text {
            font-size: 15px;
            color: #555555;
            line-height: 1.7;
            margin-bottom: 25px;
        }

        /* Reference Box */
        .reference-box {
            background: linear-gradient(135deg, #fef5f5 0%, #fff8f8 100%);
            border: 1px solid rgba(191, 38, 41, 0.15);
            border-left: 4px solid #BF2629;
            border-radius: 8px;
            padding: 20px 24px;
            margin: 25px 0;
        }

        .reference-label {
            font-size: 12px;
            color: #717171;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 8px;
        }

        .reference-number {
            font-size: 22px;
            font-weight: 700;
            color: #BF2629;
            letter-spacing: 1px;
        }

        /* Section */
        .section {
            margin-bottom: 30px;
        }

        .section-title {
            font-size: 17px;
            font-weight: 600;
            color: #BF2629;
            margin-bottom: 16px;
            padding-bottom: 10px;
            border-bottom: 2px solid #BF2629;
            display: flex;
            align-items: center;
        }

        .section-title .icon {
            margin-right: 10px;
        }

        /* Booking Item Email */
        .booking-item-email {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 15px;
            border-left: 4px solid #BF2629;
        }

        .vehicle-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .vehicle-header h3 {
            margin: 0;
            color: #BF2629;
            font-size: 16px;
        }

        .service-type-badge {
            display: inline-block;
            background: #e3f2fd;
            color: #1976d2;
            padding: 4px 8px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 12px;
            vertical-align: middle;
        }

        .trip-details {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .location-info {
            flex: 1;
        }

        .pickup-info,
        .dropoff-info {
            margin-bottom: 10px;
        }

        .location-label {
            font-weight: 600;
            color: #717171;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
            display: block;
            margin-bottom: 4px;
        }

        .location-details {
            color: #333333;
            font-size: 14px;
            line-height: 1.4;
        }

        .duration-info {
            text-align: right;
        }

        .duration-badge {
            background: #e5e7eb;
            color: #374151;
            padding: 6px 12px;
            border-radius: 16px;
            font-size: 12px;
            font-weight: 600;
        }

        /* Info Table */
        .info-table {
            width: 100%;
            border-collapse: collapse;
        }

        .info-table tr {
            border-bottom: 1px solid #eef0f2;
        }

        .info-table tr:last-child {
            border-bottom: none;
        }

        .info-table td {
            padding: 12px 0;
            font-size: 14px;
            vertical-align: top;
        }

        .info-table td:first-child {
            font-weight: 600;
            color: #717171;
            width: 40%;
            padding-right: 15px;
        }

        .info-table td:last-child {
            color: #333333;
        }

        /* Highlight Box */
        .highlight-box {
            background-color: #f8f9fa;
            border-left: 4px solid #BF2629;
            border-radius: 0 8px 8px 0;
            padding: 20px 24px;
            margin: 20px 0;
        }

        .highlight-box.warning {
            background-color: #fffbeb;
            border-left-color: #f59e0b;
        }

        .highlight-box h3 {
            margin: 0 0 12px 0;
            font-size: 16px;
            font-weight: 600;
        }

        .highlight-box.warning h3 {
            color: #d97706;
        }

        .highlight-box p {
            margin: 8px 0;
            font-size: 14px;
            color: #555555;
            line-height: 1.6;
        }

        .payment-benefits ul {
            margin: 12px 0 0 0;
            padding-left: 20px;
        }

        .payment-benefits li {
            font-size: 14px;
            color: #555555;
            margin-bottom: 6px;
            line-height: 1.5;
        }

        /* Button */
        .btn-container {
            text-align: center;
            margin: 30px 0;
        }

        .btn {
            display: inline-block;
            padding: 14px 32px;
            background: linear-gradient(135deg, #BF2629 0%, #a02123 100%);
            color: #ffffff !important;
            text-decoration: none;
            font-size: 15px;
            font-weight: 600;
            border-radius: 8px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(191, 38, 41, 0.25);
            border: none;
            cursor: pointer;
        }

        .btn:hover {
            background: linear-gradient(135deg, #a02123 0%, #8f1d1f 100%);
        }

        .btn-secondary {
            background: #717171;
            box-shadow: 0 4px 12px rgba(113, 113, 113, 0.25);
        }

        /* Status Badge */
        .status-badge {
            display: inline-block;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
        }

        .status-pending {
            background-color: #fef3c7;
            color: #d97706;
        }

        .status-partial {
            background-color: #dbeafe;
            color: #2563eb;
        }

        /* Divider */
        .divider {
            height: 1px;
            background: linear-gradient(to right, transparent, #e5e7eb, transparent);
            margin: 30px 0;
        }

        /* Footer */
        .email-footer {
            background: linear-gradient(135deg, #f8f9fa 0%, #f1f2f4 100%);
            padding: 30px 35px;
            text-align: center;
            border-top: 1px solid #eef0f2;
        }

        .footer-brand {
            margin-bottom: 20px;
        }

        .footer-brand img {
            max-height: 32px;
            opacity: 0.8;
        }

        .footer-text {
            font-size: 13px;
            color: #717171;
            margin: 0;
            line-height: 1.6;
        }

        .footer-text strong {
            color: #BF2629;
        }

        .footer-links {
            margin: 15px 0;
        }

        .footer-links a {
            color: #717171;
            text-decoration: none;
            font-size: 13px;
            margin: 0 10px;
        }

        .footer-links a:hover {
            color: #BF2629;
        }

        .copyright {
            font-size: 12px;
            color: #999999;
            margin-top: 15px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .email-wrapper {
                padding: 20px 10px;
            }

            .email-content {
                padding: 30px 20px;
            }

            .email-header {
                padding: 30px 20px;
            }

            .email-header h1 {
                font-size: 22px;
            }

            .reference-number {
                font-size: 18px;
            }

            .btn {
                padding: 12px 24px;
                font-size: 14px;
            }

            .trip-details {
                flex-direction: column;
            }

            .duration-info {
                text-align: left;
                margin-top: 15px;
            }
        }
    </style>
@endpush

@push('scripts')
    <script>
        $(document).ready(function() {
            // Copy booking reference to clipboard on click
            $('.reference-number').on('click', function() {
                const referenceNumber = $(this).text().trim();

                if (navigator.clipboard) {
                    navigator.clipboard.writeText(referenceNumber).then(function() {
                        // Show temporary tooltip
                        const $ref = $('.reference-number');
                        const originalText = $ref.text();
                        $ref.html('<i class="bi bi-check-circle-fill text-success"></i> Copied!');

                        setTimeout(function() {
                            $ref.text(originalText);
                        }, 2000);
                    }).catch(function(err) {
                        console.error('Failed to copy:', err);
                    });
                }
            });

            // Add cursor pointer and title to booking reference
            $('.reference-number').css('cursor', 'pointer').attr('title', 'Click to copy reference number');

            // Form submission handler
            $('#payment-resume-form').on('submit', function(e) {
                const submitBtn = $(this).find('button[type="submit"]');
                const originalText = submitBtn.html();

                // Show loading state
                submitBtn.prop('disabled', true);
                submitBtn.html('<i class="bi bi-arrow-repeat spin"></i> Processing Payment...');

                // Re-enable after 10 seconds as fallback
                setTimeout(() => {
                    submitBtn.prop('disabled', false);
                    submitBtn.html(originalText);
                }, 10000);
            });
        });
    </script>
@endpush
