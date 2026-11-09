@php
    $pickupDate = !empty($item['pickup_date']) ? \Carbon\Carbon::parse($item['pickup_date']) : null;
    $returnDate = !empty($item['return_date']) ? \Carbon\Carbon::parse($item['return_date']) : null;
    $calculatedDays = $pickupDate && $returnDate ? max(1, $pickupDate->diffInDays($returnDate) + 1) : 1;
    $itemTotal = $item['total_price'] ?? (($item['price'] ?? 0) * $calculatedDays);
    $serviceType = $item['service_type'] ?? null;
    $durationLabel = getServiceDurationLabel($serviceType, $calculatedDays);
    $isDayService = isServiceDurationBased(data_get($item, 'service_type_data.code', $serviceType), data_get($item, 'service_type_data.pricing_mode'));
    $distanceDetails = $item['distance_details'] ?? [];
    if (empty($distanceDetails) && isset($item['service_package_info'])) {
        $perDay = $item['service_package_info']['max_km_per_day'] ?? null;
        $distanceDetails = ['allowed_total_km' => $perDay ? $perDay * $calculatedDays : null, 'free_km_per_day' => $perDay];
    }
    $allowedTotalKm = $distanceDetails['allowed_total_km'] ?? null;
    $freeKmPerDay = $distanceDetails['free_km_per_day'] ?? null;
    $extraKmPrice = $distanceDetails['extra_km_price'] ?? null;
    $minimumKm = $distanceDetails['minimum_km'] ?? null;
    $minimumKmApplied = $distanceDetails['minimum_km_applied'] ?? false;
    $actualDistance = $distanceDetails['actual_journey_distance'] ?? ($minimumKmApplied ? null : ($distanceDetails['journey_distance'] ?? null));
    $outboundKm = $item['outbound_distance_km'] ?? $distanceDetails['outbound_distance_km'] ?? null;
    $returnKm = $item['return_distance_km'] ?? $distanceDetails['return_distance_km'] ?? null;
    $pickupLoc = is_array($item['pickup_location'] ?? null) ? ($item['pickup_location']['address'] ?? '') : ($item['pickup_location'] ?? '');
    $dropoffLoc = is_array($item['dropoff_location'] ?? null) ? ($item['dropoff_location']['address'] ?? '') : ($item['dropoff_location'] ?? '');
    if ($serviceType === 'airport_transfers') {
        $pickupLoc = $pickupLoc ?: ($item['pickup_airport'] ?? data_get($item, 'flight_details.arrival_airport', ''));
        $dropoffLoc = $dropoffLoc ?: ($item['dropoff_airport'] ?? data_get($item, 'flight_details.departure_airport', ''));
    }
    $selectedAddons = is_array($item['addons'] ?? null) ? $item['addons'] : [];
    $currentExtraKm = $item['extra_km']['km'] ?? 0;
    $hasItemDiscount = $item['has_discount'] ?? false;
    $itemOriginalAmount = $item['original_amount'] ?? $itemTotal;
