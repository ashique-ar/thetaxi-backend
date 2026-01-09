@props([
    'item',
    'index',
    'currencySymbol' => 'LKR',
])

@php
    // Safely decode location data
    $pickupLoc = is_string($item->pickup_location ?? null)
        ? json_decode($item->pickup_location, true)
        : ($item->pickup_location ?? []);
    
    $dropoffLoc = is_string($item->dropoff_location ?? null)
        ? json_decode($item->dropoff_location, true)
        : ($item->dropoff_location ?? []);
    
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
    $unitPrice = $item->unit_price ?? $item->amount ?? 0;
    $totalPrice = $item->total_price ?? $item->amount ?? 0;
@endphp

<!-- Email Booking Item Card -->
<div style="background-color: #f8f9fa; border: 1px solid #eef0f2; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
    <h3 style="margin-top: 0; color: #BF2629; font-size: 16px;">
        Vehicle {{ $index + 1 }}: {{ $vehicleGroupName }}
    </h3>
    <table class="info-table" style="width: 100%; border-collapse: collapse;">
        <tr>
            <td style="padding: 8px 0; border-bottom: 1px solid #eef0f2; font-weight: 600; color: #333; width: 30%;">
                Service Type
            </td>
            <td style="padding: 8px 0; border-bottom: 1px solid #eef0f2; color: #555;">
                {{ $serviceTypeName }}
            </td>
        </tr>
        <tr>
            <td style="padding: 8px 0; border-bottom: 1px solid #eef0f2; font-weight: 600; color: #333; width: 30%;">
                Pickup
            </td>
            <td style="padding: 8px 0; border-bottom: 1px solid #eef0f2; color: #555;">
                {{ $fromDate }} at {{ $fromTime }}<br>
                <small style="color: #717171;">{{ $pickupAddress }}</small>
            </td>
        </tr>
        <tr>
            <td style="padding: 8px 0; border-bottom: 1px solid #eef0f2; font-weight: 600; color: #333; width: 30%;">
                Return
            </td>
            <td style="padding: 8px 0; border-bottom: 1px solid #eef0f2; color: #555;">
                {{ $toDate }} at {{ $toTime }}<br>
                <small style="color: #717171;">{{ $dropoffAddress }}</small>
            </td>
        </tr>
        <tr>
            <td style="padding: 8px 0; border-bottom: 1px solid #eef0f2; font-weight: 600; color: #333; width: 30%;">
                Duration
            </td>
            <td style="padding: 8px 0; border-bottom: 1px solid #eef0f2; color: #555;">
                {{ $durationDays }} Days
            </td>
        </tr>
        <tr>
            <td style="padding: 8px 0; border-bottom: 1px solid #eef0f2; font-weight: 600; color: #333; width: 30%;">
                Rate per Day
            </td>
            <td style="padding: 8px 0; border-bottom: 1px solid #eef0f2; color: #555;">
                {{ $currencySymbol }} {{ number_format($unitPrice, 2) }}
            </td>
        </tr>
        <tr>
            <td style="padding: 8px 0; font-weight: 600; color: #333; width: 30%;">
                Item Total
            </td>
            <td style="padding: 8px 0; color: #555; font-weight: 600;">
                {{ $currencySymbol }} {{ number_format($totalPrice, 2) }}
            </td>
        </tr>
    </table>
</div>
