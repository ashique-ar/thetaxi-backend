<?php

namespace App\Http\Controllers\Api\Booking\Traits;

use App\Models\Booking\Booking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

trait BookingPricingTrait
{
    public function calculatePricing(Request $request): JsonResponse
    {
        try {
            $params = $this->bookingFlowService->normalizeDynamicCalculationParams($request->all());
            $requirements = $this->bookingFlowService->getDynamicCalculationRequirements($params);
            $usesDropoffTime = (bool) ($requirements['uses_dropoff_time'] ?? true);
            $pickupRequired = (bool) ($requirements['pickup_location_required'] ?? true);
            $dropoffRequired = (bool) ($requirements['dropoff_location_required'] ?? true);

            $rules = [
                'customer_id' => 'nullable|string',
                'is_corporate_booking' => 'sometimes|boolean',
                'corporate_account_id' => 'nullable|uuid|exists:corporates,id',
                'employee_id' => 'nullable|uuid|exists:users,id',
                'corporate_department_id' => 'nullable|uuid|exists:corporate_departments,id',
                'corporate_division_id' => 'nullable|uuid|exists:corporate_divisions,id',
                'cost_center' => 'nullable|string|max:255',
                'project_code' => 'nullable|string|max:255',
                'corporate_contact' => 'nullable|array',
                'corporate_contact.name' => 'nullable|string|max:255',
                'corporate_contact.email' => 'nullable|email|max:255',
                'corporate_contact.phone' => 'nullable|string|max:50',
                'service_type' => 'required|string',
                'vehicle_group_id' => 'sometimes|string',
                'vehicle_groups' => 'sometimes|array',
                'vehicle_groups.*.id' => 'required|string',
                'vehicle_groups.*.quantity' => 'required|integer|min:1',
                'vehicles' => 'sometimes|array',
                'vehicles.*.id' => 'required|string',
                'vehicles.*.group_id' => 'required|string',
                'drivers' => 'sometimes|array',
                'drivers.*.id' => 'required|string',
                'vehicle_driver_assignments' => 'sometimes|array',
                'vehicle_driver_assignments.*.vehicle_id' => 'required|string',
                'vehicle_driver_assignments.*.driver_id' => 'nullable|string',
                'booking_items' => 'sometimes|array',
                'from_date' => 'required_without:booking_items|date',
                'from_time' => 'required_without:booking_items|string',
                'pickup_location' => $pickupRequired ? 'required_without:booking_items|array' : 'nullable|array',
                'pickup_location.latitude' => $pickupRequired ? 'required_without:booking_items|numeric' : 'required_with:pickup_location|numeric',
                'pickup_location.longitude' => $pickupRequired ? 'required_without:booking_items|numeric' : 'required_with:pickup_location|numeric',
                'dropoff_location' => $dropoffRequired ? 'required_without:booking_items|array' : 'nullable|array',
                'dropoff_location.latitude' => $dropoffRequired ? 'required_without:booking_items|numeric' : 'required_with:dropoff_location|numeric',
                'dropoff_location.longitude' => $dropoffRequired ? 'required_without:booking_items|numeric' : 'required_with:dropoff_location|numeric',
                'additional_pickup_locations' => 'sometimes|array',
                'additional_dropoff_locations' => 'sometimes|array',
                'ordered_additional_stops' => 'sometimes|array',
                'selected_addons' => 'sometimes|array',
                'selected_addons.*.id' => 'required|string',
                'selected_addons.*.quantity' => 'sometimes|integer|min:1',
                'selected_addons.*.custom_price' => 'sometimes',
                'selected_addons.*.group_id' => 'sometimes|string',
                'variable_customizations' => 'sometimes|array',
                'variable_customizations.*.id' => 'sometimes|string',
                'variable_customizations.*.variable_name' => 'required|string',
                'variable_customizations.*.variable_type' => 'sometimes|string|in:slab_rate,common_rate,addon_rate,fixed_value',
                'variable_customizations.*.original_value' => 'sometimes|numeric',
                'variable_customizations.*.custom_value' => 'required|numeric',
                'variable_customizations.*.rate_type' => 'sometimes|string|in:per_day,per_hour,flat_rate,per_package',
                'variable_customizations.*.billing_type' => 'sometimes|string|in:per_day,per_hour,per_package',
                'variable_customizations.*.model_type' => 'sometimes|string',
                'variable_customizations.*.model_id' => 'sometimes|string',
                'variable_customizations.*.context' => 'sometimes|string|in:base_pricing,addon_pricing',
                'variable_customizations.*.reason' => 'sometimes|string',
                'variable_customizations.*.vehicle_group_id' => 'sometimes|string',
                'has_variable_customizations' => 'sometimes|boolean',
                'session_id' => 'sometimes|string',
                'is_preview_calculation' => 'sometimes|boolean',
                'currency' => 'sometimes|string|size:3',
                'base_currency' => 'sometimes|string|size:3',
                'booking_id' => 'nullable|string',
                'preserve_custom_pricing' => 'sometimes|boolean',
                'preserve_custom_addon_prices' => 'sometimes|boolean',
                'force_recalculation' => 'sometimes|boolean',
                'applied_discounts' => 'sometimes|array',
            ];

            if ($usesDropoffTime) {
                $rules['to_date'] = 'required_without:booking_items|date|after_or_equal:from_date';
                $rules['to_time'] = 'required_without:booking_items|string';
            } else {
                $rules['to_date'] = 'nullable|date|after_or_equal:from_date';
                $rules['to_time'] = 'nullable|string';
            }

            Validator::make($params, $rules)->validate();

            if (!$usesDropoffTime && empty($params['to_date'])) {
                $params['to_date'] = $params['from_date'];
                $params['to_time'] = $params['from_time'] ?? ($params['to_time'] ?? null);
            }

            $result = $this->bookingFlowService->calculatePricing($params);

            return response()->json([
                'success' => true,
                'data' => $result
            ]);
        } catch (\Exception $e) {
            Log::error('Enhanced pricing calculation failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'error' => 'Failed to calculate enhanced pricing',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function getBookingSummary(Request $request): JsonResponse
    {
        try {
            $validatedData = $request->validate([
                'customer_id' => 'required|string',
                'service_type' => 'required|string',
                'vehicle_group_id' => 'required|string',
                'vehicle_id' => 'nullable|string',
                'driver_id' => 'nullable|string',
                'from_date' => 'required|date',
                'to_date' => 'required|date',
                'from_time' => 'nullable|string',
                'to_time' => 'nullable|string',
                'pickup_location_id' => 'nullable|string',
                'dropoff_location_id' => 'nullable|string',
                'selected_addons' => 'nullable|array'
            ]);

            $summary = $this->bookingFlowService->getBookingSummary($validatedData);

            return response()->json([
                'status' => 'success',
                'data' => $summary,
                'message' => 'Booking summary retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting booking summary: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get booking summary',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getDynamicPricingAdjustments(Request $request): JsonResponse
    {
        $request->validate([
            'service_type' => 'required|string',
            'vehicle_group_id' => 'required|uuid|exists:vehicle_groups,id',
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
        ]);

        try {
            $adjustments = $this->bookingFlowService->getDynamicPricingAdjustments($request->all());

            return response()->json([
                'status' => 'success',
                'data' => $adjustments,
                'message' => 'Dynamic pricing adjustments retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting dynamic pricing adjustments: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get dynamic pricing adjustments',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function validateBookingRules(Request $request): JsonResponse
    {
        try {
            $validation = $this->bookingFlowService->validateBookingRules($request->all());

            return response()->json([
                'status' => 'success',
                'data' => $validation,
                'message' => 'Booking rules validated successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error validating booking rules: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to validate booking rules',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function calculateRoute(Request $request): JsonResponse
    {
        $request->validate([
            'waypoints' => 'required|array|min:2',
            'waypoints.*.latitude' => 'required|numeric',
            'waypoints.*.longitude' => 'required|numeric',
            'waypoints.*.address' => 'sometimes|string',
            'optimize' => 'sometimes|boolean',
            'mode' => 'sometimes|string|in:driving,walking,bicycling,transit',
        ]);

        try {
            $waypoints = $request->input('waypoints');
            $optimize = $request->input('optimize', true);
            $mode = $request->input('mode', 'driving');

            $googleMapsService = app(\App\Services\GoogleMapsService::class);
            $route = $googleMapsService->calculateOptimalRoute($waypoints, $optimize, $mode);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'total_distance' => $route['distance_km'],
                    'total_duration' => $route['duration_minutes'],
                    'duration_seconds' => $route['duration_seconds'],
                    'optimized_waypoints' => $route['waypoints'],
                    'polyline' => $route['polyline'],
                ],
                'message' => 'Route calculated successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error calculating route: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to calculate route',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getAvailableCurrencies(): JsonResponse
    {
        try {
            $currencies = $this->currencyService->getAvailableCurrencies();

            return response()->json([
                'success' => true,
                'data' => $currencies
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching currencies: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'error' => 'Failed to fetch currencies',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function convertCurrency(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'amount' => 'required|numeric|min:0',
                'from_currency' => 'required|string|size:3',
                'to_currency' => 'required|string|size:3',
            ]);

            $convertedAmount = $this->currencyService->convert(
                $request->amount,
                $request->from_currency,
                $request->to_currency
            );

            $exchangeRate = $this->currencyService->getExchangeRate(
                $request->from_currency,
                $request->to_currency
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'original_amount' => $request->amount,
                    'converted_amount' => $convertedAmount,
                    'from_currency' => $request->from_currency,
                    'to_currency' => $request->to_currency,
                    'exchange_rate' => $exchangeRate
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Currency conversion failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'error' => 'Currency conversion failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function getBookingAddons(string $bookingId): JsonResponse
    {
        try {
            $booking = Booking::with(['addons.addon'])->findOrFail($bookingId);

            $addonsData = $booking->addons->map(function ($bookingAddon) {
                return [
                    'id' => $bookingAddon->id,
                    'addon_id' => $bookingAddon->addon_id,
                    'addon_name' => $bookingAddon->addon->name ?? 'Unknown',
                    'addon_original_price' => $bookingAddon->addon->amount ?? 0,
                    'qty' => $bookingAddon->qty,
                    'rate' => $bookingAddon->rate,
                    'amount' => $bookingAddon->amount,
                    'label' => $bookingAddon->label,
                    'is_custom_rate' => $bookingAddon->rate != ($bookingAddon->addon->amount ?? 0),
                    'created_at' => $bookingAddon->created_at,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'booking_id' => $bookingId,
                    'addons_cost_in_booking' => $booking->addons_cost,
                    'addons' => $addonsData,
                    'total_calculated_from_addons' => $addonsData->sum('amount')
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting booking addons: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'error' => 'Failed to get booking addons',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function updateAddonPricing(Request $request): JsonResponse
    {
        $request->validate([
            'booking_id' => 'nullable|string|exists:bookings,id',
            'addons' => 'required|array',
            'addons.*.addon_id' => 'required|string|exists:vehicle_addons,id',
            'addons.*.qty' => 'required|integer|min:1',
            'addons.*.rate' => 'required|numeric|min:0',
            'addons.*.amount' => 'required|numeric|min:0',
            'addons.*.is_custom_rate' => 'boolean',
        ]);

        try {
            $result = [
                'addons' => $request->addons,
                'total_addon_cost' => collect($request->addons)->sum('amount'),
                'custom_pricing_applied' => collect($request->addons)->contains('is_custom_rate', true)
            ];

            return response()->json([
                'status' => 'success',
                'data' => $result,
                'message' => 'Addon pricing updated successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating addon pricing: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update addon pricing',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getCustomizableVariables(Request $request): JsonResponse
    {
        $request->validate([
            'service_type_id' => 'required|string',
            'vehicle_group_id' => 'sometimes|string',
            'vehicle_group_ids' => 'sometimes|array',
            'vehicle_group_ids.*' => 'string',
            'booking_id' => 'nullable|string',
        ]);

        $vehicleGroupIds = [];
        if ($request->has('vehicle_group_ids')) {
            $vehicleGroupIds = $request->vehicle_group_ids;
        } elseif ($request->has('vehicle_group_id')) {
            $vehicleGroupIds = [$request->vehicle_group_id];
        } else {
            return response()->json([
                'status' => 'error',
                'message' => 'Either vehicle_group_id or vehicle_group_ids is required'
            ], 400);
        }

        try {
            $variables = $this->bookingFlowService->getCustomizableVariables(
                $request->service_type_id,
                $vehicleGroupIds,
                $request->booking_id
            );

            return response()->json([
                'status' => 'success',
                'data' => $variables,
                'message' => 'Customizable variables retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting customizable variables: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get customizable variables',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function storeVariableCustomizations(Request $request): JsonResponse
    {
        $request->validate([
            'customizations' => 'required|array',
            'customizations.*.variable_name' => 'required|string',
            'customizations.*.variable_type' => 'required|string',
            'customizations.*.vehicle_group_id' => 'required|string',
            'customizations.*.original_value' => 'required|numeric',
            'customizations.*.custom_value' => 'required|numeric',
            'customizations.*.reason' => 'nullable|string',
            'customizations.*.context' => 'required|string|in:base_pricing,addon_pricing',
            'booking_id' => 'nullable|string',
            'session_id' => 'nullable|string',
        ]);

        try {
            $stored = $this->bookingFlowService->storeVariableCustomizations(
                $request->customizations,
                $request->booking_id,
                $request->session_id
            );

            return response()->json([
                'status' => 'success',
                'data' => $stored,
                'message' => 'Variable customizations stored successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error storing variable customizations: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to store variable customizations',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getVariableCustomizations(Request $request): JsonResponse
    {
        $request->validate([
            'booking_id' => 'nullable|string',
            'session_id' => 'nullable|string',
        ]);

        if (!$request->booking_id && !$request->session_id) {
            return response()->json([
                'status' => 'error',
                'message' => 'Either booking_id or session_id is required'
            ], 400);
        }

        try {
            $customizations = $this->bookingFlowService->getVariableCustomizations(
                $request->booking_id,
                $request->session_id
            );

            return response()->json([
                'status' => 'success',
                'data' => $customizations,
                'message' => 'Variable customizations retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting variable customizations: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get variable customizations',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
