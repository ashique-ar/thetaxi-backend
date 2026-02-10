@props([
    'item',
    'index',
    'currencySymbol' => 'LKR',
    'variant' => 'default', // 'default' or 'compact'
])

@php
    // Backwards-compat: allow the caller to pass either 'item' or 'group' (CMS passes 'group')
    if (!isset($item) && isset($group)) {
        $item = $group;
    }

    // Normalize arrays into objects for predictable property access
    if (isset($item) && is_array($item)) {
        $item = (object) $item;
    }

    // Normalize nested arrays for common nested objects
    if (isset($item->serviceType) && is_array($item->serviceType)) {
        $item->serviceType = (object) $item->serviceType;
    }
    if (isset($item->vehicleGroup) && is_array($item->vehicleGroup)) {
        $item->vehicleGroup = (object) $item->vehicleGroup;
    }

    // Ensure optional props have sensible defaults
    $index = $index ?? 0;
    $currencySymbol = $currencySymbol ?? 'LKR';

    // Safely decode location data
    $pickupLocRaw = $item->pickup_location ?? null;
    $pickupLoc = is_string($pickupLocRaw) ? json_decode($pickupLocRaw, true) : $pickupLocRaw ?? [];

    $dropoffLocRaw = $item->dropoff_location ?? null;
    $dropoffLoc = is_string($dropoffLocRaw) ? json_decode($dropoffLocRaw, true) : $dropoffLocRaw ?? [];

    // Format dates safely (guard when dates may be missing)
    $fromDate = isset($item->from_date) ? \Carbon\Carbon::parse($item->from_date)->format('M d, Y') : 'N/A';
    $toDate = isset($item->to_date) ? \Carbon\Carbon::parse($item->to_date)->format('M d, Y') : 'N/A';
    $fromTime = $item->from_time ?? '00:00';
    $toTime = $item->to_time ?? '00:00';

    // Get display values with fallbacks
    $vehicleGroupName = $item->vehicleGroup?->name ?? 'N/A';
    $serviceTypeName = $item->serviceType?->name ?? 'N/A';
    $durationDays = $item->duration_days ?? 0;
    $pickupAddress = $pickupLoc['address'] ?? 'N/A';
    $dropoffAddress = $dropoffLoc['address'] ?? 'N/A';

    // Get pricing values with fallbacks
    $unitPrice = $item->unit_price ?? ($item->amount ?? 0);
    $totalPrice = $item->total_price ?? ($item->amount ?? 0);
    $quantity = $item->quantity ?? 1;
@endphp

