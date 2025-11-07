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

    <!-- Search Filter & Booking Form Section -->
    <div class="filter-wrapper hotel mb-40">
        <div class="container">
            <!-- Dynamic Booking Form for modifications -->
            @include('components.booking-form')
        </div>
    </div>

    <!-- Vehicle Results Section -->
    <div class="package-standard-wrapper pt-60 mb-110">
        <div class="container">
            <!-- Results Header -->
            <div class="row mb-4">
                <div class="col-lg-8">
                    <h4 class="results-title">{{ count($results['data']) }} Vehicle Groups Available</h4>
                    <p class="text-muted">Choose from our premium selection of vehicles</p>
                </div>
                <div class="col-lg-4">
                    <select class="form-select" id="sortResults">
                        <option value="default">Sort By</option>
                        <option value="price_low">Price: Low to High</option>
                        <option value="price_high">Price: High to Low</option>
                        <option value="name">Name: A to Z</option>
                    </select>
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
                                :availability="$availability" :searchId="$search->id" :isRecommended="$isRecommended" :showBookNow="false" :showViewDetails="true" />
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
                    <strong id="cartTotalPrice">LKR 0.00</strong>
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

        .price-unit {
            display: block;
            font-size: 12px;
            color: var(--black-color);
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
        .vehicle-card[data-service="airport-transfer"] .category-badge {
            background: linear-gradient(135deg, #007bff, #0056b3);
        }

        .vehicle-card[data-service="drop-pickup"] .category-badge {
            background: linear-gradient(135deg, #28a745, #1e7e34);
        }

        .vehicle-card[data-service="rental_package"] .category-badge {
            background: linear-gradient(135deg, #ffc107, #e0a800);
        }

        .vehicle-card[data-service="custom-tour"] .category-badge {
            background: linear-gradient(135deg, #6f42c1, #5a32a3);
        }

        .vehicle-card[data-service="corporate-transport"] .category-badge {
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
                const basePrice = parseFloat(btn.data('base-price'));
                const currency = btn.data('currency');

                // Get booking dates from the search
                const searchData = {
                    search_id: searchId,
                    from_date: '{{ $search->from_date ?? '' }}',
                    to_date: '{{ $search->to_date ?? '' }}',
                    from_time: '{{ $search->from_time ?? '' }}',
                    to_time: '{{ $search->to_time ?? '' }}',
                    service_type: '{{ $search->service_type ?? '' }}',
                    pickup_location: '{{ $search->pickup_location ?? '' }}',
                    dropoff_location: '{{ $search->dropoff_location ?? '' }}',
                    duration_days: {{ $search->duration_days ?? 1 }}
                };

                addToCart({
                    group_id: groupId,
                    group_name: groupName,
                    base_price: basePrice,
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
            // Check if item already exists in cart
            const existingIndex = cart.findIndex(cartItem =>
                cartItem.group_id === item.group_id && cartItem.search_id === item.search_id
            );

            if (existingIndex !== -1) {
                // Update quantity
                cart[existingIndex].quantity += item.quantity;
            } else {
                // Add new item
                cart.push(item);
            }

            // Save to session storage
            saveCart();
            updateCartDisplay();
            showCartFloat();
        }

        function removeFromCart(index) {
            cart.splice(index, 1);
            saveCart();
            updateCartDisplay();

            if (cart.length === 0) {
                $('#cartSummaryFloat').fadeOut();
            }
        }

        function saveCart() {
            localStorage.setItem('thetaxi_cart', JSON.stringify(cart));

            // Also save to server session via AJAX
            $.ajax({
                url: '{{ route('cart.sync') }}',
                method: 'POST',
                data: {
                    _token: '{{ csrf_token() }}',
                    cart: cart
                },
                success: function(response) {
                    console.log('Cart synced to server');
                }
            });
        }

        function loadCart() {
            const savedCart = localStorage.getItem('thetaxi_cart');
            if (savedCart) {
                cart = JSON.parse(savedCart);
                if (cart.length > 0) {
                    updateCartDisplay();
                    showCartFloat();
                }
            }
        }

        function updateCartDisplay() {
            const $cartItems = $('#cartFloatItems');
            const $cartTotal = $('#cartTotalPrice');

            $cartItems.empty();

            let total = 0;
            cart.forEach((item, index) => {
                const itemTotal = item.base_price * item.quantity * (item.duration_days || 1);
                total += itemTotal;

                $cartItems.append(`
                <div class="cart-float-item">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div class="flex-grow-1">
                            <strong>${item.group_name}</strong>
                            <div class="small">${item.duration_days || 1} day(s)</div>
                            <div class="small">${item.from_date} to ${item.to_date}</div>
                        </div>
                        <button type="button" class="btn btn-sm btn-link text-white p-0 ms-2" onclick="removeFromCart(${index})">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span>Qty: ${item.quantity}</span>
                        <strong>${item.currency} ${itemTotal.toFixed(2)}</strong>
                    </div>
                </div>
            `);
            });

            $cartTotal.text(cart[0]?.currency + ' ' + total.toFixed(2));
        }

        function showCartFloat() {
            $('#cartSummaryFloat').fadeIn();
        }

        function updateAllPrices() {
            // This function will be called when booking dates change
            // Re-calculate prices for all vehicles based on new dates
            console.log('Updating prices for new dates...');

            // You can make AJAX call here to recalculate prices
            // For now, we'll keep the base prices
        }
    </script>
@endpush
