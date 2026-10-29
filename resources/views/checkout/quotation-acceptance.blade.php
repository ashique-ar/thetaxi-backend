@extends('layouts.app')

@section('title', 'Review Your Quotation')
@section('seo_exact_title', 'true')

@push('meta')
    @include('partials.seo', ['seoOverride' => ['is_indexable' => false]])
@endpush

@section('content')
    @php
        $snapshot = $quotation['display_snapshot'] ?? $quotation['snapshot'] ?? [];
        $currency = $snapshot['currency'] ?? $booking->currency ?? 'LKR';
        $customerName = trim(($booking->customer?->user?->first_name ?? '') . ' ' . ($booking->customer?->user?->last_name ?? '')) ?: 'Customer';
        $total = (float) ($snapshot['total_estimated'] ?? $booking->total_estimated ?? 0);
        $amountPaid = (float) ($snapshot['amount_paid'] ?? 0);
        $amountDue = max(0, $total - $amountPaid);
    @endphp

    <div class="breadcrumb-section">
        <div class="container banner-content">
            <h1>Review your quotation</h1>
            <p class="subtitle">Check the booking details and terms before accepting this offer.</p>
        </div>
    </div>

    <main class="container pt-5 pb-5" style="max-width: 960px;">
        @if (session('error'))
            <div class="alert alert-danger" role="alert">{{ session('error') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-danger" role="alert">Please accept the quotation terms to continue.</div>
        @endif

        <section class="card p-4 mb-4">
            <div class="d-flex flex-wrap justify-content-between gap-3">
                <div>
                    <p class="text-muted mb-1">Quotation reference</p>
                    <h2 class="h4 mb-1">{{ $booking->booking_number ?: $booking->id }}</h2>
                    <p class="mb-0">Prepared for {{ $customerName }}</p>
                </div>
                <div class="text-md-end">
                    <p class="text-muted mb-1">Offer revision {{ $revisionNumber }}</p>
                    <p class="mb-0">Valid until {{ \Illuminate\Support\Carbon::parse($expiresAt)->timezone(config('app.timezone'))->format('M j, Y g:i A') }}</p>
                </div>
            </div>

            <hr>
            <h3 class="h5">Services in this quotation</h3>
            @forelse (($snapshot['items'] ?? []) as $item)
                @php
                    $pickup = is_array($item['pickup_location'] ?? null) ? ($item['pickup_location']['address'] ?? '') : ($item['pickup_location'] ?? '');
                    $dropoff = is_array($item['dropoff_location'] ?? null) ? ($item['dropoff_location']['address'] ?? '') : ($item['dropoff_location'] ?? '');
                @endphp
                <div class="border rounded p-3 mb-3">
                    <strong>{{ ($item['service_name'] ?? '') ?: 'Rental / taxi service' }}</strong>
                    @if (!empty($item['vehicle_group_name']))
                        <div>{{ $item['vehicle_group_name'] }}</div>
                    @endif
                    <div class="text-muted">{{ !empty($item['is_self_driven']) ? 'Self-drive' : 'With driver' }}</div>
                    <div>{{ $item['from_date'] ?? 'Date not set' }} {{ $item['from_time'] ?? '' }} – {{ $item['to_date'] ?? '' }} {{ $item['to_time'] ?? '' }}</div>
                    @if ($pickup || $dropoff)
                        <div class="text-muted">{{ $pickup ?: 'Pickup not set' }} → {{ $dropoff ?: 'Drop-off not set' }}</div>
                    @endif
                    <div class="text-muted">{{ $item['quantity'] ?? 1 }} × {{ number_format((float) ($item['unit_price'] ?? 0), 2) }} {{ $currency }}</div>
                    <div class="fw-semibold">{{ number_format((float) ($item['total_price'] ?? 0), 2) }} {{ $currency }}</div>
                </div>
            @empty
                <p class="text-muted">No item details were saved for this quotation. Contact our team before accepting.</p>
            @endforelse

            <div class="d-flex justify-content-between border-top pt-3">
                <strong>Total</strong>
                <strong>{{ number_format($total, 2) }} {{ $currency }}</strong>
            </div>
            @if ($amountPaid > 0)
                <div class="d-flex justify-content-between mt-2">
                    <span>Paid</span><span>{{ number_format($amountPaid, 2) }} {{ $currency }}</span>
                </div>
            @endif
            <div class="d-flex justify-content-between mt-2">
                <span>Due after acceptance</span><strong>{{ number_format($amountDue, 2) }} {{ $currency }}</strong>
            </div>
        </section>

        <section class="card p-4 mb-4">
            <h3 class="h5">Terms for this offer</h3>
            @forelse ($terms as $term)
                <article class="border rounded p-3 mb-3">
                    <h4 class="h6 mb-1">{{ $term['title'] }}</h4>
                    <p class="text-muted mb-2">Version {{ ($term['version'] ?? '') ?: 'current at issue' }}</p>
                    <div style="white-space: pre-wrap;">{{ $term['content'] }}</div>
                </article>
            @empty
                <p class="text-muted">No additional service terms apply to this quotation.</p>
            @endforelse
        </section>

        <form method="POST" action="{{ route('checkout.quotation-accept', ['token' => $token]) }}" class="card p-4">
            @csrf
            <input type="hidden" name="terms_hash" value="{{ $termsHash }}">
            <label class="d-flex align-items-start gap-2 mb-3">
                <input type="checkbox" name="accept_terms" value="1" required style="margin-top: 5px;">
                <span>I have reviewed this quotation and the terms above. I accept this offer and understand that I will continue to payment.</span>
            </label>
            @error('accept_terms') <p class="text-danger">{{ $message }}</p> @enderror
            <button type="submit" class="btn btn-primary">Accept quotation and continue</button>
        </form>
    </main>
@endsection
