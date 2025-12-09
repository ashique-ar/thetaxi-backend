@extends('layouts.app')

@section('title', 'Search Results - TheTaxi')

@section('content')

    <!-- Breadcrumb section Start-->
    <div class="breadcrumb-section three"
        style="background-image:linear-gradient(rgba(0, 0, 0, 0.3), rgba(0, 0, 0, 0.3)), url({{ asset('assets/img/innerpages/breadcrumb-bg6.jpg') }});">
        <div class="container">
            <div class="banner-content">
                <h1>Available Vehicles</h1>
                <ul class="breadcrumb-list">
                    <li><a href="{{ route('home') }}">Home</a></li>
                    <li>Search Results</li>
                </ul>
            </div>
        </div>
    </div>

    {{-- <div class="filter-wrapper hotel mb-40">
        <div class="container">
            @include('components.booking-form')
        </div>
    </div> --}}

    <!-- Vehicle Results Section -->
    <div class="package-standard-wrapper pt-5 mb-110">
        <div class="container">
            <!-- Search Summary & Duration Display -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="search-summary-card">
                        @php
                            $serviceType = $search->service_type ?? 'point_to_point';
                            $durationDays = $search->duration_days ?? 1;
                            $packageHours = $search->package_hours ?? null;
                            $isPackageService = in_array($serviceType, ['wedding_hire', 'airport_transfers']);
                            
                            // Format duration based on service type
                            if ($serviceType === 'wedding_hire' && $packageHours) {
                                $durationText = $packageHours . ' Hour Package';
                                $durationIcon = 'bi-clock';
                            } elseif ($serviceType === 'airport_transfers') {
                                $durationText = 'One-way Transfer';
                                $durationIcon = 'bi-airplane';
                            } else {
                                $durationText = $durationDays . ' Day' . ($durationDays !== 1 ? 's' : '');
                                $durationIcon = 'bi-calendar-event';
                            }
                        @endphp
                        
                        <div class="search-summary-header">
                            <div class="duration-display">
                                <i class="{{ $durationIcon }}"></i>
                                <span class="duration-text">{{ $durationText }}</span>
                            </div>
                            <div class="search-details">
                                @if($search->from_date && $search->to_date)
                                    <span class="date-range">
                                        {{ \Carbon\Carbon::parse($search->from_date)->format('M d') }} - 
                                        {{ \Carbon\Carbon::parse($search->to_date)->format('M d, Y') }}
                                    </span>
                                @endif
                                @if($search->pickup_location)
                                    <span class="location-info">
                                        <i class="bi bi-geo-alt"></i>
                                        {{ $search->pickup_location }}
                                        @if($search->dropoff_location && $search->dropoff_location !== $search->pickup_location)
                                            → {{ $search->dropoff_location }}
                                        @endif
                                    </span>
                                @endif
                                @if(isset($search->total_distance_km) && $search->total_distance_km > 0)
                                    <span class="distance-info">
                                        <i class="bi bi-signpost-2"></i>
                                        {{ number_format($search->total_distance_km, 2) }} km
                                    </span>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Results Header -->
            <div class="row mb-4">
                <div class="col-lg-6">
                    <h4 class="results-title">{{ count($results['data']) }} Vehicle Groups Available</h4>
                    <p class="text-muted">Choose from our premium selection of vehicles</p>
                </div>
                <div class="col-lg-6">
                    <div class="d-flex gap-2 justify-content-end align-items-center">
                        
                        <!-- Sort Dropdown -->
                        <select class="form-select" id="sortResults" style="max-width: 200px;">
                            <option value="default">Sort By</option>
                            <option value="price_low">Price: Low to High</option>
                            <option value="price_high">Price: High to Low</option>
                            <option value="name">Name: A to Z</option>
                        </select>
                        <!-- New Search Button -->
                        <a href="{{ route('home') }}" class="btn btn-success text-nowrap">
                            <i class="bi bi-search"></i> New Search
                        </a>
                    </div>
                </div>
            </div>

            @if (isset($results['data']) && count($results['data']) > 0)
                <!-- Vehicle Grid - 4 cols (lg), 3 cols (md), 1 col (sm) -->
                <div class="row g-4 vehicle-results-grid">
                    @foreach ($results['data'] as $result)
                        @php
                            $pricing = $result['pricing_info'] ?? ['base_amount' => 0, 'currency' => 'LKR'];
                            $enhancedPricing = $result['enhanced_pricing'] ?? [];
                            $serviceFeatures = $result['service_features'] ?? [];
                            $availability = [
                                'available' => $result['available_count'] ?? 0,
                                'total' => $result['total_count'] ?? 0,
                            ];
                            $isRecommended = $result['recommended'] ?? false;
                        @endphp

                        <div class="col-lg-3 col-md-4 col-sm-12" data-vehicle-group="{{ $result['id'] }}"
                            data-price="{{ $pricing['base_amount'] ?? 0 }}"
                            data-name="{{ $result['name'] ?? 'Unknown Vehicle' }}">
                            <x-vehicle-card :vehicle="$result" :pricing="$pricing" :enhancedPricing="$enhancedPricing" :serviceFeatures="$serviceFeatures"
                                :availability="$availability" :searchId="$search->id" :isRecommended="$isRecommended" :showBookNow="true" :showViewDetails="false" />
                        </div>
                    @endforeach
                </div>
            @else
                <!-- No Results Found -->
                <div class="no-results-card">
                    <div class="text-center py-5">
                        <svg width="100" height="100" viewBox="0 0 100 100" fill="none"
                            xmlns="http://www.w3.org/2000/svg">
                            <path
                                d="M50 10C27.9 10 10 27.9 10 50C10 72.1 27.9 90 50 90C72.1 90 90 72.1 90 50C90 27.9 72.1 10 50 10ZM50 80C33.4 80 20 66.6 20 50C20 33.4 33.4 20 50 20C66.6 20 80 33.4 80 50C80 66.6 66.6 80 50 80Z"
                                fill="#ddd" />
                            <path d="M35 45H65M35 55H55" stroke="#ddd" stroke-width="4" stroke-linecap="round" />
                        </svg>
                        <h3 class="mt-4">No Vehicles Available</h3>
                        <p class="text-muted">Unfortunately, there are no vehicles available for your search
                            criteria.<br>Try modifying your search dates or service type.</p>
                        <a href="{{ route('home') }}" class="btn btn-primary mt-3">
                            <i class="bi bi-arrow-left"></i> Back to Home
                        </a>
                    </div>
                </div>
            @endif
        </div>
    </div>

    <!-- Cart Summary Float (Hidden by default, shown when items added) -->
    <div id="cartSummaryFloat" class="cart-summary-float" style="display: none;">
        <div class="cart-float-content">
            <div class="cart-float-header">
                <h6><i class="bi bi-cart-fill"></i> Cart</h6>
                <button type="button" class="btn-close btn-close-white" id="closeCartFloat"></button>
            </div>
            <div class="cart-float-body" id="cartFloatItems">
                <!-- Cart items will be dynamically added here -->
            </div>
            <div class="cart-float-footer">
                <div class="cart-total mb-2">
                    <span>Total:</span>
                    <strong id="cartTotalPrice">{{ getCurrencySymbol() }} 0.00</strong>
                </div>
                <a href="{{ route('cart') }}" class="btn btn-light w-100">
                    <i class="bi bi-cart-check"></i> View Cart & Checkout
                </a>
            </div>
        </div>
    </div>

