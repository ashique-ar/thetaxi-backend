@extends('layouts.app')

@section('title', 'Booking Confirmation - TheTaxi')

@section('content')
    @php
        $isQuotation = $type === 'quotation';
        $isPaid = $booking && $booking->payment_status === 'paid';
        $isPending = $booking && $booking->payment_status === 'pending';
        $currencySymbol = $booking ? getCurrencySymbol($booking->currency) : '$';
        $advancePercentage = \App\Models\Website\WebsiteSetting::getValue(
            'advance_payment_percentage',
            config('booking.advance_payment.percentage', 50),
        );
        $taxRateSetting = \App\Models\Website\WebsiteSetting::getValue(
            'tax_rate',
            \App\Models\Website\WebsiteSetting::getValue('tax_percentage', config('booking.tax.rate', 2.5)),
        );
        $vatRateSetting = \App\Models\Website\WebsiteSetting::getValue(
            'vat_rate',
            \App\Models\Website\WebsiteSetting::getValue('vat_percentage', config('booking.vat.rate', 18)),
        );
        $taxRateDisplay =
            $taxRateSetting > 0 && $taxRateSetting <= 1 ? round($taxRateSetting * 100, 2) : $taxRateSetting;
        $vatRateDisplay =
            $vatRateSetting > 0 && $vatRateSetting <= 1 ? round($vatRateSetting * 100, 2) : $vatRateSetting;
    @endphp

    <!-- Breadcrumb section -->
    <div class="breadcrumb-section"
        style="background-image:linear-gradient(rgba(0, 0, 0, 0.3), rgba(0, 0, 0, 0.3)), url({{ asset('assets/img/innerpages/breadcrumb-bg.jpg') }});">
        <div class="container">
            <div class="banner-content">
                @if ($isQuotation)
                    {{-- <div class="success-icon quotation">
                        <i class="bi bi-file-text-fill"></i>
                    </div> --}}
                    <h1>Quotation Request Submitted!</h1>
                    <p class="lead">Thank you for your interest. Our team will review your request and send you a detailed
                        quotation within 24 hours.</p>
                @elseif($isPaid)
                    {{-- <div class="success-icon paid">
                        <i class="bi bi-check-circle-fill"></i>
                    </div> --}}
                    <h1>Payment Successful!</h1>
                    <p class="lead">Your booking has been confirmed. You will receive a confirmation email shortly.</p>
                @else
                    {{-- <div class="success-icon pending">
                        <i class="bi bi-clock-fill"></i>
                    </div> --}}
                    <h1>Booking Received!</h1>
                    <p class="lead">Your booking has been received. Please complete the payment to confirm your
                        reservation.</p>
                @endif
            </div>
        </div>
    </div>
    <!-- End Breadcrumb section -->


    <!-- Email-style Success Page -->
    <div class="checkout-success pt-100 mb-100">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-lg-10 card p-4">
                    <!-- Content -->
                    <div class="email-content">
                        <!-- Greeting -->
                        <p class="greeting">
                            Dear <strong>{{ $booking->customer->full_name ?? 'Valued Customer' }}</strong>,
                        </p>

                        <p class="intro-text">
                            @if ($isQuotation)
                                Thank you for your quotation request. Our team will review your requirements and get back to
                                you
                                within 24 hours.
                            @elseif($isPaid)
                                Thank you for your booking with {{ env('COMPANY_NAME', 'TheTaxi Company') }}! Your
                                reservation
                                has been confirmed and we're excited to serve you.
                            @else
                                Thank you for your booking with {{ env('COMPANY_NAME', 'TheTaxi Company') }}! We have
                                received
                                your reservation request and will process it shortly.
                            @endif
                        </p>

                        @if ($booking)
                            <!-- Reference Box -->
                            <div class="reference-box">
                                <div class="reference-label">Booking Reference</div>
                                <div class="reference-number">{{ $booking->booking_number }}</div>
                            </div>

                            <!-- Vehicle Wise Trip Details Section -->
                            <div class="section">
                                <h2 class="section-title">
                                    <span class="icon">🚗</span> Vehicle Wise Trip Details
                                </h2>
                                @if ($booking->bookingItems->count() > 0)
                                    @foreach ($booking->bookingItems as $index => $item)
                                        <x-booking-item-email :item="$item" :index="$index" :currencySymbol="$currencySymbol" />
                                    @endforeach
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
                                        <td>{{ $booking->customer?->user?->full_name ?? 'N/A' }}</td>
                                    </tr>
                                    <tr>
                                        <td>Email</td>
                                        <td>{{ $booking->customer?->user?->email ?? 'N/A' }}</td>
                                    </tr>
                                    <tr>
                                        <td>Phone</td>
                                        <td>{{ $booking->customer?->user?->phone ?? 'N/A' }}</td>
                                    </tr>
                                    @if ($booking->customer->identification ?? null)
                                        <tr>
                                            <td>ID Type</td>
                                            <td>{{ $booking->customer->identification }}</td>
                                        </tr>
                                    @endif
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
                                        <td>Subtotal</td>
                                        <td>{{ $currencySymbol }} {{ number_format($booking->base_amount, 2) }}</td>
                                    </tr>
                                    @if ($booking->service_fee > 0)
                                        <tr>
                                            <td>Service Fee</td>
                                            <td>{{ $currencySymbol }} {{ number_format($booking->service_fee, 2) }}</td>
                                        </tr>
                                    @endif
                                    @if ($booking->tax_amount > 0)
                                        <tr>
                                            <td>{{ config('booking.tax.label', 'NBT') }} ({{ $taxRateDisplay }}%)</td>
                                            <td>{{ $currencySymbol }} {{ number_format($booking->tax_amount, 2) }}</td>
                                        </tr>
                                    @endif
                                    @if (($booking->vat_amount ?? 0) > 0)
                                        <tr>
                                            <td>{{ config('booking.vat.label', 'VAT') }} ({{ $vatRateDisplay }}%)</td>
                                            <td>{{ $currencySymbol }} {{ number_format($booking->vat_amount, 2) }}</td>
                                        </tr>
                                    @endif
                                    @if ($booking->discount_amount > 0)
                                        <tr style="color: #16a34a;">
                                            <td>Discount</td>
                                            <td>-{{ $currencySymbol }} {{ number_format($booking->discount_amount, 2) }}
                                            </td>
                                        </tr>
                                    @endif

                                    @php
                                        // Addon charges (persisted booking addons)
                                        $addonCharges = (float) ($booking->bookingAddons->sum('amount') ?? 0);

                                        // Extra km charges: try booking addons flagged as mileage, fallback to workflow cart data
                                        $extraKmCharges =
                                            (float) ($booking->bookingAddons->where('is_milage', true)->sum('amount') ??
                                                0);
                                        if (empty($extraKmCharges)) {
                                            $workflow = is_string($booking->workflow_data)
                                                ? json_decode($booking->workflow_data, true)
                                                : $booking->workflow_data ?? [];
                                            $extraKmCharges = 0;
                                            foreach ($workflow['cart_items'] ?? [] as $ci) {
                                                $extraKmCharges += (float) ($ci['extra_km']['total_cost'] ?? 0);
                                            }
                                        }
                                    @endphp

                                    @if ($addonCharges > 0)
                                        <tr>
                                            <td>Addon Charges</td>
                                            <td>{{ $currencySymbol }} {{ number_format($addonCharges, 2) }}</td>
                                        </tr>
                                    @endif

                                    @if ($extraKmCharges > 0)
                                        <tr>
                                            <td>Extra KM Charges</td>
                                            <td>{{ $currencySymbol }} {{ number_format($extraKmCharges, 2) }}</td>
                                        </tr>
                                    @endif

                                    <tr class="price-total">
                                        <td>Total Amount</td>
                                        <td>{{ $currencySymbol }} {{ number_format($booking->total_estimated, 2) }}</td>
                                    </tr>
                                    @if ($booking->payment_type === 'advance')
                                        <tr style="background: #eff6ff;">
                                            <td><strong>Amount Paid ({{ $advancePercentage }}%)</strong></td>
                                            <td><strong>{{ $currencySymbol }}
                                                    {{ number_format($booking->amount_to_pay ?? 0, 2) }}</strong></td>
                                        </tr>
                                        <tr style="background: #eff6ff;">
                                            <td>Balance Due at Pickup</td>
                                            <td>{{ $currencySymbol }}
                                                {{ number_format($booking->total_estimated - ($booking->amount_to_pay ?? 0), 2) }}
                                            </td>
                                        </tr>
                                    @elseif($booking->payment_status === 'paid')
                                        <tr style="background: #f0fdf4;">
                                            <td><strong>Amount Paid</strong></td>
                                            <td><strong>{{ $currencySymbol }}
                                                    {{ number_format($booking->amount_to_pay ?? $booking->total_estimated, 2) }}</strong>
                                            </td>
                                        </tr>
                                    @endif
                                    <tr>
                                        <td>Payment Method</td>
                                        <td>
                                            @switch($booking->payment_method)
                                                @case('webxpay')
                                                @case('online')
                                                    Pay Online
                                                @break

                                                @case('bank_transfer')
                                                    Bank Transfer
                                                @break

                                                @case('online_banking')
                                                    Online Banking
                                                @break

                                                @default
                                                    {{ ucfirst(str_replace('_', ' ', $booking->payment_method ?? 'N/A')) }}
                                                @break
                                            @endswitch
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>Payment Status</td>
                                        <td>
                                            @if ($booking->payment_status === 'paid')
                                                <span class="status-badge status-paid">✓ Paid</span>
                                            @elseif($booking->payment_status === 'pending')
                                                <span class="status-badge status-pending">⏳ Pending</span>
                                            @else
                                                <span
                                                    class="status-badge status-processing">{{ ucfirst($booking->payment_status) }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                </table>
                            </div>

                            @if ($booking->special_requirements)
                                <div class="section">
                                    <h2 class="section-title">
                                        <span class="icon">📋</span> Special Requirements
                                    </h2>
                                    <p style="color: #555; line-height: 1.6; margin: 0;">
                                        {{ $booking->special_requirements }}
                                    </p>
                                </div>
                            @endif

                            <div class="divider"></div>

                            <!-- What's Next Section -->
                            <div class="section">
                                <h2 class="section-title">
                                    <span class="icon">📌</span> What's Next?
                                </h2>

                                @if ($isQuotation)
                                    <div class="highlight-box info">
                                        <h3>Your Request is Being Processed</h3>
                                        <p><strong>Step 1:</strong> Our team will review your quotation request</p>
                                        <p><strong>Step 2:</strong> We'll contact you at
                                            <strong>{{ $booking->customer->phone ?? 'your provided number' }}</strong>
                                            within
                                            24 hours
                                        </p>
                                        <p><strong>Step 3:</strong> You'll receive a detailed quote with vehicle options and
                                            pricing</p>
                                        <p style="margin-bottom: 0;"><strong>Step 4:</strong> Once approved, we'll send a
                                            secure
                                            payment link to confirm your booking</p>
                                    </div>
                                @elseif($isPending)
                                    @php
                                        $paymentLink = \App\Helpers\BookingLinkHelper::getPaymentLink($booking);
                                        $isFallbackPaymentLink = $paymentLink === route('checkout');
                                    @endphp
                                    <div class="highlight-box warning">
                                        <h3>⏳ Complete Your Payment</h3>
                                        <p>Click the button below to securely complete your payment online using WebXPay:
                                        </p>

                                        <!-- Payment Link CTA -->
                                        <div class="btn-container" style="margin: 20px 0;">
                                            @if (!$isFallbackPaymentLink)
                                                <a href="{{ $paymentLink }}" class="btn">
                                                    🔒 Pay {{ $currencySymbol }}
                                                    {{ number_format($booking->amount_to_pay ?? $booking->total_estimated, 2) }}
                                                    with WebXPay
                                                </a>
                                                <p
                                                    style="text-align: center; color: #717171; font-size: 13px; margin: 10px 0;">
                                                    <span style="color: #28a745;">✓ Secure SSL Encryption</span> •
                                                    <span style="color: #28a745;">✓ All Major Cards Accepted</span> •
                                                    <span style="color: #28a745;">✓ Instant Confirmation</span>
                                                </p>
                                            @else
                                                <div class="highlight-box warning" style="text-align:center;">
                                                    <p style="font-weight:600;">We couldn't generate a secure direct payment
                                                        link for this booking.</p>
                                                    <p>Please <a
                                                            href="mailto:{{ config('mail.from.address', 'bookings@casonsrentacar.lk') }}"
                                                            style="color:#BF2629; text-decoration:none;">contact support</a>
                                                        or
                                                        visit our <a href="{{ route('checkout') }}"
                                                            style="color:#BF2629; text-decoration:none;">checkout page</a>
                                                        to
                                                        complete your payment.</p>
                                                </div>
                                            @endif
                                        </div>

                                        <p style="margin-bottom: 0; font-style: italic; font-size: 13px;">Secure payment
                                            powered
                                            by WebXPay. Your booking will be confirmed immediately after successful payment.
                                        </p>
                                    </div>
                                @elseif($booking->payment_type === 'advance')
                                    <div class="highlight-box success">
                                        <h3>✓ Payment Confirmed!</h3>
                                        <p>You have successfully paid {{ $advancePercentage }}% advance
                                            ({{ $currencySymbol }}
                                            {{ number_format($booking->amount_to_pay ?? 0, 2) }}).</p>
                                        <p><strong>Balance Due at Pickup:</strong> {{ $currencySymbol }}
                                            {{ number_format($booking->total_estimated - ($booking->amount_to_pay ?? 0), 2) }}
                                        </p>
                                        <p style="margin-bottom: 8px;"><strong>Important Reminders:</strong></p>
                                        <ul>
                                            <li>Bring valid government-issued ID/Passport</li>
                                            <li>Bring a valid driver's license</li>
                                            <li>A credit card may be required for security deposit</li>
                                            <li>Arrive 15 minutes before scheduled pickup time</li>
                                        </ul>
                                    </div>
                                @else
                                    <div class="highlight-box success">
                                        <h3>✓ Your Booking is Confirmed!</h3>
                                        <p>Your vehicle will be prepared and ready for pickup on
                                            <strong>{{ \Carbon\Carbon::parse($booking->from_date)->format('F d, Y \a\t g:i A') }}</strong>.
                                        </p>
                                        <p style="margin-bottom: 8px;"><strong>Important Reminders:</strong></p>
                                        <ul>
                                            <li>Bring valid government-issued ID/Passport</li>
                                            <li>Bring a valid driver's license</li>
                                            <li>A credit card may be required for security deposit</li>
                                            <li>Arrive 15 minutes before scheduled pickup time</li>
                                        </ul>
                                    </div>
                                @endif
                            </div>

                            <!-- Need Assistance Section -->
                            <div class="section">
                                <h2 class="section-title">
                                    <span class="icon">📞</span> Need Assistance?
                                </h2>
                                <p style="color: #555; margin-bottom: 15px;">If you have any questions about your booking,
                                    please contact us:</p>
                                <table class="info-table">
                                    <tr>
                                        <td>📞 Phone</td>
                                        <td><a href="tel:+94112345678" style="color: #BF2629; text-decoration: none;">+94 11
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
                                        <td><a href="https://wa.me/94712345678"
                                                style="color: #BF2629; text-decoration: none;">+94 71 234 5678</a></td>
                                    </tr>
                                </table>
                                <p style="text-align: center; color: #717171; margin-top: 15px; font-size: 13px;">Our
                                    customer
                                    support team is available 24/7 to assist you.</p>
                            </div>

                            <!-- CTA Button -->
                            <div class="btn-container">
                                @if ($isPending)
                                    @php
                                        $paymentLink = \App\Helpers\BookingLinkHelper::getPaymentLink($booking);
                                        $isFallbackPaymentLink = $paymentLink === route('checkout');
                                    @endphp
                                    @if (!$isFallbackPaymentLink)
                                        <a href="{{ $paymentLink }}" class="btn">Complete Payment -
                                            {{ $currencySymbol }}
                                            {{ number_format($booking->amount_to_pay ?? $booking->total_estimated - ($booking->amount_paid ?? 0), 2) }}</a>
                                    @else
                                        <a href="mailto:{{ config('mail.from.address', 'bookings@casonsrentacar.lk') }}"
                                            class="btn">Contact Support to Complete Payment</a>
                                    @endif
                                @elseif($isPaid)
                                    <a href="{{ route('home') }}" class="btn">Visit Our Website</a>
                                @else
                                    <a href="{{ route('home') }}" class="btn">Visit Our Website</a>
                                @endif
                            </div>

                            <p style="text-align: center; color: #555; font-size: 15px;">Thank you for choosing
                                {{ env('COMPANY_NAME', 'TheTaxi Company') }}. We look forward to serving you!</p>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('styles')
    <style>
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

        .highlight-box.success {
            background-color: #f0fdf4;
            border-left-color: #22c55e;
        }

        .highlight-box.warning {
            background-color: #fffbeb;
            border-left-color: #f59e0b;
        }

        .highlight-box.info {
            background-color: #eff6ff;
            border-left-color: #3b82f6;
        }

        .highlight-box h3 {
            margin: 0 0 12px 0;
            font-size: 16px;
            font-weight: 600;
        }

        .highlight-box.success h3 {
            color: #16a34a;
        }

        .highlight-box.warning h3 {
            color: #d97706;
        }

        .highlight-box.info h3 {
            color: #2563eb;
        }

        .highlight-box p {
            margin: 8px 0;
            font-size: 14px;
            color: #555555;
            line-height: 1.6;
        }

        .highlight-box ul {
            margin: 12px 0 0 0;
            padding-left: 20px;
        }

        .highlight-box li {
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

        .status-paid {
            background-color: #dcfce7;
            color: #16a34a;
        }

        .status-pending {
            background-color: #fef3c7;
            color: #d97706;
        }

        .status-processing {
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
        });
    </script>
@endpush
