@props([
    'booking',
    'currencySymbol' => 'LKR',
    'advancePercentage' => null,
    'amountDue' => null,
    'showMethod' => true,
    'showStatus' => true,
])

@php
    $bookingAddons = collect($booking->bookingAddons ?? []);
    $normalAddonCharges = (float) $bookingAddons
        ->reject(fn ($addon) => (bool) ($addon->is_milage ?? false))
        ->sum('amount');
    $extraKmCharges = (float) $bookingAddons
        ->filter(fn ($addon) => (bool) ($addon->is_milage ?? false))
        ->sum('amount');

    if ($extraKmCharges <= 0) {
        foreach ($booking->bookingItems ?? [] as $bookingItem) {
            $metadata = is_array($bookingItem->metadata ?? null) ? $bookingItem->metadata : [];
            $extraKmCharges += (float) ($metadata['extra_km_total'] ?? 0);

            if (empty($metadata['extra_km_total'])) {
                $customizations = is_array($bookingItem->customizations ?? null) ? $bookingItem->customizations : [];
                foreach ($customizations as $customization) {
                    if (($customization['type'] ?? null) === 'extra_km') {
                        $extraKmCharges += (float) ($customization['total_cost'] ?? ($customization['total'] ?? 0));
                    }
                }
            }
        }
    }

    if ($extraKmCharges <= 0) {
        $workflow = is_string($booking->workflow_data ?? null)
            ? json_decode($booking->workflow_data, true)
            : $booking->workflow_data ?? [];
        $workflow = is_array($workflow) ? $workflow : [];
        foreach ($workflow['cart_items'] ?? [] as $cartItem) {
            $extraKmCharges += (float) ($cartItem['extra_km']['total_cost'] ?? 0);
        }
    }

    $taxRateSetting = \App\Models\Website\WebsiteSetting::getValue(
        'tax_rate',
        \App\Models\Website\WebsiteSetting::getValue('tax_percentage', config('booking.tax.rate', 2.5)),
    );
    $vatRateSetting = \App\Models\Website\WebsiteSetting::getValue(
        'vat_rate',
        \App\Models\Website\WebsiteSetting::getValue('vat_percentage', config('booking.vat.rate', 18)),
    );
    $taxRateDisplay = $taxRateSetting > 0 && $taxRateSetting <= 1 ? round($taxRateSetting * 100, 2) : $taxRateSetting;
    $vatRateDisplay = $vatRateSetting > 0 && $vatRateSetting <= 1 ? round($vatRateSetting * 100, 2) : $vatRateSetting;

    $totalEstimated = (float) ($booking->total_estimated ?? 0);
    $amountToPay = (float) ($booking->amount_to_pay ?? $totalEstimated);
    $amountPaid = (float) ($booking->amount_paid ?? 0);
    $amountDueNow = $amountDue !== null ? (float) $amountDue : null;
    $paymentStatus = $booking->payment_status ?? null;
    $paymentType = $booking->payment_type ?? null;
    $isQuotation = ($booking->status ?? null) === 'quotation_requested' || $paymentType === 'quotation';
    $effectiveAdvancePercentage = $advancePercentage;
    if ($effectiveAdvancePercentage === null && $paymentType === 'advance' && $totalEstimated > 0) {
        $effectiveAdvancePercentage = round(($amountToPay / $totalEstimated) * 100, 2);
    }

    $totalLabel = $isQuotation ? 'Estimated Quotation Total' : 'Total Amount';
    $paymentMethodLabel = match ($booking->payment_method ?? null) {
        'webxpay', 'online' => 'Online Payment',
        'bank_transfer' => 'Bank Transfer',
        'online_banking' => 'Online Banking',
        null => $isQuotation ? 'Not required for quotation' : 'N/A',
        default => ucwords(str_replace('_', ' ', (string) $booking->payment_method)),
    };
    $paymentStatusLabel = $isQuotation
        ? 'Quotation requested'
        : ucwords(str_replace('_', ' ', (string) ($paymentStatus ?? 'pending')));
@endphp

