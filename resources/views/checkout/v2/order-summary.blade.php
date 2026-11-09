@php
    $hasTourItem = collect($cart)->contains(fn ($item) => !empty($item['is_tour']));
    $hasNonTourItem = collect($cart)->contains(fn ($item) => empty($item['is_tour']));
    $pickupDepositTotal = collect($cart)->sum(fn ($item) => (float) ($item['refundable_deposit'] ?? 0));
    $appliedPromoCode = $cartData['coupon_code'] ?? null;
    $summaryRows = [
        'addon_charges' => ['Add-on charges', $totals['addon_charges'] ?? 0],
        'extra_km_charges' => ['Extra distance', $totals['extra_km_charges'] ?? 0],
        'service_fee' => ['Service fee', $serviceFee],
        'tax' => ["{$taxLabel} ({$taxPercentageLabel}%)", $tax],
        'vat' => ["{$vatLabel} ({$vatPercentageLabel}%)", $vat],
    ];
@endphp
<section class="overflow-hidden rounded-2xl border border-slate-200/90 bg-white shadow-sm" aria-labelledby="v2-summary-title" data-purpose="order-summary-sidebar">
    <div class="flex items-center justify-between gap-3 border-b border-slate-100 bg-slate-50/70 p-5">
        <div><h2 id="v2-summary-title" class="text-base font-bold text-slate-900">Order Summary</h2><p class="text-xs text-slate-500">{{ count($cart) }} {{ $hasTourItem ? (count($cart) === 1 ? 'booking item' : 'booking items') : (count($cart) === 1 ? 'vehicle' : 'vehicles') }}</p></div>
        <button type="button" class="checkout-clear-cart-btn text-xs font-semibold text-slate-500 hover:text-red-600" title="Clear cart"><i class="bi bi-trash" aria-hidden="true"></i> Clear Cart</button>
    </div>
    <ul class="m-0 list-none p-0">
        @foreach ($cart as $key => $item)
            @include('checkout.v2.cart-item', ['key' => $key, 'item' => $item])
        @endforeach
    </ul>
    <div class="space-y-3 border-t border-slate-100 p-5 text-xs text-slate-600">
        <h3 class="text-xs font-bold uppercase tracking-wider text-slate-800">Price details</h3>
        <div class="flex justify-between gap-3"><span>Subtotal</span><strong class="text-slate-900" data-summary-field="subtotal">{{ $currencySymbol }} {{ number_format(floor(max(0, $subtotal)), 0) }}</strong></div>
        @foreach ($summaryRows as $field => [$label, $amount])
            <div data-summary-row="{{ $field }}" @if ($amount <= 0) style="display:none" @endif class="v2-summary-row gap-3"><span>{{ $label }}</span><strong class="text-slate-900" data-summary-field="{{ $field }}">{{ $currencySymbol }} {{ number_format(floor(max(0, $amount)), 0) }}</strong></div>
        @endforeach
        <div data-summary-row="coupon_discount" @if ($discount <= 0) style="display:none" @endif class="v2-summary-row gap-3 text-emerald-700"><span>Discount</span><strong data-summary-field="coupon_discount">-{{ $currencySymbol }} {{ number_format(floor(max(0, $discount)), 0) }}</strong></div>
        <div class="border-t border-slate-100 pt-3">
            <label for="checkout-promo-input" class="mb-2 block text-xs font-bold text-slate-800">Promo code</label>
            <div id="checkout-promo-body">
                @if ($appliedPromoCode)
                    <div class="flex items-center justify-between gap-2 rounded-lg bg-emerald-50 p-3 text-emerald-700"><span class="promo-code-value">{{ $appliedPromoCode }}</span><button type="button" class="remove-promo-checkout-btn font-semibold" aria-label="Remove promo code">Remove</button></div>
                @else
                    <div class="flex gap-2"><input type="text" id="checkout-promo-input" placeholder="Enter code" autocomplete="off" class="min-w-0 flex-1 rounded-lg border-slate-200 px-3 py-2 text-xs"><button type="button" id="apply-promo-checkout-btn" class="rounded-lg bg-slate-900 px-4 py-2 text-xs font-bold text-white"><span class="btn-text">Apply</span><span class="btn-loading" style="display:none">Applying…</span></button></div>
                @endif
            </div>
            <div id="checkout-promo-message" class="mt-2 text-xs" role="status" aria-live="polite"></div>
        </div>
        <div class="flex items-start justify-between gap-3 border-t border-slate-200 pt-4"><div><strong class="text-sm text-slate-900">Total Amount</strong><small class="mt-1 block text-slate-500">Includes selected add-ons{{ $hasNonTourItem ? ' and extra distance' : '' }}</small></div><strong class="text-lg font-extrabold text-red-700" data-summary-field="total">{{ $currencySymbol }} {{ number_format(floor(max(0, $total)), 0) }}</strong></div>
        @if ($pickupDepositTotal > 0)
            <div class="flex justify-between gap-3"><span>Refundable deposit at vehicle collection</span><strong class="text-slate-900">{{ $currencySymbol }} {{ number_format(floor($pickupDepositTotal), 0) }}</strong></div>
            <div class="flex justify-between gap-3"><span>Booking total including refundable deposit</span><strong class="text-slate-900" data-summary-field="pickup_total">{{ $currencySymbol }} {{ number_format(floor(max(0, $total + $pickupDepositTotal)), 0) }}</strong></div>
            <p class="text-slate-500">The refundable deposit is not charged in today’s checkout payment.</p>
        @endif
    </div>
    @if (!empty($canShowCheckout))
        @include('checkout.v2.actions')
    @endif
</section>
