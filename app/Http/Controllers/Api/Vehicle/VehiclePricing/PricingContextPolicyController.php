<?php

namespace App\Http\Controllers\Api\Vehicle\VehiclePricing;

use App\Http\Controllers\Controller;
use App\Services\Pricing\PricingContextPolicyService;
use Illuminate\Http\JsonResponse;

class PricingContextPolicyController extends Controller
{
    public function __construct()
    {
        $this->middleware(
            'permission:business-settings.view|business-settings.edit|vehicle-group-pricing.view|vehicle-pricing-slabs.view|vehicle-pricing-common-rates.view|vehicle-pricing-calculations.view|km-range-pricing.view|price-adjustments.view|service-types.view|bookings.view|bookings.create|create_bookings'
        );
    }

    public function show(PricingContextPolicyService $policy): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => $policy->describe(),
        ]);
    }
}