@if ($variant === 'compact')
    <!-- Compact Variant (for email templates) -->
    <div class="booking-item-email p-3 mb-3 border rounded bg-light">
        <div class="row align-items-center mb-2">
            <div class="col-md-7">
                <strong class="text-primary">
                    <i class="bi bi-car-front-fill me-1"></i>
                    Vehicle {{ $index + 1 }}: {{ $vehicleGroupName }}
                </strong>
                <!-- Service Type Badge - Highlighted -->
                <div class="small mt-1">
                    <span class="badge bg-warning text-dark" style="font-size: 11px; font-weight: 600;">
                        <i class="bi bi-tag"></i> {{ $serviceTypeName }}
                    </span>
                </div>
            </div>
            <div class="col-md-5 text-md-end">
                @php
                    $servicePricingMode = $item->serviceType?->pricing_mode ?? null;
                    $distanceDetails = isset($item->distance_details)
                        ? (is_string($item->distance_details)
                            ? json_decode($item->distance_details, true)
                            : $item->distance_details)
                        : [];
                    $displayDistance =
                        $distanceDetails['actual_journey_distance'] ??
                        ($distanceDetails['journey_distance'] ?? ($distanceDetails['total_distance'] ?? null));
                    $itemJourneyDuration =
                        $item->journey_duration_seconds ?? ($distanceDetails['journey_duration_seconds'] ?? null);
                @endphp

                @if ($servicePricingMode !== 'day')
                    @if (!empty($displayDistance))
                        <span class="badge bg-white text-dark border"><strong>{{ number_format($displayDistance, 1) }}
                                km</strong>
                            @if (!empty($itemJourneyDuration))
                                <div class="small text-muted">{{ gmdate('H:i', $itemJourneyDuration) }} estimated</div>
                            @endif
                        </span>
                    @elseif (!empty($itemJourneyDuration))
                        @php
                            $hours = floor($itemJourneyDuration / 3600);
                            $minutes = floor(($itemJourneyDuration % 3600) / 60);
                            $readable = trim(($hours > 0 ? "{$hours}h" : '') . ($minutes > 0 ? " {$minutes}m" : ''));
                        @endphp
                        <span class="badge bg-white text-dark border">{{ $readable }} estimated</span>
                    @else
                        <span class="badge bg-white text-dark border">{{ $durationDays }}
                            Day{{ $durationDays != 1 ? 's' : '' }}</span>
                    @endif
                @else
                    <span class="badge bg-white text-dark border">{{ $durationDays }}
                        Day{{ $durationDays != 1 ? 's' : '' }}</span>
                @endif
            </div>
        </div>

        <div class="row g-2 mt-2">
            <div class="col-md-6 border-end">
                <div class="d-flex align-items-start">
                    <i class="bi bi-geo-alt-fill text-primary mt-1 me-2" style="font-size: 0.8rem;"></i>
                    <div>
                        <div class="text-uppercase" style="font-size: 0.65rem; font-weight: bold; color: #717171;">
                            Pickup
                        </div>
                        <div style="font-size: 0.9rem;">
                            {{ $fromDate }} at {{ $fromTime }}
                        </div>
                        <div class="small text-muted">
                            {{ $pickupAddress }}
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="d-flex align-items-start">
                    <i class="bi bi-geo-fill text-danger mt-1 me-2" style="font-size: 0.8rem;"></i>
                    <div>
                        <div class="text-uppercase" style="font-size: 0.65rem; font-weight: bold; color: #717171;">
                            Return
                        </div>
                        <div style="font-size: 0.9rem;">
                            {{ $toDate }} at {{ $toTime }}
                        </div>
                        <div class="small text-muted">
                            {{ $dropoffAddress }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@else
    <!-- Default Variant (for web pages) -->
    <div class="booking-item shadow-sm p-4 mb-3 border rounded bg-white">
        <div class="row align-items-center mb-3">
            <div class="col-md-8">
                <h6 class="mb-0 text-primary">
                    <i class="bi bi-car-front-fill me-2"></i>
                    Vehicle {{ $index + 1 }}: {{ $vehicleGroupName }}
                </h6>
                <!-- Service Type Badge - Highlighted -->
                <div class="mt-2">
                    <span class="badge bg-warning text-dark" style="font-size: 12px; font-weight: 600;">
                        <i class="bi bi-tag"></i> {{ $serviceTypeName }}
                    </span>
                </div>
            </div>
            <div class="col-md-4 text-md-end">
                @php
                    $servicePricingMode = $item->serviceType?->pricing_mode ?? null;
                    $distanceDetails = isset($item->distance_details)
                        ? (is_string($item->distance_details)
                            ? json_decode($item->distance_details, true)
                            : $item->distance_details)
                        : [];
                    $displayDistance =
                        $distanceDetails['actual_journey_distance'] ??
                        ($distanceDetails['journey_distance'] ?? ($distanceDetails['total_distance'] ?? null));
                    $itemJourneyDuration =
                        $item->journey_duration_seconds ?? ($distanceDetails['journey_duration_seconds'] ?? null);
                @endphp

                @if ($servicePricingMode !== 'day')
                    @if (!empty($displayDistance))
                        <span class="badge bg-white text-dark border"><strong>{{ number_format($displayDistance, 1) }}
                                km</strong>
                            @if (!empty($itemJourneyDuration))
                                <div class="small text-muted">{{ gmdate('H:i', $itemJourneyDuration) }} estimated</div>
                            @endif
                        </span>
                    @elseif (!empty($itemJourneyDuration))
                        @php
                            $hours = floor($itemJourneyDuration / 3600);
                            $minutes = floor(($itemJourneyDuration % 3600) / 60);
                            $readable = trim(($hours > 0 ? "{$hours}h" : '') . ($minutes > 0 ? " {$minutes}m" : ''));
                        @endphp
                        <span class="badge bg-white text-dark border">{{ $readable }} estimated</span>
                    @else
                        <span class="badge bg-white text-dark border">{{ $durationDays }}
                            Day{{ $durationDays != 1 ? 's' : '' }}</span>
                    @endif
                @else
                    <span class="badge bg-white text-dark border">{{ $durationDays }}
                        Day{{ $durationDays != 1 ? 's' : '' }}</span>
                @endif
            </div>
        </div>

        <div class="row g-4">
            <div class="col-md-6 border-end">
                <div class="d-flex mb-3">
                    <div class="flex-shrink-0">
                        <i class="bi bi-geo-alt-fill text-primary"></i>
                    </div>
                    <div class="ms-3">
                        <div class="text-uppercase small text-muted font-weight-bold">
                            Pickup Location
                        </div>
                        <div>
                            {{ $pickupAddress }}
                        </div>
                        <div class="small text-muted">
                            {{ $fromDate }} at {{ $fromTime }}
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="d-flex mb-3">
                    <div class="flex-shrink-0">
                        <i class="bi bi-geo-fill text-danger"></i>
                    </div>
                    <div class="ms-3">
                        <div class="text-uppercase small text-muted font-weight-bold">
                            Return Location
                        </div>
                        <div>
                            {{ $dropoffAddress }}
                        </div>
                        <div class="small text-muted">
                            @if ($item->serviceType?->uses_dropoff_time ?? true)
                                {{ $toDate }} at {{ $toTime }}
                            @else
                                {{ $toDate }}
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pricing Information -->
        <div class="row g-4 mt-2 pt-3 border-top">
            <div class="col-md-6">
                <div class="pricing-info">
                    <div class="d-flex justify-content-between mb-2">
                        @if ($servicePricingMode !== 'day')
                            <span class="text-muted">Transfer:</span>
                        @else
                            <span class="text-muted">Rate per Day:</span>
                        @endif

                        <strong>{{ $currencySymbol }} {{ number_format($unitPrice, 2) }}</strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Quantity:</span>
                        <strong>{{ $quantity }}</strong>
                    </div>
                </div>
            </div>
            <div class="col-md-6 text-md-end">
                <div class="pricing-total">
                    <div class="text-muted small mb-1">Item Total</div>
                    <div class="fs-5 text-primary font-weight-bold">
                        {{ $currencySymbol }} {{ number_format($totalPrice, 2) }}
                    </div>
                </div>
            </div>
        </div>
    </div>
@endif
