<?php

namespace App\Http\ViewComposers;

use App\Services\CurrencyService;
use Illuminate\View\View;

class CurrencyViewComposer
{
    protected CurrencyService $currencyService;

    public function __construct(CurrencyService $currencyService)
    {
        $this->currencyService = $currencyService;
    }

    /**
     * Bind data to the view.
     */
    public function compose(View $view): void
    {
        $view->with([
            'selectedCurrency' => $this->currencyService->getSelectedCurrency(),
            'availableCurrencies' => $this->currencyService->getAvailableCurrenciesForDisplay(),
            'currencySymbol' => getCurrencySymbol()
        ]);
    }
}