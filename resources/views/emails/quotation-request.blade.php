@extends('emails.layouts.master')

@section('title', 'Quotation Request - ' . config('app.name'))

@section('header_title', 'Quotation Request Received')

@section('header_subtitle', 'Thank you for your interest')

@section('content')
    @php
        use App\Helpers\BookingLinkHelper;
        $workflowData = is_string($booking->workflow_data)
            ? json_decode($booking->workflow_data, true)
            : $booking->workflow_data ?? [];
        $cartItems = $workflowData['cart_items'] ?? [];
        $pickupLocation = is_string($booking->pickup_location)
            ? json_decode($booking->pickup_location, true)
            : $booking->pickup_location;
        $contactTime = match ($booking->contact_time ?? null) {
            'morning' => 'Morning (9 AM - 12 PM)',
            'afternoon' => 'Afternoon (12 PM - 5 PM)',
            'evening' => 'Evening (5 PM - 8 PM)',
            'anytime' => 'Anytime',
            default => 'Not specified',
        };
        // $budgetRange = match ($workflowData['budget_range'] ?? null) {
        //     'under-500' => 'Under $500',
        //     '500-1000' => '$500 - $1,000',
        //     '1000-2000' => '$1,000 - $2,000',
        //     'over-2000' => 'Over $2,000',
        //     default => 'Not specified',
        // };
        $checkoutLink = BookingLinkHelper::getQuotationCheckoutLink($booking);
    @endphp

    <!-- Greeting -->
    <p class="greeting">
        Dear <strong>{{ $booking->customer->full_name ?? 'Valued Customer' }}</strong>,
    </p>

    <p class="intro-text">
        Thank you for submitting a quotation request to {{ env('COMPANY_NAME', 'TheTaxi Company') }}. We have received
        your inquiry and will get back to you shortly.
    </p>

    <!-- Reference Box -->
    <div class="reference-box">
        <div class="reference-label">Quotation Reference</div>
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
                <td>{{ $booking->customer?->full_name ?? 'N/A' }}</td>
            </tr>
            <tr>
                <td>Email</td>
                <td>{{ $booking->customer?->email ?? 'N/A' }}</td>
            </tr>
            <tr>
                <td>Phone</td>
                <td>{{ $booking->customer?->phone ?? 'N/A' }}</td>
            </tr>
            @if ($booking->customer?->identification ?? null)
                <tr>
                    <td>Identification</td>
                    <td>{{ $booking->customer->identification }}</td>
                </tr>
            @endif
            @if (!empty($booking->customer?->address))
                <tr>
                    <td>Address</td>
                    <td>{{ $booking->customer->address }}</td>
                </tr>
            @endif
            @if (!empty($booking->customer?->city))
                <tr>
                    <td>City</td>
                    <td>{{ $booking->customer->city }}</td>
                </tr>
            @endif
            @if (!empty($booking->customer?->country))
                <tr>
                    <td>Country</td>
                    <td>{{ $booking->customer->country }}</td>
                </tr>
            @endif
            <tr>
                <td>Preferred Contact Time</td>
                <td>{{ $contactTime }}</td>
            </tr>
        </table>
    </div>

    <!-- Vehicles Wise Trip Details Section -->
    <div class="section">
        <h2 class="section-title">
            <span class="icon">🚗</span> Vehicle Wise Trip Details
        </h2>
        @php
            $currencySymbol = getCurrencySymbol($booking->currency ?? 'LKR');
        @endphp
        @if ($booking->bookingItems->count() > 0)
            @foreach ($booking->bookingItems as $index => $item)
                <x-booking-item-email :item="$item" :index="$index" :currencySymbol="$currencySymbol" />
            @endforeach
        @endif
    </div>

    @if ($booking->special_requirements)
        <div class="section">
            <h2 class="section-title">
                <span class="icon">📝</span> Special Requirements
            </h2>
            <p style="color: #555; line-height: 1.6; margin: 0;">{{ $booking->special_requirements }}</p>
        </div>
    @endif

    @if (!empty($workflowData['flight_details']))
        <div class="section">
            <h2 class="section-title">
                <span class="icon">✈️</span> Flight Information
            </h2>
            @php $flight = $workflowData['flight_details'] ?? []; @endphp
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

    @php
        $paymentType = 'quotation';
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
                <h4 style="margin-top:8px;">{{ $t->title }}</h4>
                <div style="color:#555;text-align: left;">{!! $t->content !!}</div>
            @endforeach
        </div>
    @endif

    <div class="divider"></div>

    <!-- Next Steps Section -->
    <div class="section">
        <h2 class="section-title">
            <span class="icon">📌</span> Next Steps
        </h2>
        <div class="highlight-box info">
            <h3>What Happens Now?</h3>
            <p style="margin-bottom: 0;">Our team will review your quotation request and contact you within 24 hours with a
                detailed quote and options tailored to your needs.</p>
        </div>
    </div>

    <!-- Quick Checkout Option -->
    <div class="section">
        <h2 class="section-title">
            <span class="icon">⚡</span> Accept & Proceed to Payment
        </h2>
        <p style="color: #555; margin-bottom: 15px;">If you're ready to proceed with the same vehicle and dates now, you can
            click the button below to proceed directly to payment. All your previously selected options will be pre-filled.
        </p>

        <div class="btn-container">
            <a href="{{ $checkoutLink }}" class="btn"
                style="display: inline-block; background-color: #BF2629; color: #FFFFFF; padding: 12px 30px; text-decoration: none; border-radius: 5px; font-weight: bold;">
                ✓ Accept Quotation & Proceed to Payment
            </a>
        </div>

        <p style="text-align: center; color: #717171; font-size: 13px; margin-top: 10px;">
            If the button doesn't work, copy and paste this link:<br>
            <a href="{{ $checkoutLink }}"
                style="color: #BF2629; text-decoration: underline; word-break: break-all;">{{ $checkoutLink }}</a>
        </p>
    </div>

    <p style="text-align: center; color: #555; font-size: 15px; margin-top: 30px;">Thank you for choosing
        {{ env('COMPANY_NAME', 'TheTaxi Company') }}. We look forward to serving you!</p>
@endsection
