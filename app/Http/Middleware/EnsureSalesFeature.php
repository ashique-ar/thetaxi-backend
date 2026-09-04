<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSalesFeature
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $features = config('sales.features', []);

        if (! array_key_exists($feature, $features) || $features[$feature] !== true) {
            return new JsonResponse([
                'status' => 'error',
                'code' => 'CONFIGURATION_MISSING',
                'message' => 'The requested Sales capability is not enabled.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $next($request);
    }
}
