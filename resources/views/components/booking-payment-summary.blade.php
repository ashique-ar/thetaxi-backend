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

    $paymentRows = [];
    if ($isQuotation) {
        $paymentRows[] = [
            'class' => 'booking-payment-due-row',
            'style' => 'background: #f8fafc;',
            'label' => 'Payment Due Now',
            'value' => 'No payment required',
            'strong' => true,
        ];
    } else {
        if ($paymentType === 'advance') {
            $advanceLabel = 'Amount Paid / Due Now';
            if ($effectiveAdvancePercentage) {
                $advanceLabel .= ' (' . $effectiveAdvancePercentage . '%)';
            }
            $paidNow = floor(max(0, $amountDueNow ?? $amountToPay));
            $balanceDue = floor(max(0, $totalEstimated - ($amountDueNow ?? $amountToPay)));
            $paymentRows[] = [
                'class' => 'booking-payment-due-row',
                'style' => 'background: #eff6ff;',
                'label' => $advanceLabel,
                'value' => $currencySymbol . ' ' . number_format($paidNow, 0),
                'strong' => true,
            ];
            $paymentRows[] = [
                'class' => '',
                'style' => 'background: #eff6ff;',
                'label' => 'Balance Due at Pickup',
                'value' => $currencySymbol . ' ' . number_format($balanceDue, 0),
                'strong' => false,
            ];
        }

        if ($paymentType === 'checkin') {
            $paymentRows[] = [
                'class' => 'booking-payment-due-row',
                'style' => 'background: #fff7ed;',
                'label' => 'Amount Due at Check-in',
                'value' => $currencySymbol . ' ' . number_format(floor(max(0, $totalEstimated)), 0),
                'strong' => true,
            ];
        }

        if ($paymentType !== 'advance' && $paymentType !== 'checkin' && $amountDueNow !== null) {
            $paymentRows[] = [
                'class' => 'booking-payment-due-row',
                'style' => 'background: #fef3c7;',
                'label' => 'Amount Due Now',
                'value' => $currencySymbol . ' ' . number_format(floor(max(0, $amountDueNow)), 0),
                'strong' => true,
            ];
        }

        if ($paymentType !== 'advance' && $paymentType !== 'checkin' && $amountDueNow === null && $paymentStatus === 'paid') {
            $paymentRows[] = [
                'class' => 'booking-payment-due-row',
                'style' => 'background: #f0fdf4;',
                'label' => 'Amount Paid',
                'value' => $currencySymbol . ' ' . number_format(floor(max(0, $amountToPay)), 0),
                'strong' => true,
            ];
        }
    }

    $paymentStatusBadgeClass = 'status-processing';
    $paymentStatusBadgeText = $paymentStatusLabel;
    if ($paymentStatus === 'paid') {
        $paymentStatusBadgeClass = 'status-paid';
        $paymentStatusBadgeText = 'Paid';
    } elseif ($isQuotation) {
        $paymentStatusBadgeClass = 'status-processing';
        $paymentStatusBadgeText = 'Quotation requested';
    } elseif ($paymentStatus === 'pending') {
        $paymentStatusBadgeClass = 'status-pending';
        $paymentStatusBadgeText = 'Pending';
    }
@endphp

<table class="info-table booking-payment-summary" style="width: 100%; border-collapse: collapse; table-layout: fixed;">
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
    <tr class="price-total booking-payment-total-row" style="background-color: #fff7f7; border-top: 2px solid #BF2629; border-bottom: 2px solid #BF2629;">
        <td style="font-size: 16px; font-weight: 800; padding: 12px 0;">{{ $totalLabel }}</td>
        <td style="font-size: 22px; font-weight: 900; color: #BF2629; padding: 12px 0; text-align: right;">
            {{ $currencySymbol }} {{ number_format(floor(max(0, $totalEstimated)), 0) }}
        </td>
    </tr>

    @foreach ($paymentRows as $paymentRow)
        <tr class="{{ $paymentRow['class'] }}" style="{{ $paymentRow['style'] }}">
            <td>
                @if ($paymentRow['strong'])
                    <strong>{{ $paymentRow['label'] }}</strong>
                @else
                    {{ $paymentRow['label'] }}
                @endif
            </td>
            <td>
                @if ($paymentRow['strong'])
                    <strong>{{ $paymentRow['value'] }}</strong>
                @else
                    {{ $paymentRow['value'] }}
                @endif
            </td>
        </tr>
    @endforeach

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
                <span class="status-badge {{ $paymentStatusBadgeClass }}">{{ $paymentStatusBadgeText }}</span>
            </td>
        </tr>
    @endif
</table>
