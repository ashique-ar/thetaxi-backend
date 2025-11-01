<div class="filter-wrapper">
    <ul class="filter-item-list">
        <li class="single-item active" data-service="airport-transfer">
            <svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path
                    d="M21 16v-2l-8-5V3.5c0-.83-.67-1.5-1.5-1.5S10 2.67 10 3.5V9l-8 5v2l8-2.5V19l-2 1.5V22l3.5-1 3.5 1v-1.5L13 19v-5.5l8 2.5z" />
            </svg>
            <span>Airport Transfer</span>
        </li>
        <li class="single-item" data-service="drop-pickup" data-redirect="{{ route('point-to-point') }}">
            <svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path
                    d="M18.92 6.01C18.72 5.42 18.16 5 17.5 5h-11c-.66 0-1.21.42-1.42 1.01L3 12v8c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-1h12v1c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-8l-2.08-5.99zM6.5 16c-.83 0-1.5-.67-1.5-1.5S5.67 13 6.5 13s1.5.67 1.5 1.5S7.33 16 6.5 16zm11 0c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zM5 11l1.5-4.5h11L19 11H5z" />
            </svg>
            <span>Drop & Pickup</span>
        </li>
        <li class="single-item" data-service="rental-packages">
            <svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path
                    d="M17 5h-2v2h2v2h2V7h2V5h-2V3h-2v2zm-2 4V7H9.01L3 13.01V19c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2v-6h-6zM5 19v-4.99l4-4 4 4L9 18H5zm14 0h-6v-4l-2-2-4 4v2h12z" />
            </svg>
            <span>Rental Packages</span>
        </li>
        <li class="single-item" data-service="corporate-transport" data-redirect="{{ route('corporate-transfers') }}">
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
        <form id="airport-transfer-form" class="filter-input show" data-service="airport-transfer"
            action="{{ route('booking.search') }}" method="POST">
            @csrf
            <input type="hidden" name="service_type" value="airport-transfer">

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
                    <input type="text" class="from-display location-search @error('from') is-invalid @enderror"
                        placeholder="Enter pickup location" value="{{ old('from') }}" readonly>
                    <input type="hidden" name="from" class="from-value" value="{{ old('from') }}">
                    <input type="hidden" name="from_lat" class="location-lat" value="{{ old('from_lat') }}">
                    <input type="hidden" name="from_lng" class="location-lng" value="{{ old('from_lng') }}">
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
                    <input type="text" class="to-display location-search @error('to') is-invalid @enderror"
                        placeholder="Enter destination" value="{{ old('to') }}" readonly>
                    <input type="hidden" name="to" class="to-value" value="{{ old('to') }}">
                    <input type="hidden" name="to_lat" class="location-lat" value="{{ old('to_lat') }}">
                    <input type="hidden" name="to_lng" class="location-lng" value="{{ old('to_lng') }}">
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
                    value="{{ old('date') }}"
                    data-date-format="dd/mm/yyyy"
                    data-date-autoclose="true"
                    data-date-today-highlight="true"
                    data-date-start-date="0d"
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
                    <input type="time" name="time" class="@error('time') is-invalid @enderror"
                        value="{{ old('time', '00:00') }}" required>
                </div>
                @error('time')
                    <span class="text-danger small">{{ $message }}</span>
                @enderror
            </div>

            <button type="submit" class="primary-btn1">
                <span>Search For Vehicles</span>
            </button>
        </form>

        <!-- Drop & Pickup Form -->
        <form id="drop-pickup-form" class="filter-input" data-service="drop-pickup"
            action="{{ route('booking.search') }}" method="POST">
            @csrf
            <input type="hidden" name="service_type" value="drop-pickup">

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
                    <input type="text" name="pickup" placeholder="Pickup Location"
                        class="location-search @error('pickup') is-invalid @enderror" value="{{ old('pickup') }}"
                        required>
                    <input type="hidden" name="pickup_lat" class="location-lat" value="{{ old('pickup_lat') }}">
                    <input type="hidden" name="pickup_lng" class="location-lng" value="{{ old('pickup_lng') }}">
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
                        class="location-search @error('dropoff') is-invalid @enderror" value="{{ old('dropoff') }}"
                        required>
                    <input type="hidden" name="dropoff_lat" class="location-lat" value="{{ old('dropoff_lat') }}">
                    <input type="hidden" name="dropoff_lng" class="location-lng" value="{{ old('dropoff_lng') }}">
                </div>
                @error('dropoff')
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
                    class="custom-datepicker @error('date') is-invalid @enderror" value="{{ old('date') }}"
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
                    <input type="time" name="time" class="@error('time') is-invalid @enderror"
                        value="{{ old('time', '00:00') }}" required>
                </div>
                @error('time')
                    <span class="text-danger small">{{ $message }}</span>
                @enderror
            </div>

            <!-- Return Transfer Toggle Switch -->
            <div class="return-transfer-toggle-container">
                <label class="toggle-switch">
                    <input type="checkbox" id="need-return" name="need_return" value="1"
                        {{ old('need_return') ? 'checked' : '' }}>
                    <span class="toggle-slider"></span>
                    <span class="toggle-label">Return Transfer</span>
                </label>
                @error('need_return')
                    <span class="text-danger small">{{ $message }}</span>
                @enderror
            </div>

            <!-- Return Transfer Fields (Hidden by default) -->
            <div id="return-transfer-fields" style="display: none;">
                <div class="return-transfer-header">
                    <h5>Return Transfer Details</h5>
                </div>

                <!-- Return Pickup Location -->
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
                        <input type="text" name="return_pickup" placeholder="Return Pickup Location"
                            class="@error('return_pickup') is-invalid @enderror" value="{{ old('return_pickup') }}">
                    </div>
                    @error('return_pickup')
                        <span class="text-danger small">{{ $message }}</span>
                    @enderror
                </div>

                <!-- Return Drop Off Location -->
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
                        <input type="text" name="return_dropoff" placeholder="Return Drop Off Location"
                            class="@error('return_dropoff') is-invalid @enderror"
                            value="{{ old('return_dropoff') }}">
                    </div>
                    @error('return_dropoff')
                        <span class="text-danger small">{{ $message }}</span>
                    @enderror
                </div>

                <!-- Return Date -->
                <div class="single-search-box date-field">
                    <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                        <path
                            d="M15 2h-1V0h-2v2H6V0H4v2H3C1.89 2 1 2.89 1 4v12c0 1.1.89 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.11-.9-2-2-2zm0 14H3V7h12v9z" />
                    </svg>
                    <input type="text" name="return_date" placeholder="DD/MM/YYYY" class="custom-datepicker"
                        autocomplete="off">
                </div>

                <!-- Return Time -->
                <div class="single-search-box">
                    <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                        <path
                            d="M9 0C4.03 0 0 4.03 0 9s4.03 9 9 9 9-4.03 9-9-4.03-9-9-9zm0 16c-3.87 0-7-3.13-7-7s3.13-7 7-7 7 3.13 7 7-3.13 7-7 7zm.5-12H8v5l4.25 2.52.75-1.23-3.5-2.08V4z" />
                    </svg>
                    <div class="custom-select-dropdown">
                        <input type="time" name="return_time" value="00:00">
                    </div>
                </div>
            </div>

            <button type="submit" class="primary-btn1">
                <span>Search For Vehicles</span>
            </button>
        </form>

        <!-- Rental Packages Form -->
        <form id="rental-packages-form" class="filter-input" data-service="rental-packages"
            action="{{ route('booking.search') }}" method="POST">
            @csrf
            <input type="hidden" name="service_type" value="rental-packages">

            <!-- Package Type Selection -->


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
                        class="location-search @error('pickup') is-invalid @enderror" value="{{ old('pickup') }}"
                        required>
                    <input type="hidden" name="pickup_lat" class="location-lat" value="{{ old('pickup_lat') }}">
                    <input type="hidden" name="pickup_lng" class="location-lng" value="{{ old('pickup_lng') }}">
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
                        class="location-search @error('dropoff') is-invalid @enderror" value="{{ old('dropoff') }}"
                        required>
                    <input type="hidden" name="dropoff_lat" class="location-lat" value="{{ old('dropoff_lat') }}">
                    <input type="hidden" name="dropoff_lng" class="location-lng" value="{{ old('dropoff_lng') }}">
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
                    value="{{ old('pickup_date') }}" required autocomplete="off">
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
                    <input type="time" name="pickup_time" class="@error('pickup_time') is-invalid @enderror"
                        value="{{ old('pickup_time', '00:00') }}" required>
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
                    value="{{ old('dropoff_date') }}" required autocomplete="off">
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
                    <input type="time" name="dropoff_time" class="@error('dropoff_time') is-invalid @enderror"
                        value="{{ old('dropoff_time', '00:00') }}" required>
                </div>
                @error('dropoff_time')
                    <span class="text-danger small">{{ $message }}</span>
                @enderror
            </div>

            <div class="package-type-selector">
                <div style="display: flex; gap: 20px; justify-content: center;">
                    <label>
                        <input type="radio" name="package_type" value="taxi-100km"
                            {{ old('package_type', 'taxi-100km') == 'taxi-100km' ? 'checked' : '' }}>
                        <span>TAXI 100 KM per day</span>
                    </label>
                    <label>
                        <input type="radio" name="package_type" value="tour-200km"
                            {{ old('package_type') == 'tour-200km' ? 'checked' : '' }}>
                        <span>TOUR 200 KM Per day</span>
                    </label>
                </div>
                @error('package_type')
                    <span class="text-danger small">{{ $message }}</span>
                @enderror
            </div>
            <button type="submit" class="primary-btn1">
                <span>Search For Vehicles</span>
            </button>
        </form>

        <!-- Corporate Transport Form -->
        <form id="corporate-transport-form" class="filter-input" data-service="corporate-transport"
            action="{{ route('booking.enquiry') }}" method="POST">
            @csrf
            <input type="hidden" name="service_type" value="corporate-transport">

            <!-- Company Name -->
            <div class="single-search-box">
                <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                    <path
                        d="M12 7V3H2v18h20V7H12zM6 19H4v-2h2v2zm0-4H4v-2h2v2zm0-4H4V9h2v2zm0-4H4V5h2v2zm4 12H8v-2h2v2zm0-4H8v-2h2v2zm0-4H8V9h2v2zm0-4H8V5h2v2zm10 12h-8v-2h2v-2h-2v-2h2v-2h-2V9h8v10zm-2-8h-2v2h2v-2zm0 4h-2v2h2v-2z" />
                </svg>
                <div class="custom-select-dropdown">
                    <input type="text" name="company_name" placeholder="Company Name"
                        class="@error('company_name') is-invalid @enderror" value="{{ old('company_name') }}"
                        required>
                </div>
                @error('company_name')
                    <span class="text-danger small">{{ $message }}</span>
                @enderror
            </div>

            <!-- Contact Person -->
            <div class="single-search-box">
                <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                    <path
                        d="M9 9c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z" />
                </svg>
                <div class="custom-select-dropdown">
                    <input type="text" name="contact_person" placeholder="Contact Person"
                        class="@error('contact_person') is-invalid @enderror" value="{{ old('contact_person') }}"
                        required>
                </div>
                @error('contact_person')
                    <span class="text-danger small">{{ $message }}</span>
                @enderror
            </div>

            <!-- Email -->
            <div class="single-search-box">
                <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                    <path
                        d="M16 2H2C0.9 2 0.01 2.9 0.01 4L0 14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 4l-7 4.5L2 6V4l7 4.5L16 4v2z" />
                </svg>
                <div class="custom-select-dropdown">
                    <input type="email" name="email" placeholder="Email Address"
                        class="@error('email') is-invalid @enderror" value="{{ old('email') }}" required>
                </div>
                @error('email')
                    <span class="text-danger small">{{ $message }}</span>
                @enderror
            </div>

            <!-- Phone -->
            <div class="single-search-box">
                <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                    <path
                        d="M3.62 7.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V17c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z" />
                </svg>
                <div class="custom-select-dropdown">
                    <input type="tel" name="phone" placeholder="Phone Number"
                        class="@error('phone') is-invalid @enderror" value="{{ old('phone') }}" required>
                </div>
                @error('phone')
                    <span class="text-danger small">{{ $message }}</span>
                @enderror
            </div>

            <!-- Service Requirements - Full Width -->
            <div class="corporate-requirements-field">
                <textarea name="requirements" placeholder="Describe your corporate transport requirements..."
                    class="@error('requirements') is-invalid @enderror" rows="4" required>{{ old('requirements') }}</textarea>
                @error('requirements')
                    <span class="text-danger small">{{ $message }}</span>
                @enderror
            </div>

            <button type="submit" class="primary-btn1 corporate-submit-btn">
                <span>Submit Enquiry</span>
            </button>
        </form>
    </div>
