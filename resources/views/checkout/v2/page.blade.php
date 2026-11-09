@extends('layouts.app')

@section('title', 'Checkout')
@section('seo_exact_title', 'true')
@push('meta')
    @include('partials.seo', ['seoOverride' => ['is_indexable' => false]])
@endpush
@section('content')
    @php
        // Cart data is passed from controller
        $cart = $cartData['items'] ?? [];
        $tourOnlyCheckout = count($cart) === 1 && !empty(collect($cart)->first()['is_tour']);
        $totals = $cartData['totals'] ?? [];
        $currencySymbol = $cartData['currency_symbol'] ?? getCurrencySymbol();
        $displayCurrency = $cartData['currency'] ?? getSelectedCurrency();

        $subtotal = $totals['subtotal'] ?? 0;
        $serviceFee = $totals['service_fee'] ?? 0;
        $addonCharges = $totals['addon_charges'] ?? 0;
        $tax = $totals['tax'] ?? 0;
        $taxLabel = $totals['tax_label'] ?? 'NBT';
        $vat = $totals['vat'] ?? 0;
        $vatLabel = $totals['vat_label'] ?? 'VAT';
        $discount = $totals['coupon_discount'] ?? 0;
        $total = $totals['total'] ?? 0;

        $advancePercentage = $advancePercentage ?? 50;
        $advanceMinAmount = max(0, (float) ($advanceMinAmount ?? 0));
        $advancePaymentEnabled = $advancePaymentEnabled ?? true;

        // Fetch settings from database
        try {
            $taxPercentage = \App\Models\Website\WebsiteSetting::getValue('tax_rate', null);
            if ($taxPercentage === null) {
                $taxPercentage = \App\Models\Website\WebsiteSetting::getValue('tax_percentage', 18);
            }
            $vatPercentage = \App\Models\Website\WebsiteSetting::getValue('vat_rate', null);
            if ($vatPercentage === null) {
                $vatPercentage = \App\Models\Website\WebsiteSetting::getValue('vat_percentage', 0);
            }

            // Normalize for display (support 0.18 or 18 formats)
            $taxPercentage = (float) $taxPercentage;
            $taxPercentageLabel =
                $taxPercentage > 0 && $taxPercentage <= 1 ? round($taxPercentage * 100, 2) : $taxPercentage;
            $vatPercentage = (float) $vatPercentage;
            $vatPercentageLabel =
                $vatPercentage > 0 && $vatPercentage <= 1 ? round($vatPercentage * 100, 2) : $vatPercentage;
        } catch (Exception $e) {
            \Log::error('Error fetching website settings: ' . $e->getMessage());
            $taxPercentage = 18;
            $vatPercentage = 0;

            // Normalize fallback labels
            $taxPercentage = (float) $taxPercentage;
            $taxPercentageLabel =
                $taxPercentage > 0 && $taxPercentage <= 1 ? round($taxPercentage * 100, 2) : $taxPercentage;
            $vatPercentage = (float) $vatPercentage;
            $vatPercentageLabel =
                $vatPercentage > 0 && $vatPercentage <= 1 ? round($vatPercentage * 100, 2) : $vatPercentage;
        }

        // The minimum can make the advance more than the configured percentage.
        $advancePaymentAmount = min($total, max($total * ($advancePercentage / 100), $advanceMinAmount));
        $advanceActualPercentage = $total > 0 ? round(($advancePaymentAmount / $total) * 100, 2) : 0;

        // Payment amount based on type
        $paymentAmount = match ($paymentType) {
            'advance' => $advancePaymentAmount,
            'quotation' => 0,
            default => $total,
        };
        $paymentAmount = floor(max(0, $paymentAmount));
    @endphp
    @include('checkout.v2.' . (get_active_theme() === 'default' ? 'theme-01' : get_active_theme()))
@endsection
@push('styles')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&amp;display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/intl-tel-input@17/build/css/intlTelInput.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    <link rel="stylesheet" href="{{ assetVersion('assets/css/checkout-v2.css') }}">
@endpush
@include('checkout.v2.behavior')
