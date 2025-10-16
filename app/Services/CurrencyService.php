<?php

namespace App\Services;

use App\Models\Currency;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CurrencyService
{
    /**
     * Convert amount from one currency to another
     */
    public function convert(float $amount, string $fromCurrency, string $toCurrency): float
    {
        if ($fromCurrency === $toCurrency) {
            return $amount;
        }

        $exchangeRate = $this->getExchangeRate($fromCurrency, $toCurrency);
        return round($amount * $exchangeRate, 2);
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
        
        return $symbol . ' ' . number_format($amount, 2);
    }

    /**
     * Get default currency
     */
    public function getDefaultCurrency(): string
    {
        return config('app.default_currency', 'LKR');
    }

    /**
     * Validate currency code
     */
    public function isValidCurrency(string $currencyCode): bool
    {
        return Currency::where('code', $currencyCode)->exists();
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
                $convertedPricing[$field] = round($convertedPricing[$field] * $exchangeRate, 2);
            }
        }

        // Convert breakdown items
        if (isset($convertedPricing['breakdown']) && is_array($convertedPricing['breakdown'])) {
            foreach ($convertedPricing['breakdown'] as &$item) {
                if (isset($item['amount'])) {
                    $item['amount'] = round($item['amount'] * $exchangeRate, 2);
                }
            }
        }

        // Convert base pricing items
        if (isset($convertedPricing['base_pricing']) && is_array($convertedPricing['base_pricing'])) {
            foreach ($convertedPricing['base_pricing'] as &$item) {
                if (isset($item['amount'])) {
                    $item['amount'] = round($item['amount'] * $exchangeRate, 2);
                }
                if (isset($item['original_amount'])) {
                    $item['original_amount'] = round($item['original_amount'] * $exchangeRate, 2);
                }
            }
        }

        // Convert addon items
        if (isset($convertedPricing['addons_pricing']['addons']) && is_array($convertedPricing['addons_pricing']['addons'])) {
            foreach ($convertedPricing['addons_pricing']['addons'] as &$addon) {
                if (isset($addon['unit_price'])) {
                    $addon['unit_price'] = round($addon['unit_price'] * $exchangeRate, 2);
                }
                if (isset($addon['total_price'])) {
                    $addon['total_price'] = round($addon['total_price'] * $exchangeRate, 2);
                }
                if (isset($addon['original_price'])) {
                    $addon['original_price'] = round($addon['original_price'] * $exchangeRate, 2);
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
