<?php

namespace App\Http\ViewComposers;

use App\Services\CurrencyService;
use Illuminate\View\View;
use Illuminate\Support\Facades\Cache;

class CurrencyViewComposer
{
    protected CurrencyService $currencyService;

    public function __construct(CurrencyService $currencyService)
    {
        $this->currencyService = $currencyService;
    }

    /**
     * Bind data to the view.
     * CRITICAL FIX: Cache currency data to avoid service calls on every page
     */
    public function compose(View $view): void
    {
        // Cache currency data for 1 hour to avoid repeated service calls
        $currencyData = Cache::remember('global_currency_data', 3600, function() {
            try {
                return [
                    'selectedCurrency' => $this->currencyService->getSelectedCurrency(),
                    'availableCurrencies' => $this->currencyService->getAvailableCurrenciesForDisplay(),
                    'currencySymbol' => getCurrencySymbol()
                ];
            } catch (\Exception $e) {
                return [
                    'selectedCurrency' => 'USD',
                    'availableCurrencies' => ['USD' => 'US Dollar'],
                    'currencySymbol' => '$'
                ];
            }
        });
        
        $view->with($currencyData);
    }
}