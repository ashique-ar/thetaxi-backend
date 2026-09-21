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
    // Normalize thumbnail which may be stored as an array or string in different places
    $thumb = null;
    if (isset($vehicle['thumbnail'])) {
        $thumbRaw = $vehicle['thumbnail'];
        if (is_array($thumbRaw)) {
            $thumb = $thumbRaw['path'] ?? ($thumbRaw[0] ?? null);
        } else {
            $thumb = $thumbRaw;
        }
    }
    $mainImage = $thumb ? s3_asset($thumb) : asset('assets/img/default-vehicle.jpg');

    // Determine if this vehicle is quotation-only
    $isQuotationOnly = $vehicle['quotation_only'] ?? false;
    $allowBooking = $vehicle['allow_booking'] ?? true;
    $quotationOnlyReasons = $vehicle['quotation_only_reasons'] ?? [];

    // Check specific conditions for quotation-only
    $hasPricing = isset($pricing['base_amount']) && $pricing['base_amount'] > 0;
    $isGroupActive = $vehicle['is_group_active'] ?? true;
    $availabilitySetting = app(\App\Services\WebsiteSettingsService::class)
        ->get('public_booking_enforce_vehicle_availability', true);
    $availabilityEnforced = $availabilitySetting === null || $availabilitySetting === ''
        ? true
        : (filter_var($availabilitySetting, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true);
    $hasAvailableVehicles = !$availabilityEnforced || ($availability['available'] ?? 0) > 0;
    $isInquiryOnly = $vehicle['is_inquiry_only'] ?? false;
    $serviceRequiresInquiry = $vehicle['service_requires_inquiry'] ?? false;

    // Final determination: show quotation button if any condition is met
    $showQuotationButton =
        $isQuotationOnly || !$hasPricing || !$isGroupActive || $isInquiryOnly || $serviceRequiresInquiry ||
        ($availabilityEnforced && !$hasAvailableVehicles);
    $showPublicPrice = $hasPricing && !$showQuotationButton;

    // Can add to cart/book only if all conditions are met
    $canAddToCart =
        $allowBooking &&
        $hasPricing &&
        $isGroupActive &&
        $hasAvailableVehicles &&
        !$isInquiryOnly &&
        !$serviceRequiresInquiry;
@endphp

@php
    // Get pricing data from BookingFlowService (already calculated final amounts)
    $totalAmountLKR = $pricing['base_amount'] ?? 0; // This is the FINAL package amount (after adjustments)
    $durationDays = $pricing['duration_info']['days'] ?? 1;
    $packageHours = $pricing['duration_info']['package_hours'] ?? null;
    $serviceType = $pricing['service_type'] ?? 'point_to_point';

    // Get discount/adjustment details
    $hasDiscount = $pricing['has_discount'] ?? false;
    $originalAmountLKR = $pricing['original_amount'] ?? $totalAmountLKR;
    $discountAmountLKR = $pricing['discount_amount'] ?? 0;
    $discountPercentage = $pricing['discount_percentage'] ?? 0;
    $savingsDisplay = $pricing['savings_display'] ?? null;

    // Get return trip pricing details (for ride_now with return)
    $isReturnTrip = $pricing['is_return_trip'] ?? false;
    $returnTripDetails = $pricing['return_trip_details'] ?? null;
    $oneWayAmountLKR = $pricing['one_way_amount'] ?? null;
    $returnAmountLKR = $pricing['return_amount'] ?? null;
    $returnDiscountPercentage = $returnTripDetails['discount_percentage'] ?? 0;
    $returnDiscountAmountLKR = $returnTripDetails['discount_amount'] ?? 0;
    $returnRuleLabel = $returnTripDetails['rule_label'] ?? null;

    // Determine service type flags
    $isPackageService = in_array($serviceType, ['wedding_hire', 'airport_transfers']);
    $isWeddingPackage = $serviceType === 'wedding_hire' && $packageHours;
    $isOneDay = $durationDays === 1;
    $isRideNow = $serviceType === 'ride_now';

    // IMPORTANT: BookingFlowService returns TOTAL PACKAGE AMOUNT, not per-day rate
    // Only calculate per-day rate for display purposes in multi-day non-package services
    $perDayRateLKR = !$isPackageService && $durationDays > 1 ? $totalAmountLKR / $durationDays : $totalAmountLKR;
    $originalPerDayRateLKR =
        !$isPackageService && $durationDays > 1 ? $originalAmountLKR / $durationDays : $originalAmountLKR;

    // Convert to selected currency using helper functions
    $selectedCurrency = getSelectedCurrency();
    $totalAmountConverted = convertPrice($totalAmountLKR); // Final package amount
    $originalAmountConverted = convertPrice($originalAmountLKR); // Original amount before discount
    $perDayRateConverted = convertPrice($perDayRateLKR); // Per-day rate for display only
    $originalPerDayConverted = convertPrice($originalPerDayRateLKR); // Original per-day rate
    $discountAmountConverted = convertPrice($discountAmountLKR);
    $currencySymbol = getCurrencySymbol();

    // Convert return trip amounts if applicable
    $oneWayAmountConverted = $oneWayAmountLKR ? convertPrice($oneWayAmountLKR) : null;
    $returnAmountConverted = $returnAmountLKR ? convertPrice($returnAmountLKR) : null;
    $returnDiscountAmountConverted = $returnDiscountAmountLKR ? convertPrice($returnDiscountAmountLKR) : null;
@endphp

<!-- Vehicle Card -->
<div class="vehicle-card modern-card h-100 {{ $isRecommended ? 'recommended-vehicle' : '' }} {{ is_theme('theme-02') ? 't2-vehicle-card' : theme_class('vehicle-card') }}"
    data-vehicle-group="{{ $vehicle['id'] }}" data-price="{{ $hasPricing ? ($pricing['base_amount'] ?? 0) : '' }}"
    data-name="{{ $vehicle['name'] ?? 'Unknown Vehicle' }}">

    <!-- Vehicle Image -->
    <div class="vehicle-image-container">
        <img src="{{ $mainImage }}" alt="{{ $vehicle['name'] ?? 'Unknown Vehicle' }}" class="vehicle-img" loading="lazy">

        @if ($showPublicPrice && $hasDiscount && $discountPercentage > 0)
            <span class="discount-badge">
                {{ round($discountPercentage) }}% OFF
            </span>
        @endif
        <!-- Category Badge -->
        @if (isset($vehicle['category']['name']['name']))
            <span class="category-badge">{{ $vehicle['category']['name']['name'] }}</span>
        @endif
    </div>

    <!-- Vehicle Info -->
    <div class="vehicle-card-content">
        <!-- Vehicle Name -->
        <h5 class="vehicle-name">{{ $vehicle['name'] ?? 'Unknown Vehicle' }}</h5>


        <!-- Vehicle Specs Grid -->
        <div class="vehicle-specs">
            <div class="spec-item">
                @if (isset($vehicle['passengers_count']) && $vehicle['passengers_count'])
                    <i class="bi bi-people-fill"></i>
                    <span>{{ $vehicle['passengers_count'] }}</span>
                @elseif(isset($vehicle['seating_capacity']))
                    <i class="bi bi-people-fill"></i>
                    <span>{{ $vehicle['seating_capacity'] }} Seats</span>
                @endif
                @if (isset($vehicle['no_of_doors']) && $vehicle['no_of_doors'])
                    <i class="bi bi-people-fill"></i>
                    <span>{{ $vehicle['no_of_doors'] }} Doors</span>
                @endif

                @if (isset($vehicle['transmission']['name']))
                    <i class="bi bi-gear-fill"></i>
                    <span>{{ $vehicle['transmission']['name'] }}</span>
                @endif

                @if (isset($vehicle['fuel_type']['name']))
                    <i class="bi bi-fuel-pump-fill"></i>
                    <span>{{ $vehicle['fuel_type']['name'] }}</span>
                @endif

                @if (isset($vehicle['hand_luggages']) && $vehicle['hand_luggages'])
                    <i class="bi bi-suitcase-fill"></i>
                    <span>{{ $vehicle['hand_luggages'] }}</span>
                @endif
            </div>
        </div>

        <!-- Enhanced Pricing Section -->
        <div class="vehicle-card-price-slot">
        @if ($showPublicPrice)
        <div class="price-display">



            @if ($isWeddingPackage)
                <!-- Wedding Package Pricing - Use total amount directly -->
                @if ($hasDiscount && $originalAmountLKR > $totalAmountLKR)
                    <div class="original-price-display">
                        <del class="original-price-strike">{{ $currencySymbol }}
                            {{ number_format(floor(max(0, $originalAmountConverted)), 0) }}</del>
                    </div>
                @endif
                <h4 class="price-amount{{ $hasDiscount ? ' discounted-price' : '' }}"
                    data-base-price-lkr="{{ $totalAmountLKR }}" data-original-price-lkr="{{ $originalAmountLKR }}"
                    data-package-hours="{{ $packageHours }}" data-service-type="{{ $serviceType }}"
                    data-currency="{{ $selectedCurrency }}" data-is-package="true"
                    data-has-discount="{{ $hasDiscount ? 'true' : 'false' }}">
                    <small class="currency-code">{{ $currencySymbol }} </small>
                    <span class="price-value">{{ number_format(floor(max(0, $totalAmountConverted)), 0) }}</span>
                    <span class="price-unit">/ {{ $packageHours }}h package</span>
                </h4>
            @elseif($isPackageService)
                <!-- Airport Transfer Package Pricing - Use total amount directly -->
                @if ($hasDiscount && $originalAmountLKR > $totalAmountLKR)
                    <div class="original-price-display">
                        <del class="original-price-strike">{{ $currencySymbol }}
                            {{ number_format(floor(max(0, $originalAmountConverted)), 0) }}</del>
                    </div>
                @endif
                <h4 class="price-amount{{ $hasDiscount ? ' discounted-price' : '' }}"
                    data-base-price-lkr="{{ $totalAmountLKR }}" data-original-price-lkr="{{ $originalAmountLKR }}"
                    data-service-type="{{ $serviceType }}" data-currency="{{ $selectedCurrency }}"
                    data-is-package="true" data-has-discount="{{ $hasDiscount ? 'true' : 'false' }}">
                    <small class="currency-code">{{ $currencySymbol }}</small>
                    <span class="price-value">{{ number_format(floor(max(0, $totalAmountConverted)), 0) }}</span>
                    <span class="price-unit">/ transfer</span>
                </h4>
            @elseif($isOneDay)
                <!-- One Day Pricing - Use total amount directly -->
                @if ($hasDiscount && $originalAmountLKR > $totalAmountLKR)
                    <div class="original-price-display">
                        <del class="original-price-strike">{{ $currencySymbol }}
                            {{ number_format(floor(max(0, $originalAmountConverted)), 0) }}</del>
                    </div>
                @endif
                <h4 class="price-amount{{ $hasDiscount ? ' discounted-price' : '' }}"
                    data-base-price-lkr="{{ $totalAmountLKR }}" data-original-price-lkr="{{ $originalAmountLKR }}"
                    data-duration="{{ $durationDays }}" data-currency="{{ $selectedCurrency }}"
                    data-is-package="false" data-has-discount="{{ $hasDiscount ? 'true' : 'false' }}">
                    <small class="currency-code">{{ $currencySymbol }}</small>
                    <span class="price-value">{{ number_format(floor(max(0, $totalAmountConverted)), 0) }}</span>
                </h4>
                @if (!$isRideNow)
                    <div class="total-price-info mt-1 text-muted small">
                        <span class="duration-label">{{ getServiceDurationLabel($serviceType, 1) }}</span>
                    </div>
                @endif
            @else
                <!-- Multi-day Pricing -->
                @if ($isRideNow)
                    <!-- Ride Now: display total price with return trip breakdown if applicable -->
                    @if ($isReturnTrip && $returnTripDetails)
                        <!-- Return Trip Pricing Breakdown -->
                        <div class="return-trip-pricing">
                            <div class="trip-breakdown">
                                <div class="trip-item outbound">
                                    <span class="trip-label"><i class="bi bi-arrow-right-circle"></i>
                                        Outbound</span>
                                    <span class="trip-amount">{{ $currencySymbol }}
                                        {{ number_format(floor(max(0, $oneWayAmountConverted)), 0) }}</span>
                                </div>
                                <div class="trip-item return">
                                    <span class="trip-label">
                                        <i class="bi bi-arrow-left-circle"></i> Return
                                        @if ($returnDiscountPercentage > 0)
                                            <span class="text-success fw-semibold">{{ $returnDiscountPercentage }}%
                                                off</span>
                                        @endif
                                    </span>
                                    <span class="trip-amount">{{ $currencySymbol }}
                                        {{ number_format(floor(max(0, $returnAmountConverted)), 0) }}</span>
                                </div>
                            </div>
                            <div class="total-combined-price">
                                {{-- <span class="total-label">Total:</span> --}}
                                <h4 class="price-amount discounted-price"
                                    data-base-price-lkr="{{ $totalAmountLKR }}"
                                    data-one-way-lkr="{{ $oneWayAmountLKR }}"
                                    data-return-lkr="{{ $returnAmountLKR }}" data-is-return-trip="true"
                                    data-return-discount="{{ $returnDiscountPercentage }}"
                                    data-currency="{{ $selectedCurrency }}">
                                    <small class="currency-code">{{ $currencySymbol }}</small>
                                    <span class="price-value">{{ number_format(floor(max(0, $totalAmountConverted)), 0) }}</span>
                                </h4>
                            </div>
                            @if ($returnDiscountAmountLKR > 0)
                                <small class="text-success fw-semibold return-savings">
                                    <i class="bi bi-tag-fill"></i> You save {{ $currencySymbol }}
                                    {{ number_format(floor(max(0, $returnDiscountAmountConverted)), 0) }} on return!
                                </small>
                            @endif
                        </div>
                    @else
                        <!-- Standard Ride Now: display total price only -->
                        @if ($hasDiscount && $originalAmountLKR > $totalAmountLKR)
                            <div class="original-price-display">
                                <del class="original-price-strike">{{ $currencySymbol }}
                                    {{ number_format(floor(max(0, $originalAmountConverted)), 0) }}</del>
                            </div>
                        @endif
                        <h4 class="price-amount{{ $hasDiscount ? ' discounted-price' : '' }}"
                            data-base-price-lkr="{{ $totalAmountLKR }}"
                            data-original-price-lkr="{{ $originalAmountLKR }}" data-duration="{{ $durationDays }}"
                            data-currency="{{ $selectedCurrency }}" data-is-package="false"
                            data-has-discount="{{ $hasDiscount ? 'true' : 'false' }}">
                            <small class="currency-code">{{ $currencySymbol }}</small>
                            <span class="price-value">{{ number_format(floor(max(0, $totalAmountConverted)), 0) }}</span>
                        </h4>
                    @endif
                @else
                    @php
                        $isFixedRateService = isServiceFixedRate($serviceType);
                    @endphp
                    @if ($isFixedRateService)
                        <!-- Fixed-rate service (trip-based) - show total only -->
                        @if ($hasDiscount && $originalAmountLKR > $totalAmountLKR)
                            <div class="original-price-display">
                                <del class="original-price-strike">{{ $currencySymbol }}
                                    {{ number_format(floor(max(0, $originalAmountConverted)), 0) }}</del>
                            </div>
                        @endif
                        <h4 class="price-amount{{ $hasDiscount ? ' discounted-price' : '' }}"
                            data-base-price-lkr="{{ $totalAmountLKR }}"
                            data-original-price-lkr="{{ $originalAmountLKR }}"
                            data-duration="{{ $durationDays }}" data-currency="{{ $selectedCurrency }}"
                            data-is-package="false" data-service-type="{{ $serviceType }}"
                            data-has-discount="{{ $hasDiscount ? 'true' : 'false' }}">
                            <small class="currency-code">{{ $currencySymbol }}</small>
                            <span class="price-value">{{ number_format(floor(max(0, $totalAmountConverted)), 0) }}</span>
                        </h4>
                        <div class="total-price-info mt-1 text-muted small">
                            <span
                                class="duration-label">{{ getServiceDurationLabel($serviceType, $durationDays) }}</span>
                        </div>
                    @else
                        <!-- Show calculated per-day rate for multi-day rentals -->
                        @if ($hasDiscount && $originalPerDayRateLKR > $perDayRateLKR)
                            <div class="original-price-display">
                                <del class="original-total-strike me-1">{{ $currencySymbol }}
                                    {{ number_format(floor(max(0, $originalAmountConverted)), 0) }}</del>
                                {{-- <del
                                    class="original-price-strike">{{ $currencySymbol }} {{ number_format(floor(max(0, $originalPerDayConverted)), 0) }}/day</del> --}}
                            </div>
                        @endif
                        <h4 class="price-amount{{ $hasDiscount ? ' discounted-price' : '' }}"
                            data-base-price-lkr="{{ $totalAmountLKR }}"
                            data-original-price-lkr="{{ $originalAmountLKR }}"
                            data-per-day-lkr="{{ round($perDayRateLKR, 2) }}"
                            data-duration="{{ $durationDays }}" data-currency="{{ $selectedCurrency }}"
                            data-is-package="false" data-has-discount="{{ $hasDiscount ? 'true' : 'false' }}">
                            <small class="currency-code">{{ $currencySymbol }}</small>
                            <span class="price-value">{{ number_format(floor(max(0, $totalAmountConverted)), 0) }}</span>
                            <span
                                class="price-unit">({{ getServiceDurationLabel($serviceType, $durationDays) }})</span>
                        </h4>
                    @endif
                @endif
            @endif

            {{-- Show savings info only for actual discounts --}}
            @if ($hasDiscount && $discountAmountLKR > 0)
                <small class="text-success fw-semibold">
                    <i class="bi bi-tag-fill"></i> You save
                    {{ $currencySymbol }} {{ number_format(floor(max(0, $discountAmountConverted)), 0) }}
                </small>
            @endif

            @if (isset($enhancedPricing['savings']) && !empty($enhancedPricing['savings']) && !$hasDiscount)
                <small class="text-success">
                    <i class="bi bi-tag-fill"></i> Save {{ $enhancedPricing['savings']['amount'] ?? '0' }}
                </small>
            @endif
        </div>
        @endif
        </div>
        
        <!-- Vehicle Amenities & Additional Info -->
        <div class="vehicle-card-amenities-slot">
        @if (isset($vehicle['refundable_deposit']))
            <div class="vehicle-amenities">
                @if (isset($vehicle['refundable_deposit']) && $vehicle['refundable_deposit'] > 0)
                    <span class="amenity-badge">
                        <i class="bi bi-shield-check"></i> Deposit:
                        {{ getCurrencySymbol() }} {{ number_format($vehicle['refundable_deposit'], 0) }}
                    </span>
                @endif
            </div>
        @endif
        </div>

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
            $hasExtraHourPrice =
                $serviceType === 'day_rental' &&
                isset($distanceDetails['extra_hour_price']) &&
                $distanceDetails['extra_hour_price'] > 0;
            $extraHourLabel = $distanceDetails['extra_hour_label'] ?? 'Extra Hour Rate';
            $showDistanceDetails =
                $showPublicPrice &&
                ($hasFreeKmPerDay || $hasFreeKmPerPackage || $hasAllowedKm || $hasExtraKmPrice || $hasExtraHourPrice);

            // Check if we have journey duration and the service supports time-based durations
            $journeyDurationSeconds = $distanceDetails['journey_duration_seconds'] ?? null;
            $servicePricingMode = $serviceType->pricing_mode ?? ($serviceType['pricing_mode'] ?? null);
            $showDurationDetails =
                $showPublicPrice && $journeyDurationSeconds && $journeyDurationSeconds > 0 && $servicePricingMode !== 'day';

            // Define perDayKm from distance details
            $perDayKm = $distanceDetails['free_km_per_day'] ?? null;

            \Illuminate\Support\Facades\Log::debug('[km-debug] Vehicle card render', [
                'vehicle_group_id' => $vehicle['id'] ?? null,
                'vehicle_name' => $vehicle['name'] ?? null,
                'service_type' => $serviceType ?? null,
                'duration_days' => $durationDays,
                'distance_details' => $distanceDetails,
                'has_free_km_per_day' => $hasFreeKmPerDay,
                'has_free_km_per_package' => $hasFreeKmPerPackage,
                'has_allowed_km' => $hasAllowedKm,
                'has_extra_km_price' => $hasExtraKmPrice,
                'has_extra_hour_price' => $hasExtraHourPrice,
                'show_distance_details' => $showDistanceDetails,
            ]);
        @endphp

        <div class="vehicle-card-details-slot">
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

                {{-- Explicit included/allowed total KM (show when available) --}}
                @if ($hasAllowedKm && !$hasFreeKmPerDay && !$hasFreeKmPerPackage)
                    <small class="pricing-detail-item">
                        <i class="bi bi-check2-circle"></i>
                        <strong>Included:</strong> {{ number_format($distanceDetails['allowed_total_km'], 0) }} km
                    </small>
                @endif

                @if ($hasExtraKmPrice)
                    <small class="pricing-detail-item">
                        <i class="bi bi-lightning-fill"></i> Extra:
                        <small class="currency-code">{{ getCurrencySymbol() }}</small>
                        {{ number_format(floor(max(0, $distanceDetails['extra_km_price'])), 0) }}/km
                    </small>
                @endif

                @if ($hasExtraHourPrice)
                    <small class="pricing-detail-item">
                        <i class="bi bi-clock-fill"></i> {{ $extraHourLabel }}:
                        <small class="currency-code">{{ getCurrencySymbol() }}</small>
                        {{ number_format(floor(max(0, $distanceDetails['extra_hour_price'])), 0) }}/hour
                    </small>
                @endif

                {{-- Show journey duration --}}
                {{-- @if ($showDurationDetails)
                    <small class="pricing-detail-item">
                        <i class="bi bi-clock"></i>
                        {{ gmdate('H:i', $journeyDurationSeconds) }} estimated
                    </small>
                @endif --}}
            </div>
        @endif
        </div>

        <!-- Service Features (if any) -->
        <div class="vehicle-card-features-slot">
        @if (!empty($serviceFeatures))
            <div class="service-features mb-2">
                @foreach (array_slice($serviceFeatures, 0, 3) as $feature)
                    <span class="feature-badge">
                        <i class="bi bi-check-circle"></i> {{ $feature }}
                    </span>
                @endforeach
            </div>
        @endif
        </div>

        <!-- Action Buttons -->
        <div class="vehicle-actions mt-1">
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

            .discount-badge {
                position: absolute;
                top: 12px;
                right: 12px;
                padding: 0 10px;
                border-radius: 20px;
                font-size: 11px;
                font-weight: 700;
                text-transform: uppercase;
                background: #28a745;
                color: white;
                backdrop-filter: blur(10px);
            }

            /* Vehicle Content */
            .vehicle-card-content {
                padding: 20px;
                display: grid !important;
                grid-template-rows: 38px 26px 84px 30px 34px 40px minmax(86px, auto);
                flex-grow: 1;
            }

            .vehicle-card-price-slot,
            .vehicle-card-amenities-slot,
            .vehicle-card-details-slot,
            .vehicle-card-features-slot {
                min-width: 0;
                overflow: hidden;
            }

            .vehicle-card-price-slot {
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .vehicle-card-price-slot .price-display {
                width: 100%;
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
                padding-top: 5px;
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
                margin: 0;
                line-height: 1;
            }

            /* Currency code styling - non-bold, neutral color */
            .currency-code {
                color: var(--black-color);
                font-weight: 500;
                display: inline-block;
                margin-right: 6px;
                font-size: 0.92em;
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
            .vehicle-actions {
                margin-top: auto !important;
                align-self: end;
            }

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

            .service-features {
                display: flex;
                flex-wrap: wrap;
            }

            .feature-badge {
                display: inline-flex;
                align-items: center;
                padding: 0 8px;
                gap: 1px 4px;
                font-size: 11px;
                font-weight: 600;
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

            .original-price {
                text-align: center;
                font-size: 14px;
                margin-bottom: 4px;
            }

            .original-price s {
                color: #999;
                font-weight: 500;
            }

            /* Price with discount highlight */
            .price-amount[data-has-discount="true"] {
                color: var(--success-color);
            }

            .price-amount[data-has-discount="true"]::after {
                content: '';
                display: block;
                width: 100%;
                height: 3px;
                background: linear-gradient(90deg, transparent, var(--success-color), transparent);
                margin-top: 4px;
                border-radius: 2px;
            }

            /* Enhanced Hover Effects for Service-Aware Cards */
            .recommended-vehicle:hover {
                transform: translateY(-6px);
                box-shadow: 0 12px 35px rgba(255, 215, 0, 0.3);
            }

            /* Better mobile experience for service features */
            @media (max-width: 767px) {

                .vehicle-card-content {
                    grid-template-rows: 42px 28px 92px 32px 38px 44px minmax(86px, auto);
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
                gap: 0;
                margin: 0;
                padding: 0 8px;
                background: #f9f9f9;
                border-radius: 10px;
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

            /* Return Trip Pricing Styles */
            .return-trip-pricing {
                background: linear-gradient(135deg, #f8f9fa 0%, #e9f5f2 100%);
                border-radius: 12px;
                padding: 12px;
                margin-top: 8px;
            }

            .trip-breakdown {
                display: flex;
                flex-direction: column;
                gap: 8px;
                margin-bottom: 12px;
            }

            .trip-item {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 6px 8px;
                background: white;
                border-radius: 6px;
                font-size: 13px;
            }

            .trip-item.outbound {
                border-left: 3px solid var(--primary-color);
            }

            .trip-item.return {
                border-left: 3px solid #28a745;
            }

            .trip-label {
                display: flex;
                align-items: center;
                gap: 6px;
                color: #555;
                font-weight: 500;
            }

            .trip-label i {
                font-size: 14px;
            }

            .trip-item.outbound .trip-label i {
                color: var(--primary-color);
            }

            .trip-item.return .trip-label i {
                color: #28a745;
            }

            .trip-amount {
                font-weight: 600;
                color: #333;
            }

            .return-discount-badge {
                background: #28a745;
                color: white;
                font-size: 10px;
                padding: 2px 6px;
                border-radius: 10px;
                font-weight: 600;
            }

            .total-combined-price {
                display: flex;
                align-items: center;
                justify-content: center;
                padding-top: 10px;
                border-top: 1px dashed #ddd;
            }

            .total-combined-price .total-label {
                font-size: 13px;
                color: #666;
                font-weight: 500;
            }

            .total-combined-price .price-amount {
                margin: 0;
            }

            .return-savings {
                display: block;
                text-align: center;
                margin-top: 8px;
                font-size: 12px;
            }

            /* Theme 2 vehicle cards: distinct search/home presentation */
            .t2-vehicle-card.vehicle-card {
                border: 1px solid color-mix(in srgb, var(--black-color, #717171) 16%, transparent) !important;
                border-radius: 14px !important;
                background: #ffffff !important;
                box-shadow: 0 10px 28px rgba(15, 23, 42, 0.08) !important;
                padding: 10px !important;
                overflow: visible !important;
            }

            .t2-vehicle-card.vehicle-card:hover {
                transform: translateY(-6px) !important;
                border-color: color-mix(in srgb, var(--primary-color1, #BF2629) 35%, transparent) !important;
                box-shadow: 0 22px 54px rgba(15, 23, 42, 0.14) !important;
            }

            .t2-vehicle-card .vehicle-image-container {
                height: 168px !important;
                border-radius: 10px !important;
                background: color-mix(in srgb, var(--black-color, #717171) 10%, #ffffff) !important;
            }

            .t2-vehicle-card .vehicle-image-container::after {
                content: "";
                position: absolute;
                inset: auto 0 0;
                height: 52%;
                background: linear-gradient(180deg, transparent 0%, color-mix(in srgb, var(--black-color, #717171) 62%, transparent) 100%) !important;
                pointer-events: none;
            }

            .t2-vehicle-card .vehicle-card-content {
                padding: 14px 4px 2px !important;
            }

            .t2-vehicle-card .vehicle-name {
                min-height: 0 !important;
                margin-bottom: 10px !important;
                color: color-mix(in srgb, var(--black-color, #717171) 82%, #000000) !important;
                font-size: 17px !important;
                line-height: 1.25 !important;
            }

            .t2-vehicle-card .category-badge {
                left: 12px !important;
                right: auto !important;
                background: color-mix(in srgb, var(--black-color, #717171) 82%, transparent) !important;
                border-radius: 6px !important;
                padding: 4px 9px !important;
                letter-spacing: 0 !important;
            }

            .t2-vehicle-card .discount-badge {
                right: 12px !important;
                left: auto !important;
                background: var(--primary-color1, #BF2629) !important;
                border-radius: 6px !important;
            }

            .t2-vehicle-card .vehicle-specs {
                margin-bottom: 12px !important;
                padding: 0 !important;
                border-bottom: 0 !important;
            }

            .t2-vehicle-card .spec-item {
                gap: 8px !important;
                color: color-mix(in srgb, var(--black-color, #717171) 72%, #ffffff) !important;
            }

            .t2-vehicle-card .spec-item span {
                display: inline-flex !important;
                align-items: center !important;
                min-height: 24px !important;
                padding: 3px 8px !important;
                border-radius: 999px !important;
                background: color-mix(in srgb, var(--black-color, #717171) 8%, #ffffff) !important;
                color: color-mix(in srgb, var(--black-color, #717171) 72%, #111827) !important;
                font-size: 12px !important;
            }

            .t2-vehicle-card .spec-item i {
                color: var(--primary-color1, #BF2629) !important;
                font-size: 14px !important;
            }

            .t2-vehicle-card .vehicle-pricing {
                margin-top: 12px !important;
                padding: 12px !important;
                border: 1px solid color-mix(in srgb, var(--primary-color1, #BF2629) 16%, transparent) !important;
                border-radius: 10px !important;
                background: color-mix(in srgb, var(--primary-color1, #BF2629) 6%, #ffffff) !important;
            }

            .t2-vehicle-card .price-display {
                text-align: left !important;
            }

            .t2-vehicle-card .price-amount {
                color: var(--primary-color1, #BF2629) !important;
                font-size: 24px !important;
                line-height: 1.15 !important;
            }

            .t2-vehicle-card .currency-code,
            .t2-vehicle-card .price-unit {
                color: var(--black-color, #717171) !important;
            }

            .t2-vehicle-card .original-price-display del {
                color: color-mix(in srgb, var(--black-color, #717171) 62%, #ffffff) !important;
            }

            .t2-vehicle-card .pricing-details {
                gap: 6px !important;
            }

            .t2-vehicle-card .pricing-detail-item {
                background: color-mix(in srgb, var(--primary-color1, #BF2629) 8%, #ffffff) !important;
                color: var(--primary-color1, #BF2629) !important;
                border: 1px solid color-mix(in srgb, var(--primary-color1, #BF2629) 18%, transparent) !important;
            }

            .t2-vehicle-card .vehicle-actions {
                display: grid !important;
                grid-template-columns: 1fr !important;
                gap: 8px !important;
            }

            .t2-vehicle-card .vehicle-actions .btn {
                border-radius: 8px !important;
                font-size: 13px !important;
                padding: 10px 12px !important;
            }

            .t2-vehicle-card .vehicle-actions .btn-primary,
            .t2-vehicle-card .vehicle-actions .book-now-btn {
                background: var(--primary-color1, #BF2629) !important;
                border-color: var(--primary-color1, #BF2629) !important;
            }

            .t2-vehicle-card .vehicle-actions .btn-primary:hover,
            .t2-vehicle-card .vehicle-actions .book-now-btn:hover {
                background: var(--black-color, #717171) !important;
                border-color: var(--black-color, #717171) !important;
            }

            .t2-vehicle-card .vehicle-actions .btn-outline-primary,
            .t2-vehicle-card .vehicle-actions .add-to-cart-btn,
            .t2-vehicle-card .vehicle-actions .btn-outline-secondary {
                background: #ffffff !important;
                border-color: color-mix(in srgb, var(--black-color, #717171) 25%, transparent) !important;
                color: var(--black-color, #717171) !important;
            }

            .t2-vehicle-card .vehicle-actions .btn-outline-primary:hover,
            .t2-vehicle-card .vehicle-actions .add-to-cart-btn:hover,
            .t2-vehicle-card .vehicle-actions .btn-outline-secondary:hover {
                background: var(--black-color, #717171) !important;
                border-color: var(--black-color, #717171) !important;
                color: #ffffff !important;
            }

            .t2-vehicle-card .vehicle-actions .btn-warning {
                background: var(--black-color, #717171) !important;
                border-color: var(--black-color, #717171) !important;
                color: #ffffff !important;
            }

            @media (min-width: 768px) {
                .t2-vehicle-card .vehicle-actions:has(.btn:nth-child(2)) {
                    grid-template-columns: 1fr 1fr !important;
                }
            }
        </style>
    @endpush
@endonce

{{-- Include vehicle card scripts (Add to Cart, Book Now, Request Quotation handlers) --}}
{{-- Uses @once directive internally to ensure scripts are loaded only once even with multiple cards --}}
@include('components.vehicle-card-scripts')
