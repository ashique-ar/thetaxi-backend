@extends('layouts.app')
@section('seo_exact_title', 'true')

@section('title', ($vehicleGroup->name ?? 'Vehicle Details'))

@push('meta')
    @include('partials.seo', ['model' => $vehicleGroup])
    @php
        $schemaCurrency = $pricing['currency'] ?? getSelectedCurrency();
        $schemaPrice = (float) ($pricing['base_amount'] ?? 0);
        $schemaImage = $vehicleGroup->thumbnail
            ? s3_asset($vehicleGroup->thumbnail['path'] ?? $vehicleGroup->thumbnail)
            : asset('assets/img/default-vehicle.jpg');
        $vehicleSchema = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $vehicleGroup->name ?? 'Vehicle',
            'description' => strip_tags($vehicleGroup->description ?? ($vehicleGroup->name ?? 'Vehicle rental option')),
            'image' => [$schemaImage],
            'brand' => [
                '@type' => 'Brand',
                'name' => $vehicleGroup->make->name ?? (config('app.name')),
            ],
            'url' => route('vehicle.details', ['id' => $vehicleGroup->id]),
            'offers' => [
                '@type' => 'Offer',
                'priceCurrency' => $schemaCurrency,
                'price' => number_format($schemaPrice, 2, '.', ''),
                'availability' => 'https://schema.org/InStock',
                'url' => route('vehicle.details', ['id' => $vehicleGroup->id]),
            ],
        ];
    @endphp
    <script type="application/ld+json">{!! json_encode($vehicleSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
@endpush

@section('content')
    @php
        $today = now()->format('Y-m-d');
        $pickupDate = $searchData['pickup_date'] ?? ($searchData['date'] ?? $today);
        $returnDate = $searchData['return_date'] ?? $pickupDate;
        $pickupTime = $searchData['pickup_time'] ?? ($searchData['time'] ?? '10:00');
        $returnTime = $searchData['return_time'] ?? $pickupTime;
        $numDays = max(1, \Carbon\Carbon::parse($pickupDate)->diffInDays(\Carbon\Carbon::parse($returnDate)) + 1);
        $initialServiceTypeCode = $searchData['service_type'] ?? 'day_rental';
        $initialServiceTypeName =
            $serviceTypes->firstWhere('code', $initialServiceTypeCode)->name ??
            ucwords(str_replace('_', ' ', $initialServiceTypeCode));

        $bookingFormSearch = (object) [
            'service_type' => $initialServiceTypeCode,
            'from_date' => $pickupDate,
            'to_date' => $returnDate,
            'from_time' => $pickupTime,
            'to_time' => $returnTime,
            'pickup_location' => $searchData['pickup_location'] ?? ['address' => 'Colombo, Sri Lanka', 'latitude' => 6.9271, 'longitude' => 79.8612],
            'dropoff_location' => $searchData['dropoff_location'] ?? ['address' => 'Colombo, Sri Lanka', 'latitude' => 6.9271, 'longitude' => 79.8612],
            'pickup_latitude' => $searchData['pickup_lat'] ?? 6.9271,
            'pickup_longitude' => $searchData['pickup_lng'] ?? 79.8612,
            'dropoff_latitude' => $searchData['dropoff_lat'] ?? 6.9271,
            'dropoff_longitude' => $searchData['dropoff_lng'] ?? 79.8612,
            'duration_days' => $numDays,
            'passengers' => 1,
            'transfer_type' => $searchData['transfer_type'] ?? null,
            'rental_mode' => $searchData['rental_mode'] ?? null,
        ];

        $mainImage = $vehicleGroup->thumbnail
            ? s3_asset($vehicleGroup->thumbnail['path'] ?? $vehicleGroup->thumbnail)
            : asset('assets/img/default-vehicle.jpg');
        $vehicleImages = [$mainImage];
        if (!empty($vehicleGroup->images)) {
            foreach ($vehicleGroup->images as $image) {
                $vehicleImages[] = s3_asset($image['path'] ?? $image);
            }
        }
        $vehicleImages = array_values(array_unique(array_filter(array_slice($vehicleImages, 0, 8))));
    @endphp

    <div class="breadcrumb-section three"
        style="background-image:linear-gradient(rgba(0, 0, 0, 0.3), rgba(0, 0, 0, 0.3)), url({{ asset('assets/img/innerpages/breadcrumb-bg.jpg') }});">
        <div class="container banner-content">
            <div class="">
                <h1>{{ $vehicleGroup->name ?? 'Vehicle Details' }}</h1>
                <ul class="breadcrumb-list">
                    <li><a href="{{ route('home') }}">Home</a></li>
                    <li>Vehicle Details</li>
                </ul>
            </div>
        </div>
    </div>

    <div class="vehicle-details-wrapper {{ theme_class('vehicle-details') }} py-5">
        <div class="container">
            <div class="row g-4">
                <div class="col-lg-8">
                    <div class="vehicle-image-gallery mb-4">
                        <div class="main-vehicle-image">
                            <img src="{{ $vehicleImages[0] ?? $mainImage }}" alt="{{ $vehicleGroup->name }}"
                                class="img-fluid rounded" id="mainVehicleImage">
                        </div>

                        @if (count($vehicleImages) > 1)
                            <div class="vehicle-thumbnails mt-3">
                                <div class="row g-2">
                                    @foreach ($vehicleImages as $index => $image)
                                        <div class="col-2">
                                            <img src="{{ $image }}" alt="Vehicle Image {{ $index + 1 }}"
                                                class="img-fluid rounded thumbnail-img {{ $index === 0 ? 'active' : '' }}"
                                                onclick="changeMainImage(event, '{{ $image }}')"
                                                style="cursor:pointer;height:80px;object-fit:cover;width:100%;">
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>

                    <div class="vehicle-info-card">
                        <h2 class="vehicle-title">{{ $vehicleGroup->name }}</h2>
                        @if ($vehicleGroup->description)
                            <p class="vehicle-description">{{ $vehicleGroup->description }}</p>
                        @endif
                        <div class="vehicle-meta-chips mb-3">
                            @if ($vehicleGroup->category)
                                <span class="meta-chip"><i class="bi bi-tag"></i>
                                    {{ $vehicleGroup->category->name ?? 'Standard' }}</span>
                            @endif
                            @if ($vehicleGroup->seating_capacity)
                                <span class="meta-chip"><i class="bi bi-people"></i> {{ $vehicleGroup->seating_capacity }}
                                    Seats</span>
                            @endif
                            @if ($vehicleGroup->transmission)
                                <span class="meta-chip"><i class="bi bi-gear"></i>
                                    {{ $vehicleGroup->transmission->name }}</span>
                            @endif
                        </div>

                        <div class="vehicle-quick-grid">
                            @if ($vehicleGroup->passengers_count || $vehicleGroup->seating_capacity)
                                <div class="quick-cell">
                                    <small>Passengers</small>
                                    <strong>{{ $vehicleGroup->passengers_count ?? $vehicleGroup->seating_capacity }}</strong>
                                </div>
                            @endif
                            @if ($vehicleGroup->hand_luggages)
                                <div class="quick-cell">
                                    <small>Hand Luggage</small>
                                    <strong>{{ $vehicleGroup->hand_luggages }}</strong>
                                </div>
                            @endif
                            @if ($vehicleGroup->no_of_doors)
                                <div class="quick-cell">
                                    <small>Doors</small>
                                    <strong>{{ $vehicleGroup->no_of_doors }}</strong>
                                </div>
                            @endif
                            @if (!is_null($vehicleGroup->air_conditioning))
                                <div class="quick-cell">
                                    <small>A/C</small>
                                    <strong>{{ $vehicleGroup->air_conditioning ? 'Yes' : 'No' }}</strong>
                                </div>
                            @endif
                            @if (($vehicleGroup->vehicles_count ?? 0) > 0)
                                <div class="quick-cell">
                                    <small>Vehicles In Group</small>
                                    <strong>{{ $vehicleGroup->vehicles_count }}</strong>
                                </div>
                            @endif
                            @if (($vehicleGroup->refundable_deposit ?? 0) > 0)
                                <div class="quick-cell">
                                    <small>Refundable Deposit</small>
                                    <strong>{{ getCurrencySymbol() }}
                                        {{ number_format((float) $vehicleGroup->refundable_deposit, 0) }}</strong>
                                </div>
                            @endif
                        </div>

                        <div class="vehicle-specifications mt-4">
                            <h4>Vehicle Group Details</h4>
                            @php
                                $groupDetails = [
                                    ['label' => 'Make', 'value' => $vehicleGroup->make->name ?? null],
                                    ['label' => 'Model', 'value' => $vehicleGroup->model->name ?? null],
                                    ['label' => 'Grade', 'value' => $vehicleGroup->grade->name ?? null],
                                    ['label' => 'Class', 'value' => $vehicleGroup->class->name ?? null],
                                    ['label' => 'Category', 'value' => $vehicleGroup->category->name ?? null],
                                    ['label' => 'Transmission', 'value' => $vehicleGroup->transmission->name ?? null],
                                    ['label' => 'Fuel Type', 'value' => $vehicleGroup->fuelType->name ?? null],
                                    [
                                        'label' => 'Seating Capacity',
                                        'value' => $vehicleGroup->seating_capacity ?? $vehicleGroup->passengers_count,
                                    ],
                                    ['label' => 'Hand Luggages', 'value' => $vehicleGroup->hand_luggages],
                                    ['label' => 'No. of Doors', 'value' => $vehicleGroup->no_of_doors],
                                    [
                                        'label' => 'Air Conditioning',
                                        'value' => is_null($vehicleGroup->air_conditioning)
                                            ? null
                                            : ($vehicleGroup->air_conditioning
                                                ? 'Available'
                                                : 'Not Available'),
                                    ],
                                ];
                            @endphp
                            <div class="details-grid">
                                @foreach ($groupDetails as $detail)
                                    @if (!is_null($detail['value']) && $detail['value'] !== '')
                                        <div class="detail-row">
                                            <span class="detail-label">{{ $detail['label'] }}</span>
                                            <span class="detail-value">{{ $detail['value'] }}</span>
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                        </div>

                        @if (!empty($vehicleGroup->specs) && is_array($vehicleGroup->specs))
                            <div class="vehicle-specifications mt-4">
                                <h4>Additional Specifications</h4>
                                <div class="details-grid">
                                    @foreach ($vehicleGroup->specs as $key => $value)
                                        @if (!is_null($value) && $value !== '')
                                            <div class="detail-row">
                                                <span
                                                    class="detail-label">{{ ucwords(str_replace('_', ' ', (string) $key)) }}</span>
                                                <span class="detail-value">
                                                    @if (is_array($value))
                                                        {{ implode(', ', array_filter($value, fn($v) => !is_null($v) && $v !== '')) }}
                                                    @else
                                                        {{ $value }}
                                                    @endif
                                                </span>
                                            </div>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        <div class="vehicle-specifications mt-4">
                            <h4>Vehicle Specifications</h4>
                            <div class="row g-3">
                                @if ($vehicleGroup->seating_capacity)
                                    <div class="col-md-6">
                                        <div class="spec-item">
                                            <i class="bi bi-people-fill"></i>
                                            <span><strong>Seating:</strong> {{ $vehicleGroup->seating_capacity }}
                                                passengers</span>
                                        </div>
                                    </div>
                                @endif
                                @if ($vehicleGroup->transmission)
                                    <div class="col-md-6">
                                        <div class="spec-item">
                                            <i class="bi bi-gear-fill text-primary"></i>
                                            <span><strong>Transmission:</strong>
                                                {{ $vehicleGroup->transmission->name }}</span>
                                        </div>
                                    </div>
                                @endif
                                @if ($vehicleGroup->fuelType)
                                    <div class="col-md-6">
                                        <div class="spec-item">
                                            <i class="bi bi-fuel-pump-fill text-primary"></i>
                                            <span><strong>Fuel:</strong> {{ $vehicleGroup->fuelType->name }}</span>
                                        </div>
                                    </div>
                                @endif
                                @if ($vehicleGroup->category)
                                    <div class="col-md-6">
                                        <div class="spec-item">
                                            <i class="bi bi-car-front-fill text-primary"></i>
                                            <span><strong>Category:</strong>
                                                {{ $vehicleGroup->category->name ?? 'Standard' }}</span>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="booking-form-card">
                        <div class="booking-form-shell">
                            <div class="booking-shell-header">
                                <div>
                                    <h5 class="mb-1">Plan Your Ride</h5>
                                    <small>Defaulted to {{ $initialServiceTypeName }} ({{ $numDays }}
                                        {{ \Illuminate\Support\Str::plural('day', $numDays) }}).</small>
                                </div>
                                <div class="booking-shell-price" id="vehiclePriceHeader"
                                    data-currency-symbol="{{ getCurrencySymbol() }}"
                                    data-currency-code="{{ $pricing['currency'] ?? getSelectedCurrency() }}"
                                    data-initial-price="{{ (float) ($pricing['base_amount'] ?? 0) }}"
                                    data-initial-service="{{ $initialServiceTypeCode }}"
                                    data-initial-days="{{ $numDays }}">
                                    <small>Current</small>
                                    <strong id="vehicleHeaderPriceValue">{{ getCurrencySymbol() }}
                                        {{ number_format(floor(max(0, (float) ($pricing['base_amount'] ?? 0))), 0) }}</strong>
                                </div>
                            </div>
                            <div class="booking-shell-body">
                                @include('components.booking-form', ['search' => $bookingFormSearch])
                            </div>
                        </div>

                        <div class="vehicle-price-summary mt-3" id="vehiclePriceSummary" itemprop="offers" itemscope
                            itemtype="https://schema.org/Offer">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <div>
                                    <small class="text-uppercase text-muted">Live Price</small>
                                    <h5 class="mb-1" id="vehiclePriceLabel">{{ $initialServiceTypeName }}</h5>
                                    <p class="mb-0 text-muted small" id="vehiclePriceSubLabel">
                                        {{ $numDays }} {{ \Illuminate\Support\Str::plural('day', $numDays) }}
                                    </p>
                                </div>
                                <div class="text-end">
                                    <div class="vehicle-summary-price" id="vehicleSummaryPrice">
                                        {{ getCurrencySymbol() }}
                                        {{ number_format(floor(max(0, (float) ($pricing['base_amount'] ?? 0))), 0) }}
                                    </div>
                                    @if (($pricing['base_amount'] ?? 0) > 0 && $numDays > 1)
                                        <div class="vehicle-summary-unit" id="vehicleSummaryUnit">
                                            {{ getCurrencySymbol() }}
                                            {{ number_format(floor(max(0, (float) ($pricing['base_amount'] ?? 0) / $numDays)), 0) }}/day
                                        </div>
                                    @else
                                        <div class="vehicle-summary-unit" id="vehicleSummaryUnit">per day</div>
                                    @endif
                                </div>
                            </div>
                            <div class="vehicle-price-status d-none" id="vehiclePriceStatus"></div>
                            <meta itemprop="priceCurrency" id="vehicleOfferCurrencyMeta"
                                content="{{ $pricing['currency'] ?? getSelectedCurrency() }}">
                            <meta itemprop="price" id="vehicleOfferPriceMeta"
                                content="{{ number_format((float) ($pricing['base_amount'] ?? 0), 2, '.', '') }}">
                            <link itemprop="availability" href="https://schema.org/InStock">
                        </div>

                        <div class="vehicle-actions-card mt-3">
                            <h6 class="mb-2">Direct Booking</h6>
                            <p class="text-muted small mb-3">Uses the selected values in the form above.</p>
                            <div class="d-grid gap-2">
                                <button type="button" class="btn btn-outline-primary w-100" id="vehicleAddToCartBtn">
                                    <i class="bi bi-cart-plus"></i> Add to Cart
                                </button>
                                <button type="button" class="btn btn-primary w-100" id="vehicleBookNowBtn">
                                    <i class="bi bi-calendar-check"></i> Book Now
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('styles')
    <style>
        :root {
            --vehicle-primary: #bf2629;
            --vehicle-primary-dark: #9f1f21;
            --vehicle-surface: #ffffff;
            --vehicle-muted: #6b7280;
            --vehicle-border: #eceef2;
            --vehicle-shadow: 0 10px 28px rgba(17, 24, 39, 0.08);
            --vehicle-shadow-sm: 0 6px 16px rgba(17, 24, 39, 0.06);
        }

        .vehicle-details-wrapper {
            background:
                radial-gradient(circle at top right, rgba(191, 38, 41, 0.08), transparent 34%),
                linear-gradient(180deg, #f8f9fa 0%, #f4f6f8 100%);
        }

        .main-vehicle-image img {
            width: 100%;
            height: 430px;
            object-fit: cover;
            border-radius: 16px;
            box-shadow: var(--vehicle-shadow);
        }

        .thumbnail-img {
            border: 2px solid transparent;
            transition: all 0.2s ease;
            border-radius: 10px;
        }

        .thumbnail-img.active,
        .thumbnail-img:hover {
            border-color: var(--vehicle-primary);
            transform: translateY(-2px);
            box-shadow: var(--vehicle-shadow-sm);
        }

        .vehicle-info-card {
            background: var(--vehicle-surface);
            padding: 28px;
            border-radius: 16px;
            border: 1px solid var(--vehicle-border);
            box-shadow: var(--vehicle-shadow);
        }

        .vehicle-title {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 8px;
            line-height: 1.2;
            color: #111827;
        }

        .vehicle-description {
            color: var(--vehicle-muted);
            line-height: 1.7;
        }

        .vehicle-meta-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .meta-chip {
            background: #f7f8fa;
            border: 1px solid var(--vehicle-border);
            border-radius: 999px;
            padding: 6px 12px;
            font-size: 12px;
            font-weight: 600;
            color: #1f2937;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .vehicle-quick-grid {
            margin-top: 16px;
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
        }

        .quick-cell {
            border: 1px solid var(--vehicle-border);
            border-radius: 12px;
            padding: 10px 12px;
            background: #fcfcfd;
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .quick-cell small {
            color: var(--vehicle-muted);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .quick-cell strong {
            color: #111827;
            font-size: 15px;
            font-weight: 700;
            line-height: 1.2;
        }

        .vehicle-specifications h4 {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 14px;
            color: #111827;
        }

        .details-grid {
            border: 1px solid var(--vehicle-border);
            border-radius: 12px;
            overflow: hidden;
            background: #fff;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 11px 14px;
            border-bottom: 1px solid #f1f3f7;
            gap: 16px;
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-label {
            color: var(--vehicle-muted);
            font-size: 13px;
            font-weight: 500;
        }

        .detail-value {
            color: #111827;
            font-size: 14px;
            font-weight: 600;
            text-align: right;
        }

        .spec-item {
            display: flex;
            gap: 12px;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px dashed var(--vehicle-border);
        }

        .spec-item i {
            color: var(--vehicle-primary);
            font-size: 18px;
        }

        .booking-form-card {
            position: sticky;
            top: 20px;
        }

        .booking-form-shell {
            background: var(--vehicle-surface);
            border-radius: 16px;
            border: 1px solid var(--vehicle-border);
            box-shadow: var(--vehicle-shadow);
            overflow: hidden;
        }

        .booking-shell-header {
            background: linear-gradient(135deg, #121826 0%, #1f2937 100%);
            color: #fff;
            padding: 14px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }

        .booking-shell-header h5 {
            font-size: 17px;
            font-weight: 700;
            margin: 0;
            color: #fff;
        }

        .booking-shell-header small {
            color: rgba(255, 255, 255, 0.75);
            font-size: 11px;
        }

        .booking-shell-price {
            text-align: right;
        }

        .booking-shell-price small {
            display: block;
            margin-bottom: 2px;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: rgba(255, 255, 255, 0.7);
        }

        .booking-shell-price strong {
            font-size: 15px;
            font-weight: 700;
            color: #fff;
        }

        .booking-shell-body {
            padding: 12px;
            background: #fff;
        }

        .vehicle-details-wrapper .booking-shell-body .filter-wrapper {
            margin-top: 0 !important;
            background: transparent !important;
            box-shadow: none !important;
            padding: 0 !important;
        }

        .vehicle-details-wrapper .booking-shell-body .filter-item-list {
            margin: 0 !important;
            gap: 8px !important;
        }

        .vehicle-details-wrapper .booking-shell-body .single-item {
            border-radius: 10px !important;
            border: 1px solid var(--vehicle-border) !important;
            background: #f8fafc !important;
            padding: 10px 8px !important;
        }

        .vehicle-details-wrapper .booking-shell-body .single-item.active {
            background: var(--vehicle-primary) !important;
            border-color: var(--vehicle-primary) !important;
            color: #fff !important;
        }

        .vehicle-details-wrapper .booking-shell-body .filter-input-wrap {
            margin-top: 10px !important;
            background: transparent !important;
            box-shadow: none !important;
            padding: 0 !important;
        }

        .vehicle-details-wrapper .booking-shell-body .single-search-box {
            margin-bottom: 10px !important;
        }

        .vehicle-details-wrapper .booking-shell-body .booking-field > .input-label {
            font-weight: 600 !important;
            font-size: 12px !important;
        }

        .vehicle-details-wrapper .booking-shell-body .single-search-box input,
        .vehicle-details-wrapper .booking-shell-body .single-search-box select {
            border-radius: 9px !important;
            border: 1px solid var(--vehicle-border) !important;
        }

        .vehicle-actions-card {
            background: var(--vehicle-surface);
            border-radius: 16px;
            padding: 18px;
            border: 1px solid var(--vehicle-border);
            box-shadow: var(--vehicle-shadow-sm);
        }

        .vehicle-price-summary {
            background: #fff;
            border: 1px solid var(--vehicle-border);
            border-radius: 14px;
            padding: 14px;
            box-shadow: var(--vehicle-shadow-sm);
        }

        .vehicle-summary-price {
            font-size: 24px;
            font-weight: 800;
            line-height: 1.1;
            color: var(--vehicle-primary);
        }

        .vehicle-summary-unit {
            color: var(--vehicle-muted);
            font-size: 12px;
            font-weight: 600;
        }

        .vehicle-price-status {
            margin-top: 10px;
            padding: 8px 10px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            line-height: 1.3;
        }

        .vehicle-price-status.loading {
            color: #0f172a;
            background: #eef2ff;
        }

        .vehicle-price-status.error {
            color: #991b1b;
            background: #fee2e2;
        }

        .vehicle-actions-card .btn {
            border-radius: 10px;
            padding: 11px 14px;
            font-weight: 600;
            transition: all 0.2s ease;
        }

        .vehicle-actions-card .btn-primary:hover,
        .vehicle-actions-card .btn-outline-primary:hover {
            transform: translateY(-1px);
        }

        /* Hide search button on vehicle page: direct add/book flow only */
        .booking-form-card .primary-btn1 {
            display: none !important;
        }

        .vehicle-toast {
            position: fixed;
            right: 20px;
            top: 20px;
            z-index: 1200;
            min-width: 260px;
            max-width: 360px;
            border-radius: 10px;
            padding: 12px 14px;
            color: #fff;
            box-shadow: var(--vehicle-shadow);
            font-size: 14px;
        }

        .vehicle-toast.success {
            background: #0f766e;
        }

        .vehicle-toast.error {
            background: #b91c1c;
        }

        @media (max-width: 992px) {
            .main-vehicle-image img {
                height: 320px;
            }

            .vehicle-title {
                font-size: 26px;
            }

            .booking-form-card {
                position: static;
            }

            .vehicle-quick-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 576px) {
            .vehicle-info-card {
                padding: 18px;
            }

            .vehicle-actions-card {
                padding: 14px;
            }

            .vehicle-quick-grid {
                grid-template-columns: 1fr;
            }

            .detail-row {
                flex-direction: column;
                align-items: flex-start;
                gap: 4px;
            }

            .detail-value {
                text-align: left;
            }

            .booking-shell-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .booking-shell-price {
                text-align: left;
            }
        }

        .booking-form-card .filter-wrapper .filter-input-wrap .filter-input.show {
            display: grid !important;
            grid-template-columns: minmax(0, 1fr) !important;
            text-align: left !important;
        }
    </style>
@endpush

@push('scripts')
    <script>
        (function() {
            const serviceTypeNames = @json($serviceTypes->pluck('name', 'code'));
            const priceHeader = document.getElementById('vehiclePriceHeader');
            const priceHeaderValue = document.getElementById('vehicleHeaderPriceValue');
            const summaryPrice = document.getElementById('vehicleSummaryPrice');
            const summaryUnit = document.getElementById('vehicleSummaryUnit');
            const summaryLabel = document.getElementById('vehiclePriceLabel');
            const summarySubLabel = document.getElementById('vehiclePriceSubLabel');
            const priceStatus = document.getElementById('vehiclePriceStatus');
            const offerPriceMeta = document.getElementById('vehicleOfferPriceMeta');
            const offerCurrencyMeta = document.getElementById('vehicleOfferCurrencyMeta');

            let pricingRequest = null;
            let pricingDebounce = null;

            function pluralize(value, unit) {
                return `${value} ${unit}${value === 1 ? '' : 's'}`;
            }

            function toYmd(dateValue) {
                if (!dateValue) return '';
                if (/^\d{4}-\d{2}-\d{2}$/.test(dateValue)) return dateValue;
                if (/^\d{2}\/\d{2}\/\d{4}$/.test(dateValue)) {
                    const [d, m, y] = dateValue.split('/');
                    return `${y}-${m}-${d}`;
                }
                return dateValue;
            }

            function diffDaysInclusive(fromDate, toDate) {
                if (!fromDate || !toDate) return 1;
                const start = new Date(`${fromDate}T00:00:00`);
                const end = new Date(`${toDate}T00:00:00`);
                if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) return 1;
                const diff = Math.round((end - start) / 86400000);
                return Math.max(1, diff + 1);
            }

            function getActiveBookingForm() {
                return document.querySelector('.booking-form-card .filter-input.show') ||
                    document.querySelector('.booking-form-card form[data-service]');
            }

            function getActiveFormValues() {
                const form = getActiveBookingForm();
                if (!form) throw new Error('No active booking form found');

                const fd = new FormData(form);
                const serviceType = fd.get('service_type') || form.getAttribute('data-service') || 'day_rental';
                const pickup = fd.get('pickup') || fd.get('pickup_location') || '';
                const dropoff = fd.get('dropoff') || fd.get('dropoff_location') || pickup;
                const pickupDate = toYmd(fd.get('pickup_date') || fd.get('date'));
                const returnDate = toYmd(fd.get('dropoff_date') || fd.get('return_date') || fd.get('to_date') ||
                    pickupDate);
                const pickupTime = fd.get('pickup_time') || fd.get('time') || fd.get('from_time') || '10:00';
                const returnTime = fd.get('dropoff_time') || fd.get('return_time') || fd.get('to_time') || pickupTime;
                const numDaysRaw = parseInt(fd.get('num_days'), 10);
                const numDays = Number.isFinite(numDaysRaw) && numDaysRaw > 0 ? numDaysRaw : diffDaysInclusive(
                    pickupDate, returnDate);

                const pickupLat = fd.get('pickup_lat') || '';
                const pickupLng = fd.get('pickup_lng') || '';
                const dropoffLat = fd.get('dropoff_lat') || pickupLat;
                const dropoffLng = fd.get('dropoff_lng') || pickupLng;

                return {
                    serviceType,
                    pickup,
                    dropoff,
                    pickupDate,
                    returnDate,
                    pickupTime,
                    returnTime,
                    pickupLat,
                    pickupLng,
                    dropoffLat,
                    dropoffLng,
                    numDays,
                    packageId: fd.get('package_id') || '',
                };
            }

            function buildCartPayload() {
                const form = getActiveBookingForm();
                const values = getActiveFormValues();
                const payload = form ? new FormData(form) : new FormData();
                payload.append('vehicle_group_id', '{{ $vehicleGroup->id }}');
                payload.append('group_name', '{{ addslashes($vehicleGroup->name ?? 'Vehicle') }}');
                payload.set('service_type', values.serviceType);
                payload.set('pickup', values.pickup);
                payload.set('dropoff', values.dropoff);
                payload.set('pickup_location', values.pickup);
                payload.set('dropoff_location', values.dropoff);
                payload.set('pickup_date', values.pickupDate);
                payload.set('return_date', values.returnDate);
                payload.set('pickup_time', values.pickupTime);
                payload.set('return_time', values.returnTime);
                payload.set('pickup_lat', values.pickupLat);
                payload.set('pickup_lng', values.pickupLng);
                payload.set('dropoff_lat', values.dropoffLat);
                payload.set('dropoff_lng', values.dropoffLng);
                payload.set('num_days', values.numDays);

                const rawReturnDate = payload.get('return_date') || values.returnDate;
                const rawReturnTime = payload.get('return_time') || values.returnTime;
                if (rawReturnDate) payload.set('return_trip_date', toYmd(rawReturnDate));
                if (rawReturnTime) payload.set('return_trip_time', rawReturnTime);

                if (values.packageId) {
                    payload.set('package_id', values.packageId);
                    payload.set('service_package_id', values.packageId);
                }

                return payload;
            }

            function buildPricingPayload() {
                const form = getActiveBookingForm();
                if (!form) throw new Error('No active booking form found');

                const values = getActiveFormValues();
                const fd = new FormData(form);
                const payload = {
                    _token: '{{ csrf_token() }}',
                };

                fd.forEach((value, key) => {
                    payload[key] = value;
                });

                payload.service_type = values.serviceType;
                payload.pickup = values.pickup;
                payload.dropoff = values.dropoff;
                payload.pickup_location = values.pickup;
                payload.dropoff_location = values.dropoff;
                payload.pickup_date = values.pickupDate;
                payload.return_date = values.returnDate;
                payload.pickup_time = values.pickupTime;
                payload.return_time = values.returnTime;
                payload.pickup_lat = values.pickupLat;
                payload.pickup_lng = values.pickupLng;
                payload.dropoff_lat = values.dropoffLat;
                payload.dropoff_lng = values.dropoffLng;
                payload.num_days = values.numDays;

                if (values.packageId) payload.package_id = values.packageId;

                return payload;
            }

            function formatMoney(amount) {
                const value = Number(amount || 0);
                const symbol = (priceHeader && priceHeader.dataset.currencySymbol) || '{{ getCurrencySymbol() }}';
                return `${symbol} ${Math.floor(Math.max(0, value)).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 0 })}`;
            }

            function resolveServiceLabel(code) {
                if (!code) return 'Service';
                if (serviceTypeNames[code]) return serviceTypeNames[code];
                return String(code).replace(/_/g, ' ').replace(/\b\w/g, (m) => m.toUpperCase());
            }

            function getDurationLabel(serviceType, days) {
                if (serviceType === 'ride_now') return 'One-time trip';
                if (serviceType === 'airport_transfers') return 'Airport transfer';
                if (serviceType === 'point_to_point') return 'Trip';
                return days === 1 ? '1 day' : `${days} days`;
            }

            function isFixedRateService(serviceType) {
                return ['ride_now', 'airport_transfers', 'point_to_point'].includes(serviceType);
            }

            function setPriceStatus(message, type) {
                if (!priceStatus) return;
                if (!message) {
                    priceStatus.classList.add('d-none');
                    priceStatus.classList.remove('loading', 'error');
                    priceStatus.textContent = '';
                    return;
                }

                priceStatus.classList.remove('d-none', 'loading', 'error');
                if (type === 'loading') priceStatus.classList.add('loading');
                if (type === 'error') priceStatus.classList.add('error');
                priceStatus.textContent = message;
            }

            function applyPricingToUi(pricing, searchData) {
                const amount = Number((pricing && pricing.base_amount) || 0);
                const serviceType = ((pricing && pricing.service_type) || (searchData && searchData.service_type) ||
                    'day_rental').toString();
                const durationInfo = (pricing && pricing.duration_info) || {};
                const numDays = Math.max(
                    1,
                    parseInt(durationInfo.days || (searchData && searchData.num_days) || 1, 10) || 1
                );
                const packageHours = parseInt(durationInfo.package_hours || 0, 10) || 0;
                const fixedRateService = isFixedRateService(serviceType);
                const isWeddingPackage = serviceType === 'wedding_hire' && packageHours > 0;
                const perDay = numDays > 0 ? (amount / numDays) : amount;
                const durationLabel = getDurationLabel(serviceType, numDays);
                const currencyCode = (pricing && pricing.currency) || ((priceHeader && priceHeader.dataset
                    .currencyCode) ||
                    '{{ getSelectedCurrency() }}');

                if (priceHeaderValue) priceHeaderValue.textContent = formatMoney(amount);
                if (summaryPrice) summaryPrice.textContent = formatMoney(amount);
                if (summaryLabel) summaryLabel.textContent = resolveServiceLabel(serviceType);
                if (summarySubLabel) {
                    if (isWeddingPackage) {
                        summarySubLabel.textContent = `${packageHours}h package total`;
                    } else if (serviceType === 'airport_transfers') {
                        summarySubLabel.textContent = 'Transfer total';
                    } else if (fixedRateService) {
                        summarySubLabel.textContent = `${durationLabel} total`;
                    } else {
                        summarySubLabel.textContent = `${durationLabel} total`;
                    }
                }
                if (summaryUnit) {
                    if (isWeddingPackage) {
                        summaryUnit.textContent = `/${packageHours}h package`;
                    } else if (serviceType === 'airport_transfers') {
                        summaryUnit.textContent = '/ transfer';
                    } else if (fixedRateService) {
                        summaryUnit.textContent = durationLabel;
                    } else if (numDays > 1) {
                        summaryUnit.textContent = `${formatMoney(perDay)}/day`;
                    } else {
                        summaryUnit.textContent = durationLabel;
                    }
                }
                if (offerPriceMeta) offerPriceMeta.setAttribute('content', Math.floor(Math.max(0, amount)).toFixed(0));
                if (offerCurrencyMeta) offerCurrencyMeta.setAttribute('content', currencyCode);
                if (priceHeader) {
                    priceHeader.dataset.currencyCode = currencyCode;
                }
            }

            function refreshPriceNow() {
                let payload;
                try {
                    payload = buildPricingPayload();
                } catch (err) {
                    return;
                }

                if (pricingRequest && typeof pricingRequest.abort === 'function') {
                    pricingRequest.abort();
                }

                setPriceStatus('Updating price...', 'loading');
                pricingRequest = $.ajax({
                    url: '{{ route('vehicle.updatePricing', ['id' => $vehicleGroup->id]) }}',
                    method: 'POST',
                    data: payload,
                    success: function(response) {
                        if (response && response.success && response.pricing) {
                            applyPricingToUi(response.pricing, response.search_data || payload);
                            setPriceStatus('', '');
                        } else {
                            setPriceStatus('Unable to update pricing for current selection.', 'error');
                        }
                    },
                    error: function(xhr, status) {
                        if (status === 'abort') return;
                        const message = xhr.responseJSON && xhr.responseJSON.message ?
                            xhr.responseJSON.message :
                            'Unable to update pricing right now. Try again in a moment.';
                        setPriceStatus(message, 'error');
                    }
                });
            }

            function requestPriceUpdate() {
                if (pricingDebounce) {
                    clearTimeout(pricingDebounce);
                }
                pricingDebounce = setTimeout(refreshPriceNow, 350);
            }

            function postToCart(bookNow) {
                const payload = buildCartPayload();
                $.ajax({
                    url: '{{ route('cart.add') }}',
                    method: 'POST',
                    data: payload,
                    processData: false,
                    contentType: false,
                    success: function(response) {
                        if (response && response.success) {
                            window.dispatchEvent(new CustomEvent('cartUpdated'));
                            if (bookNow) {
                                window.location.href = '{{ route('checkout') }}';
                            } else {
                                showToast('Vehicle added to cart successfully.', 'success');
                            }
                        } else {
                            showToast((response && response.message) ? response.message :
                                'Failed to add vehicle to cart.', 'error');
                        }
                    },
                    error: function(xhr) {
                        const message = xhr.responseJSON && xhr.responseJSON.message ?
                            xhr.responseJSON.message :
                            'Error adding to cart. Please check form values and try again.';
                        showToast(message, 'error');
                    }
                });
            }

            function showToast(message, type) {
                const toast = document.createElement('div');
                toast.className = `vehicle-toast ${type || 'success'}`;
                toast.textContent = message;
                document.body.appendChild(toast);
                setTimeout(() => {
                    toast.style.opacity = '0';
                    toast.style.transition = 'opacity .2s ease';
                }, 2500);
                setTimeout(() => toast.remove(), 2800);
            }

            document.addEventListener('DOMContentLoaded', function() {
                // Vehicle page should not submit booking search forms directly.
                document.querySelectorAll('.booking-form-card form[data-service]').forEach(function(form) {
                    form.addEventListener('submit', function(e) {
                        e.preventDefault();
                    });
                });

                const bookingCard = document.querySelector('.booking-form-card');
                if (bookingCard) {
                    bookingCard.addEventListener('change', function(e) {
                        if (e.target && (e.target.matches('input') || e.target.matches('select'))) {
                            requestPriceUpdate();
                        }
                    });

                    bookingCard.addEventListener('input', function(e) {
                        if (e.target && e.target.matches(
                                'input[name=\"pickup\"], input[name=\"dropoff\"], input[name=\"pickup_date\"], input[name=\"dropoff_date\"], input[name=\"date\"], input[name=\"pickup_time\"], input[name=\"dropoff_time\"], input[name=\"time\"]'
                                )) {
                            requestPriceUpdate();
                        }
                    });
                }

                document.querySelectorAll('.booking-form-card .single-item[data-service]').forEach(function(
                tab) {
                    tab.addEventListener('click', function() {
                        setTimeout(requestPriceUpdate, 450);
                    });
                });

                const addBtn = document.getElementById('vehicleAddToCartBtn');
                const bookBtn = document.getElementById('vehicleBookNowBtn');

                if (addBtn) addBtn.addEventListener('click', function() {
                    postToCart(false);
                });
                if (bookBtn) bookBtn.addEventListener('click', function() {
                    postToCart(true);
                });

                requestPriceUpdate();
            });

            window.changeMainImage = function(event, imageSrc) {
                const main = document.getElementById('mainVehicleImage');
                if (main) main.setAttribute('src', imageSrc);
                document.querySelectorAll('.thumbnail-img').forEach(function(img) {
                    img.classList.remove('active');
                });
                if (event && event.target) {
                    event.target.classList.add('active');
                }
            };
        })();
    </script>
@endpush
