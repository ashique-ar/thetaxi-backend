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
        width: 380px;
        background: var(--primary-color, #111827);
        border-radius: 18px;
        box-shadow: 0 16px 48px rgba(0, 0, 0, 0.35), 0 0 0 1px rgba(255, 255, 255, 0.08);
        z-index: 1050;
        animation: cartSummaryFloatSlideInUp 0.35s cubic-bezier(0.16, 1, 0.3, 1);
        overflow: hidden;
    }

    .cart-summary-float .cart-float-content {
        background: var(--primary-color, #111827);
    }

    /* Slide-in animation for cart float appearance */
    @keyframes cartSummaryFloatSlideInUp {
        from {
            transform: translateY(80px);
            opacity: 0;
        }
        to {
            transform: translateY(0);
            opacity: 1;
        }
    }

    /* Cart float header - title, count badge, clear & close actions */
    .cart-summary-float .cart-float-header {
        padding: 14px 18px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.12);
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
        color: white;
    }

    .cart-summary-float .cart-float-title-group {
        display: flex;
        align-items: center;
        gap: 9px;
        min-width: 0;
    }

    .cart-summary-float .cart-float-icon-badge {
        width: 30px;
        height: 30px;
        border-radius: 8px;
        background: rgba(255, 255, 255, 0.14);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 14px;
        flex-shrink: 0;
    }

    .cart-summary-float .cart-float-header h6 {
        margin: 0;
        font-size: 15px;
        font-weight: 700;
        letter-spacing: -0.01em;
        color: white;
        white-space: nowrap;
    }

    .cart-summary-float .cart-count-pill {
        font-size: 11px;
        font-weight: 600;
        line-height: 1;
        padding: 4px 9px;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.14);
        color: white;
        white-space: nowrap;
    }

    .cart-summary-float .cart-float-actions {
        display: flex;
        align-items: center;
        gap: 6px;
        flex-shrink: 0;
    }

    .cart-summary-float #clearCartFloat {
        padding: 4px 8px;
        font-size: 12px;
        font-weight: 500;
        color: rgba(255, 255, 255, 0.78);
        background: transparent;
        border: none;
        border-radius: 6px;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        transition: color 0.15s ease, background 0.15s ease;
    }

    .cart-summary-float #clearCartFloat:hover {
        color: #fff;
        background: rgba(255, 255, 255, 0.1);
    }

    .cart-summary-float .cart-close-btn {
        width: 28px;
        height: 28px;
        padding: 0;
        border: none;
        border-radius: 7px;
        background: transparent;
        color: rgba(255, 255, 255, 0.78);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 14px;
        transition: color 0.15s ease, background 0.15s ease;
    }

    .cart-summary-float .cart-close-btn:hover {
        color: #fff;
        background: rgba(255, 255, 255, 0.1);
    }

    /* Cart float body - scrollable items container */
    .cart-summary-float .cart-float-body {
        padding: 12px 14px;
        max-height: 320px;
        overflow-y: auto;
        display: flex;
        flex-direction: column;
        gap: 10px;
        color: white;
        background: var(--primary-color, #111827);
        scrollbar-width: thin;
        scrollbar-color: rgba(255, 255, 255, 0.2) transparent;
    }

    .cart-summary-float .cart-float-body::-webkit-scrollbar {
        width: 5px;
    }

    .cart-summary-float .cart-float-body::-webkit-scrollbar-thumb {
        background: rgba(255, 255, 255, 0.2);
        border-radius: 999px;
    }

    /* Individual cart item card */
    .cart-summary-float .cart-float-item {
        padding: 12px;
        border-radius: 12px;
        background: rgba(255, 255, 255, 0.06);
        border: 1px solid rgba(255, 255, 255, 0.1);
        font-size: 12.5px;
        line-height: 1.4;
        transition: background 0.15s ease, border-color 0.15s ease;
    }

    .cart-summary-float .cart-float-item:hover {
        background: rgba(255, 255, 255, 0.09);
        border-color: rgba(255, 255, 255, 0.18);
    }

    .cart-summary-float .cart-item-top {
        display: flex;
        align-items: flex-start;
        gap: 10px;
    }

    .cart-summary-float .cart-item-thumb {
        width: 54px;
        height: 40px;
        border-radius: 8px;
        object-fit: cover;
        flex-shrink: 0;
        background: rgba(255, 255, 255, 0.08);
        border: 1px solid rgba(255, 255, 255, 0.1);
    }

    .cart-summary-float .cart-item-thumb-placeholder {
        width: 54px;
        height: 40px;
        border-radius: 8px;
        flex-shrink: 0;
        background: rgba(255, 255, 255, 0.08);
        border: 1px solid rgba(255, 255, 255, 0.1);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 16px;
        color: rgba(255, 255, 255, 0.6);
    }

    .cart-summary-float .cart-item-info {
        flex: 1;
        min-width: 0;
    }

    .cart-summary-float .cart-item-title {
        display: block;
        font-size: 14px;
        font-weight: 700;
        line-height: 1.3;
        color: #fff;
        margin-bottom: 3px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .cart-summary-float .cart-item-dates {
        font-size: 11.5px;
        line-height: 1.35;
        color: rgba(255, 255, 255, 0.68);
        display: flex;
        align-items: center;
        gap: 5px;
    }

    .cart-summary-float .cart-item-remove {
        width: 24px;
        height: 24px;
        padding: 0;
        border: none;
        border-radius: 6px;
        background: transparent;
        color: rgba(255, 255, 255, 0.55);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 12px;
        flex-shrink: 0;
        transition: color 0.15s ease, background 0.15s ease;
    }

    .cart-summary-float .cart-item-remove:hover {
        color: #fff;
        background: rgba(255, 255, 255, 0.12);
    }

    .cart-summary-float .cart-item-bottom {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        margin-top: 10px;
        padding-top: 9px;
        border-top: 1px solid rgba(255, 255, 255, 0.08);
    }

    .cart-summary-float .cart-item-meta-left {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
        min-width: 0;
    }

    .cart-summary-float .cart-item-badge {
        font-size: 11px;
        font-weight: 600;
        line-height: 1.2;
        padding: 3px 8px;
        border-radius: 6px;
        white-space: nowrap;
    }

    .cart-summary-float .cart-item-rate-muted {
        font-size: 11.5px;
        color: rgba(255, 255, 255, 0.72);
        white-space: nowrap;
    }

    .cart-summary-float .cart-item-price {
        font-size: 14.5px;
        font-weight: 700;
        line-height: 1.2;
        color: #fff;
        white-space: nowrap;
        font-variant-numeric: tabular-nums;
    }

    /* Cart float footer - totals and checkout button */
    .cart-summary-float .cart-float-footer {
        padding: 14px 18px 16px;
        border-top: 1px solid rgba(255, 255, 255, 0.12);
        background: var(--primary-color, #111827);
        font-size: 12.5px;
    }

    /* Cart breakdown rows (subtotal, charges, taxes) */
    .cart-summary-float .cart-breakdown > div {
        display: flex;
        justify-content: space-between;
        align-items: center;
        color: white;
        font-size: 12.5px;
        line-height: 1.4;
        margin-bottom: 6px;
    }

    .cart-summary-float .cart-breakdown > div span:first-child {
        color: rgba(255, 255, 255, 0.74);
    }

    .cart-summary-float .cart-breakdown > div span:last-child {
        font-weight: 600;
        font-variant-numeric: tabular-nums;
    }

    /* Cart total row styling */
    .cart-summary-float .cart-total {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        color: white;
        border-top: 1px solid rgba(255, 255, 255, 0.12);
        padding-top: 12px;
        margin-top: 10px;
        margin-bottom: 12px !important;
    }

    .cart-summary-float .cart-total-overline {
        display: block;
        font-size: 10.5px;
        font-weight: 600;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        color: rgba(255, 255, 255, 0.65);
        margin-bottom: 2px;
    }

    .cart-summary-float .cart-total strong#cartTotalPrice {
        display: block;
        font-size: 22px;
        font-weight: 800;
        line-height: 1.15;
        letter-spacing: -0.02em;
        font-variant-numeric: tabular-nums;
    }

    /* Currency symbol styling within cart float */
    .cart-summary-float .currency-symbol {
        font-size: 0.78em;
        font-weight: 500 !important;
        opacity: 0.9;
        margin-right: 0.15rem;
    }

    /* Primary checkout button */
    .cart-summary-float .cart-checkout-btn {
        padding: 11px 16px;
        border-radius: 10px;
        font-size: 14px;
        font-weight: 700;
        line-height: 1.25;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        transition: transform 0.15s ease, box-shadow 0.15s ease;
    }

    .cart-summary-float .cart-checkout-btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 6px 16px rgba(0, 0, 0, 0.22);
    }

    .cart-summary-float .cart-float-meta {
        margin-top: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 14px;
        flex-wrap: wrap;
        font-size: 11px;
        line-height: 1.3;
        color: rgba(255, 255, 255, 0.7);
    }

    .cart-summary-float .cart-float-meta span {
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }

    /* ==================== RESPONSIVE STYLES ==================== */
    
    /* Tablet breakpoint */
    @media (max-width: 991px) {
        .cart-summary-float {
            width: 340px;
        }
    }

    /* Mobile breakpoint - full width with margins */
    @media (max-width: 767px) {
        .cart-summary-float {
            width: calc(100% - 32px);
            right: 16px;
            left: 16px;
            bottom: 16px;
        }

        .cart-summary-float .cart-float-body {
            max-height: min(38dvh, 280px);
        }

        .cart-summary-float .cart-total strong#cartTotalPrice {
            font-size: 20px;
        }
    }
