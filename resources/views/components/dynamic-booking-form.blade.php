{{--
    Dynamic Booking Form Renderer
    Renders a complete service booking form from form_config JSON.

    Props:
    - $tab: BookingFormTab model
    - $serviceTypeModel: ServiceType model (nullable)
    - $fields: array - Parsed form_config fields
    - $formId: string - Form element ID
    - $isActive: bool - Whether this form tab is currently active
    - $serviceCode: string - The service type code for this form
    - $getLocationForService: Closure
    - $getDateForService: Closure
    - $getTimeForService: Closure
    - $safeOldOr: Closure
    - $predefinedLocations: Collection
    - $airportOptions: Collection
    - $airportTransferType: string|null
--}}

@php
    $isInquiry = (bool) ($serviceTypeModel?->is_inquiry ?? false);
    $actionRoute = $isInquiry ? route('booking.enquiry') : route('booking.search');
    $submitLabel = $isInquiry ? 'Submit Inquiry' : 'Search Vehicles';
    $rentalMode = in_array($serviceCode, ['self_drive', 'with_driver']) ? $serviceCode : null;

    // Resolve current values for location fields
    $pickupLoc = $getLocationForService($serviceCode, true);
    $dropoffLoc = $getLocationForService($serviceCode, false);
    $pickupDate = $getDateForService($serviceCode, true);
    $pickupTime = $getTimeForService($serviceCode, true);
    $dropoffDate = $getDateForService($serviceCode, false);
    $dropoffTime = $getTimeForService($serviceCode, false);

    // Sort fields by order
    $sortedFields = collect($fields)->sortBy('order')->all();

    // Load service packages for this service type
    $servicePackages = collect();
    if ($serviceTypeModel) {
        try {
            $servicePackages = $serviceTypeModel->packages()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get();
        } catch (\Exception $e) {
            $servicePackages = collect();
        }
    }

    // Prefix for unique IDs (first 2 chars of service code, or custom)
    $prefixMap = [
        'airport_transfers' => 'at',
        'ride_now' => 'rn',
        'day_rental' => 'dr',
        'self_drive' => 'sd',
        'with_driver' => 'wd',
        'wedding_hire' => 'wh',
        'corporate' => 'co',
    ];
    $prefix = $prefixMap[$serviceCode] ?? substr(preg_replace('/[^a-z]/', '', strtolower($serviceCode)), 0, 3);
@endphp

