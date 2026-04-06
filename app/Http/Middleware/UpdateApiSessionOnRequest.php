<?php

namespace App\Http\Middleware;

use App\Services\CurrencyService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class UpdateApiSessionOnRequest
{
    public function __construct(protected CurrencyService $currencyService)
    {
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        try {
            $requestedCurrency = strtoupper((string) (
                $request->header('X-Currency-Code')
                ?: $request->header('X-Selected-Currency')
                ?: $request->header('X-Display-Currency')
                ?: $request->input('currency')
                ?: ''
            ));

            if ($requestedCurrency !== '' && $this->currencyService->isValidCurrency($requestedCurrency)) {
                $this->currencyService->setSelectedCurrency($requestedCurrency);

                // Keep request payload aligned for endpoints that read request('currency')
                // but where frontend sends currency context via headers.
                if (!$request->filled('currency')) {
                    $request->merge(['currency' => $requestedCurrency]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Failed applying selected currency from request headers: ' . $e->getMessage());
        }

        $response = $next($request);

        try {
            $user = $request->user();
            if ($user) {
                $tokenModel = null;

                // Passport: token() returns the access token model when using token guard
                if (method_exists($user, 'token')) {
                    $tokenModel = $user->token();
                }

                // currentAccessToken() available
                if (!$tokenModel && method_exists($user, 'currentAccessToken')) {
                    $tokenModel = $user->currentAccessToken();
                }

                if ($tokenModel && $tokenModel->id) {
                    $apiSession = \App\Models\ApiSession::where('token_id', $tokenModel->id)->latest()->first();
                    if ($apiSession) {
                        $apiSession->last_active = now();
                        // Prefer client provided IP and location if present
                        $apiSession->ip_address = $request->input('client_ip', $request->ip());
                        if ($request->filled('client_location')) {
                            $apiSession->location = $request->input('client_location');
                        }
                        $apiSession->save();

                        // Mark this as current session and clear other 'current' flags for same user/token
                        \App\Models\ApiSession::where('user_id', $user->id)
                            ->where('id', '!=', $apiSession->id)
                            ->update(['current' => false]);

                        $apiSession->current = true;
                        $apiSession->save();
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Failed updating api_session last_active: ' . $e->getMessage());
        }

        return $response;
    }
}
