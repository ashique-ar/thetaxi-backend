@extends('layouts.app')

@section('title', 'Checkout - TheTaxi')

@section('content')
<!-- Breadcrumb section -->
<div class="breadcrumb-section" style="background-image:linear-gradient(rgba(0, 0, 0, 0.3), rgba(0, 0, 0, 0.3)), url({{ asset('assets/img/innerpages/breadcrumb-bg1.jpg') }});">  
    <div class="container">
        <div class="banner-content">
            <h1>Checkout</h1>
            <ul class="breadcrumb-list">
                <li><a href="{{ route('home') }}">Home</a></li>
                <li><a href="{{ route('cart') }}">Cart</a></li>
                <li>Checkout</li>
            </ul>
        </div>
    </div>
</div>
<!-- End Breadcrumb section -->

@php
    // Cart data is passed from controller
    $cart = $cartData['items'] ?? [];
    $totals = $cartData['totals'] ?? [];
    $currencySymbol = $cartData['currency_symbol'] ?? getCurrencySymbol();
    
    $paymentType = request()->get('type', 'full');
    $subtotal = $totals['subtotal'] ?? 0;
    $serviceFee = $totals['service_fee'] ?? 0;
    $tax = $totals['tax'] ?? 0;
    $taxLabel = $totals['tax_label'] ?? 'NBT';
    $vat = $totals['vat'] ?? 0;
    $vatLabel = $totals['vat_label'] ?? 'VAT';
    $discount = $totals['coupon_discount'] ?? 0;
    $total = $totals['total'] ?? 0;
    
    // Payment amount based on type
    $advancePercentage = config('booking.advance_payment.percentage', 50);
    $paymentAmount = match($paymentType) {
        'advance' => $total * ($advancePercentage / 100),
        'quotation' => 0,
        default => $total
    };
@endphp

