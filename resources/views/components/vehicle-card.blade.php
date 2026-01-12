@props([
    'vehicle',
    'pricing' => [],
    'enhancedPricing' => [],
    'serviceFeatures' => [],
    'availability' => ['available' => 0, 'total' => 0],
    'searchId' => null,
    'isRecommended' => false,
    'showBookNow' => false,
    'showViewDetails' => true,
])

@php
    $pricing = $pricing ?: ['base_amount' => 0, 'currency' => 'LKR'];
    $mainImage = isset($vehicle['thumbnail'])
        ? s3_asset($vehicle['thumbnail']['path'] ?? '')
        : asset('assets/img/default-vehicle.jpg');

    // Determine if this vehicle is quotation-only
    $isQuotationOnly = $vehicle['quotation_only'] ?? false;
    $allowBooking = $vehicle['allow_booking'] ?? true;
    $quotationOnlyReasons = $vehicle['quotation_only_reasons'] ?? [];

    // Check specific conditions for quotation-only
    $hasPricing = isset($pricing['base_amount']) && $pricing['base_amount'] > 0;
    $isGroupActive = $vehicle['is_group_active'] ?? true;
    $hasAvailableVehicles = ($availability['available'] ?? 0) > 0;
    $isInquiryOnly = $vehicle['is_inquiry_only'] ?? false;
    $serviceRequiresInquiry = $vehicle['service_requires_inquiry'] ?? false;

    // Final determination: show quotation button if any condition is met
    $showQuotationButton =
        $isQuotationOnly || !$hasPricing || !$isGroupActive || $isInquiryOnly || $serviceRequiresInquiry;

    // Can add to cart/book only if all conditions are met
    $canAddToCart =
        $allowBooking &&
        $hasPricing &&
        $isGroupActive &&
        $hasAvailableVehicles &&
        !$isInquiryOnly &&
        !$serviceRequiresInquiry;
@endphp

