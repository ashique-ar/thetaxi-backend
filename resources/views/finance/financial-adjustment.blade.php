<!doctype html><html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans;color:#20242a}h1{font-size:20px}table{width:100%;border-collapse:collapse}td,th{border:1px solid #ddd;padding:8px;text-align:left}.amount{text-align:right}</style></head><body>
<h1>{{ str($adjustment->type)->replace('_', ' ')->title() }}</h1>
<p>Document reference: {{ $adjustment->id }}</p>
<table><tr><th>Invoice</th><td>{{ $adjustment->settlement?->invoice_number ?: $adjustment->settlement?->settlement_number }}</td></tr><tr><th>Booking</th><td>{{ $adjustment->booking?->booking_number }}</td></tr><tr><th>Amount</th><td class="amount">{{ number_format($adjustment->amount, 2) }}</td></tr><tr><th>Reason</th><td>{{ $adjustment->reason }}</td></tr><tr><th>Transaction reference</th><td>{{ $adjustment->reference ?: '-' }}</td></tr><tr><th>Recorded</th><td>{{ $adjustment->created_at?->format('Y-m-d H:i:s') }}</td></tr></table>
</body></html>
