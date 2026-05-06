@extends('emails.layouts.master')

@section('title', 'Quotation Request Received - ' . config('app.name'))

@section('header_title', 'Quotation Request Received')

@section('header_subtitle', 'Your Premium Transport Experience Awaits')

@section('content')
    @php
        $serviceType = $requestData['service_type'] ?? 'Quotation';
        $pickupLocation = $requestData['pickup_location'] ?? null;
        $dropoffLocation = $requestData['dropoff_location'] ?? null;
        $travelDate = $requestData['travel_date'] ?? null;
        $travelTime = $requestData['travel_time'] ?? null;
        $returnDate = $requestData['return_date'] ?? null;
        $returnTime = $requestData['return_time'] ?? null;
        $passengers = $requestData['passengers'] ?? null;
        $vehicleThumbnail = $vehicleGroup->thumbnail ?? null;
        $thumb = is_array($vehicleThumbnail) ? ($vehicleThumbnail['path'] ?? ($vehicleThumbnail[0] ?? null)) : $vehicleThumbnail;
        $imageUrl = $thumb ? s3_asset($thumb) : asset('assets/img/default-vehicle.jpg');
        $pickupDateTime = trim(($travelDate ?: 'To be confirmed') . ($travelTime ? ' ' . $travelTime : ''));
    @endphp

    <p class="greeting">
        Dear <strong>{{ $customerName }}</strong>,
    </p>

    <p class="intro-text">
        Thank you for your quotation request. Our team will review your requirements and get back to you within
        {{ $estimatedResponseTime }}.
    </p>

    <div class="reference-box">
        <div class="reference-label">Quotation Reference</div>
        <div class="reference-number">{{ $inquiryNumber }}</div>
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
                @if ($returnDate || $returnTime)
                    <tr>
                        <td>Return Date & Time</td>
                        <td>{{ trim(($returnDate ?: 'To be confirmed') . ($returnTime ? ' ' . $returnTime : '')) }}</td>
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
                <td>{{ $requestData['customer_email'] ?? 'N/A' }}</td>
            </tr>
            <tr>
                <td>Phone</td>
                <td>{{ $requestData['customer_phone'] ?? 'N/A' }}</td>
            </tr>
            @if (!empty($requestData['phone_country']))
                <tr>
                    <td>Country</td>
                    <td>{{ strtoupper($requestData['phone_country']) }}</td>
                </tr>
            @endif
        </table>
    </div>

    @if (!empty($requestData['special_requirements']))
        <div class="section">
            <h2 class="section-title">
                <span class="icon">Notes</span> Special Requirements
            </h2>
            <p style="color: #555; line-height: 1.6; margin: 0;">{{ $requestData['special_requirements'] }}</p>
        </div>
    @endif

    <div class="divider"></div>

    <div class="section">
        <h2 class="section-title">
            <span class="icon">Next</span> What's Next?
        </h2>
        <div class="highlight-box info">
            <h3>Your Request is Being Processed</h3>
            <p><strong>Step 1:</strong> Our team will review your quotation request</p>
            <p><strong>Step 2:</strong> We'll contact you at
                <strong>{{ $requestData['customer_phone'] ?? 'your provided number' }}</strong> within
                {{ $estimatedResponseTime }}
            </p>
            <p><strong>Step 3:</strong> You'll receive a detailed quote with vehicle options and pricing</p>
            <p style="margin-bottom: 0;"><strong>Step 4:</strong> Once approved, we'll send the next steps to confirm your
                booking</p>
        </div>
    </div>

    <div class="section">
        <h2 class="section-title">
            <span class="icon">Help</span> Need Assistance?
        </h2>
        <p style="color: #555; margin-bottom: 15px;">If you have any questions about your quotation request, please contact
            us:</p>
        <table class="info-table">
            <tr>
                <td>Phone</td>
                <td><a href="tel:{{ $supportPhone }}"
                        style="color: #BF2629; text-decoration: none;">{{ $supportPhone }}</a></td>
            </tr>
            <tr>
                <td>Email</td>
                <td><a href="mailto:{{ $supportEmail }}"
                        style="color: #BF2629; text-decoration: none;">{{ $supportEmail }}</a></td>
            </tr>
            <tr>
                <td>Website</td>
                <td><a href="{{ config('app.url') }}"
                        style="color: #BF2629; text-decoration: none;">{{ config('app.url') }}</a></td>
            </tr>
        </table>
    </div>
@endsection
