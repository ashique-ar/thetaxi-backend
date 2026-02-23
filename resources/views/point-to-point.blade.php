@extends('layouts.app')

@section('title', 'Point-to-Point Transfers')

@push('meta')
    @include('partials.seo')
@endpush

@section('content')
    <!-- Point-to-Point Banner Section Start-->
    <div class="home4-banner-section mb-100">
        <div class="banner-video-area">
            <video autoplay loop muted playsinline src="{{ asset('assets/video/home4-banner-video.mp4') }}"></video>
        </div>
        <div class="banner-content-wrap">
            <div class="container">
                <div class="banner-content">
                    <h1>Point-to-Point Transfers</h1>
                    <p>Reliable door-to-door transportation service for your convenience</p>

                    <!-- Point-to-Point Booking Form -->
                    <div class="filter-wrapper">
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
                                    <button type="button" class="btn-close" data-bs-dismiss="alert"
                                        aria-label="Close"></button>
                                </div>
                            @endif

                            <!-- Success Message Display -->
                            @if (session('success'))
                                <div class="alert alert-success alert-dismissible fade show" role="alert">
                                    {{ session('success') }}
                                    <button type="button" class="btn-close" data-bs-dismiss="alert"
                                        aria-label="Close"></button>
                                </div>
                            @endif

                            <!-- Drop & Pickup Form -->
                            <form id="point_to_point-form" class="filter-input show" data-service="point_to_point"
                                action="{{ route('booking.search') }}" method="GET">
                                @csrf
                                <input type="hidden" name="service_type" value="point_to_point">

                                <!-- Pickup Location -->
                                <div class="single-search-box location-search-box">
                                    <svg width="18" height="18" viewBox="0 0 18 18"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <path
                                            d="M9 0C4.037 0 0 4.037 0 9c0 6.75 9 9 9 9s9-2.25 9-9c0-4.963-4.037-9-9-9zm0 12.75c-2.07 0-3.75-1.68-3.75-3.75S6.93 5.25 9 5.25s3.75 1.68 3.75 3.75-1.68 3.75-3.75 3.75z" />
                                    </svg>
                                    <div class="custom-select-dropdown">
                                        <input type="text" name="pickup" placeholder="Pick up location"
                                            class="nice-select custom-location-search @error('pickup') is-invalid @enderror"
                                            value="{{ old('pickup', 'Colombo, Sri Lanka') }}" required autocomplete="off">
                                        <input type="hidden" name="pickup_lat" value="{{ old('pickup_lat', '6.9271') }}">
                                        <input type="hidden" name="pickup_lng" value="{{ old('pickup_lng', '79.8612') }}">
                                    </div>
                                    @error('pickup')
                                        <span class="text-danger small">{{ $message }}</span>
                                    @enderror
                                </div>

                                <!-- Drop Off Location -->
                                <div class="single-search-box location-search-box">
                                    <svg width="18" height="18" viewBox="0 0 18 18"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <path
                                            d="M9 0C4.037 0 0 4.037 0 9c0 6.75 9 9 9 9s9-2.25 9-9c0-4.963-4.037-9-9-9zm0 12.75c-2.07 0-3.75-1.68-3.75-3.75S6.93 5.25 9 5.25s3.75 1.68 3.75 3.75-1.68 3.75-3.75 3.75z" />
                                    </svg>
                                    <div class="custom-select-dropdown">
                                        <input type="text" name="dropoff" placeholder="Drop off location"
                                            class="nice-select custom-location-search @error('dropoff') is-invalid @enderror"
                                            value="{{ old('dropoff', 'Galle, Sri Lanka') }}" required autocomplete="off">
                                        <input type="hidden" name="dropoff_lat" value="{{ old('dropoff_lat', '6.0535') }}">
                                        <input type="hidden" name="dropoff_lng"
                                            value="{{ old('dropoff_lng', '80.221') }}">
                                    </div>
                                    @error('dropoff')
                                        <span class="text-danger small">{{ $message }}</span>
                                    @enderror
                                </div>

                                <!-- Date -->
                                <div class="single-search-box date-field">
                                    <svg width="18" height="18" viewBox="0 0 18 18"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <path
                                            d="M14 2h-1V1a1 1 0 0 0-2 0v1H7V1a1 1 0 0 0-2 0v1H4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2zM4 4h1v1a1 1 0 0 0 2 0V4h4v1a1 1 0 0 0 2 0V4h1v2H4V4zm0 4h10v6H4V8z" />
                                    </svg>
                                    <input type="text" name="date" placeholder="DD/MM/YYYY"
                                        class="nice-select custom-datepicker @error('date') is-invalid @enderror"
                                        value="{{ old('date', date('d/m/Y')) }}" required autocomplete="off">
                                    @error('date')
                                        <span class="text-danger small">{{ $message }}</span>
                                    @enderror
                                </div>

                                <!-- Time -->
                                <div class="single-search-box">
                                    <svg width="18" height="18" viewBox="0 0 18 18"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <path
                                            d="M9 0C4.037 0 0 4.037 0 9s4.037 9 9 9 9-4.037 9-9S13.963 0 9 0zm4.5 9.75h-4.5a.75.75 0 0 1-.75-.75V4.5a.75.75 0 0 1 1.5 0v3.75h3.75a.75.75 0 0 1 0 1.5z" />
                                    </svg>
                                    <div class="custom-select-dropdown">
                                        <select name="time" class="nice-select @error('time') is-invalid @enderror"
                                            required>
                                            <option value="">Select time</option>
                                            @for ($hour = 0; $hour < 24; $hour++)
                                                @for ($minute = 0; $minute < 60; $minute += 30)
                                                    @php
                                                        $time = sprintf('%02d:%02d', $hour, $minute);
                                                        $selected = old('time', '12:00') == $time ? 'selected' : '';
                                                    @endphp
                                                    <option value="{{ $time }}" {{ $selected }}>
                                                        {{ $time }}</option>
                                                @endfor
                                            @endfor
                                        </select>
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
                                        <span class="slider round"></span>
                                        <span class="toggle-label">Return Transfer</span>
                                    </label>
                                    @error('need_return')
                                        <span class="text-danger small">{{ $message }}</span>
                                    @enderror
                                </div>

                                <!-- Return Transfer Fields (Hidden by default) -->
                                <div id="return-transfer-fields"
                                    style="display: {{ old('need_return') ? 'block' : 'none' }};">
                                    <div class="return-transfer-header">
                                        <h5>Return Transfer Details</h5>
                                    </div>

                                    <!-- Return Date -->
                                    <div class="single-search-box date-field">
                                        <svg width="18" height="18" viewBox="0 0 18 18"
                                            xmlns="http://www.w3.org/2000/svg">
                                            <path
                                                d="M14 2h-1V1a1 1 0 0 0-2 0v1H7V1a1 1 0 0 0-2 0v1H4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2zM4 4h1v1a1 1 0 0 0 2 0V4h4v1a1 1 0 0 0 2 0V4h1v2H4V4zm0 4h10v6H4V8z" />
                                        </svg>
                                        <input type="text" name="return_date" placeholder="DD/MM/YYYY"
                                            class="nice-select custom-datepicker @error('return_date') is-invalid @enderror"
                                            value="{{ old('return_date') }}" autocomplete="off">
                                        @error('return_date')
                                            <span class="text-danger small">{{ $message }}</span>
                                        @enderror
                                    </div>

                                    <!-- Return Time -->
                                    <div class="single-search-box">
                                        <svg width="18" height="18" viewBox="0 0 18 18"
                                            xmlns="http://www.w3.org/2000/svg">
                                            <path
                                                d="M9 0C4.037 0 0 4.037 0 9s4.037 9 9 9 9-4.037 9-9S13.963 0 9 0zm4.5 9.75h-4.5a.75.75 0 0 1-.75-.75V4.5a.75.75 0 0 1 1.5 0v3.75h3.75a.75.75 0 0 1 0 1.5z" />
                                        </svg>
                                        <div class="custom-select-dropdown">
                                            <select name="return_time"
                                                class="nice-select @error('return_time') is-invalid @enderror">
                                                <option value="">Select return time</option>
                                                @for ($hour = 0; $hour < 24; $hour++)
                                                    @for ($minute = 0; $minute < 60; $minute += 30)
                                                        @php
                                                            $time = sprintf('%02d:%02d', $hour, $minute);
                                                            $selected = old('return_time') == $time ? 'selected' : '';
                                                        @endphp
                                                        <option value="{{ $time }}" {{ $selected }}>
                                                            {{ $time }}</option>
                                                    @endfor
                                                @endfor
                                            </select>
                                        </div>
                                        @error('return_time')
                                            <span class="text-danger small">{{ $message }}</span>
                                        @enderror
                                    </div>
                                </div>

                                <!-- Passengers -->
                                <div class="single-search-box">
                                    <svg width="18" height="18" viewBox="0 0 18 18"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <path
                                            d="M9 9c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z" />
                                    </svg>
                                    <div class="custom-select-dropdown">
                                        <select name="passengers"
                                            class="nice-select @error('passengers') is-invalid @enderror" required>
                                            <option value="">Passengers</option>
                                            @for ($i = 1; $i <= 15; $i++)
                                                <option value="{{ $i }}"
                                                    {{ old('passengers', '2') == $i ? 'selected' : '' }}>
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
                </div>
            </div>
        </div>
    </div>
    <!-- Point-to-Point Banner Section End-->

    <!-- Point-to-Point Features Section Start-->
    <div class="home4-feature-section mb-100">
        <div class="container">
            <div class="row text-center mb-5">
                <div class="col-12">
                    <h2 class="wow animate fadeInDown" data-wow-delay="200ms" data-wow-duration="1500ms">
                        Why Choose Our Point-to-Point Service?
                    </h2>
                    <p class="wow animate fadeInDown" data-wow-delay="300ms" data-wow-duration="1500ms">
                        Experience reliable, comfortable, and affordable door-to-door transportation
                    </p>
                </div>
            </div>
            <div class="row g-4">
                <div class="col-lg-4 col-md-6 wow animate fadeInDown" data-wow-delay="200ms" data-wow-duration="1500ms">
                    <div class="feature-card">
                        <div class="icon">
                            <img src="{{ asset('assets/img/home4/icon/feature-icon1.svg') }}" alt="">
                        </div>
                        <h4>Door-to-Door Service</h4>
                        <p>Direct pickup and drop-off at your exact locations with no detours or unnecessary stops.</p>
                        <img src="{{ asset('assets/img/home4/vector/feature-card-vector.svg') }}" alt=""
                            class="vector">
                    </div>
                </div>
                <div class="col-lg-4 col-md-6 wow animate fadeInDown" data-wow-delay="400ms" data-wow-duration="1500ms">
                    <div class="feature-card two">
                        <div class="icon">
                            <img src="{{ asset('assets/img/home4/icon/feature-icon2.svg') }}" alt="">
                        </div>
                        <h4>Flexible Scheduling</h4>
                        <p>Book your ride for any time that suits you, with return trip options available.</p>
                        <img src="{{ asset('assets/img/home4/vector/feature-card-vector.svg') }}" alt=""
                            class="vector">
                    </div>
                </div>
                <div class="col-lg-4 col-md-6 wow animate fadeInDown" data-wow-delay="600ms" data-wow-duration="1500ms">
                    <div class="feature-card three">
                        <div class="icon">
                            <img src="{{ asset('assets/img/home4/icon/feature-icon3.svg') }}" alt="">
                        </div>
                        <h4>Professional Drivers</h4>
                        <p>Experienced and courteous drivers who know the local area and prioritize your safety.</p>
                        <img src="{{ asset('assets/img/home4/vector/feature-card-vector.svg') }}" alt=""
                            class="vector">
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- Point-to-Point Features Section End-->

    <!-- Service Details Section Start-->
    <div class="package-section mb-100">
        <div class="container">
            <div class="row">
                <div class="col-lg-6 wow animate fadeInLeft" data-wow-delay="200ms" data-wow-duration="1500ms">
                    <div class="package-content-wrap">
                        <div class="section-title1 mb-4">
                            <span>Point-to-Point Transfer</span>
                            <h2>Convenient Transportation Solutions</h2>
                        </div>
                        <p class="mb-4">Our point-to-point transfer service provides reliable transportation between any
                            two locations. Whether you need to get to work, attend appointments, or travel for leisure,
                            we've got you covered.</p>

                        <div class="service-features">
                            <div class="feature-item mb-3">
                                <i class="bi bi-check-circle-fill text-primary me-2"></i>
                                <span>Real-time tracking and updates</span>
                            </div>
                            <div class="feature-item mb-3">
                                <i class="bi bi-check-circle-fill text-primary me-2"></i>
                                <span>Multiple vehicle options available</span>
                            </div>
                            <div class="feature-item mb-3">
                                <i class="bi bi-check-circle-fill text-primary me-2"></i>
                                <span>Advance booking or immediate rides</span>
                            </div>
                            <div class="feature-item mb-3">
                                <i class="bi bi-check-circle-fill text-primary me-2"></i>
                                <span>Return journey options</span>
                            </div>
                            <div class="feature-item mb-3">
                                <i class="bi bi-check-circle-fill text-primary me-2"></i>
                                <span>Competitive fixed pricing</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6 wow animate fadeInRight" data-wow-delay="400ms" data-wow-duration="1500ms">
                    <div class="package-img-area">
                        <img src="{{ asset('assets/img/home4/package-img.jpg') }}" alt="Point-to-Point Service"
                            class="img-fluid">
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- Service Details Section End-->

    <!-- FAQ Section Start-->
    <div class="faq-section mb-100">
        <div class="container">
            <div class="row">
                <div class="col-lg-8 mx-auto">
                    <div class="section-title1 text-center mb-5">
                        <span>Frequently Asked Questions</span>
                        <h2>Common Questions About Point-to-Point Transfers</h2>
                    </div>

                    <div class="accordion" id="pointToPointFAQ">
                        <div class="accordion-item">
                            <h2 class="accordion-header" id="headingOne">
                                <button class="accordion-button" type="button" data-bs-toggle="collapse"
                                    data-bs-target="#collapseOne">
                                    How far in advance can I book a point-to-point transfer?
                                </button>
                            </h2>
                            <div id="collapseOne" class="accordion-collapse collapse show"
                                data-bs-parent="#pointToPointFAQ">
                                <div class="accordion-body">
                                    You can book your point-to-point transfer up to 30 days in advance. For immediate rides,
                                    we accept bookings as little as 30 minutes before pickup time, subject to vehicle
                                    availability.
                                </div>
                            </div>
                        </div>

                        <div class="accordion-item">
                            <h2 class="accordion-header" id="headingTwo">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse"
                                    data-bs-target="#collapseTwo">
                                    Can I add stops along the way?
                                </button>
                            </h2>
                            <div id="collapseTwo" class="accordion-collapse collapse" data-bs-parent="#pointToPointFAQ">
                                <div class="accordion-body">
                                    Yes, you can add stops during your journey. Please specify additional stops when
                                    booking, as this may affect the pricing and travel time.
                                </div>
                            </div>
                        </div>

                        <div class="accordion-item">
                            <h2 class="accordion-header" id="headingThree">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse"
                                    data-bs-target="#collapseThree">
                                    What if my plans change?
                                </button>
                            </h2>
                            <div id="collapseThree" class="accordion-collapse collapse"
                                data-bs-parent="#pointToPointFAQ">
                                <div class="accordion-body">
                                    We understand plans can change. You can modify or cancel your booking up to 2 hours
                                    before the scheduled pickup time without any charges. Changes within 2 hours may incur
                                    additional fees.
                                </div>
                            </div>
                        </div>

                        <div class="accordion-item">
                            <h2 class="accordion-header" id="headingFour">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse"
                                    data-bs-target="#collapseFour">
                                    Are return trips cheaper?
                                </button>
                            </h2>
                            <div id="collapseFour" class="accordion-collapse collapse" data-bs-parent="#pointToPointFAQ">
                                <div class="accordion-body">
                                    Yes, we offer discounted rates for return journeys when booked together. The return trip
                                    discount can be up to 15% off the regular fare.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- FAQ Section End-->

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Add loading states to form submission
            const form = document.getElementById('point_to_point-form');

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
                        // Clear return transfer fields when hiding
                        returnTransferFields.querySelectorAll('input, select').forEach(field => {
                            field.value = '';
                        });
                    }
                });
            }
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

                // Ensure return date is after pickup date
                $('.custom-datepicker[name="return_date"]').on('change', function() {
                    const pickupDate = $('.custom-datepicker[name="date"]').val();
                    const returnDate = $(this).val();

                    if (pickupDate && returnDate && isValidDDMMYYYY(pickupDate) && isValidDDMMYYYY(
                            returnDate)) {
                        const pickup = parseDate(pickupDate);
                        const returnD = parseDate(returnDate);

                        if (returnD < pickup) {
                            $(this).addClass('is-invalid');
                            $(this).siblings('.invalid-feedback').remove();
                            $(this).after(
                                '<div class="invalid-feedback">Return date must be on or after pickup date</div>'
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
@endsection
