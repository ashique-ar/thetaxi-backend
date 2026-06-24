<?php

namespace App\Services;

use App\Models\Currency;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CurrencyService
{
    /**
     * Normalize calculated monetary amounts for customer-facing totals and charges.
     */
    public function normalizeAmount(float|int|string|null $amount): float
    {
        $numericAmount = is_numeric($amount) ? (float) $amount : 0.0;

        return (float) ($numericAmount < 0 ? ceil($numericAmount) : floor($numericAmount));
    }

    /**
     * Convert amount from one currency to another
     */
    public function convert(float $amount, string $fromCurrency, string $toCurrency): float
    {
        if ($fromCurrency === $toCurrency) {
            return $this->normalizeAmount($amount);
        }

        $exchangeRate = $this->getExchangeRate($fromCurrency, $toCurrency);
        return $this->normalizeAmount($amount * $exchangeRate);
    }

    /**
     * Get exchange rate between two currencies
     */
    public function getExchangeRate(string $fromCurrency, string $toCurrency): float
    {
        if ($fromCurrency === $toCurrency) {
            return 1.0;
        }

        $cacheKey = "exchange_rate_{$fromCurrency}_{$toCurrency}";
        
        return Cache::remember($cacheKey, 3600, function () use ($fromCurrency, $toCurrency) {
            $fromCurrencyData = Currency::where('code', $fromCurrency)->first();
            $toCurrencyData = Currency::where('code', $toCurrency)->first();

            if (!$fromCurrencyData || !$toCurrencyData) {
                Log::warning("Currency not found for conversion", [
                    'from' => $fromCurrency,
                    'to' => $toCurrency
                ]);
                return 1.0;
            }

            // Get rates relative to base currency (assuming LKR is base)
            $fromRate = (float) ($fromCurrencyData->exrate ?? 1.0);
            $toRate = (float) ($toCurrencyData->exrate ?? 1.0);

            if ($fromRate <= 0) $fromRate = 1.0;
            if ($toRate <= 0) $toRate = 1.0;

            // Convert: amount_in_from -> amount_in_base -> amount_in_to
            return $toRate / $fromRate;
        });
    }

    /**
     * Get all available currencies
     */
    public function getAvailableCurrencies(): array
    {
        return Cache::remember('available_currencies', 3600, function () {
            return Currency::select('id', 'code', 'name', 'symbol', 'exrate')
                ->orderBy('code')
                ->get()
                ->toArray();
        });
    }

    /**
     * Format amount with currency
     */
    public function formatAmount(float $amount, string $currencyCode): string
    {
        $currency = Currency::where('code', $currencyCode)->first();
        $symbol = $currency ? $currency->symbol : $currencyCode;
        
        return $symbol . ' ' . number_format($this->normalizeAmount($amount), 0);
    }

    /**
     * Get default currency
     */
    public function getDefaultCurrency(): string
    {
        $currency = app(WebsiteSettingsService::class)->get('default_currency', config('app.default_currency', 'LKR'));

        return strtoupper(trim((string) ($currency ?: 'LKR')));
    }

    /**
     * Validate currency code
     */
    public function isValidCurrency(string $currencyCode): bool
    {
        return Currency::where('code', $currencyCode)->exists();
    }

    /**
     * Convert amount from LKR to target currency
     * This is the main conversion function for the public website
     * Exchange rate format: 1 LKR = X units of foreign currency
     */
    public function convertFromLKR(float $lkrAmount, string $targetCurrencyCode): float
    {
        if ($targetCurrencyCode === 'LKR') {
            return $this->normalizeAmount($lkrAmount);
        }

        $targetCurrency = Currency::where('code', $targetCurrencyCode)->first();
        if (!$targetCurrency || !$targetCurrency->exrate || (float)$targetCurrency->exrate <= 0) {
            Log::warning("Invalid currency or exchange rate for conversion", [
                'target_currency' => $targetCurrencyCode,
                'exrate' => $targetCurrency?->exrate
            ]);
            return $this->normalizeAmount($lkrAmount); // Return original amount if conversion fails
        }

        $exchangeRate = (float) $targetCurrency->exrate;
        // Since exrate is "1 LKR = X foreign currency", multiply by the rate
        return $this->normalizeAmount($lkrAmount * $exchangeRate);
    }

    /**
     * Convert amount from target currency to LKR
     * Exchange rate format: 1 LKR = X units of foreign currency
     */
    public function convertToLKR(float $amount, string $fromCurrencyCode): float
    {
        if ($fromCurrencyCode === 'LKR') {
            return $this->normalizeAmount($amount);
        }

        $fromCurrency = Currency::where('code', $fromCurrencyCode)->first();
        if (!$fromCurrency || !$fromCurrency->exrate || (float)$fromCurrency->exrate <= 0) {
            Log::warning("Invalid currency or exchange rate for conversion", [
                'from_currency' => $fromCurrencyCode,
                'exrate' => $fromCurrency?->exrate
            ]);
            return $this->normalizeAmount($amount); // Return original amount if conversion fails
        }

        $exchangeRate = (float) $fromCurrency->exrate;
        // Since exrate is "1 LKR = X foreign currency", divide by the rate to get LKR
        return $this->normalizeAmount($amount / $exchangeRate);
    }

    /**
     * Get current user's selected currency from session
     */
    public function getSelectedCurrency(): string
    {
        return session('selected_currency', $this->getDefaultCurrency());
    }

    /**
     * Set user's selected currency in session
     */
    public function setSelectedCurrency(string $currencyCode): void
    {
        if ($this->isValidCurrency($currencyCode)) {
            session(['selected_currency' => $currencyCode]);
        }
    }

    /**
     * Format amount with current selected currency
     */
    public function formatAmountInSelectedCurrency(float $lkrAmount): string
    {
        $selectedCurrency = $this->getSelectedCurrency();
        $convertedAmount = $this->convertFromLKR($lkrAmount, $selectedCurrency);
        return $this->formatAmount($convertedAmount, $selectedCurrency);
    }

    /**
     * Get available currencies for display (only those with valid exchange rates)
     */
    public function getAvailableCurrenciesForDisplay(): array
    {
        return Cache::remember('display_currencies', 3600, function () {
            return Currency::select('id', 'code', 'name', 'symbol', 'exrate')
                ->whereNotNull('exrate')
                ->where('exrate', '>', 0)
                ->orderBy('code')
                ->get()
                ->toArray();
        });
    }

    /**
     * Convert pricing structure to different currency
     */
    public function convertPricingStructure(array $pricing, string $fromCurrency, string $toCurrency): array
    {
        if ($fromCurrency === $toCurrency) {
            return $pricing;
        }

        $exchangeRate = $this->getExchangeRate($fromCurrency, $toCurrency);
        $convertedPricing = $pricing;

        // Convert main amounts
        $amountFields = ['total_amount', 'base_amount', 'subtotal', 'addons_total', 'discount_amount', 'tax_amount', 'total'];
        
        foreach ($amountFields as $field) {
            if (isset($convertedPricing[$field])) {
                $convertedPricing[$field] = $this->normalizeAmount($convertedPricing[$field] * $exchangeRate);
            }
        }

        // Convert breakdown items
        if (isset($convertedPricing['breakdown']) && is_array($convertedPricing['breakdown'])) {
            foreach ($convertedPricing['breakdown'] as &$item) {
                if (isset($item['amount'])) {
                    $item['amount'] = $this->normalizeAmount($item['amount'] * $exchangeRate);
                }
            }
        }

        // Convert base pricing items
        if (isset($convertedPricing['base_pricing']) && is_array($convertedPricing['base_pricing'])) {
            foreach ($convertedPricing['base_pricing'] as &$item) {
                if (isset($item['amount'])) {
                    $item['amount'] = $this->normalizeAmount($item['amount'] * $exchangeRate);
                }
                if (isset($item['original_amount'])) {
                    $item['original_amount'] = $this->normalizeAmount($item['original_amount'] * $exchangeRate);
                }
            }
        }

        // Convert addon items
        if (isset($convertedPricing['addons_pricing']['addons']) && is_array($convertedPricing['addons_pricing']['addons'])) {
            foreach ($convertedPricing['addons_pricing']['addons'] as &$addon) {
                if (isset($addon['unit_price'])) {
                    $addon['unit_price'] = $this->normalizeAmount($addon['unit_price'] * $exchangeRate);
                }
                if (isset($addon['total_price'])) {
                    $addon['total_price'] = $this->normalizeAmount($addon['total_price'] * $exchangeRate);
                }
                if (isset($addon['original_price'])) {
                    $addon['original_price'] = $this->normalizeAmount($addon['original_price'] * $exchangeRate);
                }
            }
        }

        // Update currency information
        $convertedPricing['currency'] = $toCurrency;
        $convertedPricing['exchange_rate'] = $exchangeRate;
        $convertedPricing['original_currency'] = $fromCurrency;

        return $convertedPricing;
    }
}
