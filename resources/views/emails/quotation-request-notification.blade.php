<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>New Quotation Request</title>
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

        .urgent {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }

        .action-required {
            background-color: #d1ecf1;
            border: 1px solid #bee5eb;
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
        <h1>🚗 New Quotation Request</h1>
        <p>Corporate Transport Services</p>
    </div>

    <div class="content">
        <div class="urgent">
            <strong>⚠️ Action Required:</strong> A new quotation request has been submitted for
            <strong>{{ $vehicleGroup->name }}</strong>.
            Distance calculation was not possible due to missing coordinates, requiring manual quotation.
        </div>

        <div class="section">
            <h3>📋 Inquiry Details</h3>
            <div class="info-grid">
                <div class="info-label">Inquiry ID:</div>
                <div>{{ $inquiry->id }}</div>

                <div class="info-label">Vehicle Group:</div>
                <div><strong>{{ $vehicleGroup->name }}</strong></div>

                <div class="info-label">Service Type:</div>
                <div>{{ $serviceType }}</div>

                <div class="info-label">Submitted:</div>
                <div>{{ $inquiry->created_at->format('Y-m-d H:i:s') }}</div>

                <div class="info-label">Priority:</div>
                <div><span style="color: #dc3545; font-weight: bold;">HIGH</span></div>
            </div>
        </div>

        <div class="section">
            <h3>👤 Customer Information</h3>
            <div class="info-grid">
                <div class="info-label">Name:</div>
                <div><strong>{{ $customerName }}</strong></div>

                <div class="info-label">Email:</div>
                <div><a href="mailto:{{ $customerEmail }}">{{ $customerEmail }}</a></div>

                <div class="info-label">Phone:</div>
                <div><a href="tel:{{ $customerPhone }}">{{ $customerPhone }}</a></div>

                @if ($companyName)
                    <div class="info-label">Company:</div>
                    <div>{{ $companyName }}</div>
                @endif
            </div>
        </div>

        <div class="section">
            <h3>🗓️ Travel Details</h3>
            <div class="info-grid">
                @if ($travelDate)
                    <div class="info-label">Date:</div>
                    <div>{{ $travelDate }}</div>
                @endif

                @if ($travelTime)
                    <div class="info-label">Time:</div>
                    <div>{{ $travelTime }}</div>
                @endif

                @if ($pickupLocation)
                    <div class="info-label">Pickup:</div>
                    <div>{{ $pickupLocation }}</div>
                @endif

                @if ($dropoffLocation)
                    <div class="info-label">Dropoff:</div>
                    <div>{{ $dropoffLocation }}</div>
                @endif

                @if ($passengers)
                    <div class="info-label">Passengers:</div>
                    <div>{{ $passengers }}</div>
                @endif
            </div>
        </div>

        @if ($specialRequirements)
            <div class="section">
                <h3>📝 Special Requirements</h3>
                <p>{{ $specialRequirements }}</p>
            </div>
        @endif

        <div class="section">
            <h3>🔍 Technical Issue</h3>
            <p><strong>Coordinates Missing:</strong> The automated distance calculation failed due to missing or invalid
                location coordinates. This requires manual intervention to:</p>
            <ul>
                <li>Verify the exact pickup and dropoff locations</li>
                <li>Calculate accurate distance and travel time</li>
                <li>Prepare custom pricing based on route requirements</li>
                <li>Consider any special routing or accessibility needs</li>
            </ul>
        </div>

        <div class="action-required">
            <h3>🎯 Next Steps</h3>
            <p><strong>Response Required Within: 2 Business Hours</strong></p>
            <p>Please:</p>
            <ol style="text-align: left; display: inline-block;">
                <li>Contact the customer to confirm exact locations</li>
                <li>Calculate route distance and duration manually</li>
                <li>Prepare detailed quotation with pricing breakdown</li>
                <li>Send quotation to customer and update inquiry status</li>
            </ol>
        </div>

        <div class="footer">
            <p>This email was automatically generated by the TheTaxi booking system.</p>
            <p>Inquiry ID: {{ $inquiry->id }} | Generated: {{ now()->format('Y-m-d H:i:s') }}</p>
        </div>
    </div>
</body>

</html>
