<?php

namespace App\Http\Controllers;

use App\Services\CurrencyService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class CurrencyController extends Controller
{
    protected CurrencyService $currencyService;

    public function __construct(CurrencyService $currencyService)
    {
        $this->currencyService = $currencyService;
    }

    /**
     * Switch currency and return to previous page
     */
    public function switch(Request $request): RedirectResponse|JsonResponse
    {
        $currencyCode = $request->input('currency');
        
        if (!$currencyCode || !$this->currencyService->isValidCurrency($currencyCode)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid currency code'
                ], 400);
            }
            
            return redirect()->back()->with('error', 'Invalid currency selected');
        }

        // Set the selected currency in session
        $this->currencyService->setSelectedCurrency($currencyCode);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Currency updated successfully',
                'currency' => $currencyCode,
                'symbol' => getCurrencySymbol($currencyCode)
            ]);
        }

        return redirect()->back()->with('success', 'Currency updated to ' . $currencyCode);
    }

    /**
     * Get available currencies for AJAX requests
     */
    public function available(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'currencies' => $this->currencyService->getAvailableCurrenciesForDisplay(),
            'selected' => $this->currencyService->getSelectedCurrency()
        ]);
    }
}