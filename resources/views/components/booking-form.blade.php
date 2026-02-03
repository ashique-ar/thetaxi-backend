@php
    // Ensure $search exists to avoid undefined variable when included without search context
    $search = $search ?? null;

    // Helper function to safely get search property
    $getSearchProp = function ($prop, $default = null) use ($search) {
        // First check old() helper for form resubmissions
        $oldValue = old($prop);
        if ($oldValue !== null) {
            return $oldValue;
        }

        // Then check search object
        if (!isset($search)) {
            return $default;
        }
        if (is_object($search) && property_exists($search, $prop)) {
            return $search->$prop;
        }
        if (is_array($search) && isset($search[$prop])) {
            return $search[$prop];
        }
        return $default;
    };

    // Get current service type
    $currentServiceType = $getSearchProp('service_type', 'airport_transfers');

    // Normalize pickup/dropoff locations: support string OR object/array with latitude/longitude
    $normalizeLocation = function ($search, $key) {
        $result = [
            'address' => null,
            'lat' => null,
            'lng' => null,
        ];

        if (!isset($search)) {
            return $result;
        }

        // Check for dedicated latitude/longitude fields
        $latKey = $key . '_latitude';
        $lngKey = $key . '_longitude';
        if (isset($search->$latKey) || (is_array($search) && isset($search[$latKey]))) {
            $result['lat'] = is_object($search) ? $search->$latKey : $search[$latKey];
        }
        if (isset($search->$lngKey) || (is_array($search) && isset($search[$lngKey]))) {
            $result['lng'] = is_object($search) ? $search->$lngKey : $search[$lngKey];
        }

        // If a combined location object exists, extract from it
        $loc = null;
        if (is_object($search) && property_exists($search, $key)) {
            $loc = $search->$key;
        }
        if (is_array($search) && isset($search[$key])) {
            $loc = $search[$key];
        }

        if ($loc) {
            if (is_array($loc)) {
                $result['address'] = $loc['address'] ?? ($loc['name'] ?? null);
                $result['lat'] = $result['lat'] ?? ($loc['latitude'] ?? ($loc['lat'] ?? null));
                $result['lng'] = $result['lng'] ?? ($loc['longitude'] ?? ($loc['lng'] ?? null));
            } elseif (is_object($loc)) {
                $result['address'] = $loc->address ?? ($loc->name ?? null);
                $result['lat'] = $result['lat'] ?? ($loc->latitude ?? ($loc->lat ?? null));
                $result['lng'] = $result['lng'] ?? ($loc->longitude ?? ($loc->lng ?? null));
            } else {
                // loc may be a simple string
                $result['address'] = $loc;
            }
        }

        // Do not synthesize or guess an address from coordinates here; prefer the raw address from form or initial data.
        // Final fallback: if still empty, leave null so JS can set defaults if desired.

        // Final fallback: if still empty, leave null so JS can set defaults
        return $result;
    };

    $pickup = $normalizeLocation($search ?? null, 'pickup_location');
    $dropoff = $normalizeLocation($search ?? null, 'dropoff_location');

    // ============================================================================
    // CROSS-SERVICE LOCATION & DATE INTELLIGENCE
    // ============================================================================
    // When a search includes a drop-off location, use it as pickup for OTHER services.
    // The originally searched service keeps its original data unchanged.
    // ============================================================================

    // Get search dates
    $searchPickupDate = $getSearchProp('pickup_date') ?? $getSearchProp('from_date');
    $searchDropoffDate = $getSearchProp('dropoff_date') ?? $getSearchProp('to_date');
    $searchPickupTime = $getSearchProp('pickup_time') ?? $getSearchProp('from_time');
    $searchDropoffTime = $getSearchProp('dropoff_time') ?? $getSearchProp('to_time');

    // Determine if drop-off location exists (has meaningful data)
    $hasDropoffLocation = !empty($dropoff['address']) || (!empty($dropoff['lat']) && !empty($dropoff['lng']));

    // For OTHER service types (not the searched one), determine default location and date
    $otherServicesPickupLocation = $hasDropoffLocation ? $dropoff : $pickup;
    $otherServicesPickupDate = $searchDropoffDate ?? $searchPickupDate;
    $otherServicesPickupTime = $searchDropoffTime ?? $searchPickupTime;

    // For drop-off date on other services, default to same day as pickup (same-day rental)
    $otherServicesDropoffDate = null;
    if ($otherServicesPickupDate) {
        try {
            $dateObj = new DateTime($otherServicesPickupDate);
            // Same day rental - no need to add days
            $otherServicesDropoffDate = $dateObj->format('Y-m-d');
        } catch (Exception $e) {
            $otherServicesDropoffDate = null;
        }
    }

    // Helper function to get location for a specific service type
    $getLocationForService = function ($serviceType, $isPickup = true) use (
        $currentServiceType,
        $pickup,
        $dropoff,
        $otherServicesPickupLocation,
    ) {
        // If this is the searched service, return original data
        if ($serviceType === $currentServiceType) {
            return $isPickup ? $pickup : $dropoff;
        }

        // For other services, pickup comes from otherServicesPickupLocation
        if ($isPickup) {
            return $otherServicesPickupLocation;
        }

        // Drop-off for other services is empty by default (user needs to fill)
        return ['address' => null, 'lat' => null, 'lng' => null];
    };

    // Helper function to get date for a specific service type
    $getDateForService = function ($serviceType, $isPickup = true) use (
        $currentServiceType,
        $searchPickupDate,
        $searchDropoffDate,
        $otherServicesPickupDate,
        $otherServicesDropoffDate,
    ) {
        // If this is the searched service, return original data
        if ($serviceType === $currentServiceType) {
            return $isPickup ? $searchPickupDate : $searchDropoffDate;
        }

        // For other services
        return $isPickup ? $otherServicesPickupDate : $otherServicesDropoffDate;
    };

    // Helper function to get time for a specific service type
    $getTimeForService = function ($serviceType, $isPickup = true) use (
        $currentServiceType,
        $searchPickupTime,
        $searchDropoffTime,
        $otherServicesPickupTime,
    ) {
        // If this is the searched service, return original data
        if ($serviceType === $currentServiceType) {
            return $isPickup ? $searchPickupTime : $searchDropoffTime;
        }

        // For other services, use the pickup time (or default to empty)
        return $isPickup ? $otherServicesPickupTime : null;
    };

    // ============================================================================
    // SPECIAL HANDLING FOR AIRPORT TRANSFERS
    // ============================================================================
    // Determine transfer_type (from-airport / to-airport) for airport_transfers
    $airportTransferType = null;
    if ($currentServiceType === 'airport_transfers') {
        // If this IS the searched service, use the search transfer_type
        $airportTransferType = $getSearchProp('transfer_type', 'from-airport');
    } else {
        // If switching FROM another service TO airport_transfers, default to 'to-airport'
        $airportTransferType = 'to-airport';
    }

    // Override with old() if form was resubmitted
    $airportTransferType = old('transfer_type', $airportTransferType);

    // Utility for safe old() fallback
    $safeOldOr = function ($field, $value) {
        $oldValue = old($field);
        return $oldValue !== null ? $oldValue : $value;
    };

    // Load service type model and flags to drive form behavior
    try {
        $serviceTypeModel = \App\Models\Service\ServiceType::where('code', $currentServiceType)->first();
    } catch (Exception $e) {
        $serviceTypeModel = null;
    }

    $usesDropoffTime = $serviceTypeModel?->uses_dropoff_time ?? true;
    $allowReturnTrip = $serviceTypeModel?->allow_return_trip ?? false;
    $pricingMode = $serviceTypeModel?->pricing_mode ?? 'day';

    // Booking advance time configured in admin (hours). Used by blade/js to set minimum booking date/time.
    try {
        $bookingSettings = app(\App\Services\WebsiteSettingsService::class)->getBookingSettings();
        $bookingAdvanceHours = (int) ($bookingSettings['booking_advance_hours'] ?? 4);
    } catch (Exception $e) {
        $bookingAdvanceHours = 0;
    }
@endphp

