@extends('emails.layouts.master')

@section('title', 'Payment Initiated - ' . config('app.name'))

@section('header_title', 'Payment Initiated')

@section('header_subtitle', 'Complete your payment to confirm your booking')

@section('content')
    @php
        $currencySymbol = getCurrencySymbol($booking->currency ?? 'LKR');
        $customerName = $booking->customer?->full_name ?? 'Valued Customer';
        $paymentLink = \App\Helpers\BookingLinkHelper::getPaymentLink($booking);
        $isFallbackPaymentLink = $paymentLink === route('checkout');
    @endphp

    <!-- Greeting -->
    <p class="greeting">
        Dear <strong>{{ $customerName }}</strong>,
    </p>

    <p class="intro-text">
        Your payment request has been initiated. Please complete the payment process to confirm your booking.
    </p>

    <!-- Reference Box -->
    <div class="reference-box">
        <div class="reference-label">Booking Reference</div>
        <div class="reference-number">{{ $booking->booking_number }}</div>
    </div>

    <!-- Customer Information Section -->
    <div class="section">
        <h2 class="section-title">
            <span class="icon">👤</span> Customer Information
        </h2>
        <table class="info-table">
            <tr>
                <td>Name</td>
                <td>{{ $booking->customer->full_name ?? 'N/A' }}</td>
            </tr>
            <tr>
                <td>Email</td>
                <td>{{ $booking->customer->email ?? 'N/A' }}</td>
            </tr>
            <tr>
                <td>Phone</td>
                <td>{{ $booking->customer->phone ?? 'N/A' }}</td>
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

    <!-- Booking Details Section -->
    <div class="section">
        <h2 class="section-title">
            <span class="icon">🚗</span> Trip Details
        </h2>
        @if ($booking->bookingItems->count() > 0)
            @foreach ($booking->bookingItems as $index => $item)
                <x-booking-item-email :item="$item" :index="$index" :currencySymbol="$currencySymbol" />
            @endforeach
        @endif
    </div>

    <!-- Payment Details Section -->
    <div class="section">
        <h2 class="section-title">
            <span class="icon">💳</span> Payment Details
        </h2>
        <table class="info-table">
            <tr class="price-total">
                <td>Amount to Pay</td>
                <td>{{ $currencySymbol }} {{ number_format($amount, 2) }}</td>
            </tr>
            <tr>
                <td>Payment Type</td>
                <td>{{ ucfirst($booking->payment_type ?? 'full') }}</td>
            </tr>
            <tr>
                <td>Payment Method</td>
                <td>WebXPay Secure Gateway</td>
            </tr>
            <tr>
                <td>Status</td>
                <td><span class="status-badge status-pending">⏳ Pending Completion</span></td>
            </tr>
        </table>
    </div>

    @php
        $paymentType = $booking->payment_type ?? null;
        $serviceParams = collect();
        foreach ($booking->bookingItems as $bi) {
            $serviceParams->push($bi->service_type ?? ($bi->serviceType?->id ?? null));
        }
        if ($booking->serviceType?->id) {
            $serviceParams->push($booking->serviceType->id);
        }
        $serviceParams = $serviceParams->filter()->unique()->values();

        $applicableTerms = collect();
        if ($serviceParams->count()) {
            foreach ($serviceParams as $s) {
                $applicableTerms = $applicableTerms->merge(
                    \App\Models\TermsAndCondition::getForCheckout($s, $paymentType),
                );
            }
        } else {
            $applicableTerms = \App\Models\TermsAndCondition::getForCheckout(null, $paymentType);
        }
        $applicableTerms = $applicableTerms->unique('id')->values();
    @endphp

    @if ($applicableTerms->count())
        <div class="section">
            <h2 class="section-title"><span class="icon">📜</span> Terms & Conditions</h2>
            @foreach ($applicableTerms as $t)
                <h4 style="margin-top:8px;">{{ $t->title }} @if ($t->service_name)
                        <small class="text-muted">({{ $t->service_name }})</small>
                    @endif
                </h4>
                <div style="color:#555;text-align: left;">{!! $t->content !!}</div>
            @endforeach
        </div>
    @endif

    <div class="divider"></div>

    <!-- Action Required Section with Payment Button -->
    <div class="highlight-box warning">
        <h3>⏳ Action Required</h3>
        <p>Please complete your payment as soon as possible to secure your booking. Your reservation will be confirmed once
            the payment is successfully processed.</p>
    </div>

    <!-- WebXPay Payment CTA -->
    <div class="btn-container">
        @if (!$isFallbackPaymentLink)
            <a href="{{ $paymentLink }}" class="btn"
                style="display: inline-block; background-color: #15803d; background-image: linear-gradient(135deg, #15803d 0%, #166534 100%); box-shadow: 0 4px 12px rgba(21, 128, 61, 0.24); color: #FFFFFF; padding: 15px 40px; text-decoration: none; border-radius: 8px; font-weight: bold; margin: 20px 0; font-size: 16px;">
                🔒 Pay {{ $currencySymbol }} {{ number_format($amount, 2) }} with WebXPay
            </a>
            <p style="text-align: center; color: #717171; font-size: 13px; margin: 10px 0;">
                <span style="color: #28a745;">✓ Secure SSL Encryption</span> •
                <span style="color: #28a745;">✓ All Major Cards Accepted</span> •
                <span style="color: #28a745;">✓ Instant Confirmation</span>
            </p>

            <p style="text-align: center; color: #717171; font-size: 13px; margin: 15px 0;">
                If the button above doesn't work, copy and paste this link in your browser:<br>
                <a href="{{ $paymentLink }}"
                    style="color: #BF2629; text-decoration: underline; word-break: break-all;">{{ $paymentLink }}</a>
            </p>
        @else
            <div class="highlight-box warning" style="text-align:center;">
                <p style="font-weight:600;">We couldn't generate a secure direct payment link for this booking.</p>
                <p>Please <a href="mailto:{{ $supportEmail }}" style="color:#BF2629; text-decoration:none;">contact
                        support</a> or visit our <a href="{{ route('checkout') }}"
                        style="color:#BF2629; text-decoration:none;">checkout page</a> to complete your payment.</p>
            </div>
        @endif
    </div>

    <!-- Need Assistance Section -->
    <div class="section">
        <h2 class="section-title">
            <span class="icon">📞</span> Need Assistance?
        </h2>
        <table class="info-table">
            <tr>
                <td>📧 Email</td>
                <td><a href="mailto:{{ $supportEmail }}"
                        style="color: #BF2629; text-decoration: none;">{{ $supportEmail }}</a></td>
            </tr>
            <tr>
                <td>📞 Phone</td>
                <td><a href="tel:{{ $supportPhone }}"
                        style="color: #BF2629; text-decoration: none;">{{ $supportPhone }}</a></td>
            </tr>
        </table>
        <p style="text-align: center; color: #717171; margin-top: 15px; font-size: 13px;">Our customer support team is
            available 24/7 to assist you.</p>
    </div>
@endsection
