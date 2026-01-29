@props(['item', 'index', 'currencySymbol' => 'LKR'])

@php
    // Safely decode location data
    $pickupLoc = is_string($item->pickup_location ?? null)
        ? json_decode($item->pickup_location, true)
        : $item->pickup_location ?? [];

    $dropoffLoc = is_string($item->dropoff_location ?? null)
        ? json_decode($item->dropoff_location, true)
        : $item->dropoff_location ?? [];

    // Format dates safely - handle null values
    $fromDate = $item->from_date ? \Carbon\Carbon::parse($item->from_date)->format('M d, Y') : 'N/A';
    $toDate = $item->to_date ? \Carbon\Carbon::parse($item->to_date)->format('M d, Y') : 'N/A';
    $fromTime = $item->from_time ?? '00:00';
    $toTime = $item->to_time ?? '00:00';

    // Get display values with fallbacks
    $vehicleGroupName = $item->vehicleGroup?->name ?? 'N/A';
    $serviceTypeName = $item->serviceType?->name ?? 'N/A';
    $durationDays = $item->duration_days ?? 0;
    $pickupAddress = $pickupLoc['address'] ?? 'N/A';
    $dropoffAddress = $dropoffLoc['address'] ?? 'N/A';

    // Get vehicle group images
    $vehicleGroupImages = $item->vehicleGroup?->images ?? [];
    $vehicleGroupThumbnail = $item->vehicleGroup?->thumbnail ?? null;
    $defaultImage = $vehicleGroupThumbnail
        ? (is_array($vehicleGroupThumbnail)
            ? $vehicleGroupThumbnail[0] ?? null
            : $vehicleGroupThumbnail)
        : null;
    if (!$defaultImage && !empty($vehicleGroupImages)) {
        $defaultImage = is_array($vehicleGroupImages[0])
            ? $vehicleGroupImages[0]['url'] ?? ($vehicleGroupImages[0]['path'] ?? null)
            : $vehicleGroupImages[0];
    }

    // Get addon data - check multiple sources
    $itemAddons = $item->addons ?? [];
    $addonsList = [];
    $addonsTotal = 0;

    if (is_array($itemAddons)) {
        foreach ($itemAddons as $addon) {
            $addonName = $addon['name'] ?? ($addon['label'] ?? ($addon['addon_name'] ?? 'Unknown Add-on'));
            $addonQty = max(1, (int) ($addon['quantity'] ?? ($addon['qty'] ?? 1)));
            $addonRate =
                (float) ($addon['rate'] ?? ($addon['unit_price'] ?? ($addon['price'] ?? ($addon['amount'] ?? 0))));

            // Calculate total - prefer explicit total, fallback to rate * qty
            $addonTotal =
                (float) ($addon['total_price'] ??
                    ($addon['total'] ?? ($addon['calculated_amount'] ?? ($addon['amount'] ?? $addonRate * $addonQty))));

            // Only add if we have valid data
            if ($addonName && ($addonTotal > 0 || $addonRate > 0)) {
                $addonsList[] = [
                    'name' => $addonName,
                    'qty' => $addonQty,
                    'rate' => $addonRate,
                    'total' => $addonTotal,
                ];
                $addonsTotal += $addonTotal;
            }
        }
    }

    // Check for extra kilometers in customizations or metadata
    $extraKilometers = 0;
    $extraKmRate = 0;
    $extraKmTotal = 0;

    $customizations = $item->customizations ?? [];
    $metadata = $item->metadata ?? [];

    // Look for extra kilometers in various places
    if (is_array($customizations)) {
        foreach ($customizations as $customization) {
            if (
                isset($customization['type']) &&
                in_array($customization['type'], ['extra_km', 'extra_kilometers', 'additional_km'])
            ) {
                $extraKilometers =
                    $customization['quantity'] ?? ($customization['km'] ?? ($customization['value'] ?? 0));
                $extraKmRate = $customization['rate'] ?? ($customization['price_per_km'] ?? 0);
                $extraKmTotal = $customization['total'] ?? $extraKilometers * $extraKmRate;
                break;
            }
        }
    }

    if ($extraKilometers == 0 && is_array($metadata)) {
        $extraKilometers =
            $metadata['extra_km'] ?? ($metadata['extra_kilometers'] ?? ($metadata['additional_km'] ?? 0));
        $extraKmRate = $metadata['extra_km_rate'] ?? ($metadata['km_rate'] ?? 0);
        $extraKmTotal = $metadata['extra_km_total'] ?? $extraKilometers * $extraKmRate;
    }

    // Get distance details from booking item metadata or workflow data
    $distanceDetails = [];

    // Check if distance_details exists in item metadata
    if (isset($metadata['distance_details'])) {
        $distanceDetails = $metadata['distance_details'];
    } elseif (isset($item->distance_details)) {
        $distanceDetails = is_string($item->distance_details)
            ? json_decode($item->distance_details, true)
            : $item->distance_details;
    }

    // Fallback: try to get from workflow_data cart items
    if (empty($distanceDetails) && isset($item->booking->workflow_data)) {
        $workflowData = is_string($item->booking->workflow_data)
            ? json_decode($item->booking->workflow_data, true)
            : $item->booking->workflow_data;

        $cartItems = $workflowData['cart_items'] ?? [];
        foreach ($cartItems as $cartItem) {
            if (isset($cartItem['vehicle_group_id']) && $cartItem['vehicle_group_id'] == $item->vehicle_group_id) {
                $distanceDetails = $cartItem['distance_details'] ?? [];
                break;
            }
        }
    }

    // Get pricing values with fallbacks
    $unitPrice = $item->unit_price ?? ($item->amount ?? 0);
    $totalPrice = $item->total_price ?? ($item->amount ?? 0);

    // Get service package info from metadata
    $servicePackageInfo = $item->metadata['service_package_info'] ?? null;

    // Determine service code (robust for arrays or objects), prefer explicit service_type field or relation code
    $serviceCode = null;
    if (is_object($item)) {
        $serviceCode = $item->serviceType?->code ?? ($item->service_type ?? null);
    } elseif (is_array($item)) {
        $serviceCode = $item['service_type'] ?? null;
    }
    if (empty($serviceCode)) {
        $serviceCode = strtolower(str_replace(' ', '_', $serviceTypeName ?? ''));
    }

    $journeyDurationSeconds =
        $distanceDetails['journey_duration_seconds'] ??
        ($distanceDetails['total_duration_seconds'] ??
            ($item->journey_duration_seconds ??
                ($item['journey_duration_seconds'] ??
                    null ??
                    ($item->total_duration_seconds ?? ($item['total_duration_seconds'] ?? null ?? null)))));
    $journeyDurationReadable = null;
    if ($journeyDurationSeconds && is_numeric($journeyDurationSeconds)) {
        $hours = floor($journeyDurationSeconds / 3600);
        $minutes = floor(($journeyDurationSeconds % 3600) / 60);
        $journeyDurationReadable = trim(($hours > 0 ? "{$hours}h" : '') . ($minutes > 0 ? " {$minutes}m" : ''));
    }
