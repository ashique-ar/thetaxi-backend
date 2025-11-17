@component('mail::message')
# Booking Confirmation

Dear {{ $booking->customer_name }},

Thank you for your booking with TheTaxi! We're excited to serve you.

## Booking Reference
**{{ $booking->reference }}**

---

## Booking Details

| Detail | Information |
|--------|-------------|
| **Name** | {{ $booking->customer_name }} |
| **Email** | {{ $booking->customer_email }} |
| **Phone** | {{ $booking->customer_phone }} |
| **Payment Type** | @switch($booking->payment_type)
    @case('full') Full Payment @break
    @case('advance') 50% Advance Payment @break
    @case('quotation') Quotation Request @break
@endswitch |
| **Payment Method** | @switch($booking->payment_method)
    @case('paypal') PayPal @break
    @case('stripe') Credit Card (Stripe) @break
    @case('bank_transfer') Bank Transfer @break
    @case('online_banking') Online Banking @break
@endswitch |
| **Status** | @switch($booking->status)
    @case('pending') Pending @break
    @case('confirmed') Confirmed @break
    @case('cancelled') Cancelled @break
    @case('completed') Completed @break
@endswitch |

---

## Order Summary

@php
    $cartItems = json_decode($booking->cart_items, true) ?? [];
@endphp

@if(!empty($cartItems))
| Vehicle | Days | Price/Day | Subtotal |
|---------|------|-----------|----------|
@foreach($cartItems as $item)
| {{ $item['name'] ?? 'Vehicle' }} | {{ $item['days'] ?? 1 }} | ${{ number_format($item['price'] ?? 0, 2) }} | ${{ number_format(($item['price'] ?? 0) * ($item['days'] ?? 1), 2) }} |
@endforeach
@endif

---

## Price Breakdown

- **Subtotal**: ${{ number_format($booking->subtotal, 2) }}
- **Service Fee**: ${{ number_format($booking->service_fee, 2) }}
- **Tax (10%)**: ${{ number_format($booking->tax, 2) }}
@if($booking->discount > 0)
- **Discount**: -${{ number_format($booking->discount, 2) }}
@endif
- **Total Amount**: **${{ number_format($booking->total_amount, 2) }}**
@if($booking->payment_type !== 'quotation')
- **Amount to Pay**: **${{ number_format($booking->payment_amount, 2) }}**
@endif

---

@if($booking->special_notes)
## Special Requests
{{ $booking->special_notes }}
@endif

@if(!empty($booking->flight_details))
## Flight Details
@php
    $flight = $booking->flight_details ?? [];
@endphp
- **Airline**: {{ $flight['airline'] ?? 'N/A' }}
- **Flight Number**: {{ $flight['flight_number'] ?? 'N/A' }}
- **Arrival Date**: {{ $flight['arrival_date'] ?? 'N/A' }}
- **Arrival Time**: {{ $flight['arrival_time'] ?? 'N/A' }}
@endif

---

## What's Next?

@if($booking->payment_type === 'quotation')
Our team will review your quotation request and contact you at **{{ $booking->customer_phone }}** within 24 hours with a detailed quote and booking options.
@elseif($booking->payment_type === 'advance')
You have successfully paid 50% of your booking amount. The remaining balance will be collected at the time of vehicle pickup.

**Important**: Please carry a valid government-issued ID and a credit card for the security deposit at pickup.
@else
Your payment has been processed successfully. Your vehicle will be ready for pickup on the scheduled date.

**Important**: Please carry a valid government-issued ID and a credit card for the security deposit at pickup.
@endif

---

## Questions?

If you have any questions about your booking, please don't hesitate to contact us:
- **Phone**: +94 (0)XX XXX XXXX
- **Email**: {{ config('mail.from.address') }}
- **Website**: {{ config('app.url') }}

We look forward to serving you!

@component('mail::button', ['url' => config('app.url')])
View Your Booking
@endcomponent

Thanks,  
{{ config('app.name') }} Team
@endcomponent