<!-- Checkout Page Start-->
<div class="checkout-page pt-100 mb-100">
    <div class="container">
        @if(empty($cart))
            <div class="alert alert-warning text-center">
                <h4>Your cart is empty!</h4>
                <p>Please add some vehicles to your cart before proceeding to checkout.</p>
                <a href="{{ route('search') }}" class="primary-btn1 mt-3">Browse Vehicles</a>
            </div>
        @else
        <form id="checkout-form" method="POST" action="{{ route('checkout.process') }}">
            @csrf
            
            <!-- Payment Type Selection Section -->
            <div class="payment-type-selection mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-credit-card"></i> Choose Payment Option</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <div class="payment-option">
                                    <input type="radio" name="payment_type" value="full" id="payment_full" 
                                           {{ $paymentType === 'full' ? 'checked' : '' }} class="payment-radio">
                                    <label for="payment_full" class="payment-label">
                                        <div class="payment-card">
                                            <i class="bi bi-credit-card-fill text-success"></i>
                                            <h6>Pay Full Amount</h6>
                                            <p class="mb-0">Complete payment now</p>
                                            <small class="text-muted">Total: {{ $currencySymbol }}{{ number_format($total, 2) }}</small>
                                        </div>
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="payment-option">
                                    <input type="radio" name="payment_type" value="advance" id="payment_advance" 
                                           {{ $paymentType === 'advance' ? 'checked' : '' }} class="payment-radio">
                                    <label for="payment_advance" class="payment-label">
                                        <div class="payment-card">
                                            <i class="bi bi-credit-card text-warning"></i>
                                            <h6>Pay {{ config('booking.advance_payment.percentage', 50) }}% Advance</h6>
                                            <p class="mb-0">Pay remaining on pickup</p>
                                            <small class="text-muted">Now: {{ $currencySymbol }}{{ number_format($total * (config('booking.advance_payment.percentage', 50) / 100), 2) }}</small>
                                        </div>
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="payment-option">
                                    <input type="radio" name="payment_type" value="quotation" id="payment_quotation" 
                                           {{ $paymentType === 'quotation' ? 'checked' : '' }} class="payment-radio">
                                    <label for="payment_quotation" class="payment-label">
                                        <div class="payment-card">
                                            <i class="bi bi-file-text text-info"></i>
                                            <h6>Request Quotation</h6>
                                            <p class="mb-0">Get detailed pricing</p>
                                            <small class="text-muted">No payment now</small>
                                        </div>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Payment Type Alert -->
            <div class="alert alert-info mb-4" id="payment-type-alert">
                <div id="alert-content">
                    @switch($paymentType)
                        @case('advance')
                            <h6><i class="bi bi-info-circle"></i> Advance Payment (50%)</h6>
                            <p class="mb-0">You are paying 50% advance. The remaining amount will be collected at the time of vehicle pickup.</p>
                            @break
                        @case('quotation')
                            <h6><i class="bi bi-file-text"></i> Request Quotation</h6>
                            <p class="mb-0">You are requesting a quotation. Our team will contact you with detailed pricing and booking information.</p>
                            @break
                        @default
                            <h6><i class="bi bi-credit-card"></i> Full Payment</h6>
                            <p class="mb-0">You are making full payment for your vehicle rental booking.</p>
                    @endswitch
                </div>
            </div>

            <div class="row g-lg-4 gy-5">
                <div class="col-lg-7">
                    <div class="checkout-form-wrapper">
                        <div class="checkout-form-title">
                            <h4>Billing Information</h4>
                        </div>
                        <div class="checkout-form">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-inner two mb-25">
                                        <label>Full Name*</label>
                                        <input type="text" name="full_name" placeholder="Enter your full name" required 
                                               value="{{ old('full_name') }}">
                                        @error('full_name')
                                            <span class="text-danger">{{ $message }}</span>
                                        @enderror
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-inner two mb-25">
                                        <label>Phone Number*</label>
                                        <input type="tel" name="phone" placeholder="Enter phone number" required 
                                               value="{{ old('phone') }}">
                                        @error('phone')
                                            <span class="text-danger">{{ $message }}</span>
                                        @enderror
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-inner two mb-25">
                                        <label>Email Address*</label>
                                        <input type="email" name="email" placeholder="Enter email address" required 
                                               value="{{ old('email') }}">
                                        @error('email')
                                            <span class="text-danger">{{ $message }}</span>
                                        @enderror
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-inner two mb-25">
                                        <label>National ID / Passport*</label>
                                        <input type="text" name="identification" placeholder="ID/Passport number" required 
                                               value="{{ old('identification') }}">
                                        @error('identification')
                                            <span class="text-danger">{{ $message }}</span>
                                        @enderror
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-inner two mb-25">
                                        <label>Street Address*</label>
                                        <input type="text" name="address" placeholder="Enter street address" required 
                                               value="{{ old('address') }}">
                                        @error('address')
                                            <span class="text-danger">{{ $message }}</span>
                                        @enderror
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-inner two mb-25">
                                        <label>City*</label>
                                        <input type="text" name="city" placeholder="Enter city" required 
                                               value="{{ old('city') }}">
                                        @error('city')
                                            <span class="text-danger">{{ $message }}</span>
                                        @enderror
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-inner two mb-25">
                                        <label>Country*</label>
                                        <input type="text" name="country" placeholder="Enter country" required 
                                               value="{{ old('country', 'Sri Lanka') }}">
                                        @error('country')
                                            <span class="text-danger">{{ $message }}</span>
                                        @enderror
                                    </div>
                                </div>
                                <div class="col-md-12">
                                    <div class="form-inner two mb-25">
                                        <label>Special Requirements</label>
                                        <textarea name="special_notes" placeholder="Any special requests or requirements...">{{ old('special_notes') }}</textarea>
                                    </div>
                                </div>
                                
                                <!-- Flight Details Section -->
                                <div class="col-md-12">
                                    <div class="form-section-divider">
                                        <h6>Flight Details (Optional)</h6>
                                        <p class="text-muted">If arriving by flight, provide details for airport pickup</p>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-inner two mb-25">
                                        <label>Airline</label>
                                        <input type="text" name="flight_airline" placeholder="e.g., Sri Lankan Airlines" 
                                               value="{{ old('flight_airline') }}">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-inner two mb-25">
                                        <label>Flight Number</label>
                                        <input type="text" name="flight_number" placeholder="e.g., UL123" 
                                               value="{{ old('flight_number') }}">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-inner two mb-25">
                                        <label>Arrival Date</label>
                                        <input type="date" name="flight_arrival_date" 
                                               value="{{ old('flight_arrival_date') }}">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-inner two mb-25">
                                        <label>Arrival Time</label>
                                        <input type="time" name="flight_arrival_time" 
                                               value="{{ old('flight_arrival_time') }}">
                                    </div>
                                </div>
                                <div class="col-md-12">
                                    <div class="form-inner two mb-25">
                                        <label>Additional Notes</label>
                                        <textarea name="additional_notes" placeholder="Any other information you'd like to share...">{{ old('additional_notes') }}</textarea>
                                    </div>
                                </div>
                                
                                @if($paymentType === 'quotation')
                                <!-- Additional fields for quotation request -->
                                <div class="col-md-6">
                                    <div class="form-inner two mb-25">
                                        <label>Preferred Contact Time</label>
                                        <select name="contact_time" class="form-select">
                                            <option value="">Select preferred time</option>
                                            <option value="morning" {{ old('contact_time') == 'morning' ? 'selected' : '' }}>Morning (9 AM - 12 PM)</option>
                                            <option value="afternoon" {{ old('contact_time') == 'afternoon' ? 'selected' : '' }}>Afternoon (12 PM - 5 PM)</option>
                                            <option value="evening" {{ old('contact_time') == 'evening' ? 'selected' : '' }}>Evening (5 PM - 8 PM)</option>
                                            <option value="anytime" {{ old('contact_time') == 'anytime' ? 'selected' : '' }}>Anytime</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-inner two mb-25">
                                        <label>Budget Range (Optional)</label>
                                        <select name="budget_range" class="form-select">
                                            <option value="">Select budget range</option>
                                            <option value="under-500" {{ old('budget_range') == 'under-500' ? 'selected' : '' }}>Under $500</option>
                                            <option value="500-1000" {{ old('budget_range') == '500-1000' ? 'selected' : '' }}>$500 - $1,000</option>
                                            <option value="1000-2000" {{ old('budget_range') == '1000-2000' ? 'selected' : '' }}>$1,000 - $2,000</option>
                                            <option value="over-2000" {{ old('budget_range') == 'over-2000' ? 'selected' : '' }}>Over $2,000</option>
                                        </select>
                                    </div>
                                </div>
                                @endif
                                
                                <!-- Dynamic Terms and Conditions -->
                                @if(!empty($termsAndConditions))
                                <div class="col-md-12">
                                    <div class="terms-conditions-section">
                                        <h6>Terms & Conditions</h6>
                                        <div class="terms-content">
                                            @foreach($termsAndConditions as $tc)
                                            <div class="term-item mb-3">
                                                <h6 class="term-title">{{ $tc->title }}</h6>
                                                <div class="term-body">
                                                    {!! $tc->content !!}
                                                </div>
                                            </div>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                                @endif
                                
                                <div class="col-md-12">
                                    <div class="form-inner2">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="save_info" 
                                                   value="1" id="saveInfo" {{ old('save_info') ? 'checked' : '' }}>
                                            <label class="form-check-label" for="saveInfo">
                                                Save my information for future bookings
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-12">
                                    <div class="form-inner2">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="terms_accepted" 
                                                   value="1" id="termsAccepted" required {{ old('terms_accepted') ? 'checked' : '' }}>
                                            <label class="form-check-label" for="termsAccepted">
                                                @if(!empty($termsAndConditions))
                                                    I agree to the above Terms & Conditions and Privacy Policy*
                                                @else
                                                    I agree to the <a href="#" target="_blank">Terms & Conditions</a> and <a href="#" target="_blank">Privacy Policy</a>*
                                                @endif
                                            </label>
                                        </div>
                                        @error('terms_accepted')
                                            <span class="text-danger">{{ $message }}</span>
                                        @enderror
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-5">
                    <div class="checkout-form-wrapper">
                        <div class="checkout-form-title">
                            <h4>Order Summary</h4>
                        </div>
                        <div class="order-sum-area">
                            <div class="cart-menu">
                                <div class="cart-body">
                                    <ul>
                                        @foreach($cart as $key => $item)
                                        <li class="single-item">
                                            <div class="item-area">
                                                <div class="main-item">
                                                    <div class="item-img">
                                                        @if(isset($item['image']) && $item['image'])
                                                            <img src="{{ s3_asset($item['image']) }}" alt="{{ $item['name'] ?? 'Vehicle' }}">
                                                        @else
                                                            <img src="{{ asset('assets/img/innerpages/cart-img1.png') }}" alt="{{ $item['name'] ?? 'Vehicle' }}">
                                                        @endif
                                                    </div>
                                                    <div class="content-and-quantity">
                                                        <div class="content">
                                                            <span>{{ $currencySymbol }}{{ number_format($item['price'] ?? 0, 2) }}/day × {{ $item['days'] ?? 1 }} days</span>
                                                            <h6><a href="#">{{ $item['name'] ?? 'Vehicle Rental' }}</a></h6>
                                                            <p><small>{{ date('M d', strtotime($item['pickup_date'])) }} - {{ date('M d, Y', strtotime($item['return_date'])) }}</small></p>
                                                            <p><small><i class="bi bi-geo-alt"></i> {{ $item['pickup_location'] ?? 'Location' }}</small></p>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="item-total">
                                                    {{ $currencySymbol }}{{ number_format(($item['price'] ?? 0) * ($item['days'] ?? 1), 2) }}
                                                </div>
                                            </div>
                                        </li>
                                        @endforeach
                                    </ul>
                                </div>
                                
                                <div class="cart-footer">
                                    <div class="pricing-area mb-40">
                                        <ul>
                                            <li>
                                                <strong>Subtotal</strong>
                                                <strong>{{ $currencySymbol }}{{ number_format($subtotal, 2) }}</strong>
                                            </li>
                                            @if($serviceFee > 0)
                                            <li>
                                                Service Fee
                                                <div class="order-info">
                                                    <span>{{ $currencySymbol }}{{ number_format($serviceFee, 2) }}</span>
                                                </div>
                                            </li>
                                            @endif
                                            @if($tax > 0)
                                            <li>
                                                {{ $taxLabel }} ({{ number_format(config('booking.tax.rate', 0.025) * 100, 1) }}%)
                                                <div class="order-info">
                                                    <span>{{ $currencySymbol }}{{ number_format($tax, 2) }}</span>
                                                </div>
                                            </li>
                                            @endif
                                            @if($vat > 0)
                                            <li>
                                                {{ $vatLabel }} ({{ number_format(config('booking.vat.rate', 0.18) * 100, 0) }}%)
                                                <div class="order-info">
                                                    <span>{{ $currencySymbol }}{{ number_format($vat, 2) }}</span>
                                                </div>
                                            </li>
                                            @endif
                                            @if($discount > 0)
                                            <li>
                                                Discount
                                                <div class="order-info text-success">
                                                    <span>-{{ $currencySymbol }}{{ number_format($discount, 2) }}</span>
                                                </div>
                                            </li>
                                            @endif
                                            <li class="total-row">
                                                <strong>Total</strong>
                                                <strong>{{ $currencySymbol }}{{ number_format($total, 2) }}</strong>
                                            </li>
                                            @if($paymentType !== 'full')
                                            <li class="payment-amount-row">
                                                <strong>
                                                    @if($paymentType === 'advance')
                                                        Amount to Pay ({{ config('booking.advance_payment.percentage', 50) }}%)
                                                    @elseif($paymentType === 'quotation')
                                                        Quotation Request
                                                    @endif
                                                </strong>
                                                <strong class="text-primary">
                                                    @if($paymentType === 'quotation')
                                                        No Payment Required
                                                    @else
                                                        {{ $currencySymbol }}{{ number_format($paymentAmount, 2) }}
                                                    @endif
                                                </strong>
                                            </li>
                                            @endif
                                        </ul>
                                    </div>
                                    
                                    @if($paymentType !== 'quotation')
                                    <!-- Payment Method Selection -->
                                    <div class="choose-payment-method">
                                        <h6>Select Payment Method</h6>
                                        @error('payment_method')
                                            <div class="alert alert-danger">{{ $message }}</div>
                                        @enderror
                                        <div class="payment-option">
                                            <ul>
                                                @foreach($paymentMethods as $key => $method)
                                                <li class="{{ $key }}">
                                                    <input type="radio" name="payment_method" value="{{ $key }}" 
                                                           id="payment_{{ $key }}" {{ old('payment_method') === $key ? 'checked' : '' }}>
                                                    <label for="payment_{{ $key }}">
                                                        <i class="{{ $method['icon'] ?? 'bi-credit-card' }}" style="font-size: 24px;"></i>
                                                        <span>{{ $method['label'] ?? ucfirst($key) }}</span>
                                                        @if(isset($method['description']))
                                                            <small class="d-block text-muted" style="font-size: 11px;">{{ $method['description'] }}</small>
                                                        @endif
                                                        <div class="checked">
                                                            <i class="bi bi-check"></i>
                                                        </div>
                                                    </label>
                                                </li>
                                                @endforeach
                                            </ul>
                                        </div>
                                        
                                        <!-- Bank Transfer Instructions -->
                                        <div class="pt-25" id="BankTransferInfo" style="display: none;">
                                            <div class="alert alert-info">
                                                <h6><i class="bi bi-bank"></i> Bank Transfer Details:</h6>
                                                <p><strong>Account Name:</strong> Casons Rent A Car (Pvt) Ltd</p>
                                                <p><strong>Bank:</strong> Commercial Bank of Ceylon PLC</p>
                                                <p><strong>Account No:</strong> 1234567890</p>
                                                <p><strong>Branch:</strong> Colombo Main Branch</p>
                                                <p><strong>SWIFT Code:</strong> CCEYLKLX</p>
                                                <small class="text-muted">* Please use your booking reference as the transfer description and email the payment receipt to payments@casonsrentacar.lk</small>
                                            </div>
                                        </div>
                                        
                                        <!-- Online Banking Instructions -->
                                        <div class="pt-25" id="OnlineBankingInfo" style="display: none;">
                                            <div class="alert alert-info">
                                                <h6><i class="bi bi-wallet2"></i> Online Banking Payment:</h6>
                                                <p>You will be redirected to your bank's secure payment gateway after clicking "Complete Booking".</p>
                                                <small class="text-muted">* Supported banks: Commercial Bank, HNB, Sampath Bank, Nations Trust Bank</small>
                                            </div>
                                        </div>
                                    </div>
                                    @endif
                                    
                                    <button type="submit" class="primary-btn1 w-100" id="checkout-submit-btn">
                                        <span>
                                            @if($paymentType === 'quotation')
                                                Submit Quotation Request
                                            @else
                                                Complete Booking - {{ $currencySymbol }}{{ number_format($paymentAmount, 2) }}
                                            @endif
                                            <svg width="10" height="10" viewBox="0 0 10 10" xmlns="http://www.w3.org/2000/svg">
                                                <path d="M9.73535 1.14746C9.57033 1.97255 9.32924 3.26406 9.24902 4.66797C9.16817 6.08312 9.25559 7.5453 9.70214 8.73633C9.84754 9.12406 9.65129 9.55659 9.26367 9.70215C8.9001 9.83849 8.4969 9.67455 8.32812 9.33398L8.29785 9.26367L8.19921 8.98438C7.73487 7.5758 7.67054 5.98959 7.75097 4.58203C7.77875 4.09598 7.82525 3.62422 7.87988 3.17969L1.53027 9.53027C1.23738 9.82317 0.762615 9.82317 0.469722 9.53027C0.176829 9.23738 0.176829 8.76262 0.469722 8.46973L6.83593 2.10254C6.3319 2.16472 5.79596 2.21841 5.25 2.24902C3.8302 2.32862 2.2474 2.26906 0.958003 1.79102L0.704097 1.68945L0.635738 1.65527C0.303274 1.47099 0.157578 1.06102 0.310542 0.704102C0.463655 0.347333 0.860941 0.170391 1.22363 0.28418L1.29589 0.310547L1.48828 0.387695C2.47399 0.751207 3.79966 0.827571 5.16601 0.750977C6.60111 0.670504 7.97842 0.428235 8.86132 0.262695L9.95312 0.0585938L9.73535 1.14746Z"></path>
                                            </svg>
                                        </span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
        @endif
    </div>
