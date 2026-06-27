                            <div class="checkout-form-wrapper">
                                <div class="checkout-form-title">
                                    <div class="checkout-summary-heading">
                                        <div>
                                            <h4>Order Summary</h4>
                                            <span>{{ count($cart) }} {{ count($cart) === 1 ? 'vehicle' : 'vehicles' }}</span>
                                        </div>
                                        <div class="checkout-heading-total">
                                            <small>Total</small>
                                            <strong data-summary-field="total">{{ $currencySymbol }}
                                                {{ number_format(floor(max(0, $total)), 0) }}</strong>
                                        </div>
                                    </div>
                                    <button type="button" class="checkout-clear-cart-btn" title="Clear cart">
                                        <i class="bi bi-trash"></i>
                                        <span>Clear</span>
                                    </button>
                                </div>
                                <div class="order-sum-area">
                                    <div class="cart-menu">
                                        <div class="cart-body">
                                            @include('checkout.partials.cart-items')
                                        </div>

                                        <div class="cart-footer">
                                            <div class="pricing-area mb-40">
                                                <div class="checkout-pricing-heading">
                                                    <span>Price details</span>
                                                    <small>All charges shown below</small>
                                                </div>
                                                <ul>
                                                    <li>
                                                        <strong>Subtotal</strong>
                                                        <strong class="checkout-summary-amount" data-summary-field="subtotal">{{ $currencySymbol }}
                                                            {{ number_format(floor(max(0, $subtotal)), 0) }}</strong>
                                                    </li>
                                                    @php
                                                        $addonCharges = $totals['addon_charges'] ?? 0;
                                                        $extraKmCharges = $totals['extra_km_charges'] ?? 0;
                                                    @endphp
                                                    <li data-summary-row="addon_charges" style="{{ $addonCharges > 0 ? '' : 'display: none;' }}">
                                                        Addon Charges
                                                        <div class="order-info text-success">
                                                            <span class="checkout-summary-amount" data-summary-field="addon_charges">{{ $currencySymbol }}
                                                                {{ number_format(floor(max(0, $addonCharges)), 0) }}</span>
                                                        </div>
                                                    </li>
                                                    <li data-summary-row="extra_km_charges" style="{{ $extraKmCharges > 0 ? '' : 'display: none;' }}">
                                                        Extra KM Charges
                                                        <div class="order-info text-info">
                                                            <span class="checkout-summary-amount" data-summary-field="extra_km_charges">{{ $currencySymbol }}
                                                                {{ number_format(floor(max(0, $extraKmCharges)), 0) }}</span>
                                                        </div>
                                                    </li>
                                                    @if ($serviceFee > 0)
                                                        <li data-summary-row="service_fee">
                                                            Service Fee
                                                            <div class="order-info">
                                                                <span class="checkout-summary-amount" data-summary-field="service_fee">{{ $currencySymbol }}
                                                                    {{ number_format(floor(max(0, $serviceFee)), 0) }}</span>
                                                            </div>
                                                        </li>
                                                    @endif
                                                    @if ($tax > 0)
                                                        <li data-summary-row="tax">
                                                            {{ $taxLabel }}
                                                            ({{ $taxPercentageLabel }}%)
                                                            <div class="order-info">
                                                                <span class="checkout-summary-amount" data-summary-field="tax">{{ $currencySymbol }}
                                                                    {{ number_format(floor(max(0, $tax)), 0) }}</span>
                                                            </div>
                                                        </li>
                                                    @endif
                                                    @if ($vatPercentage > 0 && $vat > 0)
                                                        <li data-summary-row="vat">
                                                            {{ $vatLabel }}
                                                            ({{ $vatPercentageLabel }}%)
                                                            <div class="order-info">
                                                                <span class="checkout-summary-amount" data-summary-field="vat">{{ $currencySymbol }}
                                                                    {{ number_format(floor(max(0, $vat)), 0) }}</span>
                                                            </div>
                                                        </li>
                                                    @endif

                                                    {{-- Promo Code Section --}}
                                                    <li class="promo-code-checkout-section">
                                                        <div class="promo-code-checkout-wrapper">
                                                            <div class="promo-code-header">
                                                                <i class="bi bi-tag"></i>
                                                                <span>Promo Code</span>
                                                            </div>
                                                            @php
                                                                $appliedPromoCode = $cartData['coupon_code'] ?? null;
                                                                $promoDiscount = $cartData['coupon_discount'] ?? 0;
                                                            @endphp
                                                            <div id="checkout-promo-body">
                                                                @if ($appliedPromoCode)
                                                                    {{-- Promo code is applied --}}
                                                                    <div class="applied-promo-checkout">
                                                                        <div class="promo-badge-checkout">
                                                                            <i
                                                                                class="bi bi-check-circle-fill text-success"></i>
                                                                            <span
                                                                                class="promo-code-value">{{ $appliedPromoCode }}</span>
                                                                            <button type="button"
                                                                                class="remove-promo-checkout-btn"
                                                                                title="Remove promo code">
                                                                                <i class="bi bi-x-lg"></i>
                                                                            </button>
                                                                        </div>
                                                                    </div>
                                                                @else
                                                                    {{-- No promo code - show input --}}
                                                                    <div class="promo-input-checkout">
                                                                        <input type="text" id="checkout-promo-input"
                                                                            placeholder="Enter code" autocomplete="off">
                                                                        <button type="button" id="apply-promo-checkout-btn"
                                                                            class="apply-promo-checkout-btn">
                                                                            <span class="btn-text">Apply</span>
                                                                            <span class="btn-loading"
                                                                                style="display: none;"><i
                                                                                    class="bi bi-hourglass-split"></i></span>
                                                                        </button>
                                                                    </div>
                                                                @endif
                                                            </div>
                                                            <div id="checkout-promo-message"
                                                                class="promo-message-checkout"></div>
                                                        </div>
                                                    </li>

                                                    @php
                                                        $priceAdjustmentDiscount =
                                                            $totals['price_adjustment_discount'] ?? 0;
                                                    @endphp
                                                    @if ($priceAdjustmentDiscount > 0)
                                                        <li class="price-adjustment-discount-row" data-summary-row="price_adjustment_discount">
                                                            <span class="text-success">
                                                                <i class="bi bi-percent"></i> Price Adjustment Discount
                                                            </span>
                                                            <div class="order-info text-success">
                                                                <span class="checkout-summary-amount" data-summary-field="price_adjustment_discount">-{{ $currencySymbol }}
                                                                    {{ number_format(floor(max(0, $priceAdjustmentDiscount)), 0) }}</span>
                                                            </div>
                                                        </li>
                                                    @endif
                                                    @if ($discount > 0)
                                                        <li class="discount-checkout-row" data-summary-row="coupon_discount">
                                                            <strong class="text-success"><i class="bi bi-tag-fill"></i>
                                                                Discount</strong>
                                                            <div class="order-info text-success">
                                                                <span class="checkout-summary-amount" data-summary-field="coupon_discount">-{{ $currencySymbol }}
                                                                    {{ number_format(floor(max(0, $discount)), 0) }}</span>
                                                            </div>
                                                        </li>
                                                    @endif
                                                    <li class="total-row">
                                                        <strong>Total</strong>
                                                        <strong class="checkout-summary-amount" data-summary-field="total">{{ $currencySymbol }}
                                                            {{ number_format(floor(max(0, $total)), 0) }}</strong>
                                                    </li>
                                                    @if ($paymentType !== 'full')
                                                        <li class="payment-amount-row">
                                                            <strong>
                                                                @if ($paymentType === 'advance')
                                                                    Amount to Pay
                                                                    ({{ $advancePercentage }}%)
                                                                @elseif($paymentType === 'checkin')
                                                                    Pay on Check-in
                                                                @elseif($paymentType === 'quotation')
                                                                    Quotation Request
                                                                @endif
                                                            </strong>
                                                            <strong class="text-primary">
                                                                @if ($paymentType === 'quotation')
                                                                    No Payment Required
                                                                @elseif($paymentType === 'checkin')
                                                                    <span data-summary-field="payment_amount">{{ $currencySymbol }}
                                                                        {{ number_format(floor(max(0, $total)), 0) }}</span>
                                                                @else
                                                                    <span data-summary-field="payment_amount">{{ $currencySymbol }}
                                                                        {{ number_format(floor(max(0, $paymentAmount)), 0) }}</span>
                                                                @endif
                                                            </strong>
                                                        </li>
                                                    @endif
                                                </ul>
                                            </div>