@endphp
<li class="checkout-cart-item is-expanded border-b border-slate-100 p-5" data-cart-key="{{ $key }}" data-purpose="vehicle-item">
    <div class="flex items-start gap-4">
        <div class="h-16 w-20 shrink-0 overflow-hidden rounded-xl border border-slate-200 bg-slate-100">
            <img class="h-full w-full object-cover" src="{{ !empty($item['image']) ? s3_asset($item['image']) : asset('assets/img/innerpages/cart-img1.png') }}" alt="{{ $item['name'] ?? 'Vehicle' }}">
        </div>
        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-start justify-between gap-2"><h3 class="text-sm font-bold text-slate-900">{{ $item['name'] ?? 'Vehicle' }}</h3><strong class="text-xs font-bold text-checkout-accent">{{ $currencySymbol }} {{ number_format(floor(max(0, $itemTotal)), 0) }}</strong></div>
            <div class="mt-1 flex flex-wrap items-center gap-2"><span class="rounded-md bg-checkout-accent-soft px-2 py-0.5 text-[10px] font-semibold text-checkout-accent">{{ !empty($item['is_tour']) ? 'Tour' : data_get($item, 'service_type_data.name', $serviceType ?? 'Service') }}</span><span class="text-xs text-slate-500">{{ $durationLabel }}</span></div>
            <p class="mt-1 text-xs text-slate-500">{{ $pickupDate ? $pickupDate->format('M d, Y') : 'Date N/A' }} @if (!empty($item['pickup_time'])) · {{ $item['pickup_time'] }} @endif @if ($returnDate) – {{ $returnDate->format('M d, Y') }} @endif</p>
            @if ($hasItemDiscount && $itemOriginalAmount > $itemTotal)<p class="mt-1 text-xs text-emerald-700"><del class="text-slate-400">{{ $currencySymbol }} {{ number_format(floor(max(0, $itemOriginalAmount)), 0) }}</del> · {{ round($item['discount_percentage'] ?? 0) }}% off @if (($item['discount_amount'] ?? 0) > 0) · Save {{ $currencySymbol }} {{ number_format(floor(max(0, $item['discount_amount'])), 0) }} @endif</p>@endif
        </div>
    </div>
    <div class="checkout-item-toolbar mt-3 flex items-center justify-between gap-3">
        <button type="button" class="checkout-item-toggle text-xs font-semibold text-slate-600" aria-expanded="true" title="Hide booking details"><span class="checkout-item-toggle-label">Less</span> <i class="bi bi-chevron-down" aria-hidden="true"></i></button>
        <button type="button" class="checkout-remove-item-btn text-xs font-semibold text-checkout-accent" data-cart-key="{{ $key }}" aria-label="Remove {{ $item['name'] ?? 'item' }}"><i class="bi bi-trash" aria-hidden="true"></i> Remove</button>
    </div>
    <div class="v2-item-details mt-3 rounded-xl bg-slate-50 p-3 text-xs text-slate-600">
        <p><strong class="text-slate-900">Pickup:</strong> {{ formatLocationName($pickupLoc, 'N/A') }}</p>
        <p class="mt-1"><strong class="text-slate-900">Dropoff:</strong> {{ formatLocationName($dropoffLoc, 'N/A') }}</p>
        @if (!$isDayService && $minimumKmApplied && $minimumKm)<p class="mt-1">Minimum {{ number_format($minimumKm, 0) }} km @if (is_numeric($actualDistance)) (Actual: {{ number_format($actualDistance, 1) }} km) @endif</p>@endif
        @if ($allowedTotalKm || $freeKmPerDay)<p class="mt-1">@if ($freeKmPerDay && $calculatedDays > 1) {{ number_format($freeKmPerDay, 0) }} km/day ({{ number_format($allowedTotalKm ?? $freeKmPerDay * $calculatedDays, 0) }} km total) @else {{ number_format($allowedTotalKm ?: $freeKmPerDay, 0) }} km included @endif @if ($extraKmPrice) · Extra: {{ $currencySymbol }} {{ number_format(floor(max(0, $extraKmPrice)), 0) }}/km @endif</p>@endif
        @if (!empty($item['is_return_trip']) && $outboundKm && $returnKm)<p class="mt-1">Trip distance: {{ number_format($outboundKm, 1) }} km outbound + {{ number_format($returnKm, 1) }} km return = {{ number_format($outboundKm + $returnKm, 1) }} km</p>@endif
        @if (!empty($item['is_return_trip']) && !empty($item['return_trip_date']))<p class="mt-1 text-emerald-700">Return trip: {{ \Carbon\Carbon::parse($item['return_trip_date'])->format('M d, Y') }} @if (!empty($item['return_trip_time'])) · {{ $item['return_trip_time'] }} @endif · {{ formatLocationName($dropoffLoc, 'Drop-off') }} → {{ formatLocationName($pickupLoc, 'Pickup') }} @if (($item['return_discount_percentage'] ?? 0) > 0) · {{ $item['return_discount_percentage'] }}% off @endif</p>@endif
        @if (!empty($item['is_return_trip']) && !empty($item['one_way_price']) && !empty($item['return_price']))<p class="mt-1">Outbound: {{ $currencySymbol }} {{ number_format(floor(max(0, $item['one_way_price'])), 0) }} · Return: {{ $currencySymbol }} {{ number_format(floor(max(0, $item['return_price'])), 0) }}</p>@endif
        @if (!empty($item['is_tour']))
            <div class="mt-3"><strong class="text-slate-900">Tour itinerary</strong><p>{{ $item['passenger_count'] ?? 0 }} travelers · {{ $item['luggage_count'] ?? 0 }} bags · {{ $item['child_seat_count'] ?? 0 }} child seats requested · {{ number_format((float) ($item['tour_distance_km'] ?? 0), 1) }} km estimated</p>
                @foreach ($item['tour_days_snapshot'] ?? [] as $day)<p class="mt-1"><strong>Day {{ $day['day_number'] }}:</strong> {{ data_get($day, 'start_location.name', 'Start') }} @foreach ($day['stops'] ?? [] as $stop) → {{ $stop['name'] ?? 'Stop' }} @if (!empty($stop['estimated_arrival'])) (~{{ $stop['estimated_arrival'] }}) @endif @endforeach → {{ data_get($day, 'end_location.name', 'End') }}</p>@endforeach
                <p class="mt-1">Arrival times are route estimates in each day’s local time zone.</p><p class="mt-1">{{ $item['activity_entry_note'] ?? '' }}</p>
            </div>
        @endif
    </div>
    <div class="v2-item-details checkout-item-addons {{ count($selectedAddons) ? '' : 'checkout-option-pending' }} mt-3" data-cart-key="{{ $key }}" data-service-type="{{ $serviceType ?? '' }}">
        <div class="flex items-center justify-between gap-3"><div><strong class="block text-xs text-slate-900">Add-ons</strong><small class="text-xs text-slate-500">Selected: <span class="checkout-addon-count">{{ count($selectedAddons) }}</span></small></div><button type="button" class="checkout-toggle-addons text-xs font-semibold text-checkout-accent" data-cart-key="{{ $key }}" data-service-type="{{ $serviceType ?? '' }}" data-vehicle-name="{{ $item['name'] ?? 'Vehicle' }}">Manage</button></div>
        <div class="checkout-selected-addons mt-2 text-xs text-slate-500">@forelse ($selectedAddons as $addon)<div class="checkout-selected-addon-row" data-addon-id="{{ $addon['id'] ?? '' }}"><strong>{{ $addon['name'] ?? 'Add-on' }}</strong> · Qty: {{ max(1, (int) ($addon['qty'] ?? 1)) }} · {{ $currencySymbol }} {{ number_format(max(0, (float) ($addon['calculated_amount'] ?? 0)), $displayCurrency === 'LKR' ? 0 : 2) }}</div>@empty<div class="checkout-no-addons">No add-ons selected</div>@endforelse</div>
    </div>
    @if (empty($item['is_tour']))
        <div class="v2-item-details checkout-item-extra-km {{ $currentExtraKm > 0 ? '' : 'checkout-option-pending' }} mt-3" data-cart-key="{{ $key }}">
            <div class="flex items-center justify-between gap-3"><div><strong class="block text-xs text-slate-900">Extra distance</strong><small class="text-xs text-slate-500">Selected: <span class="checkout-extra-km-count">{{ number_format($currentExtraKm, 0) }}</span> km</small></div><button type="button" class="checkout-toggle-extra-km text-xs font-semibold text-checkout-accent" data-cart-key="{{ $key }}" data-vehicle-name="{{ $item['name'] ?? 'Vehicle' }}">Manage</button></div>
            <div class="checkout-extra-km-panel" data-cart-key="{{ $key }}" style="display:none"></div>
        </div>
    @endif
</li>
