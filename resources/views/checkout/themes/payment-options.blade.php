<section class="reservation-payment" aria-labelledby="reservation-payment-title">
    <h2 id="reservation-payment-title">Select Payment Option</h2>
    <div class="reservation-payment__options">
        @if ($paymentMethods->has('online'))
            <label class="reservation-payment__option" for="payment_full">
                <input type="radio" name="payment_type" value="full" id="payment_full" form="checkout-form" @checked($paymentType === 'full')>
                <span class="reservation-payment__copy"><span class="reservation-payment__line"><strong>Pay Full Amount</strong><b>{{ $currencySymbol }} {{ number_format(floor(max(0, $total)), 0) }}</b></span><small>Complete payment now</small></span>
            </label>
        @endif
        @if ($advancePaymentEnabled)
            <label class="reservation-payment__option" for="payment_advance">
                <input type="radio" name="payment_type" value="advance" id="payment_advance" form="checkout-form" @checked($paymentType === 'advance')>
                <span class="reservation-payment__copy"><span class="reservation-payment__line"><strong>Pay <span data-advance-percentage>{{ $advanceActualPercentage }}</span>% Advance</strong><b>Now: {{ $currencySymbol }} <span data-advance-payment-amount>{{ number_format(floor(max(0, $advancePaymentAmount)), 0) }}</span></b></span><small>{{ $tourOnlyCheckout ? 'Pay the balance later' : 'Pay remaining on pickup' }}</small></span>
            </label>
        @endif
        @if ($offlinePaymentEnabled)
            <label class="reservation-payment__option" for="payment_checkin">
                <input type="radio" name="payment_type" value="checkin" id="payment_checkin" form="checkout-form" @checked($paymentType === 'checkin')>
                <span class="reservation-payment__copy"><span class="reservation-payment__line"><strong>{{ $tourOnlyCheckout ? 'Pay later' : 'Pay on Check-in' }}</strong><b>Due: {{ $currencySymbol }} {{ number_format(floor(max(0, $total)), 0) }}</b></span><small>{{ $tourOnlyCheckout ? 'No payment is due now' : 'Pay when you collect' }}</small></span>
            </label>
        @endif
        <label class="reservation-payment__option" for="payment_quotation">
            <input type="radio" name="payment_type" value="quotation" id="payment_quotation" form="checkout-form" @checked($paymentType === 'quotation')>
            <span class="reservation-payment__copy"><span class="reservation-payment__line"><strong>Request Quotation</strong><b>No payment now</b></span><small>Get detailed pricing</small></span>
        </label>
    </div>
</section>
