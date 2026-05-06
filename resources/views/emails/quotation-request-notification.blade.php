@extends('emails.layouts.master')

@section('title', 'New Quotation Request - ' . config('app.name'))

@section('header_title', 'New Quotation Request')

@section('header_subtitle', 'Your Premium Transport Experience Awaits')

@section('content')
    @php
        $serviceType = $requestData['service_type'] ?? 'Quotation';
        $pickupLocation = $requestData['pickup_location'] ?? null;
        $dropoffLocation = $requestData['dropoff_location'] ?? null;
        $travelDate = $requestData['travel_date'] ?? null;
        $travelTime = $requestData['travel_time'] ?? null;
        $passengers = $requestData['passengers'] ?? null;
        $vehicleThumbnail = $vehicleGroup->thumbnail ?? null;
        $thumb = is_array($vehicleThumbnail) ? ($vehicleThumbnail['path'] ?? ($vehicleThumbnail[0] ?? null)) : $vehicleThumbnail;
        $imageUrl = $thumb ? s3_asset($thumb) : asset('assets/img/default-vehicle.jpg');
        $pickupDateTime = trim(($travelDate ?: 'To be confirmed') . ($travelTime ? ' ' . $travelTime : ''));
    @endphp

    <p class="greeting">
        Dear <strong>Team</strong>,
    </p>

    <p class="intro-text">
        A new quotation request has been received. Please review the request and contact the customer with pricing.
    </p>

    <div class="reference-box">
        <div class="reference-label">Quotation Reference</div>
        <div class="reference-number">{{ $inquiry->inquiry_number ?? $inquiry->id }}</div>
    </div>

    <div class="section">
        <h2 class="section-title">
            <span class="icon">Vehicle</span> Vehicle Wise Trip Details
        </h2>

        <div
            style="background-color: #f8f9fa; border: 1px solid #eef0f2; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
            <div style="display: flex; align-items: flex-start; gap: 15px;">
                @if ($imageUrl)
                    <div style="flex-shrink: 0;">
                        <img src="{{ $imageUrl }}" alt="{{ $vehicleGroup->name }}"
                            style="width: 120px; height: 80px; object-fit: cover; border-radius: 6px; border: 1px solid #ddd;">
                    </div>
                @endif
                <div style="flex: 1;">
                    <h3 style="margin-top: 0; margin-bottom: 5px; color: #BF2629; font-size: 16px;">
                        Vehicle 1: {{ $vehicleGroup->name }}
                    </h3>
                    <span
                        style="display: inline-block; background-color: #fff3cd; color: #856404; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; border: 1px solid #ffc107; margin-bottom: 8px;">
                        Service: {{ ucwords(str_replace(['_', '-'], ' ', $serviceType)) }}
                    </span>
                </div>
            </div>

            <table class="info-table" style="width: 100%; border-collapse: collapse; margin-top: 15px;">
                @if ($pickupLocation)
                    <tr>
                        <td>Pickup Location</td>
                        <td>{{ $pickupLocation }}
                            <div style="margin-top: 6px; color: #777; font-size: 12px; line-height: 1.5;">
                                Pickup Date & Time: <strong>{{ $pickupDateTime }}</strong>
                            </div>
                        </td>
                    </tr>
                @elseif ($travelDate || $travelTime)
                    <tr>
                        <td>Pickup Date & Time</td>
                        <td>{{ $pickupDateTime }}</td>
                    </tr>
                @endif
                @if ($dropoffLocation)
                    <tr>
                        <td>Dropoff Location</td>
                        <td>{{ $dropoffLocation }}</td>
                    </tr>
                @endif
                @if ($passengers)
                    <tr>
                        <td>Passengers</td>
                        <td>{{ $passengers }}</td>
                    </tr>
                @endif
            </table>
        </div>
    </div>

    <div class="section">
        <h2 class="section-title">
            <span class="icon">Customer</span> Customer Information
        </h2>
        <table class="info-table">
            <tr>
                <td>Name</td>
                <td>{{ $customerName }}</td>
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
            @if (!empty($requestData['phone_country']))
                <tr>
                    <td>Country</td>
                    <td>{{ strtoupper($requestData['phone_country']) }}</td>
                </tr>
            @endif
            <tr>
                <td>Submitted</td>
                <td>{{ optional($inquiry->created_at)->format('Y-m-d H:i:s') ?? now()->format('Y-m-d H:i:s') }}</td>
            </tr>
        </table>
    </div>

    @if ($specialRequirements)
        <div class="section">
            <h2 class="section-title">
                <span class="icon">Notes</span> Special Requirements
            </h2>
            <p style="color: #555; line-height: 1.6; margin: 0;">{{ $specialRequirements }}</p>
        </div>
    @endif

    <div class="divider"></div>

    <div class="section">
        <h2 class="section-title">
            <span class="icon">Next</span> What's Next?
        </h2>
        <div class="highlight-box info">
            <h3>Quotation Follow-up Required</h3>
            <p><strong>Step 1:</strong> Review the requested vehicle and trip details</p>
            <p><strong>Step 2:</strong> Prepare the quote manually</p>
            <p><strong>Step 3:</strong> Contact the customer at <strong>{{ $customerPhone }}</strong></p>
            <p style="margin-bottom: 0;"><strong>Step 4:</strong> Update the inquiry after follow-up</p>
        </div>
    </div>
@endsection