</div>
<!--Checkout Page End-->
@endsection

@push('styles')
<style>
/* Payment type selection styles */
.payment-type-selection .payment-option {
    position: relative;
}

.payment-radio {
    display: none;
}

.payment-label {
    cursor: pointer;
    display: block;
    margin: 0;
}

.payment-card {
    border: 2px solid #e9ecef;
    border-radius: 12px;
    padding: 20px;
    text-align: center;
    transition: all 0.3s ease;
    background: white;
}

.payment-card:hover {
    border-color: var(--primary-color1);
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}

.payment-radio:checked + .payment-label .payment-card {
    border-color: var(--primary-color1);
    background: rgba(201, 28, 35, 0.05);
}

.payment-card i {
    font-size: 2rem;
    margin-bottom: 10px;
    display: block;
}

.payment-card h6 {
    margin: 10px 0 5px 0;
    font-weight: 600;
    color: #333;
}

.payment-card p {
    font-size: 14px;
    color: #666;
    margin-bottom: 5px;
}

.payment-card small {
    font-weight: 500;
}

/* Existing payment option styles */
.payment-option ul {
    display: flex;
    gap: 15px;
    list-style: none;
    padding: 0;
    margin: 15px 0;
}

.payment-option li {
    flex: 1;
    position: relative;
    border: 2px solid #ddd;
    border-radius: 8px;
    overflow: hidden;
    cursor: pointer;
    transition: all 0.3s ease;
}

