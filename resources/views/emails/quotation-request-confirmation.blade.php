@extends('emails.layouts.master')

@section('title', 'Quotation Request Received - ' . config('app.name'))

@section('header_title', 'Quotation Request Received')

@section('header_subtitle', 'Your request is being processed')

@section('content')
    @php
        use App\Helpers\BookingLinkHelper;
        use App\Models\Booking\Booking;

        $booking = $booking ?? null;

        // Get the quotation booking for this inquiry to generate checkout link
        $quotationBooking =
            isset($booking) && $booking instanceof Booking
                ? $booking
                : (isset($inquiryNumber)
                    ? Booking::where('booking_number', $inquiryNumber)->first()
                    : null);
        $checkoutLink = $quotationBooking ? BookingLinkHelper::getQuotationCheckoutLink($quotationBooking) : null;
    @endphp

    <!-- Greeting -->
    <p class="greeting">
        Dear <strong>{{ $customerName }}</strong>,
    </p>

    <p class="intro-text">
        Your quotation request for <strong>{{ $vehicleGroup->name }}</strong> has been successfully received and is being
        processed.
    </p>

    <!-- Reference Box -->
    <div class="reference-box">
        <div class="reference-label">Reference Number</div>
        <div class="reference-number">{{ $inquiryNumber }}</div>
    </div>

    <!-- Success Message -->
    <div class="highlight-box success">
        <h3>✓ Request Submitted Successfully</h3>
        <p style="margin-bottom: 0;">Our transport specialists are reviewing your requirements and will prepare a customized
            quote for you.</p>
    </div>

    <!-- Customer Information -->
    <div class="section">
        <h2 class="section-title">
            <span class="icon">👤</span> Contact Information
        </h2>
        <table class="info-table">
            <tr>
                <td>Name</td>
                <td>{{ $customerName }}</td>
            </tr>
            @if (isset($customerEmail))
                <tr>
                    <td>Email</td>
                    <td>{{ $customerEmail }}</td>
                </tr>
            @endif
            @if (isset($customerPhone))
                <tr>
                    <td>Phone</td>
                    <td>{{ $customerPhone }}</td>
                </tr>
            @endif
            @if (isset($customerAddress) && !empty($customerAddress))
                <tr>
                    <td>Address</td>
                    <td>{{ $customerAddress }}</td>
                </tr>
            @endif
            @if (isset($customerCity) && !empty($customerCity))
                <tr>
                    <td>City</td>
                    <td>{{ $customerCity }}</td>
                </tr>
            @endif
            @if (isset($customerCountry) && !empty($customerCountry))
                <tr>
                    <td>Country</td>
                    <td>{{ $customerCountry }}</td>
                </tr>
            @endif
        </table>
    </div>

    <!-- Request Details Section -->
    <div class="section">
        <h2 class="section-title">
            <span class="icon">📋</span> Your Request Details
        </h2>
        @php
            $currencySymbol =
                isset($booking) && $booking instanceof \App\Models\Booking\Booking
                    ? getCurrencySymbol($booking->currency)
                    : getCurrencySymbol('LKR');
        @endphp

        @if (isset($booking) && $booking instanceof \App\Models\Booking\Booking && $booking->bookingItems->count() > 0)
            <!-- Vehicle Wise Trip Details for Multi-Item Bookings -->
            <h3 style="margin-top: 0; color: #333; font-size: 15px; margin-bottom: 15px;">Vehicle Wise Trip Details</h3>
            @foreach ($booking->bookingItems as $index => $item)
                <x-booking-item-email :item="$item" :index="$index" :currencySymbol="$currencySymbol" />
            @endforeach
        @endif

        @php
            $paymentType = 'quotation';
            $serviceParams = collect();
            if ($booking instanceof \App\Models\Booking\Booking) {
                foreach ($booking->bookingItems as $bi) {
                    $serviceParams->push($bi->service_type ?? ($bi->serviceType?->id ?? null));
                }
                if ($booking->serviceType?->id) {
                    $serviceParams->push($booking->serviceType->id);
                }
            } elseif (!empty($requestData['service_type'])) {
                $serviceParams->push($requestData['service_type']);
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
    </div>

    <div class="divider"></div>

    <!-- What Happens Next Section -->
    <div class="section">
        <h2 class="section-title">
            <span class="icon">⏱️</span> What Happens Next
        </h2>
        <div class="highlight-box">
            <p><strong>1. Review Process:</strong> Our transport specialists are reviewing your requirements</p>
            <p><strong>2. Route Analysis:</strong> We're calculating the optimal route and pricing for your journey</p>
            <p><strong>3. Custom Quote:</strong> A detailed quotation will be prepared specifically for your needs</p>
            <p style="margin-bottom: 0;"><strong>4. Direct Contact:</strong> Our team will contact you directly with the
                quotation</p>
        </div>
    </div>

    <!-- Expected Response Time -->
    <div class="highlight-box info">
        <h3>📞 Expected Response Time</h3>
        <p style="font-size: 18px; font-weight: 600; color: #2563eb; margin: 10px 0;">{{ $estimatedResponseTime }}</p>
        <p style="margin-bottom: 0;">Our corporate transport team will contact you within this timeframe with a detailed
            quotation.</p>
    </div>

    @if ($checkoutLink)
        <!-- Quick Checkout Option -->
        <div class="section">
            <h2 class="section-title">
                <span class="icon">⚡</span> Ready to Proceed?
            </h2>
            <p style="color: #555; margin-bottom: 15px;">If you'd like to proceed with this booking now without waiting for
                our quotation, you can proceed directly to payment with the same vehicle and dates pre-filled.</p>

            <div class="btn-container">
                <a href="{{ $checkoutLink }}" class="btn"
                    style="display: inline-block; background-color: #15803d; background-image: linear-gradient(135deg, #15803d 0%, #166534 100%); box-shadow: 0 4px 12px rgba(21, 128, 61, 0.24); color: #FFFFFF; padding: 15px 40px; text-decoration: none; border-radius: 8px; font-weight: bold; font-size: 16px;">
                    Book Now
                </a>
                <p style="text-align: center; color: #717171; font-size: 13px; margin: 10px 0;">
                    <span style="color: #28a745;">✓ Secure Payment</span> •
                    <span style="color: #28a745;">✓ Instant Booking</span> •
                    <span style="color: #28a745;">✓ Email Confirmation</span>
                </p>
            </div>

            <p style="text-align: center; color: #717171; font-size: 13px; margin-top: 10px;">
                If the button doesn't work, copy and paste:<br>
                <a href="{{ $checkoutLink }}"
                    style="color: #BF2629; text-decoration: underline; word-break: break-all;">{{ $checkoutLink }}</a>
            </p>
        </div>
    @endif

    <!-- Why Request a Quotation Section -->
    <div class="section">
        <h2 class="section-title">
            <span class="icon">🚗</span> Why Request a Quotation?
        </h2>
        <p style="color: #555; margin-bottom: 15px;">You're receiving a custom quotation because:</p>
        <ul style="color: #555; padding-left: 20px; margin: 0;">
            <li style="margin-bottom: 8px;">Your journey requires specialized routing or pricing</li>
            <li style="margin-bottom: 8px;">The service involves unique requirements or locations</li>
            <li style="margin-bottom: 8px;">We want to ensure you receive the most accurate pricing</li>
            <li>Our team can optimize the service for your specific needs</li>
        </ul>
    </div>

    <div class="divider"></div>

    <!-- Need Immediate Assistance Section -->
    <div class="section">
        <h2 class="section-title">
            <span class="icon">📞</span> Need Immediate Assistance?
        </h2>
        <p style="color: #555; margin-bottom: 15px;">If you have any questions or need to modify your request, contact us:
        </p>
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
        <p style="text-align: center; color: #717171; margin-top: 15px; font-size: 13px; font-style: italic;">
            Please reference your inquiry number: <strong style="color: #BF2629;">{{ $inquiryNumber }}</strong>
        </p>
    </div>

    <!-- About Section -->
    <div class="section">
        <h2 class="section-title">
            <span class="icon">🌟</span> About {{ env('COMPANY_NAME', 'TheTaxi Company') }}
        </h2>
        <p style="color: #555; line-height: 1.7; margin: 0;">
            We specialize in providing reliable, professional transport solutions for businesses and individuals. Our fleet
            of well-maintained vehicles and experienced drivers ensure comfortable and punctual service for all your
            transport needs.
        </p>
    </div>

    <p style="text-align: center; color: #555; font-size: 15px; margin-top: 30px;">Thank you for choosing
        {{ env('COMPANY_NAME', 'TheTaxi Company') }} for your transport needs!</p>
@endsection