@endsection

@push('styles')
    <style>
        /* ==================== THEME COLORS (From app.blade.php) ==================== */
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

        /* ==================== RESULTS HEADER ==================== */
        .results-title {
            font-size: 24px;
            font-weight: 700;
            color: #333;
            margin-bottom: 8px;
        }

        /* ==================== SEARCH SUMMARY CARD ==================== */
        .search-summary-card {
            background: linear-gradient(135deg, var(--primary-color) 0%, #A31E23 100%);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: var(--shadow-md);
            color: white;
        }

        .search-summary-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }

        .duration-display {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 24px;
            font-weight: 700;
        }

        .duration-display i {
            font-size: 28px;
            color: rgba(255, 255, 255, 0.9);
        }

        .duration-text {
            color: white;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .search-details {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 8px;
        }

        .date-range {
            font-size: 16px;
            font-weight: 600;
            color: rgba(255, 255, 255, 0.95);
        }

        .location-info {
            font-size: 14px;
            color: rgba(255, 255, 255, 0.9);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .location-info i {
            font-size: 14px;
        }

        .distance-info {
            font-size: 14px;
            color: rgba(255, 255, 255, 0.95);
            display: flex;
            align-items: center;
            gap: 6px;
            background: rgba(255, 255, 255, 0.2);
            padding: 4px 12px;
            border-radius: 20px;
            font-weight: 600;
        }

        .distance-info i {
            font-size: 14px;
        }

        @media (max-width: 768px) {
            .search-summary-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .search-details {
                align-items: flex-start;
                width: 100%;
            }
            
            .duration-display {
                font-size: 20px;
            }
            
            .duration-display i {
                font-size: 24px;
            }
        }

        /* ==================== VEHICLE CARD ==================== */
        .vehicle-card {
            background: var(--white-color);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
        }

        .vehicle-card:hover {
            box-shadow: var(--shadow-lg);
            transform: translateY(-4px);
        }

        /* Vehicle Image */
        .vehicle-image-container {
            position: relative;
            height: 200px;
            overflow: hidden;
            background: #f5f5f5;
        }

        .vehicle-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }

        .vehicle-card:hover .vehicle-img {
            transform: scale(1.05);
        }

        /* Badges */
        .availability-badge {
            position: absolute;
            top: 12px;
            right: 12px;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 5px;
            backdrop-filter: blur(10px);
        }

        .availability-badge.available {
            background: rgba(40, 167, 69, 0.95);
            color: white;
        }

        .availability-badge.unavailable {
            background: rgba(220, 53, 69, 0.95);
            color: white;
        }

        .category-badge {
            position: absolute;
            top: 12px;
            left: 12px;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            background: rgba(191, 38, 41, 0.95);
            color: white;
            backdrop-filter: blur(10px);
        }

        /* Vehicle Content */
        .vehicle-card-content {
            padding: 20px;
            display: flex;
            flex-direction: column;
            flex-grow: 1;
        }

        .vehicle-name {
            font-size: 18px;
            font-weight: 700;
            color: #333;
            margin-bottom: 16px;
            line-height: 1.3;
            min-height: 48px;
        }

        /* Specs Grid */
        .vehicle-specs {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 16px;
        }

        .spec-item {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            color: var(--black-color);
        }

        .spec-item i {
            color: var(--primary-color);
            font-size: 16px;
            flex-shrink: 0;
        }

        /* Inclusions */
        .vehicle-inclusions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 16px;
        }

        .inclusion-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 10px;
            background: #f0f9ff;
            color: #0369a1;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
        }

        /* Pricing */
        .vehicle-pricing {
            margin-top: auto;
            padding-top: 16px;
            border-top: 2px solid #f0f0f0;
        }

        .price-display {
            text-align: center;
        }

        .price-label {
            display: block;
            font-size: 12px;
            color: var(--black-color);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }

        .price-amount {
            font-size: 28px;
            font-weight: 800;
            color: var(--primary-color);
            margin: 8px 0;
            line-height: 1;
        }

        /* Action Buttons */
        .vehicle-actions .btn {
            font-weight: 600;
            padding: 10px 16px;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .vehicle-actions .btn-primary {
            background: var(--primary-color);
            border-color: var(--primary-color);
        }

        .vehicle-actions .btn-primary:hover {
            background: #a01f22;
            border-color: #a01f22;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(191, 38, 41, 0.3);
        }

        .vehicle-actions .btn-outline-primary {
            color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .vehicle-actions .btn-outline-primary:hover {
            background: var(--primary-color);
            border-color: var(--primary-color);
            color: white;
        }

        /* ==================== NO RESULTS ==================== */
        .no-results-card {
            background: white;
            border-radius: 16px;
            padding: 60px 40px;
            text-align: center;
            box-shadow: var(--shadow-sm);
        }

        .no-results-card h3 {
            color: #333;
            font-weight: 700;
        }

        /* ==================== CART FLOAT ==================== */
        .cart-summary-float {
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 350px;
            background: var(--primary-color);
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
            z-index: 1050;
            animation: slideInUp 0.4s ease-out;
        }

        @keyframes slideInUp {
            from {
                transform: translateY(100px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .cart-float-header {
            padding: 16px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: white;
        }

        .cart-float-header h6 {
            margin: 0;
            font-weight: 700;
            color: white;
        }

        .cart-float-body {
            padding: 16px 20px;
            max-height: 300px;
            overflow-y: auto;
            color: white;
        }

        .cart-float-item {
            padding: 12px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .cart-float-item:last-child {
            border-bottom: none;
        }

        .cart-float-footer {
            padding: 16px 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.2);
        }

        .cart-total {
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: white;
            font-size: 16px;
        }

        .cart-total strong {
            font-size: 20px;
            font-weight: 800;
        }

        /* ==================== RESPONSIVE ==================== */
        @media (max-width: 991px) {
            .vehicle-name {
                min-height: auto;
                font-size: 16px;
            }

            .price-amount {
                font-size: 24px;
            }

            .cart-summary-float {
                width: 300px;
            }
        }

        @media (max-width: 767px) {
            .cart-summary-float {
                width: calc(100% - 40px);
                right: 20px;
                left: 20px;
            }

            .vehicle-image-container {
                height: 180px;
            }
        }

        @media (max-width: 575px) {
            .vehicle-specs {
                grid-template-columns: 1fr;
            }
        }

        /* ==================== SERVICE-AWARE FEATURES ==================== */
        /* Recommended Vehicle Badge */
        .recommended-badge {
            position: absolute;
            top: 12px;
            left: 50%;
            transform: translateX(-50%);
            padding: 8px 16px;
            border-radius: 25px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            background: linear-gradient(135deg, #ffd700, #ffed4e);
            color: #333;
            border: 2px solid #ffd700;
            box-shadow: 0 4px 12px rgba(255, 215, 0, 0.3);
            animation: pulse 2s infinite;
            z-index: 5;
        }

        @keyframes pulse {
            0% {
                transform: translateX(-50%) scale(1);
            }

            50% {
                transform: translateX(-50%) scale(1.05);
            }

            100% {
                transform: translateX(-50%) scale(1);
            }
        }

        /* Recommended Vehicle Card Styling */
        .recommended-vehicle {
            border: 3px solid #ffd700;
            box-shadow: 0 8px 25px rgba(255, 215, 0, 0.2);
            position: relative;
        }

        .recommended-vehicle::before {
            content: '';
            position: absolute;
            top: -3px;
            left: -3px;
            right: -3px;
            bottom: -3px;
            background: linear-gradient(45deg, #ffd700, #ffed4e, #ffd700);
            border-radius: 19px;
            z-index: -1;
            animation: glow 3s ease-in-out infinite alternate;
        }

        @keyframes glow {
            from {
                opacity: 0.5;
            }

            to {
                opacity: 0.8;
            }
        }

        /* Service Features */
        .service-features {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-bottom: 12px;
        }

        .feature-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 8px;
            background: #e8f5e8;
            color: #2d5a2d;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            border: 1px solid #c3e6c3;
        }

        .feature-badge i {
            font-size: 10px;
            color: #28a745;
        }

        /* Enhanced Pricing Display */
        .savings-info {
            padding: 4px 8px;
            background: rgba(40, 167, 69, 0.1);
            border-radius: 8px;
            display: inline-block;
        }

        .savings-info small {
            font-weight: 600;
            letter-spacing: 0.3px;
        }

        /* Service-specific color coding */
        .vehicle-card[data-service="airport_transfers"] .category-badge {
            background: linear-gradient(135deg, #007bff, #0056b3);
        }

        .vehicle-card[data-service="point_to_point"] .category-badge {
            background: linear-gradient(135deg, #28a745, #1e7e34);
        }

        .vehicle-card[data-service="ride_now"] .category-badge {
            background: linear-gradient(135deg, #ffc107, #e0a800);
        }

        .vehicle-card[data-service="custom_tour"] .category-badge {
            background: linear-gradient(135deg, #6f42c1, #5a32a3);
        }

        .vehicle-card[data-service="corporate_transport"] .category-badge {
            background: linear-gradient(135deg, #343a40, #212529);
        }

        /* Enhanced Hover Effects for Service-Aware Cards */
        .recommended-vehicle:hover {
            transform: translateY(-6px);
            box-shadow: 0 12px 35px rgba(255, 215, 0, 0.3);
        }

        /* Better mobile experience for service features */
        @media (max-width: 767px) {
            .service-features {
                margin-bottom: 8px;
            }

            .feature-badge {
                font-size: 10px;
                padding: 3px 6px;
            }

            .recommended-badge {
                font-size: 10px;
                padding: 6px 12px;
            }
        }
    </style>
@endpush

@push('scripts')
    <script>
        // Cart management (Session-based)
        let cart = [];
        let cartTotals = {};
        let cartCurrency = '{{ getSelectedCurrency() }}';
        let cartCurrencySymbol = '{{ getCurrencySymbol() }}';

        $(document).ready(function() {
            // Load cart from session storage
            loadCart();

            // Sort functionality
            $('#sortResults').on('change', function() {
                const sortBy = $(this).val();
                const $grid = $('.vehicle-results-grid');
                const $cards = $grid.find('.col-lg-3').toArray();

                if (sortBy === 'default') {
                    return;
                }

                $cards.sort(function(a, b) {
                    const priceA = parseFloat($(a).data('price')) || 0;
                    const priceB = parseFloat($(b).data('price')) || 0;
                    const nameA = $(a).data('name') || '';
                    const nameB = $(b).data('name') || '';

                    if (sortBy === 'price_low') {
                        return priceA - priceB;
                    } else if (sortBy === 'price_high') {
                        return priceB - priceA;
                    } else if (sortBy === 'name') {
                        return nameA.localeCompare(nameB);
                    }
                    return 0;
                });

                $grid.html($cards);
            });

            // Add to cart functionality
            $('.add-to-cart-btn').on('click', function() {
                const btn = $(this);
                const groupId = btn.data('group-id');
                const searchId = btn.data('search-id');
                const groupName = btn.data('group-name');
                const totalPrice = parseFloat(btn.data('base-price')); // This is the TOTAL price
                const currency = btn.data('currency');

                // Get booking dates from the search
                const fromDate = '{{ $search->from_date ?? '' }}';
                const toDate = '{{ $search->to_date ?? '' }}';
                
                // Calculate duration days from dates
                let durationDays = 1;
                if (fromDate && toDate) {
                    const from = new Date(fromDate);
                    const to = new Date(toDate);
                    const diffTime = Math.abs(to - from);
                    durationDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) || 1;
                }
                
                const searchData = {
                    search_id: searchId,
                    from_date: fromDate,
                    to_date: toDate,
                    from_time: '{{ $search->from_time ?? '' }}',
                    to_time: '{{ $search->to_time ?? '' }}',
                    service_type: '{{ $search->service_type ?? '' }}',
                    pickup_location: '{{ $search->pickup_location ?? '' }}',
                    pickup_lat: {{ $search->pickup_latitude ?? 'null' }},
                    pickup_lng: {{ $search->pickup_longitude ?? 'null' }},
                    dropoff_location: '{{ $search->dropoff_location ?? '' }}',
                    dropoff_lat: {{ $search->dropoff_latitude ?? 'null' }},
                    dropoff_lng: {{ $search->dropoff_longitude ?? 'null' }},
                    duration_days: durationDays
                };

                // Send to cart WITHOUT price - let backend recalculate
                addToCart({
                    group_id: groupId,
                    group_name: groupName,
                    currency: currency,
                    quantity: 1,
                    ...searchData
                });

                // Visual feedback
                btn.html('<i class="bi bi-check-circle-fill"></i> Added!');
                btn.prop('disabled', true);

                setTimeout(() => {
                    btn.html('<i class="bi bi-cart-plus"></i> Add to Cart');
                    btn.prop('disabled', false);
                }, 2000);
            });

            // Close cart float
            $('#closeCartFloat').on('click', function() {
                $('#cartSummaryFloat').fadeOut();
            });

            // Booking form date change - update prices
            $(document).on('change', 'input[name="from_date"], input[name="to_date"]', function() {
                updateAllPrices();
            });
        });

        function addToCart(item) {
            // Add to cart via AJAX - let backend recalculate pricing
            $.ajax({
                url: '{{ route("cart.add") }}',
                method: 'POST',
                data: {
                    _token: '{{ csrf_token() }}',
                    vehicle_group_id: item.group_id,
                    name: item.group_name,
                    pickup_date: item.from_date,
                    return_date: item.to_date,
                    from_time: item.from_time,
                    to_time: item.to_time,
                    pickup_location: item.pickup_location,
                    pickup_lat: item.pickup_lat,
                    pickup_lng: item.pickup_lng,
                    dropoff_location: item.dropoff_location,
                    dropoff_lat: item.dropoff_lat,
                    dropoff_lng: item.dropoff_lng,
                    service_type: item.service_type,
                    search_data: item
                },
                success: function(response) {
                    if (response.success) {
                        // Load updated cart from server
                        loadCartFromServer();
                        showCartFloat();
                        // Dispatch cart updated event
                        window.dispatchEvent(new CustomEvent('cartUpdated'));
                        // Show success message
                        showSuccessNotification('Vehicle added to cart successfully!');
                    } else {
                        showErrorNotification('Error: ' + response.message);
                    }
                },
                error: function(xhr) {
                    console.error('Error adding to cart:', xhr);
                    const errorMsg = xhr.responseJSON?.message || 'Error adding item to cart. Please try again.';
                    showErrorNotification(errorMsg);
                }
            });
        }

        function removeFromCart(cartKey) {
            $.ajax({
                url: '{{ route("cart.remove") }}',
                method: 'POST',
                data: {
                    _token: '{{ csrf_token() }}',
                    cart_key: cartKey
                },
                success: function(response) {
                    if (response.success) {
                        loadCartFromServer();
                        // Dispatch cart updated event
                        window.dispatchEvent(new CustomEvent('cartUpdated'));
                        if (Object.keys(cart).length === 0) {
                            $('#cartSummaryFloat').fadeOut();
                        }
                    } else {
                        alert('Error: ' + response.message);
                    }
                },
                error: function(xhr) {
                    console.error('Error removing from cart:', xhr);
                    alert('Error removing item. Please try again.');
                }
            });
        }

        function loadCartFromServer() {
            $.ajax({
                url: '{{ route("cart.get") }}',
                method: 'GET',
                success: function(response) {
                    if (response.success) {
                        cart = response.items || [];
                        cartTotals = response.totals || {};
                        cartCurrency = Object.keys(cart).length > 0 ? Object.values(cart)[0].currency : '{{ getSelectedCurrency() }}';
                        cartCurrencySymbol = Object.keys(cart).length > 0 ? Object.values(cart)[0].currency_symbol : '{{ getCurrencySymbol() }}';
                        updateCartDisplay();
                        if (Object.keys(cart).length > 0) {
                            showCartFloat();
                        }
                    }
                },
                error: function(xhr) {
                    console.error('Error loading cart:', xhr);
                }
            });
        }

        function loadCart() {
            // Load cart from server instead of localStorage
            loadCartFromServer();
        }

        function updateCartDisplay() {
            const $cartItems = $('#cartFloatItems');
            const $cartTotal = $('#cartTotalPrice');

            $cartItems.empty();

            let total = 0;
            Object.keys(cart).forEach((key, index) => {
                const item = cart[key];
                const price = parseFloat(item.price || 0);
                const days = parseInt(item.days || 1);
                const itemTotal = price * days;
                total += itemTotal;

                $cartItems.append(`
                <div class="cart-float-item">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div class="flex-grow-1">
                            <strong>${item.vehicle_name || item.name || 'Vehicle Rental'}</strong>
                            <div class="small">${days} day(s)</div>
                            <div class="small">${item.pickup_date || ''} to ${item.return_date || ''}</div>
                        </div>
                        <button type="button" class="btn btn-sm btn-link text-white p-0 ms-2" onclick="removeFromCart('${key}')">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span>Per day: ${cartCurrencySymbol}${price.toFixed(2)}</span>
                        <strong>${cartCurrencySymbol}${itemTotal.toFixed(2)}</strong>
                    </div>
                </div>
            `);
            });

            // Use server-calculated total if available, otherwise use client-calculated total
            const displayTotal = cartTotals.total || total;
            $('#cartTotalPrice').text(cartCurrencySymbol + ' ' + displayTotal.toFixed(2));
        }

        function showCartFloat() {
            $('#cartSummaryFloat').fadeIn();
        }

        function showSuccessNotification(message) {
            // Create bootstrap alert
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
            // Create bootstrap alert
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

        function updateAllPrices() {
            // This function will be called when booking dates change
            // Re-calculate prices for all vehicles based on new dates
            console.log('Updating prices for new dates...');

            // You can make AJAX call here to recalculate prices
            // For now, we'll keep the base prices
        }

        // New Search functionality
        $('#newSearchBtn').on('click', function() {
            // Create a new search modal/form
            showNewSearchModal();
        });

        function showNewSearchModal() {
            // Create a modal with search form
            const modal = $(`
                <div class="modal fade" id="newSearchModal" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">
                                    <i class="bi bi-search"></i> Start New Search
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <form id="newSearchForm">
                                    <div class="row g-3">
                                        <!-- Service Type -->
                                        <div class="col-md-12">
                                            <label class="form-label">Service Type</label>
                                            <select name="service_type" class="form-select" required>
                                                <option value="point_to_point">Point to Point</option>
                                                <option value="ride_now">Ride Now</option>
                                                <option value="airport_transfers">Airport Transfer</option>
                                                <option value="wedding_hire">Wedding Hire</option>
                                                <option value="corporate">Corporate</option>
                                            </select>
                                        </div>
                                        
                                        <!-- Pickup Location -->
                                        <div class="col-md-6">
                                            <label class="form-label">Pickup Location</label>
                                            <input type="text" name="pickup_location" class="form-control" required>
                                        </div>
                                        
                                        <!-- Dropoff Location -->
                                        <div class="col-md-6">
                                            <label class="form-label">Dropoff Location</label>
                                            <input type="text" name="dropoff_location" class="form-control">
                                        </div>
                                        
                                        <!-- Pickup Date -->
                                        <div class="col-md-6">
                                            <label class="form-label">Pickup Date</label>
                                            <input type="date" name="pickup_date" class="form-control" 
                                                   min="{{ date('Y-m-d') }}" value="{{ date('Y-m-d') }}" required>
                                        </div>
                                        
                                        <!-- Return Date -->
                                        <div class="col-md-6">
                                            <label class="form-label">Return Date</label>
                                            <input type="date" name="return_date" class="form-control" 
                                                   min="{{ date('Y-m-d') }}" value="{{ date('Y-m-d', strtotime('+1 day')) }}">
                                        </div>
                                        
                                        <!-- Times -->
                                        <div class="col-md-6">
                                            <label class="form-label">Pickup Time</label>
                                            <input type="time" name="pickup_time" class="form-control" value="10:00">
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <label class="form-label">Return Time</label>
                                            <input type="time" name="return_time" class="form-control" value="18:00">
                                        </div>
                                    </div>
                                </form>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="button" class="btn btn-primary" id="submitNewSearch">
                                    <i class="bi bi-search"></i> Search Vehicles
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `);
            
            $('body').append(modal);
            $('#newSearchModal').modal('show');
            
            // Handle form submission
            $('#submitNewSearch').on('click', function() {
                const formData = new FormData($('#newSearchForm')[0]);
                const searchParams = new URLSearchParams();
                
                for (const [key, value] of formData.entries()) {
                    if (value) {
                        searchParams.append(key, value);
                    }
                }
                
                // Redirect to search with new parameters
                window.location.href = '{{ route("search") }}?' + searchParams.toString();
            });
            
            // Clean up modal when hidden
            $('#newSearchModal').on('hidden.bs.modal', function() {
                $(this).remove();
            });
        }

        // Book Now functionality
        $(document).on('click', '.book-now-btn', function() {
            const $btn = $(this);
            const groupId = $btn.data('group-id');
            const searchId = $btn.data('search-id');
            const groupName = $btn.data('group-name');
            const totalPrice = parseFloat($btn.data('base-price')); 
            const currency = $btn.data('currency');

            // Get booking dates from the search
            const fromDate = '{{ $search->from_date ?? '' }}';
            const toDate = '{{ $search->to_date ?? '' }}';
            
            // Calculate duration days from dates
            let durationDays = 1;
            if (fromDate && toDate) {
                const from = new Date(fromDate);
                const to = new Date(toDate);
                const diffTime = Math.abs(to - from);
                durationDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) || 1;
            }
            
            const searchData = {
                search_id: searchId,
                from_date: fromDate,
                to_date: toDate,
                from_time: '{{ $search->from_time ?? '' }}',
                to_time: '{{ $search->to_time ?? '' }}',
                service_type: '{{ $search->service_type ?? '' }}',
                pickup_location: '{{ $search->pickup_location ?? '' }}',
                pickup_lat: {{ $search->pickup_latitude ?? 'null' }},
                pickup_lng: {{ $search->pickup_longitude ?? 'null' }},
                dropoff_location: '{{ $search->dropoff_location ?? '' }}',
                dropoff_lat: {{ $search->dropoff_latitude ?? 'null' }},
                dropoff_lng: {{ $search->dropoff_longitude ?? 'null' }},
                duration_days: durationDays
            };

            // Create proper item object for addToCart
            const item = {
                group_id: groupId,
                group_name: groupName,
                currency: currency,
                quantity: 1,
                ...searchData
            };
            
            // Add to cart first
            addToCart(item);
            
            // Wait for cart addition to complete, then redirect to cart
            setTimeout(() => {
                window.location.href = '{{ route("cart") }}';
            }, 1000);
        });
    </script>
@endpush
