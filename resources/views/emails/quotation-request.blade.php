@component('mail::message')
# Quotation Request Received

Dear {{ $booking->customer_name }},

Thank you for submitting a quotation request to TheTaxi! We have received your inquiry and will get back to you shortly.

## Quotation Reference
**{{ $booking->reference }}**

---

## Your Request Details

| Detail | Information |
|--------|-------------|
| **Name** | {{ $booking->customer_name }} |
| **Email** | {{ $booking->customer_email }} |
| **Phone** | {{ $booking->customer_phone }} |
| **Location** | {{ $booking->customer_city }}@if($booking->customer_country), {{ $booking->customer_country }}@endif |
| **Preferred Contact Time** | @switch($booking->contact_time)
    @case('morning') Morning (9 AM - 12 PM) @break
    @case('afternoon') Afternoon (12 PM - 5 PM) @break
    @case('evening') Evening (5 PM - 8 PM) @break
    @case('anytime') Anytime @break
@endswitch |
| **Budget Range** | @switch($booking->budget_range)
    @case('under-500') Under $500 @break
    @case('500-1000') $500 - $1,000 @break
    @case('1000-2000') $1,000 - $2,000 @break
    @case('over-2000') Over $2,000 @break
@endswitch |

---

## Vehicles of Interest

@php
    $cartItems = json_decode($booking->cart_items, true) ?? [];
@endphp

@if(!empty($cartItems))
@foreach($cartItems as $item)
- **{{ $item['name'] ?? 'Vehicle' }}** ({{ $item['vehicle_type'] ?? 'Sedan' }})
  - Duration: {{ $item['days'] ?? 1 }} days
  - Estimated Date: {{ isset($item['pickup_date']) ? date('F d, Y', strtotime($item['pickup_date'])) : 'Not specified' }}
@endforeach
@endif

---

@if($booking->special_notes)
## Special Requirements
{{ $booking->special_notes }}
@endif

@if(!empty($booking->flight_details))
## Flight Information
@php
    $flight = $booking->flight_details ?? [];
@endphp
- **Airline**: {{ $flight['airline'] ?? 'N/A' }}
- **Flight Number**: {{ $flight['flight_number'] ?? 'N/A' }}
- **Arrival Date**: {{ $flight['arrival_date'] ?? 'N/A' }}
- **Arrival Time**: {{ $flight['arrival_time'] ?? 'N/A' }}
@endif

---

## Next Steps

Our team will review your quotation request and contact you at your preferred time to discuss:
- Available vehicle options matching your requirements
- Detailed pricing and package options
- Special offers and discounts
- Flexible payment terms

**We typically respond within 24 hours during business hours.**

---

## Questions Before We Contact?

Feel free to provide additional information or call us directly:
- **Phone**: +94 (0)XX XXX XXXX
- **Email**: {{ config('mail.from.address') }}
- **Website**: {{ config('app.url') }}

We look forward to assisting you!

@component('mail::button', ['url' => config('app.url')])
View Your Quotation Request
@endcomponent

Thanks,  
{{ config('app.name') }} Team
@endcomponent
