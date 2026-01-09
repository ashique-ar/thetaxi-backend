@props([
    'item',
    'index',
    'currencySymbol' => 'LKR',
    'variant' => 'default', // 'default' or 'compact'
])

@php
    // Safely decode location data
    $pickupLoc = is_string($item->pickup_location ?? null)
        ? json_decode($item->pickup_location, true)
        : $item->pickup_location ?? [];

    $dropoffLoc = is_string($item->dropoff_location ?? null)
        ? json_decode($item->dropoff_location, true)
        : $item->dropoff_location ?? [];

    // Format dates safely
    $fromDate = \Carbon\Carbon::parse($item->from_date)->format('M d, Y');
    $toDate = \Carbon\Carbon::parse($item->to_date)->format('M d, Y');
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
                <div class="small text-muted">{{ $serviceTypeName }}</div>
            </div>
            <div class="col-md-5 text-md-end">
                <span class="badge bg-white text-dark border">
                    {{ $durationDays }} Days
                </span>
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
                <p class="text-muted small mb-0">Service: {{ $serviceTypeName }}</p>
            </div>
            <div class="col-md-4 text-md-end">
                <span class="badge bg-light text-dark border">
                    {{ $durationDays }} Days
                </span>
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
                            Pickup
                        </div>
                        <div>
                            {{ $fromDate }} at {{ $fromTime }}
                        </div>
                        <div class="small">
                            {{ $pickupAddress }}
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
                            Return
                        </div>
                        <div>
                            {{ $toDate }} at {{ $toTime }}
                        </div>
                        <div class="small">
                            {{ $dropoffAddress }}
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
                        <span class="text-muted">Rate per Day:</span>
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
