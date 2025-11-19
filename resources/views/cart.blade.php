@extends('layouts.app')

@section('title', 'Shopping Cart - TheTaxi')

@section('content')
<!-- Breadcrumb section -->
<div class="breadcrumb-section" style="background-image:linear-gradient(rgba(0, 0, 0, 0.3), rgba(0, 0, 0, 0.3)), url({{ asset('assets/img/innerpages/breadcrumb-bg1.jpg') }});">  
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
            } catch (Exception $e) {
                \Log::error('Error loading cart: ' . $e->getMessage());
                $cart = [];
                $cartTotals = [];
                $currencySymbol = getCurrencySymbol();
                $selectedCurrency = getSelectedCurrency();
            }
        @endphp
        
        @if(!empty($cart))
        <div class="row g-lg-4 gy-5">
            <div class="col-xl-8 col-lg-7">
                <div class="cart-shopping-wrapper">
                    <div class="cart-widget-title">
                        <h4>My Shopping Cart</h4>
                    </div>
                    <table class="cart-table">
                        <thead>
                            <tr>
                                <th>Vehicle Info</th>
                                <th>Price</th>
                                <th>Days</th>
                                <th>Total</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($cart as $key => $item)
                            <tr data-cart-key="{{ $key }}">
                                <td data-label="Vehicle Info">
                                    <div class="product-info-wrapper">
                                        <div class="product-info-img">
                                            @if(isset($item['image']) && $item['image'])
                                                <img src="{{ asset('storage/' . $item['image']) }}" alt="{{ $item['name'] ?? 'Vehicle' }}">
                                            @else
                                                <img src="{{ asset('assets/img/innerpages/cart-img1.png') }}" alt="{{ $item['name'] ?? 'Vehicle' }}">
                                            @endif
                                        </div>
                                        <div class="product-info-content">
                                            <h6>{{ $item['name'] ?? 'Vehicle Rental' }}</h6>
                                            <p><span>Vehicle Type: </span>{{ $item['vehicle_type'] ?? 'Sedan' }}</p>
                                            <div class="booking-details">
                                                @if(isset($item['pickup_date']) && isset($item['return_date']))
                                                <p><strong>Pickup:</strong> {{ date('M d, Y', strtotime($item['pickup_date'])) }}
                                                    @if(isset($item['from_time']))
                                                    <span class="text-muted">@ {{ $item['from_time'] }}</span>
                                                    @endif
                                                </p>
                                                <p><strong>Return:</strong> {{ date('M d, Y', strtotime($item['return_date'])) }}
                                                    @if(isset($item['to_time']))
                                                    <span class="text-muted">@ {{ $item['to_time'] }}</span>
                                                    @endif
                                                </p>
                                                @endif
                                                @if(isset($item['pickup_location']))
                                                <p><strong>From:</strong> {{ $item['pickup_location'] }}</p>
                                                @endif
                                                @if(isset($item['return_location']) && $item['return_location'] !== $item['pickup_location'])
                                                <p><strong>To:</strong> {{ $item['return_location'] }}</p>
                                                @endif
                                            </div>
                                            <ul>
                                                <li>
                                                    <button class="remove-item" data-cart-key="{{ $key }}">
                                                        <i class="bi bi-trash"></i> Remove
                                                    </button>
                                                </li>
                                                <li>
                                                    <div class="qty-btn">Days</div>
                                                    <div class="quantity-area">
                                                        <div class="quantity">
                                                            <a class="quantity__minus" data-cart-key="{{ $key }}">
                                                                <span><i class="bi bi-dash"></i></span>
                                                            </a>
                                                            <input name="quantity" type="text" class="quantity__input" 
                                                                   value="{{ $item['days'] ?? 1 }}" data-cart-key="{{ $key }}" readonly>
                                                            <a class="quantity__plus" data-cart-key="{{ $key }}">
                                                                <span><i class="bi bi-plus"></i></span>
                                                            </a>
                                                        </div>
                                                    </div>
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                </td>
                                <td data-label="Price">
                                    <span>{{ $currencySymbol }}{{ number_format($item['price'] ?? 0, 2) }}/day</span>
                                </td>
                                <td data-label="Days">
                                    <span class="days-display">{{ $item['days'] ?? 1 }}</span>
                                </td>
                                <td data-label="Total">
                                    <span class="item-total">{{ $currencySymbol }}{{ number_format(($item['price'] ?? 0) * ($item['days'] ?? 1), 2) }}</span>
                                </td>
                                <td data-label="Action">
                                    <button class="btn btn-sm btn-danger remove-item" data-cart-key="{{ $key }}">
                                        <i class="bi bi-x"></i>
                                    </button>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <div class="cart-actions mt-4">
                        <a href="{{ route('search') }}" class="details-button">
                            Continue Shopping
                            <svg width="10" height="10" viewBox="0 0 10 10" xmlns="http://www.w3.org/2000/svg">
                                <path d="M1 9L9 1M9 1C7.22222 1.33333 3.33333 2 1 1M9 1C8.66667 2.66667 8 6.33333 9 9" stroke-width="1.5" stroke-linecap="round" />
                            </svg>
                        </a>
                        <button class="details-button clear-cart ms-3">
                            <i class="bi bi-trash"></i> Clear Cart
                        </button>
                    </div>
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
                                    <span class="service-fee">{{ $currencySymbol }}{{ number_format($cartTotals['service_fee'] ?? 0, 2) }}</span>
                                </div>
                            </li>
                            <li>
                                Tax (10%)
                                <div class="order-info">
                                    <p>Estimated Tax</p>
                                    <span class="tax-amount">
                                        {{ $currencySymbol }}{{ number_format($cartTotals['tax'] ?? 0, 2) }}
                                    </span>
                                </div>
                            </li>
                            <li>
                                <div class="coupon-area">
                                    <span>Coupon Code</span>
                                    <form id="coupon-form">
                                        @csrf
                                        <div class="form-inner">
                                            <input type="text" name="coupon_code" placeholder="Enter coupon code" id="coupon-input">
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
                                    <svg width="10" height="10" viewBox="0 0 10 10" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M9.73535 1.14746C9.57033 1.97255 9.32924 3.26406 9.24902 4.66797C9.16817 6.08312 9.25559 7.5453 9.70214 8.73633C9.84754 9.12406 9.65129 9.55659 9.26367 9.70215C8.9001 9.83849 8.4969 9.67455 8.32812 9.33398L8.29785 9.26367L8.19921 8.98438C7.73487 7.5758 7.67054 5.98959 7.75097 4.58203C7.77875 4.09598 7.82525 3.62422 7.87988 3.17969L1.53027 9.53027C1.23738 9.82317 0.762615 9.82317 0.469722 9.53027C0.176829 9.23738 0.176829 8.76262 0.469722 8.46973L6.83593 2.10254C6.3319 2.16472 5.79596 2.21841 5.25 2.24902C3.8302 2.32862 2.2474 2.26906 0.958003 1.79102L0.704097 1.68945L0.635738 1.65527C0.303274 1.47099 0.157578 1.06102 0.310542 0.704102C0.463655 0.347333 0.860941 0.170391 1.22363 0.28418L1.29589 0.310547L1.48828 0.387695C2.47399 0.751207 3.79966 0.827571 5.16601 0.750977C6.60111 0.670504 7.97842 0.428235 8.86132 0.262695L9.95312 0.0585938L9.73535 1.14746Z"></path>
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
                <img src="{{ asset('assets/img/innerpages/empty-cart.png') }}" alt="Empty Cart" class="mb-4" style="max-width: 300px;">
                <h3>Your cart is empty</h3>
                <p class="mb-4">Looks like you haven't added any vehicles to your cart yet.</p>
                <a href="{{ route('search') }}" class="primary-btn1">
                    <span>
                        Start Shopping
                        <svg width="10" height="10" viewBox="0 0 10 10" xmlns="http://www.w3.org/2000/svg">
                            <path d="M1 9L9 1M9 1C7.22222 1.33333 3.33333 2 1 1M9 1C8.66667 2.66667 8 6.33333 9 9" stroke-width="1.5" stroke-linecap="round" />
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

