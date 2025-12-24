<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Quotation Request Received</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            background-color: #c91c23;
            color: white;
            padding: 20px;
            text-align: center;
            border-radius: 5px 5px 0 0;
        }

        .content {
            background-color: #f9f9f9;
            padding: 20px;
            border-radius: 0 0 5px 5px;
            border: 1px solid #ddd;
        }

        .section {
            margin-bottom: 20px;
            padding: 15px;
            background-color: white;
            border-radius: 5px;
            border-left: 4px solid #c91c23;
        }

        .section h3 {
            margin-top: 0;
            color: #c91c23;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 10px;
            margin-bottom: 10px;
        }

        .info-label {
            font-weight: bold;
            color: #666;
        }

        .success {
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
        }

        .next-steps {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            padding: 15px;
            border-radius: 5px;
            text-align: center;
        }

        .contact-info {
            background-color: #e3f2fd;
            border: 1px solid #bbdefb;
            padding: 15px;
            border-radius: 5px;
            text-align: center;
        }

        .btn {
            display: inline-block;
            padding: 12px 25px;
            background-color: #c91c23;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            margin: 10px 5px;
        }

        .footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #eee;
            color: #666;
            font-size: 0.9em;
        }
    </style>
</head>

<body>
    <div class="header">
        <img src="{{ asset('assets/img/header-logo.png') }}" alt="TheTaxi" style="max-height: 44px; margin-bottom: 10px;">
        <h1>✅ Quotation Request Received</h1>
        <p>TheTaxi - Premium Transport Services</p>
    </div>

    <div class="content">
        <div class="success">
            <h3>🎉 Thank You, {{ $customerName }}!</h3>
            <p>Your quotation request for <strong>{{ $vehicleGroup->name }}</strong> has been successfully received and
                is being processed.</p>
        </div>

        <div class="section">
            <h3>📋 Your Request Details</h3>
            <div class="info-grid">
                <div class="info-label">Reference Number:</div>
                <div><strong>{{ $inquiryNumber }}</strong></div>

                <div class="info-label">Vehicle Group:</div>
                <div>{{ $vehicleGroup->name }}</div>

                @if ($vehicleGroup->description)
                    <div class="info-label">Description:</div>
                    <div>{{ $vehicleGroup->description }}</div>
                @endif

                <div class="info-label">Submitted:</div>
                <div>{{ $inquiry->created_at->format('F j, Y \a\t g:i A') }}</div>

                <div class="info-label">Status:</div>
                <div><span style="color: #28a745; font-weight: bold;">Under Review</span></div>
            </div>
        </div>

        <div class="section">
            <h3>⏱️ What Happens Next</h3>
            <ol>
                <li><strong>Review Process:</strong> Our transport specialists are reviewing your requirements</li>
                <li><strong>Route Analysis:</strong> We're calculating the optimal route and pricing for your journey
                </li>
                <li><strong>Custom Quote:</strong> A detailed quotation will be prepared specifically for your needs
                </li>
                <li><strong>Direct Contact:</strong> Our team will contact you directly with the quotation</li>
            </ol>
        </div>

        <div class="next-steps">
            <h3>📞 Expected Response Time</h3>
            <p><strong>{{ $estimatedResponseTime }}</strong></p>
            <p>Our corporate transport team will contact you within this timeframe with a detailed quotation.</p>
        </div>

        <div class="section">
            <h3>🚗 Why Request a Quotation?</h3>
            <p>You're receiving a custom quotation because:</p>
            <ul>
                <li>Your journey requires specialized routing or pricing</li>
                <li>The service involves unique requirements or locations</li>
                <li>We want to ensure you receive the most accurate pricing</li>
                <li>Our team can optimize the service for your specific needs</li>
            </ul>
        </div>

        <div class="contact-info">
            <h3>📞 Need Immediate Assistance?</h3>
            <p>If you have any questions or need to modify your request, contact us:</p>
            <div style="margin: 15px 0;">
                <div style="margin: 5px 0;">
                    📧 Email: <a href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a>
                </div>
                <div style="margin: 5px 0;">
                    📱 Phone: <a href="tel:{{ $supportPhone }}">{{ $supportPhone }}</a>
                </div>
            </div>
            <p><em>Please reference your inquiry number: <strong>{{ $inquiryNumber }}</strong></em></p>
        </div>

        <div class="section">
            <h3>🌟 About TheTaxi Corporate Services</h3>
            <p>We specialize in providing reliable, professional transport solutions for businesses and individuals.
                Our fleet of well-maintained vehicles and experienced drivers ensure comfortable and punctual service
                for all your transport needs.</p>
        </div>

        <div class="footer">
            <p>Thank you for choosing TheTaxi for your transport needs.</p>
            <p>Reference: {{ $inquiryNumber }} | Submitted: {{ $inquiry->created_at->format('Y-m-d H:i:s') }}</p>
            <p>This is an automated confirmation email. Please do not reply to this email.</p>
        </div>
    </div>
</body>

</html>
