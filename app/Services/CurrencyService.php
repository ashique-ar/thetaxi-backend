<?php

namespace App\Services;

use App\Models\Currency;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CurrencyService
{
    private function fractionDigits(string $currencyCode): int
    {
        // Currency codes and rates come from the system's currencies table.
        // The current currency model has no precision setting, so use a
        // consistent generic minor-unit default.
        return 2;
    }

    /**
     * Normalize calculated monetary amounts for customer-facing totals and charges.
     */
    public function normalizeAmount(float|int|string|null $amount): float
    {
        $numericAmount = is_numeric($amount) ? (float) $amount : 0.0;

        return (float) ($numericAmount < 0 ? ceil($numericAmount) : floor($numericAmount));
    }

    /** Round a booked or displayed amount using the currency's minor unit. */
    public function roundAmount(float|int|string|null $amount, string $currencyCode): float
    {
        $numericAmount = is_numeric($amount) ? (float) $amount : 0.0;
        return round($numericAmount, $this->fractionDigits($currencyCode));
    }

    /**
     * Convert amount from one currency to another
     */
    public function convert(float $amount, string $fromCurrency, string $toCurrency): float
    {
        if ($fromCurrency === $toCurrency) {
            return $this->roundAmount($amount, $toCurrency);
        }

        $exchangeRate = $this->getExchangeRate($fromCurrency, $toCurrency);
        return $this->roundAmount($amount * $exchangeRate, $toCurrency);
    }

    /**
     * Get exchange rate between two currencies
     */
    public function getExchangeRate(string $fromCurrency, string $toCurrency): float
    {
        $fromCurrency = strtoupper(trim($fromCurrency));
        $toCurrency = strtoupper(trim($toCurrency));
        if (!$this->isValidCurrency($fromCurrency) || !$this->isValidCurrency($toCurrency)) {
            throw new \RuntimeException("Exchange rate unavailable for {$fromCurrency} to {$toCurrency}");
        }

        if ($fromCurrency === $toCurrency) {
            return 1.0;
        }

        // Version the key so currency admin changes invalidate every pair
        // immediately, including old cached 1:1 fallback values.
        $rateVersion = (int) Cache::get('currency.exchange_rates.v', 0);
        $cacheKey = "exchange_rate.v{$rateVersion}_{$fromCurrency}_{$toCurrency}";
        
        return Cache::remember($cacheKey, 3600, function () use ($fromCurrency, $toCurrency) {
            $fromCurrencyData = Currency::where('code', $fromCurrency)->first();
            $toCurrencyData = Currency::where('code', $toCurrency)->first();

            if (!$fromCurrencyData || !$toCurrencyData) {
                Log::warning("Currency not found for conversion", [
                    'from' => $fromCurrency,
                    'to' => $toCurrency
                ]);
                throw new \RuntimeException("Exchange rate unavailable for {$fromCurrency} to {$toCurrency}");
            }

            // Currency exrates are maintained by the system against its configured base.
            $fromRate = is_numeric($fromCurrencyData->exrate) ? (float) $fromCurrencyData->exrate : 0.0;
            $toRate = is_numeric($toCurrencyData->exrate) ? (float) $toCurrencyData->exrate : 0.0;

            if ($fromRate <= 0 || $toRate <= 0) {
                Log::error('Invalid exchange rate for conversion', [
                    'from' => $fromCurrency,
                    'to' => $toCurrency,
                    'from_rate' => $fromRate,
                    'to_rate' => $toRate,
                ]);
                throw new \RuntimeException("Invalid exchange rate for {$fromCurrency} to {$toCurrency}");
            }

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
        
        $digits = $this->fractionDigits($currencyCode);
        return $symbol . ' ' . number_format($this->roundAmount($amount, $currencyCode), $digits);
    }

    /**
     * Get default currency
     */
    public function getDefaultCurrency(): string
    {
        $configured = strtoupper(trim((string) (
            app(WebsiteSettingsService::class)->get('default_currency')
            ?: config('app.default_currency', '')
        )));
        if ($configured !== '' && $this->hasUsableExchangeRate($configured)) {
            return $configured;
        }

        $available = Currency::query()
            ->whereNotNull('exrate')
            ->where('exrate', '>', 0)
            ->orderBy('code')
            ->value('code');
        if ($available) {
            return strtoupper((string) $available);
        }

        throw new \RuntimeException('No valid currencies are configured in system settings.');
    }

    public function getBookingBaseCurrency(): string
    {
        $settings = app(WebsiteSettingsService::class)->getBookingSettings();
        $configured = strtoupper(trim((string) (
            $settings['booking_base_currency']
            ?? config('booking.base_currency', '')
        )));

        return $configured !== '' && $this->isValidCurrency($configured)
            ? $configured
            : $this->getDefaultCurrency();
    }

    /**
     * Validate currency code
     */
    public function isValidCurrency(string $currencyCode): bool
    {
        return Currency::where('code', strtoupper(trim($currencyCode)))->exists();
    }

    private function hasUsableExchangeRate(string $currencyCode): bool
    {
        return Currency::query()
            ->where('code', strtoupper(trim($currencyCode)))
            ->whereNotNull('exrate')
            ->where('exrate', '>', 0)
            ->exists();
    }

    /**
     * Convert amount from LKR to target currency
     * This is the main conversion function for the public website
     * Exchange rate format: 1 LKR = X units of foreign currency
     */
    public function convertFromLKR(float $lkrAmount, string $targetCurrencyCode): float
    {
        if ($targetCurrencyCode === 'LKR') {
            return $this->roundAmount($lkrAmount, $targetCurrencyCode);
        }

        $targetCurrency = Currency::where('code', $targetCurrencyCode)->first();
        if (!$targetCurrency || !$targetCurrency->exrate || (float)$targetCurrency->exrate <= 0) {
            Log::warning("Invalid currency or exchange rate for conversion", [
                'target_currency' => $targetCurrencyCode,
                'exrate' => $targetCurrency?->exrate
            ]);
            throw new \RuntimeException("Exchange rate unavailable for LKR to {$targetCurrencyCode}");
        }

        $exchangeRate = (float) $targetCurrency->exrate;
        // Since exrate is "1 LKR = X foreign currency", multiply by the rate
        return $this->roundAmount($lkrAmount * $exchangeRate, $targetCurrencyCode);
    }

    /**
     * Convert amount from target currency to LKR
     * Exchange rate format: 1 LKR = X units of foreign currency
     */
    public function convertToLKR(float $amount, string $fromCurrencyCode): float
    {
        if ($fromCurrencyCode === 'LKR') {
            return $this->roundAmount($amount, 'LKR');
        }

        $fromCurrency = Currency::where('code', $fromCurrencyCode)->first();
        if (!$fromCurrency || !$fromCurrency->exrate || (float)$fromCurrency->exrate <= 0) {
            Log::warning("Invalid currency or exchange rate for conversion", [
                'from_currency' => $fromCurrencyCode,
                'exrate' => $fromCurrency?->exrate
            ]);
            throw new \RuntimeException("Exchange rate unavailable for {$fromCurrencyCode} to LKR");
        }

        $exchangeRate = (float) $fromCurrency->exrate;
        // Since exrate is "1 LKR = X foreign currency", divide by the rate to get LKR
        return $this->roundAmount($amount / $exchangeRate, 'LKR');
    }

    /**
     * Get current user's selected currency from session
     */
    public function getSelectedCurrency(): string
    {
        $selected = strtoupper(trim((string) session('selected_currency', '')));
        return $selected !== '' && $this->isValidCurrency($selected)
            ? $selected
            : $this->getDefaultCurrency();
    }

    /**
     * Set user's selected currency in session
     */
    public function setSelectedCurrency(string $currencyCode): void
    {
        $currencyCode = strtoupper(trim($currencyCode));
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
                $convertedPricing[$field] = $this->roundAmount($convertedPricing[$field] * $exchangeRate, $toCurrency);
            }
        }

        // Convert breakdown items
        if (isset($convertedPricing['breakdown']) && is_array($convertedPricing['breakdown'])) {
            foreach ($convertedPricing['breakdown'] as &$item) {
                if (isset($item['amount'])) {
                    $item['amount'] = $this->roundAmount($item['amount'] * $exchangeRate, $toCurrency);
                }
            }
        }

        // Convert base pricing items
        if (isset($convertedPricing['base_pricing']) && is_array($convertedPricing['base_pricing'])) {
            foreach ($convertedPricing['base_pricing'] as &$item) {
                if (isset($item['amount'])) {
                    $item['amount'] = $this->roundAmount($item['amount'] * $exchangeRate, $toCurrency);
                }
                if (isset($item['original_amount'])) {
                    $item['original_amount'] = $this->roundAmount($item['original_amount'] * $exchangeRate, $toCurrency);
                }
            }
        }

        // Convert addon items
        if (isset($convertedPricing['addons_pricing']['addons']) && is_array($convertedPricing['addons_pricing']['addons'])) {
            foreach ($convertedPricing['addons_pricing']['addons'] as &$addon) {
                if (isset($addon['unit_price'])) {
                    $addon['unit_price'] = $this->roundAmount($addon['unit_price'] * $exchangeRate, $toCurrency);
                }
                if (isset($addon['total_price'])) {
                    $addon['total_price'] = $this->roundAmount($addon['total_price'] * $exchangeRate, $toCurrency);
                }
                if (isset($addon['original_price'])) {
                    $addon['original_price'] = $this->roundAmount($addon['original_price'] * $exchangeRate, $toCurrency);
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
