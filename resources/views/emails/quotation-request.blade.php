@extends('emails.layouts.master')

@section('title', 'Quotation Request - ' . config('app.name'))

@section('header_title', $staffRequested ? 'Your Quotation Is Ready' : 'Quotation Request Received')

@section('header_subtitle', $staffRequested ? 'Please review your offer' : 'Thank you for your interest')

@section('content')
    @php
        $currencyCode = getBookingDisplayCurrency($booking);
        $workflowData = is_string($booking->workflow_data)
            ? json_decode($booking->workflow_data, true)
            : $booking->workflow_data ?? [];
        $checkoutContact = (array) ($workflowData['checkout_contact'] ?? []);
        $customerName = trim((string) ($checkoutContact['first_name'] ?? '') . ' ' . (string) ($checkoutContact['last_name'] ?? ''))
            ?: ($booking->customer?->full_name ?: 'Valued Customer');
        $cartItems = $workflowData['cart_items'] ?? [];
        $quotationOptions = $workflowData['quotation_options'] ?? [];
        $tripOptionGroups = \App\Helpers\BookingLinkHelper::quotationTripOptionGroups($quotationOptions);
        $quotationDisplaySnapshot = count($quotationOptions) > 1
            ? \App\Helpers\BookingLinkHelper::quotationDisplaySnapshot($booking)
            : [];
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
    @endphp

    <!-- Greeting -->
    <p class="greeting">
        Dear <strong>{{ $customerName }}</strong>,
    </p>

    <p class="intro-text">
        @if ($staffRequested)
            Your quotation from {{ ($settings['company_name'] ?? $settings['brand_name'] ?? $settings['site_name'] ?? 'Company') }} is ready. Review the details below and contact us if you need any changes.
        @else
            Thank you for submitting a quotation request to {{ ($settings['company_name'] ?? $settings['brand_name'] ?? $settings['site_name'] ?? 'Company') }}. We have received your inquiry and our team will review it and contact you with an offer.
        @endif
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
                <td>{{ $customerName }}</td>
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

    @if ($staffRequested && count($quotationOptions) > 1)
        <div class="section">
            <h2 class="section-title"><span class="icon">🚘</span> Vehicle choices for each trip</h2>
            <p>Choose one vehicle for each trip on the quotation page. The total will update to match your selections.</p>
            @foreach ($tripOptionGroups as $tripNumber => $choices)
                <div class="highlight-box info" style="margin-bottom:10px;">
                    @php $trip = $quotationDisplaySnapshot['items'][$tripNumber - 1] ?? []; @endphp
                    <strong>Trip {{ $tripNumber }} — {{ $trip['service_name'] ?? 'Trip' }}</strong>
                    @if (!empty($trip['from_date']))
                        <div>{{ \Carbon\Carbon::parse($trip['from_date'])->format('M j, Y') }} {{ $trip['from_time'] ?? '' }} — {{ !empty($trip['to_date']) ? \Carbon\Carbon::parse($trip['to_date'])->format('M j, Y') : '' }} {{ $trip['to_time'] ?? '' }}</div>
                    @endif
                    @if (!empty($trip['route']))<div>{{ $trip['route'] }}</div>@endif
                    @foreach ($choices as $choice)
                        <table role="presentation" style="margin-top:5px; border-collapse:collapse;">
                            <tr>
                                <td style="padding-right:8px; vertical-align:middle;"><img src="{{ $choice['image'] }}" alt="{{ $choice['vehicle_group_name'] }}" width="80" height="54" style="display:block; width:80px; height:54px; object-fit:cover; border-radius:4px;"></td>
                                <td style="vertical-align:middle;">{{ $choice['vehicle_group_name'] }} — {{ number_format($choice['price'], 2) }} {{ $currencyCode }}</td>
                            </tr>
                        </table>
                    @endforeach
                </div>
            @endforeach
        </div>
    @endif

    <!-- Booking item details -->
    @if (count($quotationOptions) < 2)
    <div class="section">
        <h2 class="section-title">
            <span class="icon">{{ $booking->bookingItems->contains(fn ($item) => filled(data_get($item->metadata, 'tour_itinerary_id'))) ? '🗺️' : '🚗' }}</span>
            {{ $booking->bookingItems->contains(fn ($item) => filled(data_get($item->metadata, 'tour_itinerary_id'))) ? 'Tour itinerary and booking details' : 'Vehicle Wise Trip Details' }}
        </h2>
        @php
            // Keep all persisted checkout amounts paired with their snapshot currency.
            $currencyCode = getBookingDisplayCurrency($booking);
        @endphp
        @if ($booking->bookingItems->count() > 0)
            @foreach ($booking->bookingItems as $index => $item)
                <x-booking-item-email :item="$item" :index="$index" :currency="$currencyCode" />
            @endforeach
        @endif
    </div>
    @endif

    <!-- Payment Summary Section -->
    @if (count($quotationOptions) < 2)
    <div class="section">
        <h2 class="section-title">
            <span class="icon">💳</span> Quotation Summary
        </h2>
        <x-booking-payment-summary :booking="$booking" :currency="$currencyCode" :show_method="false" />
    </div>
    @endif
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

    @if ($terms)
        <div class="section">
            <h2 class="section-title"><span class="icon">📜</span> Terms &amp; Conditions</h2>
            @foreach ($terms as $term)
                <h4 style="margin-top:8px;">{{ $term['title'] }}</h4>
                <div style="color:#555;text-align:left;white-space:pre-wrap;line-height:1.55;">{{ \App\Services\PendingPaymentManager::quotationTermText($term['content']) }}</div>
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
            <p style="margin-bottom: 0;">@if ($staffRequested) Your quotation is ready. Please review the details and let us know if you have questions. @else Our team will review your quotation request and contact you as soon as possible with a detailed quote and options tailored to your needs. @endif</p>
        </div>
    </div>

    @if ($staffRequested && $checkoutLink)
    <div class="section">
        <h2 class="section-title">
            <span class="icon">⚡</span> Accept & Proceed to Payment
        </h2>
        <p style="color: #555; margin-bottom: 15px;">Open your quotation to review the booking details and terms before accepting and continuing to payment.
        </p>

        <div class="btn-container">
            <a href="{{ $checkoutLink }}" class="btn"
                style="display: inline-block; background-color: #15803d; background-image: linear-gradient(135deg, #15803d 0%, #166534 100%); box-shadow: 0 4px 12px rgba(21, 128, 61, 0.24); color: #FFFFFF; padding: 12px 30px; text-decoration: none; border-radius: 5px; font-weight: bold;">
                Review your quotation
            </a>
        </div>

        <p class="highlight-box info" style="margin: 15px 0;">
            The prices in this quotation are valid for 7 days. This secure checkout link expires 7 days after it is issued.
        </p>

        <p style="text-align: center; color: #717171; font-size: 13px; margin-top: 10px;">
            If the button doesn't work, copy and paste this link:<br>
            <a href="{{ $checkoutLink }}"
                style="color: {{ $brandColor }}; text-decoration: underline; word-break: break-all;">{{ $checkoutLink }}</a>
        </p>
    </div>
    @elseif ($staffRequested)
    <div class="section">
        <p>Our team will contact you with an updated offer. This email does not contain an active checkout link.</p>
    </div>
    @endif

    @php
        $supportPhone = $settings['company_phone'] ?? '';
        $supportPhoneTel = preg_replace('/[^0-9+]/', '', $supportPhone);
    @endphp
    @if(!empty($supportPhoneTel))
        <div style="text-align: center; margin: 25px 0 15px 0;">
            <a href="tel:{{ $supportPhoneTel }}"
                style="display: inline-block; background-color: {{ $brandColor }}; color: #ffffff; padding: 10px 22px; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 14px;">
                📞 Call 24/7 Support
            </a>
        </div>
    @endif

    <p style="text-align: center; color: #555; font-size: 15px; margin-top: 20px;">Thank you for choosing
        {{ ($settings['company_name'] ?? $settings['brand_name'] ?? $settings['site_name'] ?? 'Company') }}. We look forward to serving you!</p>
@endsection
