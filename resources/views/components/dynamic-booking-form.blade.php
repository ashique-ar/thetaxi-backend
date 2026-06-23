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
    $submitLabel = $isInquiry
        ? ($settings['booking_submit_inquiry_label'] ?? 'Submit Inquiry')
        : ($settings['booking_search_submit_label'] ?? 'Search Vehicles');

    // Resolve current values for location fields
    $pickupLoc = $getLocationForService($serviceCode, true);
    $dropoffLoc = $getLocationForService($serviceCode, false);
    $pickupDate = $getDateForService($serviceCode, true);
    $pickupTime = $getTimeForService($serviceCode, true);
    $dropoffDate = $getDateForService($serviceCode, false);
    $dropoffTime = $getTimeForService($serviceCode, false);

    // Sort fields by order
    $sortedFields = collect($fields)->sortBy('order')->all();

    // Backward-compatible return-trip UX for Ride Now.
    // The frontend JS still targets legacy IDs/classes (ride_now-return-*).
    $allowReturnTrip = (bool) ($serviceTypeModel?->allow_return_trip ?? false);
    $useLegacyRideNowReturnBlock = ($serviceCode === 'ride_now' && $allowReturnTrip);
    $rideNowReturnFieldKeys = [];
    if ($useLegacyRideNowReturnBlock) {
        foreach ($sortedFields as $key => $cfg) {
            $submitAs = $cfg['submit_as'] ?? $key;
            if (in_array($submitAs, ['is_return_trip', 'return_date', 'return_time'], true)) {
                $rideNowReturnFieldKeys[] = $key;
            }
        }
        foreach ($rideNowReturnFieldKeys as $returnFieldKey) {
            unset($sortedFields[$returnFieldKey]);
        }
    }

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

    // Legacy Ride Now return values
    $rideNowIsReturnTrip = old('is_return_trip');
    if ($rideNowIsReturnTrip === null) {
        if (isset($search) && is_object($search) && property_exists($search, 'is_return_trip')) {
            $rideNowIsReturnTrip = $search->is_return_trip;
        } elseif (isset($search) && is_array($search) && array_key_exists('is_return_trip', $search)) {
            $rideNowIsReturnTrip = $search['is_return_trip'];
        } else {
            $rideNowIsReturnTrip = false;
        }
    }
    $rideNowIsReturnTrip = filter_var($rideNowIsReturnTrip, FILTER_VALIDATE_BOOLEAN);

    $rideNowReturnDate = old('return_date');
    if ($rideNowReturnDate === null) {
        if (isset($search) && is_object($search) && property_exists($search, 'return_date')) {
            $rideNowReturnDate = $search->return_date;
        } elseif (isset($search) && is_array($search) && array_key_exists('return_date', $search)) {
            $rideNowReturnDate = $search['return_date'];
        }
    }
    if ($rideNowReturnDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $rideNowReturnDate)) {
        $rideNowReturnDate = date('d/m/Y', strtotime($rideNowReturnDate));
    }
    if (!$rideNowReturnDate) {
        $rideNowReturnDate = date('d/m/Y');
    }

    $rideNowReturnTime = old('return_time');
    if ($rideNowReturnTime === null) {
        if (isset($search) && is_object($search) && property_exists($search, 'return_time')) {
            $rideNowReturnTime = $search->return_time;
        } elseif (isset($search) && is_array($search) && array_key_exists('return_time', $search)) {
            $rideNowReturnTime = $search['return_time'];
        } else {
            $rideNowReturnTime = '12:00';
        }
    }
@endphp

