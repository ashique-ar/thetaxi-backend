@extends('layouts.app')

@section('title', ($vehicleGroup->name ?? 'Vehicle Details') . ' - TheTaxi')

@section('content')
    <!-- Breadcrumb section Start-->
    <div class="breadcrumb-section three"
        style="background-image:linear-gradient(rgba(0, 0, 0, 0.3), rgba(0, 0, 0, 0.3)), url({{ asset('assets/img/innerpages/breadcrumb-bg6.jpg') }});">
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
                        // dd($vehicleGroup);
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
                            <h4>Specifications</h4>
                            <div class="row g-3">
                                @if ($vehicleGroup->seating_capacity)
                                    <div class="col-md-6">
                                        <div class="spec-item">
                                            <i class="bi bi-people-fill text-primary"></i>
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
                    <div class="booking-form-card sticky-top">
                        <div class="card shadow">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0">
                                    <i class="bi bi-calendar-check"></i>
                                    Book This Vehicle
                                </h5>
                            </div>
                            <div class="card-body">
                                <form id="vehicleBookingForm">
                                    @csrf
                                    <input type="hidden" name="vehicle_group_id" value="{{ $vehicleGroup->id }}">

                                    <!-- Service Type -->
                                    <div class="mb-3">
                                        <label class="form-label">Service Type</label>
                                        <select name="service_type" id="serviceType" class="form-select" required>
                                            @foreach ($serviceTypes as $serviceType)
                                                <option value="{{ $serviceType->code }}"
                                                    {{ $searchData['service_type'] === $serviceType->code ? 'selected' : '' }}>
                                                    {{ $serviceType->name }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>

                                    <!-- Pickup Location -->
                                    <div class="mb-3">
                                        <label class="form-label">Pickup Location</label>
                                        <input type="text" name="pickup_location" id="pickupLocation"
                                            class="form-control location-input"
                                            value="{{ $searchData['pickup_location'] }}" placeholder="Enter pickup address"
                                            required>
                                        <input type="hidden" name="pickup_lat" id="pickupLat"
                                            value="{{ $searchData['pickup_lat'] }}">
                                        <input type="hidden" name="pickup_lng" id="pickupLng"
                                            value="{{ $searchData['pickup_lng'] }}">
                                    </div>

                                    <!-- Dropoff Location -->
                                    <div class="mb-3">
                                        <label class="form-label">Dropoff Location</label>
                                        <input type="text" name="dropoff_location" id="dropoffLocation"
                                            class="form-control location-input"
                                            value="{{ $searchData['dropoff_location'] }}"
                                            placeholder="Enter dropoff address">
                                        <input type="hidden" name="dropoff_lat" id="dropoffLat"
                                            value="{{ $searchData['dropoff_lat'] }}">
                                        <input type="hidden" name="dropoff_lng" id="dropoffLng"
                                            value="{{ $searchData['dropoff_lng'] }}">
                                    </div>

                                    <!-- Pickup Date & Time -->
                                    <div class="row g-2 mb-3">
                                        <div class="col-7">
                                            <label class="form-label">Pickup Date</label>
                                            <input type="date" name="pickup_date" id="pickupDate" class="form-control"
                                                value="{{ $searchData['pickup_date'] }}" min="{{ date('Y-m-d') }}"
                                                required>
                                        </div>
                                        <div class="col-5">
                                            <label class="form-label">Time</label>
                                            <input type="time" name="pickup_time" id="pickupTime"
                                                class="form-control" value="{{ $searchData['pickup_time'] }}">
                                        </div>
                                    </div>

                                    <!-- Return Date & Time -->
                                    <div class="row g-2 mb-3">
                                        <div class="col-7">
                                            <label class="form-label">Return Date</label>
                                            <input type="date" name="return_date" id="returnDate"
                                                class="form-control" value="{{ $searchData['return_date'] }}"
                                                min="{{ date('Y-m-d') }}">
                                        </div>
                                        <div class="col-5">
                                            <label class="form-label">Time</label>
                                            <input type="time" name="return_time" id="returnTime"
                                                class="form-control" value="{{ $searchData['return_time'] }}">
                                        </div>
                                    </div>

                                    <!-- Duration Display -->
                                    <div class="duration-display mb-3 p-2 bg-light rounded">
                                        <i class="bi bi-calendar-event text-primary"></i>
                                        <span>Duration: <strong id="durationText">Calculating...</strong></span>
                                    </div>

                                    <!-- Pricing Display -->
                                    <div class="pricing-display mb-4 p-3 bg-primary bg-opacity-10 rounded">
                                        <div id="pricingLoader" class="text-center">
                                            <div class="spinner-border spinner-border-sm text-primary" role="status">
                                            </div>
                                            <span class="ms-2">Calculating price...</span>
                                        </div>
                                        <div id="pricingContent" style="display: none;">
                                            <div class="price-breakdown">
                                                <div class="d-flex justify-content-between mb-2">
                                                    <span>Base Price:</span>
                                                    <strong id="basePrice">{{ getCurrencySymbol() }} 0</strong>
                                                </div>
                                                <div class="d-flex justify-content-between mb-2">
                                                    <span>Duration:</span>
                                                    <span id="pricingDuration">1 day</span>
                                                </div>
                                                <hr>
                                                <div class="d-flex justify-content-between">
                                                    <strong>Total Price:</strong>
                                                    <strong class="text-primary"
                                                        id="totalPrice">{{ getCurrencySymbol() }} 0</strong>
                                                </div>
                                            </div>
                                        </div>
                                        <div id="pricingError" class="text-danger text-center" style="display: none;">
                                            <i class="bi bi-exclamation-triangle"></i>
                                            <span>Unable to calculate price</span>
                                        </div>
                                    </div>
                                </form>

                                <!-- Action Buttons -->
                                <div class="d-grid gap-2">
                                    <button type="button" class="btn btn-success btn-lg" id="addToCartBtn">
                                        <i class="bi bi-cart-plus"></i> Add to Cart
                                    </button>
                                    <button type="button" class="btn btn-primary" id="bookNowBtn">
                                        <i class="bi bi-calendar-check"></i> Book Now
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary" id="inquireBtn">
                                        <i class="bi bi-envelope"></i> Make Inquiry
                                    </button>
                                </div>
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
        .vehicle-details-wrapper {
            background-color: #f8f9fa;
        }

        .vehicle-image-gallery .main-vehicle-image {
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .vehicle-image-gallery .main-vehicle-image img {
            width: 100%;
            height: 400px;
            object-fit: cover;
        }

        .thumbnail-img {
            border: 2px solid transparent;
            transition: all 0.3s ease;
        }

        .thumbnail-img:hover,
        .thumbnail-img.active {
            border-color: var(--primary-color1);
            transform: scale(1.05);
        }

        .vehicle-info-card {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .vehicle-title {
            color: #333;
            font-weight: 700;
            margin-bottom: 15px;
        }

        .spec-item,
        .feature-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 0;
        }

        .spec-item i,
        .feature-item i {
            font-size: 18px;
        }

        .booking-form-card {
            top: 20px;
            z-index: unset;
        }

        .duration-display {
            font-size: 14px;
        }

        .pricing-display {
            border: 1px solid rgba(var(--primary-color1), 0.2);
        }

        .price-breakdown {
            font-size: 14px;
        }

        .location-input {
            background: white;
        }

        @media (max-width: 991.98px) {
            .booking-form-card {
                position: static !important;
                top: auto !important;
            }

            .vehicle-image-gallery .main-vehicle-image img {
                height: 250px;
            }
        }
    </style>
@endpush

@push('scripts')
    <script>
        $(document).ready(function() {
            // Initialize form
            updateDurationAndPricing();

            // Form change handlers
            $('#vehicleBookingForm').on('change', 'input, select', function() {
                updateDurationAndPricing();
            });

            // Date validation
            $('#pickupDate').on('change', function() {
                const pickupDate = $(this).val();
                $('#returnDate').attr('min', pickupDate);

                // Auto-adjust return date if it's before pickup date
                const returnDate = $('#returnDate').val();
                if (returnDate && new Date(returnDate) < new Date(pickupDate)) {
                    const nextDay = new Date(pickupDate);
                    nextDay.setDate(nextDay.getDate() + 1);
                    $('#returnDate').val(nextDay.toISOString().split('T')[0]);
                }

                updateDurationAndPricing();
            });

            // Action button handlers
            $('#addToCartBtn').on('click', function() {
                addToCart();
            });

            $('#bookNowBtn').on('click', function() {
                addToCart(true); // Book now = add to cart + redirect
            });

            $('#inquireBtn').on('click', function() {
                makeInquiry();
            });
        });

        function changeMainImage(imageSrc) {
            $('#mainVehicleImage').attr('src', imageSrc);
            $('.thumbnail-img').removeClass('active');
            $(event.target).addClass('active');
        }

        function updateDurationAndPricing() {
            const pickupDate = $('#pickupDate').val();
            const returnDate = $('#returnDate').val();

            if (!pickupDate || !returnDate) {
                $('#durationText').text('Please select dates');
                return;
            }

            // Calculate duration (day-based)
            const pickup = new Date(pickupDate);
            const returnD = new Date(returnDate);
            const diffTime = Math.abs(returnD - pickup);
            const diffDays = Math.max(1, Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1); // +1 for inclusive days

            $('#durationText').text(diffDays + ' day' + (diffDays !== 1 ? 's' : ''));
            $('#pricingDuration').text(diffDays + ' day' + (diffDays !== 1 ? 's' : ''));

            // Update pricing
            updatePricing();
        }

        function updatePricing() {
            const formData = {
                service_type: $('#serviceType').val(),
                pickup_date: $('#pickupDate').val(),
                return_date: $('#returnDate').val(),
                pickup_time: $('#pickupTime').val(),
                return_time: $('#returnTime').val(),
                pickup_location: $('#pickupLocation').val(),
                dropoff_location: $('#dropoffLocation').val(),
                pickup_lat: $('#pickupLat').val(),
                pickup_lng: $('#pickupLng').val(),
                dropoff_lat: $('#dropoffLat').val(),
                dropoff_lng: $('#dropoffLng').val(),
            };

            // Show loader
            $('#pricingLoader').show();
            $('#pricingContent').hide();
            $('#pricingError').hide();

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

                        // Convert LKR to selected currency
                        const convertedAmount = convertPrice(totalAmount);

                        $('#basePrice').text(currency + ' ' + formatPrice(convertedAmount));
                        $('#totalPrice').text(currency + ' ' + formatPrice(convertedAmount));

                        $('#pricingLoader').hide();
                        $('#pricingContent').show();
                    } else {
                        showPricingError();
                    }
                },
                error: function() {
                    showPricingError();
                }
            });
        }

        function showPricingError() {
            $('#pricingLoader').hide();
            $('#pricingContent').hide();
            $('#pricingError').show();
        }

        function addToCart(bookNow = false) {
            const formData = new FormData($('#vehicleBookingForm')[0]);

            // Add additional data
            formData.append('group_name', '{{ $vehicleGroup->name }}');
            formData.append('base_price', $('#totalPrice').text().replace(/[^\d.]/g, ''));

            $.ajax({
                url: '{{ route('cart.add') }}',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        // Update cart icon
                        if (typeof window.refreshCartIcon === 'function') {
                            window.refreshCartIcon();
                        }

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

        function makeInquiry() {
            // Implement inquiry functionality
            alert('Inquiry functionality coming soon!');
        }

        // Helper functions (can be moved to global if needed)
        function convertPrice(lkrAmount) {
            // This should match the PHP convertPrice() function
            // For now, return as-is (assuming same currency)
            return lkrAmount;
        }

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