.payment-option li:hover {
    border-color: var(--primary-color1);
}

.payment-option li.active {
    border-color: var(--primary-color1);
    background-color: #f8f9fa;
}

.payment-option li input[type="radio"] {
    display: none;
}

.payment-option li label {
    display: block;
    padding: 15px;
    text-align: center;
    cursor: pointer;
    margin: 0;
}

.payment-option li label img {
    max-height: 30px;
    max-width: 100%;
}

.payment-option li .checked {
    position: absolute;
    top: 5px;
    right: 5px;
    background: var(--primary-color1);
    color: white;
    border-radius: 50%;
    width: 20px;
    height: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.payment-option li.active .checked {
    opacity: 1;
}

.cart-footer .pricing-area ul li {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 0;
    border-bottom: 1px solid #eee;
}

.cart-footer .pricing-area ul li.total-row {
    border-top: 2px solid #ddd;
    margin-top: 10px;
    padding-top: 15px;
    font-size: 18px;
}

.cart-footer .pricing-area ul li.payment-amount-row {
    background: #f8f9fa;
    padding: 15px;
    margin: 15px -20px 0;
    border-radius: 8px;
    border: none;
}

.single-item .item-area {
    display: flex;
    align-items: center;
    gap: 15px;
    padding: 15px 0;
    border-bottom: 1px solid #eee;
}

.single-item .main-item {
    display: flex;
    align-items: flex-start;
    gap: 15px;
    flex: 1;
}

.single-item .item-img {
    width: 60px;
    height: 60px;
    border-radius: 8px;
    overflow: hidden;
    flex-shrink: 0;
}

.single-item .item-img img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.single-item .content h6 {
    margin-bottom: 5px;
    font-size: 14px;
}

.single-item .content span {
    font-size: 12px;
    color: #666;
    font-weight: 600;
}

.single-item .content p {
    margin: 2px 0;
    font-size: 12px;
    color: #888;
}

.single-item .item-total {
    font-weight: 600;
    color: var(--primary-color1);
    text-align: right;
}

.form-inner.two {
    margin-bottom: 20px;
}

.form-inner.two label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: #333;
}

