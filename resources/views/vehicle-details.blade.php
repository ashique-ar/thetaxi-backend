@extends('layouts.app')

@section('title', 'Vehicle Details - TheTaxi')

@section('content')
    <!-- Start Breadcrumb section -->
    <div class="breadcrumb-section"
        style="background-image:linear-gradient(rgba(0, 0, 0, 0.3), rgba(0, 0, 0, 0.3)), url({{ asset('assets/img/innerpages/breadcrumb-bg1.jpg') }});">
        <div class="container">
            <div class="banner-content">
                <h1>Vehicle Details</h1>
                <ul class="breadcrumb-list">
                    <li><a href="{{ route('home') }}">Home</a></li>
                    <li><a href="{{ route('search.results', ['id' => $search->id]) }}">Search Results</a></li>
                    <li>Vehicle Details</li>
                </ul>
            </div>
        </div>
    </div>
    <!-- End Breadcrumb section -->

    <!-- Vehicle Details Page Start-->
    <div class="product-details-page pt-100 mb-100">
        <div class="container">
            <!-- Booking Form Section -->
            <div class="filter-wrapper hotel mb-40">
                <div class="container">
                    @include('components.booking-form')
                </div>
            </div>

            <div class="row gy-5 justify-content-between mb-70">
                <div class="col-xl-5 col-lg-6">
                    <div class="product-details-img">
                        <div class="tab-content" id="v-pills-tabContent">
                            @php
                                $vehicleImages = [
                                    asset('assets/img/default-vehicle.jpg'),
                                    asset('assets/img/default-vehicle.jpg'),
                                    asset('assets/img/default-vehicle.jpg')
                                ];
                            @endphp
                            
                            @foreach($vehicleImages as $index => $image)
                                <div class="tab-pane fade {{ $index === 0 ? 'show active' : '' }}" id="v-pills-img{{ $index + 1 }}" role="tabpanel">
                                    <div class="product-details-tab-img">
                                        <img src="{{ $image }}" alt="{{ $vehicleGroup->name ?? 'Vehicle' }}">
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <ul class="nav nav-pills" id="v-pills-tab" role="tablist" aria-orientation="vertical">
                            @foreach($vehicleImages as $index => $image)
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link {{ $index === 0 ? 'active' : '' }}" id="v-pills-img{{ $index + 1 }}-tab" data-bs-toggle="pill"
                                        data-bs-target="#v-pills-img{{ $index + 1 }}" type="button" role="tab"
                                        aria-controls="v-pills-img{{ $index + 1 }}" aria-selected="{{ $index === 0 ? 'true' : 'false' }}">
                                        <img src="{{ $image }}" alt="{{ $vehicleGroup->name ?? 'Vehicle' }}">
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="product-details-content">
                        @php
                            $vehicle = $vehicleGroup->toArray();
                            $pricingData = $pricing ?? ['base_amount' => 0, 'currency' => 'LKR'];
                            $availability = [
                                'available' => $vehicleGroup->vehicles->where('is_active', true)->count(),
                                'total' => $vehicleGroup->vehicles->count()
                            ];
                            $duration = $search->duration_days ?? 1;
                            $totalPrice = $pricingData['base_amount'] * $duration;
                        @endphp
                        
                        <h2>{{ $vehicleGroup->name ?? 'Vehicle Details' }}</h2>
                        <p>{{ $vehicleGroup->description ?? 'Premium vehicle for your transportation needs.' }}</p>
                        
                        <!-- Vehicle Features -->
                        <ul class="vehicle-features-list">
                            @if($vehicleGroup->category)
                            <li>
                                <svg width="16" height="16" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg">
                                    <path fill-rule="evenodd" clip-rule="evenodd" d="M4.5197 9.35783L6.0477 11.5708C6.99602 10.2009 11.2112 3.50919 13.6349 0.400391C11.1248 5.14183 8.94274 10.0882 6.98018 15.0588C6.69858 15.7717 5.69441 15.7839 5.39873 15.0767C4.46385 12.8415 3.45873 10.6202 2.35938 8.46199C3.14977 8.30391 3.99265 8.56743 4.51953 9.35783H4.5197Z" />
                                </svg>
                                Category: {{ $vehicleGroup->category->name }}
                            </li>
                            @endif
                            <li>
                                <svg width="16" height="16" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg">
                                    <path fill-rule="evenodd" clip-rule="evenodd" d="M4.5197 9.35783L6.0477 11.5708C6.99602 10.2009 11.2112 3.50919 13.6349 0.400391C11.1248 5.14183 8.94274 10.0882 6.98018 15.0588C6.69858 15.7717 5.69441 15.7839 5.39873 15.0767C4.46385 12.8415 3.45873 10.6202 2.35938 8.46199C3.14977 8.30391 3.99265 8.56743 4.51953 9.35783H4.5197Z" />
                                </svg>
                                Capacity: {{ $vehicleGroup->seating_capacity ?? 'N/A' }} Passengers
                            </li>
                            @if(isset($pricingData['includes_driver']) && $pricingData['includes_driver'])
                            <li>
                                <svg width="16" height="16" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg">
                                    <path fill-rule="evenodd" clip-rule="evenodd" d="M4.5197 9.35783L6.0477 11.5708C6.99602 10.2009 11.2112 3.50919 13.6349 0.400391C11.1248 5.14183 8.94274 10.0882 6.98018 15.0588C6.69858 15.7717 5.69441 15.7839 5.39873 15.0767C4.46385 12.8415 3.45873 10.6202 2.35938 8.46199C3.14977 8.30391 3.99265 8.56743 4.51953 9.35783H4.5197Z" />
                                </svg>
                                Professional Driver Included
                            </li>
                            @endif
                            @if(isset($pricingData['includes_fuel']) && $pricingData['includes_fuel'])
                            <li>
                                <svg width="16" height="16" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg">
                                    <path fill-rule="evenodd" clip-rule="evenodd" d="M4.5197 9.35783L6.0477 11.5708C6.99602 10.2009 11.2112 3.50919 13.6349 0.400391C11.1248 5.14183 8.94274 10.0882 6.98018 15.0588C6.69858 15.7717 5.69441 15.7839 5.39873 15.0767C4.46385 12.8415 3.45873 10.6202 2.35938 8.46199C3.14977 8.30391 3.99265 8.56743 4.51953 9.35783H4.5197Z" />
                                </svg>
                                Fuel Included
                            </li>
                            @endif
                        </ul>
                        
                        <!-- Pricing Section -->
                        <div class="price-tag">
                            <div class="pricing-display">
                                <div class="base-price">
                                    <span class="price-label">Per Day:</span>
                                    <span class="price-amount">{{ $pricingData['currency'] ?? 'LKR' }} {{ number_format($pricingData['base_amount'] ?? 0, 2) }}</span>
                                </div>
                                <div class="total-price">
                                    <span class="price-label">Total ({{ $duration }} {{ $duration > 1 ? 'days' : 'day' }}):</span>
                                    <h5 class="total-amount">{{ $pricingData['currency'] ?? 'LKR' }} <span class="calculated-total">{{ number_format($totalPrice, 2) }}</span></h5>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Date Range Selection -->
                        <div class="booking-dates-section">
                            <h6>Select Your Booking Dates</h6>
                            <div class="row">
                                <div class="col-md-6">
                                    <label class="form-label">Pickup Date</label>
                                    <input type="date" class="form-control pickup-date-input" 
                                           value="{{ $search->pickup_date ? $search->pickup_date->format('Y-m-d') : '' }}"
                                           data-vehicle-id="{{ $vehicleGroup->id }}"
                                           data-base-price="{{ $pricingData['base_amount'] ?? 0 }}">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Dropoff Date</label>
                                    <input type="date" class="form-control dropoff-date-input" 
                                           value="{{ $search->dropoff_date ? $search->dropoff_date->format('Y-m-d') : '' }}"
                                           data-vehicle-id="{{ $vehicleGroup->id }}"
                                           data-base-price="{{ $pricingData['base_amount'] ?? 0 }}">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Quantity and Action Buttons -->
                        <div class="product-quantity d-flex align-items-center justify-content-start">
                            @if($availability['available'] > 0)
                                <div class="quantity me-3">
                                    <a class="quantity__minus"><span><i class="bi bi-dash"></i></span></a>
                                    <input name="quantity" type="text" class="quantity__input" value="1" min="1" max="{{ $availability['available'] }}">
                                    <a class="quantity__plus"><span><i class="bi bi-plus"></i></span></a>
                                </div>
                                
                                <button type="button" class="primary-btn1 transparent me-3 add-to-cart-btn" 
                                        data-vehicle-id="{{ $vehicleGroup->id }}"
                                        data-search-id="{{ $search->id ?? '' }}"
                                        data-base-price="{{ $pricingData['base_amount'] ?? 0 }}">
                                    <span>
                                        Add to Cart
                                        <svg width="10" height="10" viewBox="0 0 10 10" xmlns="http://www.w3.org/2000/svg">
                                            <path d="M9.73535 1.14746C9.57033 1.97255 9.32924 3.26406 9.24902 4.66797C9.16817 6.08312 9.25559 7.5453 9.70214 8.73633C9.84754 9.12406 9.65129 9.55659 9.26367 9.70215C8.9001 9.83849 8.4969 9.67455 8.32812 9.33398L8.29785 9.26367L8.19921 8.98438C7.73487 7.5758 7.67054 5.98959 7.75097 4.58203C7.77875 4.09598 7.82525 3.62422 7.87988 3.17969L1.53027 9.53027C1.23738 9.82317 0.762615 9.82317 0.469722 9.53027C0.176829 9.23738 0.176829 8.76262 0.469722 8.46973L6.83593 2.10254C6.3319 2.16472 5.79596 2.21841 5.25 2.24902C3.8302 2.32862 2.2474 2.26906 0.958003 1.79102L0.704097 1.68945L0.635738 1.65527C0.303274 1.47099 0.157578 1.06102 0.310542 0.704102C0.463655 0.347333 0.860941 0.170391 1.22363 0.28418L1.29589 0.310547L1.48828 0.387695C2.47399 0.751207 3.79966 0.827571 5.16601 0.750977C6.60111 0.670504 7.97842 0.428235 8.86132 0.262695L9.95312 0.0585938L9.73535 1.14746Z"></path>
                                        </svg>
                                    </span>
                                    <span>
                                        Add to Cart
                                        <svg width="10" height="10" viewBox="0 0 10 10" xmlns="http://www.w3.org/2000/svg">
                                            <path d="M9.73535 1.14746C9.57033 1.97255 9.32924 3.26406 9.24902 4.66797C9.16817 6.08312 9.25559 7.5453 9.70214 8.73633C9.84754 9.12406 9.65129 9.55659 9.26367 9.70215C8.9001 9.83849 8.4969 9.67455 8.32812 9.33398L8.29785 9.26367L8.19921 8.98438C7.73487 7.5758 7.67054 5.98959 7.75097 4.58203C7.77875 4.09598 7.82525 3.62422 7.87988 3.17969L1.53027 9.53027C1.23738 9.82317 0.762615 9.82317 0.469722 9.53027C0.176829 9.23738 0.176829 8.76262 0.469722 8.46973L6.83593 2.10254C6.3319 2.16472 5.79596 2.21841 5.25 2.24902C3.8302 2.32862 2.2474 2.26906 0.958003 1.79102L0.704097 1.68945L0.635738 1.65527C0.303274 1.47099 0.157578 1.06102 0.310542 0.704102C0.463655 0.347333 0.860941 0.170391 1.22363 0.28418L1.29589 0.310547L1.48828 0.387695C2.47399 0.751207 3.79966 0.827571 5.16601 0.750977C6.60111 0.670504 7.97842 0.428235 8.86132 0.262695L9.95312 0.0585938L9.73535 1.14746Z"></path>
                                        </svg>
                                    </span>
                                </button>
                                
                                <a href="{{ route('checkout') }}" class="primary-btn1 book-now-btn">
                                    <span>
                                        Book Now
                                        <svg width="10" height="10" viewBox="0 0 10 10" xmlns="http://www.w3.org/2000/svg">
                                            <path d="M9.73535 1.14746C9.57033 1.97255 9.32924 3.26406 9.24902 4.66797C9.16817 6.08312 9.25559 7.5453 9.70214 8.73633C9.84754 9.12406 9.65129 9.55659 9.26367 9.70215C8.9001 9.83849 8.4969 9.67455 8.32812 9.33398L8.29785 9.26367L8.19921 8.98438C7.73487 7.5758 7.67054 5.98959 7.75097 4.58203C7.77875 4.09598 7.82525 3.62422 7.87988 3.17969L1.53027 9.53027C1.23738 9.82317 0.762615 9.82317 0.469722 9.53027C0.176829 9.23738 0.176829 8.76262 0.469722 8.46973L6.83593 2.10254C6.3319 2.16472 5.79596 2.21841 5.25 2.24902C3.8302 2.32862 2.2474 2.26906 0.958003 1.79102L0.704097 1.68945L0.635738 1.65527C0.303274 1.47099 0.157578 1.06102 0.310542 0.704102C0.463655 0.347333 0.860941 0.170391 1.22363 0.28418L1.29589 0.310547L1.48828 0.387695C2.47399 0.751207 3.79966 0.827571 5.16601 0.750977C6.60111 0.670504 7.97842 0.428235 8.86132 0.262695L9.95312 0.0585938L9.73535 1.14746Z"></path>
                                        </svg>
                                    </span>
                                    <span>
                                        Book Now
                                        <svg width="10" height="10" viewBox="0 0 10 10" xmlns="http://www.w3.org/2000/svg">
                                            <path d="M9.73535 1.14746C9.57033 1.97255 9.32924 3.26406 9.24902 4.66797C9.16817 6.08312 9.25559 7.5453 9.70214 8.73633C9.84754 9.12406 9.65129 9.55659 9.26367 9.70215C8.9001 9.83849 8.4969 9.67455 8.32812 9.33398L8.29785 9.26367L8.19921 8.98438C7.73487 7.5758 7.67054 5.98959 7.75097 4.58203C7.77875 4.09598 7.82525 3.62422 7.87988 3.17969L1.53027 9.53027C1.23738 9.82317 0.762615 9.82317 0.469722 9.53027C0.176829 9.23738 0.176829 8.76262 0.469722 8.46973L6.83593 2.10254C6.3319 2.16472 5.79596 2.21841 5.25 2.24902C3.8302 2.32862 2.2474 2.26906 0.958003 1.79102L0.704097 1.68945L0.635738 1.65527C0.303274 1.47099 0.157578 1.06102 0.310542 0.704102C0.463655 0.347333 0.860941 0.170391 1.22363 0.28418L1.29589 0.310547L1.48828 0.387695C2.47399 0.751207 3.79966 0.827571 5.16601 0.750977C6.60111 0.670504 7.97842 0.428235 8.86132 0.262695L9.95312 0.0585938L9.73535 1.14746Z"></path>
                                        </svg>
                                    </span>
                                </a>
                            @else
                                <div class="unavailable-notice">
                                    <span class="text-danger">
                                        <i class="bi bi-exclamation-triangle"></i>
                                        Currently Unavailable
                                    </span>
                                </div>
                            @endif
                        </div>
                        
                        <!-- Additional Information -->
                        <ul class="aditional-info">
                            <li><span>Vehicle ID:</span> {{ $vehicleGroup->id }}</li>
                            @if($vehicleGroup->category)
                            <li><span>Category:</span> <a href="#">{{ $vehicleGroup->category->name }}</a></li>
                            @endif
                            <li><span>Availability:</span> {{ $availability['available'] }} of {{ $availability['total'] }} vehicles</li>
                        </ul>
                    </div>
                </div>
            </div>
            
            <!-- Vehicle Description and Additional Details -->
            <div class="product-description-and-review-area">
                <div class="row">
                    <div class="col-lg-12">
                        <div class="nav nav2 nav-pills" id="v-pills-tab2" role="tablist" aria-orientation="vertical">
                            <button class="nav-link active" id="description-tab" data-bs-toggle="pill"
                                data-bs-target="#description" type="button" role="tab" aria-controls="description"
                                aria-selected="true">Vehicle Details</button>
                            <button class="nav-link" id="features-tab" data-bs-toggle="pill" data-bs-target="#features"
                                type="button" role="tab" aria-controls="features" aria-selected="false">Features & Amenities</button>
                            <button class="nav-link" id="booking-tab" data-bs-toggle="pill" data-bs-target="#booking"
                                type="button" role="tab" aria-controls="booking" aria-selected="false">Booking Information</button>
                        </div>
                        <div class="tab-content tab-content2" id="v-pills-tabContent2">
                            <div class="tab-pane fade active show" id="description" role="tabpanel" aria-labelledby="description-tab">
                                <div class="description">
                                    <h4>{{ $vehicleGroup->name ?? 'Vehicle Details' }}</h4>
                                    <p>{{ $vehicleGroup->description ?? 'This premium vehicle offers exceptional comfort and reliability for your transportation needs. Our professional drivers ensure a safe and pleasant journey.' }}</p>

                                    <div class="vehicle-specifications">
                                        <h5>Specifications</h5>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <ul class="specification-list">
                                                    @if($vehicleGroup->category)
                                                    <li><strong>Category:</strong> {{ $vehicleGroup->category->name }}</li>
                                                    @endif
                                                    <li><strong>Seating Capacity:</strong> {{ $vehicleGroup->seating_capacity ?? 'N/A' }} passengers</li>
                                                    <li><strong>Available Units:</strong> {{ $availability['available'] }} of {{ $availability['total'] }}</li>
                                                </ul>
                                            </div>
                                            <div class="col-md-6">
                                                <ul class="specification-list">
                                                    <li><strong>Driver:</strong> {{ isset($pricingData['includes_driver']) && $pricingData['includes_driver'] ? 'Included' : 'Not Included' }}</li>
                                                    <li><strong>Fuel:</strong> {{ isset($pricingData['includes_fuel']) && $pricingData['includes_fuel'] ? 'Included' : 'Not Included' }}</li>
                                                    <li><strong>Base Rate:</strong> {{ $pricingData['currency'] ?? 'LKR' }} {{ number_format($pricingData['base_amount'] ?? 0, 2) }}/day</li>
                                                </ul>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="tab-pane fade" id="features" role="tabpanel" aria-labelledby="features-tab">
                                <div class="features">
                                    <h4>Features & Amenities</h4>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <h5>Standard Features</h5>
                                            <ul class="features-list">
                                                <li><i class="bi bi-check-circle-fill text-success"></i> Air Conditioning</li>
                                                <li><i class="bi bi-check-circle-fill text-success"></i> Comfortable Seating</li>
                                                <li><i class="bi bi-check-circle-fill text-success"></i> Safety Equipment</li>
                                                <li><i class="bi bi-check-circle-fill text-success"></i> Professional Driver</li>
                                            </ul>
                                        </div>
                                        <div class="col-md-6">
                                            <h5>Additional Services</h5>
                                            <ul class="features-list">
                                                @if(isset($pricingData['includes_driver']) && $pricingData['includes_driver'])
                                                <li><i class="bi bi-check-circle-fill text-success"></i> Experienced Driver Included</li>
                                                @endif
                                                @if(isset($pricingData['includes_fuel']) && $pricingData['includes_fuel'])
                                                <li><i class="bi bi-check-circle-fill text-success"></i> Fuel Costs Included</li>
                                                @endif
                                                <li><i class="bi bi-check-circle-fill text-success"></i> 24/7 Customer Support</li>
                                                <li><i class="bi bi-check-circle-fill text-success"></i> Flexible Booking</li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="tab-pane fade" id="booking" role="tabpanel" aria-labelledby="booking-tab">
                                <div class="booking-info">
                                    <h4>Booking Information</h4>
                                    <div class="booking-details">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <h5>Pickup Details</h5>
                                                <p><strong>Location:</strong> {{ $search->pickup_location ?? 'To be specified' }}</p>
                                                <p><strong>Date:</strong> {{ $search->pickup_date ? $search->pickup_date->format('d M Y, H:i') : 'To be selected' }}</p>
                                            </div>
                                            <div class="col-md-6">
                                                <h5>Dropoff Details</h5>
                                                <p><strong>Location:</strong> {{ $search->dropoff_location ?? 'To be specified' }}</p>
                                                <p><strong>Date:</strong> {{ $search->dropoff_date ? $search->dropoff_date->format('d M Y, H:i') : 'To be selected' }}</p>
                                            </div>
                                        </div>
                                        
                                        <div class="pricing-breakdown mt-4">
                                            <h5>Pricing Breakdown</h5>
                                            <div class="breakdown-table">
                                                <div class="breakdown-row">
                                                    <span>Base Rate (per day)</span>
                                                    <span>{{ $pricingData['currency'] ?? 'LKR' }} {{ number_format($pricingData['base_amount'] ?? 0, 2) }}</span>
                                                </div>
                                                <div class="breakdown-row">
                                                    <span>Duration</span>
                                                    <span>{{ $duration }} {{ $duration > 1 ? 'days' : 'day' }}</span>
                                                </div>
                                                @if(isset($pricingData['breakdown']) && is_array($pricingData['breakdown']))
                                                    @foreach($pricingData['breakdown'] as $fee)
                                                    <div class="breakdown-row">
                                                        <span>{{ $fee['name'] ?? 'Additional Fee' }}</span>
                                                        <span>{{ $pricingData['currency'] ?? 'LKR' }} {{ number_format($fee['amount'] ?? 0, 2) }}</span>
                                                    </div>
                                                    @endforeach
                                                @endif
                                                <div class="breakdown-row total">
                                                    <span><strong>Total Amount</strong></span>
                                                    <span><strong>{{ $pricingData['currency'] ?? 'LKR' }} <span class="calculated-total">{{ number_format($totalPrice, 2) }}</span></strong></span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!--Vehicle Details Page End-->