.quantity {
    display: flex;
    align-items: center;
    border: 1px solid #ddd;
    border-radius: 5px;
    width: fit-content;
}

.quantity__minus,
.quantity__plus {
    padding: 5px 10px;
    background: #f8f9fa;
    cursor: pointer;
    user-select: none;
}

.quantity__input {
    border: none;
    text-align: center;
    width: 50px;
    padding: 5px;
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
    // Update cart totals
    function updateCartTotals() {
        // The totals are already calculated server-side with proper currency conversion
        // This function is kept for compatibility but should rely on server data
        console.log('Cart totals are managed server-side with currency conversion');
    }

    // Quantity controls
    $('.quantity__plus').on('click', function() {
        let cartKey = $(this).data('cart-key');
        let input = $(this).siblings('.quantity__input');
        let currentVal = parseInt(input.val()) || 1;
        let newVal = currentVal + 1;
        
        updateCartItemDays(cartKey, newVal);
    });

    $('.quantity__minus').on('click', function() {
        let cartKey = $(this).data('cart-key');
        let input = $(this).siblings('.quantity__input');
        let currentVal = parseInt(input.val()) || 1;
        let newVal = Math.max(1, currentVal - 1);
        
        updateCartItemDays(cartKey, newVal);
    });

    // Update cart item days via AJAX
    function updateCartItemDays(cartKey, days) {
        $.ajax({
            url: '{{ route("cart.update-days") }}',
            method: 'POST',
            data: {
                _token: '{{ csrf_token() }}',
                cart_key: cartKey,
                days: days
            },
            success: function(response) {
                if (response.success) {
                    // Update the input value
                    $('input[data-cart-key="' + cartKey + '"]').val(days);
                    // Reload page to get updated totals with proper currency conversion
                    location.reload();
                }
            },
            error: function() {
                alert('Error updating cart. Please try again.');
            }
        });
    }

    // Remove item from cart
    $(document).on('click', '.remove-item', function() {
        if (confirm('Are you sure you want to remove this item?')) {
            let cartKey = $(this).data('cart-key');
            
            $.ajax({
                url: '{{ route("cart.remove") }}',
                method: 'POST',
                data: {
                    _token: '{{ csrf_token() }}',
                    cart_key: cartKey
                },
                success: function(response) {
                    if (response.success) {
                        // Reload the entire page to reflect server-side changes
                        location.reload();
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
                url: '{{ route("cart.clear") }}',
                method: 'POST',
                data: {
                    _token: '{{ csrf_token() }}'
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
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
            url: '{{ route("cart.apply-coupon") }}',
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
                    $('#coupon-message').html('<div class="alert alert-danger">' + response.message + '</div>');
                }
            },
            error: function() {
                $('#coupon-message').html('<div class="alert alert-danger">Error applying coupon. Please try again.</div>');
            }
        });
    });
});
</script>
@endpush