<!-- Vehicle Card -->
<div class="vehicle-card modern-card h-100 {{ $isRecommended ? 'recommended-vehicle' : '' }}"
    data-vehicle-group="{{ $vehicle['id'] }}" data-price="{{ $pricing['base_amount'] ?? 0 }}"
    data-name="{{ $vehicle['name'] ?? 'Unknown Vehicle' }}">

    <!-- Vehicle Image -->
    <div class="vehicle-image-container">
        <img src="{{ $mainImage }}" alt="{{ $vehicle['name'] ?? 'Unknown Vehicle' }}" class="vehicle-img" loading="lazy">

        {{-- @if ($canAddToCart && $availability['available'] > 0)
            <span class="availability-badge available">
                <i class="bi bi-check-circle-fill"></i> Available
            </span>
        @elseif($showQuotationButton)
            <span class="availability-badge quotation">
                <i class="bi bi-calculator"></i> Quote Only
            </span>
        @elseif(!$hasAvailableVehicles && ($availability['total'] ?? 0) > 0)
            <span class="availability-badge unavailable">
                <i class="bi bi-clock"></i> Fully Booked
            </span>
        @else
            <span class="availability-badge unavailable">
                <i class="bi bi-x-circle-fill"></i> Not Available
            </span>
        @endif --}}

        {{-- @if ($isRecommended)
            <span class="recommended-badge">
                <i class="bi bi-star-fill"></i> Recommended
            </span>
        @endif --}}

        <!-- Category Badge -->
        @if (isset($vehicle['category']['name']['name']))
            <span class="category-badge">{{ $vehicle['category']['name']['name'] }}</span>
        @endif
    </div>

    <!-- Vehicle Info -->
    <div class="vehicle-card-content">
        <!-- Vehicle Name -->
        <h5 class="vehicle-name">{{ $vehicle['name'] ?? 'Unknown Vehicle' }}</h5>

        <!-- Service Features (if any) -->
        @if (!empty($serviceFeatures))
            <div class="service-features mb-2">
                @foreach (array_slice($serviceFeatures, 0, 2) as $feature)
                    <span class="feature-badge">
                        <i class="bi bi-check-circle"></i> {{ $feature }}
                    </span>
                @endforeach
            </div>
        @endif
        <!-- Vehicle Specs Grid -->
        <div class="vehicle-specs">
            @if (isset($vehicle['passengers_count']) && $vehicle['passengers_count'])
                <div class="spec-item">
                    <i class="bi bi-people-fill"></i>
                    <span>{{ $vehicle['passengers_count'] }} Passengers</span>
                </div>
            @elseif(isset($vehicle['seating_capacity']))
                <div class="spec-item">
                    <i class="bi bi-people-fill"></i>
                    <span>{{ $vehicle['seating_capacity'] }} Seats</span>
                </div>
            @endif
            @if (isset($vehicle['no_of_doors']) && $vehicle['no_of_doors'])
                <div class="spec-item">
                    <i class="bi bi-people-fill"></i>
                    <span>{{ $vehicle['no_of_doors'] }} Doors</span>
                </div>
            @endif

            @if (isset($vehicle['transmission']['name']))
                <div class="spec-item">
                    <i class="bi bi-gear-fill"></i>
                    <span>{{ $vehicle['transmission']['name'] }}</span>
                </div>
            @endif

            @if (isset($vehicle['fuel_type']['name']))
                <div class="spec-item">
                    <i class="bi bi-fuel-pump-fill"></i>
                    <span>{{ $vehicle['fuel_type']['name'] }}</span>
                </div>
            @endif

            @if (isset($vehicle['hand_luggages']) && $vehicle['hand_luggages'])
                <div class="spec-item">
                    <i class="bi bi-suitcase-fill"></i>
                    <span>{{ $vehicle['hand_luggages'] }} Luggages</span>
                </div>
            @endif
        </div>

        <!-- Vehicle Amenities & Additional Info -->
        <div class="vehicle-amenities">
            @if ($vehicle['air_conditioning'] ?? false)
                <span class="amenity-badge">
                    <i class="bi bi-snow"></i> AC
                </span>
            @endif

            @if (isset($vehicle['refundable_deposit']) && $vehicle['refundable_deposit'] > 0)
                <span class="amenity-badge">
                    <i class="bi bi-shield-check"></i> Deposit:
                    {{ getCurrencySymbol() }}{{ number_format($vehicle['refundable_deposit'], 0) }}
                </span>
            @endif
        </div>

        {{-- Debug: Uncomment to see pricing data --}}
        {{-- @dump($pricing) --}}

        <!-- Distance/KM Details Row -->
        @php
            $distanceDetails = $pricing['distance_details'] ?? [];
            $durationInfo = $pricing['duration_info'] ?? [];
            $durationDays = $durationInfo['days'] ?? 1;

            $hasFreeKmPerDay = isset($distanceDetails['free_km_per_day']) && $distanceDetails['free_km_per_day'] > 0;
            $hasFreeKmPerPackage =
                isset($distanceDetails['free_km_per_package']) && $distanceDetails['free_km_per_package'] > 0;
            $hasAllowedKm = isset($distanceDetails['allowed_total_km']) && $distanceDetails['allowed_total_km'] > 0;
            $hasExtraKmPrice = isset($distanceDetails['extra_km_price']) && $distanceDetails['extra_km_price'] > 0;
            $showDistanceDetails = $hasFreeKmPerDay || $hasFreeKmPerPackage || $hasAllowedKm || $hasExtraKmPrice;

            // Check if we have journey duration
            $journeyDurationSeconds = $distanceDetails['journey_duration_seconds'] ?? null;
            $showDurationDetails = $journeyDurationSeconds && $journeyDurationSeconds > 0;

            // Define perDayKm from distance details
            $perDayKm = $distanceDetails['free_km_per_day'] ?? null;
        @endphp

        @if ($showDistanceDetails || $showDurationDetails)
            <div class="pricing-details">
                {{-- Show free KM per day for daily rentals --}}
                @if ($perDayKm && !$hasFreeKmPerPackage)
                    <small class="pricing-detail-item">
                        <i class="bi bi-speedometer2"></i>
                        {{ number_format($perDayKm, 0) }} km/day
                        @if ($durationDays > 1 && $hasAllowedKm)
                            <span class="text-muted">({{ number_format($distanceDetails['allowed_total_km'], 0) }} km
                                total)</span>
                        @endif
                    </small>
                @elseif ($hasFreeKmPerPackage)
                    {{-- Show free KM per package for package-based services --}}
                    <small class="pricing-detail-item">
                        <i class="bi bi-speedometer2"></i>
                        {{ number_format($distanceDetails['free_km_per_package'], 0) }} km included
                    </small>
                @endif

                @if ($hasExtraKmPrice)
                    <small class="pricing-detail-item">
                        <i class="bi bi-lightning-fill"></i> Extra:
                        {{ getCurrencySymbol() }}{{ number_format($distanceDetails['extra_km_price'], 0) }}/km
                    </small>
                @endif

                {{-- Show journey duration --}}
                @if ($showDurationDetails)
                    <small class="pricing-detail-item">
                        <i class="bi bi-clock"></i>
                        {{ gmdate('H:i', $journeyDurationSeconds) }} estimated
                    </small>
                @endif
            </div>
        @endif

        {{-- <div class="availability-indicator">
            <small class="text-muted">
                <i class="bi bi-car-front-fill"></i>
                {{ $availability['available'] }} / {{ $availability['total'] }} Available
            </small>
        </div> --}}

        <!-- Features/Inclusions -->
        {{-- @if (isset($pricing['includes_driver']) || isset($pricing['includes_fuel']))
        <div class="vehicle-inclusions">
            @if ($pricing['includes_driver'] ?? false)
            <span class="inclusion-badge">
                <i class="bi bi-person-check-fill"></i> Driver
            </span>
            @endif
            @if ($pricing['includes_fuel'] ?? false)
            <span class="inclusion-badge">
                <i class="bi bi-droplet-fill"></i> Fuel
            </span>
            @endif
        </div>
        @endif --}}

        <!-- Enhanced Pricing Section -->
        <div class="vehicle-pricing mt-auto">
            <div class="price-display">

                @php
                    // Get pricing data from BookingFlowService (already calculated final amounts)
                    $totalAmountLKR = $pricing['base_amount'] ?? 0; // This is the FINAL package amount, not per-day
                    $durationDays = $pricing['duration_info']['days'] ?? 1;
                    $packageHours = $pricing['duration_info']['package_hours'] ?? null;
                    $serviceType = $pricing['service_type'] ?? 'point_to_point';

                    // Determine if this is a package service (wedding, airport transfers)
                    $isPackageService = in_array($serviceType, ['wedding_hire', 'airport_transfers']);
                    $isWeddingPackage = $serviceType === 'wedding_hire' && $packageHours;
                    $isOneDay = $durationDays === 1;

                    // IMPORTANT: BookingFlowService returns TOTAL PACKAGE AMOUNT, not per-day rate
                    // Only calculate per-day rate for display purposes in multi-day non-package services
                    $perDayRateLKR =
                        !$isPackageService && $durationDays > 1 ? $totalAmountLKR / $durationDays : $totalAmountLKR;

                    // Convert to selected currency using helper functions
                    $selectedCurrency = getSelectedCurrency();
                    $totalAmountConverted = convertPrice($totalAmountLKR); // Final package amount
                    $perDayRateConverted = convertPrice($perDayRateLKR); // Per-day rate for display only
                    $currencySymbol = getCurrencySymbol();
                @endphp

                @if ($isWeddingPackage)
                    <!-- Wedding Package Pricing - Use total amount directly -->
                    <h4 class="price-amount" data-base-price-lkr="{{ $totalAmountLKR }}"
                        data-package-hours="{{ $packageHours }}" data-service-type="{{ $serviceType }}"
                        data-currency="{{ $selectedCurrency }}" data-is-package="true">
                        {{ $currencySymbol }}
                        <span class="price-value">{{ number_format($totalAmountConverted, 0) }}</span>
                        <span class="price-unit">/ {{ $packageHours }}h package</span>
                    </h4>
                @elseif($isPackageService)
                    <!-- Airport Transfer Package Pricing - Use total amount directly -->
                    <h4 class="price-amount" data-base-price-lkr="{{ $totalAmountLKR }}"
                        data-service-type="{{ $serviceType }}" data-currency="{{ $selectedCurrency }}"
                        data-is-package="true">
                        {{ $currencySymbol }}
                        <span class="price-value">{{ number_format($totalAmountConverted, 0) }}</span>
                        <span class="price-unit">/ transfer</span>
                    </h4>
                @elseif($isOneDay)
                    <!-- One Day Pricing - Use total amount directly -->
                    <h4 class="price-amount" data-base-price-lkr="{{ $totalAmountLKR }}"
                        data-duration="{{ $durationDays }}" data-currency="{{ $selectedCurrency }}"
                        data-is-package="false">
                        {{ $currencySymbol }}
                        <span class="price-value">{{ number_format($totalAmountConverted, 0) }}</span>
                    </h4>
                    <div class="total-price-info mt-1 text-muted small">
                        <span class="duration-label">1 day rental</span>
                    </div>
                @else
                    <!-- Multi-day Pricing - Show calculated per-day rate for display -->
                    <h4 class="price-amount" data-base-price-lkr="{{ $totalAmountLKR }}"
                        data-per-day-lkr="{{ round($perDayRateLKR, 2) }}" data-duration="{{ $durationDays }}"
                        data-currency="{{ $selectedCurrency }}" data-is-package="false">
                        {{ $currencySymbol }}
                        <span class="price-value">{{ number_format($perDayRateConverted, 0) }}</span>
                        <span class="price-unit">/day</span>
                    </h4>

                    <!-- Total Price as Secondary Info for multi-day -->
                    <div class="total-price-info mt-2 text-muted small">
                        <span class="total-label">Total:</span>
                        <strong>{{ $currencySymbol }} {{ number_format($totalAmountConverted, 0) }}</strong>
                        <span class="duration-label">({{ $durationDays }} days)</span>
                    </div>
                @endif

                @if (isset($enhancedPricing['savings']) && !empty($enhancedPricing['savings']))
                    <div class="savings-info mt-1">
                        <small class="text-success">
                            <i class="bi bi-tag-fill"></i> Save {{ $enhancedPricing['savings']['amount'] ?? '0' }}
                        </small>
                    </div>
                @endif
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="vehicle-actions mt-3">
            @if ($canAddToCart)
                @if ($showBookNow && $searchId)
                    <!-- Book Now Button (Primary in Search Results) -->
                    <button type="button" class="btn btn-primary w-100 mb-2 book-now-btn"
                        data-group-id="{{ $vehicle['id'] }}" data-search-id="{{ $searchId }}"
                        data-group-name="{{ $vehicle['name'] ?? 'Vehicle' }}"
                        data-base-price="{{ $pricing['base_amount'] ?? 0 }}"
                        data-currency="{{ $pricing['currency'] ?? 'LKR' }}"
                        data-service-type="{{ $pricing['service_type'] ?? 'point_to_point' }}">
                        <i class="bi bi-calendar-check"></i> Book Now
                    </button>

                    <!-- Add to Cart as Secondary Option -->
                    <button type="button" class="btn btn-outline-primary w-100 add-to-cart-btn"
                        data-group-id="{{ $vehicle['id'] }}" data-search-id="{{ $searchId }}"
                        data-group-name="{{ $vehicle['name'] ?? 'Vehicle' }}"
                        data-base-price="{{ $pricing['base_amount'] ?? 0 }}"
                        data-currency="{{ $pricing['currency'] ?? 'LKR' }}"
                        data-service-type="{{ $pricing['service_type'] ?? 'point_to_point' }}">
                        <i class="bi bi-cart-plus"></i> Add to Cart
                    </button>
                @else
                    <!-- Add to Cart Primary (for landing/featured pages) -->
                    <button type="button" class="btn btn-primary w-100 mb-2 add-to-cart-btn"
                        data-group-id="{{ $vehicle['id'] }}" data-search-id="{{ $searchId }}"
                        data-group-name="{{ $vehicle['name'] ?? 'Vehicle' }}"
                        data-base-price="{{ $pricing['base_amount'] ?? 0 }}"
                        data-currency="{{ $pricing['currency'] ?? 'LKR' }}"
                        data-service-type="{{ $pricing['service_type'] ?? 'point_to_point' }}">
                        <i class="bi bi-cart-plus"></i> Add to Cart
                    </button>
                @endif

                @if ($showViewDetails)
                    <a href="{{ $searchId ? route('vehicle.details', ['id' => $vehicle['id'], 'search' => $searchId]) : route('vehicle.details', ['id' => $vehicle['id']]) }}"
                        class="btn btn-outline-secondary btn-sm w-100">
                        <i class="bi bi-eye"></i> View Details
                    </a>
                @endif
            @elseif($showQuotationButton)
                <!-- Request Quotation Button - when booking is not allowed -->
                <button type="button" class="btn btn-warning w-100 mb-2 request-quotation-btn"
                    data-group-id="{{ $vehicle['id'] }}" data-group-name="{{ $vehicle['name'] ?? 'Vehicle' }}"
                    data-search-id="{{ $searchId }}" data-bs-toggle="modal"
                    data-bs-target="#requestQuotationModal">
                    <i class="bi bi-calculator"></i> Request Quotation
                </button>
                <p class="text-muted small mb-0 text-center">
                    <i class="bi bi-info-circle"></i>
                    @if (!$hasPricing)
                        Price on request
                    @elseif (!$isGroupActive)
                        Currently unavailable
                    @elseif ($isInquiryOnly || $serviceRequiresInquiry)
                        Inquiry required
                    @elseif (!$hasAvailableVehicles)
                        All vehicles booked
                    @else
                        Contact us for booking
                    @endif
                </p>

                @if ($showViewDetails)
                    <a href="{{ $searchId ? route('vehicle.details', ['id' => $vehicle['id'], 'search' => $searchId]) : route('vehicle.details', ['id' => $vehicle['id']]) }}"
                        class="btn btn-outline-secondary btn-sm w-100 mt-2">
                        <i class="bi bi-eye"></i> View Details
                    </a>
                @endif
            @else
                <button type="button" class="btn btn-secondary w-100" disabled>
                    <i class="bi bi-exclamation-circle"></i> Not Available
                </button>
            @endif
        </div>
    </div>
