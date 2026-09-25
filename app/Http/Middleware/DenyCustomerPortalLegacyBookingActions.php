<?php

namespace App\Http\Middleware;

use App\Services\UserContextService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DenyCustomerPortalLegacyBookingActions
{
    public function __construct(private UserContextService $contexts) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $context = $user
            ? $this->contexts->resolveActiveContextFromRequest($user, $request)
            : null;

        if (($context['portal_profile'] ?? null) === 'customer') {
            return response()->json([
                'status' => 'error',
                'message' => 'Customer-context booking access must use customer-scoped portal endpoints.',
            ], 403);
        }

        return $next($request);
    }
}