@endphp

<!-- Email Booking Item Card -->
<div
    style="background-color: #f8f9fa; border: 1px solid #eef0f2; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
    <div style="display: flex; align-items: flex-start; gap: 15px;">
        @php
            // Resolve image URL for email: support full URLs, local assets and S3 stored paths
            if ($defaultImage) {
                if (preg_match('/^https?:\/\//', $defaultImage)) {
                    $imageUrl = $defaultImage;
                } else {
                    // Prefer s3_asset which now smartly falls back to local assets when needed
                    $imageUrl = s3_asset($defaultImage) ?? app('url')->asset(ltrim($defaultImage, '/'));
                }
            } else {
                $imageUrl = asset('assets/img/default-vehicle.jpg');
            }
        @endphp

        @if ($imageUrl)
            <div style="flex-shrink: 0;">
                <img src="{{ $imageUrl }}" alt="{{ $vehicleGroupName }}"
                    style="width: 120px; height: 80px; object-fit: cover; border-radius: 6px; border: 1px solid #ddd;">
            </div>
        @endif
        <div style="flex: 1;">
            <h3 style="margin-top: 0; margin-bottom: 5px; color: #BF2629; font-size: 16px;">
                Vehicle {{ $index + 1 }}: {{ $vehicleGroupName }}
            </h3>
            <!-- Service Type Badge - Highlighted -->
            <span
                style="display: inline-block; background-color: #fff3cd; color: #856404; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; border: 1px solid #ffc107; margin-bottom: 8px;">
                <i style="margin-right: 4px;">🏷️</i>{{ $serviceTypeName }}
            </span>
        </div>
    </div>

    <table class="info-table" style="width: 100%; border-collapse: collapse; margin-top: 15px;">
        <tr>
            <td style="padding: 8px 0; border-bottom: 1px solid #eef0f2; font-weight: 600; color: #333; width: 30%;">
                Pickup Location
            </td>
            <td style="padding: 8px 0; border-bottom: 1px solid #eef0f2; color: #555;">
                {{ $pickupAddress }}
            </td>
        </tr>
        <tr>
            <td style="padding: 8px 0; border-bottom: 1px solid #eef0f2; font-weight: 600; color: #333; width: 30%;">
                Dropoff Location
            </td>
            <td style="padding: 8px 0; border-bottom: 1px solid #eef0f2; color: #555;">
                {{ $dropoffAddress }}
            </td>
        </tr>
        <tr>
            <td style="padding: 8px 0; border-bottom: 1px solid #eef0f2; font-weight: 600; color: #333; width: 30%;">
                Pickup Date & Time
            </td>
            <td style="padding: 8px 0; border-bottom: 1px solid #eef0f2; color: #555;">
                {{ $fromDate }} at {{ $fromTime }}
            </td>
        </tr>
        <tr>
            <td style="padding: 8px 0; border-bottom: 1px solid #eef0f2; font-weight: 600; color: #333; width: 30%;">
                Return Date & Time
            </td>
            <td style="padding: 8px 0; border-bottom: 1px solid #eef0f2; color: #555;">
                {{ $toDate }} at {{ $toTime }}
            </td>
        </tr>
        <tr>
            <td style="padding: 8px 0; border-bottom: 1px solid #eef0f2; font-weight: 600; color: #333; width: 30%;">
                Duration
            </td>
            <td style="padding: 8px 0; border-bottom: 1px solid #eef0f2; color: #555;">
                {{-- Prefer showing journey duration when available (dynamic) for time-based services; fall back to days --}}

                @if (!empty($journeyDurationReadable))
                    {{ $journeyDurationReadable }} estimated
                @else
                    {{ $durationDays }} Day{{ $durationDays != 1 ? 's' : '' }}
                @endif
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

        @php
            // Extract return trip pricing from multiple possible sources
            $oneWayPrice = $item->one_way_price ?? ($item->metadata['one_way_price'] ?? null);
            $returnPrice = $item->return_price ?? ($item->metadata['return_price'] ?? null);
            $returnDiscountPct =
                $item->return_discount_percentage ?? ($item->metadata['return_discount_percentage'] ?? 0);

            // Fallback: try workflow cart item (matching item_index or vehicle_group_id)
            $cartItem = null;
            $cartIndex = $item->metadata['item_index'] ?? null;
            if (is_null($oneWayPrice) || is_null($returnPrice)) {
                $workflow = is_string($item->booking->workflow_data ?? null)
                    ? json_decode($item->booking->workflow_data, true)
                    : $item->booking->workflow_data ?? [];
                if (!is_null($cartIndex) && isset($workflow['cart_items'][$cartIndex])) {
                    $cartItem = $workflow['cart_items'][$cartIndex];
                } else {
                    foreach ($workflow['cart_items'] ?? [] as $ci) {
                        if (isset($ci['vehicle_group_id']) && $ci['vehicle_group_id'] == $item->vehicle_group_id) {
                            $cartItem = $ci;
                            break;
                        }
                    }
                }

                if ($cartItem) {
                    $oneWayPrice = $oneWayPrice ?? ($cartItem['one_way_price'] ?? null);
                    $returnPrice = $returnPrice ?? ($cartItem['return_price'] ?? null);
                    $returnDiscountPct = $returnDiscountPct ?? ($cartItem['return_discount_percentage'] ?? 0);
                }
            }
        @endphp

        @if (!empty($oneWayPrice) || !empty($returnPrice))
            <tr>
                <td
                    style="padding: 8px 0; border-bottom: 1px solid #eef0f2; font-weight: 600; color: #333; width: 30%;">
                    Outbound Trip
                </td>
                <td style="padding: 8px 0; border-bottom: 1px solid #eef0f2; color: #555;">
                    {{ $currencySymbol }} {{ number_format((float) $oneWayPrice, 2) }}
                </td>
            </tr>
            <tr>
                <td
                    style="padding: 8px 0; border-bottom: 1px solid #eef0f2; font-weight: 600; color: #333; width: 30%;">
                    Return Trip
                </td>
                <td style="padding: 8px 0; border-bottom: 1px solid #eef0f2; color: #555;">
                    {{ $currencySymbol }} {{ number_format((float) $returnPrice, 2) }}
                    @if (!empty($returnDiscountPct) && $returnDiscountPct > 0)
                        <small class="text-success" style="margin-left:8px;">({{ $returnDiscountPct }}% off)</small>
                    @endif
                </td>
            </tr>
            <tr>
                <td style="padding: 8px 0; font-weight: 700; color: #333; width: 30%;">Combined (Item Total)</td>
                <td style="padding: 8px 0; color: #555; font-weight:700;">{{ $currencySymbol }}
                    {{ number_format((float) $totalPrice, 2) }}</td>
            </tr>
        @endif

        @if (!empty($distanceDetails))
            @php
                $allowedTotalKm = $distanceDetails['allowed_total_km'] ?? null;
                $freeKmPerDay = $distanceDetails['free_km_per_day'] ?? null;
                $freeKmPerPackage = $distanceDetails['free_km_per_package'] ?? null;
                $extraKmPrice = $distanceDetails['extra_km_price'] ?? null;
                $minimumKm = $distanceDetails['minimum_km'] ?? null;
                $minimumKmApplied = $distanceDetails['minimum_km_applied'] ?? false;
                $actualJourneyDistance =
                    $distanceDetails['actual_journey_distance'] ?? ($distanceDetails['journey_distance'] ?? null);
                $journeyDistance = $distanceDetails['journey_distance'] ?? null;
                // Additional fields
                $pickupDistance = $distanceDetails['pickup_distance'] ?? null;
                $deliveryDistance = $distanceDetails['delivery_distance'] ?? null;
                $totalDistance = $distanceDetails['total_distance'] ?? null;
                $calculationType = $distanceDetails['calculation_type'] ?? null;
                $effectiveDays = $distanceDetails['effective_days'] ?? null;
                $journeyDurationSeconds = $distanceDetails['journey_duration_seconds'] ?? null;

                // Determine extra KM from multiple sources (customizations/metadata/distanceDetails)
                if (empty($extraKilometers)) {
                    $extraKilometers = $distanceDetails['extra_km'] ?? 0;
                }

                // Calculate extra KM total if not already present
                if (empty($extraKmTotal) && $extraKilometers > 0 && $extraKmPrice) {
                    $extraKmTotal = $extraKilometers * $extraKmPrice;
                }

                // Human readable duration
                $journeyDurationReadable = null;
                if ($journeyDurationSeconds && is_numeric($journeyDurationSeconds)) {
                    $hours = floor($journeyDurationSeconds / 3600);
                    $minutes = floor(($journeyDurationSeconds % 3600) / 60);
                    $journeyDurationReadable = trim(
                        ($hours > 0 ? "{$hours}h" : '') . ($minutes > 0 ? " {$minutes}m" : ''),
                    );
                }

                // Fallbacks for display ordering
                $displayDistance = $actualJourneyDistance ?? ($journeyDistance ?? ($totalDistance ?? null));
            @endphp

            <tr>
                <td colspan="2" style="background: #f8fafc; font-weight: bold; color: #374151; padding: 12px;">
                    📏 Distance & Kilometer Information
                </td>
            </tr>

            @if ($minimumKmApplied && $minimumKm)
                <tr>
                    <td
                        style="padding: 8px 0; border-bottom: 1px solid #eef0f2; font-weight: 600; color: #92400e; background: #fef3c7;">
                        Minimum KM Charge
                    </td>
                    <td style="padding: 8px 0; border-bottom: 1px solid #eef0f2; color: #92400e; background: #fef3c7;">
                        <strong>{{ number_format($minimumKm, 0) }} km</strong>
                        <small style="color: #92400e;">(Actual distance: {{ number_format($actualJourneyDistance, 1) }}
                            km)</small>
                    </td>
                </tr>
            @elseif($displayDistance)
                <tr>
                    <td style="padding: 8px 0; border-bottom: 1px solid #eef0f2; font-weight: 600; color: #333;">
                        Estimated Distance (charged)
                    </td>
                    <td style="padding: 8px 0; border-bottom: 1px solid #eef0f2; color: #555;">
                        <strong>{{ number_format($displayDistance, 1) }} km</strong>
                        @if ($journeyDurationReadable)
                            <small style="display:block;color:#777; margin-top:4px;">Duration:
                                {{ $journeyDurationReadable }}</small>
                        @endif
                    </td>
                </tr>
            @endif

            @if ($pickupDistance || $deliveryDistance)
                <tr>
                    <td style="padding: 8px 0; border-bottom: 1px solid #eef0f2; font-weight: 600; color: #333;">Pickup
                        / Delivery KM</td>
                    <td style="padding: 8px 0; border-bottom: 1px solid #eef0f2; color: #555;">
                        @if ($pickupDistance)
                            <div>Pickup distance: <strong>{{ number_format($pickupDistance, 1) }} km</strong></div>
                        @endif
                        @if ($deliveryDistance)
                            <div>Delivery distance: <strong>{{ number_format($deliveryDistance, 1) }} km</strong></div>
                        @endif
                        @if ($totalDistance)
                            <div>Total billable distance: <strong>{{ number_format($totalDistance, 1) }} km</strong>
                            </div>
                        @endif
                    </td>
                </tr>
            @endif

            @if ($effectiveDays || $calculationType)
                <tr>
                    <td>
                        @if ($calculationType)
                            <div><strong>Type:</strong> {{ ucfirst($calculationType) }}</div>
                        @endif
                        @if ($effectiveDays)
                            <div><strong>Effective days:</strong> {{ $effectiveDays }}</div>
                        @endif
                    </td>
                </tr>
            @endif

            @if ($freeKmPerDay && $durationDays > 1)
                <tr>
                    <td>Free KM per Day</td>
                    <td><strong>{{ number_format($freeKmPerDay, 0) }} km</strong>
                    </td>
                </tr>
                @if ($allowedTotalKm)
                    <tr>
                        <td>Total Allowed KM</td>
                        <td><strong>{{ number_format($allowedTotalKm, 0) }}
                                km</strong> <small>({{ $durationDays }}
                                days)</small></td>
                    </tr>
                @endif
            @elseif($freeKmPerPackage)
                <tr>
                    <td>Included KM</td>
                    <td><strong>{{ number_format($freeKmPerPackage, 0) }}
                            km</strong> <small>(per package)</small></td>
                </tr>
            @elseif($allowedTotalKm)
                <tr>
                    <td>Included KM</td>
                    <td><strong>{{ number_format($allowedTotalKm, 0) }} km</strong>
                    </td>
                </tr>
            @endif

            @if ($extraKilometers > 0)
                <tr>
                    <td style="padding: 8px 0; border-bottom: 1px solid #eef0f2; font-weight: 600; color: #333;">
                        Extra Kilometers
                    </td>
                    <td style="padding: 8px 0; border-bottom: 1px solid #eef0f2; color: #555;">
                        {{ number_format($extraKilometers) }} km
                        @if ($extraKmPrice > 0)
                            @ {{ $currencySymbol }}{{ number_format($extraKmPrice, 2) }}/km
                        @endif
                        @if ($extraKmTotal > 0)
                            <span
                                style="float: right; color: #BF2629;">{{ $currencySymbol }}{{ number_format($extraKmTotal, 2) }}</span>
                        @endif
                    </td>
                </tr>
            @endif

            @if ($extraKmPrice && empty($extraKilometers) && isset($distanceDetails['extra_km']) && $distanceDetails['extra_km'] > 0)
                <tr>
                    <td>Extra KM Rate</td>
                    <td><strong>{{ $currencySymbol }}{{ number_format($extraKmPrice, 2) }}</strong>
                        per km</td>
                </tr>
            @elseif ($extraKmPrice)
                <tr>
                    <td>Extra KM Rate</td>
                    <td><strong>{{ $currencySymbol }}{{ number_format($extraKmPrice, 2) }}</strong>
                        per km</td>
                </tr>
            @endif
        @endif

        @if (!empty($addonsList))
            <tr>
                <td
                    style="padding: 8px 0; border-bottom: 1px solid #eef0f2; font-weight: 600; color: #333; vertical-align: top;">
                    Selected Add-ons
                </td>
                <td style="padding: 8px 0; border-bottom: 1px solid #eef0f2; color: #555;">
                    @foreach ($addonsList as $addon)
                        <div
                            style="margin-bottom: 4px; display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <strong>{{ $addon['name'] }}</strong>
                                <small style="color: #777; margin-left: 4px;">
                                    (Qty: {{ $addon['qty'] }}@if ($addon['rate'] > 0)
                                        × {{ $currencySymbol }}{{ number_format($addon['rate'], 2) }}
                                    @elseif ($addon['total'] > 0 && $addon['qty'] > 0)
                                        - Avg:
                                        {{ $currencySymbol }}{{ number_format($addon['total'] / $addon['qty'], 2) }}
                                    @endif)
                                </small>
                            </div>
                            <span
                                style="color: #BF2629; font-weight: 600;">{{ $currencySymbol }}{{ number_format($addon['total'], 2) }}</span>
                        </div>
                    @endforeach
                </td>
            </tr>
        @endif

        @if ($extraKilometers > 0)
            <tr>
                <td style="padding: 8px 0; border-bottom: 1px solid #eef0f2; font-weight: 600; color: #333;">
                    Extra Kilometers
                </td>
                <td style="padding: 8px 0; border-bottom: 1px solid #eef0f2; color: #555;">
                    {{ number_format($extraKilometers) }} km
                    @if ($extraKmRate > 0)
                        @ {{ $currencySymbol }}{{ number_format($extraKmRate, 2) }}/km
                    @endif
                    @if ($extraKmTotal > 0)
                        <span
                            style="float: right; color: #BF2629;">{{ $currencySymbol }}{{ number_format($extraKmTotal, 2) }}</span>
                    @endif
                </td>
            </tr>
        @endif

        <tr>
            <td style="padding: 8px 0; font-weight: 600; color: #333; width: 30%;">
                Item Total
            </td>
            <td style="padding: 8px 0; color: #555; font-weight: 600;">
                {{ $currencySymbol }} {{ number_format($totalPrice, 2) }}
            </td>
        </tr>
    </table>

    @php
        // Try to locate extra km info from the parent booking workflow cart (if available)
        $workflow = is_string($item->booking->workflow_data ?? null)
            ? json_decode($item->booking->workflow_data, true)
            : $item->booking->workflow_data ?? [];
        $cartIndex = $item->metadata['item_index'] ?? null;
        $bookingExtra = null;
        if (!is_null($cartIndex) && isset($workflow['cart_items'][$cartIndex])) {
            $bookingExtra = $workflow['cart_items'][$cartIndex]['extra_km'] ?? null;
        }
        // Also support legacy/addon-format extra km
        if (empty($bookingExtra) && !empty($item->addons) && is_array($item->addons)) {
            foreach ($item->addons as $ad) {
                if (
                    (isset($ad['is_milage']) && $ad['is_milage']) ||
                    (isset($ad['code']) && $ad['code'] === 'extra_km')
                ) {
                    $bookingExtra = $ad;
                    break;
                }
            }
        }
    @endphp

    @if ((!empty($item->addons) && is_array($item->addons)) || !empty($bookingExtra))
        <div style="margin-top:12px;">
            @if (!empty($item->addons) && is_array($item->addons))
                <h4 style="margin:8px 0 6px 0; font-size:14px;">Selected Addons</h4>
                <table class="info-table" style="width:100%; border-collapse:collapse;">
                    @foreach ($item->addons as $addonKey => $addon)
                        @php
                            // Normalize addon data structures
                            $addonName =
                                $addon['name'] ?? ($addon['label'] ?? (is_string($addonKey) ? $addonKey : 'Addon'));
                            $addonQty = $addon['qty'] ?? ($addon['quantity'] ?? 1);
                            $addonRate = $addon['amount'] ?? ($addon['rate'] ?? 0);
                            $addonTotal = $addon['calculated_amount'] ?? ($addon['total'] ?? $addonRate * $addonQty);
                        @endphp
                        <tr>
                            <td style="padding:6px 0; border-bottom:1px solid #eef0f2;">{{ $addonName }}
                                @if ($addonQty > 1)
                                    <small style="color:#777;">×{{ $addonQty }}</small>
                                @endif
                            </td>
                            <td style="padding:6px 0; border-bottom:1px solid #eef0f2; text-align:right;">
                                {{ $currencySymbol }} {{ number_format($addonTotal, 2) }}</td>
                        </tr>
                    @endforeach
                </table>
            @endif

            @if (!empty($bookingExtra))
                <h4 style="margin:8px 0 6px 0; font-size:14px;">Extra KM</h4>
                <div style="color:#555;">
                    {{ $bookingExtra['km'] ?? ($bookingExtra['quantity'] ?? 0) }} km • {{ $currencySymbol }}
                    {{ number_format($bookingExtra['total_cost'] ?? ($bookingExtra['calculated_amount'] ?? 0), 2) }}
                </div>
            @endif
        </div>
    @endif
</div>