<form id="{{ $formId }}"
      class="filter-input {{ $isActive ? 'show' : '' }}"
      data-service="{{ $serviceCode }}"
      action="{{ $actionRoute }}"
      method="GET">

    <input type="hidden" name="service_type" value="{{ $serviceCode }}">
    @if($rentalMode)
        <input type="hidden" name="rental_mode" value="{{ $rentalMode }}">
    @endif

    @foreach($sortedFields as $fieldName => $field)
        @php
            $submitAs = $field['submit_as'] ?? $fieldName;
            $fieldType = $field['type'] ?? 'text';
            $locationMode = $field['location_mode'] ?? 'autocomplete';
            $syncFrom = $field['sync_from'] ?? '';

            // Resolve current value based on field type and submit_as
            $currentValue = '';
            $currentLat = '';
            $currentLng = '';
            $fieldDefault = $field['default'] ?? '';

            // For conditional locations with airport variants, ensure default_lat/lng are set
            // The airport-select component handles fallback to first airport if name doesn't match
            if ($fieldType === 'location' && $locationMode === 'conditional' && !empty($field['conditions'])) {
                foreach ($field['conditions'] as $cv => $cc) {
                    if (($cc['type'] ?? '') === 'airport' && isset($airportOptions) && $airportOptions->isNotEmpty()) {
                        // Ensure we have default coordinates even if not in config
                        if (empty($field['default_lat']) && $fieldDefault) {
                            $cleanDefault = preg_replace('/\s*\(Airport\)\s*$/i', '', $fieldDefault);
                            foreach ($airportOptions as $ap) {
                                $apName = is_array($ap) ? ($ap['name'] ?? '') : ($ap->name ?? '');
                                if ($apName === $fieldDefault || $apName === $cleanDefault) {
                                    $field['default_lat'] = is_array($ap) ? ($ap['latitude'] ?? '') : ($ap->latitude ?? '');
                                    $field['default_lng'] = is_array($ap) ? ($ap['longitude'] ?? '') : ($ap->longitude ?? '');
                                    break;
                                }
                            }
                        }
                        break;
                    }
                }
            }

            if ($fieldType === 'location') {
                // Determine which location data to use
                $isPickupField = str_contains($fieldName, 'pickup') || $submitAs === 'pickup';
                $isDropoffField = str_contains($fieldName, 'dropoff') || $submitAs === 'dropoff';

                if ($isPickupField) {
                    $locAddr = $pickupLoc['address'] ?? '';
                    $currentValue = $safeOldOr($submitAs, $locAddr ?: $fieldDefault);
                    $locLat = $pickupLoc['lat'] ?? '';
                    $locLng = $pickupLoc['lng'] ?? '';
                    $currentLat = $safeOldOr($submitAs . '_lat', $locLat ?: ($field['default_lat'] ?? ''));
                    $currentLng = $safeOldOr($submitAs . '_lng', $locLng ?: ($field['default_lng'] ?? ''));
                } elseif ($isDropoffField) {
                    $locAddr = $dropoffLoc['address'] ?? '';
                    $currentValue = $safeOldOr($submitAs, $locAddr ?: $fieldDefault);
                    $locLat = $dropoffLoc['lat'] ?? '';
                    $locLng = $dropoffLoc['lng'] ?? '';
                    $currentLat = $safeOldOr($submitAs . '_lat', $locLat ?: ($field['default_lat'] ?? ''));
                    $currentLng = $safeOldOr($submitAs . '_lng', $locLng ?: ($field['default_lng'] ?? ''));
                } else {
                    $currentValue = $safeOldOr($submitAs, $fieldDefault);
                }
            } elseif ($fieldType === 'date') {
                $isPickupDate = str_contains($fieldName, 'pickup') || $submitAs === 'date';
                $rawDate = $isPickupDate ? $pickupDate : $dropoffDate;
                $formatted = $rawDate ? date('d/m/Y', strtotime($rawDate)) : date('d/m/Y');
                $currentValue = old($submitAs, $formatted);
            } elseif ($fieldType === 'time') {
                $isPickupTime = str_contains($fieldName, 'pickup') || $submitAs === 'time';
                $rawTime = $isPickupTime ? $pickupTime : $dropoffTime;
                $currentValue = old($submitAs, $rawTime ?? ($fieldDefault ?: '09:00'));
            } elseif ($fieldType === 'radio') {
                if ($submitAs === 'transfer_type') {
                    $currentValue = old($submitAs, $airportTransferType ?? ($fieldDefault ?: ''));
                }
                else {
                    $currentValue = old($submitAs, $fieldDefault);
                }
                // Validate that currentValue matches an actual option; if not, use first option
                if (!empty($field['options'])) {
                    $optionValues = array_column($field['options'], 'value');
                    if (!in_array($currentValue, $optionValues, true)) {
                        $currentValue = $optionValues[0] ?? '';
                    }
                }
            } else {
                $currentValue = old($submitAs, $fieldDefault);
            }
        @endphp

        @include('components.dynamic-form-field', [
            'fieldName' => $fieldName,
            'field' => $field,
            'prefix' => $prefix,
            'formId' => $formId,
            'currentValue' => $currentValue,
            'currentLat' => $currentLat,
            'currentLng' => $currentLng,
            'predefinedLocations' => $predefinedLocations ?? collect(),
            'airportOptions' => $airportOptions ?? collect(),
            'servicePackages' => $servicePackages,
            'transferType' => $airportTransferType ?? null,
        ])

        {{-- For predefined_or_custom location with sync_from: add dropoff sync fields --}}
        @if($fieldType === 'location' && $locationMode === 'predefined_or_custom' && $syncFrom)
            @php
                $isDropoff = str_contains($fieldName, 'dropoff') || $submitAs === 'dropoff';
                // Only add sync fields for the dropoff side — the pickup PLS dispatches events
                // Actually, the sync is handled differently: pickup PLS dispatches, and setupDropoffSync
                // in JS handles the hidden fields. We need the dropoff wrapper + hidden fields here.
            @endphp
        @endif
    @endforeach

    {{-- Dropoff sync fields for self_drive/with_driver --}}
    @if(in_array($serviceCode, ['self_drive', 'with_driver']))
        @php
            $hasPickupPredefined = false;
            $hasDropoffField = false;
            foreach ($sortedFields as $fn => $f) {
                if (($f['submit_as'] ?? $fn) === 'pickup' && ($f['location_mode'] ?? '') === 'predefined_or_custom') {
                    $hasPickupPredefined = true;
                }
                if (str_contains($fn, 'dropoff') && ($f['type'] ?? '') === 'location') {
                    $hasDropoffField = true;
                }
            }
        @endphp
        @if($hasPickupPredefined && !$hasDropoffField)
            {{-- Dropoff wrapper for doorstep (custom) selection --}}
            <div class="single-search-box location-search-box custom-location-box {{ $prefix }}-dropoff-box"
                 id="{{ $prefix }}_dropoff_wrapper"
                 style="display: none;">
                <div class="d-flex align-items-center gap-2 py-1">
                    <label class="input-label">Dropoff Location</label>
                    @include('components.partials.location-icon')
                </div>
                <div class="custom-select-dropdown">
                    <input type="text" name="dropoff" id="{{ $prefix }}_dropoff_input"
                           placeholder="Enter your dropoff location"
                           class="location-search @error('dropoff') is-invalid @enderror"
                           value="{{ $safeOldOr('dropoff', $dropoffLoc['address'] ?? '') }}"
                           disabled>
                    <input type="hidden" name="dropoff_lat" id="{{ $prefix }}_dropoff_lat" class="location-lat"
                           value="{{ $safeOldOr('dropoff_lat', $dropoffLoc['lat'] ?? '') }}">
                    <input type="hidden" name="dropoff_lng" id="{{ $prefix }}_dropoff_lng" class="location-lng"
                           value="{{ $safeOldOr('dropoff_lng', $dropoffLoc['lng'] ?? '') }}">
                </div>
                @error('dropoff')
                    <span class="text-danger small">{{ $message }}</span>
                @enderror
            </div>
            {{-- Hidden dropoff fields for predefined location sync --}}
            <input type="hidden" name="dropoff" id="{{ $prefix }}_dropoff_hidden" value="" disabled>
            <input type="hidden" name="dropoff_lat" id="{{ $prefix }}_dropoff_lat_hidden" value="" disabled>
            <input type="hidden" name="dropoff_lng" id="{{ $prefix }}_dropoff_lng_hidden" value="" disabled>
            <input type="hidden" name="dropoff_predefined" id="{{ $prefix }}_dropoff_predefined" value="" disabled>
        @endif
    @endif

    <button type="submit" class="primary-btn1">
        <span>{{ $submitLabel }}</span>
    </button>
</form>
