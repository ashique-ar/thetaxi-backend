{{--
    Cart Summary Float Component
    
    A floating cart summary panel that displays cart items and pricing breakdown.
    Can be included in any view using <x-cart-summary-float />
    
    Props:
    - cartRoute: (optional) Override cart page route
    - getCartRoute: (optional) Override cart.get API route  
    - removeRoute: (optional) Override cart.remove API route
    
    Requirements: 1.1, 1.3, 1.4, 1.5, 1.6, 1.7, 1.8, 1.9, 1.10, 1.11, 1.12
--}}

@props([
    'cartRoute' => null,
    'getCartRoute' => null,
    'removeRoute' => null,
])

@php
    $cartPageRoute = $cartRoute ?? route('checkout');
    $currencySymbol = getCurrencySymbol();
@endphp

{{-- Component Styles - wrapped in @once to prevent duplication (Requirements: 1.2, 7.1, 7.2, 7.3) --}}
@once('cart-summary-float-styles')
@push('styles')
<style>
    /* ==================== CART SUMMARY FLOAT COMPONENT STYLES ==================== */
    
    /* Main container - fixed position floating panel */
    .cart-summary-float {
        position: fixed;
        bottom: 20px;
        right: 20px;
        width: 350px;
        background: var(--primary-color, #111827);
        border-radius: 16px;
        box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
        z-index: 1050;
        animation: cartSummaryFloatSlideInUp 0.4s ease-out;
        overflow: hidden;
    }

    .cart-summary-float .cart-float-content {
        background: var(--primary-color, #111827);
    }

    /* Slide-in animation for cart float appearance */
    @keyframes cartSummaryFloatSlideInUp {
        from {
            transform: translateY(100px);
            opacity: 0;
        }
        to {
            transform: translateY(0);
            opacity: 1;
        }
    }

    /* Cart float header - title and close button */
    .cart-summary-float .cart-float-header {
        padding: 16px 20px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.2);
        display: flex;
        justify-content: space-between;
        align-items: center;
        color: white;
    }

    .cart-summary-float .cart-float-header h6 {
        margin: 0;
        font-weight: 700;
        color: white;
    }

    /* Cart float body - scrollable items container */
    .cart-summary-float .cart-float-body {
        padding: 16px 20px;
        max-height: 300px;
        overflow-y: auto;
        color: white;
        background: var(--primary-color, #111827);
    }

    /* Individual cart item styling */
    .cart-summary-float .cart-float-item {
        padding: 12px 0;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        line-height: 1.5;
    }

    .cart-summary-float .cart-float-item:last-child {
        border-bottom: none;
    }

    /* Cart float footer - totals and checkout button */
    .cart-summary-float .cart-float-footer {
        padding: 16px 20px;
        border-top: 1px solid rgba(255, 255, 255, 0.2);
        background: var(--primary-color, #111827);
    }

    /* Cart total row styling */
    .cart-summary-float .cart-total {
        display: flex;
        justify-content: space-between;
        align-items: center;
        color: white;
        font-size: 16px;
    }

    .cart-summary-float .cart-total strong {
        font-size: 20px;
        font-weight: 800;
    }

    /* Currency symbol styling within cart float */
    .cart-summary-float .currency-symbol {
        font-size: 0.75em;
        font-weight: 400 !important;
        opacity: 0.9;
        margin-right: 0.2rem;
    }

    /* Cart breakdown rows (subtotal, charges, taxes) */
    .cart-summary-float .cart-breakdown > div {
        display: flex;
        justify-content: space-between;
        align-items: center;
        color: white;
        font-size: 14px;
        margin-bottom: 4px;
    }

    .cart-summary-float .cart-breakdown > div span:first-child {
        opacity: 0.9;
    }

    /* ==================== RESPONSIVE STYLES ==================== */
    
    /* Tablet breakpoint */
    @media (max-width: 991px) {
        .cart-summary-float {
            width: 300px;
        }
    }

    /* Mobile breakpoint - full width with margins */
    @media (max-width: 767px) {
        .cart-summary-float {
            width: calc(100% - 40px);
            right: 20px;
            left: 20px;
        }
    }
</style>
@endpush
@endonce

<!-- Cart Summary Float (Hidden by default, shown when items added) -->
<div id="cartSummaryFloat" class="cart-summary-float {{ theme_class('cart-summary-float') }}" style="display: none;">
    <div class="cart-float-content">
        {{-- Cart Header with icon and close button --}}
        <div class="cart-float-header">
            <h6><i class="bi bi-cart-fill"></i> Cart</h6>
            <button type="button" class="btn-close btn-close-white" id="closeCartFloat" aria-label="Close cart"></button>
        </div>
        
        {{-- Cart Body - Dynamic cart items container --}}
        <div class="cart-float-body" id="cartFloatItems">
            <!-- Cart items will be dynamically added here via JavaScript -->
        </div>
        
        {{-- Cart Footer - Charge breakdown and checkout --}}
        <div class="cart-float-footer">
            <div class="cart-breakdown">
                {{-- Subtotal row - always visible --}}
                <div class="cart-subtotal mb-2">
                    <span>Subtotal:</span>
                    <span id="cartSubtotalPrice">
                        <span class="currency-symbol">{{ $currencySymbol }}</span> 
                        <span class="amount">0</span>
                    </span>
                </div>
                
                {{-- Add-on Charges row - hidden when zero (Requirement 1.6) --}}
                <div class="cart-addon-charges mb-1" style="display: none;">
                    <span>Add-on Charges:</span>
                    <span id="cartAddonCharges">
                        <span class="currency-symbol">{{ $currencySymbol }}</span> 
                        <span class="amount">0</span>
                    </span>
                </div>
                
                {{-- Extra KM Charges row - hidden when zero (Requirement 1.7) --}}
                <div class="cart-extra-km-charges mb-1" style="display: none;">
                    <span>Extra KM Charges:</span>
                    <span id="cartExtraKmCharges">
                        <span class="currency-symbol">{{ $currencySymbol }}</span> 
                        <span class="amount">0</span>
                    </span>
                </div>
                
                {{-- Service Fee row - hidden when zero (Requirement 1.8) --}}
                <div class="cart-service-fee mb-1" style="display: none;">
                    <span>Service Fee:</span>
                    <span id="cartServiceFee">
                        <span class="currency-symbol">{{ $currencySymbol }}</span> 
                        <span class="amount">0</span>
                    </span>
                </div>
                
                {{-- Tax row - hidden when zero (Requirement 1.9) --}}
                <div class="cart-tax mb-1" style="display: none;">
                    <span>Tax:</span>
                    <span id="cartTax">
                        <span class="currency-symbol">{{ $currencySymbol }}</span> 
                        <span class="amount">0</span>
                    </span>
                </div>
                
                {{-- VAT row - hidden when zero (Requirement 1.10) --}}
                <div class="cart-vat mb-1" style="display: none;">
                    <span>VAT:</span>
                    <span id="cartVat">
                        <span class="currency-symbol">{{ $currencySymbol }}</span> 
                        <span class="amount">0</span>
                    </span>
                </div>
                
                {{-- Total Amount row - always visible (Requirement 1.11) --}}
                <div class="cart-total mb-2 mt-2" style="border-top: 1px solid rgba(255,255,255,0.2); padding-top: 8px;">
                    <span><strong>Total Amount:</strong></span>
                    <strong id="cartTotalPrice">
                        <span class="currency-symbol">{{ $currencySymbol }}</span> 
                        <span class="amount">0</span>
                    </strong>
                </div>
            </div>
            
            {{-- Helper text --}}
            <small class="text-white d-block mb-2">Review extras and extra km at checkout</small>
            
            {{-- Checkout button (Requirement 1.12) --}}
            <a href="{{ $cartPageRoute }}" class="btn btn-light w-100">
                <i class="bi bi-cart-check"></i> Review & Checkout
            </a>
        </div>
    </div>
</div>

{{-- Component JavaScript - wrapped in @once to prevent duplication (Requirements: 1.13, 5.1, 5.2, 5.3, 5.4, 5.5, 5.6, 5.7, 5.8) --}}
@once('cart-summary-float-scripts')
@push('scripts')
<script>
    // Cart Summary Float Component - Cart Management (Session-based)
    // These variables are used by the cart float component for state management
    if (typeof window.cartFloatInitialized === 'undefined') {
        window.cartFloatInitialized = true;
        
        // Cart state variables
        var cart = [];
        var cartTotals = {};
        var cartCurrency = '{{ getSelectedCurrency() }}';
        var cartCurrencySymbol = '{{ getCurrencySymbol() }}';

        /**
         * Load cart data from server via AJAX
         * Fetches current cart state and updates the display
         * Requirements: 1.13
         */
        function loadCart() {
            loadCartFromServer();
        }

        /**
         * Fetch cart data from server and update local state
         * Makes AJAX GET request to cart.get route
         * Requirements: 1.13
         */
        function loadCartFromServer() {
            $.ajax({
                url: '{{ route('cart.get') }}',
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

        /**
         * Update the cart display with current cart items and totals
         * Renders cart items with vehicle name, dates, rate type, duration, and pricing
         * Requirements: 5.1, 5.2, 5.3, 5.4, 5.5, 5.6, 5.7
         */
        function updateCartDisplay() {
            const $cartItems = $('#cartFloatItems');
            const $cartTotal = $('#cartTotalPrice');

            $cartItems.empty();

            // Helper function to get pricing label based on service type (Requirement 5.3)
            function getPricingLabel(serviceType) {
                const labels = {
                    'ride_now': 'Rate',
                    'airport_transfers': 'Transfer Rate',
                    'point_to_point': 'Trip Rate',
                    'corporate': 'Per Day',
                    'day_rental': 'Per Day'
                };
                return labels[serviceType] || 'Rate';
            }

            // Helper function to get duration label based on service type (Requirement 5.4)
            function getDurationLabel(serviceType, days) {
                const fixedRateServices = ['ride_now', 'airport_transfers', 'point_to_point'];
                if (fixedRateServices.includes(serviceType)) {
                    if (serviceType === 'ride_now') return 'Drop/Pickup';
                    if (serviceType === 'airport_transfers') return 'Airport transfer';
                    return 'Trip';
                }
                return days === 1 ? '1 day Package' : `${days} days Package`;
            }

            // Helper function to check if service is fixed-rate
            function isFixedRate(serviceType) {
                return ['ride_now', 'airport_transfers', 'point_to_point'].includes(serviceType);
            }

            let total = 0;
            Object.keys(cart).forEach((key, index) => {
                const item = cart[key];
                const price = parseFloat(item.price || 0);
                const days = parseInt(item.days || 1);
                // Prefer server-calculated total_price when available (ensures return trip totals used)
                const itemTotal = parseFloat((item.total_price !== undefined && item.total_price !== null) ? item.total_price : (price * days));
                total += itemTotal;

                const serviceType = item.service_type || '';
                const pricingLabel = getPricingLabel(serviceType);
                const durationLabel = getDurationLabel(serviceType, days);
                const fixedRate = isFixedRate(serviceType);

                // Build pricing display based on service type
                let pricingHtml = '';

                // If fixed-rate and return trip info available, show outbound/return breakdown (Requirement 5.5)
                if (fixedRate && item.is_return_trip && (item.one_way_price || item.return_price)) {
                    const oneWay = parseFloat(item.one_way_price || 0);
                    const returnPrice = parseFloat(item.return_price || 0);
                    const returnPct = parseFloat(item.return_discount_percentage || 0);

                    pricingHtml = `
                        <div class="return-trip-breakdown text-white-0">
                            <div style="color: #fff; font-size: 13px;">
                                <i class="bi bi-arrow-right-circle"></i> Outbound: <small class="currency-symbol">${cartCurrencySymbol}</small> ${Math.floor(Math.max(0, oneWay)).toLocaleString('en-US', {minimumFractionDigits: 0, maximumFractionDigits: 0})}
                            </div>
                            <div style="color: #fff; font-size: 13px;">
                                <i class="bi bi-arrow-left-circle"></i> Return: ${returnPct > 0 ? '<span class="badge bg-success" style="font-size: 11px; margin-left: 6px;">' + returnPct + '% off</span>' : ''} <small class="currency-symbol">${cartCurrencySymbol}</small> ${Math.floor(Math.max(0, returnPrice)).toLocaleString('en-US', {minimumFractionDigits: 0, maximumFractionDigits: 0})}
                            </div>
                            <div style="margin-top:6px; font-weight:700;">
                                <small class="currency-symbol">${cartCurrencySymbol}</small> ${Math.floor(Math.max(0, itemTotal)).toLocaleString('en-US', {minimumFractionDigits: 0, maximumFractionDigits: 0})}
                            </div>
                        </div>
                    `;
                } else if (fixedRate) {
                    pricingHtml = `<span>${pricingLabel}: <small class="currency-symbol">${cartCurrencySymbol}</small> ${Math.floor(Math.max(0, itemTotal)).toLocaleString('en-US', {minimumFractionDigits: 0, maximumFractionDigits: 0})}</span>`;
                } else {
                    pricingHtml = `
                        <span>${pricingLabel}: <small class="currency-symbol">${cartCurrencySymbol}</small> ${Math.floor(Math.max(0, price)).toLocaleString('en-US', {minimumFractionDigits: 0, maximumFractionDigits: 0})}</span>
                        <strong><small class="currency-symbol">${cartCurrencySymbol}</small> ${Math.floor(Math.max(0, itemTotal)).toLocaleString('en-US', {minimumFractionDigits: 0, maximumFractionDigits: 0})}</strong>
                    `;
                }

                // Render cart item with vehicle name, dates, duration label, pricing, and remove button
                // Requirements: 5.1, 5.2, 5.4, 5.6, 5.7
                $cartItems.append(`
                    <div class="cart-float-item">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div class="flex-grow-1">
                                <strong>${item.vehicle_name || item.name || 'Vehicle Rental'}</strong>
                                <small class="small bg-success py-1 px-2 rounded">${durationLabel}</small>
                                <div class="small">${item.pickup_date || ''} to ${item.return_date || ''}</div>
                            </div>
                            <button type="button" class="btn btn-sm btn-link text-white p-0 ms-2" onclick="removeFromCart('${key}')">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </div>
                        <div class="d-flex justify-content-between">
                            ${pricingHtml}
                        </div>
                    </div>
                `);
            });

            // Use server-calculated subtotal if available, otherwise use client-calculated total as fallback
            const subtotal = cartTotals.subtotal || total;
            const finalTotal = cartTotals.total || total;

            // Update subtotal (base amount before additional charges)
            $('#cartSubtotalPrice .amount').text(subtotal.toLocaleString('en-US', {
                minimumFractionDigits: 0,
                maximumFractionDigits: 0
            }));

            // Update breakdown items - show/hide based on value (Requirements 1.6, 1.7, 1.8, 1.9, 1.10)
            const updateBreakdownItem = (selector, value) => {
                if (value && parseFloat(value) > 0) {
                    $(selector + ' .amount').text(parseFloat(value).toLocaleString('en-US', {
                        minimumFractionDigits: 0,
                        maximumFractionDigits: 0
                    }));
                    $(selector).show();
                } else {
                    $(selector).hide();
                }
            };

            updateBreakdownItem('.cart-addon-charges', cartTotals.addon_charges || 0);
            updateBreakdownItem('.cart-extra-km-charges', cartTotals.extra_km_charges || 0);
            updateBreakdownItem('.cart-service-fee', cartTotals.service_fee || 0);
            updateBreakdownItem('.cart-tax', cartTotals.tax || 0);
            updateBreakdownItem('.cart-vat', cartTotals.vat || 0);

            // Update final total (should be subtotal + all charges)
            $('#cartTotalPrice .amount').text(finalTotal.toLocaleString('en-US', {
                minimumFractionDigits: 0,
                maximumFractionDigits: 0
            }));
        }

        /**
         * Show the cart summary float with fade animation
         */
        function showCartFloat() {
            $('#cartSummaryFloat').fadeIn();
        }

        /**
         * Hide the cart summary float with fade animation
         */
        function hideCartFloat() {
            $('#cartSummaryFloat').fadeOut();
        }

        /**
         * Remove an item from the cart
         * Makes AJAX POST to cart.remove route and updates display
         * Requirement: 5.8
         * @param {string} cartKey - The unique key identifying the cart item
         */
        function removeFromCart(cartKey) {
            $.ajax({
                url: '{{ route('cart.remove') }}',
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
                        showErrorNotification('Error: ' + response.message);
                    }
                },
                error: function(xhr) {
                    console.error('Error removing from cart:', xhr);
                    let errorMessage = 'Error removing item. Please try again.';
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        errorMessage = xhr.responseJSON.message;
                    }
                    showErrorNotification(errorMessage);
                }
            });
        }

        /**
         * Show a success notification toast
         * @param {string} message - The message to display
         */
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

        /**
         * Show an error notification toast
         * @param {string} message - The error message to display
         */
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
    }

    // Initialize cart float on document ready
    $(document).ready(function() {
        // Load cart from server on page load
        if (typeof loadCart === 'function') {
            loadCart();
        }

        // Close cart float button handler
        $('#closeCartFloat').on('click', function() {
            $('#cartSummaryFloat').fadeOut();
        });
    });
</script>
@endpush
@endonce
