<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Initiated - {{ config('app.name') }}</title>
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
        .info-table td { padding: 8px 10px; border-bottom: 1px solid #eeeeee; }
        .info-table td:first-child { font-weight: bold; width: 40%; color: #666; }
        .highlight-box { background: #f8f9fa; border-left: 4px solid #c91c23; padding: 14px; margin: 16px 0; }
        .reference { font-size: 20px; font-weight: bold; color: #c91c23; letter-spacing: 1px; }
        .footer { background: #f8f9fa; padding: 18px; text-align: center; font-size: 13px; color: #666; }
        @media only screen and (max-width: 600px) {
            .content { padding: 20px 16px; }
            .info-table td { display: block; width: 100%; }
            .info-table td:first-child { border-bottom: none; padding-bottom: 4px; }
        }
    </style>
</head>
<body>
@php
    $currencySymbol = getCurrencySymbol($booking->currency ?? 'LKR');
    $customerName = $booking->customer?->full_name ?? 'Valued Customer';
@endphp

<div class="container">
    <div class="header">
        <img src="{{ asset('assets/img/header-logo.png') }}" alt="TheTaxi">
        <h1>Payment Initiated</h1>
    </div>

    <div class="content">
        <p style="font-size: 15px;">Dear <strong>{{ $customerName }}</strong>,</p>
        <p>Your payment request has been initiated. Please complete the payment process to confirm your booking.</p>

        <div class="highlight-box">
            <p style="margin: 0 0 6px 0; color: #666;">Booking Reference</p>
            <p class="reference" style="margin: 0;">{{ $booking->booking_number }}</p>
        </div>

        <div class="section">
            <h2>Payment Details</h2>
            <table class="info-table">
                <tr>
                    <td>Amount to Pay</td>
                    <td>{{ $currencySymbol }}{{ number_format($amount, 2) }}</td>
                </tr>
                <tr>
                    <td>Payment Type</td>
                    <td>{{ ucfirst($booking->payment_type ?? 'full') }}</td>
                </tr>
                <tr>
                    <td>Payment Method</td>
                    <td>{{ $booking->payment_method ? ucfirst($booking->payment_method) : 'Online' }}</td>
                </tr>
                <tr>
                    <td>Status</td>
                    <td>Pending Completion</td>
                </tr>
            </table>
        </div>

        <div class="section">
            <h2>Need Assistance?</h2>
            <table class="info-table">
                <tr>
                    <td>Email</td>
                    <td><a href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a></td>
                </tr>
                <tr>
                    <td>Phone</td>
                    <td><a href="tel:{{ $supportPhone }}">{{ $supportPhone }}</a></td>
                </tr>
            </table>
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
