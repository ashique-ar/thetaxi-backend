<!doctype html>
<html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;color:#1f2937;font-size:12px}h1{font-size:20px}.meta,.totals{width:100%;border-collapse:collapse}.meta td,.totals td{padding:6px;border-bottom:1px solid #e5e7eb}.amount{text-align:right}.muted{color:#6b7280}</style></head>
<body>
<h1>Corporate Account Statement</h1>
<p class="muted">Statement {{ $settlement->settlement_number }} · {{ $statement['period_start'] }} to {{ $statement['period_end'] }}</p>
<table class="totals">
<tr><td>Opening balance</td><td class="amount">{{ number_format($statement['opening_balance'],2) }}</td></tr>
<tr><td>Period charges</td><td class="amount">{{ number_format($statement['charges'],2) }}</td></tr>
<tr><td>Payments</td><td class="amount">({{ number_format($statement['payments'],2) }})</td></tr>
<tr><td>Refunds</td><td class="amount">{{ number_format($statement['refunds'],2) }}</td></tr>
<tr><td>Adjustments</td><td class="amount">{{ number_format($statement['adjustments'],2) }}</td></tr>
<tr><td><strong>Closing balance</strong></td><td class="amount"><strong>{{ number_format($statement['account_closing_balance'],2) }}</strong></td></tr>
</table>
<p class="muted">Generated {{ $statement['generated_at'] }}. This statement is reproduced from the immutable settlement snapshot.</p>
</body></html>
