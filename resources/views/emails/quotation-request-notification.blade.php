@extends('emails.layouts.master')

@section('title', 'New Quotation Request - ' . config('app.name'))

@section('header_title', 'New Quotation Request')

@section('header_subtitle', 'Action Required - Corporate Transport Services')

@section('content')
    <!-- Urgent Notice -->
    <div class="highlight-box warning">
        <h3>⚠️ Action Required</h3>
        <p style="margin-bottom: 0;">A new quotation request has been submitted for
            <strong>{{ $vehicleGroup->name }}</strong>. Distance calculation was not possible due to missing coordinates,
            requiring manual quotation.
        </p>
    </div>

    <!-- Inquiry Details Section -->
    <div class="section">
        <h2 class="section-title">
            <span class="icon">📋</span> Inquiry Details
        </h2>
        <table class="info-table">
            <tr>
                <td>Inquiry ID</td>
                <td><strong>{{ $inquiry->id }}</strong></td>
            </tr>
            <tr>
                <td>Vehicle Group</td>
                <td><strong>{{ $vehicleGroup->name }}</strong></td>
            </tr>
            <tr>
                <td>Service Type</td>
                <td>{{ $serviceType }}</td>
            </tr>
            <tr>
                <td>Submitted</td>
                <td>{{ $inquiry->created_at->format('Y-m-d H:i:s') }}</td>
            </tr>
            <tr>
                <td>Priority</td>
                <td><span style="color: #BF2629; font-weight: bold;">HIGH</span></td>
            </tr>
        </table>
    </div>

    <!-- Customer Information Section -->
    <div class="section">
        <h2 class="section-title">
            <span class="icon">👤</span> Customer Information
        </h2>
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
            @if (isset($customerIdentification) && !empty($customerIdentification))
                <tr>
                    <td>Identification</td>
                    <td>{{ $customerIdentification }}</td>
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
            @if ($companyName)
                <tr>
                    <td>Company</td>
                    <td>{{ $companyName }}</td>
                </tr>
            @endif
        </table>
    </div>

    <!-- Vehicle Wise Trip Details Section -->
    <div class="section">
        <h2 class="section-title">
            <span class="icon">🚗</span> Vehicle Wise Trip Details
        </h2>
        @if (isset($booking) && $booking->bookingItems->count() > 0)
            @php
                $currencySymbol = getCurrencySymbol($booking->currency ?? 'LKR');
            @endphp
            @foreach ($booking->bookingItems as $index => $item)
                <x-booking-item-email :item="$item" :index="$index" :currencySymbol="$currencySymbol" />
            @endforeach
        @endif
    </div>

    @if ($specialRequirements)
        <div class="section">
            <h2 class="section-title">
                <span class="icon">📝</span> Special Requirements
            </h2>
            <p style="color: #555; line-height: 1.6; margin: 0;">{{ $specialRequirements }}</p>
        </div>
    @endif

    <div class="divider"></div>

    <!-- Technical Issue Section -->
    <div class="section">
        <h2 class="section-title">
            <span class="icon">🔍</span> Technical Issue
        </h2>
        <div class="highlight-box">
            <p><strong>Coordinates Missing:</strong> The automated distance calculation failed due to missing or invalid
                location coordinates. This requires manual intervention to:</p>
            <ul style="margin: 12px 0 0 0; padding-left: 20px;">
                <li style="margin-bottom: 6px;">Verify the exact pickup and dropoff locations</li>
                <li style="margin-bottom: 6px;">Calculate accurate distance and travel time</li>
                <li style="margin-bottom: 6px;">Prepare custom pricing based on route requirements</li>
                <li>Consider any special routing or accessibility needs</li>
            </ul>
        </div>
    </div>

    <!-- Action Required Section -->
    <div class="highlight-box info">
        <h3>🎯 Next Steps</h3>
        <p style="font-size: 16px;"><strong>Response Required Within: 2 Business Hours</strong></p>
        <p>Please:</p>
        <ol style="margin: 12px 0 0 0; padding-left: 20px; color: #555;">
            <li style="margin-bottom: 8px;">Contact the customer to confirm exact locations</li>
            <li style="margin-bottom: 8px;">Calculate route distance and duration manually</li>
            <li style="margin-bottom: 8px;">Prepare detailed quotation with pricing breakdown</li>
            <li>Send quotation to customer and update inquiry status</li>
        </ol>
    </div>

    <p style="text-align: center; color: #717171; font-size: 13px; margin-top: 30px;">
        Inquiry ID: {{ $inquiry->id }} | Generated: {{ now()->format('Y-m-d H:i:s') }}
    </p>
@endsection
