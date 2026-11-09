@props(['booking', 'currency' => null])
@php
    $depositLkr = collect($booking->bookingItems ?? [])->sum(function ($item) {
        $metadata = is_array($item->metadata ?? null) ? $item->metadata : [];
        $snapshot = is_array($item->workflow_config_snapshot ?? null) ? $item->workflow_config_snapshot : [];
        $requiresDeposit = (bool) ($metadata['requires_refundable_deposit']
            ?? $snapshot['requires_refundable_deposit']
            ?? $item->serviceType?->requires_refundable_deposit);
        if (! $requiresDeposit) return 0;
        return max(0, (float) ($metadata['refundable_deposit_lkr'] ?? $item->vehicleGroup?->refundable_deposit ?? 0));
    });
    $currency = strtoupper((string) ($currency ?: getBookingDisplayCurrency($booking)));
    $currencyService = app(\App\Services\CurrencyService::class);
    $deposit = $currencyService->convertFromLKR($depositLkr, $currency);
    $bookingTotal = $currencyService->convert((float) ($booking->total_estimated ?? 0), strtoupper((string) ($booking->currency ?: 'LKR')), $currency);
@endphp
@if ($deposit > 0)
    <div class="mt-3 p-3" style="background:#fff7ed;border:1px solid #fdba74;border-radius:8px;">
        <div style="display:flex;justify-content:space-between;gap:12px;"><strong>Refundable deposit due when you collect the vehicle</strong><strong>{{ $currency }} {{ number_format(floor($deposit), 0) }}</strong></div>
        <div style="display:flex;justify-content:space-between;gap:12px;margin-top:6px;"><strong>Booking total including refundable deposit</strong><strong>{{ $currency }} {{ number_format(floor($bookingTotal + $deposit), 0) }}</strong></div>
        <small>The deposit is refundable and is payable at vehicle collection. It is separate from the booking payment above.</small>
    </div>
@endif
