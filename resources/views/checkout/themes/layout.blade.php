@include('checkout.themes.' . $checkoutDesign . '-header')
<div class="checkout-page {{ theme_class('checkout-workflow') }} reservation-page reservation-page--{{ $checkoutDesign }}" id="checkoutContentStart">
    <div class="reservation-page__inner">
        @if (empty($cart))
            <div class="alert alert-warning text-center" role="status">
                <h2>Your cart is empty!</h2>
                <p>Please add some vehicles to your cart before proceeding to checkout.</p>
                <a href="{{ route('search') }}" class="reservation-action">Browse Vehicles</a>
            </div>
        @else
            @php
                $canBookAsNewCustomer = $guestBookingEnabled || Auth::check();
                $showCheckoutDetails = (!$customerLookupEnabled && $canBookAsNewCustomer)
                    || !empty($verifiedCheckoutCustomer) || !empty($tourCheckoutCustomer) || old('email') || old('first_name');
                $hideCheckoutDetails = !$showCheckoutDetails || ($customerLookupEnabled && !$verifiedCheckoutCustomer && !old('first_name') && !old('email'));
                $canShowCheckout = $canBookAsNewCustomer || Auth::check() || $customerLookupEnabled;
            @endphp
            <div class="reservation-layout">
                <div class="reservation-layout__customer">
                    @include('checkout.themes.customer')
                    @if ($canShowCheckout)
                        <div id="checkout-details-layout" @if ($hideCheckoutDetails) hidden @endif>
                            <form id="checkout-form" method="POST" action="{{ route('checkout.process') }}">
                                @csrf
                                @if ($errors->any() || session('error'))
                                    <div class="alert alert-danger" role="alert">
                                        @if (session('error')) <div>{{ session('error') }}</div> @endif
                                        @foreach ($errors->all() as $message) <div>{{ $message }}</div> @endforeach
                                    </div>
                                @endif
                                @include('checkout.themes.billing')
                            </form>
                        </div>
                    @elseif (!$customerLookupEnabled)
                        <div class="alert alert-info" role="status">Guest bookings are currently unavailable. Please sign in to continue.</div>
                    @endif
                </div>
                <aside class="reservation-layout__summary" aria-label="Order summary and payment">
                    @include('checkout.themes.order-summary')
                    @if ($canShowCheckout)
                        <div id="checkout-payment-layout" @if ($hideCheckoutDetails) hidden @endif>
                            @include('checkout.themes.payment-options')
                                            <div class="alert alert-warning mb-3" id="payment-contact-requirement" role="status" hidden>
                                                Enter both a valid email address and mobile number to continue checkout.
                                            </div>

                                            <!-- Payment Type Alert -->
                                            <div class="alert alert-info mb-4" id="payment-type-alert">
                                                <div id="alert-content">
                                                    @switch($paymentType)
                                                        @case('advance')
                                                            <h6><i class="bi bi-info-circle"></i> Advance Payment
                                                                (<span data-advance-percentage>{{ $advanceActualPercentage }}</span>%)</h6>
                                                            <p class="mb-0">You are paying <span data-advance-percentage>{{ $advanceActualPercentage }}</span>%
                                                                advance. The remaining amount will be collected at the time
                                                                of vehicle pickup.</p>
                                                        @break

                                                        @case('checkin')
                                                            <h6><i class="bi bi-cash-coin"></i> {{ $tourOnlyCheckout ? 'Pay later' : 'Pay on Check-in' }}</h6>
                                                            <p class="mb-0">{{ $tourOnlyCheckout ? 'No payment is required now. The full amount is due according to your Tour booking terms.' : 'No payment is required now. You will pay the full amount when you check-in to collect the vehicle.' }}</p>
                                                        @break

                                                        @case('quotation')
                                                            <h6><i class="bi bi-file-text"></i> Request Quotation</h6>
                                                            <p class="mb-0">You are requesting a quotation. Our team will
                                                                contact you with detailed pricing and booking information.</p>
                                                        @break

                                                        @default
                                                            <h6><i class="bi bi-credit-card"></i> Full Payment</h6>
                                                            <p class="mb-0">You are making full payment for your booking.</p>
                                                    @endswitch
                                                </div>
                                            </div>

                                            <!-- Hidden payment method field - set automatically based on payment type -->
                                            <input type="hidden" name="payment_method" id="payment_method_field"
                                                value="" form="checkout-form">

                                            <button type="submit" class="reservation-submit" id="checkout-submit-btn"
                                                form="checkout-form">
                                                <span>
                                                    @if ($paymentType === 'quotation')
                                                        Submit Quotation Request
                                                    @elseif ($paymentType === 'checkin')
                                                        Confirm Booking - {{ $tourOnlyCheckout ? 'Pay Later' : 'Pay on Check-in' }}
                                                    @else
                                                        Complete Booking -
                                                        <small class="currency-symbol">{{ $currencySymbol }}</small>
                                                        {{ number_format(floor(max(0, $paymentAmount)), 0) }}
                                                    @endif
                                                    <svg width="10" height="10" viewBox="0 0 10 10"
                                                        xmlns="http://www.w3.org/2000/svg">
                                                        <path
                                                            d="M9.73535 1.14746C9.57033 1.97255 9.32924 3.26406 9.24902 4.66797C9.16817 6.08312 9.25559 7.5453 9.70214 8.73633C9.84754 9.12406 9.65129 9.55659 9.26367 9.70215C8.9001 9.83849 8.4969 9.67455 8.32812 9.33398L8.29785 9.26367L8.19921 8.98438C7.73487 7.5758 7.67054 5.98959 7.75097 4.58203C7.77875 4.09598 7.82525 3.62422 7.87988 3.17969L1.53027 9.53027C1.23738 9.82317 0.762615 9.82317 0.469722 9.53027C0.176829 9.23738 0.176829 8.76262 0.469722 8.46973L6.83593 2.10254C6.3319 2.16472 5.79596 2.21841 5.25 2.24902C3.8302 2.32862 2.2474 2.26906 0.958003 1.79102L0.704097 1.68945L0.635738 1.65527C0.303274 1.47099 0.157578 1.06102 0.310542 0.704102C0.463655 0.347333 0.860941 0.170391 1.22363 0.28418L1.29589 0.310547L1.48828 0.387695C2.47399 0.751207 3.79966 0.827571 5.16601 0.750977C6.60111 0.670504 7.97842 0.428235 8.86132 0.262695L9.95312 0.0585938L9.73535 1.14746Z">
                                                        </path>
                                                    </svg>
                                                </span>
                                            </button>
                        </div>
                    @endif
                </aside>
            </div>
        @endif
    </div>
</div>