<form id="{{ $formId }}"
      class="filter-input {{ $isActive ? 'show' : '' }}"
      data-service="{{ $serviceCode }}"
      action="{{ $actionRoute }}"
      method="GET">

    <input type="hidden" name="service_type" value="{{ $serviceCode }}">

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
            } elseif ($fieldType === 'package_select') {
                $searchPackageValue = null;
                if (isset($search) && is_object($search)) {
                    $searchPackageValue = $search->$submitAs
                        ?? ($search->package_id ?? null)
                        ?? ($search->service_package_id ?? null);
                } elseif (isset($search) && is_array($search)) {
                    $searchPackageValue = $search[$submitAs]
                        ?? ($search['package_id'] ?? null)
                        ?? ($search['service_package_id'] ?? null);
                }

                $currentValue = old($submitAs, $searchPackageValue ?? $fieldDefault);
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
            @endphp
        @endif
    @endforeach

    @if($useLegacyRideNowReturnBlock)
        <div class="return-trip-section" id="ride_now-return-trip-section">
            <div class="return-trip-toggle">
                <label class="return-trip-checkbox-label">
                    <input type="checkbox"
                           name="is_return_trip"
                           id="ride_now-return-toggle"
                           value="1"
                           {{ $rideNowIsReturnTrip ? 'checked' : '' }}>
                    <span class="return-trip-text">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                             xmlns="http://www.w3.org/2000/svg">
                            <path
                                d="M7.5 21L3 16.5M3 16.5L7.5 12M3 16.5H16.5C18.9853 16.5 21 14.4853 21 12C21 9.51472 18.9853 7.5 16.5 7.5H15"
                                stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                stroke-linejoin="round" />
                        </svg>
                        {{ $settings['booking_return_trip_toggle_label'] ?? 'Add Return Trip' }}
                    </span>
                </label>
            </div>

            <div class="return-trip-details"
                 id="ride_now-return-details"
                 style="display: {{ $rideNowIsReturnTrip ? 'grid' : 'none' }};">
                <div class="return-route-summary">
                    <div class="route-badge">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                             xmlns="http://www.w3.org/2000/svg">
                            <path d="M9 5L16 12L9 19" stroke="currentColor" stroke-width="2"
                                  stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        <span class="route-text">
                            <strong>{{ $settings['booking_return_route_label'] ?? 'Return:' }}</strong>
                            <span id="return-dropoff-location">{{ $dropoffLoc['address'] ?? ($settings['booking_return_dropoff_placeholder'] ?? 'Drop-off') }}</span>
                            &rarr;
                            <span id="return-pickup-location">{{ $pickupLoc['address'] ?? ($settings['booking_return_pickup_placeholder'] ?? 'Pickup') }}</span>
                        </span>
                    </div>
                </div>

                <div class="single-search-box date-field">
                    <div class="d-flex align-items-center gap-2 py-1">
                        <label class="input-label">{{ $settings['booking_return_date_label'] ?? 'Return Date' }}</label>
                        @include('components.partials.calendar-icon')
                    </div>
                    <input type="text"
                           name="return_date"
                           id="ride_now-return-date"
                           placeholder="{{ $settings['booking_return_date_placeholder'] ?? 'DD/MM/YYYY' }}"
                           class="custom-datepicker @error('return_date') is-invalid @enderror"
                           value="{{ $rideNowReturnDate }}"
                           autocomplete="off">
                    @error('return_date')
                        <span class="text-danger small">{{ $message }}</span>
                    @enderror
                </div>

                <div class="single-search-box">
                    <div class="d-flex align-items-center gap-2 py-1">
                        <label class="input-label">{{ $settings['booking_return_time_label'] ?? 'Return Time' }}</label>
                        @include('components.partials.clock-icon')
                    </div>
                    <div class="custom-select-dropdown">
                        <input type="time"
                               name="return_time"
                               id="ride_now-return-time"
                               value="{{ $rideNowReturnTime }}"
                               class="@error('return_time') is-invalid @enderror">
                    </div>
                    @error('return_time')
                        <span class="text-danger small">{{ $message }}</span>
                    @enderror
                </div>

                <div class="return-pricing-info"
                     id="ride_now-return-pricing-info"
                     style="display: {{ $rideNowIsReturnTrip ? 'block' : 'none' }};">
                    <div class="text-success">
                        <span id="ride_now-return-discount-label">{{ $settings['booking_return_discount_label'] ?? 'Same Day Return' }}</span>
                        <span class="fw-bold" id="ride_now-return-discount-value">{{ $settings['booking_return_discount_value'] ?? '50% off return' }}</span>
                    </div>
                </div>
            </div>
        </div>
    @endif

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
                    <label class="input-label">{{ $settings['booking_dropoff_label'] ?? 'Dropoff Location' }}</label>
                    @include('components.partials.location-icon')
                </div>
                <div class="custom-select-dropdown">
                    <input type="text" name="dropoff" id="{{ $prefix }}_dropoff_input"
                           placeholder="{{ $settings['booking_dropoff_placeholder'] ?? 'Enter your dropoff location' }}"
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
