@extends('layouts.app')

@section('title', 'Shopping Cart - TheTaxi')

@section('content')
    <!-- Breadcrumb section -->
    <div class="breadcrumb-section"
        style="background-image:linear-gradient(rgba(0, 0, 0, 0.3), rgba(0, 0, 0, 0.3)), url({{ asset('assets/img/innerpages/breadcrumb-bg1.jpg') }});">
        <div class="container">
            <div class="banner-content">
                <h1>Shopping Cart</h1>
                <ul class="breadcrumb-list">
                    <li><a href="{{ route('home') }}">Home</a></li>
                    <li>Cart</li>
                </ul>
            </div>
        </div>
    </div>
    <!-- End Breadcrumb section -->

    <!-- Cart Page Start-->
    <div class="cart-page pt-100 mb-100">
        <div class="container">
            @php
                // Get cart items from database via CartService with currency conversion
                try {
                    $cartService = app(\App\Services\CartService::class);
                    $cartModel = $cartService->getOrCreateCart();
                    $cartData = $cartService->toArray($cartModel); // This applies currency conversion
                    $cart = $cartData['items'] ?? [];
                    $cartTotals = $cartData['totals'] ?? [];
                    $currencySymbol = $cartData['currency_symbol'] ?? getCurrencySymbol();
                    $selectedCurrency = $cartData['currency'] ?? getSelectedCurrency();

                    // Fetch settings from database
                    $taxPercentage = \App\Models\Website\WebsiteSetting::getValue('tax_percentage', 10);
                    $serviceFeeSetting = \App\Models\Website\WebsiteSetting::getValue('service_fee_percentage', 5);
                    $vatPercentage = \App\Models\Website\WebsiteSetting::getValue('vat_percentage', 0);
                } catch (Exception $e) {
                    \Log::error('Error loading cart: ' . $e->getMessage());
                    $cart = [];
                    $cartTotals = [];
                    $currencySymbol = getCurrencySymbol();
                    $selectedCurrency = getSelectedCurrency();
                    $taxPercentage = 10;
                    $serviceFeeSetting = 5;
                    $vatPercentage = 0;
                }
            @endphp

            @if (!empty($cart))
                <div class="row g-lg-4 gy-5">
                    <div class="col-xl-8 col-lg-7">
                        <div class="cart-shopping-wrapper">
                            <div class="cart-widget-title d-flex justify-content-between align-items-center mb-4">
                                <h4>My Shopping Cart</h4>
                                <div class="d-flex">
                                    <a href="{{ route('search') }}" class="details-button">
                                        Continue Shopping
                                        <svg width="10" height="10" viewBox="0 0 10 10"
                                            xmlns="http://www.w3.org/2000/svg">
                                            <path
                                                d="M1 9L9 1M9 1C7.22222 1.33333 3.33333 2 1 1M9 1C8.66667 2.66667 8 6.33333 9 9"
                                                stroke-width="1.5" stroke-linecap="round" />
                                        </svg>
                                    </a>
                                    <button class="details-button clear-cart ms-3">
                                        Clear Cart <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                            <table class="cart-table">
                                <thead>
                                    <tr>
                                        <th>Vehicle Info</th>
                                        <th>Price</th>
                                        <th>Total</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($cart as $key => $item)
                                        @php
                                           // Calculate days from pickup and return dates
                                            $pickupDate = isset($item['pickup_date'])
                                                ? \Carbon\Carbon::parse($item['pickup_date'])
                                                : null;
                                            $returnDate = isset($item['return_date'])
                                                ? \Carbon\Carbon::parse($item['return_date'])
                                                : null;
                                            $calculatedDays =
                                                $pickupDate && $returnDate
                                                    ? max(1, $pickupDate->diffInDays($returnDate))
                                                    : 1;

                                            // Use total_price if available (already calculated for all days in LKR)
                                            // Otherwise calculate from per-day price and calculated days
                                            $itemTotal = isset($item['total_price'])
                                                ? $item['total_price']
                                                : ($item['price'] ?? 0) * $calculatedDays;
                                        @endphp
                                        <tr data-cart-key="{{ $key }}">
                                            <td data-label="Vehicle Info">
                                                <div class="product-info-wrapper">
                                                    <div class="product-info-img">
                                                        @if (isset($item['image']) && $item['image'])
                                                            <img src="{{ s3_asset($item['image']) }}"
                                                                alt="{{ $item['name'] ?? 'Vehicle' }}">
                                                        @else
                                                            <img src="{{ asset('assets/img/innerpages/cart-img1.png') }}"
                                                                alt="{{ $item['name'] ?? 'Vehicle' }}">
                                                        @endif
                                                    </div>
                                                    <div class="product-info-content">
                                                        <h6>{{ $item['name'] ?? 'Vehicle Rental' }} <span class="badge bg-warning rounded-pill" style="font-size: 10px; vertical-align: middle;">{{ $item['service_type_data']['name'] ?? 'Service Type' }}</span></h6>

                                                        <div class="booking-details">
                                                            @if (isset($item['pickup_date']) && isset($item['return_date']))
                                                                <p>
                                                                    {{ $item['pickup_location'] }} -
                                                                    {{ $item['return_location'] }}
                                                                </p>

                                                                <p>
                                                                    {{ $pickupDate->format('M d, Y') }}
                                                                    @if (isset($item['from_time']))
                                                                        <span class="text-muted">@
                                                                            {{ $item['from_time'] }}</span>
                                                                    @endif
                                                                    -
                                                                    {{ $returnDate->format('M d, Y') }}
                                                                    @if (isset($item['to_time']))
                                                                        <span class="text-muted">@
                                                                            {{ $item['to_time'] }}</span>
                                                                    @endif
                                                                </p>
                                                                <p class="text-muted"
                                                                    style="font-size: 12px; margin-top: 8px;">
                                                                    <i class="bi bi-calendar-event"></i> <strong>Duration:
                                                                        {{ $calculatedDays }}
                                                                        day{{ $calculatedDays !== 1 ? 's' : '' }}</strong>
                                                                </p>
                                                            @endif
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td data-label="Price">
                                                <span>{{ $currencySymbol }}{{ number_format($item['price'] ?? 0, 2) }}/day</span>
                                            </td>
                                            <td data-label="Total">
                                                <span
                                                    class="item-total">{{ $currencySymbol }}{{ number_format($itemTotal, 2) }}</span>
                                            </td>
                                            <td data-label="Action">
                                                <button class="remove-item btn btn-sm btn-outline-danger"
                                                    data-cart-key="{{ $key }}">
                                                    <i class="bi bi-trash"></i> Remove
                                                </button>
                                            </td>
                                        </tr>
                                        {{-- Unified Addon Management Section --}}
                                        <tr class="unified-addons-row" data-cart-key="{{ $key }}">
                                            <td colspan="5">
                                                <div class="unified-addons-container">
                                                    <div class="addons-header-unified">
                                                        <div class="header-left">
                                                            <h6 class="mb-0"><i class="bi bi-gift"></i> Customize with
                                                                Services</h6>
                                                            <small class="text-muted">Selected: <span
                                                                    class="selected-count">0</span> service(s)</small>
                                                        </div>
                                                        <button type="button"
                                                            class="btn btn-sm btn-outline-secondary toggle-addons-section collapsed"
                                                            data-cart-key="{{ $key }}"
                                                            title="Toggle addons section">
                                                            <i class="bi bi-chevron-down"></i> Show
                                                        </button>
                                                    </div>
                                                    <div class="addons-grid-unified collapsed" data-cart-key="{{ $key }}"
                                                        data-service-type="{{ $item['service_type'] ?? '' }}">
                                                        <div class="text-center py-3">
                                                            <div class="spinner-border spinner-border-sm" role="status">
                                                                <span class="visually-hidden">Loading...</span>
                                                            </div>
                                                            <small class="d-block mt-2 text-muted">Loading
                                                                services...</small>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>

                        </div>
                    </div>
                    <div class="col-xl-4 col-lg-5">
                        <div class="cart-order-sum-area">
                            <div class="cart-widget-title">
                                <h4>Order Summary</h4>
                            </div>
                            <div class="order-summary-wrap">
                                <ul class="order-summary-list">
                                    <li>
                                        <strong>Subtotal</strong>
                                        <strong class="cart-subtotal">
                                            {{ $currencySymbol }}{{ number_format($cartTotals['subtotal'] ?? 0, 2) }}
                                        </strong>
                                    </li>
                                    <li>
                                        Service Charges
                                        <div class="order-info">
                                            <p>Processing Fee</p>
                                            <span
                                                class="service-fee">{{ $currencySymbol }}{{ number_format($cartTotals['service_fee'] ?? 0, 2) }}</span>
                                        </div>
                                    </li>
                                    <li>
                                        Tax ({{ $taxPercentage }}%)
                                        <div class="order-info">
                                            <p>Estimated Tax</p>
                                            <span class="tax-amount">
                                                {{ $currencySymbol }}{{ number_format($cartTotals['tax'] ?? 0, 2) }}
                                            </span>
                                        </div>
                                    </li>
                                    @if ($vatPercentage > 0)
                                        <li>
                                            VAT ({{ $vatPercentage }}%)
                                            <div class="order-info">
                                                <p>Value Added Tax</p>
                                                <span class="vat-amount">
                                                    {{ $currencySymbol }}{{ number_format($cartTotals['vat'] ?? 0, 2) }}
                                                </span>
                                            </div>
                                        </li>
                                    @endif
                                    @if (($cartTotals['addon_charges'] ?? 0) > 0)
                                        <li>
                                            Addon Charges
                                            <div class="order-info">
                                                <p>Additional Services</p>
                                                <span class="addon-charges-amount">
                                                    {{ $currencySymbol }}{{ number_format($cartTotals['addon_charges'] ?? 0, 2) }}
                                                </span>
                                            </div>
                                        </li>
                                    @endif
                                    <li>
                                        <div class="coupon-area">
                                            <span>Coupon Code</span>
                                            <form id="coupon-form">
                                                @csrf
                                                <div class="form-inner">
                                                    <input type="text" name="coupon_code" placeholder="Enter coupon code"
                                                        id="coupon-input">
                                                    <button type="submit" class="apply-btn">Apply</button>
                                                </div>
                                            </form>
                                            <div id="coupon-message" class="mt-2"></div>
                                        </div>
                                    </li>
                                    <li class="discount-row" style="display: none;">
                                        <strong>Discount</strong>
                                        <strong class="discount-amount text-success">-$0.00</strong>
                                    </li>
                                    <li>
                                        <strong>Total</strong>
                                        <strong class="cart-total">
                                            {{ $currencySymbol }}{{ number_format($cartTotals['total'] ?? 0, 2) }}
                                        </strong>
                                    </li>
                                </ul>

                                <div class="checkout-buttons mt-4">
                                    <a href="{{ route('checkout') }}" class="primary-btn1 mt-20 w-100">
                                        <span>
                                            Proceed to Checkout
                                            <svg width="10" height="10" viewBox="0 0 10 10"
                                                xmlns="http://www.w3.org/2000/svg">
                                                <path
                                                    d="M9.73535 1.14746C9.57033 1.97255 9.32924 3.26406 9.24902 4.66797C9.16817 6.08312 9.25559 7.5453 9.70214 8.73633C9.84754 9.12406 9.65129 9.55659 9.26367 9.70215C8.9001 9.83849 8.4969 9.67455 8.32812 9.33398L8.29785 9.26367L8.19921 8.98438C7.73487 7.5758 7.67054 5.98959 7.75097 4.58203C7.77875 4.09598 7.82525 3.62422 7.87988 3.17969L1.53027 9.53027C1.23738 9.82317 0.762615 9.82317 0.469722 9.53027C0.176829 9.23738 0.176829 8.76262 0.469722 8.46973L6.83593 2.10254C6.3319 2.16472 5.79596 2.21841 5.25 2.24902C3.8302 2.32862 2.2474 2.26906 0.958003 1.79102L0.704097 1.68945L0.635738 1.65527C0.303274 1.47099 0.157578 1.06102 0.310542 0.704102C0.463655 0.347333 0.860941 0.170391 1.22363 0.28418L1.29589 0.310547L1.48828 0.387695C2.47399 0.751207 3.79966 0.827571 5.16601 0.750977C6.60111 0.670504 7.97842 0.428235 8.86132 0.262695L9.95312 0.0585938L9.73535 1.14746Z">
                                                </path>
                                            </svg>
                                        </span>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            @else
                <!-- Empty Cart State -->
                <div class="empty-cart-area text-center py-5">
                    <div class="empty-cart-content">
                        <img src="{{ asset('assets/img/innerpages/empty-cart.png') }}" alt="Empty Cart" class="mb-4"
                            style="max-width: 300px;">
                        <h3>Your cart is empty</h3>
                        <p class="mb-4">Looks like you haven't added any vehicles to your cart yet.</p>
                        <a href="{{ route('search') }}" class="primary-btn1">
                            <span>
                                Start Shopping
                                <svg width="10" height="10" viewBox="0 0 10 10"
                                    xmlns="http://www.w3.org/2000/svg">
                                    <path d="M1 9L9 1M9 1C7.22222 1.33333 3.33333 2 1 1M9 1C8.66667 2.66667 8 6.33333 9 9"
                                        stroke-width="1.5" stroke-linecap="round" />
                                </svg>
                            </span>
                        </a>
                    </div>
                </div>
            @endif
        </div>
    </div>
    <!--Cart Page End-->
