<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $typeLabel }} - {{ config('app.name') }}</title>
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
        .button { display: inline-block; padding: 10px 24px; background: #c91c23; color: #ffffff; text-decoration: none; border-radius: 5px; margin-top: 12px; }
        @media only screen and (max-width: 600px) {
            .content { padding: 20px 16px; }
            .info-table td { display: block; width: 100%; }
            .info-table td:first-child { border-bottom: none; padding-bottom: 4px; }
        }
    </style>
</head>
<body>
@php
    $payload = $inquiry->payload ?? [];
    $form = is_array($payload['form'] ?? null) ? $payload['form'] : [];
@endphp

<div class="container">
    <div class="header">
        <img src="{{ asset('assets/img/header-logo.png') }}" alt="TheTaxi">
        <h1>{{ $typeLabel }} Received</h1>
    </div>

    <div class="content">
        <p style="font-size: 15px;">Dear <strong>{{ $contactName }}</strong>,</p>
        <p>{{ $intro }}</p>

        <div class="highlight-box">
            <p style="margin: 0 0 6px 0; color: #666;">Reference</p>
            <p class="reference" style="margin: 0;">{{ $inquiry->id }}</p>
        </div>

        <div class="section">
            <h2>Inquiry Details</h2>
            <table class="info-table">
                <tr>
                    <td>Type</td>
                    <td>{{ $typeLabel }}</td>
                </tr>
                @if(!empty($form['company_name']))
                <tr>
                    <td>Company</td>
                    <td>{{ $form['company_name'] }}</td>
                </tr>
                @endif
                @if(!empty($form['contact_person']))
                <tr>
                    <td>Contact Person</td>
                    <td>{{ $form['contact_person'] }}</td>
                </tr>
                @endif
                @if(!empty($form['name']))
                <tr>
                    <td>Name</td>
                    <td>{{ $form['name'] }}</td>
                </tr>
                @endif
                <tr>
                    <td>Email</td>
                    <td>{{ $inquiry->email }}</td>
                </tr>
                <tr>
                    <td>Phone</td>
                    <td>{{ $inquiry->phone ?? 'N/A' }}</td>
                </tr>
                @if(!empty($form['country']))
                <tr>
                    <td>Country</td>
                    <td>{{ $form['country'] }}</td>
                </tr>
                @endif
                @if(!empty($form['pickup_location']))
                <tr>
                    <td>Pickup Location</td>
                    <td>{{ $form['pickup_location'] }}</td>
                </tr>
                @endif
                @if(!empty($form['dropoff_location']))
                <tr>
                    <td>Dropoff Location</td>
                    <td>{{ $form['dropoff_location'] }}</td>
                </tr>
                @endif
                @if(!empty($form['travel_date']))
                <tr>
                    <td>Travel Date</td>
                    <td>{{ $form['travel_date'] }}</td>
                </tr>
                @endif
                @if(!empty($form['travel_time']))
                <tr>
                    <td>Travel Time</td>
                    <td>{{ $form['travel_time'] }}</td>
                </tr>
                @endif
                @if(!empty($form['passengers']))
                <tr>
                    <td>Passengers</td>
                    <td>{{ $form['passengers'] }}</td>
                </tr>
                @endif
                @if(!empty($form['requirements']))
                <tr>
                    <td>Requirements</td>
                    <td>{{ $form['requirements'] }}</td>
                </tr>
                @endif
                @if(!empty($form['message']))
                <tr>
                    <td>Message</td>
                    <td>{{ $form['message'] }}</td>
                </tr>
                @endif
                @if(!empty($inquiry->message) && empty($form['message']) && empty($form['requirements']))
                <tr>
                    <td>Message</td>
                    <td>{{ $inquiry->message }}</td>
                </tr>
                @endif
                <tr>
                    <td>Submitted</td>
                    <td>{{ $inquiry->created_at?->format('F j, Y \\a\\t g:i A') }}</td>
                </tr>
            </table>
        </div>

        <div class="section">
            <h2>What Happens Next</h2>
            <p>Our team will review your inquiry and contact you using the details provided. If you need to add more information, reply to this email or contact us directly.</p>
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
                <tr>
                    <td>Website</td>
                    <td><a href="{{ config('app.url') }}">{{ config('app.url') }}</a></td>
                </tr>
            </table>
            <div style="text-align: center;">
                <a href="{{ config('app.url') }}" class="button">Visit TheTaxi</a>
            </div>
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