<div class="filter-wrapper {{ theme_class('filter-wrapper') }}">
    <ul class="filter-item-list">
        <li class="single-item {{ $currentServiceType === 'airport_transfers' ? 'active' : '' }}"
            data-service="airport_transfers">
            <svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path
                    d="M21 16v-2l-8-5V3.5c0-.83-.67-1.5-1.5-1.5S10 2.67 10 3.5V9l-8 5v2l8-2.5V19l-2 1.5V22l3.5-1 3.5 1v-1.5L13 19v-5.5l8 2.5z" />
            </svg>
            <span>Airport Transfer</span>
        </li>

        <li class="single-item {{ $currentServiceType === 'ride_now' ? 'active' : '' }}" data-service="ride_now">
            <svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path
                    d="M17 5h-2v2h2v2h2V7h2V5h-2V3h-2v2zm-2 4V7H9.01L3 13.01V19c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2v-6h-6zM5 19v-4.99l4-4 4 4L9 18H5zm14 0h-6v-4l-2-2-4 4v2h12z" />
            </svg>
            <span>Drop & Pickup</span>
        </li>

        <li class="single-item {{ $currentServiceType === 'day_rental' ? 'active' : '' }}" data-service="day_rental">
            <svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path
                    d="M19 3h-1V1h-2v2H8V1H6v2H5c-1.11 0-1.99.9-1.99 2L3 19c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V8h14v11zM9 10H7v2h2v-2zm4 0h-2v2h2v-2zm4 0h-2v2h2v-2zm-8 4H7v2h2v-2zm4 0h-2v2h2v-2zm4 0h-2v2h2v-2z" />
            </svg>
            <span>Day Package</span>
        </li>

        {{-- <li class="single-item {{ $currentServiceType === 'point_to_point' ? 'active' : '' }}" data-service="point_to_point" data-redirect="{{ route('point-to-point') }}">
            <svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path
                    d="M18.92 6.01C18.72 5.42 18.16 5 17.5 5h-11c-.66 0-1.21.42-1.42 1.01L3 12v8c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-1h12v1c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-8l-2.08-5.99zM6.5 16c-.83 0-1.5-.67-1.5-1.5S5.67 13 6.5 13s1.5.67 1.5 1.5S7.33 16 6.5 16zm11 0c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zM5 11l1.5-4.5h11L19 11H5z" />
            </svg>
            <span>Point to Point</span>
        </li> --}}
        {{-- <li class="single-item {{ $currentServiceType === 'corporate_transport' ? 'active' : '' }}"
            data-service="corporate_transport" data-redirect="{{ route('corporate-transfers') }}">
            <svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path
                    d="M12 7V3H2v18h20V7H12zM6 19H4v-2h2v2zm0-4H4v-2h2v2zm0-4H4V9h2v2zm0-4H4V5h2v2zm4 12H8v-2h2v2zm0-4H8v-2h2v2zm0-4H8V9h2v2zm0-4H8V5h2v2zm10 12h-8v-2h2v-2h-2v-2h2v-2h-2V9h8v10zm-2-8h-2v2h2v-2zm0 4h-2v2h2v-2z" />
            </svg>
            <span>Corporate Transport</span>
        </li> --}}
    </ul>

    <div class="filter-input-wrap">
        <!-- Validation Errors Display -->
        @if ($errors->any())
            <div class="alert alert-danger alert-dismissible fade show" role="alert">

                <strong>Please correct the following errors:</strong>
                <ul class="mb-0 mt-2">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif

        <!-- Success Message Display -->
        @if (session('success'))
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif

        <!-- Airport Transfer Form -->
        <form id="airport_transfers-form"
            class="filter-input {{ $currentServiceType === 'airport_transfers' ? 'show' : '' }}"
            data-service="airport_transfers" action="{{ route('booking.search') }}" method="GET">
            <input type="hidden" name="service_type" value="airport_transfers">

            @if ($bookingAdvanceHours && $bookingAdvanceHours > 0)
                <div class="booking-advance-note alert alert-warning d-flex align-items-center" role="note"
                    style="margin-bottom:12px;">
                    <svg width="18" height="18" viewBox="0 0 16 16" fill="none"
                        xmlns="http://www.w3.org/2000/svg" style="margin-right:8px;flex-shrink:0;">
                        <path
                            d="M8 1.333c-3.683 0-6.667 2.984-6.667 6.667S4.317 14.667 8 14.667 14.667 11.683 14.667 8 11.683 1.333 8 1.333zm0 9.334a.667.667 0 110 1.334.667.667 0 010-1.334zM7.333 4.667h1.334V9.33H7.333V4.667z"
                            fill="#856404" />
                    </svg>
                    <div style="color:#856404;">
                        <strong>Booking Notice:</strong>
                        <span> Bookings must be made at least <strong>{{ $bookingAdvanceHours }}
                                hour{{ $bookingAdvanceHours > 1 ? 's' : '' }}</strong> in advance.</span>
                    </div>
                </div>
            @endif

            <!-- Transfer Type Selection - Compact Toggle Style -->
            <div class="transfer-type-selector">
                <div class="transfer-type-toggle">
                    <label class="transfer-type-option">
                        <input type="radio" name="transfer_type" value="from-airport"
                            {{ $airportTransferType === 'from-airport' ? 'checked' : '' }}>
                        <span>From Airport</span>
                    </label>
                    <label class="transfer-type-option">
                        <input type="radio" name="transfer_type" value="to-airport"
                            {{ $airportTransferType === 'to-airport' ? 'checked' : '' }}>
                        <span>To Airport</span>
                    </label>
                </div>
                @error('transfer_type')
                    <span class="text-danger small">{{ $message }}</span>
                @enderror
            </div>

            <!-- Package Selection (uses same toggle design as transfer-type) -->
            {{-- <div class="package-selector" id="airport_transfers-packages" style="display: none;"
                data-selected="{{ old('package_id', $getSearchProp('service_package_id', '')) }}">
                <div class="transfer-type-toggle package-selector-toggle">
                    <!-- Packages will be dynamically loaded here as transfer-type-option labels -->
                </div>
                <div class="loading-packages" style="display: none;">
                    <span>Loading packages...</span>
                </div>
            </div> --}}

            <!-- From Location -->
            @php
                $airportTransfersPickup = $getLocationForService('airport_transfers', true);
            @endphp
            <div class="single-search-box from-location location-search-box">
                <div class="d-flex align-items-center gap-2 py-1">
                    <label class="input-label">Pickup Location</label>
                    <svg width="15" height="15" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                        <g>
                            <path
                                d="M12.5944 8.99987C12.5944 10.988 10.9826 12.5998 8.99443 12.5998C7.00627 12.5998 5.39465 10.988 5.39465 8.99987C5.39465 7.0117 7.00627 5.40009 8.99443 5.40009C10.9826 5.40009 12.5944 7.0117 12.5944 8.99987Z" />
                            <path
                                d="M17.4601 8.4599H16.2564C15.9858 4.86535 13.1291 2.00812 9.53458 1.7372V0.539976C9.53458 0.241723 9.29268 0 8.9946 0C8.69635 0 8.45462 0.241723 8.45462 0.539976V1.7372C4.85986 2.00812 2.00297 4.86535 1.73235 8.4599H0.540018C0.241723 8.4599 0 8.7017 0 8.99987C0 9.29813 0.241723 9.53985 0.539976 9.53985H1.73239C2.00297 13.1344 4.85991 15.9916 8.45441 16.2625V17.4601C8.45441 17.7583 8.69614 18 8.99439 18C9.29251 18 9.53428 17.7583 9.53428 17.4601V16.2625C13.1289 15.9918 15.9858 13.1346 16.2564 9.53985H17.4601C17.7583 9.53985 18 9.29813 18 8.99987C18 8.70175 17.7583 8.4599 17.4601 8.4599ZM8.99443 15.2096C5.56504 15.2094 2.78509 12.4291 2.78509 8.9997C2.78522 5.57014 5.56554 2.7902 8.99494 2.7902C12.4245 2.7902 15.2046 5.57048 15.2046 8.99987C15.2005 12.428 12.4225 15.2058 8.99443 15.2096Z" />
                        </g>
                    </svg>
                </div>
                <div class="custom-select-dropdown">
                    <!-- Airport Select (shown when from-airport is selected) -->
                    <select name="pickup" id="from-airport-select"
                        class="airport-select from-field hidden @error('pickup') is-invalid @enderror" disabled>
                        <option value="">Select Airport</option>
                        <option value="Colombo BIA Airport" data-lat="7.1808" data-lng="79.8841"
                            {{ old('pickup') == 'Colombo BIA Airport' || ($airportTransfersPickup['address'] ?? '') == 'Colombo BIA Airport' ? 'selected' : '' }}>
                            Bandaranaike International Airport (BIA)
                        </option>
                        <option value="Mattala Rajapaksa Airport" data-lat="6.2847" data-lng="81.1242"
                            {{ old('pickup') == 'Mattala Rajapaksa Airport' || ($airportTransfersPickup['address'] ?? '') == 'Mattala Rajapaksa Airport' ? 'selected' : '' }}>
                            Mattala Rajapaksa International Airport
                        </option>
                        <option value="Jaffna International Airport" data-lat="9.7923" data-lng="80.0701"
                            {{ old('pickup') == 'Jaffna International Airport' || ($airportTransfersPickup['address'] ?? '') == 'Jaffna International Airport' ? 'selected' : '' }}>
                            Jaffna International Airport
                        </option>
                    </select>

                    <!-- Location Input (shown when to-airport is selected) -->
                    <input type="text" name="pickup" id="from-location-input" placeholder="Enter pickup location"
                        class="location-search from-field hidden @error('pickup') is-invalid @enderror"
                        value="{{ $safeOldOr('pickup', $airportTransfersPickup['address'] ?? '') }}" disabled>

                    <input type="hidden" name="pickup_lat" class="location-lat"
                        value="{{ $safeOldOr('pickup_lat', $airportTransfersPickup['lat'] ?? '7.1808') }}">
                    <input type="hidden" name="pickup_lng" class="location-lng"
                        value="{{ $safeOldOr('pickup_lng', $airportTransfersPickup['lng'] ?? '79.8841') }}">
                </div>
                @error('pickup')
                    <span class="text-danger small">{{ $message }}</span>
                @enderror
            </div>

            <!-- To Location -->
            @php
                $airportTransfersDropoff = $getLocationForService('airport_transfers', false);
            @endphp
            <div class="single-search-box to-location location-search-box">
                <div class="d-flex align-items-center gap-2 py-1">
                    <label class="input-label">Destination</label>
                    <svg width="15" height="15" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                        <g>
                            <path
                                d="M12.5944 8.99987C12.5944 10.988 10.9826 12.5998 8.99443 12.5998C7.00627 12.5998 5.39465 10.988 5.39465 8.99987C5.39465 7.0117 7.00627 5.40009 8.99443 5.40009C10.9826 5.40009 12.5944 7.0117 12.5944 8.99987Z" />
                            <path
                                d="M17.4601 8.4599H16.2564C15.9858 4.86535 13.1291 2.00812 9.53458 1.7372V0.539976C9.53458 0.241723 9.29268 0 8.9946 0C8.69635 0 8.45462 0.241723 8.45462 0.539976V1.7372C4.85986 2.00812 2.00297 4.86535 1.73235 8.4599H0.540018C0.241723 8.4599 0 8.7017 0 8.99987C0 9.29813 0.241723 9.53985 0.539976 9.53985H1.73239C2.00297 13.1344 4.85991 15.9916 8.45441 16.2625V17.4601C8.45441 17.7583 8.69614 18 8.99439 18C9.29251 18 9.53428 17.7583 9.53428 17.4601V16.2625C13.1289 15.9918 15.9858 13.1346 16.2564 9.53985H17.4601C17.7583 9.53985 18 9.29813 18 8.99987C18 8.70175 17.7583 8.4599 17.4601 8.4599ZM8.99443 15.2096C5.56504 15.2094 2.78509 12.4291 2.78509 8.9997C2.78522 5.57014 5.56554 2.7902 8.99494 2.7902C12.4245 2.7902 15.2046 5.57048 15.2046 8.99987C15.2005 12.428 12.4225 15.2058 8.99443 15.2096Z" />
                        </g>
                    </svg>
                </div>
                <div class="custom-select-dropdown">
                    <!-- Location Input (shown when from-airport is selected) -->
                    <input type="text" name="dropoff" id="to-location-input" placeholder="Enter destination"
                        class="location-search to-field hidden @error('dropoff') is-invalid @enderror"
                        value="{{ $safeOldOr('dropoff', $airportTransfersDropoff['address'] ?? '') }}" disabled>

                    <!-- Airport Select (shown when to-airport is selected) -->
                    <select name="dropoff" id="to-airport-select"
                        class="airport-select to-field hidden @error('dropoff') is-invalid @enderror" disabled>
                        <option value="">Select Airport</option>
                        <option value="Colombo BIA Airport" data-lat="7.1808" data-lng="79.8841"
                            {{ old('dropoff') == 'Colombo BIA Airport' || ($airportTransfersDropoff['address'] ?? '') == 'Colombo BIA Airport' ? 'selected' : '' }}>
                            Bandaranaike International Airport (BIA)
                        </option>
                        <option value="Mattala Rajapaksa Airport" data-lat="6.2847" data-lng="81.1242"
                            {{ old('dropoff') == 'Mattala Rajapaksa Airport' || ($airportTransfersDropoff['address'] ?? '') == 'Mattala Rajapaksa Airport' ? 'selected' : '' }}>
                            Mattala Rajapaksa International Airport
                        </option>
                        <option value="Jaffna International Airport" data-lat="9.7923" data-lng="80.0701"
                            {{ old('dropoff') == 'Jaffna International Airport' || ($airportTransfersDropoff['address'] ?? '') == 'Jaffna International Airport' ? 'selected' : '' }}>
                            Jaffna International Airport
                        </option>
                    </select>

                    <input type="hidden" name="dropoff_lat" class="location-lat"
                        value="{{ $safeOldOr('dropoff_lat', $airportTransfersDropoff['lat'] ?? '6.9271') }}">
                    <input type="hidden" name="dropoff_lng" class="location-lng"
                        value="{{ $safeOldOr('dropoff_lng', $airportTransfersDropoff['lng'] ?? '79.8612') }}">
                </div>
                @error('to')
                    <span class="text-danger small">{{ $message }}</span>
                @enderror
            </div>

            <!-- Date -->
            @php
                $airportTransfersPickupDate = $getDateForService('airport_transfers', true);
                $airportTransfersPickupTime = $getTimeForService('airport_transfers', true);
            @endphp
            <div class="single-search-box date-field">
                <div class="d-flex align-items-center gap-2 py-1">
                    <label class="input-label">Pickup Date</label>
                    <svg width="15" height="15" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                        <path
                            d="M15 2h-1V0h-2v2H6V0H4v2H3C1.89 2 1 2.89 1 4v12c0 1.1.89 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.11-.9-2-2-2zm0 14H3V7h12v9z" />
                    </svg>
                </div>
                <input type="text" name="date" placeholder="DD/MM/YYYY"
                    class="custom-datepicker @error('date') is-invalid @enderror"
                    value="{{ old('date', $airportTransfersPickupDate ? date('d/m/Y', strtotime($airportTransfersPickupDate)) : date('d/m/Y')) }}"
                    required autocomplete="off">
                @error('date')
                    <span class="text-danger small">{{ $message }}</span>
                @enderror
            </div>

            <!-- Time -->
            <div class="single-search-box">
                <div class="d-flex align-items-center gap-2 py-1">
                    <label class="input-label">Pickup Time</label>
                    <svg width="15" height="15" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                        <path
                            d="M9 0C4.03 0 0 4.03 0 9s4.03 9 9 9 9-4.03 9-9-4.03-9-9-9zm0 16c-3.87 0-7-3.13-7-7s3.13-7 7-7 7 3.13 7 7-3.13 7-7 7zm.5-12H8v5l4.25 2.52.75-1.23-3.5-2.08V4z" />
                    </svg>
                </div>
                <div class="custom-select-dropdown">
                    <input type="time" name="time"
                        value="{{ old('time', $airportTransfersPickupTime ?? '12:00') }}"
                        class="@error('time') is-invalid @enderror" required>
                </div>
                @error('time')
                    <span class="text-danger small">{{ $message }}</span>
                @enderror
            </div>

            <!-- Passengers -->
            {{-- <div class="single-search-box">
                <svg width="15" height="15" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                    <path d="M9 9c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                </svg>
                <div class="custom-select-dropdown">
                    <select name="passengers" class="@error('passengers') is-invalid @enderror" required>
                        <option value="">Passengers</option>
                        @for ($i = 1; $i <= 15; $i++)
                            <option value="{{ $i }}" {{ old('passengers', '2') == $i ? 'selected' : '' }}>
                                {{ $i }} {{ $i == 1 ? 'Passenger' : 'Passengers' }}
                            </option>
                        @endfor
                    </select>
                </div>
                @error('passengers')
                    <span class="text-danger small">{{ $message }}</span>
                @enderror
            </div> --}}

            <button type="submit" class="primary-btn1">
                <span>Search For Vehicles</span>
            </button>
        </form>

        <!-- Rental Packages Form -->
        <form id="ride_now-form" class="filter-input {{ $currentServiceType === 'ride_now' ? 'show' : '' }}"
            data-service="ride_now" action="{{ route('booking.search') }}" method="GET">
            <input type="hidden" name="service_type" value="ride_now">

            @if ($bookingAdvanceHours && $bookingAdvanceHours > 0)
                <div class="booking-advance-note alert alert-warning d-flex align-items-center" role="note"
                    style="margin-bottom:12px;">
                    <svg width="18" height="18" viewBox="0 0 16 16" fill="none"
                        xmlns="http://www.w3.org/2000/svg" style="margin-right:8px;flex-shrink:0;">
                        <path
                            d="M8 1.333c-3.683 0-6.667 2.984-6.667 6.667S4.317 14.667 8 14.667 14.667 11.683 14.667 8 11.683 1.333 8 1.333zm0 9.334a.667.667 0 110 1.334.667.667 0 010-1.334zM7.333 4.667h1.334V9.33H7.333V4.667z"
                            fill="#856404" />
                    </svg>
                    <div style="color:#856404;">
                        <strong>Booking Notice:</strong>
                        <span> Bookings must be made at least <strong>{{ $bookingAdvanceHours }}
                                hour{{ $bookingAdvanceHours > 1 ? 's' : '' }}</strong> in advance.</span>
                    </div>
                </div>
            @endif

            <!-- Pickup Location -->
            @php
                $rideNowPickup = $getLocationForService('ride_now', true);
            @endphp
            <div class="single-search-box location-search-box">

                <div class="d-flex align-items-center gap-2 py-1">
                    <label class="input-label">Pickup Time</label>
                    <svg width="15" height="15" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                        <g>
                            <path
                                d="M12.5944 8.99987C12.5944 10.988 10.9826 12.5998 8.99443 12.5998C7.00627 12.5998 5.39465 10.988 5.39465 8.99987C5.39465 7.0117 7.00627 5.40009 8.99443 5.40009C10.9826 5.40009 12.5944 7.0117 12.5944 8.99987Z" />
                            <path
                                d="M17.4601 8.4599H16.2564C15.9858 4.86535 13.1291 2.00812 9.53458 1.7372V0.539976C9.53458 0.241723 9.29268 0 8.9946 0C8.69635 0 8.45462 0.241723 8.45462 0.539976V1.7372C4.85986 2.00812 2.00297 4.86535 1.73235 8.4599H0.540018C0.241723 8.4599 0 8.7017 0 8.99987C0 9.29813 0.241723 9.53985 0.539976 9.53985H1.73239C2.00297 13.1344 4.85991 15.9916 8.45441 16.2625V17.4601C8.45441 17.7583 8.69614 18 8.99439 18C9.29251 18 9.53428 17.7583 9.53428 17.4601V16.2625C13.1289 15.9918 15.9858 13.1346 16.2564 9.53985H17.4601C17.7583 9.53985 18 9.29813 18 8.99987C18 8.70175 17.7583 8.4599 17.4601 8.4599ZM8.99443 15.2096C5.56504 15.2094 2.78509 12.4291 2.78509 8.9997C2.78522 5.57014 5.56554 2.7902 8.99494 2.7902C12.4245 2.7902 15.2046 5.57048 15.2046 8.99987C15.2005 12.428 12.4225 15.2058 8.99443 15.2096Z" />
                        </g>
                    </svg>
                </div>
                <div class="custom-select-dropdown">
                    <input type="text" name="pickup" placeholder="Pick up Location"
                        class="location-search @error('pickup') is-invalid @enderror"
                        value="{{ $safeOldOr('pickup', $rideNowPickup['address'] ?? 'Colombo, Sri Lanka') }}"
                        required>
                    <input type="hidden" name="pickup_lat" class="location-lat"
                        value="{{ $safeOldOr('pickup_lat', $rideNowPickup['lat'] ?? '6.9271') }}">
                    <input type="hidden" name="pickup_lng" class="location-lng"
                        value="{{ $safeOldOr('pickup_lng', $rideNowPickup['lng'] ?? '79.8612') }}">
                </div>
                @error('pickup')
                    <span class="text-danger small">{{ $message }}</span>
                @enderror
            </div>

            <!-- Drop Off Location -->
            @php
                $rideNowDropoff = $getLocationForService('ride_now', false);
            @endphp
            <div class="single-search-box location-search-box">
                <div class="d-flex align-items-center gap-2 py-1">
                    <label class="input-label">Drop Off Location</label>
                    <svg width="15" height="15" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                        <g>
                            <path
                                d="M12.5944 8.99987C12.5944 10.988 10.9826 12.5998 8.99443 12.5998C7.00627 12.5998 5.39465 10.988 5.39465 8.99987C5.39465 7.0117 7.00627 5.40009 8.99443 5.40009C10.9826 5.40009 12.5944 7.0117 12.5944 8.99987Z" />
                            <path
                                d="M17.4601 8.4599H16.2564C15.9858 4.86535 13.1291 2.00812 9.53458 1.7372V0.539976C9.53458 0.241723 9.29268 0 8.9946 0C8.69635 0 8.45462 0.241723 8.45462 0.539976V1.7372C4.85986 2.00812 2.00297 4.86535 1.73235 8.4599H0.540018C0.241723 8.4599 0 8.7017 0 8.99987C0 9.29813 0.241723 9.53985 0.539976 9.53985H1.73239C2.00297 13.1344 4.85991 15.9916 8.45441 16.2625V17.4601C8.45441 17.7583 8.69614 18 8.99439 18C9.29251 18 9.53428 17.7583 9.53428 17.4601V16.2625C13.1289 15.9918 15.9858 13.1346 16.2564 9.53985H17.4601C17.7583 9.53985 18 9.29813 18 8.99987C18 8.70175 17.7583 8.4599 17.4601 8.4599ZM8.99443 15.2096C5.56504 15.2094 2.78509 12.4291 2.78509 8.9997C2.78522 5.57014 5.56554 2.7902 8.99494 2.7902C12.4245 2.7902 15.2046 5.57048 15.2046 8.99987C15.2005 12.428 12.4225 15.2058 8.99443 15.2096Z" />
                        </g>
                    </svg>
                </div>
                <div class="custom-select-dropdown">
                    <input type="text" name="dropoff" placeholder="Drop Off Location"
                        class="location-search @error('dropoff') is-invalid @enderror"
                        value="{{ $safeOldOr('dropoff', $rideNowDropoff['address'] ?? 'Galle, Sri Lanka') }}"
                        required>
                    <input type="hidden" name="dropoff_lat" class="location-lat"
                        value="{{ $safeOldOr('dropoff_lat', $rideNowDropoff['lat'] ?? '6.0535') }}">
                    <input type="hidden" name="dropoff_lng" class="location-lng"
                        value="{{ $safeOldOr('dropoff_lng', $rideNowDropoff['lng'] ?? '80.221') }}">
                </div>
                @error('dropoff')
                    <span class="text-danger small">{{ $message }}</span>
                @enderror
            </div>

            <!-- Pickup Date -->
            @php
                $rideNowPickupDate = $getDateForService('ride_now', true);
                $rideNowPickupTime = $getTimeForService('ride_now', true);
            @endphp
            <div class="single-search-box date-field">
                <div class="d-flex align-items-center gap-2 py-1">
                    <label class="input-label">Pickpup Date</label>
                    <svg width="15" height="15" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                        <path
                            d="M15 2h-1V0h-2v2H6V0H4v2H3C1.89 2 1 2.89 1 4v12c0 1.1.89 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.11-.9-2-2-2zm0 14H3V7h12v9z" />
                    </svg>
                </div>
                <input type="text" name="pickup_date" placeholder="DD/MM/YYYY"
                    class="custom-datepicker @error('pickup_date') is-invalid @enderror"
                    value="{{ old('pickup_date', $rideNowPickupDate ? date('d/m/Y', strtotime($rideNowPickupDate)) : date('d/m/Y')) }}"
                    required autocomplete="off">
                @error('pickup_date')
                    <span class="text-danger small">{{ $message }}</span>
                @enderror
            </div>

            <!-- Pickup Time -->
            <div class="single-search-box">
                <div class="d-flex align-items-center gap-2 py-1">
                    <label class="input-label">Pickup Time</label>
                    <svg width="15" height="15" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                        <path
                            d="M9 0C4.03 0 0 4.03 0 9s4.03 9 9 9 9-4.03 9-9-4.03-9-9-9zm0 16c-3.87 0-7-3.13-7-7s3.13-7 7-7 7 3.13 7 7-3.13 7-7 7zm.5-12H8v5l4.25 2.52.75-1.23-3.5-2.08V4z" />
                    </svg>
                </div>
                <div class="custom-select-dropdown">
                    <input type="time" name="pickup_time"
                        value="{{ old('pickup_time', $rideNowPickupTime ?? '12:00') }}"
                        class="@error('pickup_time') is-invalid @enderror" required>
                </div>
                @error('pickup_time')
                    <span class="text-danger small">{{ $message }}</span>
                @enderror
            </div>

            <!-- Drop Off Date -->
            {{-- <div class="single-search-box date-field">
                <svg width="15" height="15" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                    <path
                        d="M15 2h-1V0h-2v2H6V0H4v2H3C1.89 2 1 2.89 1 4v12c0 1.1.89 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.11-.9-2-2-2zm0 14H3V7h12v9z" />
                </svg>
                <input type="text" name="dropoff_date" placeholder="DD/MM/YYYY"
                    class="custom-datepicker @error('dropoff_date') is-invalid @enderror"
                    value="{{ old('dropoff_date', isset($search) && isset($search->to_date) && $search->to_date ? date('d/m/Y', strtotime($search->to_date)) : date('d/m/Y')) }}"
                    required autocomplete="off">
                @error('dropoff_date')
                    <span class="text-danger small">{{ $message }}</span>
                @enderror
            </div>

            <!-- Drop Off Time -->
            <div class="single-search-box">
                <svg width="15" height="15" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                    <path
                        d="M9 0C4.03 0 0 4.03 0 9s4.03 9 9 9 9-4.03 9-9-4.03-9-9-9zm0 16c-3.87 0-7-3.13-7-7s3.13-7 7-7 7 3.13 7 7-3.13 7-7 7zm.5-12H8v5l4.25 2.52.75-1.23-3.5-2.08V4z" />
                </svg>
                <div class="custom-select-dropdown">
                    <input type="time" name="dropoff_time"
                        value="{{ old('dropoff_time', isset($search) && isset($search->to_time) ? $search->to_time : '12:00') }}"
                        class="@error('dropoff_time') is-invalid @enderror" required>
                </div>
                @error('dropoff_time')
                    <span class="text-danger small">{{ $message }}</span>
                @enderror
            </div> --}}

            {{-- <div class="package-selector" id="ride_now-packages" style="display: none;" class="d-none"
                data-selected="{{ old('package_id', $getSearchProp('service_package_id', '')) }}">
                <div class="transfer-type-toggle package-selector-toggle">
                    <!-- Packages will be dynamically loaded here as transfer-type-option labels -->
                </div>
                <div class="loading-packages" style="display: none;">
                    <span>Loading packages...</span>
                </div>
            </div> --}}


            <!-- Return Trip Toggle -->
            @php
                $isReturnTrip = old('is_return_trip', $getSearchProp('is_return_trip', false));
                $returnDate = old('return_date', $getSearchProp('return_date'));
                $returnTime = old('return_time', $getSearchProp('return_time', '12:00'));
                // Format return date to DD/MM/YYYY if it's in Y-m-d format