.form-inner.two input,
.form-inner.two textarea,
.form-inner.two select {
    width: 100%;
    padding: 12px 15px;
    border: 1px solid #ddd;
    border-radius: 5px;
    font-size: 14px;
    transition: border-color 0.3s ease;
}

.form-inner.two input:focus,
.form-inner.two textarea:focus,
.form-inner.two select:focus {
    outline: none;
    border-color: var(--primary-color1);
}

.form-inner.two textarea {
    min-height: 100px;
    resize: vertical;
}

.form-check {
    display: flex;
    align-items: flex-start;
    gap: 10px;
}

.form-check-input {
    margin-top: 3px;
}

.form-check-label {
    font-size: 14px;
    line-height: 1.4;
}

.alert {
    padding: 15px 20px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.alert-info {
    background-color: #d1ecf1;
    border-color: #bee5eb;
    color: #0c5460;
}

.alert-warning {
    background-color: #fff3cd;
    border-color: #ffeaa7;
    color: #856404;
}

.text-danger {
    color: #dc3545 !important;
    font-size: 12px;
    margin-top: 5px;
    display: block;
}

.form-section-divider {
    padding: 20px 0 15px 0;
    border-top: 2px solid #eee;
    margin-bottom: 15px;
}

.form-section-divider h6 {
    margin-bottom: 5px;
    color: #333;
    font-weight: 600;
}

.form-section-divider .text-muted {
    font-size: 13px;
    color: #999;
}

.terms-conditions-section {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
}

.terms-conditions-section h6 {
    margin-bottom: 15px;
    color: #333;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 14px;
}

.terms-content {
    max-height: 400px;
    overflow-y: auto;
    padding-right: 10px;
}

.term-item {
    margin-bottom: 20px;
}

.term-item:last-child {
    margin-bottom: 0;
}

.term-title {
    color: var(--primary-color1);
    font-size: 14px;
    font-weight: 600;
    margin-bottom: 8px;
}

.term-body {
    font-size: 13px;
    line-height: 1.6;
    color: #555;
}

.term-body p {
    margin-bottom: 10px;
}

.term-body ul,
.term-body ol {
    margin-left: 20px;
    margin-bottom: 10px;
}

.term-body li {
    margin-bottom: 5px;
}

@media (max-width: 768px) {
    .payment-option ul {
        flex-direction: column;
        gap: 10px;
    }
    
    .payment-option li label {
        padding: 12px;
    }
    
    .single-item .main-item {
        flex-direction: column;
        text-align: center;
    }
    
    .terms-content {
        max-height: 250px;
    }
}
</style>
@endpush

@push('scripts')
<script>
$(document).ready(function() {
    // Get PHP variables from blade
    const currencySymbol = '{{ $currencySymbol }}';
    const total = {{ $total }};
    const advancePercentage = {{ config('booking.advance_payment.percentage', 50) }};
    
    // Payment type selection handling
    $('input[name="payment_type"]').on('change', function() {
        const paymentType = $(this).val();
        const alertContent = $('#alert-content');
        const paymentMethodSection = $('.choose-payment-method');
        const submitBtn = $('#checkout-submit-btn span');
        
        // Calculate payment amounts
        const advanceAmount = total * (advancePercentage / 100);
        const fullAmount = total;
        
        // Update the alert content based on payment type
        switch(paymentType) {
            case 'advance':
                alertContent.html(`
                    <h6><i class="bi bi-info-circle"></i> Advance Payment (${advancePercentage}%)</h6>
                    <p class="mb-0">You are paying ${advancePercentage}% advance. The remaining amount will be collected at the time of vehicle pickup.</p>
                `);
                paymentMethodSection.show();
                submitBtn.html(`Complete Booking - ${currencySymbol}${advanceAmount.toFixed(2)} <svg width="10" height="10" viewBox="0 0 10 10" xmlns="http://www.w3.org/2000/svg"><path d="M9.73535 1.14746C9.57033 1.97255 9.32924 3.26406 9.24902 4.66797C9.16817 6.08312 9.25559 7.5453 9.70214 8.73633C9.84754 9.12406 9.65129 9.55659 9.26367 9.70215C8.9001 9.83849 8.4969 9.67455 8.32812 9.33398L8.29785 9.26367L8.19921 8.98438C7.73487 7.5758 7.67054 5.98959 7.75097 4.58203C7.77875 4.09598 7.82525 3.62422 7.87988 3.17969L1.53027 9.53027C1.23738 9.82317 0.762615 9.82317 0.469722 9.53027C0.176829 9.23738 0.176829 8.76262 0.469722 8.46973L6.83593 2.10254C6.3319 2.16472 5.79596 2.21841 5.25 2.24902C3.8302 2.32862 2.2474 2.26906 0.958003 1.79102L0.704097 1.68945L0.635738 1.65527C0.303274 1.47099 0.157578 1.06102 0.310542 0.704102C0.463655 0.347333 0.860941 0.170391 1.22363 0.28418L1.29589 0.310547L1.48828 0.387695C2.47399 0.751207 3.79966 0.827571 5.16601 0.750977C6.60111 0.670504 7.97842 0.428235 8.86132 0.262695L9.95312 0.0585938L9.73535 1.14746Z"></path></svg>`);
                break;
            case 'quotation':
                alertContent.html(`
                    <h6><i class="bi bi-file-text"></i> Request Quotation</h6>
                    <p class="mb-0">You are requesting a quotation. Our team will contact you with detailed pricing and booking information.</p>
                `);
                paymentMethodSection.hide();
                submitBtn.html('Submit Quotation Request <svg width="10" height="10" viewBox="0 0 10 10" xmlns="http://www.w3.org/2000/svg"><path d="M9.73535 1.14746C9.57033 1.97255 9.32924 3.26406 9.24902 4.66797C9.16817 6.08312 9.25559 7.5453 9.70214 8.73633C9.84754 9.12406 9.65129 9.55659 9.26367 9.70215C8.9001 9.83849 8.4969 9.67455 8.32812 9.33398L8.29785 9.26367L8.19921 8.98438C7.73487 7.5758 7.67054 5.98959 7.75097 4.58203C7.77875 4.09598 7.82525 3.62422 7.87988 3.17969L1.53027 9.53027C1.23738 9.82317 0.762615 9.82317 0.469722 9.53027C0.176829 9.23738 0.176829 8.76262 0.469722 8.46973L6.83593 2.10254C6.3319 2.16472 5.79596 2.21841 5.25 2.24902C3.8302 2.32862 2.2474 2.26906 0.958003 1.79102L0.704097 1.68945L0.635738 1.65527C0.303274 1.47099 0.157578 1.06102 0.310542 0.704102C0.463655 0.347333 0.860941 0.170391 1.22363 0.28418L1.29589 0.310547L1.48828 0.387695C2.47399 0.751207 3.79966 0.827571 5.16601 0.750977C6.60111 0.670504 7.97842 0.428235 8.86132 0.262695L9.95312 0.0585938L9.73535 1.14746Z"></path></svg>');
                break;
            default:
                alertContent.html(`
                    <h6><i class="bi bi-credit-card"></i> Full Payment</h6>
                    <p class="mb-0">You are making full payment for your vehicle rental booking.</p>
                `);
                paymentMethodSection.show();
                submitBtn.html(`Complete Booking - ${currencySymbol}${fullAmount.toFixed(2)} <svg width="10" height="10" viewBox="0 0 10 10" xmlns="http://www.w3.org/2000/svg"><path d="M9.73535 1.14746C9.57033 1.97255 9.32924 3.26406 9.24902 4.66797C9.16817 6.08312 9.25559 7.5453 9.70214 8.73633C9.84754 9.12406 9.65129 9.55659 9.26367 9.70215C8.9001 9.83849 8.4969 9.67455 8.32812 9.33398L8.29785 9.26367L8.19921 8.98438C7.73487 7.5758 7.67054 5.98959 7.75097 4.58203C7.77875 4.09598 7.82525 3.62422 7.87988 3.17969L1.53027 9.53027C1.23738 9.82317 0.762615 9.82317 0.469722 9.53027C0.176829 9.23738 0.176829 8.76262 0.469722 8.46973L6.83593 2.10254C6.3319 2.16472 5.79596 2.21841 5.25 2.24902C3.8302 2.32862 2.2474 2.26906 0.958003 1.79102L0.704097 1.68945L0.635738 1.65527C0.303274 1.47099 0.157578 1.06102 0.310542 0.704102C0.463655 0.347333 0.860941 0.170391 1.22363 0.28418L1.29589 0.310547L1.48828 0.387695C2.47399 0.751207 3.79966 0.827571 5.16601 0.750977C6.60111 0.670504 7.97842 0.428235 8.86132 0.262695L9.95312 0.0585938L9.73535 1.14746Z"></path></svg>`);
                break;
        }
    });

    // Payment method selection
    $('.payment-option input[type="radio"]').on('change', function() {
        $('.payment-option li').removeClass('active');
        $(this).closest('li').addClass('active');
        
        // Show/hide payment method specific fields
        $('#StripePayment, #BankTransferInfo').hide();
        
        const paymentMethod = $(this).val();
        if (paymentMethod === 'stripe') {
            $('#StripePayment').slideDown();
        } else if (paymentMethod === 'bank_transfer') {
            $('#BankTransferInfo').slideDown();
        } else if (paymentMethod === 'online_banking') {
            $('#OnlineBankingInfo').slideDown();
        }
    });
    
    // Form validation
    $('#checkout-form').on('submit', function(e) {
        const paymentType = $('input[name="payment_type"]:checked').val();
        const paymentMethod = $('input[name="payment_method"]:checked').val();
        
        // Skip payment method validation for quotation requests
        if (paymentType !== 'quotation') {
            if (!paymentMethod) {
                e.preventDefault();
                alert('Please select a payment method');
                return false;
            }
        }
        
        // Disable submit button to prevent double submission
        $('#checkout-submit-btn').prop('disabled', true).html('<span>Processing...</span>');
    });
});
</script>
@endpush