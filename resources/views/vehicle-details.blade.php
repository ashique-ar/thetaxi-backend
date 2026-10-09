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
        $priceMetadata = $pricing['calculation_metadata'] ?? [];
        $hasBookablePrice = (float) ($pricing['base_amount'] ?? 0) > 0
            && empty($pricing['error'])
            && empty($priceMetadata['requires_quotation'])
            && empty($priceMetadata['fallback_used'])
            && empty($priceMetadata['default_structure']);
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
            'passengers' => $searchData['passengers'] ?? 1,
            'transfer_type' => $searchData['transfer_type'] ?? null,
            'rental_mode' => $searchData['rental_mode'] ?? null,
            'package_id' => $searchData['package_id'] ?? $searchData['service_package_id'] ?? null,
            'service_package_id' => $searchData['service_package_id'] ?? $searchData['package_id'] ?? null,
            'package_type' => $searchData['package_type'] ?? null,
            'is_return_trip' => $searchData['is_return_trip'] ?? false,
            'return_trip_date' => $searchData['return_trip_date'] ?? $searchData['return_date'] ?? null,
            'return_trip_time' => $searchData['return_trip_time'] ?? $searchData['return_time'] ?? null,
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

    <div class="vehicle-details-section vehicle-details-wrapper {{ theme_class('vehicle-details') }} {{ is_theme('default') ? 'vehicle-details--theme-01' : '' }} py-5">
        <div class="{{ is_theme('theme-04') ? 'vehicle-details-page-layout vehicle-details-page-layout--theme-04' : 'container' }}">
            @if (is_theme('default') || is_theme('theme-02') || is_theme('theme-03'))
                <div class="vehicle-booking-top vehicle-booking-top--{{ get_active_theme() }} mb-4">
                    @if (is_theme('theme-03'))
                        <div class="t3-journey-desk__masthead" aria-hidden="true"><span>Journey desk</span><i></i><b>Search / enquire</b></div>
                    @endif
                    <div class="booking-form-card booking-form-card--vehicle booking-form-card--top {{ theme_class('booking-form-card') }}" data-booking-context="vehicle" data-vehicle-group-id="{{ $vehicleGroup->id }}">
                        @include('components.booking-form', ['search' => $bookingFormSearch, 'bookingContext' => 'vehicle', 'submitLabel' => 'Show More Vehicles'])
                        <div class="vehicle-top-price d-flex justify-content-between align-items-center gap-3 mt-3">
                            <div class="vehicle-price-summary" id="vehiclePriceSummary" itemprop="offers" itemscope itemtype="https://schema.org/Offer">
                                <span class="text-muted small" id="vehiclePriceLabel">{{ $initialServiceTypeName }}</span>
                                <strong class="vehicle-summary-price d-block" id="vehicleSummaryPrice">{{ $hasBookablePrice ? getCurrencySymbol($pricing['currency'] ?? getSelectedCurrency()) . ' ' . number_format(floor((float) $pricing['base_amount']), 0) : 'Price on request' }}</strong>
                                <span class="vehicle-summary-subline" id="vehiclePriceSubLabel">{{ $numDays }} {{ \Illuminate\Support\Str::plural('day', $numDays) }}</span>
                                <span class="vehicle-summary-unit" id="vehicleSummaryUnit"></span>
                                <span class="vehicle-price-status d-none" id="vehiclePriceStatus"></span>
                                <meta itemprop="priceCurrency" id="vehicleOfferCurrencyMeta" content="{{ $pricing['currency'] ?? getSelectedCurrency() }}">
                                <meta itemprop="price" id="vehicleOfferPriceMeta" content="{{ number_format((float) ($pricing['base_amount'] ?? 0), 2, '.', '') }}">
                                <link itemprop="availability" href="https://schema.org/InStock">
                            </div>
                            <div class="vehicle-top-actions d-flex gap-2" id="vehicleDirectBookingActions" @if (!$hasBookablePrice) hidden @endif>
                                <button type="button" class="btn btn-outline-primary" id="vehicleAddToCartBtn"><i class="bi bi-cart-plus" aria-hidden="true"></i> Add to Cart</button>
                                <button type="button" class="btn btn-primary" id="vehicleBookNowBtn"><i class="bi bi-calendar-check" aria-hidden="true"></i> Book Now</button>
                            </div>
                            <div class="vehicle-top-actions" id="vehicleQuotationActions" @if ($hasBookablePrice) hidden @endif>
                                <button type="button" class="btn btn-warning vehicle-request-quotation" data-bs-toggle="modal" data-bs-target="#vehicleQuotationModal"><i class="bi bi-receipt" aria-hidden="true"></i> Request Quotation</button>
                            </div>
                        </div>
                    </div>
                </div>
            @endif
            <div class="{{ is_theme('theme-04') ? 'vehicle-details-layout vehicle-details-layout--theme-04' : 'row g-4 vehicle-details-layout' }}">
                <div class="vehicle-details-main {{ is_theme('theme-04') ? '' : 'col-12' }}">
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
                        <div class="vehicle-specs vehicle-details-specs">
                            <div class="spec-item">
                                @if ($vehicleGroup->passengers_count || $vehicleGroup->seating_capacity)
                                    <i class="bi bi-people-fill"></i>
                                    <span>{{ $vehicleGroup->passengers_count ?? $vehicleGroup->seating_capacity }}{{ !$vehicleGroup->passengers_count ? ' Seats' : '' }}</span>
                                @endif
                                @if ($vehicleGroup->no_of_doors)
                                    <i class="bi bi-door-open-fill"></i>
                                    <span>{{ $vehicleGroup->no_of_doors }} Doors</span>
                                @endif
                                @if ($vehicleGroup->transmission)
                                    <i class="bi bi-gear-fill"></i>
                                    <span>{{ $vehicleGroup->transmission->name }}</span>
                                @endif
                                @if ($vehicleGroup->fuelType)
                                    <i class="bi bi-fuel-pump-fill"></i>
                                    <span>{{ $vehicleGroup->fuelType->name }}</span>
                                @endif
                                @if ($vehicleGroup->hand_luggages)
                                    <i class="bi bi-suitcase-fill"></i>
                                    <span>{{ $vehicleGroup->hand_luggages }}</span>
                                @endif
                            </div>
                        </div>

                        @if (($vehicleGroup->refundable_deposit ?? 0) > 0)
                            <div class="vehicle-deposit-note mt-3">
                                <i class="bi bi-shield-check"></i>
                                <span>Refundable deposit: {{ formatPrice((float) $vehicleGroup->refundable_deposit) }}</span>
                            </div>
                        @endif

                        <div class="vehicle-specifications mt-4">
                            <h4>Vehicle Details</h4>
                            @php
                                $groupDetails = [
                                    ['label' => 'Make', 'value' => $vehicleGroup->make->name ?? null],
                                    ['label' => 'Model', 'value' => $vehicleGroup->model->name ?? null],
                                    ['label' => 'Grade', 'value' => $vehicleGroup->grade->name ?? null],
                                    ['label' => 'Class', 'value' => $vehicleGroup->class->name ?? null],
                                    ['label' => 'Category', 'value' => $vehicleGroup->category->name ?? null],
                                    [
                                        'label' => 'Air Conditioning',
                                        'value' => is_null($vehicleGroup->air_conditioning)
                                            ? null
                                            : ($vehicleGroup->air_conditioning ? 'Available' : 'Not Available'),
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

                    </div>
                </div>

                @if (is_theme('theme-04'))
                <div class="vehicle-booking-rail">
                    <div class="booking-form-card booking-form-card--vehicle t4-booking-panel {{ theme_class('booking-form-card') }}"
                        data-booking-context="vehicle" data-vehicle-group-id="{{ $vehicleGroup->id }}">
                        <div class="search-booking-panel t4-booking-panel__card">
                            <header>
                                <span class="t4-kicker">Book Your Ride</span>
                            </header>
                            @include('components.booking-form', ['search' => $bookingFormSearch, 'bookingContext' => 'vehicle', 'submitLabel' => 'Show More Vehicles'])
                        </div>

                        <div class="vehicle-price-summary mt-3" id="vehiclePriceSummary" itemprop="offers" itemscope
                            itemtype="https://schema.org/Offer">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <div>
                                    <h5 class="mb-1" id="vehiclePriceLabel">{{ $initialServiceTypeName }}</h5>
                                    <p class="mb-0 text-muted small" id="vehiclePriceSubLabel">
                                        {{ $numDays }} {{ \Illuminate\Support\Str::plural('day', $numDays) }}
                                    </p>
                                </div>
                                <div class="text-end">
                                    <div class="vehicle-summary-price" id="vehicleSummaryPrice">{{ $hasBookablePrice ? getCurrencySymbol($pricing['currency'] ?? getSelectedCurrency()) . ' ' . number_format(floor((float) $pricing['base_amount']), 0) : 'Price on request' }}</div>
                                    @if ($hasBookablePrice && $numDays > 1)
                                        <div class="vehicle-summary-unit" id="vehicleSummaryUnit">
                                            {{ getCurrencySymbol($pricing['currency'] ?? getSelectedCurrency()) }}
                                            {{ number_format(floor(max(0, (float) ($pricing['base_amount'] ?? 0) / $numDays)), 0) }}/day
                                        </div>
                                    @else
                                        <div class="vehicle-summary-unit" id="vehicleSummaryUnit" @if (!$hasBookablePrice) hidden @endif></div>
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
                            <div class="d-grid gap-2" id="vehicleDirectBookingActions" @if (!$hasBookablePrice) hidden @endif>
                                <button type="button" class="btn btn-outline-primary w-100" id="vehicleAddToCartBtn">
                                    <i class="bi bi-cart-plus"></i> Add to Cart
                                </button>
                                <button type="button" class="btn btn-primary w-100" id="vehicleBookNowBtn">
                                    <i class="bi bi-calendar-check"></i> Book Now
                                </button>
                            </div>
                            <div class="d-grid" id="vehicleQuotationActions" @if ($hasBookablePrice) hidden @endif>
                                <button type="button" class="btn btn-warning w-100 vehicle-request-quotation" data-bs-toggle="modal" data-bs-target="#vehicleQuotationModal">
                                    <i class="bi bi-receipt" aria-hidden="true"></i> Request Quotation
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                @endif
            </div>
        </div>
    </div>

    <div class="modal fade" id="vehicleQuotationModal" tabindex="-1" aria-labelledby="vehicleQuotationModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title" id="vehicleQuotationModalLabel"><i class="bi bi-receipt" aria-hidden="true"></i> Request Quotation</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="{{ route('quotation.request') }}">
                    @csrf
                    <div class="modal-body">
                        <input type="hidden" name="vehicle_group_id" value="{{ $vehicleGroup->id }}">
                        <input type="hidden" name="service_type" id="quotationServiceType" value="{{ $initialServiceTypeCode }}">
                        <input type="hidden" name="pickup_location" id="quotationPickupLocation">
                        <input type="hidden" name="dropoff_location" id="quotationDropoffLocation">
                        <input type="hidden" name="travel_date" id="quotationTravelDate" value="{{ $pickupDate }}">
                        <input type="hidden" name="travel_time" id="quotationTravelTime" value="{{ $pickupTime }}">
                        <input type="hidden" name="return_date" id="quotationReturnDate" value="{{ $returnDate }}">
                        <input type="hidden" name="return_time" id="quotationReturnTime" value="{{ $returnTime }}">
                        <p class="mb-3">Send us your details and we will get back to you about the {{ $vehicleGroup->name }}.</p>
                        <div class="mb-3">
                            <label for="quotationCustomerName" class="form-label">Name</label>
                            <input id="quotationCustomerName" type="text" class="form-control" name="customer_name" required maxlength="255">
                        </div>
                        <div class="mb-3">
                            <label for="quotationCustomerEmail" class="form-label">Email</label>
                            <input id="quotationCustomerEmail" type="email" class="form-control" name="customer_email" required maxlength="255">
                        </div>
                        <div class="mb-3">
                            <label for="quotationCustomerPhone" class="form-label">Phone</label>
                            <input id="quotationCustomerPhone" type="tel" class="form-control" name="customer_phone" required maxlength="20">
                        </div>
                        <div>
                            <label for="quotationRequirements" class="form-label">Additional details</label>
                            <textarea id="quotationRequirements" class="form-control" name="special_requirements" rows="3" maxlength="1000"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning">Send Request</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('styles')
    <style>
        :root {
            --vehicle-primary: var(--t4-accent, var(--t3-accent, var(--primary-color1, #bf2629)));
            --vehicle-surface: #ffffff;
            --vehicle-muted: #6b7280;
            --vehicle-border: #eceef2;
            --vehicle-shadow: 0 10px 28px rgba(17, 24, 39, 0.08);
            --vehicle-shadow-sm: 0 6px 16px rgba(17, 24, 39, 0.06);
        }

        .vehicle-details-wrapper {
            padding-block: 24px !important;
            background: linear-gradient(180deg, #f8f9fa 0%, #f4f6f8 100%);
        }

        .vehicle-details-wrapper [hidden] { display: none !important; }

        .vehicle-details-wrapper .vehicle-details-layout { align-items: start; }
        .vehicle-image-gallery { margin-bottom: 16px !important; }
        .vehicle-image-gallery .main-vehicle-image {
            display: grid;
            min-height: clamp(250px, 30vw, 390px);
            padding: clamp(12px, 2vw, 24px);
            place-items: center;
            overflow: hidden;
            border: 1px solid var(--vehicle-border);
            border-radius: 14px;
            background: #fff;
        }

        .main-vehicle-image img {
            width: 100%;
            height: auto;
            max-height: clamp(230px, 28vw, 350px);
            object-fit: contain;
            border-radius: 8px;
            box-shadow: none;
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
            padding: clamp(18px, 2.5vw, 28px);
            border-radius: 14px;
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

        .vehicle-details-specs .spec-item {
            flex-wrap: wrap;
            gap: 8px 10px;
            padding: 8px 0;
        }

        .vehicle-details-specs .spec-item span {
            margin-right: 8px;
        }

        .vehicle-deposit-note {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--vehicle-muted);
            font-size: 14px;
        }

        .vehicle-specifications h4 {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 14px;
            color: #111827;
        }

        .details-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
            border: 0;
            overflow: visible;
            background: transparent;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 12px;
            border: 1px solid #edf0f4;
            border-radius: 8px;
            background: #fff;
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
            font-size: 12px;
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

        .vehicle-details-wrapper,
        .vehicle-details-wrapper .container,
        .vehicle-details-wrapper .row,
        .vehicle-details-wrapper [class*="col-"] {
            min-width: 0;
        }

        .vehicle-details-wrapper img {
            max-width: 100%;
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

        .vehicle-booking-top {
            width: 100%;
        }

        .vehicle-details-wrapper .booking-form-card--top,
        .vehicle-details-wrapper .booking-form-card--top .t4-booking-panel__card {
            position: static;
            width: 100%;
        }

        .vehicle-details-wrapper .booking-form-card--top .vehicle-price-summary {
            margin: 0;
            padding: 0;
            border: 0;
            box-shadow: none;
        }

        .vehicle-top-price .vehicle-price-summary {
            display: grid;
            grid-template-columns: auto auto;
            align-items: baseline;
            column-gap: 10px;
            padding: 0;
            border: 0;
            box-shadow: none;
        }
        .vehicle-top-price .vehicle-summary-price { font-size: 22px; }
        .vehicle-top-price .vehicle-summary-subline,
        .vehicle-top-price .vehicle-summary-unit { color: var(--vehicle-muted); font-size: 12px; }
        .vehicle-details-wrapper .vehicle-top-actions .btn { min-height: 42px; padding-inline: 18px; font-weight: 700; }

        .vehicle-details-wrapper .vehicle-booking-top .filter-wrapper {
            margin: 0 !important;
            padding: 0 !important;
        }

        .vehicle-details--theme-01 .vehicle-top-price {
            padding: 12px 18px;
            border-top: 1px solid rgba(15, 23, 42, 0.08);
        }

        .vehicle-top-price {
            padding: 12px 18px;
            border-top: 1px solid rgba(15, 23, 42, 0.08);
        }

        .vehicle-top-price .vehicle-price-summary {
            padding: 0;
            border: 0;
            box-shadow: none;
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

        }

        @media (max-width: 576px) {
            .vehicle-details-wrapper {
                padding-block: 24px !important;
            }

            .vehicle-image-gallery .main-vehicle-image { min-height: 230px; }
            .main-vehicle-image img { max-height: 220px; }

            .vehicle-thumbnails .row {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(56px, 1fr));
            }

            .vehicle-thumbnails .row > [class*="col-"] {
                width: 100%;
            }

            .vehicle-info-card {
                padding: 16px;
            }

            .vehicle-title {
                overflow-wrap: anywhere;
                font-size: clamp(22px, 7vw, 28px);
            }

            .vehicle-actions-card {
                padding: 14px;
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
                align-items: center;
            }

            .booking-shell-price {
                text-align: right;
            }

            .vehicle-details-wrapper .booking-shell-body { padding: 8px; }
            .vehicle-details-wrapper .booking-shell-body .filter-item-list { flex-wrap: wrap; }
            .vehicle-details-wrapper .booking-shell-body .filter-item-list .single-item { flex: 1 1 44%; }
            .detail-row { flex-wrap: wrap; }
            .details-grid { grid-template-columns: 1fr; }
            .vehicle-top-price { align-items: stretch !important; flex-direction: column; padding: 14px; }
            .vehicle-top-actions { display: grid !important; grid-template-columns: 1fr 1fr; }
            .vehicle-top-actions .btn { padding-inline: 8px !important; }
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
            const directBookingActions = document.getElementById('vehicleDirectBookingActions');
            const quotationActions = document.getElementById('vehicleQuotationActions');
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
                payload.set('vehicle_group_id', '{{ $vehicleGroup->id }}');
                payload.set('group_name', '{{ addslashes($vehicleGroup->name ?? 'Vehicle') }}');
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

            const currencySymbols = @json(collect(getAvailableCurrencies())->mapWithKeys(fn ($currency) => [strtoupper($currency['code']) => $currency['symbol'] ?? $currency['code']]));

            function formatMoney(amount, currencyCode) {
                const value = Number(amount || 0);
                const code = String(currencyCode || (priceHeader && priceHeader.dataset.currencyCode) || '{{ getSelectedCurrency() }}').toUpperCase();
                const symbol = currencySymbols[code] || code;
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

            function showQuotationActions(show) {
                if (directBookingActions) directBookingActions.hidden = show;
                if (quotationActions) quotationActions.hidden = !show;
            }

            function applyPricingToUi(pricing, searchData) {
                const amount = Number((pricing && pricing.base_amount) || 0);
                const metadata = (pricing && pricing.calculation_metadata) || {};
                const hasBookablePrice = amount > 0 && !(pricing && pricing.error) &&
                    !metadata.requires_quotation && !metadata.fallback_used && !metadata.default_structure;
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
                const durationLabel = getDurationLabel(serviceType, numDays);
                const currencyCode = (pricing && pricing.currency) || ((priceHeader && priceHeader.dataset
                    .currencyCode) ||
                    '{{ getSelectedCurrency() }}');
                const perDay = numDays > 0 ? (amount / numDays) : amount;

                if (priceHeaderValue) priceHeaderValue.textContent = hasBookablePrice ? formatMoney(amount, currencyCode) : 'Price on request';
                if (summaryPrice) summaryPrice.textContent = hasBookablePrice ? formatMoney(amount, currencyCode) : 'Price on request';
                showQuotationActions(!hasBookablePrice);
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
                    summaryUnit.hidden = !hasBookablePrice;
                    if (!hasBookablePrice) {
                        summaryUnit.textContent = '';
                    } else if (isWeddingPackage) {
                        summaryUnit.textContent = `/${packageHours}h package`;
                    } else if (serviceType === 'airport_transfers') {
                        summaryUnit.textContent = '/ transfer';
                    } else if (fixedRateService) {
                        summaryUnit.textContent = durationLabel;
                    } else if (numDays > 1) {
                        summaryUnit.textContent = `${formatMoney(perDay, currencyCode)}/day`;
                    } else {
                        summaryUnit.textContent = durationLabel;
                    }
                }
                if (offerPriceMeta) offerPriceMeta.setAttribute('content', Math.floor(Math.max(0, amount)).toFixed(0));
                if (offerCurrencyMeta) offerCurrencyMeta.setAttribute('content', currencyCode);
                if (priceHeader) {
                    priceHeader.dataset.currencyCode = currencyCode;
                    priceHeader.dataset.currencySymbol = currencySymbols[currencyCode] || currencyCode;
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

                setPriceStatus('', '');
                pricingRequest = $.ajax({
                    url: '{{ route('vehicle.updatePricing', ['id' => $vehicleGroup->id]) }}',
                    method: 'POST',
                    data: payload,
                    success: function(response) {
                        if (response && response.success && response.pricing) {
                            applyPricingToUi(response.pricing, response.search_data || payload);
                            setPriceStatus('', '');
                        } else {
                            showQuotationActions(true);
                            if (summaryPrice) summaryPrice.textContent = 'Price on request';
                            if (summaryUnit) summaryUnit.hidden = true;
                        }
                    },
                    error: function(xhr, status) {
                        if (status === 'abort') return;
                        showQuotationActions(true);
                        if (summaryPrice) summaryPrice.textContent = 'Price on request';
                        if (summaryUnit) summaryUnit.hidden = true;
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
                const bookingCard = document.querySelector('.booking-form-card--vehicle');
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

                document.querySelectorAll('.booking-form-card--vehicle .single-item[data-service]').forEach(function(
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

                document.querySelectorAll('.vehicle-request-quotation').forEach(function(button) {
                    button.addEventListener('click', function() {
                        try {
                            const values = getActiveFormValues();
                            document.getElementById('quotationServiceType').value = values.serviceType;
                            document.getElementById('quotationPickupLocation').value = values.pickup;
                            document.getElementById('quotationDropoffLocation').value = values.dropoff;
                            document.getElementById('quotationTravelDate').value = values.pickupDate;
                            document.getElementById('quotationTravelTime').value = values.pickupTime;
                            document.getElementById('quotationReturnDate').value = values.returnDate;
                            document.getElementById('quotationReturnTime').value = values.returnTime;
                        } catch (error) {
                            // Keep the initial trip details when the form has no active selection.
                        }
                    });
                });

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