</style>
@endpush
@endonce

<!-- Cart Summary Float (Hidden by default, shown when items added) -->
<div id="cartSummaryFloat" class="cart-summary-float {{ theme_class('cart-summary-float') }}" style="display: none;">
    <div class="cart-float-content">
        {{-- Cart Header with icon, count badge, clear and close buttons --}}
        <div class="cart-float-header">
            <div class="cart-float-title-group">
                <span class="cart-float-icon-badge" aria-hidden="true">
                    <i class="bi bi-bag-fill"></i>
                </span>
                <h6>Rental Cart</h6>
                <span class="cart-count-pill" id="cartFloatCountBadge">0 Vehicles</span>
            </div>
            <div class="cart-float-actions">
                <button type="button" id="clearCartFloat" title="Clear all items">
                    <i class="bi bi-trash3"></i>
                    <span>Clear all</span>
                </button>
                <button type="button" class="cart-close-btn" id="closeCartFloat" aria-label="Close cart">
                    <i class="bi bi-chevron-down"></i>
                </button>
            </div>
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
                <div class="cart-total">
                    <div>
                        <span class="cart-total-overline">Total Amount</span>
                        <strong id="cartTotalPrice">
                            <span class="currency-symbol">{{ $currencySymbol }}</span>
                            <span class="amount">0</span>
                        </strong>
                    </div>
                </div>
            </div>
            
            {{-- Checkout button (Requirement 1.12) --}}
            <a href="{{ $cartPageRoute }}" class="btn btn-light w-100 cart-checkout-btn">
                <i class="bi bi-lock-fill"></i>
                <span>Review &amp; Checkout <span id="cartCheckoutCount"></span></span>
                <i class="bi bi-arrow-right"></i>
            </a>

            {{-- Helper / trust meta --}}
            <div class="cart-float-meta">
                <span><i class="bi bi-check2"></i> Instant reservation</span>
                <span><i class="bi bi-sliders2"></i> Extras &amp; extra km at checkout</span>
            </div>
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
                        cartCurrency = response.currency || cartTotals.currency || '{{ getSelectedCurrency() }}';
                        cartCurrencySymbol = response.currency_symbol || cartTotals.currency_symbol || '{{ getCurrencySymbol() }}';
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
            $('#cartSummaryFloat .currency-symbol').text(cartCurrencySymbol);

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
            const keys = Object.keys(cart);
            const itemCount = keys.length;

            $('#cartFloatCountBadge').text(itemCount === 1 ? '1 Vehicle' : `${itemCount} Vehicles`);
            $('#cartCheckoutCount').text(itemCount > 0 ? `(${itemCount})` : '');

            const formatAmount = (val) => Math.floor(Math.max(0, val)).toLocaleString('en-US', {
                minimumFractionDigits: 0,
                maximumFractionDigits: 0
            });

            const resolveImageUrl = (img) => {
                if (!img || typeof img !== 'string') return '';
                if (img.startsWith('http://') || img.startsWith('https://') || img.startsWith('/')) return img;
                return `/storage/${img.replace(/^\/+/, '')}`;
            };

            keys.forEach((key) => {
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
                const allowedKm = item.distance_details && item.distance_details.allowed_total_km
                    ? Math.round(parseFloat(item.distance_details.allowed_total_km))
                    : null;
                const badgeText = allowedKm ? `${allowedKm.toLocaleString('en-US')} km included` : durationLabel;
                const imageUrl = resolveImageUrl(item.image || item.vehicle_image || '');
                const dateText = item.pickup_date && item.return_date
                    ? `${item.pickup_date} – ${item.return_date}${!fixedRate ? ` · ${days} ${days === 1 ? 'Day' : 'Days'}` : ''}`
                    : (item.pickup_date || durationLabel);

                const thumbHtml = imageUrl
                    ? `<img src="${imageUrl}" alt="${item.vehicle_name || item.name || 'Vehicle'}" class="cart-item-thumb" onerror="this.style.display='none';this.nextElementSibling.style.display='inline-flex';"><span class="cart-item-thumb-placeholder" style="display:none;"><i class="bi bi-car-front-fill"></i></span>`
                    : `<span class="cart-item-thumb-placeholder"><i class="bi bi-car-front-fill"></i></span>`;

                // Build pricing display based on service type
                let bottomHtml = '';

                // If fixed-rate and return trip info available, show outbound/return breakdown (Requirement 5.5)
                if (fixedRate && item.is_return_trip && (item.one_way_price || item.return_price)) {
                    const oneWay = parseFloat(item.one_way_price || 0);
                    const returnPrice = parseFloat(item.return_price || 0);
                    const returnPct = parseFloat(item.return_discount_percentage || 0);

                    bottomHtml = `
                        <div class="cart-item-bottom" style="align-items: flex-end;">
                            <div class="return-trip-breakdown" style="font-size: 11.5px; line-height: 1.45; color: rgba(255,255,255,0.85);">
                                <div>
                                    <i class="bi bi-arrow-right-circle"></i> Outbound: <small class="currency-symbol">${cartCurrencySymbol}</small>${formatAmount(oneWay)}
                                </div>
                                <div>
                                    <i class="bi bi-arrow-left-circle"></i> Return: ${returnPct > 0 ? '<span class="badge bg-success" style="font-size: 10px; margin: 0 4px;">' + returnPct + '% off</span>' : ''} <small class="currency-symbol">${cartCurrencySymbol}</small>${formatAmount(returnPrice)}
                                </div>
                            </div>
                            <span class="cart-item-price"><small class="currency-symbol">${cartCurrencySymbol}</small>${formatAmount(itemTotal)}</span>
                        </div>
                    `;
                } else if (fixedRate) {
                    bottomHtml = `
                        <div class="cart-item-bottom">
                            <div class="cart-item-meta-left">
                                <span class="cart-item-badge bg-success text-white">${badgeText}</span>
                                <span class="cart-item-rate-muted">${pricingLabel}</span>
                            </div>
                            <span class="cart-item-price"><small class="currency-symbol">${cartCurrencySymbol}</small>${formatAmount(itemTotal)}</span>
                        </div>
                    `;
                } else {
                    bottomHtml = `
                        <div class="cart-item-bottom">
                            <div class="cart-item-meta-left">
                                <span class="cart-item-badge bg-success text-white">${badgeText}</span>
                                <span class="cart-item-rate-muted"><small class="currency-symbol">${cartCurrencySymbol}</small>${formatAmount(price)} × ${days}d</span>
                            </div>
                            <span class="cart-item-price"><small class="currency-symbol">${cartCurrencySymbol}</small>${formatAmount(itemTotal)}</span>
                        </div>
                    `;
                }

                // Render cart item card with thumbnail, vehicle name, dates, badge, pricing, and remove button
                // Requirements: 5.1, 5.2, 5.4, 5.6, 5.7
                $cartItems.append(`
                    <div class="cart-float-item">
                        <div class="cart-item-top">
                            ${thumbHtml}
                            <div class="cart-item-info">
                                <strong class="cart-item-title">${item.vehicle_name || item.name || 'Vehicle Rental'}</strong>
                                <div class="cart-item-dates">
                                    <i class="bi bi-calendar3"></i>
                                    <span>${dateText}</span>
                                </div>
                            </div>
                            <button type="button" class="cart-item-remove" onclick="removeFromCart('${key}')" aria-label="Remove item">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </div>
                        ${bottomHtml}
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

        function clearCart() {
            if (!confirm('Clear all items from your cart?')) return;

            $.ajax({
                url: '{{ route('cart.clear') }}',
                method: 'POST',
                data: { _token: '{{ csrf_token() }}' },
                success: function(response) {
                    if (response.success) {
                        cart = [];
                        cartTotals = response.cart?.totals || {};
                        updateCartDisplay();
                        hideCartFloat();
                        window.dispatchEvent(new CustomEvent('cartUpdated'));
                    } else {
                        showErrorNotification('Error: ' + response.message);
                    }
                },
                error: function(xhr) {
                    showErrorNotification(xhr.responseJSON?.message || 'Error clearing cart. Please try again.');
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
            setTimeout(() => alert.remove(), 4000);
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
            setTimeout(() => alert.remove(), 5000);
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
        $('#clearCartFloat').on('click', clearCart);
    });
</script>
@endpush
@endonce
