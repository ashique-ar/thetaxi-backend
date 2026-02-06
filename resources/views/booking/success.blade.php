@extends('layouts.app')

@section('title', 'Booking Confirmed')

@section('content')
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card shadow-lg">
                <div class="card-body text-center p-5">
                    <div class="mb-4">
                        <i class="bi bi-check-circle-fill text-success" style="font-size: 5rem;"></i>
                    </div>
                    <h1 class="mb-3">Booking Confirmed!</h1>
                    <p class="lead mb-4">Thank you for your booking. Your reservation has been successfully confirmed.</p>
                    
                    <div class="alert alert-info">
                        <strong>Booking Reference:</strong> {{ $booking->booking_number ?? 'N/A' }}
                    </div>

                    <div class="mt-4">
                        <a href="{{ route('home') }}" class="btn btn-primary btn-lg">
                            <i class="bi bi-house-door me-2"></i>Return to Home
                        </a>
                        <a href="{{ route('bookings.view', $booking->id ?? '') }}" class="btn btn-outline-primary btn-lg ms-2">
                            <i class="bi bi-file-text me-2"></i>View Booking Details
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@if (!empty($settings['google_ads_conversion_id']) && !empty($settings['google_ads_conversion_label']) && isset($booking))
    @php
        $transactionId = sprintf('BOOKING_%s_%s', $booking->id ?? 'unknown', time());
        $bookingTotal = $booking->total_estimated ?? $booking->total_amount ?? 0;
        $currency = $booking->currency ?? 'LKR';
        $conversionId = $settings['google_ads_conversion_id'];
        $conversionLabel = $settings['google_ads_conversion_label'];
        $sendTo = "{$conversionId}/{$conversionLabel}";
        
        // Determine if this is a new customer
        $isNewCustomer = false;
        if ($booking->customer) {
            $previousPaidBookings = \App\Models\Booking\Booking::where('customer_id', $booking->customer_id)
                ->where('id', '!=', $booking->id)
                ->where('payment_status', 'paid')
                ->count();
            $isNewCustomer = $previousPaidBookings === 0;
        }
    @endphp
    
    <!-- Google Ads Conversion Tracking -->
    <script>
        gtag('event', 'conversion', {
            'send_to': '{{ $sendTo }}',
            'value': {{ $bookingTotal }},
            'currency': '{{ $currency }}',
            'transaction_id': '{{ $transactionId }}',
            'new_customer': {{ $isNewCustomer ? 'true' : 'false' }}
        });
    </script>
@endif
@endsection
