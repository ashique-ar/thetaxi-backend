@extends('layouts.app')

@section('title', 'Your Cart - TheTaxi')

@section('content')
    <!-- Start Breadcrumb section -->
    <div class="breadcrumb-section"
        style="background-image:linear-gradient(rgba(0, 0, 0, 0.3), rgba(0, 0, 0, 0.3)), url({{ asset('assets/img/innerpages/breadcrumb-bg1.jpg') }});">
        <div class="container">
            <div class="banner-content">
                <h1>Your Cart</h1>
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
            @if(session('success'))
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    {{ session('success') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            @endif

            @if(session('error'))
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    {{ session('error') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            @endif

            @if(count($cartItems) > 0)
                <div class="row g-lg-4 gy-5">
                    <div class="col-xl-8 col-lg-7">
                        <div class="cart-shopping-wrapper">
                            <div class="cart-widget-title">
                                <h4>Your Vehicle Bookings</h4>
                                <p>{{ count($cartItems) }} item(s) in your cart</p>
                            </div>
                            
                            <div class="cart-items-container">
                                @foreach($cartItems as $item)
                                    <div class="cart-item-card" data-key="{{ $item['key'] }}">
                                        <div class="row align-items-center">
                                            <div class="col-md-6">
                                                <div class="vehicle-cart-info">
                                                    <div class="vehicle-cart-image">
                                                        <img src="{{ asset('assets/img/default-vehicle.jpg') }}" alt="{{ $item['vehicle_group']->name }}">
                                                    </div>
                                                    <div class="vehicle-cart-details">
                                                        <h6>{{ $item['vehicle_group']->name }}</h6>
                                                        <p class="vehicle-category">
                                                            <i class="bi bi-tag"></i>
                                                            {{ $item['vehicle_group']->category->name ?? 'N/A' }}
                                                        </p>
                                                        <div class="booking-dates">
                                                            <div class="date-info">
                                                                <small class="text-muted">Pickup:</small>
                                                                <span>{{ \Carbon\Carbon::parse($item['pickup_date'])->format('d M Y') }}</span>
                                                            </div>
                                                            <div class="date-info">
                                                                <small class="text-muted">Dropoff:</small>
                                                                <span>{{ \Carbon\Carbon::parse($item['dropoff_date'])->format('d M Y') }}</span>
                                                            </div>
                                                            <div class="date-info">
                                                                <small class="text-muted">Duration:</small>
                                                                <span>{{ $item['duration_days'] }} {{ $item['duration_days'] > 1 ? 'days' : 'day' }}</span>
                                                            </div>
                                                        </div>
                                                        <div class="location-info">
                                                            <small>
                                                                <i class="bi bi-geo-alt"></i>
                                                                {{ $item['pickup_location'] }} 
                                                                @if($item['dropoff_location'] && $item['dropoff_location'] != $item['pickup_location'])
                                                                    → {{ $item['dropoff_location'] }}
                                                                @endif
                                                            </small>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="pricing-info">
                                                    <div class="price-breakdown">
                                                        <div class="price-item">
                                                            <span class="price-label">Per Day:</span>
                                                            <span class="price-value">LKR {{ number_format($item['base_price'], 2) }}</span>
                                                        </div>
                                                        <div class="price-item">
                                                            <span class="price-label">Quantity:</span>
                                                            <div class="quantity-controls">
                                                                <button type="button" class="qty-btn minus" data-key="{{ $item['key'] }}">-</button>
                                                                <input type="number" class="qty-input" value="{{ $item['quantity'] }}" min="1" max="10" data-key="{{ $item['key'] }}">
                                                                <button type="button" class="qty-btn plus" data-key="{{ $item['key'] }}">+</button>
                                                            </div>
                                                        </div>
                                                        <div class="price-item total">
                                                            <span class="price-label">Total:</span>
                                                            <span class="price-value total-price">LKR {{ number_format($item['total_price'], 2) }}</span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="cart-item-actions">
                                                    <button type="button" class="btn btn-outline-primary btn-sm edit-dates-btn" data-key="{{ $item['key'] }}">
                                                        <i class="bi bi-calendar-event"></i> Edit Dates
                                                    </button>
                                                    <button type="button" class="btn btn-outline-danger btn-sm remove-item-btn" data-key="{{ $item['key'] }}">
                                                        <i class="bi bi-trash"></i> Remove
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-4 col-lg-5">
                        <div class="cart-summary-wrapper">
                            <div class="cart-widget-title">
                                <h4>Order Summary</h4>
                            </div>
                            
                            <div class="cart-summary-content">
                                <div class="summary-calculations">
                                    @php
                                        $subtotal = 0;
                                        foreach($cartItems as $item) {
                                            $subtotal += $item['total_price'];
                                        }
                                        $taxes = $subtotal * 0.1; // 10% tax
                                        $total = $subtotal + $taxes;
                                    @endphp
                                    
                                    <div class="summary-row">
                                        <span>Subtotal:</span>
                                        <span>LKR {{ number_format($subtotal, 2) }}</span>
                                    </div>
                                    <div class="summary-row">
                                        <span>Estimated Taxes:</span>
                                        <span>LKR {{ number_format($taxes, 2) }}</span>
                                    </div>
                                    <div class="summary-row total">
                                        <span><strong>Total:</strong></span>
                                        <span><strong>LKR {{ number_format($total, 2) }}</strong></span>
                                    </div>
                                </div>
                                
                                <div class="checkout-actions">
                                    <a href="{{ route('checkout') }}" class="primary-btn1 w-100 mb-3">
                                        <span>
                                            <svg width="10" height="10" viewBox="0 0 10 10" xmlns="http://www.w3.org/2000/svg">
                                                <path d="M9.73535 1.14746C9.57033 1.97255 9.32924 3.26406 9.24902 4.66797C9.16817 6.08312 9.25559 7.5453 9.70214 8.73633C9.84754 9.12406 9.65129 9.55659 9.26367 9.70215C8.9001 9.83849 8.4969 9.67455 8.32812 9.33398L8.29785 9.26367L8.19921 8.98438C7.73487 7.5758 7.67054 5.98959 7.75097 4.58203C7.77875 4.09598 7.82525 3.62422 7.87988 3.17969L1.53027 9.53027C1.23738 9.82317 0.762615 9.82317 0.469722 9.53027C0.176829 9.23738 0.176829 8.76262 0.469722 8.46973L6.83593 2.10254C6.3319 2.16472 5.79596 2.21841 5.25 2.24902C3.8302 2.32862 2.2474 2.26906 0.958003 1.79102L0.704097 1.68945L0.635738 1.65527C0.303274 1.47099 0.157578 1.06102 0.310542 0.704102C0.463655 0.347333 0.860941 0.170391 1.22363 0.28418L1.29589 0.310547L1.48828 0.387695C2.47399 0.751207 3.79966 0.827571 5.16601 0.750977C6.60111 0.670504 7.97842 0.428235 8.86132 0.262695L9.95312 0.0585938L9.73535 1.14746Z"></path>
                                            </svg>
                                            Proceed to Checkout
                                        </span>
                                    </a>
                                    <a href="{{ route('home') }}" class="btn btn-outline-primary w-100 mb-2">
                                        <i class="bi bi-arrow-left"></i> Continue Shopping
                                    </a>
                                    <button type="button" class="btn btn-outline-danger w-100" id="clear-cart-btn">
                                        <i class="bi bi-trash"></i> Clear Cart
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            @else
                <!-- Empty Cart -->
                <div class="empty-cart-section">
                    <div class="text-center py-5">
                        <svg width="120" height="120" viewBox="0 0 120 120" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <circle cx="60" cy="60" r="60" fill="#f8f9fa"/>
                            <path d="M45 45h30v5H45v-5zm0 10h30v5H45v-5zm0 10h20v5H45v-5z" fill="#dee2e6"/>
                            <rect x="35" y="40" width="50" height="35" stroke="#adb5bd" stroke-width="2" fill="none"/>
                        </svg>
                        <h3 class="mt-4">Your Cart is Empty</h3>
                        <p class="text-muted">Start booking your perfect vehicle for your next trip.</p>
                        <a href="{{ route('home') }}" class="primary-btn1 mt-3">
                            <span>
                                <i class="bi bi-search"></i> Browse Vehicles
                            </span>
                        </a>
                    </div>
                </div>
            @endif
        </div>
    </div>
    <!--Cart Page End-->

    <!-- Date Edit Modal -->
    <div class="modal fade" id="editDatesModal" tabindex="-1" aria-labelledby="editDatesModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editDatesModalLabel">Edit Booking Dates</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="editDatesForm">
                        <input type="hidden" id="editItemKey" name="item_key">
                        <div class="row">
                            <div class="col-6">
                                <label for="editPickupDate" class="form-label">Pickup Date</label>
                                <input type="date" class="form-control" id="editPickupDate" name="pickup_date" required>
                            </div>
                            <div class="col-6">
                                <label for="editDropoffDate" class="form-label">Dropoff Date</label>
                                <input type="date" class="form-control" id="editDropoffDate" name="dropoff_date" required>
                            </div>
                        </div>
                        <div class="mt-3">
                            <div class="pricing-preview">
                                <div class="row">
                                    <div class="col-6">
                                        <small class="text-muted">Duration:</small>
                                        <span id="previewDuration">-</span>
                                    </div>
                                    <div class="col-6">
                                        <small class="text-muted">New Total:</small>
                                        <span id="previewTotal">-</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="saveDateChanges">Save Changes</button>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('styles')
<style>
    /* Cart Item Cards */
    .cart-item-card {
        background: white;
        border-radius: 12px;
        padding: 25px;
        margin-bottom: 20px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        transition: all 0.3s ease;
    }

    .cart-item-card:hover {
        box-shadow: 0 4px 20px rgba(0,0,0,0.12);
    }

    .vehicle-cart-info {
        display: flex;
        gap: 15px;
        align-items: center;
    }

    .vehicle-cart-image {
        width: 80px;
        height: 60px;
        border-radius: 8px;
        overflow: hidden;
        flex-shrink: 0;
    }

    .vehicle-cart-image img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .vehicle-cart-details h6 {
        margin: 0 0 8px 0;
        font-size: 16px;
        font-weight: 600;
        color: #333;
    }

    .vehicle-category {
        color: #666;
        font-size: 13px;
        margin: 0 0 10px 0;
    }

    .vehicle-category i {
        color: #ff8c00;
    }

    .booking-dates {
        display: flex;
        flex-wrap: wrap;
        gap: 15px;
        margin-bottom: 8px;
    }

    .date-info {
        font-size: 12px;
    }

    .date-info small {
        display: block;
        color: #999;
        margin-bottom: 2px;
    }

    .date-info span {
        font-weight: 600;
        color: #333;
    }

    .location-info {
        color: #666;
        font-size: 12px;
    }

    .location-info i {
        color: #ff8c00;
    }

    /* Pricing Info */
    .pricing-info {
        text-align: center;
    }

    .price-breakdown {
        background: #f8f9fa;
        border-radius: 8px;
        padding: 15px;
    }

    .price-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 8px;
    }

    .price-item:last-child {
        margin-bottom: 0;
    }

    .price-item.total {
        border-top: 1px solid #e9ecef;
        padding-top: 8px;
        margin-top: 8px;
    }

    .price-label {
        font-size: 13px;
        color: #666;
    }

    .price-value {
        font-weight: 600;
        color: #333;
    }

    .total-price {
        color: #ff8c00;
        font-size: 16px;
    }

    /* Quantity Controls */
    .quantity-controls {
        display: flex;
        align-items: center;
        gap: 5px;
    }

    .qty-btn {
        width: 25px;
        height: 25px;
        border: 1px solid #e9ecef;
        background: white;
        border-radius: 4px;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 14px;
        transition: all 0.3s ease;
    }

    .qty-btn:hover {
        background: #f8f9fa;
        border-color: #ff8c00;
    }

    .qty-input {
        width: 40px;
        height: 25px;
        text-align: center;
        border: 1px solid #e9ecef;
        border-radius: 4px;
        font-size: 12px;
        font-weight: 600;
    }

    /* Cart Actions */
    .cart-item-actions {
        display: flex;
        flex-direction: column;
        gap: 8px;
        text-align: center;
    }

    .cart-item-actions .btn {
        font-size: 12px;
        padding: 6px 12px;
    }

    /* Cart Summary */
    .cart-summary-wrapper {
        position: sticky;
        top: 20px;
    }

    .cart-summary-content {
        background: white;
        border-radius: 12px;
        padding: 25px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.08);
    }

    .summary-calculations {
        margin-bottom: 25px;
    }

    .summary-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 8px 0;
        border-bottom: 1px solid #f0f0f0;
    }

    .summary-row:last-child {
        border-bottom: none;
    }

    .summary-row.total {
        border-top: 2px solid #ff8c00;
        padding-top: 15px;
        margin-top: 10px;
        font-size: 18px;
    }

    /* Empty Cart */
    .empty-cart-section {
        background: white;
        border-radius: 12px;
        padding: 60px 40px;
        text-align: center;
        box-shadow: 0 2px 10px rgba(0,0,0,0.08);
    }

    /* Cart Widget Title */
    .cart-widget-title {
        background: white;
        border-radius: 12px;
        padding: 20px 25px;
        margin-bottom: 20px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.08);
    }

    .cart-widget-title h4 {
        margin: 0 0 5px 0;
        color: #333;
        font-weight: 600;
    }

    .cart-widget-title p {
        margin: 0;
        color: #666;
        font-size: 14px;
    }

    /* Modal Styles */
    .pricing-preview {
        background: #f8f9fa;
        border-radius: 8px;
        padding: 15px;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .vehicle-cart-info {
            flex-direction: column;
            text-align: center;
        }
        
        .booking-dates {
            justify-content: center;
        }
        
        .cart-item-actions {
            margin-top: 15px;
        }
        
        .pricing-info {
            margin-top: 15px;
        }
    }
