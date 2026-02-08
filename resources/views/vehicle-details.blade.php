@extends('layouts.app')

@section('title', ($vehicleGroup->name ?? 'Vehicle Details') . ' - TheTaxi')

@push('meta')
    @include('partials.seo', ['model' => $vehicleGroup])
@endpush

@section('content')
    <!-- Breadcrumb section Start-->
    <div class="breadcrumb-section three"
        style="background-image:linear-gradient(rgba(0, 0, 0, 0.3), rgba(0, 0, 0, 0.3)), url({{ asset('assets/img/innerpages/breadcrumb-bg.jpg') }});">
        <div class="container">
            <div class="banner-content">
                <h1>{{ $vehicleGroup->name ?? 'Vehicle Details' }}</h1>
                <ul class="breadcrumb-list">
                    <li><a href="{{ route('home') }}">Home</a></li>
                    @if ($search)
                        <li><a href="{{ route('search') }}?search={{ $search->id }}">Search Results</a></li>
                    @endif
                    <li>Vehicle Details</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Vehicle Details Section -->
    <div class="vehicle-details-wrapper pt-5 mb-110">
        <div class="container">
            <div class="row">
                <!-- Left Column - Vehicle Images & Info -->
                <div class="col-lg-8">
                    <!-- Vehicle Image Gallery -->
                    <div class="vehicle-image-gallery mb-4">

                        @php
                            $mainImage = $vehicleGroup->thumbnail
                                ? s3_asset($vehicleGroup->thumbnail['path'] ?? '')
                                : asset('assets/img/default-vehicle.jpg');
                            $vehicleImages = [];

                            // Add main thumbnail
                            $vehicleImages[] = $mainImage;

                            // Add vehicle images if available
                            if ($vehicleGroup->images) {
                                foreach ($vehicleGroup->images as $image) {
                                    $vehicleImages[] = s3_asset($image['path'] ?? '');
                                }
                            }

                            // Remove duplicates and take first 6 images
                            $vehicleImages = array_unique(array_slice($vehicleImages, 0, 6));
                        @endphp

                        <div class="main-vehicle-image">
                            <img src="{{ $vehicleImages[0] ?? $mainImage }}" alt="{{ $vehicleGroup->name }}"
                                class="img-fluid rounded" id="mainVehicleImage">
                        </div>

                        @if (count($vehicleImages) > 1)
                            <div class="vehicle-thumbnails mt-3">
                                <div class="row g-2">
                                    @foreach ($vehicleImages as $index => $image)
                                        <div class="col-2">
                                            <img src="{{ $image }}" alt="Vehicle Image {{ $index + 1 }}"
                                                class="img-fluid rounded thumbnail-img {{ $index === 0 ? 'active' : '' }}"
                                                onclick="changeMainImage('{{ $image }}')"
                                                style="cursor: pointer; height: 80px; object-fit: cover; width: 100%;">
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>

                    <!-- Vehicle Information -->
                    <div class="vehicle-info-card">
                        <h2 class="vehicle-title">{{ $vehicleGroup->name }}</h2>

                        @if ($vehicleGroup->description)
                            <p class="vehicle-description">{{ $vehicleGroup->description }}</p>
                        @endif

                        <!-- Vehicle Specifications -->
                        <div class="vehicle-specifications">
                            <h4>Vehicle Specifications</h4>
                            <div class="row g-3">
                                @if ($vehicleGroup->seating_capacity)
                                    <div class="col-md-6">
                                        <div class="spec-item">
                                            <i class="bi bi-people-fill"></i>
                                            <span><strong>Seating:</strong> {{ $vehicleGroup->seating_capacity }}
                                                passengers</span>
                                        </div>
                                    </div>
                                @endif

                                @if ($vehicleGroup->transmission)
                                    <div class="col-md-6">
                                        <div class="spec-item">
                                            <i class="bi bi-gear-fill text-primary"></i>
                                            <span><strong>Transmission:</strong>
                                                {{ $vehicleGroup->transmission->name }}</span>
                                        </div>
                                    </div>
                                @endif

                                @if ($vehicleGroup->fuelType)
                                    <div class="col-md-6">
                                        <div class="spec-item">
                                            <i class="bi bi-fuel-pump-fill text-primary"></i>
                                            <span><strong>Fuel:</strong> {{ $vehicleGroup->fuelType->name }}</span>
                                        </div>
                                    </div>
                                @endif

                                @if ($vehicleGroup->category)
                                    <div class="col-md-6">
                                        <div class="spec-item">
                                            <i class="bi bi-car-front-fill text-primary"></i>
                                            <span><strong>Category:</strong>
                                                {{ $vehicleGroup->category->name ?? 'Standard' }}</span>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </div>

                        <!-- Vehicle Features -->
                        @if ($vehicleGroup->features && $vehicleGroup->features->count() > 0)
                            <div class="vehicle-features mt-4">
                                <h4>Features & Amenities</h4>
                                <div class="row g-2">
                                    @foreach ($vehicleGroup->features as $feature)
                                        <div class="col-md-6">
                                            <div class="feature-item">
                                                <i class="bi bi-check-circle-fill text-success"></i>
                                                <span>{{ $feature->name }}</span>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                </div>

                <!-- Right Column - Booking Form -->
                <div class="col-lg-4">
                    <div class="booking-form-card">
                        <!-- Service Type Switcher -->
                        <div class="filter-wrapper vehicle-detail-form">
                            <ul class="filter-item-list">
                                <li class="single-item {{ $searchData['service_type'] === 'airport_transfers' ? 'active' : '' }}"
                                    data-service="airport_transfers">
                                    <svg width="20" height="20" viewBox="0 0 24 24"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <path
                                            d="M21 16v-2l-8-5V3.5c0-.83-.67-1.5-1.5-1.5S10 2.67 10 3.5V9l-8 5v2l8-2.5V19l-2 1.5V22l3.5-1 3.5 1v-1.5L13 19v-5.5l8 2.5z" />
                                    </svg>
                                    <span>Airport Transfer</span>
                                </li>
                                <li class="single-item {{ $searchData['service_type'] === 'ride_now' ? 'active' : '' }}"
                                    data-service="ride_now">
                                    <svg width="20" height="20" viewBox="0 0 24 24"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <path
                                            d="M18.92 6.01C18.72 5.42 18.16 5 17.5 5h-11c-.66 0-1.22.42-1.42 1.01L3 12v8c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-1h12v1c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-8l-2.08-5.99zM6.5 16c-.83 0-1.5-.67-1.5-1.5S5.67 13 6.5 13s1.5.67 1.5 1.5S7.33 16 6.5 16zm11 0c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zM5 11l1.5-4.5h11L19 11H5z" />
                                    </svg>
                                    <span>Ride Now</span>
                                </li>
                                <li class="single-item {{ $searchData['service_type'] === 'day_rental' ? 'active' : '' }}"
                                    data-service="day_rental">
                                    <svg width="20" height="20" viewBox="0 0 24 24"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <path
                                            d="M19 3h-1V1h-2v2H8V1H6v2H5c-1.11 0-1.99.9-1.99 2L3 19c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V8h14v11zM9 10H7v2h2v-2zm4 0h-2v2h2v-2zm4 0h-2v2h2v-2zm-8 4H7v2h2v-2zm4 0h-2v2h2v-2zm4 0h-2v2h2v-2z" />
                                    </svg>
                                    <span>Day Rental</span>
                                </li>
                                {{-- <li class="single-item {{ $searchData['service_type'] === 'point_to_point' ? 'active' : '' }}"
                                    data-service="point_to_point" data-redirect="{{ route('point-to-point') }}">
                                    <svg width="20" height="20" viewBox="0 0 24 24"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <path
                                            d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z" />
                                    </svg>
                                    <span>Point to Point</span>
                                </li>
                                <li class="single-item {{ $searchData['service_type'] === 'corporate_transport' ? 'active' : '' }}"
                                    data-service="corporate_transport" data-redirect="{{ route('corporate-transfers') }}">
                                    <svg width="20" height="20" viewBox="0 0 24 24"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <path
                                            d="M12 7V3H2v18h20V7H12zM6 19H4v-2h2v2zm0-4H4v-2h2v2zm0-4H4V9h2v2zm0-4H4V5h2v2zm4 12H8v-2h2v2zm0-4H8v-2h2v2zm0-4H8V9h2v2zm0-4H8V5h2v2zm10 12h-8v-2h2v-2h-2v-2h2v-2h-2V9h8v10z" />
                                    </svg>
                                    <span>Corporate</span>
                                </li> --}}
                            </ul>

                            <!-- Airport Transfer Form -->
                            <div class="filter-input-wrap">
                                <form id="airport_transfers-form"
                                    class="filter-input {{ $searchData['service_type'] === 'airport_transfers' ? 'show' : '' }}"
                                    data-service="airport_transfers">
                                    @csrf
                                    <input type="hidden" name="vehicle_group_id" value="{{ $vehicleGroup->id }}">
                                    <input type="hidden" name="service_type" value="airport_transfers">

                                    <!-- Transfer Type Selection -->
                                    <div class="form-section">
                                        <div class="transfer-type-toggle">
                                            <label class="transfer-type-option">
                                                <input type="radio" name="transfer_type" value="from-airport"
                                                    {{ ($searchData['transfer_type'] ?? 'from-airport') === 'from-airport' ? 'checked' : '' }}>
                                                <span><i class="bi bi-airplane-fill"></i> From Airport</span>
                                            </label>
                                            <label class="transfer-type-option">
                                                <input type="radio" name="transfer_type" value="to-airport"
                                                    {{ ($searchData['transfer_type'] ?? '') === 'to-airport' ? 'checked' : '' }}>
                                                <span><i class="bi bi-airplane-fill"></i> To Airport</span>
                                            </label>
                                        </div>
                                    </div>

                                    <!-- Location Section -->
                                    <div class="form-section">
                                        <label class="form-section-label">Pickup & Dropoff</label>

                                        <!-- From Location -->
                                        <div class="single-search-box from-location location-search-box mb-2">
                                            <svg width="18" height="18" viewBox="0 0 18 18"
                                                xmlns="http://www.w3.org/2000/svg">
                                                <path
                                                    d="M12.5944 8.99987C12.5944 10.988 10.9826 12.5998 8.99443 12.5998C7.00627 12.5998 5.39465 10.988 5.39465 8.99987C5.39465 7.0117 7.00627 5.40009 8.99443 5.40009C10.9826 5.40009 12.5944 7.0117 12.5944 8.99987Z" />
                                                <path
                                                    d="M17.4601 8.4599H16.2564C15.9858 4.86535 13.1291 2.00812 9.53458 1.7372V0.539976C9.53458 0.241723 9.29268 0 8.9946 0C8.69635 0 8.45462 0.241723 8.45462 0.539976V1.7372C4.85986 2.00812 2.00297 4.86535 1.73235 8.4599H0.540018C0.241723 8.4599 0 8.7017 0 8.99987C0 9.29813 0.241723 9.53985 0.539976 9.53985H1.73239C2.00297 13.1344 4.85991 15.9916 8.45441 16.2625V17.4601C8.45441 17.7583 8.69614 18 8.99439 18C9.29251 18 9.53428 17.7583 9.53428 17.4601V16.2625C13.1289 15.9918 15.9858 13.1346 16.2564 9.53985H17.4601C17.7583 9.53985 18 9.29813 18 8.99987C18 8.70175 17.7583 8.4599 17.4601 8.4599ZM8.99443 15.2096C5.56504 15.2094 2.78509 12.4291 2.78509 8.9997C2.78522 5.57014 5.56554 2.7902 8.99494 2.7902C12.4245 2.7902 15.2046 5.57048 15.2046 8.99987C15.2005 12.428 12.4225 15.2058 8.99443 15.2096Z" />
                                            </svg>
                                            <input type="text" name="pickup_location" id="airportFromLocation"
                                                class="location-search airport-search-field" placeholder="Pickup Location"
                                                value="{{ $searchData['pickup_location'] }}" required>
                                            <input type="hidden" name="pickup_lat" id="airportFromLat"
                                                value="{{ $searchData['pickup_lat'] }}">
                                            <input type="hidden" name="pickup_lng" id="airportFromLng"
                                                value="{{ $searchData['pickup_lng'] }}">
                                        </div>

                                        <!-- To Location -->
                                        <div class="single-search-box to-location location-search-box mb-0">
                                            <svg width="18" height="18" viewBox="0 0 18 18"
                                                xmlns="http://www.w3.org/2000/svg">
                                                <path
                                                    d="M12.5944 8.99987C12.5944 10.988 10.9826 12.5998 8.99443 12.5998C7.00627 12.5998 5.39465 10.988 5.39465 8.99987C5.39465 7.0117 7.00627 5.40009 8.99443 5.40009C10.9826 5.40009 12.5944 7.0117 12.5944 8.99987Z" />
                                                <path
                                                    d="M17.4601 8.4599H16.2564C15.9858 4.86535 13.1291 2.00812 9.53458 1.7372V0.539976C9.53458 0.241723 9.29268 0 8.9946 0C8.69635 0 8.45462 0.241723 8.45462 0.539976V1.7372C4.85986 2.00812 2.00297 4.86535 1.73235 8.4599H0.540018C0.241723 8.4599 0 8.7017 0 8.99987C0 9.29813 0.241723 9.53985 0.539976 9.53985H1.73239C2.00297 13.1344 4.85991 15.9916 8.45441 16.2625V17.4601C8.45441 17.7583 8.69614 18 8.99439 18C9.29251 18 9.53428 17.7583 9.53428 17.4601V16.2625C13.1289 15.9918 15.9858 13.1346 16.2564 9.53985H17.4601C17.7583 9.53985 18 9.29813 18 8.99987C18 8.70175 17.7583 8.4599 17.4601 8.4599ZM8.99443 15.2096C5.56504 15.2094 2.78509 12.4291 2.78509 8.9997C2.78522 5.57014 5.56554 2.7902 8.99494 2.7902C12.4245 2.7902 15.2046 5.57048 15.2046 8.99987C15.2005 12.428 12.4225 15.2058 8.99443 15.2096Z" />
                                            </svg>
                                            <input type="text" name="dropoff_location" id="airportToLocation"
                                                class="location-search airport-search-field"
                                                placeholder="Dropoff Location"
                                                value="{{ $searchData['dropoff_location'] }}" required>
                                            <input type="hidden" name="dropoff_lat" id="airportToLat"
                                                value="{{ $searchData['dropoff_lat'] }}">
                                            <input type="hidden" name="dropoff_lng" id="airportToLng"
                                                value="{{ $searchData['dropoff_lng'] }}">
                                        </div>
                                    </div>

                                    <!-- Date & Time Section -->
                                    <div class="form-section">
                                        <label class="form-section-label">Date & Time</label>
                                        <div class="row g-2">
                                            <div class="col-8">
                                                <div class="single-search-box date-field">
                                                    <svg width="18" height="18" viewBox="0 0 18 18"
                                                        xmlns="http://www.w3.org/2000/svg">
                                                        <path
                                                            d="M15 2h-1V1c0-.55-.45-1-1-1s-1 .45-1 1v1H6V1c0-.55-.45-1-1-1S4 .45 4 1v1H3c-1.1 0-2 .9-2 2v11c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 13H3V7h12v8z" />
                                                    </svg>
                                                    <input type="date" name="pickup_date" id="airportPickupDate"
                                                        class="custom-datepicker"
                                                        value="{{ $searchData['pickup_date'] }}"
                                                        min="{{ date('Y-m-d') }}" required>
                                                </div>
                                            </div>
                                            <div class="col-4">
                                                <div class="single-search-box">
                                                    <svg width="18" height="18" viewBox="0 0 18 18"
                                                        xmlns="http://www.w3.org/2000/svg">
                                                        <path
                                                            d="M9 1C4.03 1 0 5.03 0 10s4.03 9 9 9 9-4.03 9-9-4.03-9-9-9zm0 16c-3.87 0-7-3.13-7-7s3.13-7 7-7 7 3.13 7 7-3.13 7-7 7z" />
                                                        <path d="M9.5 5H8v5l4.25 2.52.75-1.23L9.5 9V5z" />
                                                    </svg>
                                                    <select name="pickup_time" id="airportPickupTime" class="form-select"
                                                        required>
                                                        @for ($hour = 0; $hour < 24; $hour++)
                                                            @for ($min = 0; $min < 60; $min += 30)
                                                                @php
                                                                    $time = sprintf('%02d:%02d', $hour, $min);
                                                                    $selected =
                                                                        $searchData['pickup_time'] === $time
                                                                            ? 'selected'
                                                                            : '';
                                                                @endphp
                                                                <option value="{{ $time }}" {{ $selected }}>
                                                                    {{ $time }}</option>
                                                            @endfor
                                                        @endfor
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Duration & Pricing Section -->
                                    <div class="form-section">
                                        <div class="duration-display mb-3">
                                            <i class="bi bi-calendar-event"></i>
                                            <span>Duration: <strong id="airportDurationText">1 day</strong></span>
                                        </div>

                                        <div class="pricing-display">
                                            <div id="airportPricingLoader" class="text-center">
                                                <div class="spinner-border spinner-border-sm" role="status"></div>
                                                <span class="ms-2">Calculating price...</span>
                                            </div>
                                            <div id="airportPricingContent" style="display: none;">
                                                <div class="price-display-large">
                                                    <span class="price-label">Total Price</span>
                                                    <span class="price-amount"
                                                        id="airportTotalPrice">{{ getCurrencySymbol() }} 0</span>
                                                </div>
                                            </div>
                                            <div id="airportPricingError" style="display: none;"
                                                class="text-danger text-center">
                                                <i class="bi bi-exclamation-triangle"></i> Unable to calculate price
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Action Buttons -->
                                    <div class="d-grid gap-2">
                                        <button type="button" class="btn btn-primary btn-lg" id="airportAddToCartBtn">
                                            <i class="bi bi-cart-plus"></i> Add to Cart
                                        </button>
                                        <button type="button" class="btn btn-success btn-lg" id="airportBookNowBtn">
                                            <i class="bi bi-calendar-check"></i> Book Now
                                        </button>
                                    </div>
                                </form>

                                <!-- Rental Package Form -->
                                <form id="ride_now-form"
                                    class="filter-input {{ $searchData['service_type'] === 'ride_now' ? 'show' : '' }}"
                                    data-service="ride_now">
                                    @csrf
                                    <input type="hidden" name="vehicle_group_id" value="{{ $vehicleGroup->id }}">
                                    <input type="hidden" name="service_type" value="ride_now">

                                    <!-- Location Section -->
                                    <div class="form-section">
                                        <label class="form-section-label">Pickup & Dropoff</label>

                                        <!-- Pickup Location -->
                                        <div class="single-search-box location-search-box mb-2">
                                            <svg width="18" height="18" viewBox="0 0 18 18"
                                                xmlns="http://www.w3.org/2000/svg">
                                                <path
                                                    d="M12.5944 8.99987C12.5944 10.988 10.9826 12.5998 8.99443 12.5998C7.00627 12.5998 5.39465 10.988 5.39465 8.99987C5.39465 7.0117 7.00627 5.40009 8.99443 5.40009C10.9826 5.40009 12.5944 7.0117 12.5944 8.99987Z" />
                                            </svg>
                                            <input type="text" name="pickup_location" id="rentalPickupLocation"
                                                class="location-search" placeholder="Pickup Location"
                                                value="{{ $searchData['pickup_location'] }}" required>
                                            <input type="hidden" name="pickup_lat" id="rentalPickupLat"
                                                value="{{ $searchData['pickup_lat'] }}">
                                            <input type="hidden" name="pickup_lng" id="rentalPickupLng"
                                                value="{{ $searchData['pickup_lng'] }}">
                                        </div>

                                        <!-- Dropoff Location -->
                                        <div class="single-search-box location-search-box mb-0">
                                            <svg width="18" height="18" viewBox="0 0 18 18"
                                                xmlns="http://www.w3.org/2000/svg">
                                                <path
                                                    d="M12.5944 8.99987C12.5944 10.988 10.9826 12.5998 8.99443 12.5998C7.00627 12.5998 5.39465 10.988 5.39465 8.99987C5.39465 7.0117 7.00627 5.40009 8.99443 5.40009C10.9826 5.40009 12.5944 7.0117 12.5944 8.99987Z" />
                                            </svg>
                                            <input type="text" name="dropoff_location" id="rentalDropoffLocation"
                                                class="location-search" placeholder="Dropoff Location"
                                                value="{{ $searchData['dropoff_location'] }}">
                                            <input type="hidden" name="dropoff_lat" id="rentalDropoffLat"
                                                value="{{ $searchData['dropoff_lat'] }}">
                                            <input type="hidden" name="dropoff_lng" id="rentalDropoffLng"
                                                value="{{ $searchData['dropoff_lng'] }}">
                                        </div>
                                    </div>

                                    <!-- Pickup Section -->
                                    <div class="form-section">
                                        <label class="form-section-label">Pickup</label>
                                        <div class="row g-2">
                                            <div class="col-8">
                                                <div class="single-search-box date-field">
                                                    <svg width="18" height="18" viewBox="0 0 18 18"
                                                        xmlns="http://www.w3.org/2000/svg">
                                                        <path
                                                            d="M15 2h-1V1c0-.55-.45-1-1-1s-1 .45-1 1v1H6V1c0-.55-.45-1-1-1S4 .45 4 1v1H3c-1.1 0-2 .9-2 2v11c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 13H3V7h12v8z" />
                                                    </svg>
                                                    <input type="date" name="pickup_date" id="rentalPickupDate"
                                                        class="custom-datepicker"
                                                        value="{{ $searchData['pickup_date'] }}"
                                                        min="{{ date('Y-m-d') }}" required>
                                                </div>
                                            </div>
                                            <div class="col-4">
                                                <div class="single-search-box">
                                                    <svg width="18" height="18" viewBox="0 0 18 18"
                                                        xmlns="http://www.w3.org/2000/svg">
                                                        <path
                                                            d="M9 1C4.03 1 0 5.03 0 10s4.03 9 9 9 9-4.03 9-9-4.03-9-9-9zm0 16c-3.87 0-7-3.13-7-7s3.13-7 7-7 7 3.13 7 7-3.13 7-7 7z" />
                                                        <path d="M9.5 5H8v5l4.25 2.52.75-1.23L9.5 9V5z" />
                                                    </svg>
                                                    <select name="pickup_time" id="rentalPickupTime" class="form-select"
                                                        required>
                                                        @for ($hour = 0; $hour < 24; $hour++)
                                                            @for ($min = 0; $min < 60; $min += 30)
                                                                @php
                                                                    $time = sprintf('%02d:%02d', $hour, $min);
                                                                    $selected =
                                                                        $searchData['pickup_time'] === $time
                                                                            ? 'selected'
                                                                            : '';
                                                                @endphp
                                                                <option value="{{ $time }}" {{ $selected }}>
                                                                    {{ $time }}</option>
                                                            @endfor
                                                        @endfor
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Return Section -->
                                    <div class="form-section">
                                        <label class="form-section-label">Return</label>
                                        <div class="row g-2">
                                            <div class="col-8">
                                                <div class="single-search-box date-field">
                                                    <svg width="18" height="18" viewBox="0 0 18 18"
                                                        xmlns="http://www.w3.org/2000/svg">
                                                        <path
                                                            d="M15 2h-1V1c0-.55-.45-1-1-1s-1 .45-1 1v1H6V1c0-.55-.45-1-1-1S4 .45 4 1v1H3c-1.1 0-2 .9-2 2v11c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 13H3V7h12v8z" />
                                                    </svg>
                                                    <input type="date" name="return_date" id="rentalReturnDate"
                                                        class="custom-datepicker"
                                                        value="{{ $searchData['return_date'] }}"
                                                        min="{{ date('Y-m-d') }}" required>
                                                </div>
                                            </div>
                                            <div class="col-4">
                                                <div class="single-search-box">
                                                    <svg width="18" height="18" viewBox="0 0 18 18"
                                                        xmlns="http://www.w3.org/2000/svg">
                                                        <path
                                                            d="M9 1C4.03 1 0 5.03 0 10s4.03 9 9 9 9-4.03 9-9-4.03-9-9-9zm0 16c-3.87 0-7-3.13-7-7s3.13-7 7-7 7 3.13 7 7-3.13 7-7 7z" />
                                                        <path d="M9.5 5H8v5l4.25 2.52.75-1.23L9.5 9V5z" />
                                                    </svg>
                                                    <select name="return_time" id="rentalReturnTime" class="form-select"
                                                        required>
                                                        @for ($hour = 0; $hour < 24; $hour++)
                                                            @for ($min = 0; $min < 60; $min += 30)
                                                                @php
                                                                    $time = sprintf('%02d:%02d', $hour, $min);
                                                                    $selected =
                                                                        $searchData['return_time'] === $time
                                                                            ? 'selected'
                                                                            : '';
                                                                @endphp
                                                                <option value="{{ $time }}" {{ $selected }}>
                                                                    {{ $time }}</option>
                                                            @endfor
                                                        @endfor
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Duration & Pricing Section -->
                                    <div class="form-section">
                                        <div class="duration-display mb-3">
                                            <i class="bi bi-calendar-event"></i>
                                            <span>Duration: <strong id="rentalDurationText">Calculating...</strong></span>
                                        </div>

                                        <div class="pricing-display">
                                            <div id="rentalPricingLoader" class="text-center">
                                                <div class="spinner-border spinner-border-sm" role="status"></div>
                                                <span class="ms-2">Calculating price...</span>
                                            </div>
                                            <div id="rentalPricingContent" style="display: none;">
                                                <div class="price-display-large">
                                                    <span class="price-label">Price per Day</span>
                                                    <span class="price-amount"
                                                        id="rentalTotalPrice">{{ getCurrencySymbol() }} 0</span>
                                                    <span class="price-secondary-info" id="rentalTotalPriceInfo"
                                                        style="display: none;">
                                                        Total: <strong
                                                            id="rentalTotalPriceValue">{{ getCurrencySymbol() }}
                                                            0</strong>
                                                    </span>
                                                </div>
                                            </div>
                                            <div id="rentalPricingError" style="display: none;"
                                                class="text-danger text-center">
                                                <i class="bi bi-exclamation-triangle"></i> Unable to calculate price
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Action Buttons -->
                                    <div class="d-grid gap-2">
                                        <button type="button" class="btn btn-primary btn-lg" id="rentalAddToCartBtn">
                                            <i class="bi bi-cart-plus"></i> Add to Cart
                                        </button>
                                        <button type="button" class="btn btn-success btn-lg" id="rentalBookNowBtn">
                                            <i class="bi bi-calendar-check"></i> Book Now
                                        </button>
                                    </div>
                                </form>

                                <!-- Day Rental Form -->
                                <form id="day_rental-form"
                                    class="filter-input {{ $searchData['service_type'] === 'day_rental' ? 'show' : '' }}"
                                    data-service="day_rental">
                                    @csrf
                                    <input type="hidden" name="vehicle_group_id" value="{{ $vehicleGroup->id }}">
                                    <input type="hidden" name="service_type" value="day_rental">

                                    <!-- Location Section -->
                                    <div class="form-section">
                                        <h6 class="section-title"><i class="bi bi-geo-alt"></i> Pickup Location</h6>
                                        <div class="row g-2">
                                            <div class="col-12">
                                                <div class="single-search-box location-search-box">
                                                    <svg width="18" height="18" viewBox="0 0 18 18"
                                                        xmlns="http://www.w3.org/2000/svg">
                                                        <path
                                                            d="M9 0C5.13 0 2 3.13 2 7c0 5.25 7 11 7 11s7-5.75 7-11c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z" />
                                                    </svg>
                                                    <input type="text" name="pickup_location"
                                                        id="dayRentalPickupLocation" class="location-search"
                                                        value="{{ $searchData['pickup_location'] ?? 'Colombo, Sri Lanka' }}"
                                                        placeholder="Enter pickup location" required>
                                                    <input type="hidden" name="pickup_lat" id="dayRentalPickupLat"
                                                        value="{{ $searchData['pickup_lat'] ?? '' }}">
                                                    <input type="hidden" name="pickup_lng" id="dayRentalPickupLng"
                                                        value="{{ $searchData['pickup_lng'] ?? '' }}">
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Date & Time Section -->
                                    <div class="form-section">
                                        <h6 class="section-title"><i class="bi bi-calendar3"></i> Date & Time</h6>
                                        <div class="row g-2">
                                            <div class="col-8">
                                                <div class="single-search-box">
                                                    <svg width="18" height="18" viewBox="0 0 18 18"
                                                        xmlns="http://www.w3.org/2000/svg">
                                                        <path
                                                            d="M15 2h-1V0h-2v2H6V0H4v2H3C1.89 2 1 2.89 1 4v12c0 1.1.89 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.11-.9-2-2-2zm0 14H3V7h12v9z" />
                                                    </svg>
                                                    <input type="date" name="date" id="dayRentalDate"
                                                        class="form-control"
                                                        value="{{ $searchData['from_date'] ?? date('Y-m-d') }}"
                                                        min="{{ date('Y-m-d') }}" required>
                                                </div>
                                            </div>
                                            <div class="col-4">
                                                <div class="single-search-box">
                                                    <svg width="18" height="18" viewBox="0 0 18 18"
                                                        xmlns="http://www.w3.org/2000/svg">
                                                        <path
                                                            d="M9 1C4.03 1 0 5.03 0 10s4.03 9 9 9 9-4.03 9-9-4.03-9-9-9zm0 16c-3.87 0-7-3.13-7-7s3.13-7 7-7 7 3.13 7 7-3.13 7-7 7z" />
                                                        <path d="M9.5 5H8v5l4.25 2.52.75-1.23L9.5 9V5z" />
                                                    </svg>
                                                    <select name="time" id="dayRentalTime" class="form-select"
                                                        required>
                                                        @for ($hour = 6; $hour < 22; $hour++)
                                                            @for ($min = 0; $min < 60; $min += 30)
                                                                @php
                                                                    $time = sprintf('%02d:%02d', $hour, $min);
                                                                    $selected =
                                                                        ($searchData['from_time'] ?? '08:00') === $time
                                                                            ? 'selected'
                                                                            : '';
                                                                @endphp
                                                                <option value="{{ $time }}" {{ $selected }}>
                                                                    {{ $time }}</option>
                                                            @endfor
                                                        @endfor
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Number of Days Section -->
                                    <div class="form-section">
                                        <h6 class="section-title"><i class="bi bi-calendar-range"></i> Duration</h6>
                                        <div class="row g-2">
                                            <div class="col-12">
                                                <div class="single-search-box">
                                                    <select name="num_days" id="dayRentalNumDays" class="form-select"
                                                        required>
                                                        <option value="1" selected>1 Day</option>
                                                        <option value="2">2 Days</option>
                                                        <option value="3">3 Days</option>
                                                        <option value="4">4 Days</option>
                                                        <option value="5">5 Days</option>
                                                        <option value="6">6 Days</option>
                                                        <option value="7">7 Days</option>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Package Selection -->
                                    <div class="form-section" id="dayRentalPackageSection">
                                        <h6 class="section-title"><i class="bi bi-box"></i> Package</h6>
                                        <div class="package-selector" id="day_rental-packages-detail">
                                            <div class="transfer-type-toggle package-selector-toggle">
                                                <!-- Packages will be dynamically loaded -->
                                            </div>
                                            <div class="loading-packages" style="display: none;">
                                                <span>Loading packages...</span>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Pricing Section -->
                                    <div class="form-section">
                                        <div class="pricing-display">
                                            <div id="dayRentalPricingLoader" class="text-center">
                                                <div class="spinner-border spinner-border-sm" role="status"></div>
                                                <span class="ms-2">Calculating price...</span>
                                            </div>
                                            <div id="dayRentalPricingContent" style="display: none;">
                                                <div class="price-display-large">
                                                    <span class="price-label">Price per Day</span>
                                                    <span class="price-amount"
                                                        id="dayRentalTotalPrice">{{ getCurrencySymbol() }} 0</span>
                                                    <span class="price-secondary-info" id="dayRentalTotalPriceInfo"
                                                        style="display: none;">
                                                        Total: <strong
                                                            id="dayRentalTotalPriceValue">{{ getCurrencySymbol() }}
                                                            0</strong>
                                                    </span>
                                                </div>
                                            </div>
                                            <div id="dayRentalPricingError" style="display: none;"
                                                class="text-danger text-center">
                                                <i class="bi bi-exclamation-triangle"></i> Unable to calculate price
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Action Buttons -->
                                    <div class="d-grid gap-2">
                                        <button type="button" class="btn btn-primary btn-lg" id="dayRentalAddToCartBtn">
                                            <i class="bi bi-cart-plus"></i> Add to Cart
                                        </button>
                                        <button type="button" class="btn btn-success btn-lg" id="dayRentalBookNowBtn">
                                            <i class="bi bi-calendar-check"></i> Book Now
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('styles')
    <style>
        /* ==================== THEME COLORS ==================== */
        :root {
            --primary-color: #BF2629;
            --black-color: #717171;
            --white-color: #ffffff;
            --border-color: #e0e0e0;
            --success-color: #28a745;
            --danger-color: #dc3545;
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.08);
            --shadow-md: 0 4px 16px rgba(0, 0, 0, 0.12);
            --shadow-lg: 0 8px 24px rgba(0, 0, 0, 0.15);
        }

        /* ==================== MAIN LAYOUT ==================== */
        .vehicle-details-wrapper {
            background-color: #f8f9fa;
        }

        /* ==================== IMAGE GALLERY ==================== */
        .vehicle-image-gallery .main-vehicle-image {
            border-radius: 16px;
            overflow: hidden;
            box-shadow: var(--shadow-md);
        }

        .vehicle-image-gallery .main-vehicle-image img {
            width: 100%;
            height: 400px;
            object-fit: cover;
            transition: transform 0.3s ease;
        }

        .thumbnail-img {
            border: 2px solid transparent;
            border-radius: 8px;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .thumbnail-img:hover,
        .thumbnail-img.active {
            border-color: var(--primary-color);
            transform: scale(1.05);
            box-shadow: var(--shadow-sm);
        }

        /* ==================== VEHICLE INFO CARD ==================== */
        .vehicle-info-card {
            background: var(--white-color);
            padding: 30px;
            border-radius: 16px;
            box-shadow: var(--shadow-md);
        }

        .vehicle-title {
            color: #333;
            font-weight: 700;
            font-size: 28px;
            margin-bottom: 15px;
            line-height: 1.3;
        }

        .vehicle-description {
            color: var(--black-color);
            font-size: 16px;
            line-height: 1.6;
            margin-bottom: 25px;
        }

        .spec-item,
        .feature-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 1px solid #f0f0f0;
        }

        .spec-item:last-child,
        .feature-item:last-child {
            border-bottom: none;
        }

        .spec-item i,
        .feature-item i {
            font-size: 18px;
            color: var(--primary-color);
            width: 20px;
            text-align: center;
        }

        /* ==================== BOOKING FORM CARD ==================== */
        .booking-form-card {
            top: 20px;
        }

        .vehicle-detail-form .single-item {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
            padding: 12px 8px;
            border: 2px solid var(--border-color);
            border-radius: 12px;
            background: var(--white-color);
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
        }

        .vehicle-detail-form .single-item:hover {
            border-color: var(--primary-color);
            background: rgba(191, 38, 41, 0.05);
        }

        .vehicle-detail-form .single-item.active {
            border-color: var(--primary-color);
            background: var(--primary-color);
            color: var(--white-color);
        }

        .vehicle-detail-form .single-item svg {
            width: 18px;
            height: 18px;
            fill: currentColor;
        }

        .vehicle-detail-form .single-item span {
            font-size: 11px;
            font-weight: 600;
            line-height: 1.2;
        }

        /* ==================== FORM STYLING ==================== */
        .filter-input-wrap {
            background: var(--white-color);
            border-radius: 16px;
            padding: 25px;
            box-shadow: var(--shadow-md);
        }

        .filter-input {
            display: none;
        }

        .filter-input.show {
            display: block;
        }

        /* Single Search Box */
        .single-search-box {
            position: relative;
            margin-bottom: 16px;
        }

        .single-search-box svg {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            fill: var(--black-color);
            z-index: 2;
        }

        .single-search-box input,
        .single-search-box select {
            width: 100%;
            padding: 12px 15px 12px 45px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s ease;
            background: var(--white-color);
        }

        .single-search-box input:focus,
        .single-search-box select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(191, 38, 41, 0.1);
        }

        /* Transfer Type Selector */
        .transfer-type-selector {
            margin-bottom: 20px;
        }

        .transfer-type-toggle {
            display: flex;
            background: #f8f9fa;
            border-radius: 8px;
            padding: 4px;
            gap: 4px;
        }

        .transfer-type-option {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
            margin: 0;
        }

        .transfer-type-option input[type="radio"] {
            display: none;
        }

        .transfer-type-option input[type="radio"]:checked+span {
            background: var(--primary-color);
            color: var(--white-color);
        }

        .transfer-type-option span {
            padding: 10px 16px;
            border-radius: 6px;
            transition: all 0.3s ease;
            width: 100%;
            text-align: center;
            font-size: 14px;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        /* ==================== FORM SECTION ORGANIZATION ==================== */
        .form-section {
            /* margin-bottom: 24px;
                    padding-bottom: 20px;
                    border-bottom: 1px solid #f0f0f0; */
        }

        .form-section:last-of-type {
            border-bottom: none;
            margin-bottom: 20px;
            padding-bottom: 0;
        }

        .form-section-label {
            display: block;
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            color: #333;
            margin-bottom: 12px;
            letter-spacing: 0.5px;
        }

        .filter-wrapper .filter-input-wrap .filter-input.show {
            display: unset;
        }

        .filter-wrapper {
            margin-top: 50px;
        }

        .vehicle-detail-form .filter-item-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin: -30px 15px;
            padding: 0;
            list-style: none;
        }

        /* Duration Display */
        .duration-display {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }

        .duration-display i {
            color: var(--primary-color);
            font-size: 16px;
        }

        /* Pricing Display */
        .pricing-display {
            background: linear-gradient(135deg, rgba(191, 38, 41, 0.05) 0%, rgba(191, 38, 41, 0.1) 100%);
            border: 2px solid rgba(191, 38, 41, 0.2);
            border-radius: 12px;
            padding: 16px;
            min-height: 80px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .price-breakdown {
            font-size: 14px;
            width: 100%;
        }

        .price-breakdown .d-flex {
            align-items: center;
        }

        .price-display-large {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            width: 100%;
            gap: 8px;
        }

        .price-label {
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            color: #666;
            letter-spacing: 0.5px;
        }

        .price-amount {
            font-size: 28px;
            font-weight: 700;
            color: var(--primary-color);
        }

        .price-secondary-info {
            display: block;
            font-size: 12px;
            color: #666;
            margin-top: 6px;
            padding-top: 6px;
            border-top: 1px solid rgba(0, 0, 0, 0.1);
        }

        /* Action Buttons */
        .btn {
            border-radius: 8px;
            font-weight: 600;
            padding: 12px 20px;
            transition: all 0.3s ease;
        }

        .btn-lg {
            padding: 14px 24px;
            font-size: 15px;
        }

        .btn-primary {
            background: var(--primary-color);
            border-color: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: #a01f22;
            border-color: #a01f22;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(191, 38, 41, 0.3);
        }

        .btn-success {
            background: var(--success-color);
            border-color: var(--success-color);
            color: white;
        }

        .btn-success:hover {
            background: #218838;
            border-color: #218838;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
        }

        /* Spinner */
        .spinner-border-sm {
            width: 1rem;
            height: 1rem;
            color: var(--primary-color);
        }

        /* ==================== RESPONSIVE DESIGN ==================== */
        @media (max-width: 991.98px) {
            .booking-form-card {
                position: static !important;
                top: auto !important;
                margin-top: 30px;
            }

            .vehicle-image-gallery .main-vehicle-image img {
                height: 250px;
            }

            .filter-input-wrap {
                padding: 20px;
            }

            .vehicle-detail-form .single-item {
                min-width: calc(25% - 6px);
                padding: 8px 6px;
            }

            .vehicle-detail-form .single-item span {
                font-size: 10px;
            }

            .form-section {
                margin-bottom: 18px;
                padding-bottom: 16px;
            }
        }

        @media (max-width: 575.98px) {
            .vehicle-detail-form .filter-item-list {
                flex-direction: column;
            }

            .vehicle-detail-form .single-item {
                min-width: 100%;
                flex-direction: row;
                justify-content: flex-start;
                text-align: left;
                padding: 12px 16px;
            }

            .vehicle-detail-form .single-item span {
                font-size: 13px;
            }

            .filter-input-wrap {
                padding: 16px;
            }

            .form-section {
                margin-bottom: 16px;
                padding-bottom: 12px;
            }

            .form-section-label {
                font-size: 12px;
                margin-bottom: 10px;
            }

            .single-search-box input,
            .single-search-box select {
                padding: 11px 12px 11px 40px;
                font-size: 13px;
            }

            .single-search-box svg {
                width: 16px;
                height: 16px;
                left: 12px;
            }

            .transfer-type-toggle {
                gap: 2px;
                padding: 3px;
            }

            .transfer-type-option span {
                padding: 8px 12px;
                font-size: 12px;
            }

            .duration-display {
                padding: 10px 12px;
                font-size: 13px;
            }

            .pricing-display {
                padding: 12px;
                min-height: 70px;
            }

            .price-amount {
                font-size: 24px;
            }

            .btn {
                padding: 11px 16px;
                font-size: 14px;
            }

            .btn-lg {
                padding: 12px 18px;
            }



            /* ==================== FORM VALIDATION ==================== */
            .is-invalid {
                border-color: var(--danger-color);
            }

            .is-invalid:focus {
                border-color: var(--danger-color);
                box-shadow: 0 0 0 3px rgba(220, 53, 69, 0.1);
            }

            .invalid-feedback {
                display: block;
                width: 100%;
                margin-top: 0.25rem;
                font-size: 0.875rem;
                color: var(--danger-color);
            }
    </style>
@endpush

@push('scripts')
    <!-- jQuery UI for autocomplete fallback -->
    <link rel="stylesheet" href="https://code.jquery.com/ui/1.13.2/themes/ui-lightness/jquery-ui.css">
    <script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js"></script>

    <script>
        $(document).ready(function() {
            // Initialize service switcher
            setupServiceSwitcher();

            // Initialize location search
            initializeLocationSearch();

            // Initialize forms
            initializeAirportForm();
            initializeRentalForm();
            initializeDayRentalForm();

            // Set up image gallery
            setupImageGallery();
        });

        function setupServiceSwitcher() {
            const filterItems = document.querySelectorAll('.vehicle-detail-form .single-item');
            const filterInputs = document.querySelectorAll('.filter-input');

            // Auto-select correct tab based on search service type
            const searchServiceType = '{{ $searchData['service_type'] ?? 'airport_transfers' }}';
            console.log('Search service type:', searchServiceType);

            // Find and click the matching tab
            const matchingItem = Array.from(filterItems).find(item => {
                const service = item.getAttribute('data-service');
                return service === searchServiceType;
            });

            if (matchingItem) {
                console.log('Auto-selecting tab for service:', searchServiceType);
                // Simulate click to activate the tab
                const clickEvent = new MouseEvent('click', {
                    bubbles: true,
                    cancelable: true,
                    view: window
                });
                matchingItem.dispatchEvent(clickEvent);
            }

            filterItems.forEach(item => {
                item.addEventListener('click', function(e) {
                    e.preventDefault();

                    const service = this.getAttribute('data-service');
                    const redirectUrl = this.getAttribute('data-redirect');

                    console.log('Service clicked:', service);

                    // If this tab has a redirect URL, navigate to it
                    if (redirectUrl) {
                        console.log('Redirecting to:', redirectUrl);
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

                    const targetForm = document.querySelector(`#${service}-form`);
                    console.log('Target form found:', targetForm);

                    if (targetForm) {
                        targetForm.classList.add('show');

                        // Initialize pricing for the newly shown form
                        if (service === 'airport_transfers') {
                            updateAirportPricing();
                        } else if (service === 'ride_now') {
                            updateRentalPricing();
                        } else if (service === 'day_rental') {
                            updateDayRentalPricing();
                        }
                    } else {
                        console.error('Form not found for service:', service);
                    }
                });
            });
        }

        function initializeLocationSearch() {
            // Initialize Google Places autocomplete if available
            if (typeof google !== 'undefined' && google.maps && google.maps.places) {
                initializeGoogleAutocomplete();
            } else {
                // Fallback to basic autocomplete
                initializeBasicAutocomplete();
            }
        }

        function initializeGoogleAutocomplete() {
            const locationInputs = document.querySelectorAll('.location-search');

            locationInputs.forEach(input => {
                // Add a data attribute to track if a valid place was selected
                input.setAttribute('data-place-selected', 'false');
                
                // Mark as invalid when user types (not selected from dropdown)
                input.addEventListener('input', function() {
                    this.setAttribute('data-place-selected', 'false');
                    this.classList.remove('is-valid');
                });

                // Check if this input should be restricted to airports only
                const isAirportField = isAirportLocationField(input);

                let options = {
                    componentRestrictions: {
                        country: 'lk'
                    },
                    fields: ['place_id', 'geometry', 'name', 'formatted_address']
                };

                // For airport fields in airport transfer mode, restrict to airports
                if (isAirportField) {
                    options.types = ['airport'];
                }

                const autocomplete = new google.maps.places.Autocomplete(input, options);

                autocomplete.addListener('place_changed', function() {
                    const place = autocomplete.getPlace();
                    
                    // Only mark as valid if a place with geometry was selected
                    if (place && place.geometry) {
                        input.setAttribute('data-place-selected', 'true');
                        input.classList.add('is-valid');
                        input.classList.remove('is-invalid');
                        updateLocationData(input, place);
                    } else {
                        input.setAttribute('data-place-selected', 'false');
                        input.classList.remove('is-valid');
                    }
                });
            });
        }

        function isAirportLocationField(input) {
            // Check if we're in airport transfer mode
            const activeForm = document.querySelector('.filter-input.show');
            if (!activeForm || !activeForm.id.includes('airport')) {
                return false;
            }

            // Check if this is a location field (pickup or dropoff)
            const inputName = input.getAttribute('name');
            return inputName && (inputName.includes('location'));
        }

        function initializeBasicAutocomplete() {
            const allCities = [
                "Colombo, Sri Lanka", "Galle, Sri Lanka", "Kandy, Sri Lanka",
                "Negombo, Sri Lanka", "Jaffna, Sri Lanka", "Trincomalee, Sri Lanka",
                "Colombo BIA Airport", "Mattala Rajapaksa Airport"
            ];

            const airportLocations = [
                "Colombo BIA Airport", "Mattala Rajapaksa Airport"
            ];

            const cityCoords = {
                "Colombo, Sri Lanka": {
                    lat: 6.9271,
                    lng: 79.8612
                },
                "Galle, Sri Lanka": {
                    lat: 6.0535,
                    lng: 80.221
                },
                "Kandy, Sri Lanka": {
                    lat: 7.2906,
                    lng: 80.6337
                },
                "Negombo, Sri Lanka": {
                    lat: 7.1408,
                    lng: 79.8636
                },
                "Jaffna, Sri Lanka": {
                    lat: 9.6615,
                    lng: 80.7855
                },
                "Trincomalee, Sri Lanka": {
                    lat: 8.5711,
                    lng: 81.2344
                },
                "Colombo BIA Airport": {
                    lat: 7.1808,
                    lng: 79.8841
                },
                "Mattala Rajapaksa Airport": {
                    lat: 6.2744,
                    lng: 81.1239
                }
            };

            $('.location-search').autocomplete({
                source: function(request, response) {
                    const term = request.term.toLowerCase();

                    // Check if this is an airport-restricted field
                    const isAirportField = $(this.element).parents('#airport_transfers-form').length > 0;

                    let availableLocations = isAirportField ? airportLocations : allCities;

                    const filtered = availableLocations.filter(city =>
                        city.toLowerCase().includes(term)
                    );

                    response(filtered);
                },
                minLength: 1,
                select: function(event, ui) {
                    $(this).val(ui.item.value);
                    const coords = cityCoords[ui.item.value];
                    if (coords) {
                        const inputName = $(this).attr('name');
                        const prefix = inputName.replace('_location', '');
                        $(`input[name="${prefix}_lat"]`).val(coords.lat);
                        $(`input[name="${prefix}_lng"]`).val(coords.lng);
                    }
                }
            });
        }

        function updateLocationData(input, place) {
            if (place.geometry && place.geometry.location) {
                const inputName = input.name;
                const prefix = inputName.replace('_location', '');

                document.querySelector(`input[name="${prefix}_lat"]`).value = place.geometry.location.lat();
                document.querySelector(`input[name="${prefix}_lng"]`).value = place.geometry.location.lng();
            }
        }

        function initializeAirportForm() {
            // Transfer type change handler
            $('input[name="transfer_type"]').on('change', function() {
                updateAirportTransferLocations($(this).val());
            });

            // Form field change handlers
            $('#airport_transfers-form').on('change', 'input, select', function() {
                updateAirportPricing();
            });

            // Action button handlers
            $('#airportAddToCartBtn').on('click', function() {
                addToCart('airport_transfers');
            });

            $('#airportBookNowBtn').on('click', function() {
                addToCart('airport_transfers', true);
            });

            // Initialize pricing
            updateAirportPricing();
        }

        function initializeRentalForm() {
            // Date validation
            $('#rentalPickupDate').on('change', function() {
                const pickupDate = $(this).val();
                $('#rentalReturnDate').attr('min', pickupDate);
                updateRentalDuration();
                updateRentalPricing();
            });

            $('#rentalReturnDate').on('change', function() {
                updateRentalDuration();
                updateRentalPricing();
            });

            // Form field change handlers
            $('#ride_now-form').on('change', 'input, select', function() {
                updateRentalPricing();
            });

            // Action button handlers
            $('#rentalAddToCartBtn').on('click', function() {
                addToCart('ride_now');
            });

            $('#rentalBookNowBtn').on('click', function() {
                addToCart('ride_now', true);
            });

            // Initialize
            updateRentalDuration();
            updateRentalPricing();
        }

        function initializeDayRentalForm() {
            // Date change handler
            $('#dayRentalDate').on('change', function() {
                updateDayRentalPricing();
            });

            // Number of days change handler
            $('#dayRentalNumDays').on('change', function() {
                updateDayRentalPricing();
            });

            // Form field change handlers
            $('#day_rental-form').on('change', 'input, select', function() {
                updateDayRentalPricing();
            });

            // Action button handlers
            $('#dayRentalAddToCartBtn').on('click', function() {
                addToCart('day_rental');
            });

            $('#dayRentalBookNowBtn').on('click', function() {
                addToCart('day_rental', true);
            });

            // Load packages for day rental
            loadDayRentalPackages();

            // Initialize pricing
            updateDayRentalPricing();
        }

        function loadDayRentalPackages() {
            const packageSelector = document.getElementById('day_rental-packages-detail');
            if (!packageSelector) return;

            const loadingIndicator = packageSelector.querySelector('.loading-packages');
            const packageToggle = packageSelector.querySelector('.package-selector-toggle');

            if (loadingIndicator) loadingIndicator.style.display = 'block';

            fetch('/api/services/day_rental/packages')
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success' && data.data.packages && data.data.packages.length > 0) {
                        packageToggle.innerHTML = '';
                        data.data.packages.forEach((pkg, index) => {
                            const label = document.createElement('label');
                            label.className = 'transfer-type-option package-option';

                            const input = document.createElement('input');
                            input.type = 'radio';
                            input.name = 'package_id';
                            input.value = pkg.id;
                            input.id = `day_rental-package-${index}`;
                            if (index === 0) input.checked = true;

                            const span = document.createElement('span');
                            span.title = pkg.description || '';
                            span.textContent = pkg.name;

                            input.addEventListener('change', function() {
                                updateDayRentalPricing();
                            });

                            label.appendChild(input);
                            label.appendChild(span);
                            packageToggle.appendChild(label);
                        });
                    }
                })
                .catch(error => {
                    console.error('Error loading day rental packages:', error);
                })
                .finally(() => {
                    if (loadingIndicator) loadingIndicator.style.display = 'none';
                });
        }

        function updateDayRentalPricing() {
            const formData = {
                service_type: 'day_rental',
                pickup_location: $('#dayRentalPickupLocation').val(),
                pickup_lat: $('#dayRentalPickupLat').val(),
                pickup_lng: $('#dayRentalPickupLng').val(),
                date: $('#dayRentalDate').val(),
                time: $('#dayRentalTime').val(),
                num_days: $('#dayRentalNumDays').val(),
                package_id: $('input[name="package_id"]:checked', '#day_rental-form').val()
            };

            updatePricing(formData, 'dayRental');
        }

        function updateAirportTransferLocations(type) {
            const fromInput = document.getElementById('airportFromLocation');
            const toInput = document.getElementById('airportToLocation');
            const fromLatInput = document.getElementById('airportFromLat');
            const fromLngInput = document.getElementById('airportFromLng');
            const toLatInput = document.getElementById('airportToLat');
            const toLngInput = document.getElementById('airportToLng');

            if (type === 'from-airport') {
                fromInput.value = 'Colombo BIA Airport';
                fromLatInput.value = '7.1808';
                fromLngInput.value = '79.8841';
                toInput.value = 'Colombo, Sri Lanka';
                toLatInput.value = '6.9271';
                toLngInput.value = '79.8612';
            } else if (type === 'to-airport') {
                fromInput.value = 'Colombo, Sri Lanka';
                fromLatInput.value = '6.9271';
                fromLngInput.value = '79.8612';
                toInput.value = 'Colombo BIA Airport';
                toLatInput.value = '7.1808';
                toLngInput.value = '79.8841';
            }

            updateAirportPricing();
        }

        function updateRentalDuration() {
            const pickupDate = $('#rentalPickupDate').val();
            const returnDate = $('#rentalReturnDate').val();

            if (!pickupDate || !returnDate) {
                $('#rentalDurationText').text('Please select dates');
                return;
            }

            const pickup = new Date(pickupDate);
            const returnD = new Date(returnDate);
            const diffTime = Math.abs(returnD - pickup);
            const diffDays = Math.max(1, Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1);

            $('#rentalDurationText').text(diffDays + ' day' + (diffDays !== 1 ? 's' : ''));
        }

        function updateAirportPricing() {
            const formData = {
                service_type: 'airport_transfers',
                transfer_type: $('input[name="transfer_type"]:checked').val(),
                pickup_location: $('#airportFromLocation').val(),
                dropoff_location: $('#airportToLocation').val(),
                pickup_lat: $('#airportFromLat').val(),
                pickup_lng: $('#airportFromLng').val(),
                dropoff_lat: $('#airportToLat').val(),
                dropoff_lng: $('#airportToLng').val(),
                pickup_date: $('#airportPickupDate').val(),
                pickup_time: $('#airportPickupTime').val()
            };

            updatePricing(formData, 'airport');
        }

        function updateRentalPricing() {
            const formData = {
                service_type: 'ride_now',
                pickup_location: $('#rentalPickupLocation').val(),
                dropoff_location: $('#rentalDropoffLocation').val(),
                pickup_lat: $('#rentalPickupLat').val(),
                pickup_lng: $('#rentalPickupLng').val(),
                dropoff_lat: $('#rentalDropoffLat').val(),
                dropoff_lng: $('#rentalDropoffLng').val(),
                pickup_date: $('#rentalPickupDate').val(),
                pickup_time: $('#rentalPickupTime').val(),
                return_date: $('#rentalReturnDate').val(),
                return_time: $('#rentalReturnTime').val()
            };

            updatePricing(formData, 'rental');
        }

        function updatePricing(formData, prefix) {
            // Show loader
            $(`#${prefix}PricingLoader`).show();
            $(`#${prefix}PricingContent`).hide();
            $(`#${prefix}PricingError`).hide();

            $.ajax({
                url: '{{ route('vehicle.updatePricing', $vehicleGroup->id) }}',
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                },
                data: formData,
                success: function(response) {
                    if (response.success && response.pricing) {
                        const pricing = response.pricing;
                        const totalAmount = pricing.base_amount || 0;
                        const currency = '{{ getCurrencySymbol() }}';

                        if (prefix === 'rental') {
                            // For rental package: show price per day
                            const pickupDate = $('#rentalPickupDate').val();
                            const returnDate = $('#rentalReturnDate').val();

                            if (pickupDate && returnDate) {
                                const pickup = new Date(pickupDate);
                                const returnD = new Date(returnDate);
                                const diffTime = Math.abs(returnD - pickup);
                                const diffDays = Math.max(1, Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1);

                                const perDayPrice = totalAmount / diffDays;
                                $(`#${prefix}TotalPrice`).text(currency + ' ' + formatPrice(perDayPrice));

                                // Show total price as secondary info
                                $(`#${prefix}TotalPriceValue`).text(currency + ' ' + formatPrice(totalAmount));
                                $(`#${prefix}TotalPriceInfo`).show();
                            } else {
                                $(`#${prefix}TotalPrice`).text(currency + ' ' + formatPrice(totalAmount));
                                $(`#${prefix}TotalPriceInfo`).hide();
                            }
                        } else {
                            // For airport transfer: show total price (one-way)
                            $(`#${prefix}TotalPrice`).text(currency + ' ' + formatPrice(totalAmount));
                        }

                        $(`#${prefix}PricingLoader`).hide();
                        $(`#${prefix}PricingContent`).show();
                    } else {
                        showPricingError(prefix);
                    }
                },
                error: function() {
                    showPricingError(prefix);
                }
            });
        }

        function showPricingError(prefix) {
            $(`#${prefix}PricingLoader`).hide();
            $(`#${prefix}PricingContent`).hide();
            $(`#${prefix}PricingError`).show();
        }

        function addToCart(serviceType, bookNow = false) {
            const form = document.getElementById(`${serviceType}-form`);
            const formData = new FormData(form);

            // Add additional data
            formData.append('group_name', '{{ $vehicleGroup->name }}');

            // Get the price based on service type
            const prefix = serviceType === 'airport_transfers' ? 'airport' : 'rental';
            const priceText = $(`#${prefix}TotalPrice`).text();
            formData.append('base_price', priceText.replace(/[^\d.]/g, ''));

            $.ajax({
                url: '{{ route('cart.add') }}',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        // Update cart icon
                        // Dispatch cart updated event
                        window.dispatchEvent(new CustomEvent('cartUpdated'));

                        if (bookNow) {
                            // Redirect to cart for booking
                            window.location.href = '{{ route('cart') }}';
                        } else {
                            // Show success message
                            showSuccessNotification('Vehicle added to cart successfully!');
                        }
                    } else {
                        showErrorNotification('Error: ' + response.message);
                    }
                },
                error: function(xhr) {
                    const errorMsg = xhr.responseJSON?.message || 'Error adding to cart. Please try again.';
                    showErrorNotification(errorMsg);
                }
            });
        }

        function setupImageGallery() {
            // Image gallery functionality
            window.changeMainImage = function(imageSrc) {
                $('#mainVehicleImage').attr('src', imageSrc);
                $('.thumbnail-img').removeClass('active');
                $(event.target).addClass('active');
            };
        }

        // Helper functions
        function formatPrice(amount) {
            return new Intl.NumberFormat().format(Math.round(amount));
        }

        function showSuccessNotification(message) {
            const alert = $(`
                <div class="alert alert-success alert-dismissible fade show" role="alert" style="position: fixed; top: 20px; right: 20px; z-index: 1100; min-width: 300px;">
                    <i class="bi bi-check-circle-fill me-2"></i>
                    ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            `);
            $('body').append(alert);
            setTimeout(() => alert.alert('close'), 4000);
        }

        function showErrorNotification(message) {
            const alert = $(`
                <div class="alert alert-danger alert-dismissible fade show" role="alert" style="position: fixed; top: 20px; right: 20px; z-index: 1100; min-width: 300px;">
                    <i class="bi bi-exclamation-circle-fill me-2"></i>
                    ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            `);
            $('body').append(alert);
            setTimeout(() => alert.alert('close'), 5000);
        }
    </script>
@endpush