@endsection

@push('styles')
    <style>
        .cart-table {
            width: 100%;
            margin-bottom: 30px;
        }

        .cart-table th,
        .cart-table td {
            padding: 20px 15px;
            vertical-align: middle;
            border-bottom: 1px solid #eee;
        }

        .cart-table th {
            background-color: #f8f9fa;
            font-weight: 600;
            text-align: left;
        }

        .product-info-wrapper {
            display: flex;
            align-items: flex-start;
            gap: 15px;
        }

        .product-info-img {
            flex-shrink: 0;
            width: 80px;
            height: 80px;
            border-radius: 8px;
            overflow: hidden;
        }

        .product-info-img img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .product-info-content h6 {
            margin-bottom: 5px;
            font-weight: 600;
        }

        .booking-details p {
            margin-bottom: 3px;
            font-size: 13px;
            color: #666;
        }

        .quantity-area {
            margin-top: 10px;
        }


        .remove-item {
            background: none;
            border: none;
            color: #dc3545;
            cursor: pointer;
            font-size: 12px;
        }

        .cart-actions {
            border-top: 1px solid #eee;
            padding-top: 20px;
        }

        .order-summary-list li {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 0;
            border-bottom: 1px solid #eee;
        }

        .coupon-area .form-inner {
            display: flex;
            margin-top: 10px;
        }

        .coupon-area input {
            flex: 1;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 5px 0 0 5px;
        }

        .apply-btn {
            padding: 8px 16px;
            background: var(--primary-color1);
            color: white;
            border: none;
            border-radius: 0 5px 5px 0;
            cursor: pointer;
        }

        .payment-buttons .primary-btn1 {
            display: block;
            text-align: center;
            text-decoration: none;
        }

        .btn-outline {
            background: transparent !important;
            color: var(--primary-color1) !important;
            border: 2px solid var(--primary-color1) !important;
        }

        .btn-secondary {
            background: #6c757d !important;
            border-color: #6c757d !important;
        }

        .empty-cart-area {
            min-height: 400px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Addon Styles */
        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .add-addons-btn {
            background-color: #28a745 !important;
            color: white !important;
            border-color: #28a745 !important;
        }

        .add-addons-btn:hover {
            background-color: #218838 !important;
            border-color: #218838 !important;
        }

        .addon-row {
            background-color: #f8f9fa;
        }

        .addon-item-display {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            padding: 12px 0;
        }

        .addon-info {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 1;
        }

        .addon-info i {
            font-size: 18px;
            color: #28a745;
        }

        .addon-info div {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .addon-info strong {
            font-size: 14px;
            color: #333;
        }

        .addon-pricing {
            display: flex;
            align-items: center;
            gap: 15px;
            white-space: nowrap;
        }

        .addon-qty {
            font-size: 13px;
            color: #666;
            background: white;
            padding: 4px 8px;
            border-radius: 4px;
        }

        .addon-price {
            font-weight: 600;
            color: #28a745;
            min-width: 80px;
            text-align: right;
        }

        .remove-addon-btn {
            color: #dc3545 !important;
            padding: 4px 8px;
            font-size: 16px;
        }

        .remove-addon-btn:hover {
            color: #c82333 !important;
        }

        @media (max-width: 768px) {
            .cart-table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }

            .cart-table thead {
                display: none;
            }

            .cart-table tr {
                display: block;
                border: 1px solid #ddd;
                margin-bottom: 15px;
                border-radius: 8px;
                padding: 15px;
            }

            .cart-table td {
                display: block;
                padding: 10px 0;
                border: none;
                text-align: left !important;
            }

            .cart-table td:before {
                content: attr(data-label) ": ";
                font-weight: 600;
                margin-right: 10px;
            }

            .product-info-wrapper {
                flex-direction: column;
                text-align: center;
            }
        }
    </style>
@endpush

@push('scripts')
    <script>
        $(document).ready(function() {
            // Update cart totals from server
            function updateCartTotals() {
                $.ajax({
                    url: '{{ route('cart.summary') }}',
                    method: 'GET',
                    success: function(response) {
                        if (response.success) {
                            // Update order summary with fresh data from server
                            $('.cart-subtotal').text(getCurrencySymbol() + response.subtotal);
                            $('.service-fee').text(getCurrencySymbol() + response.service_fee);
                            $('.tax-amount').text(getCurrencySymbol() + response.tax);
                            $('.vat-amount').text(getCurrencySymbol() + response.vat);
                            $('.addon-charges-amount').text(getCurrencySymbol() + response
                                .addon_charges);
                            $('.discount-amount').text('-' + getCurrencySymbol() + response.discount);
                            $('.cart-total').text(getCurrencySymbol() + response.total);

                            console.log('Cart totals updated from server', response);
                        }
                    },
                    error: function(xhr) {
                        console.error('Error updating cart totals:', xhr);
                    }
                });
            }

            function getCurrencySymbol() {
                // Detect from page
                return '{{ $currencySymbol ?? 'LKR' }}';
            }

            // Remove item from cart
            $(document).on('click', '.remove-item', function() {
                if (confirm('Are you sure you want to remove this item?')) {
                    let cartKey = $(this).data('cart-key');

                    $.ajax({
                        url: '{{ route('cart.remove') }}',
                        method: 'POST',
                        data: {
                            _token: '{{ csrf_token() }}',
                            cart_key: cartKey
                        },
                        success: function(response) {
                            if (response.success) {
                                // Remove the row and dispatch cart updated event
                                $('#cartRow_' + cartKey).remove();
                                window.dispatchEvent(new CustomEvent('cartUpdated'));
                                showSuccessNotification('Item removed from cart successfully!', 3000);
                                
                                // Check if cart is empty
                                if ($('.cart-table tbody tr').length === 0) {
                                    $('.cart-table tbody').append(
                                        '<tr><td colspan="5" class="text-center py-5"><em>Your cart is empty</em></td></tr>'
                                    );
                                }
                            } else {
                                alert('Error: ' + response.message);
                            }
                        },
                        error: function(xhr) {
                            console.error('Error removing item:', xhr);
                            alert('Error removing item. Please try again.');
                        }
                    });
                }
            });

            // Clear entire cart
            $('.clear-cart').on('click', function() {
                if (confirm('Are you sure you want to clear your entire cart?')) {
                    $.ajax({
                        url: '{{ route('cart.clear') }}',
                        method: 'POST',
                        data: {
                            _token: '{{ csrf_token() }}'
                        },
                        success: function(response) {
                            if (response.success) {
                                // Clear cart display and dispatch event
                                $('.cart-table tbody').empty().append(
                                    '<tr><td colspan="5" class="text-center py-5"><em>Your cart is empty</em></td></tr>'
                                );
                                window.dispatchEvent(new CustomEvent('cartUpdated'));
                                showSuccessNotification('Cart cleared successfully!', 3000);
                            }
                        },
                        error: function() {
                            alert('Error clearing cart. Please try again.');
                        }
                    });
                }
            });

            // Coupon form
            $('#coupon-form').on('submit', function(e) {
                e.preventDefault();

                let couponCode = $('#coupon-input').val().trim();
                if (!couponCode) {
                    return;
                }

                $.ajax({
                    url: '{{ route('cart.apply-coupon') }}',
                    method: 'POST',
                    data: {
                        _token: '{{ csrf_token() }}',
                        coupon_code: couponCode
                    },
                    success: function(response) {
                        if (response.success) {
                            // Reload page to show updated totals with proper currency conversion
                            location.reload();
                        } else {
                            $('#coupon-message').html('<div class="alert alert-danger">' +
                                response.message + '</div>');
                        }
                    },
                    error: function() {
                        $('#coupon-message').html(
                            '<div class="alert alert-danger">Error applying coupon. Please try again.</div>'
                        );
                    }
                });
            });

            // Addon Management - Unified Section

            // Load addons for each cart item on page load
            $(document).ready(function() {
                loadAddonsForAllItems();
            });

            function loadAddonsForAllItems() {
                $('.unified-addons-row').each(function() {
                    const cartKey = $(this).data('cart-key');
                    const serviceType = $(this).find('.addons-grid-unified').data('service-type');
                    loadUnifiedAddonsForItem(cartKey, serviceType);
                });
            }

            function loadUnifiedAddonsForItem(cartKey, serviceType) {
                $.ajax({
                    url: '{{ route('cart.addons.available') }}',
                    method: 'GET',
                    data: {
                        service_type_id: serviceType || ''
                    },
                    success: function(response) {
                        if (response.success && response.data) {
                            // Fetch currently selected addons for this cart item
                            fetchSelectedAddonsForItem(cartKey, response.data, serviceType);
                        } else {
                            displayUnifiedAddonsErrorForItem(cartKey, 'No services available');
                        }
                    },
                    error: function(xhr) {
                        console.error('Error loading addons:', xhr);
                        displayUnifiedAddonsErrorForItem(cartKey, 'Error loading services');
                    }
                });
            }

            function fetchSelectedAddonsForItem(cartKey, allAddons, serviceType) {
                $.ajax({
                    url: '{{ route('cart.addons.get', ['cartKey' => ':cartKey']) }}'.replace(':cartKey',
                        cartKey),
                    method: 'GET',
                    success: function(response) {
                        if (response.success && response.data) {
                            displayUnifiedAddonsForItem(cartKey, allAddons, response.data, serviceType);
                        } else {
                            displayUnifiedAddonsForItem(cartKey, allAddons, {}, serviceType);
                        }
                    },
                    error: function() {
                        displayUnifiedAddonsForItem(cartKey, allAddons, {}, serviceType);
                    }
                });
            }

            function displayUnifiedAddonsForItem(cartKey, addons, selectedAddons, serviceType) {
                const container = $(`.unified-addons-row[data-cart-key="${cartKey}"] .addons-grid-unified`);
                container.empty();

                if (addons.length === 0) {
                    container.html(
                        `<p class="text-center text-muted py-3">No services available for ${serviceType || 'this rental'}</p>`
                    );
                    return;
                }

                const grid = $('<div class="unified-addons-grid"></div>');
                let selectedCount = 0;

                // selectedAddons is an array of {addon_id, qty, ...}
                const selectedAddonsMap = {};
                if (Array.isArray(selectedAddons)) {
                    selectedAddons.forEach(function(addon) {
                        selectedAddonsMap[addon.addon_id] = addon.qty || 0;
                    });
                } else if (typeof selectedAddons === 'object') {
                    selectedAddonsMap = selectedAddons;
                }

                addons.forEach(function(addon) {
                    // Get current quantity from selected addons
                    const currentQty = selectedAddonsMap[addon.id] || 0;
                    const isSelected = currentQty > 0;

                    if (isSelected) selectedCount++;

                    const addonCard = `
                        <div class="unified-addon-card ${isSelected ? 'addon-selected' : 'addon-not-selected'}" data-addon-id="${addon.id}" data-cart-key="${cartKey}">
                            <div class="addon-card-header-unified">
                                <div class="addon-status-badge ${isSelected ? 'badge-selected' : 'badge-available'}">
                                    <i class="bi ${isSelected ? 'bi-check-circle-fill' : 'bi-plus-circle'}"></i>
                                    <span class="badge-text">${isSelected ? 'SELECTED' : 'AVAILABLE'}</span>
                                </div>
                                ${addon.thumbnail ? `<img src="${addon.thumbnail}" alt="${addon.name}" class="addon-card-img-unified">` : '<div class="addon-card-img-unified bg-light"><i class="bi bi-gift"></i></div>'}
                            </div>
                            <div class="addon-card-body-unified">
                                <h6 class="addon-title-unified">${addon.name}</h6>
                                <small class="addon-desc-unified">${addon.description || 'Service'}</small>
                                <div class="addon-price-unified">
                                    <strong>${parseFloat(addon.amount).toFixed(2)}</strong>
                                    <span>${addon.rate_type === 'percentage' ? '%/day' : 'LKR/one-time'}</span>
                                </div>
                            </div>
                            <div class="addon-controls-unified">
                                <div class="qty-control-unified">
                                    <button class="qty-btn-unified qty-minus-unified" data-addon-id="${addon.id}" data-cart-key="${cartKey}" title="Decrease">−</button>
                                    <input type="number" class="qty-input-unified" value="${currentQty}" min="0" max="${addon.max_qty || 999}" data-addon-id="${addon.id}" data-cart-key="${cartKey}">
                                    <button class="qty-btn-unified qty-plus-unified" data-addon-id="${addon.id}" data-cart-key="${cartKey}" title="Increase">+</button>
                                </div>
                                <button class="btn-apply-addon-unified ${isSelected ? 'btn-addon-update' : 'btn-addon-add'}" data-addon-id="${addon.id}" data-cart-key="${cartKey}">
                                    ${isSelected ? '<i class="bi bi-arrow-clockwise"></i> Update' : '<i class="bi bi-plus-lg"></i> Add'}
                                </button>
                            </div>
                        </div>
                    `;
                    grid.append(addonCard);
                });

                container.html(grid);
                updateSelectedCount(cartKey, selectedCount);
            }

            function displayUnifiedAddonsErrorForItem(cartKey, message) {
                const container = $(`.unified-addons-row[data-cart-key="${cartKey}"] .addons-grid-unified`);
                container.html(`<p class="text-center text-muted py-3">${message}</p>`);
            }

            function updateSelectedCount(cartKey, count) {
                $(`.unified-addons-row[data-cart-key="${cartKey}"] .selected-count`).text(count);
            }

            // Qty controls for unified addons
            $(document).on('click', '.qty-plus-unified', function() {
                const input = $(this).siblings('.qty-input-unified');
                const max = parseInt(input.attr('max')) || 999;
                const current = parseInt(input.val()) || 0;
                input.val(Math.min(current + 1, max)).trigger('change');
            });

            $(document).on('click', '.qty-minus-unified', function() {
                const input = $(this).siblings('.qty-input-unified');
                const current = parseInt(input.val()) || 1;
                input.val(Math.max(0, current - 1)).trigger('change');
            });

            $(document).on('change', '.qty-input-unified', function() {
                updateAddonCardStatus($(this).closest('.unified-addon-card'));
            });

            function updateAddonCardStatus(card) {
                const qty = parseInt(card.find('.qty-input-unified').val()) || 0;
                const badge = card.find('.addon-status-badge');
                const button = card.find('.btn-apply-addon-unified');
                const icon = badge.find('i');
                const text = badge.find('.badge-text');

                if (qty > 0) {
                    card.removeClass('addon-not-selected').addClass('addon-selected');
                    badge.removeClass('badge-available').addClass('badge-selected');
                    icon.removeClass('bi-plus-circle').addClass('bi-check-circle-fill');
                    text.text('SELECTED');
                    button.removeClass('btn-addon-add').addClass('btn-addon-update')
                        .html('<i class="bi bi-arrow-clockwise"></i> Update');
                } else {
                    card.removeClass('addon-selected').addClass('addon-not-selected');
                    badge.removeClass('badge-selected').addClass('badge-available');
                    icon.removeClass('bi-check-circle-fill').addClass('bi-plus-circle');
                    text.text('AVAILABLE');
                    button.removeClass('btn-addon-update').addClass('btn-addon-add')
                        .html('<i class="bi bi-plus-lg"></i> Add');
                }
            }

            // Apply/Update addon
            $(document).on('click', '.btn-apply-addon-unified', function() {
                const addonId = $(this).data('addon-id');
                const cartKey = $(this).data('cart-key');
                const qty = parseInt($(this).closest('.unified-addon-card').find('.qty-input-unified')
                    .val()) || 0;

                if (qty === 0) {
                    // Remove addon if quantity is 0
                    removeAddonFromCart(cartKey, addonId);
                } else {
                    // Add or update addon
                    addOrUpdateAddonToCart(cartKey, addonId, qty);
                }
            });

            function addOrUpdateAddonToCart(cartKey, addonId, qty) {
                $.ajax({
                    url: '{{ route('cart.addon.add') }}',
                    method: 'POST',
                    data: {
                        _token: '{{ csrf_token() }}',
                        cart_key: cartKey,
                        addon_id: addonId,
                        qty: qty
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert(response.message || 'Error updating addon');
                        }
                    },
                    error: function(xhr) {
                        console.error('Error:', xhr);
                        const errorMsg = xhr.responseJSON?.message || 'Error updating addon';
                        alert(errorMsg);
                    }
                });
            }

            // Remove addon from cart
            function removeAddonFromCart(cartKey, addonId) {
                $.ajax({
                    url: '{{ route('cart.addon.remove') }}',
                    method: 'POST',
                    data: {
                        _token: '{{ csrf_token() }}',
                        cart_key: cartKey,
                        addon_id: addonId
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert(response.message || 'Error removing addon');
                        }
                    },
                    error: function() {
                        alert('Error removing addon');
                    }
                });
            }

            // Remove addon from cart via button click
            $(document).on('click', '.remove-addon-btn', function() {
                const cartKey = $(this).data('cart-key');
                const addonId = $(this).data('addon-id');

                if (confirm('Remove this addon?')) {
                    removeAddonFromCart(cartKey, addonId);
                }
            });

            // Toggle addons section visibility
            $(document).on('click', '.toggle-addons-section', function() {
                const btn = $(this);
                const cartKey = btn.data('cart-key');
                const addonGrid = $(`.addons-grid-unified[data-cart-key="${cartKey}"]`);

                btn.toggleClass('collapsed');
                addonGrid.toggleClass('collapsed');

                // Update button text and icon
                if (btn.hasClass('collapsed')) {
                    btn.html('<i class="bi bi-chevron-down"></i> Show');
                } else {
                    btn.html('<i class="bi bi-chevron-up"></i> Hide');
                }
            });
        });
    </script>

    <style>
        /* Addon Toggle Button Styles */
        .addons-header-unified {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
        }

        .header-left {
            display: flex;
            flex-direction: column;
            gap: 5px;
            flex: 1;
        }

        .toggle-addons-section {
            padding: 6px 12px !important;
            font-size: 12px !important;
            white-space: nowrap;
            transition: all 0.3s ease;
        }

        .toggle-addons-section i {
            margin-right: 4px;
            transition: transform 0.3s ease;
        }

        .toggle-addons-section.collapsed i {
            transform: rotate(180deg);
        }

        .toggle-addons-section.collapsed {
            background-color: #e8f5e9 !important;
            color: #2e7d32 !important;
            border-color: #2e7d32 !important;
        }

        .addons-grid-unified {
            transition: max-height 0.3s ease, opacity 0.3s ease, padding 0.3s ease;
            overflow: hidden;
            max-height: 2000px;
            opacity: 1;
            padding: 15px 0;
        }

        .addons-grid-unified.collapsed {
            max-height: 0;
            opacity: 0;
            padding: 0;
            overflow: hidden;
        }

        /* Unified Addons Row Styling */
        .unified-addons-row {
            background-color: #f9f9f9;
            border-top: 2px solid #e0e0e0;
        }

        .unified-addons-container {
            padding: 20px 0;
        }

        .addons-header-unified {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 18px;
            padding-bottom: 12px;
            border-bottom: 1px solid #ddd;
        }

        .header-left {
            display: flex;
            align-items: baseline;
            gap: 12px;
        }

        .addons-header-unified h6 {
            font-size: 15px;
            font-weight: 700;
            color: #333;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .addons-header-unified h6 i {
            color: #c91c23;
            font-size: 18px;
        }

        .addons-header-unified small {
            font-size: 12px;
            color: #666;
            font-weight: 500;
        }

        .selected-count {
            color: #c91c23;
            font-weight: 700;
            font-size: 14px;
        }

        /* Unified Addon Grid */
        .unified-addons-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 16px;
        }

        /* Unified Addon Card */
        .unified-addon-card {
            background: white;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            padding: 16px;
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            gap: 12px;
            position: relative;
        }

        .unified-addon-card.addon-selected {
            border-color: #c91c23;
            background-color: #fff8f8;
            box-shadow: 0 2px 10px rgba(201, 28, 35, 0.15);
        }

        .unified-addon-card.addon-not-selected:hover {
            border-color: #ddd;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        .addon-card-header-unified {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 10px;
        }

        .addon-status-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            white-space: nowrap;
        }

        .addon-status-badge.badge-selected {
            background-color: #28a745;
            color: white;
        }

        .addon-status-badge.badge-available {
            background-color: #f0f0f0;
            color: #666;
        }

        .addon-status-badge i {
            font-size: 13px;
        }

        .badge-text {
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .addon-card-img-unified {
            width: 60px;
            height: 60px;
            border-radius: 8px;
            object-fit: cover;
            flex-shrink: 0;
            background-color: #f0f0f0;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #999;
            font-size: 28px;
        }

        /* Addon Card Body */
        .addon-card-body-unified {
            flex-grow: 1;
        }

        .addon-title-unified {
            margin: 0;
            font-size: 14px;
            font-weight: 700;
            color: #333;
            line-height: 1.3;
        }

        .addon-desc-unified {
            display: block;
            color: #888;
            font-size: 12px;
            line-height: 1.4;
            margin-top: 4px;
        }

        .addon-price-unified {
            display: flex;
            align-items: baseline;
            gap: 6px;
            margin-top: 8px;
            font-size: 13px;
        }

        .addon-price-unified strong {
            font-size: 16px;
            color: #c91c23;
            font-weight: 700;
        }

        .addon-price-unified span {
            color: #999;
            font-size: 11px;
        }

        /* Addon Controls */
        .addon-controls-unified {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .qty-control-unified {
            display: flex;
            align-items: center;
            gap: 2px;
            border: 1px solid #ddd;
            border-radius: 6px;
            padding: 2px;
            background-color: #f9f9f9;
        }

        .qty-btn-unified {
            width: 30px;
            height: 30px;
            padding: 0;
            border: none;
            background: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 16px;
            color: #333;
            transition: all 0.2s ease;
        }

        .qty-btn-unified:hover {
            background-color: #e0e0e0;
            color: #c91c23;
        }

        .qty-input-unified {
            width: 40px;
            text-align: center;
            border: none;
            background: none;
            font-size: 12px;
            font-weight: 700;
            padding: 4px;
        }

        .qty-input-unified::-webkit-outer-spin-button,
        .qty-input-unified::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }

        .qty-input-unified[type=number] {
            -moz-appearance: textfield;
        }

        .btn-apply-addon-unified {
            padding: 6px 12px;
            font-size: 11px;
            font-weight: 700;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .btn-addon-add {
            background-color: #007bff;
            color: white;
        }

        .btn-addon-add:hover {
            background-color: #0056b3;
        }

        .btn-addon-update {
            background-color: #28a745;
            color: white;
        }

        .btn-addon-update:hover {
            background-color: #218838;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .unified-addons-grid {
                grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            }

            .addons-header-unified {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }

            .addon-card-header-unified {
                flex-wrap: wrap;
            }

            .addon-controls-unified {
                flex-wrap: wrap;
            }

            .btn-apply-addon-unified {
                flex: 1;
                min-width: 80px;
            }
        }

        @media (max-width: 480px) {
            .unified-addons-grid {
                grid-template-columns: 1fr;
            }

            .addon-card-header-unified {
                flex-direction: column;
            }

            .addon-controls-unified {
                width: 100%;
                justify-content: space-between;
            }
        }
    </style>
@endpush