</style>
@endpush

@push('scripts')
<script>
    $(document).ready(function() {
        // Quantity Controls
        $('.qty-btn').on('click', function() {
            const $btn = $(this);
            const $input = $btn.siblings('.qty-input');
            const currentValue = parseInt($input.val()) || 1;
            const itemKey = $btn.data('key');
            
            let newValue = currentValue;
            if ($btn.hasClass('plus')) {
                newValue = Math.min(currentValue + 1, 10);
            } else if ($btn.hasClass('minus')) {
                newValue = Math.max(currentValue - 1, 1);
            }
            
            if (newValue !== currentValue) {
                $input.val(newValue);
                updateCartQuantity(itemKey, newValue);
            }
        });
        
        $('.qty-input').on('change', function() {
            const $input = $(this);
            const itemKey = $input.data('key');
            const newValue = Math.min(Math.max(parseInt($input.val()) || 1, 1), 10);
            
            $input.val(newValue);
            updateCartQuantity(itemKey, newValue);
        });
        
        // Update cart quantity via AJAX
        function updateCartQuantity(itemKey, quantity) {
            $.ajaxSetup({
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                }
            });
            
            $.ajax({
                url: `/cart/update/${itemKey}`,
                method: 'PATCH',
                data: { quantity: quantity },
                success: function(response) {
                    if (response.success) {
                        location.reload(); // Reload to update totals
                    }
                },
                error: function(xhr) {
                    console.error('Update failed:', xhr.responseJSON);
                    alert('Failed to update quantity. Please try again.');
                }
            });
        }
        
        // Remove Item
        $('.remove-item-btn').on('click', function() {
            const itemKey = $(this).data('key');
            
            if (confirm('Are you sure you want to remove this item from your cart?')) {
                const form = $('<form>', {
                    method: 'POST',
                    action: `/cart/remove/${itemKey}`
                });
                
                form.append($('<input>', {
                    type: 'hidden',
                    name: '_token',
                    value: $('meta[name="csrf-token"]').attr('content')
                }));
                
                form.append($('<input>', {
                    type: 'hidden',
                    name: '_method',
                    value: 'DELETE'
                }));
                
                $('body').append(form);
                form.submit();
            }
        });
        
        // Clear Cart
        $('#clear-cart-btn').on('click', function() {
            if (confirm('Are you sure you want to clear your entire cart?')) {
                const form = $('<form>', {
                    method: 'POST',
                    action: '/cart/clear'
                });
                
                form.append($('<input>', {
                    type: 'hidden',
                    name: '_token',
                    value: $('meta[name="csrf-token"]').attr('content')
                }));
                
                form.append($('<input>', {
                    type: 'hidden',
                    name: '_method',
                    value: 'DELETE'
                }));
                
                $('body').append(form);
                form.submit();
            }
        });
        
        // Edit Dates Modal
        $('.edit-dates-btn').on('click', function() {
            const itemKey = $(this).data('key');
            const $card = $(this).closest('.cart-item-card');
            
            // Extract current dates from the card
            const currentPickup = $card.find('.date-info:first span').text();
            const currentDropoff = $card.find('.date-info:nth-child(2) span').text();
            
            // Convert display format back to input format (assuming DD MMM YYYY format)
            const pickupDate = convertDisplayDateToInputDate(currentPickup);
            const dropoffDate = convertDisplayDateToInputDate(currentDropoff);
            
            $('#editItemKey').val(itemKey);
            $('#editPickupDate').val(pickupDate);
            $('#editDropoffDate').val(dropoffDate);
            
            $('#editDatesModal').modal('show');
        });
        
        // Date change in modal
        $('#editPickupDate, #editDropoffDate').on('change', function() {
            updateModalPreview();
        });
        
        function updateModalPreview() {
            const pickupDate = new Date($('#editPickupDate').val());
            const dropoffDate = new Date($('#editDropoffDate').val());
            
            if (pickupDate && dropoffDate && dropoffDate > pickupDate) {
                const timeDiff = dropoffDate.getTime() - pickupDate.getTime();
                const daysDiff = Math.ceil(timeDiff / (1000 * 3600 * 24));
                
                $('#previewDuration').text(daysDiff + (daysDiff > 1 ? ' days' : ' day'));
                // You would need to calculate new total based on item's base price
                // For now, showing placeholder
                $('#previewTotal').text('LKR --.--');
            } else {
                $('#previewDuration').text('-');
                $('#previewTotal').text('-');
            }
        }
        
        function convertDisplayDateToInputDate(displayDate) {
            // Convert "DD MMM YYYY" to "YYYY-MM-DD"
            // This is a simplified version - you might need to adjust based on exact format
            const date = new Date(displayDate);
            if (!isNaN(date.getTime())) {
                return date.toISOString().split('T')[0];
            }
            return '';
        }
    });
</script>
@endpush