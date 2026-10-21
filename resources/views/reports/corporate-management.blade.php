<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <style>
        body {
            font-family: DejaVu Sans;
            font-size: 10px;
            color: #20242a
        }

        h1 {
            font-size: 18px
        }

        table {
            width: 100%;
            border-collapse: collapse
        }

        th,
        td {
            padding: 6px;
            border: 1px solid #ddd;
            text-align: left
        }

        th {
            background: #f2f4f7
        }
    </style>
</head>

<body>
    <h1>Corporate Management Report</h1>
    <p>Period: {{ $payload['period']['from'] ?: 'All time' }} to {{ $payload['period']['to'] ?: 'Present' }}</p>
    <p>Bookings: {{ $payload['operational']['booking_count'] }} | Trips: {{ $payload['operational']['trip_count'] }}
        @if($payload['financial_metrics_visible'])
            @foreach($payload['operational']['currency_totals'] as $total)
                @php($digits = app(\App\Services\CurrencyService::class)->fractionDigits($total['currency'] ?: 'LKR'))
                <br>{{ $total['currency'] ?: 'Currency unavailable' }} estimated: {{ number_format($total['estimated_value'], $digits) }}
                | finalized: {{ number_format($total['finalized_charges'], $digits) }}
            @endforeach
        @endif
    </p>
    <p>Contractual distance: {{ number_format($payload['distance']['contractual_km'], 3) }} km (pricing) | Operational
        distance: {{ number_format($payload['distance']['operational_km'], 3) }} km (telemetry evidence)</p>
    @if(isset($payload['financial_period']['summary']))
        @php($finance = $payload['financial_period']['summary'])
        @php($digits = app(\App\Services\CurrencyService::class)->fractionDigits($finance['currency'] ?: 'LKR'))
        <h2>Billing period totals</h2>
        <p>{{ $finance['period_start'] }} to {{ $finance['period_end'] }} | {{ $finance['invoice_number'] ?: $finance['settlement_number'] }} | {{ $finance['currency'] }}<br>Charges: {{ number_format($finance['charges_total'], $digits) }} | Payments: {{ number_format($finance['payments_total'], $digits) }} | Refunds: {{ number_format($finance['refunds_total'], $digits) }} | Adjustments: {{ number_format($finance['adjustments_total'], $digits) }} | Outstanding: {{ number_format($finance['outstanding_total'], $digits) }}</p>
    @endif
    <h2>Monthly travel activity</h2>
    <table>
        <thead><tr><th>Month</th><th>Currency</th><th>Bookings</th><th>Trips</th>@if($payload['financial_metrics_visible'])<th>Estimated value</th><th>Finalized charges</th>@endif</tr></thead>
        <tbody>
            @foreach($payload['monthly_trends'] as $month)
                <tr><td>{{ $month['month'] }}</td><td>{{ $month['currency'] ?: 'Currency unavailable' }}</td><td>{{ $month['bookings'] }}</td><td>{{ $month['trips'] }}</td>@if($payload['financial_metrics_visible'])@php($digits = app(\App\Services\CurrencyService::class)->fractionDigits($month['currency'] ?: 'LKR'))<td>{{ number_format($month['estimated_value'], $digits) }}</td><td>{{ number_format($month['finalized_charges'], $digits) }}</td>@endif</tr>
            @endforeach
        </tbody>
    </table>
    <table>
        <thead>
            <tr>
                <th>Dimension</th>
                <th>Label</th>
                <th>Bookings</th>
                <th>Trips</th>
                @if($payload['financial_metrics_visible'])
                    <th>Currency</th>
                    <th>Estimated</th>
                    <th>Finalized</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    @foreach ($row as $cell)
                        <td>{{ $cell }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</body>

</html>