<table class="info-table booking-payment-summary" style="width: 100%; border-collapse: collapse;">
    <tr>
        <td>Subtotal</td>
        <td>{{ $currencySymbol }} {{ number_format(floor(max(0, (float) ($booking->base_amount ?? 0))), 0) }}</td>
    </tr>
    @if ($normalAddonCharges > 0)
        <tr>
            <td>Add-on Charges</td>
            <td>{{ $currencySymbol }} {{ number_format(floor(max(0, $normalAddonCharges)), 0) }}</td>
        </tr>
    @endif
    @if ($extraKmCharges > 0)
        <tr>
            <td>Extra KM Charges</td>
            <td>{{ $currencySymbol }} {{ number_format(floor(max(0, $extraKmCharges)), 0) }}</td>
        </tr>
    @endif
    @if (($booking->service_fee ?? 0) > 0)
        <tr>
            <td>Service Fee</td>
            <td>{{ $currencySymbol }} {{ number_format(floor(max(0, (float) $booking->service_fee)), 0) }}</td>
        </tr>
    @endif
    @if (($booking->tax_amount ?? 0) > 0)
        <tr>
            <td>{{ config('booking.tax.label', 'NBT') }} ({{ $taxRateDisplay }}%)</td>
            <td>{{ $currencySymbol }} {{ number_format(floor(max(0, (float) $booking->tax_amount)), 0) }}</td>
        </tr>
    @endif
    @if (($booking->vat_amount ?? 0) > 0)
        <tr>
            <td>{{ config('booking.vat.label', 'VAT') }} ({{ $vatRateDisplay }}%)</td>
            <td>{{ $currencySymbol }} {{ number_format(floor(max(0, (float) $booking->vat_amount)), 0) }}</td>
        </tr>
    @endif
    @if (($booking->discount_amount ?? 0) > 0)
        <tr style="color: #16a34a;">
            <td>Discount</td>
            <td>-{{ $currencySymbol }} {{ number_format(floor(max(0, (float) $booking->discount_amount)), 0) }}</td>
        </tr>
    @endif
    <tr class="price-total booking-payment-total-row" style="background-color: #fff4f4; border-top: 2px solid #BF2629; border-bottom: 2px solid #BF2629;">
        <td style="font-size: 17px; font-weight: 800; padding: 16px 0;">{{ $totalLabel }}</td>
        <td style="font-size: 22px; font-weight: 900; color: #BF2629; padding: 16px 0; text-align: right;">
            {{ $currencySymbol }} {{ number_format(floor(max(0, $totalEstimated)), 0) }}
        </td>
    </tr>

    @if ($isQuotation)
        <tr class="booking-payment-due-row" style="background: #f8fafc;">
            <td><strong>Payment Due Now</strong></td>
            <td><strong>No payment required</strong></td>
        </tr>
    @elseif ($paymentType === 'advance')
        <tr class="booking-payment-due-row" style="background: #eff6ff;">
            <td><strong>Amount Paid / Due Now@if ($effectiveAdvancePercentage) ({{ $effectiveAdvancePercentage }}%)@endif</strong></td>
            <td><strong>{{ $currencySymbol }} {{ number_format(floor(max(0, $amountDueNow ?? $amountToPay)), 0) }}</strong></td>
        </tr>
        <tr style="background: #eff6ff;">
            <td>Balance Due at Pickup</td>
            <td>{{ $currencySymbol }} {{ number_format(floor(max(0, $totalEstimated - ($amountDueNow ?? $amountToPay))), 0) }}</td>
        </tr>
    @elseif ($paymentType === 'checkin')
        <tr class="booking-payment-due-row" style="background: #fff7ed;">
            <td><strong>Amount Due at Check-in</strong></td>
            <td><strong>{{ $currencySymbol }} {{ number_format(floor(max(0, $totalEstimated)), 0) }}</strong></td>
        </tr>
    @elseif ($amountDueNow !== null)
        <tr class="booking-payment-due-row" style="background: #fef3c7;">
            <td><strong>Amount Due Now</strong></td>
            <td><strong>{{ $currencySymbol }} {{ number_format(floor(max(0, $amountDueNow)), 0) }}</strong></td>
        </tr>
    @elseif ($paymentStatus === 'paid')
        <tr class="booking-payment-due-row" style="background: #f0fdf4;">
            <td><strong>Amount Paid</strong></td>
            <td><strong>{{ $currencySymbol }} {{ number_format(floor(max(0, $amountToPay)), 0) }}</strong></td>
        </tr>
    @endif

    @if ($showMethod)
        <tr>
            <td>Payment Method</td>
            <td>{{ $paymentMethodLabel }}</td>
        </tr>
    @endif
    @if ($showStatus)
        <tr>
            <td>Payment Status</td>
            <td>
                @if ($paymentStatus === 'paid')
                    <span class="status-badge status-paid">Paid</span>
                @elseif ($isQuotation)
                    <span class="status-badge status-processing">Quotation requested</span>
                @elseif ($paymentStatus === 'pending')
                    <span class="status-badge status-pending">Pending</span>
                @else
                    <span class="status-badge status-processing">{{ $paymentStatusLabel }}</span>
                @endif
            </td>
        </tr>
    @endif
</table>
