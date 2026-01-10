@extends('emails.layouts.master')

@section('title', 'Booking Confirmation - ' . config('app.name'))

@section('header_title')
    @if ($booking->payment_status === 'paid')
        Booking Confirmed!
    @elseif($booking->status === 'quotation_requested')
        Quotation Request Received
    @else
        Booking Received
    @endif
@endsection

@section('header_subtitle', 'Your Premium Transport Experience Awaits')

@section('content')
    @php
        $currencySymbol = getCurrencySymbol($booking->currency);
        $workflowData = is_string($booking->workflow_data)
            ? json_decode($booking->workflow_data, true)
            : $booking->workflow_data ?? [];
        $cartItems = $workflowData['cart_items'] ?? [];
        $pickupLocation = is_string($booking->pickup_location)
            ? json_decode($booking->pickup_location, true)
            : $booking->pickup_location;
        $dropoffLocation = is_string($booking->dropoff_location)
            ? json_decode($booking->dropoff_location, true)
            : $booking->dropoff_location;
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

    <!-- Greeting -->
    <p class="greeting">
        Dear <strong>{{ $booking->customer->full_name ?? 'Valued Customer' }}</strong>,
    </p>

    <p class="intro-text">
        @if ($booking->payment_status === 'paid')
            Thank you for your booking with {{ env('COMPANY_NAME', 'TheTaxi Company') }}! Your reservation has been
            confirmed and we're excited to serve you.
        @elseif($booking->status === 'quotation_requested')
            Thank you for your quotation request. Our team will review your requirements and get back to you within 24
            hours.
        @else
            Thank you for your booking with {{ env('COMPANY_NAME', 'TheTaxi Company') }}! We have received your
            reservation request and will process it shortly.
        @endif
    </p>

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
            @if ($booking->customer->identification ?? null)
                <tr>
                    <td>Identification</td>
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
                    <td>-{{ $currencySymbol }} {{ number_format($booking->discount_amount, 2) }}</td>
                </tr>
            @endif
            <tr class="price-total">
                <td>Total Amount</td>
                <td>{{ $currencySymbol }} {{ number_format($booking->total_estimated, 2) }}</td>
            </tr>
            @if ($booking->payment_type === 'advance')
                <tr style="background: #eff6ff;">
                    <td><strong>Amount Paid ({{ $advancePercentage }}%)</strong></td>
                    <td><strong>{{ $currencySymbol }} {{ number_format($booking->amount_to_pay ?? 0, 2) }}</strong></td>
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
                            WebXPay Secure Gateway
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
                        <span class="status-badge status-processing">{{ ucfirst($booking->payment_status) }}</span>
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
            <p style="color: #555; line-height: 1.6; margin: 0;">{{ $booking->special_requirements }}</p>
        </div>
    @endif

    @if (!empty($workflowData['flight_details']))
        <div class="section">
            <h2 class="section-title">
                <span class="icon">✈️</span> Flight Information
            </h2>
            @php $flight = $workflowData['flight_details']; @endphp
            <table class="info-table">
                <tr>
                    <td>Airline</td>
                    <td>{{ $flight['airline'] ?? 'N/A' }}</td>
                </tr>
                <tr>
                    <td>Flight Number</td>
                    <td>{{ $flight['flight_number'] ?? 'N/A' }}</td>
                </tr>
                <tr>
                    <td>Arrival Date</td>
                    <td>{{ $flight['arrival_date'] ?? 'N/A' }}</td>
                </tr>
                <tr>
                    <td>Arrival Time</td>
                    <td>{{ $flight['arrival_time'] ?? 'N/A' }}</td>
                </tr>
            </table>
        </div>
    @endif

    <div class="divider"></div>

    <!-- What's Next Section -->
    <div class="section">
        <h2 class="section-title">
            <span class="icon">📌</span> What's Next?
        </h2>

        @if ($booking->status === 'quotation_requested')
            <div class="highlight-box info">
                <h3>Your Request is Being Processed</h3>
                <p><strong>Step 1:</strong> Our team will review your quotation request</p>
                <p><strong>Step 2:</strong> We'll contact you at
                    <strong>{{ $booking->customer->phone ?? 'your provided number' }}</strong> within 24 hours
                </p>
                <p><strong>Step 3:</strong> You'll receive a detailed quote with vehicle options and pricing</p>
                <p style="margin-bottom: 0;"><strong>Step 4:</strong> Once approved, we'll send a secure payment link to
                    confirm your booking</p>
            </div>
        @elseif($booking->payment_status === 'pending')
            @php
                $paymentLink = \App\Helpers\BookingLinkHelper::getPaymentLink($booking);
                $isFallbackPaymentLink = $paymentLink === route('checkout');
            @endphp
            <div class="highlight-box warning">
                <h3>⏳ Complete Your Payment</h3>
                <p>Click the button below to securely complete your payment online using WebXPay:</p>

                <!-- Payment Link CTA -->
                <div class="btn-container" style="margin: 20px 0;">
                    @if (!$isFallbackPaymentLink)
                        <a href="{{ $paymentLink }}" class="btn"
                            style="display: inline-block; background-color: #BF2629; color: #FFFFFF; padding: 15px 40px; text-decoration: none; border-radius: 8px; font-weight: bold; font-size: 16px;">
                            🔒 Pay
                            {{ $currencySymbol }}
                            {{ number_format($booking->amount_to_pay ?? $booking->total_estimated, 2) }}
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
                            <p style="font-weight:600;">We couldn't generate a secure direct payment link for this booking.
                            </p>
                            <p>Please <a href="mailto:{{ config('mail.from.address', 'bookings@casonsrentacar.lk') }}"
                                    style="color:#BF2629; text-decoration:none;">contact support</a> or visit our <a
                                    href="{{ route('checkout') }}" style="color:#BF2629; text-decoration:none;">checkout
                                    page</a> to complete your payment.</p>
                        </div>
                    @endif
                </div>

                <p style="margin-bottom: 0; font-style: italic; font-size: 13px;">Secure payment powered by WebXPay. Your
                    booking will be confirmed immediately after successful payment.</p>
            </div>
        @elseif($booking->payment_type === 'advance')
            <div class="highlight-box success">
                <h3>✓ Payment Confirmed!</h3>
                <p>You have successfully paid {{ $advancePercentage }}% advance
                    ({{ $currencySymbol }} {{ number_format($booking->amount_to_pay ?? 0, 2) }}).</p>
                <p><strong>Balance Due at Pickup:</strong>
                    {{ $currencySymbol }}
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
        <p style="color: #555; margin-bottom: 15px;">If you have any questions about your booking, please contact us:</p>
        <table class="info-table">
            <tr>
                <td>📞 Phone</td>
                <td><a href="tel:+94112345678" style="color: #BF2629; text-decoration: none;">+94 11 234 5678</a></td>
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
                        style="color: #BF2629; text-decoration: none;">{{ config('app.url') }}</a></td>
            </tr>
            <tr>
                <td>💬 WhatsApp</td>
                <td><a href="https://wa.me/94712345678" style="color: #BF2629; text-decoration: none;">+94 71 234 5678</a>
                </td>
            </tr>
        </table>
        <p style="text-align: center; color: #717171; margin-top: 15px; font-size: 13px;">Our customer support team is
            available 24/7 to assist you.</p>
    </div>

    <!-- CTA Button -->
    <div class="btn-container">
        @if ($booking->payment_status === 'pending')
            @php
                $paymentLink = \App\Helpers\BookingLinkHelper::getPaymentLink($booking);
                $isFallbackPaymentLink = $paymentLink === route('checkout');
            @endphp
            @if (!$isFallbackPaymentLink)
                <a href="{{ $paymentLink }}" class="btn">Complete Payment -
                    {{ $currencySymbol }}
                    {{ number_format($booking->amount_to_pay ?? $booking->total_estimated - ($booking->amount_paid ?? 0), 2) }}</a>
            @else
                <a href="mailto:{{ config('mail.from.address', 'bookings@casonsrentacar.lk') }}" class="btn">Contact
                    Support to Complete Payment</a>
            @endif
        @elseif($booking->payment_status === 'paid')
            <a href="{{ route('home') }}" class="btn">Visit Our Website</a>
        @else
            <a href="{{ route('home') }}" class="btn">Visit Our Website</a>
        @endif
    </div>

    <p style="text-align: center; color: #555; font-size: 15px;">Thank you for choosing
        {{ env('COMPANY_NAME', 'TheTaxi Company') }}. We look forward to serving you!</p>
@endsection
