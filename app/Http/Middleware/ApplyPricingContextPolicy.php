<?php

namespace App\Http\Middleware;

use App\Services\Pricing\PricingContextPolicyService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApplyPricingContextPolicy
{
    public function __construct(private readonly PricingContextPolicyService $policy)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $this->policy->applyToRequest($request);

        return $next($request);
    }
}
