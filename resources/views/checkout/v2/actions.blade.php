<div id="checkout-payment-layout" @if ($hideCheckoutDetails) hidden @endif>
    @include('checkout.v2.payment-options')
    <div class="space-y-3 bg-white p-5">
        <div id="payment-contact-requirement" class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800" role="status" hidden>Enter both a valid email address and mobile number to continue checkout.</div>
        <div id="payment-type-alert" class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs text-slate-600"><div id="alert-content">
            @switch($paymentType)
                @case('advance') <strong>Advance payment</strong><p class="mt-1">You are paying <span data-advance-percentage>{{ $advanceActualPercentage }}</span>% now. The balance is due later.</p> @break
                @case('checkin') <strong>{{ $tourOnlyCheckout ? 'Pay later' : 'Pay on Check-in' }}</strong><p class="mt-1">No payment is required now.</p> @break
                @case('quotation') <strong>Request Quotation</strong><p class="mt-1">Our team will contact you with detailed pricing.</p> @break
                @default <strong>Full Payment</strong><p class="mt-1">You are making full payment for your booking.</p>
            @endswitch
        </div></div>
        <input type="hidden" name="payment_method" id="payment_method_field" value="" form="checkout-form">
        <button type="submit" id="checkout-submit-btn" form="checkout-form" class="flex w-full items-center justify-center rounded-xl bg-red-600 px-5 py-3.5 text-sm font-bold text-white shadow-sm hover:bg-red-700"><span>@if ($paymentType === 'quotation') Submit Quotation Request @elseif ($paymentType === 'checkin') Confirm Booking - {{ $tourOnlyCheckout ? 'Pay Later' : 'Pay on Check-in' }} @else Complete Booking - <small class="currency-symbol">{{ $currencySymbol }}</small> {{ number_format(floor(max(0, $paymentAmount)), 0) }} @endif</span></button>
    </div>
</div>