if ($returnDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $returnDate)) {
    $returnDate = date('d/m/Y', strtotime($returnDate));
} elseif (!$returnDate) {
    $returnDate = date('d/m/Y'); // Default to today
                }
            @endphp
            <div class="return-trip-section " id="ride_now-return-trip-section">
                <div class="return-trip-toggle">
                    <label class="return-trip-checkbox-label">
                        <input type="checkbox" name="is_return_trip" id="ride_now-return-toggle" value="1"
                            {{ $isReturnTrip ? 'checked' : '' }}>
                        <span class="return-trip-text">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                                xmlns="http://www.w3.org/2000/svg">
                                <path
                                    d="M7.5 21L3 16.5M3 16.5L7.5 12M3 16.5H16.5C18.9853 16.5 21 14.4853 21 12C21 9.51472 18.9853 7.5 16.5 7.5H15"
                                    stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                    stroke-linejoin="round" />
                            </svg>
                            Add Return Trip
                        </span>
                    </label>
                </div>

                <!-- Return Trip Details (shown if return trip enabled) -->
                <div class="return-trip-details" id="ride_now-return-details"
                    style="display: {{ $isReturnTrip ? 'grid' : 'none' }};">
                    <!-- Return Route Summary -->
                    <div class="return-route-summary">
                        <div class="route-badge">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                                xmlns="http://www.w3.org/2000/svg">
                                <path d="M9 5L16 12L9 19" stroke="currentColor" stroke-width="2"
                                    stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                            <span class="route-text">
                                <strong>Return:</strong>
                                <span
                                    id="return-dropoff-location">{{ $rideNowDropoff['address'] ?? 'Drop-off' }}</span>
                                →
                                <span id="return-pickup-location">{{ $rideNowPickup['address'] ?? 'Pickup' }}</span>
                            </span>
                        </div>
                    </div>

                    <!-- Return Date -->
                    <div class="single-search-box date-field">
                        <div class="d-flex align-items-center gap-2 py-1">
                            <label class="input-label">Return Date</label>
                            <svg width="15" height="15" viewBox="0 0 18 18"
                                xmlns="http://www.w3.org/2000/svg">
                                <path
                                    d="M15 2h-1V0h-2v2H6V0H4v2H3C1.89 2 1 2.89 1 4v12c0 1.1.89 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.11-.9-2-2-2zm0 14H3V7h12v9z" />
                            </svg>
                        </div>
                        <input type="text" name="return_date" id="ride_now-return-date" placeholder="DD/MM/YYYY"
                            class="custom-datepicker @error('return_date') is-invalid @enderror"
                            value="{{ $returnDate }}" autocomplete="off">
                        @error('return_date')
                            <span class="text-danger small">{{ $message }}</span>
                        @enderror
                    </div>

                    <!-- Return Time -->
                    <div class="single-search-box">
                        <div class="d-flex align-items-center gap-2 py-1">
                            <label class="input-label">Return Time</label>
                            <svg width="15" height="15" viewBox="0 0 18 18"
                                xmlns="http://www.w3.org/2000/svg">
                                <path
                                    d="M9 0C4.03 0 0 4.03 0 9s4.03 9 9 9 9-4.03 9-9-4.03-9-9-9zm0 16c-3.87 0-7-3.13-7-7s3.13-7 7-7 7 3.13 7 7-3.13 7-7 7zm.5-12H8v5l4.25 2.52.75-1.23-3.5-2.08V4z" />
                            </svg>
                        </div>
                        <div class="custom-select-dropdown">
                            <input type="time" name="return_time" id="ride_now-return-time"
                                value="{{ $returnTime }}" class="@error('return_time') is-invalid @enderror">
                        </div>
                        @error('return_time')
                            <span class="text-danger small">{{ $message }}</span>
                        @enderror
                    </div>

                    <!-- Return Pricing Info -->
                    <div class="return-pricing-info" id="ride_now-return-pricing-info"
                        style="display: {{ $isReturnTrip ? 'block' : 'none' }};">
                        <div class="text-success">
                            <span class="" id="ride_now-return-discount-label">Same Day Return</span>
                            <span class="fw-bold" id="ride_now-return-discount-value">50% off return</span>
                        </div>
                    </div>
                </div>
            </div>


            <button type="submit" class="primary-btn1">
                <span>Search For Vehicles</span>
            </button>
        </form>

        <!-- Day Rental Form -->
        <form id="day_rental-form" class="filter-input {{ $currentServiceType === 'day_rental' ? 'show' : '' }}"
            data-service="day_rental" action="{{ route('booking.search') }}" method="GET">

            <input type="hidden" name="service_type" value="day_rental">
            @if ($bookingAdvanceHours && $bookingAdvanceHours > 0)
                <div class="booking-advance-note alert alert-warning d-flex align-items-center" role="note"
                    style="margin-bottom:12px;">
                    <svg width="18" height="18" viewBox="0 0 16 16" fill="none"
                        xmlns="http://www.w3.org/2000/svg" style="margin-right:8px;flex-shrink:0;">
                        <path
                            d="M8 1.333c-3.683 0-6.667 2.984-6.667 6.667S4.317 14.667 8 14.667 14.667 11.683 14.667 8 11.683 1.333 8 1.333zm0 9.334a.667.667 0 110 1.334.667.667 0 010-1.334zM7.333 4.667h1.334V9.33H7.333V4.667z"
                            fill="#856404" />
                    </svg>
                    <div style="color:#856404;">
                        <strong>Booking Notice:</strong>
                        <span> Bookings must be made at least <strong>{{ $bookingAdvanceHours }}
                                hour{{ $bookingAdvanceHours > 1 ? 's' : '' }}</strong> in advance.</span>
                    </div>
                </div>
            @endif
            <!-- Pickup Location -->
            @php
                $dayRentalPickup = $getLocationForService('day_rental', true);
            @endphp
            <div class="single-search-box location-search-box">
                <div class="d-flex align-items-center gap-2 py-1">
                    <label class="input-label">Pickup Location</label>
                    <svg width="15" height="15" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                        <g>
                            <path
                                d="M12.5944 8.99987C12.5944 10.988 10.9826 12.5998 8.99443 12.5998C7.00627 12.5998 5.39465 10.988 5.39465 8.99987C5.39465 7.0117 7.00627 5.40009 8.99443 5.40009C10.9826 5.40009 12.5944 7.0117 12.5944 8.99987Z" />
                            <path
                                d="M17.4601 8.4599H16.2564C15.9858 4.86535 13.1291 2.00812 9.53458 1.7372V0.539976C9.53458 0.241723 9.29268 0 8.9946 0C8.69635 0 8.45462 0.241723 8.45462 0.539976V1.7372C4.85986 2.00812 2.00297 4.86535 1.73235 8.4599H0.540018C0.241723 8.4599 0 8.7017 0 8.99987C0 9.29813 0.241723 9.53985 0.539976 9.53985H1.73239C2.00297 13.1344 4.85991 15.9916 8.45441 16.2625V17.4601C8.45441 17.7583 8.69614 18 8.99439 18C9.29251 18 9.53428 17.7583 9.53428 17.4601V16.2625C13.1289 15.9918 15.9858 13.1346 16.2564 9.53985H17.4601C17.7583 9.53985 18 9.29813 18 8.99987C18 8.70175 17.7583 8.4599 17.4601 8.4599ZM8.99443 15.2096C5.56504 15.2094 2.78509 12.4291 2.78509 8.9997C2.78522 5.57014 5.56554 2.7902 8.99494 2.7902C12.4245 2.7902 15.2046 5.57048 15.2046 8.99987C15.2005 12.428 12.4225 15.2058 8.99443 15.2096Z" />
                        </g>
                    </svg>
                </div>
                <div class="custom-select-dropdown">
                    <input type="text" name="pickup" placeholder="Pick up Location"
                        class="location-search @error('pickup') is-invalid @enderror"
                        value="{{ $safeOldOr('pickup', $dayRentalPickup['address'] ?? 'Colombo, Sri Lanka') }}"
                        required>
                    <input type="hidden" name="pickup_lat" class="location-lat"
                        value="{{ $safeOldOr('pickup_lat', $dayRentalPickup['lat'] ?? '6.9271') }}">
                    <input type="hidden" name="pickup_lng" class="location-lng"
                        value="{{ $safeOldOr('pickup_lng', $dayRentalPickup['lng'] ?? '79.8612') }}">
                </div>
                @error('pickup')
                    <span class="text-danger small">{{ $message }}</span>
                @enderror
            </div>

            <!-- Drop Off Location -->
            {{-- <div class="single-search-box location-search-box">
                <svg width="15" height="15" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                    <g>
                        <path
                            d="M12.5944 8.99987C12.5944 10.988 10.9826 12.5998 8.99443 12.5998C7.00627 12.5998 5.39465 10.988 5.39465 8.99987C5.39465 7.0117 7.00627 5.40009 8.99443 5.40009C10.9826 5.40009 12.5944 7.0117 12.5944 8.99987Z" />
                        <path
                            d="M17.4601 8.4599H16.2564C15.9858 4.86535 13.1291 2.00812 9.53458 1.7372V0.539976C9.53458 0.241723 9.29268 0 8.9946 0C8.69635 0 8.45462 0.241723 8.45462 0.539976V1.7372C4.85986 2.00812 2.00297 4.86535 1.73235 8.4599H0.540018C0.241723 8.4599 0 8.7017 0 8.99987C0 9.29813 0.241723 9.53985 0.539976 9.53985H1.73239C2.00297 13.1344 4.85991 15.9916 8.45441 16.2625V17.4601C8.45441 17.7583 8.69614 18 8.99439 18C9.29251 18 9.53428 17.7583 9.53428 17.4601V16.2625C13.1289 15.9918 15.9858 13.1346 16.2564 9.53985H17.4601C17.7583 9.53985 18 9.29813 18 8.99987C18 8.70175 17.7583 8.4599 17.4601 8.4599ZM8.99443 15.2096C5.56504 15.2094 2.78509 12.4291 2.78509 8.9997C2.78522 5.57014 5.56554 2.7902 8.99494 2.7902C12.4245 2.7902 15.2046 5.57048 15.2046 8.99987C15.2005 12.428 12.4225 15.2058 8.99443 15.2096Z" />
                    </g>
                </svg>
                <div class="custom-select-dropdown">
                    <input type="text" name="dropoff" placeholder="Drop Off Location"
                        class="location-search @error('dropoff') is-invalid @enderror"
                        value="{{ $safeOldOr('dropoff', $dropoff['address'] ?? 'Galle, Sri Lanka') }}"
                        required>
                    <input type="hidden" name="dropoff_lat" class="location-lat"
                        value="{{ $safeOldOr('dropoff_lat', $dropoff['lat'] ?? ($search->dropoff_latitude ?? '6.0535')) }}">
                    <input type="hidden" name="dropoff_lng" class="location-lng"
                        value="{{ $safeOldOr('dropoff_lng', $dropoff['lng'] ?? ($search->dropoff_longitude ?? '80.221')) }}">
                </div>
                @error('dropoff')
                    <span class="text-danger small">{{ $message }}</span>
                @enderror
            </div> --}}

            <!-- Pickup Date -->
            @php
                $dayRentalPickupDate = $getDateForService('day_rental', true);
                $dayRentalPickupTime = $getTimeForService('day_rental', true);
            @endphp
            <div class="single-search-box date-field">
                <div class="d-flex align-items-center gap-2 py-1">
                    <label class="input-label">Pickup Date</label>
                    <svg width="15" height="15" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                        <path
                            d="M15 2h-1V0h-2v2H6V0H4v2H3C1.89 2 1 2.89 1 4v12c0 1.1.89 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.11-.9-2-2-2zm0 14H3V7h12v9z" />
                    </svg>
                </div>
                <input type="text" name="pickup_date" placeholder="DD/MM/YYYY"
                    class="custom-datepicker @error('pickup_date') is-invalid @enderror"
                    value="{{ old('pickup_date', $dayRentalPickupDate ? date('d/m/Y', strtotime($dayRentalPickupDate)) : date('d/m/Y')) }}"
                    required autocomplete="off">
                @error('pickup_date')
                    <span class="text-danger small">{{ $message }}</span>
                @enderror
            </div>

            <!-- Pickup Time -->
            <div class="single-search-box">
                <div class="d-flex align-items-center gap-2 py-1">
                    <label class="input-label">Pickup Time</label>
                    <svg width="15" height="15" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                        <path
                            d="M9 0C4.03 0 0 4.03 0 9s4.03 9 9 9 9-4.03 9-9-4.03-9-9-9zm0 16c-3.87 0-7-3.13-7-7s3.13-7 7-7 7 3.13 7 7-3.13 7-7 7zm.5-12H8v5l4.25 2.52.75-1.23-3.5-2.08V4z" />
                    </svg>
                </div>
                <div class="custom-select-dropdown">
                    <input type="time" name="pickup_time"
                        value="{{ old('pickup_time', $dayRentalPickupTime ?? '12:00') }}"
                        class="@error('pickup_time') is-invalid @enderror" required>
                </div>
                @error('pickup_time')
                    <span class="text-danger small">{{ $message }}</span>
                @enderror
            </div>

            <!-- Drop Off Date -->
            @php
                $dayRentalDropoffDate = $getDateForService('day_rental', false);
                $dayRentalDropoffTime = $getTimeForService('day_rental', false);
            @endphp
            <div class="single-search-box date-field">
                <div class="d-flex align-items-center gap-2 py-1">
                    <label class="input-label">Return Date</label>
                    <svg width="15" height="15" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                        <path
                            d="M15 2h-1V0h-2v2H6V0H4v2H3C1.89 2 1 2.89 1 4v12c0 1.1.89 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.11-.9-2-2-2zm0 14H3V7h12v9z" />
                    </svg>
                </div>
                <input type="text" name="dropoff_date" placeholder="DD/MM/YYYY"
                    class="custom-datepicker @error('dropoff_date') is-invalid @enderror"
                    value="{{ old('dropoff_date', $dayRentalDropoffDate ? date('d/m/Y', strtotime($dayRentalDropoffDate)) : date('d/m/Y')) }}"
                    required autocomplete="off">
                @error('dropoff_date')
                    <span class="text-danger small">{{ $message }}</span>
                @enderror
            </div>

            <div class="single-search-box">
                <div class="d-flex align-items-center gap-2 py-1">
                    <label class="input-label">Return Time</label>
                    <svg width="15" height="15" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                        <path
                            d="M9 0C4.03 0 0 4.03 0 9s4.03 9 9 9 9-4.03 9-9-4.03-9-9-9zm0 16c-3.87 0-7-3.13-7-7s3.13-7 7-7 7 3.13 7 7-3.13 7-7 7zm.5-12H8v5l4.25 2.52.75-1.23-3.5-2.08V4z" />
                    </svg>
                </div>
                <div class="custom-select-dropdown">
                    <input type="time" name="dropoff_time"
                        value="{{ old('dropoff_time', $dayRentalDropoffTime ?? '12:00') }}"
                        class="@error('dropoff_time') is-invalid @enderror" required>
                </div>
                @error('dropoff_time')
                    <span class="text-danger small">{{ $message }}</span>
                @enderror
            </div>

            <div class="package-selector" id="day_rental-packages" style="display: none;"
                data-selected="{{ old('package_id', $getSearchProp('service_package_id', '')) }}">
                <div class="transfer-type-toggle package-selector-toggle">
                    <!-- Packages will be dynamically loaded here as transfer-type-option labels -->
                </div>
                <div class="loading-packages" style="display: none;">
                    <span>Loading packages...</span>
                </div>
            </div>

            <button type="submit" class="primary-btn1">
                <span>Search For Vehicles</span>
            </button>
        </form>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize package loading for all service types
        initializeServicePackages();

        // Tab functionality with redirection for specific services
        const filterItems = document.querySelectorAll('.filter-item-list .single-item');
        const filterInputs = document.querySelectorAll('.filter-input');

        filterItems.forEach(item => {
            item.addEventListener('click', function(e) {
                e.preventDefault();

                const service = this.getAttribute('data-service');
                const redirectUrl = this.getAttribute('data-redirect');

                // If this tab has a redirect URL, navigate to it
                if (redirectUrl) {
                    window.location.href = redirectUrl;
                    return;
                }

                // Otherwise, show the corresponding form
                filterItems.forEach(filterItem => {
                    filterItem.classList.remove('active');
                });
                this.classList.add('active');

                filterInputs.forEach(input => {
                    input.classList.remove('show');
                });

                const targetForm = document.querySelector(`[data-service="${service}"]`);
                if (targetForm) {
                    targetForm.classList.add('show');

                    // Initialize airport transfer form if it's the airport service
                    if (service === 'airport_transfers') {
                        initializeAirportTransferForm();
                    }
                }
            });
        });

        // Initialize airport transfer form on page load if it's active
        const activeAirportForm = document.querySelector(
            '.filter-input[data-service="airport_transfers"].show');
        if (activeAirportForm) {
            // Wait longer for external JS to load
            setTimeout(() => {
                initializeAirportTransferForm();
            }, 500);
        }

        // Force initialization after a delay to ensure everything is loaded
        setTimeout(() => {
            const airportForm = document.querySelector('#airport_transfers-form');
            if (airportForm && airportForm.classList.contains('show')) {
                console.log('Force initializing airport form after delay');
                initializeAirportTransferForm();
            }
        }, 1000);

        // Also add event listeners for transfer type radio buttons
        const transferTypeRadios = document.querySelectorAll('input[name="transfer_type"]');
        transferTypeRadios.forEach(radio => {
            radio.addEventListener('change', function() {
                if (typeof window.updateAirportTransferLocations === 'function') {
                    window.updateAirportTransferLocations(this.value);
                } else {
                    initializeFallbackAirportForm(this.value);
                }
            });
        });

        // Ensure coordinates are properly set when forms are initialized
        setTimeout(function() {
            console.log('=== BLADE TEMPLATE COORDINATE INITIALIZATION ===');

            // Trigger coordinate updates for airport transfer form if visible
            const airportForm = document.getElementById('airport_transfers-form');
            if (airportForm && airportForm.classList.contains('show')) {
                const checkedRadio = airportForm.querySelector('input[name=\"transfer_type\"]:checked');
                if (checkedRadio) {
                    if (typeof window.updateAirportTransferLocations === 'function') {
                        window.updateAirportTransferLocations(checkedRadio.value);
                    } else {
                        initializeFallbackAirportForm(checkedRadio.value);
                    }
                }
            }

            // Force coordinate debug after initialization
            if (typeof window.logCoordinateValues === 'function') {
                window.logCoordinateValues();
            }
        }, 2000);

        /**
         * Initialize airport transfer form
         */
        function initializeAirportTransferForm() {
            console.log('Initializing airport transfer form...');

            setTimeout(() => {
                const transferTypeChecked = document.querySelector(
                    'input[name="transfer_type"]:checked');
                console.log('Checked transfer type:', transferTypeChecked ? transferTypeChecked.value :
                    'none');

                if (transferTypeChecked) {
                    // Call the function from booking-form.js if available
                    if (typeof window.updateAirportTransferLocations === 'function') {
                        console.log('Using external updateAirportTransferLocations function');
                        window.updateAirportTransferLocations(transferTypeChecked.value);
                    } else {
                        console.log('Using fallback initialization');
                        initializeFallbackAirportForm(transferTypeChecked.value);
                    }
                } else {
                    // Default to from-airport if no selection
                    const fromAirportRadio = document.querySelector(
                        'input[name="transfer_type"][value="from-airport"]');
                    console.log('No transfer type selected, defaulting to from-airport');
                    if (fromAirportRadio) {
                        fromAirportRadio.checked = true;
                        if (typeof window.updateAirportTransferLocations === 'function') {
                            console.log(
                                'Using external updateAirportTransferLocations function for default'
                            );
                            window.updateAirportTransferLocations('from-airport');
                        } else {
                            console.log('Using fallback initialization for default');
                            initializeFallbackAirportForm('from-airport');
                        }
                    }
                }
            }, 100);
        }

        /**
         * Fallback initialization if booking-form.js is not loaded
         */
        function initializeFallbackAirportForm(type) {
            const fromAirportSelect = document.querySelector('#from-airport-select');
            const fromLocationInput = document.querySelector('#from-location-input');
            const toAirportSelect = document.querySelector('#to-airport-select');
            const toLocationInput = document.querySelector('#to-location-input');

            if (!fromAirportSelect || !fromLocationInput || !toAirportSelect || !toLocationInput) return;

            console.log('Fallback initialization for type:', type);

            // Reset all fields first - hide all
            fromAirportSelect.classList.remove('visible');
            fromAirportSelect.classList.add('hidden');
            fromLocationInput.classList.remove('visible');
            fromLocationInput.classList.add('hidden');
            toLocationInput.classList.remove('visible');
            toLocationInput.classList.add('hidden');
            toAirportSelect.classList.remove('visible');
            toAirportSelect.classList.add('hidden');

            // Reset required and disabled states
            fromAirportSelect.required = false;
            fromLocationInput.required = false;
            toLocationInput.required = false;
            toAirportSelect.required = false;

            fromAirportSelect.disabled = true;
            fromLocationInput.disabled = true;
            toLocationInput.disabled = true;
            toAirportSelect.disabled = true;

            if (type === 'from-airport') {
                // FROM = Airport select, TO = Location input
                fromAirportSelect.classList.remove('hidden');
                fromAirportSelect.classList.add('visible');
                toLocationInput.classList.remove('hidden');
                toLocationInput.classList.add('visible');

                fromAirportSelect.required = true;
                toLocationInput.required = true;
                fromAirportSelect.disabled = false;
                toLocationInput.disabled = false;

                // Set default values ONLY if empty
                if (!fromAirportSelect.value || fromAirportSelect.value === '') {
                    fromAirportSelect.value = 'Colombo BIA Airport';
                    // Trigger change event to update coordinates
                    fromAirportSelect.dispatchEvent(new Event('change'));
                }
                // Don't set default for toLocationInput - let it stay empty or keep existing value
            } else {
                // FROM = Location input, TO = Airport select
                fromLocationInput.classList.remove('hidden');
                fromLocationInput.classList.add('visible');
                toAirportSelect.classList.remove('hidden');
                toAirportSelect.classList.add('visible');

                fromLocationInput.required = true;
                toAirportSelect.required = true;
                fromLocationInput.disabled = false;
                toAirportSelect.disabled = false;

                // Set default values ONLY if empty
                // Don't set default for fromLocationInput - let it stay empty or keep existing value
                if (!toAirportSelect.value || toAirportSelect.value === '') {
                    toAirportSelect.value = 'Colombo BIA Airport';
                    // Trigger change event to update coordinates
                    toAirportSelect.dispatchEvent(new Event('change'));
                }
            }

            console.log('Field visibility after initialization:', {
                fromAirportSelect: fromAirportSelect.classList.contains('visible'),
                fromLocationInput: fromLocationInput.classList.contains('visible'),
                toAirportSelect: toAirportSelect.classList.contains('visible'),
                toLocationInput: toLocationInput.classList.contains('visible')
            });

            // Setup airport select change handlers
            setupAirportSelectChangeHandlers();
        }

        /**
         * Setup airport select change handlers
         */
        function setupAirportSelectChangeHandlers() {
            const fromAirportSelect = document.querySelector('#from-airport-select');
            const toAirportSelect = document.querySelector('#to-airport-select');
            const fromLat = document.querySelector('input[name="pickup_lat"]');
            const fromLng = document.querySelector('input[name="pickup_lng"]');
            const toLat = document.querySelector('input[name="dropoff_lat"]');
            const toLng = document.querySelector('input[name="dropoff_lng"]');

            if (fromAirportSelect && !fromAirportSelect.hasChangeHandler) {
                fromAirportSelect.addEventListener('change', function() {
                    const selectedOption = this.options[this.selectedIndex];
                    if (selectedOption && selectedOption.dataset.lat && selectedOption.dataset.lng &&
                        fromLat && fromLng) {
                        fromLat.value = selectedOption.dataset.lat;
                        fromLng.value = selectedOption.dataset.lng;
                        console.log('Updated FROM coordinates:', fromLat.value, fromLng.value);
                    }
                });
                fromAirportSelect.hasChangeHandler = true;
            }

            if (toAirportSelect && !toAirportSelect.hasChangeHandler) {
                toAirportSelect.addEventListener('change', function() {
                    const selectedOption = this.options[this.selectedIndex];
                    if (selectedOption && selectedOption.dataset.lat && selectedOption.dataset.lng &&
                        toLat && toLng) {
                        toLat.value = selectedOption.dataset.lat;
                        toLng.value = selectedOption.dataset.lng;
                        console.log('Updated TO coordinates:', toLat.value, toLng.value);
                    }
                });
                toAirportSelect.hasChangeHandler = true;
            }
        }

        // Ensure the correct form tab is active on page load based on current service type
        document.addEventListener('DOMContentLoaded', function() {
            const activeService = document.querySelector('.filter-item.active');
            if (activeService) {
                const service = activeService.getAttribute('data-service');
                const targetForm = document.querySelector(`[data-service="${service}"]`);
                if (targetForm && !targetForm.classList.contains('show')) {
                    targetForm.classList.add('show');
                }
            }
        });

        // Add loading states to all form submissions
        const forms = document.querySelectorAll('.filter-input');

        forms.forEach(form => {
            form.addEventListener('submit', function(e) {
                // Generic client-side enforcement of booking advance hours
                try {
                    const minAllowed = computeMinAllowedDate();
                    let selectedDate = null;
                    let selectedTime = null;

                    if (form.id === 'airport_transfers-form') {
                        selectedDate = form.querySelector('input[name="date"]')?.value || null;
                        selectedTime = form.querySelector('input[name="time"]')?.value || null;
                    } else if (form.id === 'ride_now-form' || form.id === 'day_rental-form') {
                        selectedDate = form.querySelector(
                            '.custom-datepicker[name="pickup_date"]')?.value || null;
                        selectedTime = form.querySelector('input[name="pickup_time"]')?.value ||
                            null;
                    } else if (form.querySelector('.custom-datepicker[name="date"]')) {
                        selectedDate = form.querySelector('.custom-datepicker[name="date"]')
                            ?.value || null;
                        selectedTime = form.querySelector('input[name="time"]')?.value || null;
                    }

                    if (selectedDate && selectedTime && bookingAdvanceHours > 0) {
                        // Parse DD/MM/YYYY and HH:MM
                        const dateParts = selectedDate.split('/');
                        const d = new Date(Number(dateParts[2]), Number(dateParts[1]) - 1,
                            Number(dateParts[0]));
                        const timeParts = selectedTime.split(':');
                        d.setHours(Number(timeParts[0]), Number(timeParts[1]), 0, 0);

                        if (d < minAllowed) {
                            e.preventDefault();
                            alert(
                                `Bookings must be made at least ${bookingAdvanceHours} hours in advance. Please select a later date/time.`
                            );
                            return;
                        }
                    }
                } catch (err) {
                    console.warn('Advance hours check failed:', err);
                }

                // Special handling for airport transfer form
                if (form.getAttribute('data-service') === 'airport_transfers') {
                    if (!validateAirportTransferForm(form)) {
                        e.preventDefault();
                        return;
                    }
                    // Ensure only visible fields are enabled for submission
                    prepareAirportFormForSubmission(form);
                }

                const submitBtn = form.querySelector('button[type="submit"]');
                const btnText = submitBtn.querySelector('span');
                const originalText = btnText.textContent;

                // Prevent double submission
                if (submitBtn.disabled) {
                    e.preventDefault();
                    return;
                }

                // Add loading state
                submitBtn.disabled = true;
                submitBtn.style.opacity = '0.7';
                btnText.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Searching...';

                // If form submission takes too long, restore button (fallback)
                setTimeout(() => {
                    submitBtn.disabled = false;
                    submitBtn.style.opacity = '1';
                    btnText.textContent = originalText;
                }, 30000); // 30 seconds timeout
            });
        });

        /**
         * Validate airport transfer form before submission
         */
        function validateAirportTransferForm(form) {
            const transferType = form.querySelector('input[name="transfer_type"]:checked')?.value;
            if (!transferType) {
                alert('Please select transfer type (From Airport or To Airport)');
                return false;
            }

            // Check if the visible from field has a value
            const fromAirportSelect = form.querySelector('#from-airport-select');
            const fromLocationInput = form.querySelector('#from-location-input');

            if (transferType === 'from-airport') {
                if (!fromAirportSelect.value) {
                    alert('Please select an airport');
                    fromAirportSelect.focus();
                    return false;
                }
                if (!fromLocationInput.value || fromLocationInput.style.display !== 'none') {
                    // Make sure to location has a value
                    const toInput = form.querySelector('#to-location-input');
                    if (!toInput.value) {
                        alert('Please enter destination location');
                        toInput.focus();
                        return false;
                    }
                }
            } else {
                if (!fromLocationInput.value) {
                    alert('Please enter pickup location');
                    fromLocationInput.focus();
                    return false;
                }
                const toAirportSelect = form.querySelector('#to-airport-select');
                if (!toAirportSelect.value) {
                    alert('Please select destination airport');
                    toAirportSelect.focus();
                    return false;
                }
            }

            return true;
        }

        /**
         * Prepare airport form for submission by disabling hidden fields
         */
        function prepareAirportFormForSubmission(form) {
            const fromAirportSelect = form.querySelector('#from-airport-select');
            const fromLocationInput = form.querySelector('#from-location-input');
            const toAirportSelect = form.querySelector('#to-airport-select');
            const toLocationInput = form.querySelector('#to-location-input');

            // The fields should already be properly disabled/enabled by the initialization functions
            // Just make sure hidden fields are disabled and visible fields are enabled
            if (fromAirportSelect.classList.contains('hidden')) {
                fromAirportSelect.disabled = true;
                fromLocationInput.disabled = false; // Enable the visible one
            } else {
                fromAirportSelect.disabled = false; // Enable the visible one
                fromLocationInput.disabled = true;
            }

            if (toAirportSelect.classList.contains('hidden')) {
                toAirportSelect.disabled = true;
                toLocationInput.disabled = false; // Enable the visible one
            } else {
                toAirportSelect.disabled = false; // Enable the visible one
                toLocationInput.disabled = true;
            }

            console.log('Form prepared for submission:', {
                fromAirportSelectVisible: fromAirportSelect.classList.contains('visible'),
                fromLocationInputVisible: fromLocationInput.classList.contains('visible'),
                toAirportSelectVisible: toAirportSelect.classList.contains('visible'),
                toLocationInputVisible: toLocationInput.classList.contains('visible')
            });
        }

        /**
         * Initialize service package loading for all services
         */
        function initializeServicePackages() {
            const serviceTypes = ['airport_transfers', 'ride_now', 'point_to_point', 'day_rental'];

            serviceTypes.forEach(serviceType => {
                loadServicePackages(serviceType);
            });
        }

        /**
         * Load packages for a specific service type
         */
        function loadServicePackages(serviceType) {
            const packageSelector = document.getElementById(`${serviceType}-packages`);
            if (!packageSelector) return;

            const loadingIndicator = packageSelector.querySelector('.loading-packages');
            const packageOptions = packageSelector.querySelector('.package-options');

            // Show loading and reveal selector
            if (loadingIndicator) loadingIndicator.style.display = 'block';
            packageSelector.style.display = 'block';

            // Make API call to get packages
            fetch(`/api/services/${serviceType}/packages`)
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success' && data.data.packages && data.data.packages.length > 0) {
                        renderPackageOptions(packageSelector, data.data.packages, serviceType);
                    } else {
                        console.warn(`No packages found for service: ${serviceType}`);
                        // Hide selector if no packages available
                        packageSelector.style.display = 'none';
                    }
                })
                .catch(error => {
                    console.error(`Error loading packages for ${serviceType}:`, error);
                    // Hide selector on error
                    packageSelector.style.display = 'none';
                })
                .finally(() => {
                    // Hide loading
                    if (loadingIndicator) loadingIndicator.style.display = 'none';
                });
        }

        /**
         * Render package options in the UI with the same toggle styling and markup as the transfer-type toggle
         */
        function renderPackageOptions(packageSelector, packages, serviceType) {
            const packageToggle = packageSelector.querySelector('.package-selector-toggle');
            const selected = packageSelector.dataset.selected || '';

            if (!packageToggle || !packages || packages.length === 0) {
                console.warn(`No valid packages to render for ${serviceType}`);
                packageSelector.style.display = 'none';
                return;
            }

            // Clear existing package options
            packageToggle.innerHTML = '';

            // Add new packages using the transfer-type-option markup (hidden radio + span)
            packages.forEach((pkg, index) => {
                const label = document.createElement('label');
                label.className = 'transfer-type-option package-option';

                const input = document.createElement('input');
                input.type = 'radio';
                // Backend expects package_id
                input.name = 'package_id';
                input.value = pkg.id !== undefined ? pkg.id : (pkg.slug || pkg.code || pkg.name);
                input.id = `${serviceType}-package-${index}`;

                // If server or old input specified a selected package, mark it checked
                if (String(input.value) === String(selected)) {
                    input.checked = true;
                }

                // Default the first package if nothing selected
                if (!selected && index === 0) {
                    input.checked = true;
                }

                const span = document.createElement('span');
                span.title = pkg.description || '';
                span.textContent = pkg.name || pkg.title || pkg.label || (`Package ${index + 1}`);

                // Append nodes and attach change handler for debug and included-km update
                label.appendChild(input);
                label.appendChild(span);

                // Ensure there's a hidden input to carry package_included_km to backend
                let includedInput = document.getElementById(`${serviceType}-package-included-km`);
                if (!includedInput) {
                    includedInput = document.createElement('input');
                    includedInput.type = 'hidden';
                    includedInput.name = 'package_included_km';
                    includedInput.id = `${serviceType}-package-included-km`;
                    packageSelector.appendChild(includedInput);
                }

                // If this package is pre-checked, set included km now
                if (input.checked) {
                    includedInput.value = pkg.included_km !== undefined ? pkg.included_km : '';
                }

                input.addEventListener('change', function() {
                    console.log(`Package selected for ${serviceType}:`, this.value);
                    // Update included km hidden input if available on pkg
                    includedInput.value = pkg.included_km !== undefined ? pkg.included_km : '';
                });

                packageToggle.appendChild(label);
            });

            // Ensure selector is visible if packages were successfully rendered
            packageSelector.style.display = 'block';

            console.log(`Successfully rendered ${packages.length} packages for ${serviceType}`);
        }

        function formatDuration(hours) {
            if (hours < 24) {
                return `${hours}h`;
            } else if (hours % 24 === 0) {
                const days = hours / 24;
                return days === 1 ? '1 day' : `${days} days`;
            } else {
                const days = Math.floor(hours / 24);
                const remainingHours = hours % 24;
                return `${days}d ${remainingHours}h`;
            }
        }
    });
