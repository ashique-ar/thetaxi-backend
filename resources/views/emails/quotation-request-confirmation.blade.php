@extends('emails.layouts.master')

@section('title', 'Quotation Request Received - ' . config('app.name'))

@section('header_title', 'Quotation Request Received')

@section('header_subtitle', 'Your request is being processed')

@section('content')
    @php
        use App\Helpers\BookingLinkHelper;
        use App\Models\Booking\Booking;

        $booking = $booking ?? null;
        $quotationBooking =
            $booking instanceof Booking
                ? $booking
                : (isset($inquiryNumber) ? Booking::where('booking_number', $inquiryNumber)->first() : null);
        $checkoutLink = $quotationBooking ? BookingLinkHelper::getQuotationCheckoutLink($quotationBooking) : null;
        $requestRows = [
            'Vehicle Group' => $vehicleGroup->name,
            'Service Type' => $requestData['service_type'] ?? null,
            'Pickup Location' => $requestData['pickup_location'] ?? null,
            'Dropoff Location' => $requestData['dropoff_location'] ?? null,
            'Travel Date' => $requestData['travel_date'] ?? null,
            'Travel Time' => $requestData['travel_time'] ?? null,
            'Passengers' => $requestData['passengers'] ?? null,
        ];
    @endphp

    <p class="greeting">
        Dear <strong>{{ $customerName }}</strong>,
    </p>

    <p class="intro-text">
        Thank you for your quotation request. Our team has received your request for
        <strong>{{ $vehicleGroup->name }}</strong> and will contact you with pricing.
    </p>

    <div class="reference-box">
        <div class="reference-label">Reference Number</div>
        <div class="reference-number">{{ $inquiryNumber }}</div>
    </div>

    <div class="section">
        <h2 class="section-title"><span class="icon">CAR</span> Request Details</h2>
        @if ($booking instanceof \App\Models\Booking\Booking && $booking->bookingItems->count() > 0)
            @php
                $currencySymbol = getCurrencySymbol($booking->currency ?? 'LKR');
            @endphp
            @foreach ($booking->bookingItems as $index => $item)
                <x-booking-item-email :item="$item" :index="$index" :currencySymbol="$currencySymbol" />
            @endforeach
        @else
            <table class="info-table">
                @foreach ($requestRows as $label => $value)
                    @if (!empty($value))
                        <tr>
                            <td>{{ $label }}</td>
                            <td>{{ $value }}</td>
                        </tr>
                    @endif
                @endforeach
            </table>
        @endif
    </div>

    <div class="section">
        <h2 class="section-title"><span class="icon">USER</span> Contact Information</h2>
        <table class="info-table">
            <tr>
                <td>Name</td>
                <td>{{ $customerName }}</td>
            </tr>
            @if (!empty($requestData['customer_email']))
                <tr>
                    <td>Email</td>
                    <td>{{ $requestData['customer_email'] }}</td>
                </tr>
            @endif
            @if (!empty($requestData['customer_phone']))
                <tr>
                    <td>Phone</td>
                    <td>{{ $requestData['customer_phone'] }}</td>
                </tr>
            @endif
        </table>
    </div>

    @if (!empty($requestData['special_requirements']))
        <div class="section">
            <h2 class="section-title"><span class="icon">NOTE</span> Special Requirements</h2>
            <p style="color: #555; line-height: 1.6; margin: 0;">{{ $requestData['special_requirements'] }}</p>
        </div>
    @endif

    <div class="highlight-box info">
        <h3>What Happens Next</h3>
        <p><strong>1. Review:</strong> Our team will review your request details.</p>
        <p><strong>2. Quote:</strong> We will prepare pricing manually for your requirement.</p>
        <p style="margin-bottom: 0;"><strong>3. Contact:</strong> We will contact you within
            {{ $estimatedResponseTime }}.</p>
    </div>

    @if ($checkoutLink)
        <div class="section">
            <h2 class="section-title"><span class="icon">PAY</span> Ready to Proceed?</h2>
            <p style="color: #555; margin-bottom: 15px;">You can proceed to checkout from the link below.</p>
            <div class="btn-container">
                <a href="{{ $checkoutLink }}" class="btn"
                    style="display: inline-block; background-color: #15803d; color: #FFFFFF; padding: 15px 40px; text-decoration: none; border-radius: 8px; font-weight: bold; font-size: 16px;">
                    Book Now
                </a>
            </div>
        </div>
    @endif

    <div class="divider"></div>

    <div class="section">
        <h2 class="section-title"><span class="icon">HELP</span> Need Assistance?</h2>
        <table class="info-table">
            <tr>
                <td>Email</td>
                <td><a href="mailto:{{ $supportEmail }}"
                        style="color: #BF2629; text-decoration: none;">{{ $supportEmail }}</a></td>
            </tr>
            <tr>
                <td>Phone</td>
                <td><a href="tel:{{ $supportPhone }}"
                        style="color: #BF2629; text-decoration: none;">{{ $supportPhone }}</a></td>
            </tr>
        </table>
    </div>
@endsection