</div>

<!-- Route Check Modal -->
<div class="modal fade" id="routeModal" tabindex="-1" aria-labelledby="routeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="routeModalLabel">
                    <i class="bi bi-map"></i> Route Preview & Distance Calculation
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-8">
                        <div id="routeMap" style="height: 400px; border-radius: 8px;"></div>
                    </div>
                    <div class="col-md-4">
                        <div class="route-info">
                            <h6 class="mb-3">Route Information</h6>
                            <div id="routeDetails">
                                <div class="info-item mb-2">
                                    <strong>Total Distance:</strong>
                                    <span id="totalDistance">Calculating...</span>
                                </div>
                                <div class="info-item mb-2">
                                    <strong>Estimated Duration:</strong>
                                    <span id="totalDuration">Calculating...</span>
                                </div>
                                <div class="info-item mb-3">
                                    <strong>Number of Stops:</strong>
                                    <span id="totalStops">0</span>
                                </div>
                            </div>

                            <h6 class="mb-2">Destination List</h6>
                            <div id="destinationList" class="destination-list">
                                <!-- Destinations will be populated here -->
                            </div>

                            <!-- Add Destination Button in Modal -->
                            <button type="button" class="btn btn-primary btn-sm w-100 mt-2"
                                id="addDestinationModal">
                                <i class="bi bi-plus-circle"></i> Add Another Destination
                            </button>

                            <div class="route-actions mt-3">
                                <button type="button" class="btn btn-success" id="confirmRoute">
                                    <i class="bi bi-check-circle"></i> Confirm Route
                                </button>
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                    <i class="bi bi-arrow-left"></i> Back to Edit
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Add loading states to all form submissions
        const forms = document.querySelectorAll('.filter-input');

        forms.forEach(form => {
            form.addEventListener('submit', function(e) {
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
                    if (submitBtn.disabled) {
                        submitBtn.disabled = false;
                        submitBtn.style.opacity = '1';
                        btnText.textContent = originalText;
                    }
                }, 30000); // 30 seconds timeout
            });
        });

        // Show return transfer fields when checkbox is checked
        const returnTransferCheckbox = document.getElementById('need-return');
        const returnTransferFields = document.getElementById('return-transfer-fields');

        if (returnTransferCheckbox && returnTransferFields) {
            // Check if we need to show return fields on page load (for old input)
            if (returnTransferCheckbox.checked) {
                returnTransferFields.style.display = 'block';
            }

            returnTransferCheckbox.addEventListener('change', function() {
                if (this.checked) {
                    returnTransferFields.style.display = 'block';
                } else {
                    returnTransferFields.style.display = 'none';
                }
            });
        }
    });
