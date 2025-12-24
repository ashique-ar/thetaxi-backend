<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quotation Request - {{ config('app.name') }}</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; background-color: #f4f4f4; margin: 0; padding: 0; }
        .container { max-width: 600px; margin: 20px auto; background: #ffffff; border-radius: 8px; overflow: hidden; }
        .header { background: #c91c23; color: #ffffff; padding: 24px 20px; text-align: center; }
        .header img { max-height: 44px; margin-bottom: 10px; }
        .header h1 { margin: 0; font-size: 24px; }
        .content { padding: 28px 20px; }
        .section { margin-bottom: 24px; }
        .section h2 { color: #c91c23; border-bottom: 2px solid #c91c23; padding-bottom: 8px; margin-bottom: 12px; font-size: 18px; }
        .info-table { width: 100%; border-collapse: collapse; margin: 12px 0; }
        .info-table td { padding: 8px 10px; border-bottom: 1px solid #eeeeee; vertical-align: top; }
        .info-table td:first-child { font-weight: bold; width: 40%; color: #666; }
        .highlight-box { background: #f8f9fa; border-left: 4px solid #c91c23; padding: 14px; margin: 16px 0; }
        .reference { font-size: 20px; font-weight: bold; color: #c91c23; letter-spacing: 1px; }
        .footer { background: #f8f9fa; padding: 18px; text-align: center; font-size: 13px; color: #666; }
        ul { padding-left: 20px; margin: 0; }
        @media only screen and (max-width: 600px) {
            .content { padding: 20px 16px; }
            .info-table td { display: block; width: 100%; }
            .info-table td:first-child { border-bottom: none; padding-bottom: 4px; }
        }
    </style>
</head>
<body>
@php
    $workflowData = is_string($booking->workflow_data) ? json_decode($booking->workflow_data, true) : ($booking->workflow_data ?? []);
    $cartItems = $workflowData['cart_items'] ?? [];
    $pickupLocation = is_string($booking->pickup_location) ? json_decode($booking->pickup_location, true) : $booking->pickup_location;
    $contactTime = match ($booking->contact_time ?? null) {
        'morning' => 'Morning (9 AM - 12 PM)',
        'afternoon' => 'Afternoon (12 PM - 5 PM)',
        'evening' => 'Evening (5 PM - 8 PM)',
        'anytime' => 'Anytime',
        default => 'Not specified',
    };
    $budgetRange = match ($workflowData['budget_range'] ?? null) {
        'under-500' => 'Under $500',
        '500-1000' => '$500 - $1,000',
        '1000-2000' => '$1,000 - $2,000',
        'over-2000' => 'Over $2,000',
        default => 'Not specified',
    };
@endphp

<div class="container">
    <div class="header">
        <img src="{{ asset('assets/img/header-logo.png') }}" alt="TheTaxi">
        <h1>Quotation Request Received</h1>
    </div>

    <div class="content">
        <p style="font-size: 15px;">Dear <strong>{{ $booking->customer->full_name ?? 'Valued Customer' }}</strong>,</p>

        <p>Thank you for submitting a quotation request to TheTaxi. We have received your inquiry and will get back to you shortly.</p>

        <div class="highlight-box">
            <p style="margin: 0 0 6px 0; color: #666;">Quotation Reference</p>
            <p class="reference" style="margin: 0;">{{ $booking->booking_number }}</p>
        </div>

        <div class="section">
            <h2>Your Request Details</h2>
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
                <tr>
                    <td>Pickup Location</td>
                    <td>{{ $pickupLocation['address'] ?? 'N/A' }}</td>
                </tr>
                <tr>
                    <td>Pickup Date</td>
                    <td>{{ \Carbon\Carbon::parse($booking->from_date)->format('F d, Y H:i A') }}</td>
                </tr>
                <tr>
                    <td>Return Date</td>
                    <td>{{ \Carbon\Carbon::parse($booking->to_date)->format('F d, Y H:i A') }}</td>
                </tr>
                <tr>
                    <td>Preferred Contact Time</td>
                    <td>{{ $contactTime }}</td>
                </tr>
                <tr>
                    <td>Budget Range</td>
                    <td>{{ $budgetRange }}</td>
                </tr>
            </table>
        </div>

        <div class="section">
            <h2>Vehicles of Interest</h2>
            @if(!empty($cartItems))
                <ul>
                    @foreach($cartItems as $item)
                        <li>
                            <strong>{{ $item['name'] ?? 'Vehicle' }}</strong>
                            <div>Duration: {{ $item['days'] ?? 1 }} day(s)</div>
                        </li>
                    @endforeach
                </ul>
            @else
                <p>Vehicle details will be discussed during consultation.</p>
            @endif
        </div>

        @if($booking->special_requirements)
        <div class="section">
            <h2>Special Requirements</h2>
            <p>{{ $booking->special_requirements }}</p>
        </div>
        @endif

        @if(!empty($workflowData['flight_details']))
        <div class="section">
            <h2>Flight Information</h2>
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

        <div class="section">
            <h2>Next Steps</h2>
            <p>Our team will review your quotation request and contact you within 24 hours with a detailed quote and options.</p>
        </div>
    </div>

    <div class="footer">
        <p style="margin: 0;">Best regards,</p>
        <p style="margin: 0; font-weight: bold;">{{ config('app.name', 'TheTaxi') }} Team</p>
        <p style="margin: 10px 0 0 0; font-size: 12px;">This is an automated email. Please do not reply directly to this message.</p>
    </div>
</div>
</body>
</html>
