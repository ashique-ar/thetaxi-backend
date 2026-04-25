@props(['item', 'index', 'currencySymbol' => 'LKR'])

@php
    $pickupLoc = is_string($item->pickup_location ?? null)
        ? json_decode($item->pickup_location, true)
        : $item->pickup_location ?? [];

    $dropoffLoc = is_string($item->dropoff_location ?? null)
        ? json_decode($item->dropoff_location, true)
        : $item->dropoff_location ?? [];

    $fromDate = $item->from_date ? \Carbon\Carbon::parse($item->from_date)->format('M d, Y') : 'N/A';
    $toDate = $item->to_date ? \Carbon\Carbon::parse($item->to_date)->format('M d, Y') : 'N/A';
    $fromTime = $item->from_time ?? '00:00';
    $toTime = $item->to_time ?? '00:00';
    $pickupDateTime = 'N/A';
    if ($item->from_date) {
        $pickupBase = \Carbon\Carbon::parse($item->from_date);
        if (!empty($fromTime) && preg_match('/^\d{2}:\d{2}(:\d{2})?$/', (string) $fromTime)) {
            [$pickupHour, $pickupMinute, $pickupSecond] = array_pad(explode(':', (string) $fromTime), 3, '00');
            $pickupBase->setTime((int) $pickupHour, (int) $pickupMinute, (int) $pickupSecond);
        }
        $pickupDateTime = $pickupBase->format('M d, Y h:i A');
    }

    $formatDateTime = function ($date, $time = null) {
        if (empty($date)) {
            return 'N/A';
        }

        $dateTime = \Carbon\Carbon::parse($date);
        if (!empty($time) && preg_match('/^\d{2}:\d{2}(:\d{2})?$/', (string) $time)) {
            [$hour, $minute, $second] = array_pad(explode(':', (string) $time), 3, '00');
            $dateTime->setTime((int) $hour, (int) $minute, (int) $second);
        }

        return $dateTime->format('M d, Y h:i A');
    };

    $returnDateTime = $formatDateTime($item->to_date, $toTime);

    $vehicleGroupName = $item->vehicleGroup?->name ?? 'N/A';
    $serviceTypeName = $item->serviceType?->name ?? 'N/A';
    $servicePricingMode = $item->serviceType?->pricing_mode ?? 'transfer';
    $durationDays = $item->duration_days ?? 0;
    $pickupAddress = $pickupLoc['address'] ?? 'N/A';
    $dropoffAddress = $dropoffLoc['address'] ?? 'N/A';

    $vehicleThumbnail = $item->vehicleGroup?->thumbnail ?? null;
    if (isset($vehicleThumbnail)) {
        $thumbRaw = $vehicleThumbnail;
        if (is_array($thumbRaw)) {
            $thumb = $thumbRaw['path'] ?? ($thumbRaw[0] ?? null);
        } else {
            $thumb = $thumbRaw;
        }
    }
    $defaultImage = $thumb ? s3_asset($thumb) : asset('assets/img/default-vehicle.jpg');

    $itemAddons = $item->addons ?? [];
    $addonsList = [];

    if (is_array($itemAddons)) {
        foreach ($itemAddons as $addon) {
            $addonName = $addon['name'] ?? ($addon['label'] ?? ($addon['addon_name'] ?? 'Unknown Add-on'));
            $addonQty = max(1, (int) ($addon['quantity'] ?? ($addon['qty'] ?? 1)));
            $addonRate =
                (float) ($addon['rate'] ?? ($addon['unit_price'] ?? ($addon['price'] ?? ($addon['amount'] ?? 0))));
            $addonTotal =
                (float) ($addon['total_price'] ??
                    ($addon['total'] ?? ($addon['calculated_amount'] ?? ($addon['amount'] ?? $addonRate * $addonQty))));

            if ($addonName && ($addonTotal > 0 || $addonRate > 0)) {
                $addonsList[] = [
                    'name' => $addonName,
                    'qty' => $addonQty,
                    'rate' => $addonRate,
                    'total' => $addonTotal,
                ];
            }
        }
    }

    $extraKilometers = 0;
    $extraKmRate = 0;
    $extraKmTotal = 0;

    $customizations = $item->customizations ?? [];
    $metadata = $item->metadata ?? [];

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

    $distanceDetails = [];

    if (isset($metadata['distance_details'])) {
        $distanceDetails = $metadata['distance_details'];
    } elseif (isset($item->distance_details)) {
        $distanceDetails = is_string($item->distance_details)
            ? json_decode($item->distance_details, true)
            : $item->distance_details;
    }

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

    $unitPrice = $item->unit_price ?? ($item->amount ?? 0);
    $totalPrice = $item->total_price ?? ($item->amount ?? 0);

    $serviceCode = null;
    if (is_object($item)) {
        $serviceCode = $item->serviceType?->code ?? ($item->service_type ?? null);
    } elseif (is_array($item)) {
        $serviceCode = $item['service_type'] ?? null;
    }
    if (empty($serviceCode)) {
        $serviceCode = strtolower(str_replace(' ', '_', $serviceTypeName ?? ''));
    }

    $transferType = $item->metadata['transfer_type'] ?? ($item['transfer_type'] ?? null);
    if ($transferType === 'from-airport') {
        $serviceTypeName .= ' (From Airport)';
    } elseif ($transferType === 'to-airport') {
        $serviceTypeName .= ' (To Airport)';
    }

    $journeyDurationSeconds =
        $distanceDetails['journey_duration_seconds'] ??
        ($distanceDetails['total_duration_seconds'] ??
            ($item->journey_duration_seconds ??
                ($item['journey_duration_seconds'] ??
                    ($item->total_duration_seconds ?? ($item['total_duration_seconds'] ?? null)))));

    $journeyDurationReadable = null;
    if ($journeyDurationSeconds && is_numeric($journeyDurationSeconds)) {
        $hours = floor($journeyDurationSeconds / 3600);
        $minutes = floor(($journeyDurationSeconds % 3600) / 60);
        $hoursStr = $hours > 0 ? "{$hours} " . Str::plural('Hour', $hours) : '';
        $minutesStr = $minutes > 0 ? "{$minutes} " . Str::plural('Minute', $minutes) : '';
        $journeyDurationReadable = trim($hoursStr . ($minutes > 0 ? " {$minutesStr}" : ''));
    }

    $displayDistance =
        $distanceDetails['actual_journey_distance'] ?? ($distanceDetails['journey_distance'] ?? ($distanceDetails['total_distance'] ?? null));

    $returnPickup = $item->metadata['return_pickup_location'] ?? ($item['return_pickup_location'] ?? null);
    $returnDropoff = $item->metadata['return_dropoff_location'] ?? ($item['return_dropoff_location'] ?? null);
    $returnTripDate = $item->metadata['return_trip_date'] ?? ($item['return_trip_date'] ?? null);
    $returnTripTime = $item->metadata['return_trip_time'] ?? ($item['return_trip_time'] ?? null);
    $isReturnTrip = $item->metadata['is_return_trip'] ?? ($item['is_return_trip'] ?? false);

    $oneWayPrice = $item->one_way_price ?? ($item->metadata['one_way_price'] ?? null);
    $returnPrice = $item->return_price ?? ($item->metadata['return_price'] ?? null);
    $returnDiscountPct =
        $item->return_discount_percentage ?? ($item->metadata['return_discount_percentage'] ?? 0);

    $cartItem = null;
    $cartIndex = $item->metadata['item_index'] ?? null;
    $workflow = is_string($item->booking->workflow_data ?? null)
        ? json_decode($item->booking->workflow_data, true)
        : $item->booking->workflow_data ?? [];
    $workflow = is_array($workflow) ? $workflow : [];
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
        $isReturnTrip =
            filter_var($isReturnTrip, FILTER_VALIDATE_BOOLEAN) ||
            filter_var($cartItem['is_return_trip'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $oneWayPrice = $oneWayPrice ?? ($cartItem['one_way_price'] ?? null);
        $returnPrice = $returnPrice ?? ($cartItem['return_price'] ?? null);
        $returnDiscountPct = $returnDiscountPct ?: ($cartItem['return_discount_percentage'] ?? 0);
        $returnPickup = $returnPickup ?? ($cartItem['return_pickup_location'] ?? null);
        $returnDropoff = $returnDropoff ?? ($cartItem['return_dropoff_location'] ?? null);
        $returnTripDate = $returnTripDate ?? ($cartItem['return_trip_date'] ?? null);
        $returnTripTime = $returnTripTime ?? ($cartItem['return_trip_time'] ?? null);
    }

    $isReturnTrip = filter_var($isReturnTrip, FILTER_VALIDATE_BOOLEAN);
    if ($isReturnTrip) {
        $returnDateTime = $formatDateTime($returnTripDate ?: $item->to_date, $returnTripTime ?: $toTime);
    }

    $allowedTotalKm = $distanceDetails['allowed_total_km'] ?? null;
    $freeKmPerDay = $distanceDetails['free_km_per_day'] ?? null;
    $freeKmPerPackage = $distanceDetails['free_km_per_package'] ?? null;
    $extraKmPrice = $distanceDetails['extra_km_price'] ?? null;
    $minimumKm = $distanceDetails['minimum_km'] ?? null;
    $minimumKmApplied = $distanceDetails['minimum_km_applied'] ?? false;
    $actualJourneyDistance =
        $distanceDetails['actual_journey_distance'] ?? ($distanceDetails['journey_distance'] ?? null);
    $pickupDistance = $distanceDetails['pickup_distance'] ?? null;
    $deliveryDistance = $distanceDetails['delivery_distance'] ?? null;
    $totalDistance = $distanceDetails['total_distance'] ?? null;

    $outboundKm =
        $distanceDetails['outbound_distance_km'] ?? ($item->outbound_distance_km ?? ($item['outbound_distance_km'] ?? null));
    $returnKm =
        $distanceDetails['return_distance_km'] ?? ($item->return_distance_km ?? ($item['return_distance_km'] ?? null));
    $hasReturnKmData = $isReturnTrip && $outboundKm && $returnKm;

    if (empty($extraKilometers)) {
        $extraKilometers = $distanceDetails['extra_km'] ?? 0;
    }
    if (empty($extraKmTotal) && $extraKilometers > 0 && $extraKmPrice) {
        $extraKmTotal = $extraKilometers * $extraKmPrice;
    }

    $isDayPackage = $servicePricingMode === 'day';
    $showReturnLocations = !$isDayPackage && ($isReturnTrip || !empty($returnPickup) || !empty($returnDropoff));

    if ($showReturnLocations && empty($returnPickup)) {
        $returnPickup = $dropoffLoc;
    }
    if ($showReturnLocations && empty($returnDropoff)) {
        $returnDropoff = $pickupLoc;
    }

    $returnPickupAddress = is_array($returnPickup)
        ? $returnPickup['address'] ?? 'Same as Dropoff'
        : $returnPickup ?? 'Same as Dropoff';
    $returnDropoffAddress = is_array($returnDropoff)
        ? $returnDropoff['address'] ?? 'Same as Pickup'
        : $returnDropoff ?? 'Same as Pickup';

    $packageKmLabel = null;
    if ($isDayPackage) {
        $packageKmValue = $freeKmPerPackage ?? $allowedTotalKm ?? $freeKmPerDay;
        if (!empty($packageKmValue)) {
            $packageKmLabel = number_format((float) $packageKmValue, 0) . ' km package';
        }
    }

    $tripTypeLabel = $isReturnTrip ? 'With return' : 'Drop-off only';

    $routeSummaryLines = [];
    if (!$isDayPackage && $hasReturnKmData) {
        $routeSummaryLines[] =
            'Estimated Distance: ' .
            number_format((float) $outboundKm, 1) .
            ' km drop-off + ' .
            number_format((float) $returnKm, 1) .
            ' km return = ' .
            number_format((float) $outboundKm + (float) $returnKm, 1) .
            ' km';
    } elseif (!$isDayPackage && $displayDistance) {
        $routeSummaryLines[] = 'Estimated Distance: ' . number_format((float) $displayDistance, 1) . ' km';
    }

    if (!$isDayPackage && $journeyDurationReadable) {
        $routeSummaryLines[] = 'Duration: ' . $journeyDurationReadable;
    } elseif (!$isDayPackage && !empty($journeyDurationSeconds) && is_numeric($journeyDurationSeconds)) {
        $routeSummaryLines[] = 'Duration: ' . floor($journeyDurationSeconds / 60) . ' Minutes';
    }

    $totalLabel = 'Item Total';
    if ($servicePricingMode !== 'day') {
        $totalLabel = $isReturnTrip ? 'Transfer Total (Drop-off + Return)' : 'Transfer Total (Drop-off)';
    }
@endphp

<div
    style="background-color: #f8f9fa; border: 1px solid #eef0f2; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
    <div style="display: flex; align-items: flex-start; gap: 15px;">
        @php
            if ($defaultImage) {
                if (preg_match('/^https?:\/\//', $defaultImage)) {
                    $imageUrl = $defaultImage;
                } else {
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
            <span
                style="display: inline-block; background-color: #fff3cd; color: #856404; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; border: 1px solid #ffc107; margin-bottom: 8px;">
                <i style="margin-right: 4px;">Service:</i>{{ $serviceTypeName }}
            </span>
        </div>
    </div>

    <table class="info-table" style="width: 100%; border-collapse: collapse; margin-top: 15px;">
        <tr>
            <td style="padding: 4px 0; border-bottom: 1px solid #eef0f2; font-weight: 600; color: #333; width: 30%;">
                Pickup Location
            </td>
            <td style="padding: 4px 0; border-bottom: 1px solid #eef0f2; color: #555;">
                {!! str_replace(
                    '(Airport)',
                    '<strong style="color: #BF2629;">(Airport)</strong>',
                    htmlspecialchars($pickupAddress),
                ) !!}
                <div style="margin-top: 6px; color: #777; font-size: 12px; line-height: 1.5;">
                    Pickup Date & Time: <strong>{{ $pickupDateTime }}</strong>
                </div>
            </td>
        </tr>
        @if (!$isDayPackage)
            <tr>
                <td style="padding: 4px 0; border-bottom: 1px solid #eef0f2; font-weight: 600; color: #333; width: 30%;">
                    Trip Type
                </td>
                <td style="padding: 4px 0; border-bottom: 1px solid #eef0f2; color: #555;">
                    {{ $tripTypeLabel }}
                </td>
            </tr>
        @endif
        @if (!$isDayPackage)
            <tr>
                <td style="padding: 4px 0; border-bottom: 1px solid #eef0f2; font-weight: 600; color: #333; width: 30%;">
                    Dropoff Location
                </td>
                <td style="padding: 4px 0; border-bottom: 1px solid #eef0f2; color: #555;">
                    {!! str_replace(
                        '(Airport)',
                        '<strong style="color: #BF2629;">(Airport)</strong>',
                        htmlspecialchars($dropoffAddress),
                    ) !!}

                    @if (!empty($routeSummaryLines))
                        <div style="margin-top: 6px; color: #777; font-size: 12px; line-height: 1.5;">
                            @foreach ($routeSummaryLines as $line)
                                <div>{{ $line }}</div>
                            @endforeach
                        </div>
                    @endif
                </td>
            </tr>
        @endif
        @if ($isReturnTrip)
            <tr>
                <td
                    style="padding: 4px 0; border-bottom: 1px solid #eef0f2; font-weight: 600; color: #333; width: 30%;">
                    Return Date & Time
                </td>
                <td style="padding: 4px 0; border-bottom: 1px solid #eef0f2; color: #555;">
                    {{ $returnDateTime }}
                </td>
            </tr>
        @endif
        @if ($servicePricingMode === 'day')
            <tr>
                <td style="padding: 4px 0; border-bottom: 1px solid #eef0f2; font-weight: 600; color: #333; width: 30%;">
                    Rate per Day
                </td>
                <td style="padding: 4px 0; border-bottom: 1px solid #eef0f2; color: #555;">
                    {{ $currencySymbol }} {{ number_format($unitPrice, 2) }}
                </td>
            </tr>
        @endif

        @if ($showReturnLocations)
            <tr>
                <td
                    style="padding: 4px 0; border-bottom: 1px solid #eef0f2; font-weight: 600; color: #333; width: 30%;">
                    Return Pickup
                </td>
                <td style="padding: 4px 0; border-bottom: 1px solid #eef0f2; color: #555;">
                    {!! str_replace(
                        '(Airport)',
                        '<strong style="color: #BF2629;">(Airport)</strong>',
                        htmlspecialchars($returnPickupAddress),
                    ) !!}
                </td>
            </tr>
            <tr>
                <td
                    style="padding: 4px 0; border-bottom: 1px solid #eef0f2; font-weight: 600; color: #333; width: 30%;">
                    Return Dropoff
                </td>
                <td style="padding: 4px 0; border-bottom: 1px solid #eef0f2; color: #555;">
                    {!! str_replace(
                        '(Airport)',
                        '<strong style="color: #BF2629;">(Airport)</strong>',
                        htmlspecialchars($returnDropoffAddress),
                    ) !!}
                </td>
            </tr>
        @endif

        @if (!$isDayPackage && $minimumKmApplied && $minimumKm)
            <tr>
                <td
                    style="padding: 4px 0; border-bottom: 1px solid #eef0f2; font-weight: 600; color: #92400e; background: #fef3c7;">
                    Minimum KM Charge
                </td>
                <td style="padding: 4px 0; border-bottom: 1px solid #eef0f2; color: #92400e; background: #fef3c7;">
                    <strong>{{ number_format($minimumKm, 0) }} km</strong>
                    <small style="color: #92400e;">(Actual distance: {{ number_format($actualJourneyDistance, 1) }} km)</small>
                </td>
            </tr>
        @endif

        @if ($pickupDistance || $deliveryDistance)
            <tr>
                <td style="padding: 4px 0; border-bottom: 1px solid #eef0f2; font-weight: 600; color: #333;">Pickup /
                    Delivery KM</td>
                <td style="padding: 4px 0; border-bottom: 1px solid #eef0f2; color: #555;">
                    @if ($pickupDistance)
                        <div>Pickup distance: <strong>{{ number_format($pickupDistance, 1) }} km</strong></div>
                    @endif
                    @if ($deliveryDistance)
                        <div>Delivery distance: <strong>{{ number_format($deliveryDistance, 1) }} km</strong></div>
                    @endif
                    @if ($totalDistance)
                        <div>Total billable distance: <strong>{{ number_format($totalDistance, 1) }} km</strong></div>
                    @endif
                </td>
            </tr>
        @endif

        @if ($freeKmPerDay && $durationDays > 1)
            <tr>
                <td>Free KM per Day</td>
                <td><strong>{{ number_format($freeKmPerDay, 0) }} km</strong></td>
            </tr>
            @if ($allowedTotalKm)
                <tr>
                    <td>Total Allowed KM</td>
                    <td><strong>{{ number_format($allowedTotalKm, 0) }} km</strong> <small>({{ $durationDays }} days)</small></td>
                </tr>
            @endif
        @elseif($freeKmPerPackage)
            <tr>
                <td>{{ $isDayPackage ? 'Selected Package' : 'Included KM' }}</td>
                <td>
                    <strong>{{ number_format($freeKmPerPackage, 0) }} km</strong>
                    <small>{{ $isDayPackage ? '' : '(per package)' }}</small>
                    {{-- <small>{{ $isDayPackage ? '(return to same pickup location)' : '(per package)' }}</small> --}}
                </td>
            </tr>
        @elseif($allowedTotalKm)
            <tr>
                <td>{{ $isDayPackage ? 'Selected Package' : 'Included KM' }}</td>
                <td>
                    <strong>{{ number_format($allowedTotalKm, 0) }} km</strong>
                    {{-- @if ($isDayPackage)
                        <small>(return to same pickup location)</small>
                    @endif --}}
                </td>
            </tr>
        @elseif($packageKmLabel)
            <tr>
                <td>Selected Package</td>
                <td><strong>{{ $packageKmLabel }}</strong> 
                    {{-- <small>(return to same pickup location)</small> --}}
                </td>
            </tr>
        @endif

        @if ($extraKilometers > 0)
            <tr>
                <td style="padding: 4px 0; border-bottom: 1px solid #eef0f2; font-weight: 600; color: #333;">
                    Extra Kilometers
                </td>
                <td style="padding: 4px 0; border-bottom: 1px solid #eef0f2; color: #555;">
                    {{ number_format($extraKilometers) }} km
                    @if ($extraKmPrice > 0)
                        @ {{ $currencySymbol }}{{ number_format($extraKmPrice, 2) }}/km
                    @endif
                    @if ($extraKmTotal > 0)
                        <span style="float: right; color: #BF2629;">{{ $currencySymbol }}{{ number_format($extraKmTotal, 2) }}</span>
                    @endif
                </td>
            </tr>
        @endif

        @if ($extraKmPrice)
            <tr>
                <td>Extra KM Rate</td>
                <td><strong>{{ $currencySymbol }}{{ number_format($extraKmPrice, 2) }}</strong> per km</td>
            </tr>
        @endif

        @if (!empty($addonsList))
            <tr>
                <td
                    style="padding: 4px 0; border-bottom: 1px solid #eef0f2; font-weight: 600; color: #333; vertical-align: top;">
                    Selected Add-ons
                </td>
                <td style="padding: 4px 0; border-bottom: 1px solid #eef0f2; color: #555;">
                    @foreach ($addonsList as $addon)
                        <div style="margin-bottom: 4px; display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <strong>{{ $addon['name'] }}</strong>
                                <small style="color: #777; margin-left: 4px;">
                                    (Qty: {{ $addon['qty'] }}@if ($addon['rate'] > 0)
                                        x {{ $currencySymbol }}{{ number_format($addon['rate'], 2) }}
                                    @elseif ($addon['total'] > 0 && $addon['qty'] > 0)
                                        - Avg: {{ $currencySymbol }}{{ number_format($addon['total'] / $addon['qty'], 2) }}
                                    @endif)
                                </small>
                            </div>
                            <span style="color: #BF2629; font-weight: 600;">{{ $currencySymbol }}{{ number_format($addon['total'], 2) }}</span>
                        </div>
                    @endforeach
                </td>
            </tr>
        @endif

        <tr>
            <td style="padding: 4px 0; font-weight: 600; color: #333; width: 30%;">
                {{ $totalLabel }}
            </td>
            <td style="padding: 4px 0; color: #555; font-weight: 600;">
                {{ $currencySymbol }} {{ number_format($totalPrice, 2) }}
                @if ($isReturnTrip && !empty($returnDiscountPct) && $returnDiscountPct > 0)
                    <small class="text-success" style="margin-left: 8px;">({{ $returnDiscountPct }}% return discount applied)</small>
                @endif
            </td>
        </tr>
    </table>

    @php
        $workflow = is_string($item->booking->workflow_data ?? null)
            ? json_decode($item->booking->workflow_data, true)
            : $item->booking->workflow_data ?? [];
        $cartIndex = $item->metadata['item_index'] ?? null;
        $bookingExtra = null;
        if (!is_null($cartIndex) && isset($workflow['cart_items'][$cartIndex])) {
            $bookingExtra = $workflow['cart_items'][$cartIndex]['extra_km'] ?? null;
        }
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
                            $addonName =
                                $addon['name'] ?? ($addon['label'] ?? (is_string($addonKey) ? $addonKey : 'Addon'));
                            $addonQty = $addon['qty'] ?? ($addon['quantity'] ?? 1);
                            $addonRate = $addon['amount'] ?? ($addon['rate'] ?? 0);
                            $addonTotal = $addon['calculated_amount'] ?? ($addon['total'] ?? $addonRate * $addonQty);
                        @endphp
                        <tr>
                            <td style="padding:6px 0; border-bottom:1px solid #eef0f2;">{{ $addonName }}
                                @if ($addonQty > 1)
                                    <small style="color:#777;">x{{ $addonQty }}</small>
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
                    {{ $bookingExtra['km'] ?? ($bookingExtra['quantity'] ?? 0) }} km - {{ $currencySymbol }}
                    {{ number_format($bookingExtra['total_cost'] ?? ($bookingExtra['calculated_amount'] ?? 0), 2) }}
                </div>
            @endif
        </div>
    @endif
</div>
