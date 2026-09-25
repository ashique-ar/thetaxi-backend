<?php

namespace App\Http\Middleware;

use App\Services\UserContextService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keep shared booking-flow APIs usable for customer request creation while
 * withholding quote, pricing, and operational inventory details.
 */
class RedactCustomerPortalBookingData
{
    private const HIDDEN_KEYS = [
        'pricing',
        'pricing_info',
        'pricing_breakdown',
        'price_breakdown',
        'available_count',
        'total_count',
        'available_vehicles',
        'available_vehicle_count',
        'total_vehicle_count',
        'availability_count',
        'available_resource_count',
        'remaining_capacity',
        'disabled_reason',
    ];

    public function __construct(private UserContextService $contexts) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $context = $user
            ? $this->contexts->resolveActiveContextFromRequest($user, $request)
            : null;

        $response = $next($request);

        if (($context['portal_profile'] ?? null) !== 'customer' || !$response instanceof JsonResponse) {
            return $response;
        }

        $payload = $response->getData(true);
        if (is_array($payload)) {
            $response->setData($this->redact($payload));
        }

        return $response;
    }

    private function redact(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_string($key) && $this->isRestrictedKey(strtolower($key))) {
                unset($value[$key]);
                continue;
            }

            if (is_array($item)) {
                $value[$key] = $this->redact($item);
            }
        }

        return $value;
    }

    private function isRestrictedKey(string $key): bool
    {
        if (in_array($key, self::HIDDEN_KEYS, true)) {
            return true;
        }

        foreach (['price', 'amount', 'fare', 'cost', 'discount', 'deposit', 'payment', 'balance', 'subtotal'] as $term) {
            if (str_contains($key, $term)) {
                return true;
            }
        }

        if ($key === 'tax' || str_starts_with($key, 'tax_') || str_ends_with($key, '_tax')) {
            return true;
        }

        if (($key === 'rate' || str_ends_with($key, '_rate') || str_starts_with($key, 'rate_')) && $key !== 'rate_type') {
            return true;
        }

        if ($key === 'total' || (str_starts_with($key, 'total_') && !str_contains($key, 'count'))) {
            return true;
        }

        return str_ends_with($key, '_total') && !str_contains($key, 'count');
    }
}
