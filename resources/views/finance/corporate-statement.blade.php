<!doctype html>
<html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;color:#1f2937;font-size:12px}h1{font-size:20px}.meta,.totals{width:100%;border-collapse:collapse}.meta td,.totals td{padding:6px;border-bottom:1px solid #e5e7eb}.amount{text-align:right}.muted{color:#6b7280}</style></head>
<body>
@php($currency = strtoupper((string) ($statement['currency'] ?? data_get($settlement->billing_terms_snapshot, 'currency') ?? 'LKR')))
@php($digits = app(\App\Services\CurrencyService::class)->fractionDigits($currency))
<h1>Corporate Account Statement</h1>
<p class="muted">Statement {{ $settlement->settlement_number }} · {{ $statement['period_start'] }} to {{ $statement['period_end'] }}</p>
<table class="totals">
<tr><td>Opening balance</td><td class="amount">{{ $currency }} {{ number_format($statement['opening_balance'], $digits) }}</td></tr>
@foreach (($statement['opening_balance_breakdown'] ?? []) as $opening)
    @if ($opening['source_currency'] !== $currency)
        @php($sourceDigits = app(\App\Services\CurrencyService::class)->fractionDigits($opening['source_currency']))
        <tr class="muted"><td>Prior invoice {{ $opening['invoice_number'] ?: $opening['settlement_id'] }}: {{ $opening['source_currency'] }} {{ number_format($opening['source_amount'], $sourceDigits) }} converted at {{ number_format($opening['fx_rate_to_invoice'], 8) }}</td><td class="amount">{{ $currency }} {{ number_format($opening['invoice_amount'], $digits) }}</td></tr>
    @endif
@endforeach
<tr><td>Period charges</td><td class="amount">{{ $currency }} {{ number_format($statement['charges'], $digits) }}</td></tr>
<tr><td>Payments</td><td class="amount">({{ $currency }} {{ number_format($statement['payments'], $digits) }})</td></tr>
<tr><td>Refunds</td><td class="amount">{{ $currency }} {{ number_format($statement['refunds'], $digits) }}</td></tr>
<tr><td>Adjustments</td><td class="amount">{{ $currency }} {{ number_format($statement['adjustments'], $digits) }}</td></tr>
<tr><td>Invoice balance for this period</td><td class="amount">{{ $currency }} {{ number_format($statement['period_closing_balance'], $digits) }}</td></tr>
<tr><td><strong>Total account closing balance</strong></td><td class="amount"><strong>{{ $currency }} {{ number_format($statement['account_closing_balance'], $digits) }}</strong></td></tr>
</table>
<p class="muted">Generated {{ $statement['generated_at'] }}. This statement is reproduced from the immutable settlement snapshot.</p>
</body></html>
