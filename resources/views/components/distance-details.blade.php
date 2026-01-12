@props(['distanceDetails' => [], 'durationDays' => 1, 'currencySymbol' => 'LKR', 'compact' => false])

@php
    $allowedTotalKm = $distanceDetails['allowed_total_km'] ?? null;
    $freeKmPerDay = $distanceDetails['free_km_per_day'] ?? null;
    $freeKmPerPackage = $distanceDetails['free_km_per_package'] ?? null;
    $extraKmPrice = $distanceDetails['extra_km_price'] ?? null;
    $journeyDistance = $distanceDetails['journey_distance'] ?? null;
    $extraKm = $distanceDetails['extra_km'] ?? null;

    // Don't display if no relevant data
    $hasData = $allowedTotalKm || $freeKmPerDay || $freeKmPerPackage || $extraKmPrice || $journeyDistance || $extraKm;

    if (!$hasData) {
        return;
    }
@endphp

<div class="distance-details-section"
    style="background: #f8fafc; border-radius: 6px; padding: {{ $compact ? '8px' : '12px' }}; margin: {{ $compact ? '8px 0' : '15px 0' }}; border-left: 4px solid #3b82f6;">
    <h5
        style="font-size: {{ $compact ? '12px' : '13px' }}; color: #374151; margin-bottom: {{ $compact ? '6px' : '8px' }}; font-weight: 600;">
        📏 Kilometer Information</h5>

    @if ($freeKmPerDay && $durationDays > 1)
        <div style="display: flex; justify-content: space-between; margin-bottom: {{ $compact ? '3px' : '5px' }};">
            <small style="color: #6b7280; font-size: {{ $compact ? '10px' : '12px' }};">Free KM per Day:</small>
            <strong style="font-size: {{ $compact ? '10px' : '12px' }};">{{ number_format($freeKmPerDay, 0) }}
                km</strong>
        </div>
        @if ($allowedTotalKm)
            <div style="display: flex; justify-content: space-between; margin-bottom: {{ $compact ? '3px' : '5px' }};">
                <small style="color: #6b7280; font-size: {{ $compact ? '10px' : '12px' }};">Total Allowed:</small>
                <strong style="font-size: {{ $compact ? '10px' : '12px' }};">{{ number_format($allowedTotalKm, 0) }}
                    km</strong>
            </div>
        @endif
    @elseif($freeKmPerPackage)
        <div style="display: flex; justify-content: space-between; margin-bottom: {{ $compact ? '3px' : '5px' }};">
            <small style="color: #6b7280; font-size: {{ $compact ? '10px' : '12px' }};">Included KM:</small>
            <strong style="font-size: {{ $compact ? '10px' : '12px' }};">{{ number_format($freeKmPerPackage, 0) }}
                km</strong>
        </div>
    @elseif($allowedTotalKm)
        <div style="display: flex; justify-content: space-between; margin-bottom: {{ $compact ? '3px' : '5px' }};">
            <small style="color: #6b7280; font-size: {{ $compact ? '10px' : '12px' }};">Included KM:</small>
            <strong style="font-size: {{ $compact ? '10px' : '12px' }};">{{ number_format($allowedTotalKm, 0) }}
                km</strong>
        </div>
    @endif

    @if ($extraKmPrice)
        <div style="display: flex; justify-content: space-between; margin-bottom: {{ $compact ? '3px' : '5px' }};">
            <small style="color: #6b7280; font-size: {{ $compact ? '10px' : '12px' }};">Extra KM Rate:</small>
            <strong
                style="font-size: {{ $compact ? '10px' : '12px' }}; color: #dc2626;">{{ $currencySymbol }}{{ number_format($extraKmPrice, 2) }}/km</strong>
        </div>
    @endif

    @if ($journeyDistance)
        <div style="display: flex; justify-content: space-between; margin-bottom: {{ $compact ? '3px' : '5px' }};">
            <small style="color: #6b7280; font-size: {{ $compact ? '10px' : '12px' }};">Estimated Distance:</small>
            <span style="font-size: {{ $compact ? '10px' : '12px' }};">{{ number_format($journeyDistance, 1) }}
                km</span>
        </div>
    @endif

    @if ($extraKm > 0)
        <div style="display: flex; justify-content: space-between;">
            <small style="color: #dc2626; font-size: {{ $compact ? '10px' : '12px' }};">Extra KM:</small>
            <strong
                style="font-size: {{ $compact ? '10px' : '12px' }}; color: #dc2626;">{{ number_format($extraKm, 1) }}
                km</strong>
        </div>
    @endif
</div>
