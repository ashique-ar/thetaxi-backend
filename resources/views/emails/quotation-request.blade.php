<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quotation Request - {{ config('app.name') }}</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; background-color: #f4f4f4; margin: 0; padding: 0; }
        .container { max-width: 600px; margin: 20px auto; background: #ffffff; border-radius: 8px; overflow: hidden; }
        .header { background: #17a2b8; color: #ffffff; padding: 30px 20px; text-align: center; }
        .header h1 { margin: 0; font-size: 28px; }
        .content { padding: 30px 20px; }
        .section { margin-bottom: 30px; }
        .section h2 { color: #17a2b8; border-bottom: 2px solid #17a2b8; padding-bottom: 10px; margin-bottom: 15px; font-size: 20px; }
        .info-table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        .info-table td { padding: 10px; border-bottom: 1px solid #eeeeee; }
        .info-table td:first-child { font-weight: bold; width: 40%; color: #666; }
        .highlight-box { background: #e8f4f8; border-left: 4px solid #17a2b8; padding: 15px; margin: 15px 0; }
        .reference { font-size: 24px; font-weight: bold; color: #17a2b8; letter-spacing: 2px; }
        .button { display: inline-block; padding: 12px 30px; background: #17a2b8; color: #ffffff; text-decoration: none; border-radius: 5px; margin: 20px 0; }
        .footer { background: #f8f9fa; padding: 20px; text-align: center; font-size: 14px; color: #666; }
        ul { padding-left: 20px; }
        @media only screen and (max-width: 600px) {
            .content { padding: 20px 15px; }
            .info-table td { display: block; width: 100%; }
            .info-table td:first-child { border-bottom: none; padding-bottom: 5px; }
        }
    </style>
</head>
<body>

@php
    $workflowData = is_string($booking->workflow_data) ? json_decode($booking->workflow_data, true) : $booking->workflow_data ?? [];
    $cartItems = $workflowData['cart_items'] ?? [];
    $pickupLocation = is_string($booking->pickup_location) ? json_decode($booking->pickup_location, true) : $booking->pickup_location;
@endphp

<div class="container">
    <div class="header">
        <h1>Quotation Request Received</h1>
    </div>

    <div class="content">
        <p style="font-size: 16px;">Dear <strong>{{ $booking->customer->full_name ?? 'Valued Customer' }}</strong>,</p>

        <p>Thank you for submitting a quotation request to TheTaxi! We have received your inquiry and will get back to you shortly.</p>

        <div class="highlight-box">
            <p style="margin: 0 0 10px 0; color: #666;">Quotation Reference</p>
            <p class="reference" style="margin: 0;">{{ $booking->booking_number }}</p>
        </div>

---

## Your Request Details

| Detail | Information |
|--------|-------------|
| **Name** | {{ $booking->customer?->user?->full_name ?? 'N/A' }} |
| **Email** | {{ $booking->customer?->user?->email ?? 'N/A' }} |
| **Phone** | {{ $booking->customer?->user?->phone ?? 'N/A' }} |
| **Pickup Location** | @php $pickup = is_string($booking->pickup_location) ? json_decode($booking->pickup_location, true) : $booking->pickup_location; @endphp {{ $pickup['address'] ?? 'N/A' }} |
| **Pickup Date** | {{ \Carbon\Carbon::parse($booking->from_date)->format('F d, Y H:i A') }} |
| **Return Date** | {{ \Carbon\Carbon::parse($booking->to_date)->format('F d, Y H:i A') }} |
| **Preferred Contact Time** | @switch($booking->contact_time)
    @case('morning') Morning (9 AM - 12 PM) @break
    @case('afternoon') Afternoon (12 PM - 5 PM) @break
    @case('evening') Evening (5 PM - 8 PM) @break
    @case('anytime') Anytime @break
    @default N/A @break
@endswitch |
| **Budget Range** | @php $workflowData = is_string($booking->workflow_data) ? json_decode($booking->workflow_data, true) : $booking->workflow_data ?? []; @endphp @switch($workflowData['budget_range'] ?? null)
    @case('under-500') Under $500 @break
    @case('500-1000') $500 - $1,000 @break
    @case('1000-2000') $1,000 - $2,000 @break
    @case('over-2000') Over $2,000 @break
    @default Not specified @break
@endswitch |

        <div class="section">
            <h2>Vehicles of Interest</h2>
            @if(!empty($cartItems))
                <ul>
                    @foreach($cartItems as $item)
                    <li>
                        <strong>{{ $item['name'] ?? 'Vehicle' }}</strong> ({{ $item['vehicle_type'] ?? 'Vehicle' }})
                        <ul>
                            <li>Duration: {{ $item['days'] ?? 1 }} day(s)</li>
                            <li>Pickup: {{ isset($item['pickup_date']) ? \Carbon\Carbon::parse($item['pickup_date'])->format('F d, Y') : 'Not specified' }}</li>
                        </ul>
                    </li>
                    @endforeach
                </ul>
            @else
                <p>Vehicle details will be discussed during consultation</p>
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
            @php
                $flight = $workflowData['flight_details'] ?? [];
            @endphp
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
            <p>Our team will review your quotation request and contact you at your preferred time to discuss:</p>
            <ul>
                <li>Available vehicle options matching your requirements</li>
                <li>Detailed pricing and package options</li>
                <li>Special offers and discounts</li>
                <li>Flexible payment terms</li>
            </ul>
            <div class="highlight-box">
                <p style="margin: 0; font-weight: bold;">⏰ We typically respond within 24 hours during business hours.</p>
            </div>
        </div>

        <div class="section">
            <h2>Questions Before We Contact?</h2>
            <p>Feel free to provide additional information or call us directly:</p>
            <table class="info-table">
                <tr>
                    <td>📞 Phone</td>
                    <td><a href="tel:+94112345678">+94 11 234 5678</a></td>
                </tr>
                <tr>
                    <td>📧 Email</td>
                    <td><a href="mailto:{{ config('mail.from.address') }}">{{ config('mail.from.address') }}</a></td>
                </tr>
                <tr>
                    <td>🌐 Website</td>
                    <td><a href="{{ config('app.url') }}">{{ config('app.url') }}</a></td>
                </tr>
            </table>
        </div>

        <div style="text-align: center; margin: 30px 0;">
            <a href="{{ config('app.url') }}" class="button">View Your Quotation Request</a>
        </div>

        <p style="text-align: center;">We look forward to assisting you!</p>
    </div>

    <div class="footer">
        <p style="margin: 0 0 10px 0;">Thanks,</p>
        <p style="margin: 0; font-weight: bold;">{{ config('app.name') }} Team</p>
        <p style="margin: 15px 0 0 0; font-size: 12px; color: #999;">
            This is an automated email. Please do not reply directly to this message.
        </p>
    </div>
</div>

</body>
</html>
