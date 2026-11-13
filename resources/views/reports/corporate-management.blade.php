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
    <h2>Report context</h2>
    <table><tbody>@foreach($payload['export_metadata'] as $label => $value)<tr><th>{{ $label }}</th><td>{{ $value }}</td></tr>@endforeach</tbody></table>
    <p>Travel activity dates: {{ $payload['period']['from'] ?: 'All dates' }} to {{ $payload['period']['to'] ?: 'Present' }}</p>
    <p>Bookings: {{ $payload['operational']['booking_count'] }} | Trips: {{ $payload['operational']['trip_count'] }}
        @if($payload['financial_metrics_visible'])
            @if(($payload['operational']['unpriced_trip_count'] ?? 0) > 0)
                | Trips without a saved estimate: {{ $payload['operational']['unpriced_trip_count'] }}
            @endif
            @foreach($payload['operational']['currency_totals'] as $total)
                @php($digits = app(\App\Services\CurrencyService::class)->fractionDigits($total['currency'] ?: 'LKR'))
                <br>{{ $total['currency'] ?: 'Currency unavailable' }} estimated: {{ $total['estimated_value'] === null ? 'Incomplete' : number_format($total['estimated_value'], $digits) }}
                | finalized trip value: {{ number_format($total['finalized_charges'], $digits) }}
            @endforeach
        @endif
    </p>
    @if(!empty($payload['activity_comparison']))
        @php($comparison = $payload['activity_comparison'])
        <h2>Travel activity comparison</h2>
        <p>Selected activity dates compared with the preceding equal-length period: {{ $payload['period']['from'] }} to {{ $payload['period']['to'] }} vs {{ $comparison['period']['date_from'] }} to {{ $comparison['period']['date_to'] }}. Billing-period balances are not compared.</p>
        <table><thead><tr><th>Measure</th><th>Current</th><th>Previous</th><th>Change</th><th>Change (%)</th></tr></thead><tbody>
            @foreach(['Bookings' => 'booking_count', 'Trips' => 'trip_count', 'Finalized trips' => 'finalized_trip_count'] as $label => $key)
                @php($metric = $comparison[$key])
                <tr><td>{{ $label }}</td><td>{{ number_format($metric['current']) }}</td><td>{{ number_format($metric['previous']) }}</td><td>{{ ($metric['delta'] > 0 ? '+' : '') . number_format($metric['delta']) }}</td><td>{{ $metric['percent_change'] === null ? ($metric['delta'] == 0 ? '0.0%' : 'New activity') : (($metric['percent_change'] > 0 ? '+' : '') . number_format($metric['percent_change'], 1) . '%') }}</td></tr>
            @endforeach
            @if(isset($comparison['unpriced_trip_count']))
                @php($metric = $comparison['unpriced_trip_count'])
                <tr><td>Unpriced trips</td><td>{{ number_format($metric['current']) }}</td><td>{{ number_format($metric['previous']) }}</td><td>{{ ($metric['delta'] > 0 ? '+' : '') . number_format($metric['delta']) }}</td><td>{{ $metric['percent_change'] === null ? ($metric['delta'] == 0 ? '0.0%' : 'New activity') : (($metric['percent_change'] > 0 ? '+' : '') . number_format($metric['percent_change'], 1) . '%') }}</td></tr>
            @endif
        </tbody></table>
        @if($payload['financial_metrics_visible'])
            <table><thead><tr><th>Currency</th><th>Measure</th><th>Current</th><th>Previous</th><th>Change</th><th>Change (%)</th></tr></thead><tbody>
                @foreach($comparison['currency_totals'] as $total)
                    @php($digits = app(\App\Services\CurrencyService::class)->fractionDigits($total['currency'] ?: 'LKR'))
                    <tr><td>{{ $total['currency'] ?: 'Currency unavailable' }}</td><td>Estimated value</td><td>{{ $total['current_estimated_value'] === null ? 'Incomplete' : number_format($total['current_estimated_value'], $digits) }}</td><td>{{ $total['previous_estimated_value'] === null ? 'Incomplete' : number_format($total['previous_estimated_value'], $digits) }}</td><td>{{ $total['estimated_delta'] === null ? 'Incomplete in one or both periods' : number_format($total['estimated_delta'], $digits) }}</td><td>{{ $total['estimated_percent_change'] === null ? ($total['estimated_delta'] === null ? 'Unavailable' : 'New or no prior value') : (($total['estimated_percent_change'] > 0 ? '+' : '') . number_format($total['estimated_percent_change'], 1) . '%') }}</td></tr>
                    @php($metric = $total['finalized_charges'])
                    <tr><td>{{ $total['currency'] ?: 'Currency unavailable' }}</td><td>Finalized trip value</td><td>{{ number_format($metric['current'], $digits) }}</td><td>{{ number_format($metric['previous'], $digits) }}</td><td>{{ ($metric['delta'] > 0 ? '+' : '') . number_format($metric['delta'], $digits) }}</td><td>{{ $metric['percent_change'] === null ? ($metric['delta'] == 0 ? '0.0%' : 'New activity') : (($metric['percent_change'] > 0 ? '+' : '') . number_format($metric['percent_change'], 1) . '%') }}</td></tr>
                @endforeach
            </tbody></table>
        @endif
    @endif
    <p>Contractual distance: {{ number_format($payload['distance']['contractual_km'], 3) }} km (pricing) | Operational
        distance: {{ number_format($payload['distance']['operational_km'], 3) }} km (telemetry evidence)</p>
    @if(!empty($payload['financial_periods']))
        <h2>Billing period totals</h2>
        @foreach($payload['financial_periods'] as $finance)
            @php($digits = app(\App\Services\CurrencyService::class)->fractionDigits($finance['currency'] ?: 'LKR'))
            <p>{{ $finance['period_start'] }} to {{ $finance['period_end'] }} | {{ $finance['invoice_number'] ?: $finance['settlement_number'] }} | {{ $finance['currency'] }} | {{ $finance['status'] }} | Due {{ $finance['due_date'] ?: 'On receipt' }}<br>Charges: {{ number_format($finance['charges_total'], $digits) }} | Payments: {{ number_format($finance['payments_total'], $digits) }} | Refunds: {{ number_format($finance['refunds_total'], $digits) }} | Adjustments: {{ number_format($finance['adjustments_total'], $digits) }} | Outstanding: {{ number_format($finance['outstanding_total'], $digits) }}</p>
            @if(!empty($finance['conversion_rates']))
                <table><thead><tr><th>Source currency</th><th>Invoice currency</th><th>Invoice units per source unit</th><th>Rate captured from</th><th>Rate captured through</th><th>Rate source</th></tr></thead><tbody>
                    @foreach($finance['conversion_rates'] as $rate)
                        <tr><td>{{ $rate['source_currency'] }}</td><td>{{ $rate['invoice_currency'] }}</td><td>{{ number_format($rate['rate'], 10) }}</td><td>{{ $rate['rate_at'] ?: 'Not recorded' }}</td><td>{{ $rate['rate_until'] ?: 'Not recorded' }}</td><td>{{ $rate['fx_source'] ?: 'Not recorded' }}</td></tr>
                    @endforeach
                </tbody></table>
            @endif
            @if(!empty($finance['webxpay_payments']))
                <h3>WebXPay invoice payments and LKR conversions</h3>
                <table><thead><tr><th>Invoice amount</th><th>Invoice currency</th><th>Gateway amount</th><th>Gateway currency</th><th>Invoice-to-LKR rate</th><th>Gateway-to-LKR rate</th><th>Rate captured</th><th>Attempt status</th><th>Gateway reference</th><th>Paid at</th></tr></thead><tbody>
                    @foreach($finance['webxpay_payments'] as $attempt)
                        @php($invoiceDigits = app(\App\Services\CurrencyService::class)->fractionDigits($attempt['invoice_currency'] ?: 'LKR'))
                        @php($gatewayDigits = app(\App\Services\CurrencyService::class)->fractionDigits($attempt['gateway_currency'] ?: 'LKR'))
                        <tr><td>{{ number_format($attempt['invoice_amount'], $invoiceDigits) }}</td><td>{{ $attempt['invoice_currency'] }}</td><td>{{ number_format($attempt['gateway_amount'], $gatewayDigits) }}</td><td>{{ $attempt['gateway_currency'] }}</td><td>{{ number_format($attempt['fx_rate_to_lkr'], 10) }}</td><td>{{ number_format($attempt['gateway_fx_rate_to_lkr'], 10) }}</td><td>{{ $attempt['fx_rate_at'] ?: 'Not recorded' }}</td><td>{{ $attempt['status'] }}</td><td>{{ $attempt['transaction_reference'] ?: 'Not recorded' }}</td><td>{{ $attempt['paid_at'] ?: 'Not paid' }}</td></tr>
                    @endforeach
                </tbody></table>
            @endif
        @endforeach
    @endif
    <h2>Monthly travel activity</h2>
    <table>
        <thead><tr><th>Month</th><th>Currency</th><th>Bookings</th><th>Trips</th>@if($payload['financial_metrics_visible'])<th>Unpriced trips</th><th>Estimated value</th><th>Finalized trip value</th>@endif</tr></thead>
        <tbody>
            @foreach($payload['monthly_trends'] as $month)
                <tr><td>{{ $month['month'] }}</td><td>{{ $month['currency'] ?: 'Currency unavailable' }}</td><td>{{ $month['bookings'] }}</td><td>{{ $month['trips'] }}</td>@if($payload['financial_metrics_visible'])@php($digits = app(\App\Services\CurrencyService::class)->fractionDigits($month['currency'] ?: 'LKR'))<td>{{ $month['unpriced_trip_count'] }}</td><td>{{ $month['estimated_value'] === null ? 'Incomplete' : number_format($month['estimated_value'], $digits) }}</td><td>{{ number_format($month['finalized_charges'], $digits) }}</td>@endif</tr>
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
                    <th>Unpriced trips</th>
                    <th>Currency</th>
                    <th>Estimated</th>
                    <th>Finalized trip value</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    @foreach ($row as $index => $cell)
                        @if($payload['financial_metrics_visible'] && in_array($index, [6, 7], true))
                            @php($rowDigits = app(\App\Services\CurrencyService::class)->fractionDigits($row[5] ?: 'LKR'))
                            <td>{{ $cell === null && $index === 6 ? 'Incomplete' : number_format((float) $cell, $rowDigits) }}</td>
                        @else
                            <td>{{ $cell }}</td>
                        @endif
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</body>

</html>
