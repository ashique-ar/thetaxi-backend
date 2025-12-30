@php
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
@endphp

<div class="filter-wrapper">
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
            <span>Ride Now</span>
        </li>

        <li class="single-item {{ $currentServiceType === 'day_rental' ? 'active' : '' }}" data-service="day_rental">
            <svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path
                    d="M19 3h-1V1h-2v2H8V1H6v2H5c-1.11 0-1.99.9-1.99 2L3 19c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V8h14v11zM9 10H7v2h2v-2zm4 0h-2v2h2v-2zm4 0h-2v2h2v-2zm-8 4H7v2h2v-2zm4 0h-2v2h2v-2zm4 0h-2v2h2v-2z" />
            </svg>
            <span>Day Rental</span>
        </li>

        {{-- <li class="single-item {{ $currentServiceType === 'point_to_point' ? 'active' : '' }}" data-service="point_to_point" data-redirect="{{ route('point-to-point') }}">
            <svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path
                    d="M18.92 6.01C18.72 5.42 18.16 5 17.5 5h-11c-.66 0-1.21.42-1.42 1.01L3 12v8c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-1h12v1c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-8l-2.08-5.99zM6.5 16c-.83 0-1.5-.67-1.5-1.5S5.67 13 6.5 13s1.5.67 1.5 1.5S7.33 16 6.5 16zm11 0c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zM5 11l1.5-4.5h11L19 11H5z" />
            </svg>
            <span>Point to Point</span>
        </li> --}}
        <li class="single-item {{ $currentServiceType === 'corporate_transport' ? 'active' : '' }}"
            data-service="corporate_transport" data-redirect="{{ route('corporate-transfers') }}">
            <svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path
                    d="M12 7V3H2v18h20V7H12zM6 19H4v-2h2v2zm0-4H4v-2h2v2zm0-4H4V9h2v2zm0-4H4V5h2v2zm4 12H8v-2h2v2zm0-4H8v-2h2v2zm0-4H8V9h2v2zm0-4H8V5h2v2zm10 12h-8v-2h2v-2h-2v-2h2v-2h-2V9h8v10zm-2-8h-2v2h2v-2zm0 4h-2v2h2v-2z" />
            </svg>
            <span>Corporate Transport</span>
        </li>
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

            <!-- Transfer Type Selection - Compact Toggle Style -->
            <div class="transfer-type-selector">
                <div class="transfer-type-toggle">
                    <label class="transfer-type-option">
                        <input type="radio" name="transfer_type" value="from-airport"
                            {{ old('transfer_type', 'from-airport') === 'from-airport' ? 'checked' : '' }}>
                        <span>From Airport</span>
                    </label>
                    <label class="transfer-type-option">
                        <input type="radio" name="transfer_type" value="to-airport"
                            {{ old('transfer_type') === 'to-airport' ? 'checked' : '' }}>
                        <span>To Airport</span>
                    </label>
                </div>
                @error('transfer_type')
                    <span class="text-danger small">{{ $message }}</span>
                @enderror
            </div>

            <!-- Package Selection (uses same toggle design as transfer-type) -->
            {{-- <div class="package-selector" id="airport_transfers-packages" style="display: none;"
                data-selected="{{ old('package_id', isset($search) && isset($search->package_id) ? $search->package_id : '') }}">
                <div class="transfer-type-toggle package-selector-toggle">
                    <!-- Packages will be dynamically loaded here as transfer-type-option labels -->
                </div>
                <div class="loading-packages" style="display: none;">
                    <span>Loading packages...</span>
                </div>
            </div> --}}

            <!-- From Location -->
            <div class="single-search-box from-location location-search-box">
                <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                    <g>
                        <path
                            d="M12.5944 8.99987C12.5944 10.988 10.9826 12.5998 8.99443 12.5998C7.00627 12.5998 5.39465 10.988 5.39465 8.99987C5.39465 7.0117 7.00627 5.40009 8.99443 5.40009C10.9826 5.40009 12.5944 7.0117 12.5944 8.99987Z" />
                        <path
                            d="M17.4601 8.4599H16.2564C15.9858 4.86535 13.1291 2.00812 9.53458 1.7372V0.539976C9.53458 0.241723 9.29268 0 8.9946 0C8.69635 0 8.45462 0.241723 8.45462 0.539976V1.7372C4.85986 2.00812 2.00297 4.86535 1.73235 8.4599H0.540018C0.241723 8.4599 0 8.7017 0 8.99987C0 9.29813 0.241723 9.53985 0.539976 9.53985H1.73239C2.00297 13.1344 4.85991 15.9916 8.45441 16.2625V17.4601C8.45441 17.7583 8.69614 18 8.99439 18C9.29251 18 9.53428 17.7583 9.53428 17.4601V16.2625C13.1289 15.9918 15.9858 13.1346 16.2564 9.53985H17.4601C17.7583 9.53985 18 9.29813 18 8.99987C18 8.70175 17.7583 8.4599 17.4601 8.4599ZM8.99443 15.2096C5.56504 15.2094 2.78509 12.4291 2.78509 8.9997C2.78522 5.57014 5.56554 2.7902 8.99494 2.7902C12.4245 2.7902 15.2046 5.57048 15.2046 8.99987C15.2005 12.428 12.4225 15.2058 8.99443 15.2096Z" />
                    </g>
                </svg>
                <div class="custom-select-dropdown">
                    <!-- Airport Select (shown when from-airport is selected) -->
                    <select name="from" id="from-airport-select"
                        class="airport-select from-field hidden @error('from') is-invalid @enderror" disabled>
                        <option value="">Select Airport</option>
                        <option value="Colombo BIA Airport" data-lat="7.1808" data-lng="79.8841"
                            {{ old('from', isset($search) && isset($search->pickup_location) ? $search->pickup_location : 'Colombo BIA Airport') == 'Colombo BIA Airport' ? 'selected' : '' }}>
                            Bandaranaike International Airport (BIA)
                        </option>
                        <option value="Mattala Rajapaksa Airport" data-lat="6.2847" data-lng="81.1242"
                            {{ old('from', isset($search) && isset($search->pickup_location) ? $search->pickup_location : '') == 'Mattala Rajapaksa Airport' ? 'selected' : '' }}>
                            Mattala Rajapaksa International Airport
                        </option>
                        <option value="Jaffna International Airport" data-lat="9.7923" data-lng="80.0701"
                            {{ old('from', isset($search) && isset($search->pickup_location) ? $search->pickup_location : '') == 'Jaffna International Airport' ? 'selected' : '' }}>
                            Jaffna International Airport
                        </option>
                    </select>

                    <!-- Location Input (shown when to-airport is selected) -->
                    <input type="text" name="from" id="from-location-input" placeholder="Enter pickup location"
                        class="location-search from-field hidden @error('from') is-invalid @enderror"
                        value="{{ old('from', isset($search) && isset($search->pickup_location) ? $search->pickup_location : 'Colombo, Sri Lanka') }}"
                        disabled>

                    <input type="hidden" name="pickup_lat" class="location-lat"
                        value="{{ old('pickup_lat', isset($search) && isset($search->pickup_latitude) ? $search->pickup_latitude : '7.1808') }}">
                    <input type="hidden" name="pickup_lng" class="location-lng"
                        value="{{ old('pickup_lng', isset($search) && isset($search->pickup_longitude) ? $search->pickup_longitude : '79.8841') }}">
                </div>
                @error('from')
                    <span class="text-danger small">{{ $message }}</span>
                @enderror
            </div>

            <!-- To Location -->
            <div class="single-search-box to-location location-search-box">
                <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                    <g>
                        <path
                            d="M12.5944 8.99987C12.5944 10.988 10.9826 12.5998 8.99443 12.5998C7.00627 12.5998 5.39465 10.988 5.39465 8.99987C5.39465 7.0117 7.00627 5.40009 8.99443 5.40009C10.9826 5.40009 12.5944 7.0117 12.5944 8.99987Z" />
                        <path
                            d="M17.4601 8.4599H16.2564C15.9858 4.86535 13.1291 2.00812 9.53458 1.7372V0.539976C9.53458 0.241723 9.29268 0 8.9946 0C8.69635 0 8.45462 0.241723 8.45462 0.539976V1.7372C4.85986 2.00812 2.00297 4.86535 1.73235 8.4599H0.540018C0.241723 8.4599 0 8.7017 0 8.99987C0 9.29813 0.241723 9.53985 0.539976 9.53985H1.73239C2.00297 13.1344 4.85991 15.9916 8.45441 16.2625V17.4601C8.45441 17.7583 8.69614 18 8.99439 18C9.29251 18 9.53428 17.7583 9.53428 17.4601V16.2625C13.1289 15.9918 15.9858 13.1346 16.2564 9.53985H17.4601C17.7583 9.53985 18 9.29813 18 8.99987C18 8.70175 17.7583 8.4599 17.4601 8.4599ZM8.99443 15.2096C5.56504 15.2094 2.78509 12.4291 2.78509 8.9997C2.78522 5.57014 5.56554 2.7902 8.99494 2.7902C12.4245 2.7902 15.2046 5.57048 15.2046 8.99987C15.2005 12.428 12.4225 15.2058 8.99443 15.2096Z" />
                    </g>
                </svg>
                <div class="custom-select-dropdown">
                    <!-- Location Input (shown when from-airport is selected) -->
                    <input type="text" name="to" id="to-location-input" placeholder="Enter destination"
                        class="location-search to-field hidden @error('to') is-invalid @enderror"
                        value="{{ old('to', isset($search) && isset($search->dropoff_location) ? $search->dropoff_location : 'Colombo, Sri Lanka') }}"
                        disabled>

                    <!-- Airport Select (shown when to-airport is selected) -->
                    <select name="to" id="to-airport-select"
                        class="airport-select to-field hidden @error('to') is-invalid @enderror" disabled>
                        <option value="">Select Airport</option>
                        <option value="Colombo BIA Airport" data-lat="7.1808" data-lng="79.8841"
                            {{ old('to', isset($search) && isset($search->dropoff_location) ? $search->dropoff_location : '') == 'Colombo BIA Airport' ? 'selected' : '' }}>
                            Bandaranaike International Airport (BIA)
                        </option>
                        <option value="Mattala Rajapaksa Airport" data-lat="6.2847" data-lng="81.1242"
                            {{ old('to', isset($search) && isset($search->dropoff_location) ? $search->dropoff_location : '') == 'Mattala Rajapaksa Airport' ? 'selected' : '' }}>
                            Mattala Rajapaksa International Airport
                        </option>
                        <option value="Jaffna International Airport" data-lat="9.7923" data-lng="80.0701"
                            {{ old('to', isset($search) && isset($search->dropoff_location) ? $search->dropoff_location : '') == 'Jaffna International Airport' ? 'selected' : '' }}>
                            Jaffna International Airport
                        </option>
                    </select>

                    <input type="hidden" name="dropoff_lat" class="location-lat"
                        value="{{ old('dropoff_lat', isset($search) && isset($search->dropoff_latitude) ? $search->dropoff_latitude : '6.9271') }}">
                    <input type="hidden" name="dropoff_lng" class="location-lng"
                        value="{{ old('dropoff_lng', isset($search) && isset($search->dropoff_longitude) ? $search->dropoff_longitude : '79.8612') }}">
                </div>
                @error('to')
                    <span class="text-danger small">{{ $message }}</span>
                @enderror
            </div>

            <!-- Date -->
            <div class="single-search-box date-field">
                <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                    <path
                        d="M15 2h-1V0h-2v2H6V0H4v2H3C1.89 2 1 2.89 1 4v12c0 1.1.89 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.11-.9-2-2-2zm0 14H3V7h12v9z" />
                </svg>
                <input type="text" name="date" placeholder="DD/MM/YYYY"
                    class="custom-datepicker @error('date') is-invalid @enderror"
                    value="{{ old('date', isset($search) && isset($search->from_date) && $search->from_date ? date('d/m/Y', strtotime($search->from_date)) : date('d/m/Y')) }}"
                    required autocomplete="off">
                @error('date')
                    <span class="text-danger small">{{ $message }}</span>
                @enderror
            </div>

            <!-- Time -->
            <div class="single-search-box">
                <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                    <path
                        d="M9 0C4.03 0 0 4.03 0 9s4.03 9 9 9 9-4.03 9-9-4.03-9-9-9zm0 16c-3.87 0-7-3.13-7-7s3.13-7 7-7 7 3.13 7 7-3.13 7-7 7zm.5-12H8v5l4.25 2.52.75-1.23-3.5-2.08V4z" />
                </svg>
                <div class="custom-select-dropdown">
                    <input type="time" name="time"
                        value="{{ old('time', isset($search) && isset($search->from_time) ? $search->from_time : '12:00') }}"
                        class="@error('time') is-invalid @enderror" required>
                </div>
                @error('time')
                    <span class="text-danger small">{{ $message }}</span>
                @enderror
            </div>

            <!-- Passengers -->
            {{-- <div class="single-search-box">
                <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
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
        <form id="ride_nows-form" class="filter-input {{ $currentServiceType === 'ride_now' ? 'show' : '' }}"
            data-service="ride_now" action="{{ route('booking.search') }}" method="GET">
            <input type="hidden" name="service_type" value="ride_now">

            <!-- Pickup Location -->
            <div class="single-search-box location-search-box">
                <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                    <g>
                        <path
                            d="M12.5944 8.99987C12.5944 10.988 10.9826 12.5998 8.99443 12.5998C7.00627 12.5998 5.39465 10.988 5.39465 8.99987C5.39465 7.0117 7.00627 5.40009 8.99443 5.40009C10.9826 5.40009 12.5944 7.0117 12.5944 8.99987Z" />
                        <path
                            d="M17.4601 8.4599H16.2564C15.9858 4.86535 13.1291 2.00812 9.53458 1.7372V0.539976C9.53458 0.241723 9.29268 0 8.9946 0C8.69635 0 8.45462 0.241723 8.45462 0.539976V1.7372C4.85986 2.00812 2.00297 4.86535 1.73235 8.4599H0.540018C0.241723 8.4599 0 8.7017 0 8.99987C0 9.29813 0.241723 9.53985 0.539976 9.53985H1.73239C2.00297 13.1344 4.85991 15.9916 8.45441 16.2625V17.4601C8.45441 17.7583 8.69614 18 8.99439 18C9.29251 18 9.53428 17.7583 9.53428 17.4601V16.2625C13.1289 15.9918 15.9858 13.1346 16.2564 9.53985H17.4601C17.7583 9.53985 18 9.29813 18 8.99987C18 8.70175 17.7583 8.4599 17.4601 8.4599ZM8.99443 15.2096C5.56504 15.2094 2.78509 12.4291 2.78509 8.9997C2.78522 5.57014 5.56554 2.7902 8.99494 2.7902C12.4245 2.7902 15.2046 5.57048 15.2046 8.99987C15.2005 12.428 12.4225 15.2058 8.99443 15.2096Z" />
                    </g>
                </svg>
                <div class="custom-select-dropdown">
                    <input type="text" name="pickup" placeholder="Pick up Location"
                        class="location-search @error('pickup') is-invalid @enderror"
                        value="{{ old('pickup', isset($search) && isset($search->pickup_location) ? $search->pickup_location : 'Colombo, Sri Lanka') }}"
                        required>
                    <input type="hidden" name="pickup_lat" class="location-lat"
                        value="{{ old('pickup_lat', isset($search) && isset($search->pickup_latitude) ? $search->pickup_latitude : '6.9271') }}">
                    <input type="hidden" name="pickup_lng" class="location-lng"
                        value="{{ old('pickup_lng', isset($search) && isset($search->pickup_longitude) ? $search->pickup_longitude : '79.8612') }}">
                </div>
                @error('pickup')
                    <span class="text-danger small">{{ $message }}</span>
                @enderror
            </div>

            <!-- Drop Off Location -->
            <div class="single-search-box location-search-box">
                <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
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
                        value="{{ old('dropoff', isset($search) && isset($search->dropoff_location) ? $search->dropoff_location : 'Galle, Sri Lanka') }}"
                        required>
                    <input type="hidden" name="dropoff_lat" class="location-lat"
                        value="{{ old('dropoff_lat', isset($search) && isset($search->dropoff_latitude) ? $search->dropoff_latitude : '6.0535') }}">
                    <input type="hidden" name="dropoff_lng" class="location-lng"
                        value="{{ old('dropoff_lng', isset($search) && isset($search->dropoff_longitude) ? $search->dropoff_longitude : '80.221') }}">
                </div>
                @error('dropoff')
                    <span class="text-danger small">{{ $message }}</span>
                @enderror
            </div>

            <!-- Pickup Date -->
            <div class="single-search-box date-field">
                <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                    <path
                        d="M15 2h-1V0h-2v2H6V0H4v2H3C1.89 2 1 2.89 1 4v12c0 1.1.89 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.11-.9-2-2-2zm0 14H3V7h12v9z" />
                </svg>
                <input type="text" name="pickup_date" placeholder="DD/MM/YYYY"
                    class="custom-datepicker @error('pickup_date') is-invalid @enderror"
                    value="{{ old('pickup_date', isset($search) && isset($search->from_date) && $search->from_date ? date('d/m/Y', strtotime($search->from_date)) : date('d/m/Y')) }}"
                    required autocomplete="off">
                @error('pickup_date')
                    <span class="text-danger small">{{ $message }}</span>
                @enderror
            </div>

            <!-- Pickup Time -->
            <div class="single-search-box">
                <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                    <path
                        d="M9 0C4.03 0 0 4.03 0 9s4.03 9 9 9 9-4.03 9-9-4.03-9-9-9zm0 16c-3.87 0-7-3.13-7-7s3.13-7 7-7 7 3.13 7 7-3.13 7-7 7zm.5-12H8v5l4.25 2.52.75-1.23-3.5-2.08V4z" />
                </svg>
                <div class="custom-select-dropdown">
                    <input type="time" name="pickup_time"
                        value="{{ old('pickup_time', isset($search) && isset($search->from_time) ? $search->from_time : '12:00') }}"
                        class="@error('pickup_time') is-invalid @enderror" required>
                </div>
                @error('pickup_time')
                    <span class="text-danger small">{{ $message }}</span>
                @enderror
            </div>

            <!-- Drop Off Date -->
            {{-- <div class="single-search-box date-field">
                <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                    <path
                        d="M15 2h-1V0h-2v2H6V0H4v2H3C1.89 2 1 2.89 1 4v12c0 1.1.89 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.11-.9-2-2-2zm0 14H3V7h12v9z" />
                </svg>
                <input type="text" name="dropoff_date" placeholder="DD/MM/YYYY"
                    class="custom-datepicker @error('dropoff_date') is-invalid @enderror"
                    value="{{ old('dropoff_date', isset($search) && isset($search->to_date) && $search->to_date ? date('d/m/Y', strtotime($search->to_date)) : date('d/m/Y', strtotime('+3 days'))) }}"
                    required autocomplete="off">
                @error('dropoff_date')
                    <span class="text-danger small">{{ $message }}</span>
                @enderror
            </div>

            <!-- Drop Off Time -->
            <div class="single-search-box">
                <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
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

            <div class="package-selector" id="ride_now-packages" style="display: none;"
                data-selected="{{ old('package_id', isset($search) && isset($search->package_id) ? $search->package_id : '') }}">
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

        <!-- Day Rental Form -->
        <form id="day_rental-form" class="filter-input {{ $currentServiceType === 'day_rental' ? 'show' : '' }}"
            data-service="day_rental" action="{{ route('booking.search') }}" method="GET">

            <!-- Pickup Location -->
            <div class="single-search-box location-search-box">
                <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                    <g>
                        <path
                            d="M12.5944 8.99987C12.5944 10.988 10.9826 12.5998 8.99443 12.5998C7.00627 12.5998 5.39465 10.988 5.39465 8.99987C5.39465 7.0117 7.00627 5.40009 8.99443 5.40009C10.9826 5.40009 12.5944 7.0117 12.5944 8.99987Z" />
                        <path
                            d="M17.4601 8.4599H16.2564C15.9858 4.86535 13.1291 2.00812 9.53458 1.7372V0.539976C9.53458 0.241723 9.29268 0 8.9946 0C8.69635 0 8.45462 0.241723 8.45462 0.539976V1.7372C4.85986 2.00812 2.00297 4.86535 1.73235 8.4599H0.540018C0.241723 8.4599 0 8.7017 0 8.99987C0 9.29813 0.241723 9.53985 0.539976 9.53985H1.73239C2.00297 13.1344 4.85991 15.9916 8.45441 16.2625V17.4601C8.45441 17.7583 8.69614 18 8.99439 18C9.29251 18 9.53428 17.7583 9.53428 17.4601V16.2625C13.1289 15.9918 15.9858 13.1346 16.2564 9.53985H17.4601C17.7583 9.53985 18 9.29813 18 8.99987C18 8.70175 17.7583 8.4599 17.4601 8.4599ZM8.99443 15.2096C5.56504 15.2094 2.78509 12.4291 2.78509 8.9997C2.78522 5.57014 5.56554 2.7902 8.99494 2.7902C12.4245 2.7902 15.2046 5.57048 15.2046 8.99987C15.2005 12.428 12.4225 15.2058 8.99443 15.2096Z" />
                    </g>
                </svg>
                <div class="custom-select-dropdown">
                    <input type="text" name="pickup" placeholder="Pick up Location"
                        class="location-search @error('pickup') is-invalid @enderror"
                        value="{{ old('pickup', isset($search) && isset($search->pickup_location) ? $search->pickup_location : 'Colombo, Sri Lanka') }}"
                        required>
                    <input type="hidden" name="pickup_lat" class="location-lat"
                        value="{{ old('pickup_lat', isset($search) && isset($search->pickup_latitude) ? $search->pickup_latitude : '6.9271') }}">
                    <input type="hidden" name="pickup_lng" class="location-lng"
                        value="{{ old('pickup_lng', isset($search) && isset($search->pickup_longitude) ? $search->pickup_longitude : '79.8612') }}">
                </div>
                @error('pickup')
                    <span class="text-danger small">{{ $message }}</span>
                @enderror
            </div>

            <!-- Drop Off Location -->
            {{-- <div class="single-search-box location-search-box">
                <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
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
                        value="{{ old('dropoff', isset($search) && isset($search->dropoff_location) ? $search->dropoff_location : 'Galle, Sri Lanka') }}"
                        required>
                    <input type="hidden" name="dropoff_lat" class="location-lat"
                        value="{{ old('dropoff_lat', isset($search) && isset($search->dropoff_latitude) ? $search->dropoff_latitude : '6.0535') }}">
                    <input type="hidden" name="dropoff_lng" class="location-lng"
                        value="{{ old('dropoff_lng', isset($search) && isset($search->dropoff_longitude) ? $search->dropoff_longitude : '80.221') }}">
                </div>
                @error('dropoff')
                    <span class="text-danger small">{{ $message }}</span>
                @enderror
            </div> --}}

            <!-- Pickup Date -->
            <div class="single-search-box date-field">
                <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                    <path
                        d="M15 2h-1V0h-2v2H6V0H4v2H3C1.89 2 1 2.89 1 4v12c0 1.1.89 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.11-.9-2-2-2zm0 14H3V7h12v9z" />
                </svg>
                <input type="text" name="pickup_date" placeholder="DD/MM/YYYY"
                    class="custom-datepicker @error('pickup_date') is-invalid @enderror"
                    value="{{ old('pickup_date', isset($search) && isset($search->from_date) && $search->from_date ? date('d/m/Y', strtotime($search->from_date)) : date('d/m/Y')) }}"
                    required autocomplete="off">
                @error('pickup_date')
                    <span class="text-danger small">{{ $message }}</span>
                @enderror
            </div>

            <!-- Pickup Time -->
            <div class="single-search-box">
                <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                    <path
                        d="M9 0C4.03 0 0 4.03 0 9s4.03 9 9 9 9-4.03 9-9-4.03-9-9-9zm0 16c-3.87 0-7-3.13-7-7s3.13-7 7-7 7 3.13 7 7-3.13 7-7 7zm.5-12H8v5l4.25 2.52.75-1.23-3.5-2.08V4z" />
                </svg>
                <div class="custom-select-dropdown">
                    <input type="time" name="pickup_time"
                        value="{{ old('pickup_time', isset($search) && isset($search->from_time) ? $search->from_time : '12:00') }}"
                        class="@error('pickup_time') is-invalid @enderror" required>
                </div>
                @error('pickup_time')
                    <span class="text-danger small">{{ $message }}</span>
                @enderror
            </div>

            <!-- Drop Off Date -->
            <div class="single-search-box date-field">
                <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                    <path
                        d="M15 2h-1V0h-2v2H6V0H4v2H3C1.89 2 1 2.89 1 4v12c0 1.1.89 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.11-.9-2-2-2zm0 14H3V7h12v9z" />
                </svg>
                <input type="text" name="dropoff_date" placeholder="DD/MM/YYYY"
                    class="custom-datepicker @error('dropoff_date') is-invalid @enderror"
                    value="{{ old('dropoff_date', isset($search) && isset($search->to_date) && $search->to_date ? date('d/m/Y', strtotime($search->to_date)) : date('d/m/Y', strtotime('+3 days'))) }}"
                    required autocomplete="off">
                @error('dropoff_date')
                    <span class="text-danger small">{{ $message }}</span>
                @enderror
            </div>

            <!-- Drop Off Time -->
            <div class="single-search-box">
                <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
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
            </div>

            <div class="package-selector" id="day_rental-packages" style="display: none;"
                data-selected="{{ old('package_id', isset($search) && isset($search->package_id) ? $search->package_id : '') }}">
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

                // Set default values
                if (!fromAirportSelect.value) {
                    fromAirportSelect.value = 'Colombo BIA Airport';
                    // Trigger change event to update coordinates
                    fromAirportSelect.dispatchEvent(new Event('change'));
                }
                if (!toLocationInput.value) {
                    toLocationInput.value = 'Colombo, Sri Lanka';
                }
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

                // Set default values
                if (!fromLocationInput.value) {
                    fromLocationInput.value = 'Colombo, Sri Lanka';
                }
                if (!toAirportSelect.value) {
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
    /* Airport select dropdown styles */
    .airport-select {
        width: 100%;
        padding: 12px 15px;
        border: 1px solid #e1e5e9;
        border-radius: 6px;
        background-color: #fff;
        font-size: 14px;
        font-family: inherit;
        color: #333;
        appearance: none;
        background-image: url('data:image/svg+xml;charset=US-ASCII,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 4 5"><path fill="%23666" d="M2 0L0 2h4zm0 5L0 3h4z"/></svg>');
        background-repeat: no-repeat;
        background-position: right 12px center;
        background-size: 12px;
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
        display: grid;
        gap: 10px;
        flex-wrap: wrap;
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
</style>

@push('scripts')
    <!-- Bootstrap Datepicker CSS -->
    <link rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.9.0/css/bootstrap-datepicker.min.css">

    <!-- Bootstrap Datepicker JS -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.9.0/js/bootstrap-datepicker.min.js"></script>

    <script>
        $(document).ready(function() {
            $('.custom-datepicker').datepicker({
                format: 'dd/mm/yyyy',
                autoclose: true,
                todayHighlight: true,
                startDate: '0d',
                orientation: 'bottom auto'
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

                    if (dropoff < pickup) {
                        $(this).addClass('is-invalid');
                        $(this).siblings('.invalid-feedback').remove();
                        $(this).after(
                            '<div class="invalid-feedback">Drop-off date must be on or after pickup date</div>'
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
        });
    </script>
@endpush
