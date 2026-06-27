                                                    @php
                                                        // Calculate days from pickup and return dates - day-based calculation
                                                        $pickupDate = isset($item['pickup_date'])
                                                            ? \Carbon\Carbon::parse($item['pickup_date'])
                                                            : null;
                                                        $returnDate = isset($item['return_date'])
                                                            ? \Carbon\Carbon::parse($item['return_date'])
                                                            : null;
                                                        $calculatedDays =
                                                            $pickupDate && $returnDate
                                                                ? max(1, $pickupDate->diffInDays($returnDate) + 1)
                                                                : 1;

                                                        // Use total_price if available (already calculated for all days in LKR)
                                                        // Otherwise calculate from per-day price and calculated days
                                                        $itemTotal = isset($item['total_price'])
                                                            ? $item['total_price']
                                                            : ($item['price'] ?? 0) * $calculatedDays;

                                                        // Get discount/adjustment details for this item
                                                        $hasItemDiscount = $item['has_discount'] ?? false;
                                                        $itemOriginalAmount = $item['original_amount'] ?? $itemTotal;
                                                        $itemDiscountAmount = $item['discount_amount'] ?? 0;
                                                        $itemDiscountPercentage = $item['discount_percentage'] ?? 0;
                                                    @endphp
                                                    <li class="single-item checkout-cart-item {{ $loop->first ? 'is-expanded' : 'is-collapsed' }}"
                                                        data-cart-key="{{ $key }}">
                                                        <button type="button" class="checkout-item-toggle"
                                                            aria-expanded="{{ $loop->first ? 'true' : 'false' }}"
                                                            title="{{ $loop->first ? 'Hide' : 'Show' }} booking details">
                                                            <span class="checkout-item-toggle-label">{{ $loop->first ? 'Less' : 'Details' }}</span>
                                                            <i class="bi bi-chevron-down" aria-hidden="true"></i>
                                                        </button>
                                                        <div class="item-area">
                                                            <div class="main-item">
                                                                <div class="item-img">
                                                                    @if (isset($item['image']) && $item['image'])
                                                                        <img src="{{ s3_asset($item['image']) }}"
                                                                            alt="{{ $item['name'] ?? 'Vehicle' }}">
                                                                    @else
                                                                        <img src="{{ asset('assets/img/innerpages/cart-img1.png') }}"
                                                                            alt="{{ $item['name'] ?? 'Vehicle' }}">
                                                                    @endif
                                                                </div>
                                                                <div class="content-and-quantity">
                                                                    <div class="content">
                                                                        @php
                                                                            $serviceType =
                                                                                $item['service_type'] ?? null;
                                                                            $isFixedRate = isServiceFixedRate(
                                                                                $serviceType,
                                                                            );
                                                                            $pricingLabel = getServicePricingLabel(
                                                                                $serviceType,
                                                                            );
                                                                            $durationLabel = getServiceDurationLabel(
                                                                                $serviceType,
                                                                                $calculatedDays,
                                                                            );
                                                                        @endphp
                                                                        @if ($isFixedRate)
                                                                            <span class="vehicle-rate">{{ $pricingLabel }}:
                                                                                {{ $currencySymbol }}
                                                                                {{ number_format(floor(max(0, $itemTotal)), 0) }}</span>
                                                                        @else
                                                                            <span class="vehicle-rate">{{ $currencySymbol }}
                                                                                {{ number_format(floor(max(0, $item['price'] ?? 0)), 0) }}/day
                                                                                × {{ $durationLabel }}</span>
                                                                        @endif
                                                                        <h6 class="vehicle-title">
                                                                            <a
                                                                                href="#">{{ $item['name'] ?? '' }}</a>
                                                                            <span
                                                                                class="service-type-badge">{{ $item['service_type_data']['name'] ?? ($item['service_type'] ?? 'Service') }}</span>
                                                                        </h6>
                                                                        <p class="trip-meta"><small>
                                                                                <i class="bi bi-calendar-event"></i>
                                                                                {{ $pickupDate ? $pickupDate->format('M d, Y') : 'Date N/A' }}
                                                                                @if (!empty($item['pickup_time']))
                                                                                    @ {{ $item['pickup_time'] }}
                                                                                @endif
                                                                                @if ($returnDate)
                                                                                    -
                                                                                    {{ $returnDate->format('M d, Y') }}
                                                                                @endif
                                                                                <span>({{ $durationLabel }})</span>
                                                                            </small></p>

                                                                        @php
                                                                            // Distance details for km information display
                                                                            $distanceDetails =
                                                                                $item['distance_details'] ?? [];

                                                                            // If distance_details is empty, try to get from service package info or calculate
                                                                            if (
                                                                                empty($distanceDetails) &&
                                                                                isset($item['service_package_info'])
                                                                            ) {
                                                                                $servicePackageInfo =
                                                                                    $item['service_package_info'];
                                                                                // Build fallback distance_details from service package
                                                                                $distanceDetails = [
                                                                                    'allowed_total_km' => isset(
                                                                                        $servicePackageInfo[
                                                                                            'max_km_per_day'
                                                                                        ],
                                                                                    )
                                                                                        ? $servicePackageInfo[
                                                                                                'max_km_per_day'
                                                                                            ] * $calculatedDays
                                                                                        : null,
                                                                                    'free_km_per_day' =>
                                                                                        $servicePackageInfo[
                                                                                            'max_km_per_day'
                                                                                        ] ?? null,
                                                                                    'extra_km_price' => null, // Will be fetched from service separately
                                                                                    'free_km_per_package' =>
                                                                                        $servicePackageInfo[
                                                                                            'max_km_per_package'
                                                                                        ] ?? null,
                                                                                ];
                                                                            }

                                                                            $allowedTotalKm =
                                                                                $distanceDetails['allowed_total_km'] ??
                                                                                null;
                                                                            $extraKmPrice =
                                                                                $distanceDetails['extra_km_price'] ??
                                                                                null;
                                                                            $freeKmPerDay =
                                                                                $distanceDetails['free_km_per_day'] ??
                                                                                null;

                                                                            // Minimum KM charge info
                                                                            $minimumKm =
                                                                                $distanceDetails['minimum_km'] ?? null;
                                                                            $minimumKmApplied =
                                                                                $distanceDetails[
                                                                                    'minimum_km_applied'
                                                                                ] ?? false;
                                                                            $actualJourneyDistance =
                                                                                $distanceDetails[
                                                                                    'actual_journey_distance'
                                                                                ] ??
                                                                                ($distanceDetails['journey_distance'] ??
                                                                                    null);
                                                                        @endphp

                                                                        @if ($minimumKmApplied && $minimumKm)
                                                                            <p><small
                                                                                    style="color: #92400e; background: #fef3c7; padding: 2px 6px; border-radius: 4px;">
                                                                                    <i class="bi bi-info-circle"></i>
                                                                                    <strong>Minimum
                                                                                        {{ number_format($minimumKm, 0) }}
                                                                                        km</strong>
                                                                                    (Actual:
                                                                                    {{ number_format($actualJourneyDistance, 1) }}
                                                                                    km)
                                                                                </small></p>
                                                                        @endif
                                                                        
                                                                        {{-- Display Return Trip KM Breakdown --}}
                                                                        @php
                                                                            $outboundKm = $item['outbound_distance_km'] ?? $distanceDetails['outbound_distance_km'] ?? null;
                                                                            $returnKm = $item['return_distance_km'] ?? $distanceDetails['return_distance_km'] ?? null;
                                                                            $hasReturnKmData = !empty($item['is_return_trip']) && $outboundKm && $returnKm;
                                                                        @endphp
                                                                        @if ($hasReturnKmData)
                                                                            <p><small style="background: #e3f2fd; padding: 4px 8px; border-radius: 4px; display: inline-block; border-left: 3px solid #2196f3;">
                                                                                <i class="bi bi-signpost-2-fill" style="color: #1565c0;"></i>
                                                                                <strong style="color: #1565c0;">Trip Distance:</strong>
                                                                                <span style="color: #1976d2;">
                                                                                    <i class="bi bi-arrow-right-circle"></i> {{ number_format($outboundKm, 1) }} km
                                                                                </span>
                                                                                <span style="color: #28a745;">
                                                                                    <i class="bi bi-arrow-left-circle"></i> {{ number_format($returnKm, 1) }} km
                                                                                </span>
                                                                                <strong style="color: #1565c0;">
                                                                                    = {{ number_format($outboundKm + $returnKm, 1) }} km total
                                                                                </strong>
                                                                            </small></p>
                                                                        @endif

                                                                        @if ($allowedTotalKm || $freeKmPerDay)
                                                                            <p><small style="color: #0066cc;">
                                                                                    <i class="bi bi-speedometer2"></i>
                                                                                    @if ($freeKmPerDay && $calculatedDays > 1)
                                                                                        {{ number_format($freeKmPerDay, 0) }}
                                                                                        km/day
                                                                                        ({{ number_format($allowedTotalKm ?? $freeKmPerDay * $calculatedDays, 0) }}
                                                                                        km total)
                                                                                    @elseif($allowedTotalKm)
                                                                                        {{ number_format($allowedTotalKm, 0) }}
                                                                                        km included
                                                                                    @else
                                                                                        {{ number_format($freeKmPerDay, 0) }}
                                                                                        km included
                                                                                    @endif

                                                                                    @if ($extraKmPrice)
                                                                                        <span style="color: #999;"> |
                                                                                            Extra:
                                                                                            <small
                                                                                                class="currency-symbol">{{ $currencySymbol }}</small>
                                                                                            {{ number_format(floor(max(0, $extraKmPrice)), 0) }}/km</span>
                                                                                    @endif
                                                                                </small></p>
                                                                        @endif
                                                                        @php
                                                                            $pickupLoc = is_array(
                                                                                $item['pickup_location'] ?? null,
                                                                            )
                                                                                ? $item['pickup_location']['address'] ??
                                                                                    ''
                                                                                : $item['pickup_location'] ?? '';
                                                                            $dropoffLoc = is_array(
                                                                                $item['dropoff_location'] ?? null,
                                                                            )
                                                                                ? $item['dropoff_location'][
                                                                                        'address'
                                                                                    ] ?? ''
                                                                                : $item['dropoff_location'] ?? '';
                                                                            if (
                                                                                ($item['service_type'] ?? '') ===
                                                                                'airport_transfers'
                                                                            ) {
                                                                                $pickupLoc =
                                                                                    $pickupLoc ?:
                                                                                    $item['pickup_airport'] ??
                                                                                        ($item['flight_details'][
                                                                                            'arrival_airport'
                                                                                        ] ??
                                                                                            '');
                                                                                $dropoffLoc =
                                                                                    $dropoffLoc ?:
                                                                                    $item['dropoff_airport'] ??
                                                                                        ($item['flight_details'][
                                                                                            'departure_airport'
                                                                                        ] ??
                                                                                            '');
                                                                            }
                                                                        @endphp
                                                                        <p class="trip-location"><small><i class="bi bi-geo-alt"></i>
                                                                                <strong>Pickup:</strong>
                                                                                {{ $pickupLoc ?: 'N/A' }}</small></p>
                                                                        <p class="trip-location"><small><i class="bi bi-geo-alt-fill"></i>
                                                                                <strong>Dropoff:</strong>
                                                                                {{ $dropoffLoc ?: 'N/A' }}</small></p>

                                                                        {{-- Return Trip Info --}}
                                                                        @if (!empty($item['is_return_trip']) && !empty($item['return_trip_date']))
                                                                            <div class="return-trip-info mt-2 p-2"
                                                                                style="background: linear-gradient(135deg, #e8f5e9, #c8e6c9); border-radius: 8px; border-left: 3px solid #28a745;">
                                                                                <p
                                                                                    style="margin: 0; font-weight: 600; color: #2e7d32; font-size: 12px;">
                                                                                    <i class="bi bi-arrow-left-right"></i>
                                                                                    Return Trip Included
                                                                                </p>
                                                                                <p
                                                                                    style="margin: 4px 0 0 0; font-size: 11px;">
                                                                                    <i class="bi bi-calendar-check"
                                                                                        style="color: #28a745;"></i>
                                                                                    Return:
                                                                                    {{ \Carbon\Carbon::parse($item['return_trip_date'])->format('M d, Y') }}
                                                                                    @if (!empty($item['return_trip_time']))
                                                                                        @ {{ $item['return_trip_time'] }}
                                                                                    @endif
                                                                                </p>
                                                                                <p
                                                                                    style="margin: 4px 0 0 0; font-size: 11px;">
                                                                                    <i class="bi bi-geo-alt"
                                                                                        style="color: #28a745;"></i>
                                                                                    {{ $dropoffLoc ?: 'Drop-off' }} →
                                                                                    {{ $pickupLoc ?: 'Pickup' }}
                                                                                </p>
                                                                                @if (!empty($item['return_discount_percentage']) && $item['return_discount_percentage'] > 0)
                                                                                    <span class="badge bg-success mt-1"
                                                                                        style="font-size: 10px;">
                                                                                        <i class="bi bi-tag-fill"></i>
                                                                                        {{ $item['return_discount_percentage'] }}%
                                                                                        off return trip
                                                                                    </span>
                                                                                @endif
                                                                            </div>
                                                                        @endif
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            <div class="item-total">
                                                                @php
                                                                    // Return trip pricing variables
                                                                    $isReturnTrip = $item['is_return_trip'] ?? false;
                                                                    $oneWayPrice = $item['one_way_price'] ?? null;
                                                                    $returnPrice = $item['return_price'] ?? null;
                                                                    $returnDiscountPct =
                                                                        $item['return_discount_percentage'] ?? 0;
                                                                @endphp

                                                                {{-- Return Trip Pricing Breakdown --}}
                                                                @if ($isReturnTrip && $oneWayPrice && $returnPrice)
                                                                    <div class="return-trip-breakdown mb-2"
                                                                        style="font-size: 11px; text-align: right;">
                                                                        <div style="color: #0d6efd;">
                                                                            <i class="bi bi-arrow-right-circle"></i>
                                                                            Outbound:
                                                                            <small
                                                                                class="currency-symbol">{{ $currencySymbol }}</small>{{ number_format(floor(max(0, $oneWayPrice)), 0) }}
                                                                        </div>
                                                                        <div style="color: #198754;">
                                                                            <i class="bi bi-arrow-left-circle"></i> Return:
                                                                            <small
                                                                                class="currency-symbol">{{ $currencySymbol }}</small>{{ number_format(floor(max(0, $returnPrice)), 0) }}
                                                                            @if ($returnDiscountPct > 0)
                                                                                <span class="badge bg-success"
                                                                                    style="font-size: 9px;">{{ $returnDiscountPct }}%
                                                                                    off</span>
                                                                            @endif
                                                                        </div>
                                                                    </div>
                                                                @endif

                                                                @if ($hasItemDiscount && $itemOriginalAmount > $itemTotal)
                                                                    {{-- Show discount badge --}}
                                                                    <span class="checkout-item-discount-badge">
                                                                        {{ round($itemDiscountPercentage) }}% OFF
                                                                    </span>
                                                                    {{-- Show original price with strikethrough --}}
                                                                    <div class="checkout-original-price">
                                                                        <del>{{ $currencySymbol }}{{ number_format(floor(max(0, $itemOriginalAmount)), 0) }}</del>
                                                                    </div>
                                                                @endif
                                                                <div
                                                                    class="checkout-final-price {{ $hasItemDiscount ? 'discounted' : '' }}">
                                                                    <small
                                                                        class="currency-symbol">{{ $currencySymbol }}</small>
                                                                    {{ number_format(floor(max(0, $itemTotal)), 0) }}
                                                                </div>
                                                                @if ($hasItemDiscount && $itemDiscountAmount > 0)
                                                                    <div class="checkout-savings">
                                                                        <small>Save
                                                                            {{ $currencySymbol }}{{ number_format(floor(max(0, $itemDiscountAmount)), 0) }}</small>
                                                                    </div>
                                                                @endif
                                                                <button type="button" class="checkout-remove-item-btn"
                                                                    data-cart-key="{{ $key }}"
                                                                    title="Remove {{ $item['name'] ?? 'item' }}">
                                                                    <i class="bi bi-trash"></i>
                                                                    <span>Remove</span>
                                                                </button>
                                                            </div>
                                                        </div>
                                                        @php
                                                            $selectedAddons = $item['addons'] ?? [];
                                                            $selectedAddonCount = is_array($selectedAddons)
                                                                ? count($selectedAddons)
                                                                : 0;
                                                        @endphp
                                                        <div class="checkout-item-addons {{ $selectedAddonCount > 0 ? '' : 'checkout-option-pending' }}"
                                                            data-cart-key="{{ $key }}"
                                                            data-service-type="{{ $item['service_type'] ?? '' }}">
                                                            <div class="checkout-addon-header">
                                                                <div>
                                                                    <strong><i class="bi bi-plus-circle"></i> Add-ons</strong>
                                                                    <small><span class="checkout-addon-count">{{ $selectedAddonCount }}</span> selected</small>
                                                                </div>
                                                                <button type="button" class="checkout-toggle-addons"
                                                                    data-cart-key="{{ $key }}"
                                                                    data-service-type="{{ $item['service_type'] ?? '' }}"
                                                                    data-vehicle-name="{{ $item['name'] ?? 'Vehicle' }}">
                                                                    Manage
                                                                </button>
                                                            </div>
                                                            <div class="checkout-selected-addons">
                                                                @forelse ($selectedAddons as $selectedAddon)
                                                                    @php
                                                                        $selectedQty = max(1, (int) ($selectedAddon['qty'] ?? 1));
                                                                        $selectedAmount = (float) ($selectedAddon['calculated_amount'] ?? 0);
                                                                    @endphp
                                                                    <div class="checkout-selected-addon-row"
                                                                        data-addon-id="{{ $selectedAddon['id'] ?? '' }}">
                                                                        <div>
                                                                            <strong>{{ $selectedAddon['name'] ?? 'Add-on' }}</strong>
                                                                            <small>Qty: {{ $selectedQty }}</small>
                                                                        </div>
                                                                        <span>{{ $currencySymbol }} {{ number_format(floor(max(0, $selectedAmount)), 0) }}</span>
                                                                    </div>
                                                                @empty
                                                                    <div class="checkout-no-addons">No add-ons selected</div>
                                                                @endforelse
                                                            </div>
                                                        </div>
                                                        @php
                                                            $currentExtraKm = $item['extra_km']['km'] ?? 0;
                                                        @endphp
                                                        <div class="checkout-item-extra-km {{ $currentExtraKm > 0 ? '' : 'checkout-option-pending' }}"
                                                            data-cart-key="{{ $key }}">
                                                            <div class="checkout-extra-km-header">
                                                                <div>
                                                                    <strong><i class="bi bi-speedometer2"></i> Extra KM</strong>
                                                                    <small>Selected: <span class="checkout-extra-km-count">{{ number_format($currentExtraKm, 0) }}</span> km</small>
                                                                </div>
                                                                <button type="button" class="checkout-toggle-extra-km"
                                                                    data-cart-key="{{ $key }}">
                                                                    Manage
                                                                </button>
                                                            </div>
                                                            <div class="checkout-extra-km-panel"
                                                                data-cart-key="{{ $key }}"
                                                                style="display: none;">
                                                                <div class="checkout-extra-km-loading">Loading extra KM options...</div>
                                                            </div>
                                                        </div>
                                                    </li>
