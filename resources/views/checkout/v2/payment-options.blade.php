<section class="border-t border-slate-100 bg-slate-50/40 p-5" aria-labelledby="v2-payment-title" data-purpose="payment-selector">
    <h2 id="v2-payment-title" class="mb-3 flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-slate-800"><i class="bi bi-credit-card text-red-600" aria-hidden="true"></i>Select Payment Structure</h2>
    <div class="space-y-2.5">
        @if ($paymentMethods->has('online'))
            <label data-v2-choice class="flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 bg-white p-3.5">
                <input type="radio" name="payment_type" value="full" id="payment_full" form="checkout-form" @checked($paymentType === 'full') class="mt-0.5 h-4 w-4 shrink-0 text-red-600">
                <span class="min-w-0 flex-1"><span class="flex flex-wrap items-baseline justify-between gap-2"><strong class="text-xs font-bold text-slate-900">Pay Full Amount</strong><b class="text-xs font-extrabold text-slate-900" data-summary-field="total">{{ $currencySymbol }} {{ number_format(floor(max(0, $total)), 0) }}</b></span><small class="mt-0.5 block text-[11px] text-slate-500">Complete payment now.</small></span>
            </label>
        @endif
        @if ($advancePaymentEnabled)
            <label data-v2-choice class="flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 bg-white p-3.5">
                <input type="radio" name="payment_type" value="advance" id="payment_advance" form="checkout-form" @checked($paymentType === 'advance') class="mt-0.5 h-4 w-4 shrink-0 text-red-600">
                <span class="min-w-0 flex-1"><span class="flex flex-wrap items-baseline justify-between gap-2"><strong class="text-xs font-bold text-slate-900">Pay <span data-advance-percentage>{{ $advanceActualPercentage }}</span>% Advance</strong><b class="text-xs font-extrabold text-slate-900">{{ $currencySymbol }} <span data-advance-payment-amount>{{ number_format(floor(max(0, $advancePaymentAmount)), 0) }}</span></b></span><small class="mt-0.5 block text-[11px] text-slate-500">{{ $tourOnlyCheckout ? 'Pay the balance later.' : 'Pay the remaining balance on pickup.' }}</small></span>
            </label>
        @endif
        @if ($offlinePaymentEnabled)
            <label data-v2-choice class="flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 bg-white p-3.5">
                <input type="radio" name="payment_type" value="checkin" id="payment_checkin" form="checkout-form" @checked($paymentType === 'checkin') class="mt-0.5 h-4 w-4 shrink-0 text-red-600">
                <span class="min-w-0 flex-1"><span class="flex flex-wrap items-baseline justify-between gap-2"><strong class="text-xs font-bold text-slate-900">{{ $tourOnlyCheckout ? 'Pay later' : 'Pay on Check-in' }}</strong><b class="text-xs font-extrabold text-slate-900" data-summary-field="total">{{ $currencySymbol }} {{ number_format(floor(max(0, $total)), 0) }}</b></span><small class="mt-0.5 block text-[11px] text-slate-500">{{ $tourOnlyCheckout ? 'No payment is due now.' : 'Pay when you collect.' }}</small></span>
            </label>
        @endif
        <label data-v2-choice class="flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 bg-white p-3.5">
            <input type="radio" name="payment_type" value="quotation" id="payment_quotation" form="checkout-form" @checked($paymentType === 'quotation') class="mt-0.5 h-4 w-4 shrink-0 text-red-600">
            <span class="min-w-0 flex-1"><span class="flex flex-wrap items-baseline justify-between gap-2"><strong class="text-xs font-bold text-slate-900">Request Official Quotation</strong><b class="text-xs font-extrabold text-slate-900">No payment now</b></span><small class="mt-0.5 block text-[11px] text-slate-500">Get detailed pricing from our team.</small></span>
        </label>
    </div>
</section>
