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
        $quotationOptions = $quotation['options'] ?? [];
        $tripOptionGroups = \App\Helpers\BookingLinkHelper::quotationTripOptionGroups($quotationOptions, $booking, $currency);
        $optionSelectionMap = \App\Helpers\BookingLinkHelper::quotationOptionSelectionMap($quotationOptions);
        $defaultOption = $quotationOptions[0] ?? [];
        $defaultGroupIds = $defaultOption['vehicle_group_ids'] ?? [];
    @endphp

    <div class="breadcrumb-section quotation-review-hero">
        <div class="container banner-content">
            <h1>Review your quotation</h1>
            <p class="subtitle">Check the booking details and terms before accepting this offer.</p>
        </div>
    </div>

    <main class="container pt-5 pb-5 quotation-review-page" style="max-width: 960px;">
        @if (session('error'))
            <div class="alert alert-danger" role="alert">{{ session('error') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-danger" role="alert">Please accept the quotation terms to continue.</div>
        @endif

        <section class="card p-4 mb-4 quotation-summary-card">
            <div class="d-flex flex-wrap justify-content-between gap-3">
                <div>
                    <p class="text-muted mb-1">Quotation reference</p>
                    <h2 class="h4 mb-1">{{ $booking->booking_number ?: $booking->id }}</h2>
                    <p class="mb-0">Prepared for {{ $customerName }}</p>
                </div>
                <div class="text-md-end">
                    <p class="text-muted mb-1">Offer revision {{ $revisionNumber }}</p>
                    <p class="mb-0">Valid until
                        {{ \Illuminate\Support\Carbon::parse($expiresAt)->timezone(config('app.timezone'))->format('M j, Y g:i A') }}
                    </p>
                </div>
            </div>

            <hr>
            <h3 class="h5">Trips and vehicle choices</h3>
            @forelse (($snapshot['items'] ?? []) as $item)
                @php
                    $pickup = is_array($item['pickup_location'] ?? null) ? ($item['pickup_location']['address'] ?? '') : ($item['pickup_location'] ?? '');
                    $dropoff = is_array($item['dropoff_location'] ?? null) ? ($item['dropoff_location']['address'] ?? '') : ($item['dropoff_location'] ?? '');
                    $tripIndex = $loop->index;
                @endphp
                <article class="border rounded p-3 mb-3 quotation-trip-card">
                    <strong>{{ ($item['service_name'] ?? '') ?: 'Rental / taxi service' }}</strong>
                    @if (empty($quotation['options']) && !empty($item['vehicle_group_name']))
                        <div>{{ $item['vehicle_group_name'] }}</div>
                    @endif
                    <div class="text-muted">{{ !empty($item['is_self_driven']) ? 'Self-drive' : 'With driver' }}</div>
                    <div>{{ !empty($item['from_date']) ? \Carbon\Carbon::parse($item['from_date'])->format('M j, Y') : 'Date not set' }} {{ !empty($item['from_time']) ? \Carbon\Carbon::parse($item['from_time'])->format('g:i A') : '' }} –
                        {{ !empty($item['to_date']) ? \Carbon\Carbon::parse($item['to_date'])->format('M j, Y') : '' }} {{ !empty($item['to_time']) ? \Carbon\Carbon::parse($item['to_time'])->format('g:i A') : '' }}</div>
                    @if (!empty($item['route']) || $pickup || $dropoff)
                        <div class="text-muted">{{ $item['route'] ?? (($pickup ?: 'Pickup not set') . ' → ' . ($dropoff ?: 'Drop-off not set')) }}</div>
                    @endif
                    @php
                        $extraKmRateLabel = $item['extra_km_rate_label']
                            ?? \App\Helpers\BookingLinkHelper::quotationExtraKmRateLabel(
                                (array) ($item['distance_details'] ?? []),
                                $currency,
                                !empty($item['vehicle_group_id']) ? (string) $item['vehicle_group_id'] : null,
                                !empty($item['service_type_id']) ? (string) $item['service_type_id'] : null
                            );
                    @endphp
                    @if (!empty($item['included_km_label']))
                        <div class="text-muted mt-1">Included KM: {{ $item['included_km_label'] }}</div>
                    @endif
                    @if (!empty($extraKmRateLabel))
                        <div class="text-muted mt-1">Extra KM Rate: {{ $extraKmRateLabel }}</div>
                    @endif
                    @if (empty($quotation['options']))
                    <div class="text-muted">{{ $item['quantity'] ?? 1 }} ×
                        {{ number_format((float) ($item['unit_price'] ?? 0), 2) }} {{ $currency }}</div>
                    <div class="fw-semibold">{{ number_format((float) ($item['total_price'] ?? 0), 2) }} {{ $currency }}</div>
                    @endif
                    @if (!empty($quotationOptions))
                        <fieldset class="mt-3">
                            <legend class="fw-semibold">Choose a vehicle for Trip {{ $tripIndex + 1 }}</legend>
                            @foreach (($tripOptionGroups[$tripIndex + 1] ?? []) as $choice)
                                <label class="d-flex align-items-center justify-content-between gap-3 border rounded p-2 mb-2 quotation-vehicle-option">
                                    <span class="d-flex align-items-center gap-2 quotation-vehicle-option__main">
                                        <input type="radio" form="accept-quotation-form"
                                            name="quotation_trip_vehicle_group[{{ $tripIndex }}]"
                                            value="{{ $choice['vehicle_group_id'] }}"
                                            data-trip-index="{{ $tripIndex }}"
                                            required
                                            @checked(($defaultGroupIds[$tripIndex] ?? null) === $choice['vehicle_group_id'])>
                                        <img src="{{ $choice['image'] }}" alt="{{ $choice['vehicle_group_name'] }}" width="80" height="54" class="rounded" style="width:80px;height:54px;object-fit:cover">
                                        <span class="quotation-vehicle-option__name">
                                            <span class="d-block">{{ $choice['vehicle_group_name'] }}</span>
                                            @if (!empty($choice['included_km_label']))
                                                <small class="d-block text-muted mt-1">Included KM: {{ $choice['included_km_label'] }}</small>
                                            @endif
                                            @if (!empty($choice['extra_km_rate_label']))
                                                <small class="d-block text-muted mt-1">Extra KM Rate: {{ $choice['extra_km_rate_label'] }}</small>
                                            @endif
                                        </span>
                                    </span>
                                    <strong class="quotation-vehicle-option__price"><small>Trip price</small>{{ number_format($choice['price'], 2) }} {{ $currency }}
                                        @if ($choice['deposit'] > 0)
                                            <small class="d-block mt-1">Refundable deposit</small>
                                            <span class="d-block">{{ number_format($choice['deposit'], 2) }} {{ $currency }}</span>
                                            <small class="d-block mt-1">Total including deposit</small>
                                            <span class="d-block">{{ number_format($choice['price'] + $choice['deposit'], 2) }} {{ $currency }}</span>
                                        @endif
                                    </strong>
                                </label>
                            @endforeach
                        </fieldset>
                    @endif
                </article>
            @empty
                <p class="text-muted">No item details were saved for this quotation. Contact our team before accepting.</p>
            @endforelse

            @if (empty($quotation['options']))
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
            <x-booking-refundable-deposit :booking="$booking" :currency="$currency" />
            @endif
        </section>

        <section class="card p-4 mb-4 quotation-terms-card">
            <h3 class="h5">Terms for this offer</h3>
            @forelse ($terms as $term)
                <article class="border rounded p-3 mb-3 quotation-term-card">
                    <h4 class="h6 mb-1">{{ $term['title'] }}</h4>
                    <p class="text-muted mb-2">Version {{ ($term['version'] ?? '') ?: 'current at issue' }}</p>
                    <div class="transaction-terms-content">{{ \App\Services\PendingPaymentManager::quotationTermText($term['content']) }}</div>
                </article>
            @empty
                <p class="text-muted">No additional service terms apply to this quotation.</p>
            @endforelse
        </section>

        <form id="accept-quotation-form" method="POST" action="{{ route('checkout.quotation-accept', ['token' => $token]) }}" class="card p-4 quotation-acceptance-form">
            @csrf
            <input type="hidden" name="terms_hash" value="{{ $termsHash }}">
            @if (!empty($quotationOptions))
                <input id="quotation-option-id" type="hidden" name="quotation_option_id" value="{{ $defaultOption['id'] ?? '' }}">
                <div class="quotation-selected-total" aria-live="polite">
                    <span>Selected vehicle combination</span>
                    <strong id="quotation-combined-total">{{ number_format((float) data_get($defaultOption, 'totals.total_estimated', 0), 2) }} {{ $currency }}</strong>
                    <small>The total updates as you choose one vehicle for each trip.</small>
                </div>
                @error('quotation_option_id')<p class="text-danger" role="alert">{{ $message }}</p>@enderror
            @endif
            <label class="d-flex align-items-start gap-2 mb-3">
                <input id="accept-quotation-terms" type="checkbox" name="accept_terms" value="1" required aria-invalid="{{ $errors->has('accept_terms') ? 'true' : 'false' }}" @if ($errors->has('accept_terms')) aria-describedby="accept-quotation-terms-error" @endif style="margin-top: 5px;">
                <span>I have reviewed my selected quotation option and the terms above. I accept this offer and understand that I will
                    continue to payment.</span>
            </label>
            @error('accept_terms')
            <p id="accept-quotation-terms-error" class="text-danger" role="alert">{{ $message }}</p> @enderror
            <button type="submit" class="btn btn-primary">Accept quotation &amp; continue</button>
        </form>
        @if (!empty($quotationOptions))
            <script>
                (() => {
                    const form = document.getElementById('accept-quotation-form');
                    const optionField = document.getElementById('quotation-option-id');
                    const totalLabel = document.getElementById('quotation-combined-total');
                    const submitButton = form?.querySelector('button[type="submit"]');
                    const selectionMap = @json($optionSelectionMap);
                    const tripRadios = [...document.querySelectorAll('[data-trip-index]')];
                    const tripIndexes = [...new Set([...document.querySelectorAll('[data-trip-index]')].map(input => input.dataset.tripIndex))]
                        .sort((a, b) => Number(a) - Number(b));
                    const currency = @json($currency);

                    const updateSelection = () => {
                        const selectedGroups = tripIndexes.map(index =>
                            document.querySelector(`input[name="quotation_trip_vehicle_group[${index}]"]:checked`)?.value
                        );
                        const selectedOption = selectedGroups.every(Boolean)
                            ? selectionMap[selectedGroups.join('|')]
                            : null;
                        optionField.value = selectedOption?.id || '';
                        submitButton.disabled = !selectedOption;
                        if (selectedOption) {
                            totalLabel.textContent = `${Number(selectedOption.total).toFixed(2)} ${currency}`;
                        }
                    };
                    tripRadios.forEach(input => input.addEventListener('change', updateSelection));
                    updateSelection();
                })();
            </script>
        @endif
    </main>
@endsection

@push('styles')
    <link rel="stylesheet" href="{{ assetVersion(theme_asset('checkout_stylesheet') ?? 'assets/css/checkout-theme-01.css') }}">
    <link rel="stylesheet" href="{{ assetVersion('assets/css/customer-transaction-pages.css') }}">
    <style>
        .quotation-review-hero { padding: 36px 0 30px !important; }
        .quotation-review-hero .banner-content { max-width: 900px; }
        .quotation-review-hero .banner-content h1 { margin: 0 0 6px !important; font-size: clamp(24px, 2.6vw, 32px) !important; line-height: 1.25; }
        .quotation-review-hero .banner-content .subtitle { margin: 0; font-size: 15px !important; line-height: 1.5; }
        .quotation-review-page { font-size: 14px; line-height: 1.5; }
        .quotation-review-page > .card,
        .quotation-review-page > form.card { padding: 20px !important; }
        .quotation-review-page p,
        .quotation-review-page label,
        .quotation-review-page .border { font-size: 14px; line-height: 1.5; }
        .quotation-review-page h3 { font-size: 18px; line-height: 1.35; }
        .quotation-review-page h4 { font-size: 16px; line-height: 1.4; }
        .quotation-review-page legend { font-size: 15px; line-height: 1.4; }
        .quotation-review-page article > div { font-size: 14px; line-height: 1.5; }
        @media (max-width: 576px) {
            .quotation-review-hero { padding: 30px 0 24px !important; }
            .quotation-review-page > .card,
            .quotation-review-page > form.card { padding: 16px !important; }
        }
    </style>
@endpush