@endsection

@push('styles')
<style>
    /* Vehicle Details Specific Styling */
    .vehicle-features-list {
        list-style: none;
        padding: 0;
        margin: 20px 0;
    }

    .vehicle-features-list li {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 8px 0;
        border-bottom: 1px solid #f0f0f0;
    }

    .vehicle-features-list li:last-child {
        border-bottom: none;
    }

    .vehicle-features-list svg {
        fill: #28a745;
        flex-shrink: 0;
    }

    .pricing-display {
        background: #f8f9fa;
        border-radius: 8px;
        padding: 20px;
        margin: 20px 0;
    }

    .base-price, .total-price {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 10px;
    }

    .total-price {
        border-top: 1px solid #e9ecef;
        padding-top: 10px;
        margin-top: 10px;
    }

    .price-label {
        font-size: 14px;
        color: #666;
        font-weight: 500;
    }

    .price-amount {
        font-size: 16px;
        font-weight: 600;
        color: #333;
    }

    .total-amount {
        font-size: 18px;
        font-weight: 700;
        color: #ff8c00;
        margin: 0;
    }

    .booking-dates-section {
        margin: 25px 0;
        padding: 20px;
        background: #fff;
        border: 1px solid #e9ecef;
        border-radius: 8px;
    }

    .booking-dates-section h6 {
        margin-bottom: 15px;
        color: #333;
        font-weight: 600;
    }

    .booking-dates-section .form-label {
        font-weight: 600;
        color: #666;
        margin-bottom: 8px;
    }

    .booking-dates-section .form-control {
        border-radius: 6px;
        border: 1px solid #e9ecef;
        padding: 10px 15px;
    }

    .booking-dates-section .form-control:focus {
        border-color: #ff8c00;
        box-shadow: 0 0 0 0.2rem rgba(255, 140, 0, 0.25);
    }

    .specification-list, .features-list {
        list-style: none;
        padding: 0;
    }

    .specification-list li, .features-list li {
        padding: 8px 0;
        border-bottom: 1px solid #f0f0f0;
    }

    .specification-list li:last-child, .features-list li:last-child {
        border-bottom: none;
    }

    .features-list li {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .breakdown-table {
        background: #f8f9fa;
        border-radius: 8px;
        padding: 20px;
    }

    .breakdown-row {
        display: flex;
        justify-content: space-between;
        padding: 8px 0;
        border-bottom: 1px solid #e9ecef;
    }

    .breakdown-row:last-child {
        border-bottom: none;
    }

    .breakdown-row.total {
        border-top: 2px solid #ff8c00;
        padding-top: 15px;
        margin-top: 10px;
        font-size: 16px;
    }

    .unavailable-notice {
        padding: 15px;
        background: #f8d7da;
        border-radius: 8px;
        text-align: center;
    }

    /* Quantity Controls */
    .quantity {
        display: flex;
        align-items: center;
        border: 1px solid #e9ecef;
        border-radius: 6px;
        overflow: hidden;
    }

    .quantity__minus, .quantity__plus {
        width: 40px;
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #f8f9fa;
        cursor: pointer;
        transition: background 0.3s ease;
        text-decoration: none;
        color: #666;
    }

    .quantity__minus:hover, .quantity__plus:hover {
        background: #e9ecef;
        color: #333;
    }

    .quantity__input {
        width: 60px;
        height: 40px;
        text-align: center;
        border: none;
        outline: none;
        font-weight: 600;
    }

    /* Action Buttons */
    .product-quantity .primary-btn1 {
        padding: 12px 25px;
        font-weight: 600;
        border-radius: 6px;
        transition: all 0.3s ease;
    }

    .add-to-cart-btn {
        background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
        border: none;
        color: white;
    }

    .add-to-cart-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 15px rgba(40, 167, 69, 0.4);
    }

    .book-now-btn {
        background: linear-gradient(135deg, #ff8c00 0%, #ff7043 100%);
        border: none;
        color: white;
    }

    .book-now-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 15px rgba(255, 140, 0, 0.4);
    }

    /* Responsive */
    @media (max-width: 768px) {
        .product-quantity {
            flex-direction: column;
            gap: 15px;
        }
        
        .product-quantity .primary-btn1 {
            width: 100%;
            text-align: center;
        }
        
        .booking-dates-section .row {
            gap: 15px;
        }
    }
