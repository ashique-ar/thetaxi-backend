<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Confirmation - {{ config('app.name') }}</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; background-color: #f4f4f4; margin: 0; padding: 0; }
        .container { max-width: 600px; margin: 20px auto; background: #ffffff; border-radius: 8px; overflow: hidden; }
        .header { background: #c91c23; color: #ffffff; padding: 30px 20px; text-align: center; }
        .header h1 { margin: 0; font-size: 28px; }
        .content { padding: 30px 20px; }
        .section { margin-bottom: 30px; }
        .section h2 { color: #c91c23; border-bottom: 2px solid #c91c23; padding-bottom: 10px; margin-bottom: 15px; font-size: 20px; }
        .info-table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        .info-table td { padding: 10px; border-bottom: 1px solid #eeeeee; }
        .info-table td:first-child { font-weight: bold; width: 40%; color: #666; }
        .highlight-box { background: #f8f9fa; border-left: 4px solid #c91c23; padding: 15px; margin: 15px 0; }
        .reference { font-size: 24px; font-weight: bold; color: #c91c23; letter-spacing: 2px; }
        .button { display: inline-block; padding: 12px 30px; background: #c91c23; color: #ffffff; text-decoration: none; border-radius: 5px; margin: 20px 0; }
        .footer { background: #f8f9fa; padding: 20px; text-align: center; font-size: 14px; color: #666; }
        .status-badge { display: inline-block; padding: 5px 15px; border-radius: 20px; font-size: 14px; font-weight: bold; }
        .status-paid { background: #28a745; color: white; }
        .status-pending { background: #ffc107; color: #333; }
        .status-quotation { background: #17a2b8; color: white; }
        @media only screen and (max-width: 600px) {
            .content { padding: 20px 15px; }
            .info-table td { display: block; width: 100%; }
            .info-table td:first-child { border-bottom: none; padding-bottom: 5px; }
        }
    </style>
</head>
<body>
@php
    $currencySymbol = getCurrencySymbol($booking->currency);
    $workflowData = is_string($booking->workflow_data) ? json_decode($booking->workflow_data, true) : $booking->workflow_data ?? [];
    $cartItems = $workflowData['cart_items'] ?? [];
    $pickupLocation = is_string($booking->pickup_location) ? json_decode($booking->pickup_location, true) : $booking->pickup_location;
    $dropoffLocation = is_string($booking->dropoff_location) ? json_decode($booking->dropoff_location, true) : $booking->dropoff_location;
@endphp

<div class="container">
    <div class="header">
        <img src="{{ asset('assets/img/header-logo.png') }}" alt="TheTaxi" style="max-height: 44px; margin-bottom: 10px;">
        <h1>
            @if($booking->payment_status === 'paid')
                Booking Confirmed! 🎉
            @elseif($booking->status === 'quotation_requested')
                Quotation Request Received
            @else
                Booking Received
            @endif
        </h1>
    </div>

    <div class="content">
        <p style="font-size: 16px;">Dear <strong>{{ $booking->customer->full_name ?? 'Valued Customer' }}</strong>,</p>

        <p>
            @if($booking->payment_status === 'paid')
                Thank you for your booking with TheTaxi! Your reservation has been confirmed and we're excited to serve you.
            @elseif($booking->status === 'quotation_requested')
                Thank you for your quotation request. Our team will review your requirements and get back to you within 24 hours.
            @else
                Thank you for your booking with TheTaxi! We have received your reservation request.
            @endif
        </p>

        <div class="highlight-box">
            <p style="margin: 0 0 10px 0; color: #666;">Booking Reference</p>
            <p class="reference" style="margin: 0;">{{ $booking->booking_number }}</p>
        </div>

        <div class="section">
            <h2>Trip Details</h2>
            <table class="info-table">
                <tr>
                    <td>Pickup Date</td>
                    <td>{{ \Carbon\Carbon::parse($booking->from_date)->format('l, F d, Y \a\t g:i A') }}</td>
                </tr>
                <tr>
                    <td>Return Date</td>
                    <td>{{ \Carbon\Carbon::parse($booking->to_date)->format('l, F d, Y \a\t g:i A') }}</td>
                </tr>
                <tr>
                    <td>Pickup Location</td>
                    <td>{{ $pickupLocation['address'] ?? 'N/A' }}</td>
                </tr>
                <tr>
                    <td>Return Location</td>
                    <td>{{ $dropoffLocation['address'] ?? 'N/A' }}</td>
                </tr>
                <tr>
                    <td>Duration</td>
                    <td>{{ ceil(\Carbon\Carbon::parse($booking->from_date)->diffInDays(\Carbon\Carbon::parse($booking->to_date))) }} day(s)</td>
                </tr>
            </table>
        </div>

        <div class="section">
            <h2>Customer Information</h2>
            <table class="info-table">
                <tr>
                    <td>Name</td>
                    <td>{{ $booking->customer->full_name ?? 'N/A' }}</td>
                </tr>
                <tr>
                    <td>Email</td>
                    <td>{{ $booking->customer->email ?? 'N/A' }}</td>
                </tr>
                <tr>
                    <td>Phone</td>
                    <td>{{ $booking->customer->phone ?? 'N/A' }}</td>
                </tr>
            </table>
        </div>

        <div class="section">
            <h2>Vehicles Booked</h2>
            <table class="info-table">
                @if(!empty($cartItems))
                    @foreach($cartItems as $item)
                    <tr>
                        <td colspan="2" style="font-weight: bold; color: #333;">{{ $item['name'] ?? 'Vehicle' }}</td>
                    </tr>
                    <tr>
                        <td>Duration</td>
                        <td>{{ $item['days'] ?? 1 }} day(s)</td>
                    </tr>
                    <tr>
                        <td>Rate per Day</td>
                        <td>{{ $currencySymbol }}{{ number_format($item['price'] ?? 0, 2) }}</td>
                    </tr>
                    <tr>
                        <td>Subtotal</td>
                        <td>{{ $currencySymbol }}{{ number_format(($item['price'] ?? 0) * ($item['days'] ?? 1), 2) }}</td>
                    </tr>
                    @endforeach
                @else
                    <tr>
                        <td colspan="2">Vehicle details will be confirmed based on quotation</td>
                    </tr>
                @endif
            </table>
        </div>

        <div class="section">
            <h2>Payment Summary</h2>
            <table class="info-table">
                <tr>
                    <td>Subtotal</td>
                    <td>{{ $currencySymbol }}{{ number_format($booking->base_amount, 2) }}</td>
                </tr>
                @if($booking->service_fee > 0)
                <tr>
                    <td>Service Fee</td>
                    <td>{{ $currencySymbol }}{{ number_format($booking->service_fee, 2) }}</td>
                </tr>
                @endif
                @if($booking->tax_amount > 0)
                <tr>
                    <td>{{ config('booking.tax.label', 'NBT') }} ({{ config('booking.tax.rate', 2.5) }}%)</td>
                    <td>{{ $currencySymbol }}{{ number_format($booking->tax_amount, 2) }}</td>
                </tr>
                @endif
                @if(($booking->vat_amount ?? 0) > 0)
                <tr>
                    <td>{{ config('booking.vat.label', 'VAT') }} ({{ config('booking.vat.rate', 18) }}%)</td>
                    <td>{{ $currencySymbol }}{{ number_format($booking->vat_amount, 2) }}</td>
                </tr>
                @endif
                @if($booking->discount_amount > 0)
                <tr style="color: #28a745;">
                    <td>Discount</td>
                    <td>-{{ $currencySymbol }}{{ number_format($booking->discount_amount, 2) }}</td>
                </tr>
                @endif
                <tr style="border-top: 2px solid #c91c23;">
                    <td style="font-size: 18px;"><strong>Total Amount</strong></td>
                    <td style="font-size: 18px; color: #c91c23;"><strong>{{ $currencySymbol }}{{ number_format($booking->total_estimated, 2) }}</strong></td>
                </tr>
                @if($booking->payment_type === 'advance')
                <tr style="background: #e8f4f8;">
                    <td><strong>Amount Paid ({{ config('booking.advance_payment.percentage', 50) }}%)</strong></td>
                    <td><strong>{{ $currencySymbol }}{{ number_format($booking->amount_to_pay ?? 0, 2) }}</strong></td>
                </tr>
                <tr style="background: #e8f4f8;">
                    <td>Balance Due at Pickup</td>
                    <td>{{ $currencySymbol }}{{ number_format($booking->total_estimated - ($booking->amount_to_pay ?? 0), 2) }}</td>
                </tr>
                @elseif($booking->payment_status === 'paid')
                <tr style="background: #d4edda;">
                    <td><strong>Amount Paid</strong></td>
                    <td><strong>{{ $currencySymbol }}{{ number_format($booking->amount_to_pay ?? $booking->total_estimated, 2) }}</strong></td>
                </tr>
                @endif
                <tr>
                    <td>Payment Method</td>
                    <td>
                        @switch($booking->payment_method)
                            @case('online') Online Payment @break
                            @case('bank_transfer') Bank Transfer @break
                            @case('online_banking') Online Banking @break
                            @default {{ ucfirst(str_replace('_', ' ', $booking->payment_method ?? 'N/A')) }} @break
                        @endswitch
                    </td>
                </tr>
                <tr>
                    <td>Payment Status</td>
                    <td>
                        @if($booking->payment_status === 'paid')
                            <span class="status-badge status-paid">✅ Paid</span>
                        @elseif($booking->payment_status === 'pending')
                            <span class="status-badge status-pending">⏳ Pending</span>
                        @else
                            {{ ucfirst($booking->payment_status) }}
                        @endif
                    </td>
                </tr>
            </table>
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
                $flight = $workflowData['flight_details'];
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
            <h2>What's Next?</h2>

            @if($booking->status === 'quotation_requested')
                <div class="highlight-box">
                    <p><strong>✅ Step 1:</strong> Our team will review your quotation request</p>
                    <p><strong>✅ Step 2:</strong> We'll contact you at <strong>{{ $booking->customer->phone ?? 'your provided number' }}</strong> within 24 hours</p>
                    <p><strong>✅ Step 3:</strong> You'll receive a detailed quote with vehicle options and pricing</p>
                    <p style="margin-bottom: 0;"><strong>✅ Step 4:</strong> Once approved, we'll send a secure payment link to confirm your booking</p>
                </div>

            @elseif($booking->payment_status === 'pending')
                <div class="highlight-box" style="border-left-color: #ffc107;">
                    <h3 style="margin-top: 0; color: #ffc107;">⏳ Complete Your Payment</h3>
                    <p>Please transfer the amount to the following bank account:</p>
                    <table style="width: 100%; margin: 15px 0;">
                        <tr><td style="padding: 5px 0;"><strong>Account Name:</strong></td><td>Casons Rent A Car (Pvt) Ltd</td></tr>
                        <tr><td style="padding: 5px 0;"><strong>Bank:</strong></td><td>Commercial Bank of Ceylon PLC</td></tr>
                        <tr><td style="padding: 5px 0;"><strong>Account No:</strong></td><td>1234567890</td></tr>
                        <tr><td style="padding: 5px 0;"><strong>Branch:</strong></td><td>Colombo Main Branch</td></tr>
                        <tr><td style="padding: 5px 0;"><strong>SWIFT Code:</strong></td><td>CCEYLKLX</td></tr>
                        <tr><td style="padding: 5px 0;"><strong>Reference:</strong></td><td style="color: #c91c23; font-weight: bold;">{{ $booking->booking_number }}</td></tr>
                    </table>
                    <p style="margin-bottom: 0;">After payment, please email the receipt to: <a href="mailto:payments@casonsrentacar.lk">payments@casonsrentacar.lk</a></p>
                    <p style="margin-bottom: 0;"><em>Our team will verify your payment within 2-4 business hours and send final confirmation.</em></p>
                </div>

            @elseif($booking->payment_type === 'advance')
                <div class="highlight-box" style="border-left-color: #28a745;">
                    <h3 style="margin-top: 0; color: #28a745;">✅ Payment Confirmed!</h3>
                    <p>You have successfully paid {{ config('booking.advance_payment.percentage', 50) }}% advance ({{ $currencySymbol }}{{ number_format($booking->amount_to_pay ?? 0, 2) }}).</p>
                    <p><strong>Balance Due at Pickup:</strong> {{ $currencySymbol }}{{ number_format($booking->total_estimated - ($booking->amount_to_pay ?? 0), 2) }}</p>
                    <p style="margin-bottom: 10px;"><strong>Important Reminders:</strong></p>
                    <ul style="margin: 0; padding-left: 20px;">
                        <li>Bring valid government-issued ID/Passport</li>
                        <li>Bring a valid driver's license</li>
                        <li>A credit card may be required for security deposit</li>
                        <li>Arrive 15 minutes before scheduled pickup time</li>
                    </ul>
                </div>

            @else
                <div class="highlight-box" style="border-left-color: #28a745;">
                    <h3 style="margin-top: 0; color: #28a745;">✅ Your Booking is Confirmed!</h3>
                    <p>Your vehicle will be prepared and ready for pickup on <strong>{{ \Carbon\Carbon::parse($booking->from_date)->format('F d, Y \a\t g:i A') }}</strong>.</p>
                    <p style="margin-bottom: 10px;"><strong>Important Reminders:</strong></p>
                    <ul style="margin: 0; padding-left: 20px;">
                        <li>Bring valid government-issued ID/Passport</li>
                        <li>Bring a valid driver's license</li>
                        <li>A credit card may be required for security deposit</li>
                        <li>Arrive 15 minutes before scheduled pickup time</li>
                    </ul>
                </div>
            @endif
        </div>

        <div class="section">
            <h2>Need Assistance?</h2>
            <p>If you have any questions about your booking, please contact us:</p>
            <table class="info-table">
                <tr>
                    <td>📞 Phone</td>
                    <td><a href="tel:+94112345678">+94 11 234 5678</a></td>
                </tr>
                <tr>
                    <td>📧 Email</td>
                    <td><a href="mailto:{{ config('mail.from.address', 'bookings@thetaxi.com') }}">{{ config('mail.from.address', 'bookings@thetaxi.com') }}</a></td>
                </tr>
                <tr>
                    <td>🌐 Website</td>
                    <td><a href="{{ config('app.url') }}">{{ config('app.url') }}</a></td>
                </tr>
                <tr>
                    <td>💬 WhatsApp</td>
                    <td><a href="https://wa.me/94712345678">+94 71 234 5678</a></td>
                </tr>
            </table>
            <p style="text-align: center; margin-top: 20px;">Our customer support team is available 24/7 to assist you.</p>
        </div>

        <div style="text-align: center; margin: 30px 0;">
            <a href="{{ config('app.url') }}" class="button">View Your Booking Details</a>
        </div>

        <p style="text-align: center;">Thank you for choosing TheTaxi. We look forward to serving you!</p>
    </div>

    <div class="footer">
        <p style="margin: 0 0 10px 0;">Best regards,</p>
        <p style="margin: 0; font-weight: bold;">{{ config('app.name', 'TheTaxi') }} Team</p>
        <p style="margin: 15px 0 0 0; font-size: 12px; color: #999;">
            This is an automated email. Please do not reply directly to this message.
        </p>
    </div>
</div>

</body>
</html>
