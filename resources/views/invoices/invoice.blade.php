<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 13px; color: #333; background: #fff; }
        .page { padding: 40px; max-width: 800px; margin: 0 auto; }

        /* Header */
        .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 40px; border-bottom: 3px solid #1a56db; padding-bottom: 20px; }
        .company-info h1 { font-size: 24px; color: #1a56db; font-weight: 700; }
        .company-info p { color: #555; margin-top: 4px; font-size: 12px; line-height: 1.5; }
        .invoice-meta { text-align: right; }
        .invoice-meta .invoice-label { font-size: 28px; font-weight: 700; color: #1a56db; text-transform: uppercase; letter-spacing: 2px; }
        .invoice-meta table { margin-top: 8px; margin-left: auto; }
        .invoice-meta td { padding: 2px 0 2px 16px; font-size: 12px; }
        .invoice-meta td:first-child { color: #666; text-align: right; }
        .invoice-meta td:last-child { font-weight: 600; }

        /* Parties */
        .parties { display: flex; gap: 40px; margin-bottom: 32px; }
        .party { flex: 1; }
        .party-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: #888; margin-bottom: 8px; }
        .party-name { font-size: 15px; font-weight: 700; color: #111; margin-bottom: 4px; }
        .party p { font-size: 12px; color: #555; line-height: 1.6; }

        /* Status badge */
        .status-badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; }
        .status-issued { background: #dbeafe; color: #1d4ed8; }
        .status-paid   { background: #d1fae5; color: #065f46; }
        .status-void   { background: #fee2e2; color: #991b1b; }

        /* Line items table */
        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
        .items-table thead tr { background: #1a56db; color: #fff; }
        .items-table thead th { padding: 10px 12px; text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }
        .items-table thead th:last-child { text-align: right; }
        .items-table tbody tr { border-bottom: 1px solid #e5e7eb; }
        .items-table tbody tr:nth-child(even) { background: #f9fafb; }
        .items-table tbody td { padding: 10px 12px; font-size: 12px; vertical-align: top; }
        .items-table tbody td:last-child { text-align: right; font-weight: 600; }
        .items-table .item-description { color: #555; font-size: 11px; margin-top: 2px; }

        /* Totals */
        .totals { display: flex; justify-content: flex-end; margin-bottom: 32px; }
        .totals-table { min-width: 280px; }
        .totals-table tr td { padding: 6px 12px; font-size: 13px; }
        .totals-table tr td:first-child { color: #555; }
        .totals-table tr td:last-child { text-align: right; font-weight: 600; }
        .totals-table .discount td { color: #dc2626; }
        .totals-table .grand-total td { border-top: 2px solid #1a56db; padding-top: 10px; font-size: 16px; font-weight: 700; color: #1a56db; }

        /* Payment info */
        .payment-info { background: #f0f9ff; border-left: 4px solid #1a56db; padding: 16px; margin-bottom: 24px; border-radius: 0 8px 8px 0; }
        .payment-info h3 { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: #1a56db; margin-bottom: 8px; }
        .payment-info p { font-size: 12px; color: #444; line-height: 1.6; }

        /* Notes */
        .notes { background: #fffbeb; border-left: 4px solid #f59e0b; padding: 16px; margin-bottom: 24px; border-radius: 0 8px 8px 0; }
        .notes h3 { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: #d97706; margin-bottom: 8px; }
        .notes p { font-size: 12px; color: #444; line-height: 1.6; }
        .distance-breakdown { margin-bottom: 24px; padding: 16px; background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 8px; }
        .distance-breakdown h3 { font-size: 12px; text-transform: uppercase; letter-spacing: 1px; color: #1a56db; margin-bottom: 8px; }
        .distance-breakdown table { width: 100%; border-collapse: collapse; }
        .distance-breakdown td { padding: 4px 0; font-size: 12px; }
        .distance-breakdown td:last-child { text-align: right; font-weight: 600; }

        /* Footer */
        .footer { border-top: 1px solid #e5e7eb; padding-top: 16px; text-align: center; color: #9ca3af; font-size: 11px; line-height: 1.8; }
    </style>
</head>
<body>
<div class="page">

    {{-- HEADER --}}
    <div class="header">
        <div class="company-info">
            @if(!empty($companyLogo))
                <img src="{{ $companyLogo }}" alt="Logo" style="max-height:60px; margin-bottom:8px;">
            @else
                <h1>{{ $companyName }}</h1>
            @endif
            <p>
                {!! nl2br(e($companyAddress)) !!}<br>
                @if($companyPhone) Tel: {{ $companyPhone }}<br>@endif
                @if($companyEmail) Email: {{ $companyEmail }}<br>@endif
                @if($companyWeb) Web: {{ $companyWeb }}@endif
            </p>
        </div>
        <div class="invoice-meta">
            <div class="invoice-label">Invoice</div>
            <table>
                <tr>
                    <td>Invoice No.</td>
                    <td>{{ $invoice->invoice_number }}</td>
                </tr>
                <tr>
                    <td>Booking Ref.</td>
                    <td>{{ $booking->booking_number }}</td>
                </tr>
                <tr>
                    <td>Issue Date</td>
                    <td>{{ $invoice->issue_date->format('d M Y') }}</td>
                </tr>
                @if($invoice->due_date)
                <tr>
                    <td>Due Date</td>
                    <td>{{ $invoice->due_date->format('d M Y') }}</td>
                </tr>
                @endif
                <tr>
                    <td>Status</td>
                    <td>
                        <span class="status-badge status-{{ $invoice->status }}">
                            {{ ucfirst($invoice->status) }}
                        </span>
                    </td>
                </tr>
            </table>
        </div>
    </div>

    {{-- PARTIES --}}
    <div class="parties">
        <div class="party">
            <div class="party-label">Billed To</div>
            <div class="party-name">{{ $invoice->customer_name }}</div>
            <p>
                @if($invoice->customer_email){{ $invoice->customer_email }}<br>@endif
                @if($invoice->customer_phone){{ $invoice->customer_phone }}<br>@endif
                @if($invoice->customer_address){!! nl2br(e($invoice->customer_address)) !!}@endif
            </p>
        </div>
        <div class="party">
            <div class="party-label">Service Details</div>
            <p>
                @if($serviceType)<strong>Service:</strong> {{ $serviceType }}<br>@endif
                @if($fromDate)<strong>From:</strong> {{ $fromDate }}<br>@endif
                @if($toDate)<strong>To:</strong> {{ $toDate }}<br>@endif
                @if($vehicle)<strong>Vehicle:</strong> {{ $vehicle }}<br>@endif
                @if($driver)<strong>Driver:</strong> {{ $driver }}@endif
            </p>
        </div>
    </div>

    {{-- LINE ITEMS --}}
    <table class="items-table">
        <thead>
            <tr>
                <th style="width:40%">Description</th>
                <th style="width:15%">Qty</th>
                <th style="width:20%">Unit Price</th>
                <th style="width:25%">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->line_items as $item)
            <tr>
                <td>
                    {{ $item['description'] }}
                    @if(!empty($item['note']))
                        <div class="item-description">{{ $item['note'] }}</div>
                    @endif
                </td>
                <td>{{ $item['quantity'] ?? 1 }}</td>
                <td>{{ $invoice->currency }} {{ number_format($item['unit_price'] ?? $item['amount'], 2) }}</td>
                <td>{{ $invoice->currency }} {{ number_format($item['amount'], 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    {{-- TOTALS --}}
    <div class="totals">
        <table class="totals-table">
            <tr>
                <td>Subtotal</td>
                <td>{{ $invoice->currency }} {{ number_format($invoice->subtotal, 2) }}</td>
            </tr>
            @if($invoice->discount_amount > 0)
            <tr class="discount">
                <td>Discount</td>
                <td>-{{ $invoice->currency }} {{ number_format($invoice->discount_amount, 2) }}</td>
            </tr>
            @endif
            @if($invoice->tax_amount > 0)
            <tr>
                <td>Tax</td>
                <td>{{ $invoice->currency }} {{ number_format($invoice->tax_amount, 2) }}</td>
            </tr>
            @endif
            <tr class="grand-total">
                <td>Total Due</td>
                <td>{{ $invoice->currency }} {{ number_format($invoice->total_amount, 2) }}</td>
            </tr>
        </table>
    </div>

    {{-- Contractual distance breakdown intentionally hidden from presentation. --}}

    {{-- PAYMENT INFO --}}
    @if($invoice->payment_terms)
    <div class="payment-info">
        <h3>Payment Information</h3>
        <p>{!! nl2br(e($invoice->payment_terms)) !!}</p>
    </div>
    @endif

    {{-- NOTES --}}
    @if($invoice->notes)
    <div class="notes">
        <h3>Notes</h3>
        <p>{!! nl2br(e($invoice->notes)) !!}</p>
    </div>
    @endif

    {{-- FOOTER --}}
    <div class="footer">
        <p>{{ $companyName }} &bull; {{ $companyAddress }}</p>
        <p>Thank you for choosing our services. For queries regarding this invoice, contact {{ $companyEmail }}.</p>
    </div>

</div>
</body>
</html>