</script>

@push('scripts')
    <!-- Bootstrap Datepicker CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.9.0/css/bootstrap-datepicker.min.css">
    
    <!-- Bootstrap Datepicker JS -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.9.0/js/bootstrap-datepicker.min.js"></script>

    <!-- Leaflet CSS for route visualization fallback -->
    <link rel="stylesheet" href="{{ asset('assets/css/leaflet.css') }}">

    <!-- Leaflet JS -->
    <script src="{{ asset('assets/js/leaflet.js') }}"></script>

    <script>
        // Enhanced date picker initialization
        $(document).ready(function() {
            // Initialize all date pickers with DD/MM/YYYY format
            $('.custom-datepicker').datepicker({
                format: 'dd/mm/yyyy',
                autoclose: true,
                todayHighlight: true,
                startDate: '0d', // Today or later
                orientation: 'bottom auto'
            });

            // Handle date input manually to ensure DD/MM/YYYY format
            $('.custom-datepicker').on('input', function() {
                let value = $(this).val();
                
                // Remove any non-numeric characters except /
                value = value.replace(/[^\d\/]/g, '');
                
                // Auto-add slashes
                if (value.length === 2 && !value.includes('/')) {
                    value += '/';
                } else if (value.length === 5 && value.split('/').length === 2) {
                    value += '/';
                }
                
                // Limit to DD/MM/YYYY format
                if (value.length > 10) {
                    value = value.substring(0, 10);
                }
                
                $(this).val(value);
            });

            // Validate date format on blur
            $('.custom-datepicker').on('blur', function() {
                let value = $(this).val();
                if (value && !isValidDDMMYYYY(value)) {
                    $(this).addClass('is-invalid');
                    $(this).siblings('.invalid-feedback').remove();
                    $(this).after('<div class="invalid-feedback">Please enter date in DD/MM/YYYY format</div>');
                } else {
                    $(this).removeClass('is-invalid');
                    $(this).siblings('.invalid-feedback').remove();
                }
            });

            // Date validation function
            function isValidDDMMYYYY(dateString) {
                const regex = /^\d{2}\/\d{2}\/\d{4}$/;
                if (!regex.test(dateString)) return false;
                
                const parts = dateString.split('/');
                const day = parseInt(parts[0], 10);
                const month = parseInt(parts[1], 10);
                const year = parseInt(parts[2], 10);
                
                // Check if date is valid
                const date = new Date(year, month - 1, day);
                return date.getFullYear() === year && 
                       date.getMonth() === month - 1 && 
                       date.getDate() === day &&
                       date >= new Date().setHours(0, 0, 0, 0); // Today or later
            }

            // Ensure return date is after pickup date
            $('.custom-datepicker[name="return_date"], .custom-datepicker[name="dropoff_date"]').on('change', function() {
                const $form = $(this).closest('form');
                const pickupDateField = $form.find('.custom-datepicker[name="date"], .custom-datepicker[name="pickup_date"]');
                const returnDateField = $(this);
                
                const pickupDate = pickupDateField.val();
                const returnDate = returnDateField.val();
                
                if (pickupDate && returnDate && isValidDDMMYYYY(pickupDate) && isValidDDMMYYYY(returnDate)) {
                    const pickup = parseDate(pickupDate);
                    const returnD = parseDate(returnDate);
                    
                    if (returnD <= pickup) {
                        returnDateField.addClass('is-invalid');
                        returnDateField.siblings('.invalid-feedback').remove();
                        returnDateField.after('<div class="invalid-feedback">Return/Drop-off date must be after pickup date</div>');
                    } else {
                        returnDateField.removeClass('is-invalid');
                        returnDateField.siblings('.invalid-feedback').remove();
                    }
                }
            });

            // Parse DD/MM/YYYY to Date object
            function parseDate(dateString) {
                const parts = dateString.split('/');
                return new Date(parseInt(parts[2]), parseInt(parts[1]) - 1, parseInt(parts[0]));
            }
        });
    </script>
@endpush
