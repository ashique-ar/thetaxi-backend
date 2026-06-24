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

    public function compose(View $view): void
    {
        try {
            $selectedCurrency = $this->currencyService->getSelectedCurrency();
            $availableCurrencies = Cache::remember('display_currencies_for_views', 3600, function () {
                return $this->currencyService->getAvailableCurrenciesForDisplay();
            });

            $view->with([
                'selectedCurrency' => $selectedCurrency,
                'availableCurrencies' => $availableCurrencies,
                'currencySymbol' => getCurrencySymbol($selectedCurrency),
            ]);
        } catch (\Exception $e) {
            $view->with([
                'selectedCurrency' => $this->currencyService->getDefaultCurrency(),
                'availableCurrencies' => [],
                'currencySymbol' => $this->currencyService->getDefaultCurrency(),
            ]);
        }
    }
}
