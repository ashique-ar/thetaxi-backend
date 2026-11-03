@extends('layouts.app')

@section('title', 'Tour request received')

@section('content')
@php
    $tourItem = $booking?->bookingItems?->first(fn ($item) => filled(data_get($item->metadata, 'tour_itinerary_id')));
    $tourMetadata = $tourItem?->metadata ?? [];
    $tourDays = $booking ? data_get($tourMetadata, 'tour_days_snapshot', []) : data_get($inquiry?->payload, 'days', []);
    $tourParty = $booking ? [
        'travelers' => (int) ($tourItem?->passenger_count ?? $booking->passenger_count ?? 0),
        'luggage' => (int) ($tourItem?->luggage_count ?? $booking->luggage_count ?? 0),
        'child_seats' => (int) data_get($tourMetadata, 'tour_child_seat_count', 0),
    ] : data_get($inquiry?->payload, 'party', []);
    $tourClass = data_get($tourMetadata, 'pricing_snapshot.tour_pricing_lock.purchased_class.name') ?: $tourItem?->vehicleClass?->name;
    $tourAmount = data_get($tourMetadata, 'pricing_snapshot.total_amount') ?: data_get($inquiry?->payload, 'quote.total_amount');
    $tourCurrency = data_get($tourMetadata, 'pricing_snapshot.currency') ?: ($tourItem?->currency ?: data_get($inquiry?->payload, 'quote.currency'));
@endphp
<main class="container py-5" style="max-width: 52rem">
    <section class="p-4 p-md-5 border rounded-4 bg-white shadow-sm">
        <div class="text-success mb-3" aria-hidden="true"><i class="bi bi-check-circle-fill fs-1"></i></div>
        @if ($booking)
            <span class="badge text-bg-info mb-3">{{ ucfirst(str_replace('_', ' ', $booking->status)) }}</span>
            <h1 class="h2">Your tour request has been received</h1>
            <p class="lead">Reference <strong>{{ $booking->public_reference }}</strong></p>
            <p>Our team will check vehicle availability and contact you about the next steps and payment. This page confirms we received your request; it does not confirm vehicle availability or payment.</p>
            @if ($tourClass || $tourAmount !== null)
                <div class="alert alert-light"><strong>Tour transport:</strong> {{ $tourClass ?? 'Vehicle class' }}@if ($tourAmount !== null) · quoted {{ $tourCurrency }} {{ number_format((float) $tourAmount, 2) }}@endif</div>
            @endif
        @else
            <span class="badge text-bg-info mb-3">Staff review requested</span>
            <h1 class="h2">Your Tour review request has been received</h1>
            <p class="lead">Reference <strong>{{ $inquiry->inquiry_number }}</strong></p>
            <p>Our team will review your route or vehicle availability and contact you. This request does not book or reserve a vehicle, and no payment has been taken.</p>
            @if (data_get($inquiry->payload, 'vehicle_class'))
                <div class="alert alert-light"><strong>Requested class:</strong> {{ data_get($inquiry->payload, 'vehicle_class') }}@if (data_get($inquiry->payload, 'quote.total_amount') !== null) · quote {{ data_get($inquiry->payload, 'quote.currency') }} {{ number_format((float) data_get($inquiry->payload, 'quote.total_amount'), 2) }}@endif</div>
            @endif
        @endif
        @if ($booking || data_get($inquiry?->payload, 'party'))
            <section class="border rounded-3 p-3 mb-4" aria-labelledby="tour-confirmation-plan">
                <h2 id="tour-confirmation-plan" class="h5">Your Tour plan</h2>
                <p class="mb-3">
                    @if ($booking)
                        {{ $tourParty['travelers'] }} travelers · {{ $tourParty['luggage'] }} bags
                    @else
                        {{ data_get($tourParty, 'adults', 0) }} adults · {{ data_get($tourParty, 'children', 0) }} children · {{ data_get($tourParty, 'luggage', 0) }} bags
                    @endif
                    · {{ $booking ? $tourParty['child_seats'] : data_get($tourParty, 'child_seats', 0) }} child seats requested · availability subject to confirmation
                </p>
                @foreach ($tourDays as $day)
                    <div class="mb-3">
                        <strong>Day {{ $day['day_number'] ?? $loop->iteration }}</strong>
                        @if (!empty($day['service_date']) || !empty($day['date']))
                            · {{ \Carbon\Carbon::parse($day['service_date'] ?? $day['date'])->format('M d, Y') }}
                        @endif
                        <div>
                            {{ data_get($day, 'start_location.name', data_get($day, 'start.name', 'Start')) }}
                            @foreach ($day['stops'] ?? [] as $stop)
                                → {{ $stop['name'] ?? 'Selected stop' }}@if (!empty($stop['estimated_arrival'])) <small class="text-muted">(~{{ $stop['estimated_arrival'] }})</small>@endif
                            @endforeach
                            → {{ data_get($day, 'end_location.name', data_get($day, 'end.name', 'End')) }}
                        </div>
                    </div>
                @endforeach
                <p class="small text-muted mb-0">Arrival times are route estimates in each day’s local time zone.</p>
            </section>
        @endif
        <div class="alert alert-light mb-4">
            <strong>Activity visits are route stops only.</strong> Tickets, entry fees and activity reservations are not included. Please arrange these separately.
        </div>
        <a href="{{ route('home') }}" class="btn btn-primary">Back to the website</a>
    </section>
</main>
@endsection