</style>
@endpush

@push('scripts')
<script>
    $(document).ready(function() {
        // Quantity controls
        $('.quantity__plus').on('click', function() {
            const $input = $(this).siblings('.quantity__input');
            const currentValue = parseInt($input.val()) || 1;
            const maxValue = parseInt($input.attr('max')) || 999;
            
            if (currentValue < maxValue) {
                $input.val(currentValue + 1);
            }
        });
        
        $('.quantity__minus').on('click', function() {
            const $input = $(this).siblings('.quantity__input');
            const currentValue = parseInt($input.val()) || 1;
            const minValue = parseInt($input.attr('min')) || 1;
            
            if (currentValue > minValue) {
                $input.val(currentValue - 1);
            }
        });

        // Date change functionality for pricing updates
        $('.pickup-date-input, .dropoff-date-input').on('change', function() {
            const basePrice = parseFloat($(this).data('base-price'));
            const $pickupDate = $('.pickup-date-input');
            const $dropoffDate = $('.dropoff-date-input');
            
            const pickupDate = new Date($pickupDate.val());
            const dropoffDate = new Date($dropoffDate.val());
            
            if (pickupDate && dropoffDate && dropoffDate > pickupDate) {
                const timeDiff = dropoffDate.getTime() - pickupDate.getTime();
                const daysDiff = Math.ceil(timeDiff / (1000 * 3600 * 24));
                const totalPrice = basePrice * daysDiff;
                
                // Update all total price displays
                $('.calculated-total').text(totalPrice.toLocaleString('en-US', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                }));
                
                // Update duration displays
                const durationText = daysDiff + (daysDiff > 1 ? ' days' : ' day');
                $('.total-price .price-label').text(`Total (${durationText}):`);
                
                // Update breakdown table duration
                $('.breakdown-row:contains("Duration") span:last-child').text(durationText);
            }
        });

        // Add to cart functionality
        $('.add-to-cart-btn').on('click', function() {
            const vehicleId = $(this).data('vehicle-id');
            const searchId = $(this).data('search-id');
            const basePrice = $(this).data('base-price');
            const quantity = parseInt($('.quantity__input').val()) || 1;
            
            const pickupDate = $('.pickup-date-input').val();
            const dropoffDate = $('.dropoff-date-input').val();
            
            if (!pickupDate || !dropoffDate) {
                alert('Please select both pickup and dropoff dates');
                return;
            }
            
            if (new Date(dropoffDate) <= new Date(pickupDate)) {
                alert('Dropoff date must be after pickup date');
                return;
            }
            
            // Calculate days and total price
            const timeDiff = new Date(dropoffDate).getTime() - new Date(pickupDate).getTime();
            const daysDiff = Math.ceil(timeDiff / (1000 * 3600 * 24));
            const totalPrice = basePrice * daysDiff * quantity;
            
            // Disable button during request
            const $btn = $(this);
            const originalText = $btn.html();
            $btn.prop('disabled', true).html('<span>Adding...</span>');
            
            // Send AJAX request to add to cart
            $.ajaxSetup({
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                }
            });
            
            $.ajax({
                url: '/cart/add',
                method: 'POST',
                data: {
                    vehicle_group_id: vehicleId,
                    search_id: searchId,
                    pickup_date: pickupDate,
                    dropoff_date: dropoffDate,
                    duration_days: daysDiff,
                    base_price: basePrice,
                    total_price: totalPrice,
                    quantity: quantity
                },
                success: function(response) {
                    if (response.success) {
                        // Update cart count if exists
                        if ($('.cart-count').length) {
                            $('.cart-count').text(response.cart_count);
                        }
                        
                        // Show success feedback
                        $btn.removeClass('add-to-cart-btn').addClass('btn-success').html('<span><i class="bi bi-check-circle"></i> Added to Cart</span>');
                        
                        // Show success message
                        if (typeof showToast === 'function') {
                            showToast('success', 'Vehicle added to cart successfully!');
                        } else {
                            alert('Vehicle added to cart successfully!');
                        }
                        
                        setTimeout(() => {
                            $btn.removeClass('btn-success').addClass('add-to-cart-btn').html(originalText).prop('disabled', false);
                        }, 3000);
                    } else {
                        throw new Error(response.message || 'Failed to add vehicle to cart');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Cart add error:', error);
                    let errorMessage = 'Failed to add vehicle to cart';
                    
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        errorMessage = xhr.responseJSON.message;
                    } else if (xhr.responseJSON && xhr.responseJSON.errors) {
                        errorMessage = Object.values(xhr.responseJSON.errors).flat().join(', ');
                    }
                    
                    alert(errorMessage);
                    $btn.html(originalText).prop('disabled', false);
                }
            });
        });

        // Book Now functionality
        $('.book-now-btn').on('click', function(e) {
            e.preventDefault();
            
            const vehicleId = $('.add-to-cart-btn').data('vehicle-id');
            const searchId = $('.add-to-cart-btn').data('search-id');
            const pickupDate = $('.pickup-date-input').val();
            const dropoffDate = $('.dropoff-date-input').val();
            const quantity = parseInt($('.quantity__input').val()) || 1;
            
            if (!pickupDate || !dropoffDate) {
                alert('Please select both pickup and dropoff dates');
                return;
            }
            
            if (new Date(dropoffDate) <= new Date(pickupDate)) {
                alert('Dropoff date must be after pickup date');
                return;
            }
            
            // Add to cart first, then redirect to checkout
            $('.add-to-cart-btn').trigger('click');
            
            setTimeout(() => {
                window.location.href = $(this).attr('href');
            }, 1000);
        });
    });
</script>
@endpush