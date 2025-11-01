<div class="filter-wrapper">
    <ul class="filter-item-list">
        <li class="single-item active" data-service="airport-transfer">
            <svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path
                    d="M21 16v-2l-8-5V3.5c0-.83-.67-1.5-1.5-1.5S10 2.67 10 3.5V9l-8 5v2l8-2.5V19l-2 1.5V22l3.5-1 3.5 1v-1.5L13 19v-5.5l8 2.5z" />
            </svg>
            <span>Airport Transfer</span>
        </li>

        <li class="single-item" data-service="rental-packages">
            <svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path
                    d="M17 5h-2v2h2v2h2V7h2V5h-2V3h-2v2zm-2 4V7H9.01L3 13.01V19c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2v-6h-6zM5 19v-4.99l4-4 4 4L9 18H5zm14 0h-6v-4l-2-2-4 4v2h12z" />
            </svg>
            <span>Rental Packages</span>
        </li>
        <li class="single-item" data-service="drop-pickup" data-redirect="{{ route('point-to-point') }}">
            <svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path
                    d="M18.92 6.01C18.72 5.42 18.16 5 17.5 5h-11c-.66 0-1.21.42-1.42 1.01L3 12v8c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-1h12v1c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-8l-2.08-5.99zM6.5 16c-.83 0-1.5-.67-1.5-1.5S5.67 13 6.5 13s1.5.67 1.5 1.5S7.33 16 6.5 16zm11 0c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zM5 11l1.5-4.5h11L19 11H5z" />
            </svg>
            <span>Point to Point</span>
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
                    <input type="text" name="from" placeholder="From (Airport/Hotel/Address)"
                        class="location-search @error('from') is-invalid @enderror" value="{{ old('from', 'Colombo BIA Airport') }}"
                        required>
                    <input type="hidden" name="from_lat" class="location-lat" value="{{ old('from_lat', '7.1808') }}">
                    <input type="hidden" name="from_lng" class="location-lng" value="{{ old('from_lng', '79.8841') }}">
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
                    <input type="text" name="to" placeholder="To (Airport/Hotel/Address)"
                        class="location-search @error('to') is-invalid @enderror" value="{{ old('to', 'Colombo, Sri Lanka') }}" required>
                    <input type="hidden" name="to_lat" class="location-lat" value="{{ old('to_lat', '6.9271') }}">
                    <input type="hidden" name="to_lng" class="location-lng" value="{{ old('to_lng', '79.8612') }}">
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
                    class="custom-datepicker @error('date') is-invalid @enderror" value="{{ old('date', date('d/m/Y')) }}"
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
                    <input type="time" name="time" value="{{ old('time', '12:00') }}"
                        class="@error('time') is-invalid @enderror" required>
                </div>
                @error('time')
                    <span class="text-danger small">{{ $message }}</span>
                @enderror
            </div>

            <!-- Passengers -->
            <div class="single-search-box">
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
                        class="location-search @error('pickup') is-invalid @enderror" value="{{ old('pickup', 'Colombo, Sri Lanka') }}"
                        required>
                    <input type="hidden" name="pickup_lat" class="location-lat" value="{{ old('pickup_lat', '6.9271') }}">
                    <input type="hidden" name="pickup_lng" class="location-lng" value="{{ old('pickup_lng', '79.8612') }}">
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
                        class="location-search @error('dropoff') is-invalid @enderror" value="{{ old('dropoff', 'Galle, Sri Lanka') }}"
                        required>
                    <input type="hidden" name="dropoff_lat" class="location-lat" value="{{ old('dropoff_lat', '6.0535') }}">
                    <input type="hidden" name="dropoff_lng" class="location-lng" value="{{ old('dropoff_lng', '80.221') }}">
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
                    value="{{ old('pickup_date', date('d/m/Y')) }}" required autocomplete="off">
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
                    <input type="time" name="pickup_time" value="{{ old('pickup_time', '12:00') }}"
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
                    value="{{ old('dropoff_date', date('d/m/Y', strtotime('+3 days'))) }}" required autocomplete="off">
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
                    <input type="time" name="dropoff_time" value="{{ old('dropoff_time', '12:00') }}"
                        class="@error('dropoff_time') is-invalid @enderror" required>
                </div>
                @error('dropoff_time')
                    <span class="text-danger small">{{ $message }}</span>
                @enderror
            </div>

            <div class="package-type-selector">
                <div style="display: flex; gap: 20px; justify-content: center;">
                    <label class="package-option">
                        <input type="radio" name="package_type" value="hourly"
                            {{ old('package_type', 'hourly') == 'hourly' ? 'checked' : '' }}>
                        <span>Hourly Package</span>
                    </label>
                    <label class="package-option">
                        <input type="radio" name="package_type" value="daily"
                            {{ old('package_type') == 'daily' ? 'checked' : '' }}>
                        <span>Daily Package</span>
                    </label>
                </div>
                @error('package_type')
                    <span class="text-danger small">{{ $message }}</span>
                @enderror
            </div>

            <!-- Passengers -->
            <div class="single-search-box">
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
            </div>

            <button type="submit" class="primary-btn1">
                <span>Search For Vehicles</span>
            </button>
        </form>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
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
                }
            });
        });

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
                    submitBtn.disabled = false;
                    submitBtn.style.opacity = '1';
                    btnText.textContent = originalText;
                }, 30000); // 30 seconds timeout
            });
        });
    });
</script>

@push('scripts')
    <!-- Bootstrap Datepicker CSS -->
    <link rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.9.0/css/bootstrap-datepicker.min.css">

    <!-- Bootstrap Datepicker JS -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.9.0/js/bootstrap-datepicker.min.js"></script>

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
                    $(this).after(
                        '<div class="invalid-feedback">Please enter date in DD/MM/YYYY format</div>');
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
                    date.getMonth() === (month - 1) &&
                    date.getDate() === day &&
                    date >= new Date().setHours(0, 0, 0, 0); // Not in the past
            }

            // Ensure dropoff date is after pickup date for rental packages
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
    </script>
@endpush
