@extends('emails.layouts.master')

@section('title', 'Invoice ' . $invoice->invoice_number . ' - ' . config('app.name'))

@section('header_title', 'Your Invoice Is Ready')
@section('header_subtitle', 'Invoice ' . $invoice->invoice_number)

@section('content')
<p style="margin-bottom:16px; color:#555;">
    Dear {{ $invoice->customer_name }},
</p>
<p style="margin-bottom:24px; color:#555;">
    Please find your invoice for booking <strong>{{ $booking->booking_number }}</strong> attached to this email.
    A summary is provided below.
</p>

{{-- Invoice summary card --}}
<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px; border:1px solid #e5e7eb; border-radius:8px; overflow:hidden;">
    <tr style="background:#1a56db;">
        <td colspan="2" style="padding:12px 20px; color:#fff; font-weight:700; font-size:14px;">
            Invoice Summary
        </td>
    </tr>
    <tr style="border-bottom:1px solid #e5e7eb;">
        <td style="padding:10px 20px; color:#666; font-size:13px; width:45%;">Invoice Number</td>
        <td style="padding:10px 20px; font-weight:600; font-size:13px;">{{ $invoice->invoice_number }}</td>
    </tr>
    <tr style="border-bottom:1px solid #e5e7eb; background:#f9fafb;">
        <td style="padding:10px 20px; color:#666; font-size:13px;">Booking Reference</td>
        <td style="padding:10px 20px; font-weight:600; font-size:13px;">{{ $booking->booking_number }}</td>
    </tr>
    <tr style="border-bottom:1px solid #e5e7eb;">
        <td style="padding:10px 20px; color:#666; font-size:13px;">Issue Date</td>
        <td style="padding:10px 20px; font-weight:600; font-size:13px;">{{ $invoice->issue_date->format('d M Y') }}</td>
    </tr>
    @if($invoice->due_date)
    <tr style="border-bottom:1px solid #e5e7eb; background:#f9fafb;">
        <td style="padding:10px 20px; color:#666; font-size:13px;">Due Date</td>
        <td style="padding:10px 20px; font-weight:600; font-size:13px;">{{ $invoice->due_date->format('d M Y') }}</td>
    </tr>
    @endif
    @if($invoice->discount_amount > 0)
    <tr style="border-bottom:1px solid #e5e7eb;">
        <td style="padding:10px 20px; color:#666; font-size:13px;">Discount</td>
        <td style="padding:10px 20px; font-weight:600; font-size:13px; color:#dc2626;">
            -{{ $invoice->currency }} {{ number_format($invoice->discount_amount, 2) }}
        </td>
    </tr>
    @endif
    <tr style="background:#eff6ff;">
        <td style="padding:12px 20px; color:#1d4ed8; font-weight:700; font-size:15px;">Total Amount</td>
        <td style="padding:12px 20px; font-weight:700; font-size:15px; color:#1d4ed8;">
            {{ $invoice->currency }} {{ number_format($invoice->total_amount, 2) }}
        </td>
    </tr>
</table>

{{-- Contractual distance breakdown intentionally hidden from presentation. --}}

@if($invoice->payment_terms)
<div style="background:#f0f9ff; border-left:4px solid #1a56db; padding:16px; margin-bottom:24px; border-radius:0 8px 8px 0;">
    <p style="font-weight:700; color:#1d4ed8; margin-bottom:8px; font-size:12px; text-transform:uppercase; letter-spacing:1px;">Payment Information</p>
    <p style="color:#444; font-size:13px; line-height:1.6;">{{ $invoice->payment_terms }}</p>
</div>
@endif

@if($invoice->notes)
<div style="background:#fffbeb; border-left:4px solid #f59e0b; padding:16px; margin-bottom:24px; border-radius:0 8px 8px 0;">
    <p style="font-weight:700; color:#d97706; margin-bottom:8px; font-size:12px; text-transform:uppercase; letter-spacing:1px;">Notes</p>
    <p style="color:#444; font-size:13px; line-height:1.6;">{{ $invoice->notes }}</p>
</div>
@endif

<p style="color:#888; font-size:12px; margin-top:24px;">
    The full invoice PDF is attached to this email. If you have any questions,
    please contact us at {{ config('mail.from.address') }}.
</p>
@endsection