</div>

@once
    @push('styles')
        <style>
            /* ==================== THEME COLORS (From app.blade.php) ==================== */
            :root {
                --primary-color: #BF2629;
                --black-color: #717171;
                --white-color: #ffffff;
                --border-color: #e0e0e0;
                --success-color: #28a745;
                --danger-color: #dc3545;
                --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.08);
                --shadow-md: 0 4px 16px rgba(0, 0, 0, 0.12);
                --shadow-lg: 0 8px 24px rgba(0, 0, 0, 0.15);
            }

            /* ==================== VEHICLE CARD ==================== */
            .vehicle-card {
                background: var(--white-color);
                border-radius: 16px;
                overflow: hidden;
                box-shadow: var(--shadow-sm);
                transition: all 0.3s ease;
                display: flex;
                flex-direction: column;
            }

            .vehicle-card:hover {
                box-shadow: var(--shadow-lg);
                transform: translateY(-4px);
            }

            /* Vehicle Image */
            .vehicle-image-container {
                position: relative;
                height: 200px;
                overflow: hidden;
                background: #f5f5f5;
            }

            .vehicle-img {
                width: 100%;
                height: 100%;
                object-fit: cover;
                transition: transform 0.3s ease;
            }

            .vehicle-card:hover .vehicle-img {
                transform: scale(1.05);
            }

            /* Badges */
            .availability-badge {
                position: absolute;
                top: 12px;
                right: 12px;
                padding: 6px 12px;
                border-radius: 20px;
                font-size: 12px;
                font-weight: 600;
                display: flex;
                align-items: center;
                gap: 5px;
                backdrop-filter: blur(10px);
            }

            .availability-badge.available {
                background: rgba(40, 167, 69, 0.95);
                color: white;
            }

            .availability-badge.quotation {
                background: rgba(255, 193, 7, 0.95);
                color: #212529;
            }

            .availability-badge.unavailable {
                background: rgba(220, 53, 69, 0.95);
                color: white;
            }

            .category-badge {
                position: absolute;
                top: 12px;
                left: 12px;
                padding: 6px 14px;
                border-radius: 20px;
                font-size: 11px;
                font-weight: 700;
                text-transform: uppercase;
                background: rgba(191, 38, 41, 0.95);
                color: white;
                backdrop-filter: blur(10px);
            }

            /* Vehicle Content */
            .vehicle-card-content {
                padding: 20px;
                display: flex;
                flex-direction: column;
                flex-grow: 1;
            }

            .vehicle-name {
                font-size: 18px;
                font-weight: 700;
                color: #333;
                margin-bottom: 16px;
                line-height: 1.3;
                min-height: 48px;
            }

            .spec-item {
                display: flex;
                align-items: center;
                gap: 6px;
                font-size: 13px;
                color: var(--black-color);
            }

            .spec-item i {
                color: var(--primary-color);
                font-size: 16px;
                flex-shrink: 0;
            }

            /* Inclusions */
            .vehicle-inclusions {
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
                margin-bottom: 16px;
            }

            .inclusion-badge {
                display: inline-flex;
                align-items: center;
                gap: 5px;
                padding: 5px 10px;
                background: #f0f9ff;
                color: #0369a1;
                border-radius: 6px;
                font-size: 12px;
                font-weight: 600;
            }

            /* Pricing */
            .vehicle-pricing {
                margin-top: auto;
                padding-top: 16px;
                border-top: 2px solid #f0f0f0;
            }

            .price-display {
                text-align: center;
            }

            .price-label {
                display: block;
                font-size: 12px;
                color: var(--black-color);
                text-transform: uppercase;
                letter-spacing: 0.5px;
                margin-bottom: 4px;
            }

            .price-amount {
                font-size: 28px;
                font-weight: 800;
                color: var(--primary-color);
                margin: 8px 0;
                line-height: 1;
            }

            .price-unit {
                /* display: block; */
                font-size: 12px;
                color: var(--black-color);
                margin-bottom: 8px;
            }

            /* Total Price Info (Secondary Display) */
            .total-price-info {
                padding: 8px;
                background: #f9f9f9;
                border-radius: 6px;
                font-size: 13px;
                border: 1px solid #e0e0e0;
            }

            .total-label {
                color: var(--black-color);
                font-weight: 600;
            }

            .duration-label {
                color: var(--black-color);
                font-size: 12px;
                margin-left: 4px;
            }

            /* Action Buttons */
            .vehicle-actions .btn {
                font-weight: 600;
                padding: 10px 16px;
                border-radius: 8px;
                font-size: 14px;
                transition: all 0.3s ease;
            }

            .vehicle-actions .btn-primary {
                background: var(--primary-color);
                border-color: var(--primary-color);
            }

            .vehicle-actions .btn-primary:hover {
                background: #a01f22;
                border-color: #a01f22;
                transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(191, 38, 41, 0.3);
            }

            .vehicle-actions .btn-success {
                background: var(--success-color);
                border-color: var(--success-color);
            }

            .vehicle-actions .btn-success:hover {
                background: #218838;
                border-color: #1e7e34;
                transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
            }

            .vehicle-actions .btn-outline-primary {
                color: var(--primary-color);
                border-color: var(--primary-color);
            }

            .vehicle-actions .btn-outline-primary:hover {
                background: var(--primary-color);
                border-color: var(--primary-color);
                color: white;
            }

            /* Recommended Vehicle Badge */
            .recommended-badge {
                position: absolute;
                top: 12px;
                left: 50%;
                transform: translateX(-50%);
                padding: 8px 16px;
                border-radius: 25px;
                font-size: 12px;
                font-weight: 700;
                text-transform: uppercase;
                background: linear-gradient(135deg, #ffd700, #ffed4e);
                color: #333;
                border: 2px solid #ffd700;
                box-shadow: 0 4px 12px rgba(255, 215, 0, 0.3);
                animation: pulse 2s infinite;
                z-index: 5;
            }

            @keyframes pulse {
                0% {
                    transform: translateX(-50%) scale(1);
                }

                50% {
                    transform: translateX(-50%) scale(1.05);
                }

                100% {
                    transform: translateX(-50%) scale(1);
                }
            }

            /* Recommended Vehicle Card Styling */
            .recommended-vehicle {
                border: 3px solid #ffd700;
                box-shadow: 0 8px 25px rgba(255, 215, 0, 0.2);
                position: relative;
            }

            .recommended-vehicle::before {
                content: '';
                position: absolute;
                top: -3px;
                left: -3px;
                right: -3px;
                bottom: -3px;
                background: linear-gradient(45deg, #ffd700, #ffed4e, #ffd700);
                border-radius: 19px;
                z-index: -1;
                animation: glow 3s ease-in-out infinite alternate;
            }

            @keyframes glow {
                from {
                    opacity: 0.5;
                }

                to {
                    opacity: 0.8;
                }
            }

            /* Service Features */
            .service-features {
                display: flex;
                flex-wrap: wrap;
                gap: 6px;
                margin-bottom: 12px;
            }

            .feature-badge {
                display: inline-flex;
                align-items: center;
                gap: 4px;
                padding: 4px 8px;
                background: #e8f5e8;
                color: #2d5a2d;
                border-radius: 12px;
                font-size: 11px;
                font-weight: 600;
                border: 1px solid #c3e6c3;
            }

            .feature-badge i {
                font-size: 10px;
                color: #28a745;
            }

            /* Enhanced Pricing Display */
            .savings-info {
                padding: 4px 8px;
                background: rgba(40, 167, 69, 0.1);
                border-radius: 8px;
                display: inline-block;
            }

            .savings-info small {
                font-weight: 600;
                letter-spacing: 0.3px;
            }

            /* Enhanced Hover Effects for Service-Aware Cards */
            .recommended-vehicle:hover {
                transform: translateY(-6px);
                box-shadow: 0 12px 35px rgba(255, 215, 0, 0.3);
            }

            /* Better mobile experience for service features */
            @media (max-width: 767px) {
                .service-features {
                    margin-bottom: 8px;
                }

                .feature-badge {
                    font-size: 10px;
                    padding: 3px 6px;
                }

                .recommended-badge {
                    font-size: 10px;
                    padding: 6px 12px;
                }

                .vehicle-image-container {
                    height: 180px;
                }

                .vehicle-name {
                    min-height: auto;
                    font-size: 16px;
                }

                .price-amount {
                    font-size: 24px;
                }
            }

            /* Vehicle Amenities Section */
            .vehicle-amenities {
                display: flex;
                flex-wrap: wrap;
                gap: 6px;
                margin-bottom: 12px;
                padding-top: 12px;
                border-top: 1px solid #f0f0f0;
            }

            .amenity-badge {
                display: inline-flex;
                align-items: center;
                gap: 4px;
                padding: 4px 8px;
                background: #f5f5f5;
                color: #555;
                border-radius: 8px;
                font-size: 11px;
                font-weight: 600;
            }

            .amenity-badge i {
                font-size: 12px;
                color: var(--primary-color);
            }

            /* Pricing Details Row */
            .pricing-details {
                display: flex;
                flex-wrap: wrap;
                gap: 10px;
                margin: 10px 0;
                padding: 8px;
                background: #f9f9f9;
                border-radius: 6px;
            }

            .pricing-detail-item {
                display: inline-flex;
                align-items: center;
                gap: 4px;
                color: #666;
                font-weight: 500;
            }

            .pricing-detail-item i {
                color: var(--primary-color);
                font-size: 12px;
            }

            /* Availability Indicator */
            .availability-indicator {
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 6px;
                margin: 8px 0;
                background: #f0f9f7;
                border-radius: 6px;
            }

            .availability-indicator i {
                color: var(--primary-color);
                margin-right: 4px;
            }
        </style>
    @endpush
@endonce
