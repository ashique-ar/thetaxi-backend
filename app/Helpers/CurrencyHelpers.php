<?php

/**
 * Global Currency Helper Functions
 * 
 * These functions provide easy access to currency conversion throughout the application
 * All conversions are based on LKR as the base currency
 */

use App\Services\CurrencyService;

if (!function_exists('convertPrice')) {
    /**
     * Convert price from LKR to current selected currency
     * 
     * @param float $lkrPrice Price in LKR
     * @param string|null $targetCurrency Target currency code (optional, uses session if not provided)
     * @return float Converted price
     */
    function convertPrice(float $lkrPrice, ?string $targetCurrency = null): float
    {
        $currencyService = app(CurrencyService::class);
        $targetCurrency = $targetCurrency ?: $currencyService->getSelectedCurrency();
        
        return $currencyService->convertFromLKR($lkrPrice, $targetCurrency);
    }
}

if (!function_exists('formatPrice')) {
    /**
     * Format price with currency symbol in current selected currency
     * 
     * @param float $lkrPrice Price in LKR
     * @param string|null $targetCurrency Target currency code (optional, uses session if not provided)
     * @return string Formatted price with currency symbol
     */
    function formatPrice(float $lkrPrice, ?string $targetCurrency = null): string
    {
        $currencyService = app(CurrencyService::class);
        $targetCurrency = $targetCurrency ?: $currencyService->getSelectedCurrency();
        
        $convertedPrice = $currencyService->convertFromLKR($lkrPrice, $targetCurrency);
        return $currencyService->formatAmount($convertedPrice, $targetCurrency);
    }
}

if (!function_exists('getSelectedCurrency')) {
    /**
     * Get currently selected currency code
     * 
     * @return string Currency code
     */
    function getSelectedCurrency(): string
    {
        return app(CurrencyService::class)->getSelectedCurrency();
    }
}

if (!function_exists('getCurrencySymbol')) {
    /**
     * Get currency symbol for given currency code
     * 
     * @param string|null $currencyCode Currency code (optional, uses selected if not provided)
     * @return string Currency symbol
     */
    function getCurrencySymbol(?string $currencyCode = null): string
    {
        $currencyCode = $currencyCode ?: getSelectedCurrency();
        $currency = \App\Models\Currency::where('code', $currencyCode)->first();
        return $currency?->symbol ?: $currencyCode;
    }
}

if (!function_exists('getAvailableCurrencies')) {
    /**
     * Get all available currencies for display
     * 
     * @return array Array of currency data
     */
    function getAvailableCurrencies(): array
    {
        return app(CurrencyService::class)->getAvailableCurrenciesForDisplay();
    }
}

if (!function_exists('getBookingDisplayCurrency')) {
    /**
     * Resolve the currency of the monetary snapshot stored for a booking.
     *
     * Public checkout stores converted amounts together with display_currency in
     * workflow_data. Prefer that snapshot currency so delayed emails cannot pair
     * a converted amount with a later/default booking currency label.
     */
    function getBookingDisplayCurrency(object $booking): string
    {
        $workflowData = $booking->workflow_data ?? [];

        if (is_string($workflowData)) {
            $workflowData = json_decode($workflowData, true);
        }

        $snapshotCurrency = is_array($workflowData)
            ? ($workflowData['display_currency'] ?? null)
            : null;
        $currencyCode = strtoupper(trim((string) ($snapshotCurrency ?: ($booking->currency ?? 'LKR'))));

        return preg_match('/^[A-Z]{3}$/', $currencyCode) ? $currencyCode : 'LKR';
    }
}
