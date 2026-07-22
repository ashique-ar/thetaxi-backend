<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <title>{{ $settlement->invoice_number }}</title>
    <style>
        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            color: #1f2937;
            font-size: 11px;
            margin: 32px
        }

        h1 {
            color: #1d4ed8;
            margin: 0
        }

        .head {
            display: flex;
            justify-content: space-between;
            border-bottom: 3px solid #1d4ed8;
            padding-bottom: 16px;
            margin-bottom: 20px
        }

        .meta {
            text-align: right
        }

        .box {
            background: #f8fafc;
            padding: 12px;
            margin: 12px 0
        }

        table {
            width: 100%;
            border-collapse: collapse
        }

        th {
            background: #1d4ed8;
            color: white;
            text-align: left;
            padding: 8px
        }

        td {
            padding: 8px;
            border-bottom: 1px solid #e5e7eb
        }

        .right {
            text-align: right
        }

        .totals {
            width: 45%;
            margin-left: auto;
            margin-top: 18px
        }

        .grand td {
            font-size: 14px;
            font-weight: bold;
            border-top: 2px solid #1d4ed8
        }

        .muted {
            color: #64748b
        }
    </style>
</head>

<body>
    <div class="head">
        <div>
            <h1>Account Invoice</h1>
            <p>{{ $ownerName }}</p>
        </div>
        <div class="meta"><strong>{{ $settlement->invoice_number }}</strong><br>Settlement
            {{ $settlement->settlement_number }}<br>Issued
            {{ optional($settlement->issued_at)->format('d M Y') }}<br>Due
            {{ optional($settlement->due_date)->format('d M Y') ?: 'On receipt' }}</div>
    </div>
    <div class="box"><strong>Billing period:</strong> {{ $settlement->period_start->format('d M Y') }} –
        {{ $settlement->period_end->format('d M Y') }} &nbsp; <strong>Cycle:</strong>
        {{ ucfirst($settlement->billing_cycle) }}</div>
    <table>
        <thead>
            <tr>
                <th>Booking</th>
                <th>Date</th>
                <th>Passenger / reference</th>
                <th class="right">Charge</th>
                <th class="right">Paid</th>
                <th class="right">Outstanding</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($settlement->items as $item)
                <tr>
                    <td>{{ $item->booking->booking_number ?? $item->booking_id }}</td>
                    <td>{{ optional($item->booking->booking_date)->format('d M Y') }}</td>
                    <td>{{ $item->booking->confirmation_number ?? '-' }}</td>
                    <td class="right">{{ number_format($item->charge_amount, 2) }}</td>
                    <td class="right">{{ number_format($item->paid_before_amount + $item->allocated_amount, 2) }}</td>
                    <td class="right">{{ number_format($item->outstanding_amount, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <table class="totals">
        <tr>
            <td>Charges</td>
            <td class="right">{{ number_format($settlement->charges_total, 2) }}</td>
        </tr>
        <tr>
            <td>Payments</td>
            <td class="right">({{ number_format($settlement->payments_total, 2) }})</td>
        </tr>
        <tr>
            <td>Refunds / credits</td>
            <td class="right">({{ number_format($settlement->refunds_total, 2) }})</td>
        </tr>
        <tr>
            <td>Adjustments</td>
            <td class="right">{{ number_format($settlement->adjustments_total, 2) }}</td>
        </tr>
        <tr class="grand">
            <td>Amount due</td>
            <td class="right">{{ number_format($settlement->outstanding_total, 2) }}
                {{ $settlement->items->first()?->booking?->currency ?? 'LKR' }}</td>
        </tr>
    </table>
    @if ($settlement->notes)
        <div class="box"><strong>Notes</strong><br>{{ $settlement->notes }}</div>
    @endif
    <p class="muted">Generated {{ now()->format('d M Y H:i') }}. Payment references and allocations remain available
        in the settlement audit history.</p>
</body>

</html>