</script>

<style>
    /* Booking advance note style */
    .booking-advance-note {
        background-color: #fff3cd;
        /* Bootstrap warning bg */
        color: #856404;
        /* Bootstrap warning text */
        padding: 8px 12px;
        border-radius: 6px;
        margin-bottom: 12px;
        font-weight: 500;
    }

    /* Airport select dropdown styles */
    .airport-select {
        width: 100%;
        padding: 5px;
        border: 1px solid #e1e5e9;
        border-radius: 6px;
        background-color: #fff;
        font-size: 14px;
        font-family: inherit;
        color: #333;
        appearance: none;
        transition: border-color 0.3s ease;
    }

    .airport-select:focus {
        outline: none;
        border-color: #c91c23;
        box-shadow: 0 0 0 2px rgba(201, 28, 35, 0.1);
    }

    .airport-select:hover {
        border-color: #c91c23;
    }

    .airport-select.is-invalid {
        border-color: #dc3545;
    }

    .airport-select option {
        padding: 8px 12px;
        font-size: 14px;
    }

    /* Ensure consistent styling with other form inputs */
    .location-search,
    .airport-select {
        height: auto;
        min-height: 44px;
    }

    /* Hide/show transitions for smooth UX */
    .airport-select,
    .location-search {
        transition: all 0.2s ease-in-out;
    }

    /* Google Places / Autocomplete dropdown styling (override inline widths) */
    /* Make the suggestions wider than the input and responsive on small screens */
    .pac-container {
        width: auto !important;
        min-width: 360px !important;
        /* wider minimum so more text is visible */
        max-width: 360px !important;
        box-sizing: border-box !important;
        /* z-index: 99999 !important; */
        /* left: auto !important;
        right: auto !important; */
        overflow: hidden !important;
        border-radius: 8px !important;
        box-shadow: 0 6px 18px rgba(0, 0, 0, 0.12) !important;
    }

    /* On small screens, use almost full width and keep it readable */
    @media (max-width: 576px) {
        .pac-container {
            min-width: calc(100% - 24px) !important;
            width: calc(100% - 24px) !important;
            left: 12px !important;
            right: 12px !important;
            max-width: none !important;
        }
    }

    /* Field visibility classes */
    .from-field.hidden,
    .to-field.hidden {
        display: none !important;
    }

    .from-field.visible,
    .to-field.visible {
        display: block !important;
    }

    .package-selector-toggle {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }

    .package-option {
        display: flex;
        align-items: center;
        cursor: pointer;
    }

    .package-option input[type="radio"] {
        display: none;
    }

    .package-option span {
        display: inline-block;
        /* padding: 10px 20px; */
        width: 100%;
        border: 1px solid #ddd;
        border-radius: 6px;
        background-color: #fff;
        color: #333;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.3s ease;
        white-space: nowrap;
    }

    .package-option input[type="radio"]:checked+span {
        background-color: #c91c23;
        border-color: #c91c23;
        color: #fff;
        font-weight: 600;
    }

    /* Reuse transfer-type markup styling to make packages look exactly like the transfer-type toggle */
    .transfer-type-toggle {
        display: flex;
        /* display: grid; */
        gap: 10px;
        flex-wrap: nowrap;
        align-items: center;
    }

    .transfer-type-option input[type="radio"] {
        display: none;
    }

    .transfer-type-option span {
        display: inline-block;
        width: 100%;
        /* padding: 10px 20px; */
        border: 1px solid #ddd;
        border-radius: 6px;
        background-color: #fff;
        color: #333;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.3s ease;
        white-space: nowrap;
    }

    .transfer-type-option input[type="radio"]:checked+span {
        background-color: #c91c23;
        border-color: #c91c23;
        color: #fff;
        font-weight: 600;
    }

    .package-option:hover span {
        border-color: #c91c23;
    }

    .loading-packages {
        text-align: center;
        padding: 10px;
        color: #666;
        font-style: italic;
    }

    /* Return Trip Section Styles */
    .return-trip-section {
        width: 100%;
        margin-top: 15px;
        padding-top: 15px;
        border-top: 1px dashed #e1e5e9;
        grid-column: 1 / -1;
        /* Span full width of the grid */
    }

    .return-trip-toggle {
        display: flex;
        align-items: center;
    }

    .return-trip-checkbox-label {
        display: flex;
        align-items: center;
        cursor: pointer;
        font-size: 14px;
        font-weight: 500;
        color: #333;
        user-select: none;
    }

    .return-trip-checkbox-label input[type="checkbox"] {
        width: 18px;
        height: 18px;
        margin-right: 10px;
        accent-color: #c91c23;
        cursor: pointer;
    }

    .return-trip-text {
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .return-trip-text svg {
        color: #c91c23;
    }

    .return-trip-details {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 15px;
        margin-top: 15px;
        padding: 15px;
        background-color: #f8f9fa;
        border-radius: 8px;
        border: 1px solid #e1e5e9;
    }

    .return-route-summary {
        background: linear-gradient(135deg, #c91c23, #a01620);
        border-radius: 8px;
        padding: 12px 16px;
        margin-bottom: 10px;
    }

    .route-badge {
        display: flex;
        align-items: center;
        gap: 10px;
        color: #fff;
    }

    .route-badge svg {
        flex-shrink: 0;
    }

    .route-text {
        font-size: 14px;
        line-height: 1.4;
    }

    .route-text strong {
        font-weight: 600;
    }

    .return-pricing-info {
        grid-column: 1 / -1;
        margin-top: 10px;
    }

    .return-discount-badge {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        padding: 8px 16px;
        background: linear-gradient(135deg, #28a745, #20c997);
        border-radius: 20px;
        color: #fff;
        font-size: 13px;
    }

    .discount-label {
        font-weight: 500;
    }

    .discount-value {
        font-weight: 700;
        background: rgba(255, 255, 255, 0.2);
        padding: 2px 8px;
        border-radius: 10px;
    }

    @media (max-width: 576px) {
        .return-trip-details {
            grid-template-columns: 1fr;
        }
    }

    /* Ride Now form specific button styling */
    #ride_now-form .primary-btn1 {
        grid-column: 1 / span 1;
        /* Only take first column, not full width */
        justify-self: start;
        width: auto;
        min-width: 200px;
    }

    @media (max-width: 991px) {
        #ride_now-form .primary-btn1 {
            grid-column: 1 / -1;
            /* Full width on smaller screens */
            width: 100%;
        }
    }

    /* Return trip details - 4 column layout to match form */
    .return-trip-details {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 15px;
        margin-top: 15px;
        padding: 15px;
        background-color: #f8f9fa;
        border-radius: 8px;
        border: 1px solid #e1e5e9;
    }

    .return-trip-details .return-route-summary {
        grid-column: 1 / span 2;
        /* First two columns */
    }

    .return-trip-details .single-search-box {
        grid-column: auto;
        /* Each takes one column */
    }

    .return-trip-details .return-pricing-info {
        grid-column: 4 / span 1;
        /* Last column */
        display: flex;
        align-items: center;
        margin-top: 0;
    }

    @media (max-width: 991px) {
        .return-trip-details {
            grid-template-columns: repeat(2, 1fr);
        }

        .return-trip-details .return-route-summary {
            grid-column: 1 / -1;
        }

        .return-trip-details .return-pricing-info {
            grid-column: 1 / -1;
        }
    }

    @media (max-width: 576px) {
        .return-trip-details {
            grid-template-columns: 1fr;
        }

        .return-trip-details .return-route-summary,
        .return-trip-details .single-search-box,
        .return-trip-details .return-pricing-info {
            grid-column: 1 / -1;
        }
    }

    /* Mobile tab single-line layout and wrapping fixes */
    @media (max-width: 576px) {
        .filter-item-list {
            display: flex;
            gap: 8px;
            padding: 6px 8px;
            justify-content: space-between;
            align-items: center;
            width: 100%;
            box-sizing: border-box;
            overflow-x: auto;
            /* allow horizontal scroll if there are more items */
            -webkit-overflow-scrolling: touch;
        }

        .filter-item-list .single-item {
            padding: 6px 6px;
            min-height: auto;
            display: flex !important;
            flex-direction: column !important;
            align-items: center;
            justify-content: center;
            text-align: center;
            gap: 6px;
            flex: 1 1 0;
            /* allow items to shrink equally */
            min-width: 0;
            /* allow text to wrap and not force overflow */
            box-sizing: border-box;
        }

        .filter-item-list .single-item svg {
            width: 20px;
            height: 20px;
            flex-shrink: 0;
        }

        .filter-item-list .single-item span {
            white-space: normal !important;
            display: block !important;
            word-break: break-word !important;
            hyphens: auto !important;
            line-height: 1.05;
            font-size: 13px;
            padding: 0 4px;
        }

        /* Ensure transfer type / package toggles also wrap nicely */
        .transfer-type-toggle,
        .package-selector-toggle {
            gap: 8px;
        }

        .transfer-type-option span,
        .package-option span {
            white-space: normal;
        }
    }

    .filter-item-list .single-item span {
        white-space: normal !important;
        display: block !important;
        word-break: break-word !important;
        hyphens: auto !important;
        line-height: 1.1;
        font-size: 13px;
    }

    /* Ensure transfer type / package toggles also wrap nicely */
    .transfer-type-toggle,
    .package-selector-toggle {
        gap: 8px;
    }

    .transfer-type-option span,
    .package-option span {
        white-space: normal;
    }
</style>

@push('scripts')
    <!-- Bootstrap Datepicker CSS -->
    <link rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.9.0/css/bootstrap-datepicker.min.css">

    <!-- Bootstrap Datepicker JS -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.9.0/js/bootstrap-datepicker.min.js"></script>

    <script>
        $(document).ready(function() {
            // Booking minimum lead time (hours) provided by server-side setting
            const bookingAdvanceHours = {{ $bookingAdvanceHours }};

            // Compute the earliest allowed booking datetime based on advance hours
            function computeMinAllowedDate() {
                const d = new Date();
                if (bookingAdvanceHours && bookingAdvanceHours > 0) {
                    d.setHours(d.getHours() + bookingAdvanceHours);
                }
                d.setSeconds(0);
                d.setMilliseconds(0);
                return d;
            }

            // Initialize datepickers with startDate = date part of minAllowed
            const minAllowedGlobal = computeMinAllowedDate();
            $('.custom-datepicker').datepicker({
                format: 'dd/mm/yyyy',
                autoclose: true,
                todayHighlight: true,
                startDate: new Date(minAllowedGlobal.getFullYear(), minAllowedGlobal.getMonth(),
                    minAllowedGlobal.getDate()),
                orientation: 'bottom auto'
            });

            // Helper formatters
            function pad(n) {
                return String(n).padStart(2, '0');
            }

            function formatDateDDMMYYYY(d) {
                return `${pad(d.getDate())}/${pad(d.getMonth()+1)}/${d.getFullYear()}`;
            }

            function formatTimeHHMM(d) {
                return `${pad(d.getHours())}:${pad(d.getMinutes())}`;
            }

            // Ensure forms use the minimum allowed datetime where appropriate
            function applyAdvanceDefaults() {
                const min = computeMinAllowedDate();

                // Airport transfers (date + time)
                const airportForm = $('#airport_transfers-form');
                if (airportForm.length) {
                    const dateInput = airportForm.find('input[name="date"]');
                    const timeInput = airportForm.find('input[name="time"]');

                    if (dateInput.length && timeInput.length) {
                        // Set date to min if empty or earlier
                        const curDate = dateInput.val();
                        let setDate = false;
                        if (!curDate || !isValidDDMMYYYY(curDate)) setDate = true;
                        else {
                            const parts = curDate.split('/');
                            const cd = new Date(parts[2], parts[1] - 1, parts[0]);
                            if (cd < new Date(min.getFullYear(), min.getMonth(), min.getDate())) setDate = true;
                        }
                        if (setDate) {
                            dateInput.val(formatDateDDMMYYYY(min)).datepicker('update');
                        }

                        // Time defaults
                        if (!timeInput.val()) {
                            timeInput.val(formatTimeHHMM(min));
                        } else {
                            const selDateStr = dateInput.val();
                            if (isValidDDMMYYYY(selDateStr)) {
                                const parts = selDateStr.split('/');
                                const selDate = new Date(parts[2], parts[1] - 1, parts[0]);
                                const minDateOnly = new Date(min.getFullYear(), min.getMonth(), min.getDate());
                                if (selDate.getTime() === minDateOnly.getTime()) {
                                    if (timeInput.val() < formatTimeHHMM(min)) {
                                        timeInput.val(formatTimeHHMM(min));
                                    }
                                }
                            }
                        }

                        // Enforce min time attribute when same-day
                        timeInput.attr('min', formatTimeHHMM(min));
                    }
                }

                // Ride Now and Day Rental (pickup_date + pickup_time)
                ['#ride_now-form', '#day_rental-form'].forEach(selector => {
                    const form = $(selector);
                    if (!form.length) return;
                    const dateInput = form.find('.custom-datepicker[name="pickup_date"]');
                    const timeInput = form.find('input[name="pickup_time"]');
                    if (dateInput.length && timeInput.length) {
                        if (!dateInput.val() || !isValidDDMMYYYY(dateInput.val())) {
                            dateInput.val(formatDateDDMMYYYY(min)).datepicker('update');
                        } else {
                            const parts = dateInput.val().split('/');
                            const cd = new Date(parts[2], parts[1] - 1, parts[0]);
                            if (cd < new Date(min.getFullYear(), min.getMonth(), min.getDate())) {
                                dateInput.val(formatDateDDMMYYYY(min)).datepicker('update');
                            }
                        }

                        if (!timeInput.val()) {
                            timeInput.val(formatTimeHHMM(min));
                        } else {
                            const parts = dateInput.val().split('/');
                            const cd = new Date(parts[2], parts[1] - 1, parts[0]);
                            const minDateOnly = new Date(min.getFullYear(), min.getMonth(), min.getDate());
                            if (cd.getTime() === minDateOnly.getTime()) {
                                if (timeInput.val() < formatTimeHHMM(min)) {
                                    timeInput.val(formatTimeHHMM(min));
                                }
                                timeInput.attr('min', formatTimeHHMM(min));
                            } else {
                                timeInput.removeAttr('min');
                            }
                        }
                    }
                });
            }

            // Re-apply defaults on load
            applyAdvanceDefaults();

            // Re-check whenever the date/time inputs change
            $(document).on('change', '.custom-datepicker, input[type="time"]', function() {
                applyAdvanceDefaults();
            });

            // On datepicker open, update its startDate in case settings changed
            $(document).on('show', '.custom-datepicker', function() {
                const min = computeMinAllowedDate();
                $(this).datepicker('setStartDate', new Date(min.getFullYear(), min.getMonth(), min
                    .getDate()));
            });

            $('.custom-datepicker').on('input', function() {
                let value = $(this).val();

                value = value.replace(/[^\d\/]/g, '');

                if (value.length === 2 && !value.includes('/')) {
                    value += '/';
                } else if (value.length === 5 && value.split('/').length === 2) {
                    value += '/';
                }

                if (value.length > 10) {
                    value = value.substring(0, 10);
                }

                $(this).val(value);
            });

            $('.custom-datepicker').on('blur', function() {
                let value = $(this).val();
                if (value && !isValidDDMMYYYY(value)) {
                    $(this).addClass('is-invalid');
                    $(this).siblings('.invalid-feedback').remove();
                    $(this).after(
                        '<div class="invalid-feedback">Please enter date in DD/MM/YYYY format</div>');
                } else {
                    $(this).removeClass('is-invalid');
                    $(this).siblings('.invalid-feedback').remove();
                }
            });

            function isValidDDMMYYYY(dateString) {
                const regex = /^\d{2}\/\d{2}\/\d{4}$/;
                if (!regex.test(dateString)) return false;

                const parts = dateString.split('/');
                const day = parseInt(parts[0], 10);
                const month = parseInt(parts[1], 10);
                const year = parseInt(parts[2], 10);

                const date = new Date(year, month - 1, day);
                return date.getFullYear() === year &&
                    date.getMonth() === (month - 1) &&
                    date.getDate() === day &&
                    date >= new Date().setHours(0, 0, 0, 0);
            }

            $('.custom-datepicker[name="dropoff_date"]').on('change', function() {
                const pickupDate = $('.custom-datepicker[name="pickup_date"]').val();
                const dropoffDate = $(this).val();

                if (pickupDate && dropoffDate && isValidDDMMYYYY(pickupDate) && isValidDDMMYYYY(
                        dropoffDate)) {
                    const pickup = parseDate(pickupDate);
                    const dropoff = parseDate(dropoffDate);

                    // Allow same day rental - dropoff can be same as or after pickup
                    if (dropoff < pickup) {
                        $(this).addClass('is-invalid');
                        $(this).siblings('.invalid-feedback').remove();
                        $(this).after(
                            '<div class="invalid-feedback">Drop-off date must be on or after pickup date (same-day rental allowed)</div>'
                        );
                    } else {
                        $(this).removeClass('is-invalid');
                        $(this).siblings('.invalid-feedback').remove();
                    }
                }
            });

            // Parse DD/MM/YYYY to Date object
            function parseDate(dateString) {
                const parts = dateString.split('/');
                return new Date(parts[2], parts[1] - 1, parts[0]);
            }

            // Format Date object to DD/MM/YYYY
            function formatDate(date) {
                const day = String(date.getDate()).padStart(2, '0');
                const month = String(date.getMonth() + 1).padStart(2, '0');
                const year = date.getFullYear();
                return `${day}/${month}/${year}`;
            }

            // Auto-update dropoff_date when pickup_date changes in day_rental form
            $('#day_rental-form .custom-datepicker[name="pickup_date"]').on('changeDate change', function() {
                const pickupDateStr = $(this).val();
                if (!pickupDateStr || !isValidDDMMYYYY(pickupDateStr)) return;

                const pickupDate = parseDate(pickupDateStr);
                const dropoffInput = $('#day_rental-form .custom-datepicker[name="dropoff_date"]');
                const dropoffDateStr = dropoffInput.val();

                // If dropoff_date is empty or is before pickup_date, set it to same day (same-day rental)
                if (!dropoffDateStr || !isValidDDMMYYYY(dropoffDateStr)) {
                    // Default to same day for same-day rental
                    dropoffInput.val(formatDate(pickupDate));
                    dropoffInput.datepicker('update');
                } else {
                    const dropoffDate = parseDate(dropoffDateStr);
                    // Only update if dropoff is before pickup (allow same day)
                    if (dropoffDate < pickupDate) {
                        dropoffInput.val(formatDate(pickupDate));
                        dropoffInput.datepicker('update');
                    }
                }

                // Update the minimum date for dropoff datepicker to allow same day
                dropoffInput.datepicker('setStartDate', pickupDate);
            });

            // Initialize dropoff_date min date based on current pickup_date value
            (function initDropoffMinDate() {
                const pickupDateStr = $('#day_rental-form .custom-datepicker[name="pickup_date"]').val();
                if (pickupDateStr && isValidDDMMYYYY(pickupDateStr)) {
                    const pickupDate = parseDate(pickupDateStr);
                    // Allow same day rental
                    $('#day_rental-form .custom-datepicker[name="dropoff_date"]').datepicker('setStartDate',
                        pickupDate);
                }
            })();
        });

        // Debug coordinate values on page load and form interactions
        function debugCoordinates() {
            console.log('=== COORDINATE DEBUG INFO ===');
            const forms = ['airport_transfers-form', 'ride_now-form', 'day_rental-form', 'point_to_point-form'];

            forms.forEach(formId => {
                const form = document.getElementById(formId);
                if (form) {
                    const fromLat = form.querySelector('input[name="pickup_lat"]');
                    const fromLng = form.querySelector('input[name="pickup_lng"]');
                    const toLat = form.querySelector('input[name="pickup_lng"]');
                    const toLng = form.querySelector('input[name="pickup_lat"]');
                    // const toLat = form.querySelector('input[name="dropoff_lat"]');
                    // const toLng = form.querySelector('input[name="dropoff_lng"]');

                    console.log(`${formId}:`, {
                        fromLat: fromLat ? fromLat.value : 'NOT FOUND',
                        fromLng: fromLng ? fromLng.value : 'NOT FOUND',
                        toLat: toLat ? toLat.value : 'NOT FOUND',
                        toLng: toLng ? toLng.value : 'NOT FOUND',
                        formVisible: !form.classList.contains('hidden') && form.offsetHeight > 0
                    });

                    // Check airport selects for airport transfer form
                    if (formId === 'airport_transfers-form') {
                        const fromAirport = form.querySelector('#from-airport-select');
                        const toAirport = form.querySelector('#to-airport-select');

                        if (fromAirport) {
                            const selectedFromOption = fromAirport.options[fromAirport.selectedIndex];
                            console.log('From Airport Select:', {
                                value: fromAirport.value,
                                visible: !fromAirport.classList.contains('hidden'),
                                selectedOption: selectedFromOption ? {
                                    value: selectedFromOption.value,
                                    lat: selectedFromOption.dataset.lat,
                                    lng: selectedFromOption.dataset.lng
                                } : 'none'
                            });
                        }

                        if (toAirport) {
                            const selectedToOption = toAirport.options[toAirport.selectedIndex];
                            console.log('To Airport Select:', {
                                value: toAirport.value,
                                visible: !toAirport.classList.contains('hidden'),
                                selectedOption: selectedToOption ? {
                                    value: selectedToOption.value,
                                    lat: selectedToOption.dataset.lat,
                                    lng: selectedToOption.dataset.lng
                                } : 'none'
                            });
                        }
                    }
                }
            });
        }

        // Run debug on page load
        $(document).ready(function() {
            setTimeout(debugCoordinates, 1000);
            setTimeout(debugCoordinates, 3000); // Run again after everything loads

            // Adjust Google Places suggestion dropdown width when location inputs are focused
            function adjustPacForInput(input) {
                setTimeout(function() {
                    const pac = document.querySelector('.pac-container');
                    if (!pac) return;

                    // Prefer aligning the dropdown with the main filter wrapper on wide viewports
                    const container = document.querySelector('.filter-wrapper') || document.querySelector(
                        '.container');
                    let targetWidth = Math.min(container ? container.offsetWidth - 40 : window.innerWidth -
                        24, 900);

                    // On small screens, make it nearly full width
                    if (window.innerWidth <= 576) {
                        pac.style.left = '12px';
                        pac.style.width = `calc(100% - 24px)`;
                        pac.style.maxWidth = 'none';
                    } else {
                        // Position it horizontally centered under the filter wrapper
                        if (container) {
                            const rect = container.getBoundingClientRect();
                            pac.style.left = (rect.left + 20) + 'px';
                        } else {
                            pac.style.left = (input.getBoundingClientRect().left) + 'px';
                        }
                        pac.style.width = targetWidth + 'px';
                        pac.style.maxWidth = '900px';
                    }

                    pac.style.boxSizing = 'border-box';
                    pac.style.zIndex = 99999;
                }, 80);
            }

            // Bind to location inputs
            document.querySelectorAll('input.location-search').forEach(function(el) {
                el.addEventListener('focus', function() {
                    adjustPacForInput(el);
                });
                el.addEventListener('input', function() {
                    adjustPacForInput(el);
                });
                // When the window is resized, readjust
                window.addEventListener('resize', function() {
                    if (document.activeElement === el) adjustPacForInput(el);
                });
            });
        });

        // Add coordinate debugging to form submissions
        $('form').on('submit', function(e) {
            console.log('=== FORM SUBMISSION COORDINATE CHECK ===');
            debugCoordinates();

            const form = this;
            const formId = form.id;
            let hasValidCoordinates = false;

            // Check coordinates based on form type
            if (formId === 'airport_transfers-form') {
                const fromLat = form.querySelector('input[name="pickup_lat"]');
                const fromLng = form.querySelector('input[name="pickup_lng"]');
                const toLat = form.querySelector('input[name="dropoff_lat"]');
                const toLng = form.querySelector('input[name="dropoff_lng"]');

                hasValidCoordinates = (fromLat && fromLat.value && fromLng && fromLng.value &&
                    toLat && toLat.value && toLng && toLng.value);

                console.log('Airport Transfer coordinates check:', {
                    fromLat: fromLat?.value,
                    fromLng: fromLng?.value,
                    toLat: toLat?.value,
                    toLng: toLng?.value
                });
            } else if ((formId === 'ride_now-form' || formId === 'day_rental-form' ||
                    formId === 'day_rental-form')) {
                const pickupLat = form.querySelector('input[name="pickup_lat"]');
                const pickupLng = form.querySelector('input[name="pickup_lng"]');
                const dropoffLat = form.querySelector('input[name="dropoff_lat"]');
                const dropoffLng = form.querySelector('input[name="dropoff_lng"]');

                hasValidCoordinates = (pickupLat && pickupLat.value && pickupLng && pickupLng.value &&
                    dropoffLat && dropoffLat.value && dropoffLng && dropoffLng.value);

                console.log('Ride Now coordinates check:', {
                    pickupLat: pickupLat?.value,
                    pickupLng: pickupLng?.value,
                    dropoffLat: dropoffLat?.value,
                    dropoffLng: dropoffLng?.value
                });
            }

            if (!hasValidCoordinates) {
                console.warn('WARNING: Some coordinates are missing for form:', formId);
                // Still allow submission but log the warning
            } else {
                console.log('✓ All coordinates present for submission of form:', formId);
            }
        });
    </script>
@endpush
