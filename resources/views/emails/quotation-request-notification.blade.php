@extends('emails.layouts.master')

@section('title', 'New Quotation Request - ' . config('app.name'))

@section('header_title', 'New Quotation Request')

@section('header_subtitle', 'Action Required')

@section('content')
    @php
        $booking = $booking ?? null;
        $requestRows = [
            'Vehicle Group' => $vehicleGroup->name,
            'Service Type' => $serviceType,
            'Pickup Location' => $pickupLocation,
            'Dropoff Location' => $dropoffLocation,
            'Travel Date' => $travelDate,
            'Travel Time' => $travelTime,
            'Passengers' => $passengers,
        ];
    @endphp

    <div class="highlight-box warning">
        <h3>Action Required</h3>
        <p style="margin-bottom: 0;">A new quotation request has been submitted for
            <strong>{{ $vehicleGroup->name }}</strong>. Please review the request details and prepare pricing manually.</p>
    </div>

    <div class="section">
        <h2 class="section-title"><span class="icon">REF</span> Inquiry Details</h2>
        <table class="info-table">
            <tr>
                <td>Inquiry ID</td>
                <td><strong>{{ $inquiry->inquiry_number ?? $inquiry->id }}</strong></td>
            </tr>
            <tr>
                <td>Submitted</td>
                <td>{{ optional($inquiry->created_at)->format('Y-m-d H:i:s') ?? now()->format('Y-m-d H:i:s') }}</td>
            </tr>
            <tr>
                <td>Status</td>
                <td>{{ ucfirst($inquiry->status ?? 'open') }}</td>
            </tr>
        </table>
    </div>

    <div class="section">
        <h2 class="section-title"><span class="icon">USER</span> Customer Information</h2>
        <table class="info-table">
            <tr>
                <td>Name</td>
                <td><strong>{{ $customerName }}</strong></td>
            </tr>
            <tr>
                <td>Email</td>
                <td><a href="mailto:{{ $customerEmail }}"
                        style="color: #BF2629; text-decoration: none;">{{ $customerEmail }}</a></td>
            </tr>
            <tr>
                <td>Phone</td>
                <td><a href="tel:{{ $customerPhone }}"
                        style="color: #BF2629; text-decoration: none;">{{ $customerPhone }}</a></td>
            </tr>
            @if ($companyName)
                <tr>
                    <td>Company</td>
                    <td>{{ $companyName }}</td>
                </tr>
            @endif
        </table>
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

    @if ($specialRequirements)
        <div class="section">
            <h2 class="section-title"><span class="icon">NOTE</span> Special Requirements</h2>
            <p style="color: #555; line-height: 1.6; margin: 0;">{{ $specialRequirements }}</p>
        </div>
    @endif

    <div class="highlight-box info">
        <h3>Next Steps</h3>
        <ol style="margin: 12px 0 0 0; padding-left: 20px; color: #555;">
            <li style="margin-bottom: 8px;">Review the requested vehicle and trip details.</li>
            <li style="margin-bottom: 8px;">Prepare the quotation amount manually.</li>
            <li style="margin-bottom: 8px;">Contact the customer with the quote and any clarifications.</li>
            <li>Update the inquiry status after follow-up.</li>
        </ol>
    </div>

    <p style="text-align: center; color: #717171; font-size: 13px; margin-top: 30px;">
        Inquiry ID: {{ $inquiry->inquiry_number ?? $inquiry->id }} | Generated: {{ now()->format('Y-m-d H:i:s') }}
    </p>
@endsection
