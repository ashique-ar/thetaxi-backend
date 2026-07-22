<?php

namespace App\Http\Controllers\Api\Booking\Traits;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

trait BookingAvailabilityTrait
{
    public function getAvailableVehicleGroups(Request $request): JsonResponse
    {
        try {
            $params = $this->bookingFlowService->normalizeDynamicCalculationParams($request->all());
            if (array_key_exists('include_unavailable', $params)) {
                $params['include_unavailable'] = filter_var($params['include_unavailable'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            }
            $requirements = $this->bookingFlowService->getDynamicCalculationRequirements($params);

            $usesDropoffTime = (bool) ($requirements['uses_dropoff_time'] ?? true);
            $pickupRequired = (bool) ($requirements['pickup_location_required'] ?? true);
            $dropoffRequired = (bool) ($requirements['dropoff_location_required'] ?? true);

            $rules = [
                'service_type' => 'required|string',
                'from_date' => 'required|date',
                'from_time' => 'required|string',
                'pickup_location' => $pickupRequired ? 'required|array' : 'nullable|array',
                'pickup_location.latitude' => $pickupRequired ? 'required|numeric' : 'required_with:pickup_location|numeric',
                'pickup_location.longitude' => $pickupRequired ? 'required|numeric' : 'required_with:pickup_location|numeric',
                'dropoff_location' => $dropoffRequired ? 'required|array' : 'nullable|array',
                'dropoff_location.latitude' => $dropoffRequired ? 'required|numeric' : 'required_with:dropoff_location|numeric',
                'dropoff_location.longitude' => $dropoffRequired ? 'required|numeric' : 'required_with:dropoff_location|numeric',
                'additional_pickup_locations' => 'sometimes|array',
                'additional_dropoff_locations' => 'sometimes|array',
                'ordered_additional_stops' => 'sometimes|array',
                'page' => 'nullable|integer|min:1',
                'per_page' => 'nullable|integer|min:1|max:100',
                'search' => 'nullable|string|max:255',
                'category_filter' => 'nullable|string',
                'exclude_booking_id' => 'nullable|string',
                'force_refresh' => 'nullable|boolean',
            ];

            if ($usesDropoffTime) {
                $rules['to_date'] = 'required|date|after_or_equal:from_date';
                $rules['to_time'] = 'required|string';
            } else {
                $rules['to_date'] = 'nullable|date|after_or_equal:from_date';
                $rules['to_time'] = 'nullable|string';
            }

            \Illuminate\Support\Facades\Validator::make($params, $rules)->validate();

            $params['page'] = $request->get('page', 1);
            $params['per_page'] = $request->get('per_page', 20);

            if (!$usesDropoffTime) {
                $params['to_date'] = $params['to_date'] ?? $params['from_date'];
                $params['to_time'] = $params['to_time'] ?? $params['from_time'];
            }

            $availability = $this->bookingFlowService->getAvailableVehicleGroups($params);

            return response()->json([
                'status' => 'success',
                'data' => $availability['data'] ?? $availability,
                'pagination' => $availability['pagination'] ?? null,
                'message' => 'Vehicle groups retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting vehicle groups availability: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get vehicle groups availability',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getAvailableVehiclesInGroup(Request $request): JsonResponse
    {
        try {
            $params = $this->bookingFlowService->normalizeDynamicCalculationParams($request->all());
            if (array_key_exists('include_unavailable', $params)) {
                $params['include_unavailable'] = filter_var($params['include_unavailable'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            }
            $requirements = $this->bookingFlowService->getDynamicCalculationRequirements($params);
            $usesDropoffTime = (bool) ($requirements['uses_dropoff_time'] ?? true);

            $rules = [
                'search_term' => 'nullable|string|min:1',
                'service_type' => 'required|string',
                'from_date' => 'required|date',
                'from_time' => 'required|string',
                'include_unavailable' => 'boolean',
                'vehicle_group_id' => 'nullable|string',
                'page' => 'nullable|integer|min:1',
                'per_page' => 'nullable|integer|min:1|max:100',
                'exclude_booking_id' => 'nullable|string',
                'force_refresh' => 'nullable|boolean',
            ];

            if ($usesDropoffTime) {
                $rules['to_date'] = 'required|date|after_or_equal:from_date';
                $rules['to_time'] = 'required|string';
            } else {
                $rules['to_date'] = 'nullable|date|after_or_equal:from_date';
                $rules['to_time'] = 'nullable|string';
            }

            \Illuminate\Support\Facades\Validator::make($params, $rules)->validate();

            $params['page'] = $request->get('page', 1);
            $params['per_page'] = $request->get('per_page', 20);

            if (!$usesDropoffTime) {
                $params['to_date'] = $params['to_date'] ?? $params['from_date'];
                $params['to_time'] = $params['to_time'] ?? $params['from_time'];
            }

            $vehicles = $this->bookingFlowService->searchSpecificVehicles($params);

            return response()->json([
                'status' => 'success',
                'data' => $vehicles['data'] ?? $vehicles,
                'pagination' => $vehicles['pagination'] ?? null,
                'message' => 'Specific vehicles search completed successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error searching specific vehicles: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to search specific vehicles',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getAvailableDrivers(Request $request): JsonResponse
    {
        try {
            $params = $this->bookingFlowService->normalizeDynamicCalculationParams($request->all());
            if (array_key_exists('include_unavailable', $params)) {
                $params['include_unavailable'] = filter_var($params['include_unavailable'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            }
            $requirements = $this->bookingFlowService->getDynamicCalculationRequirements($params);
            $usesDropoffTime = (bool) ($requirements['uses_dropoff_time'] ?? true);

            $rules = [
                'search_term' => 'nullable|string|min:1',
                'service_type' => 'nullable|string',
                'from_date' => 'required|date',
                'from_time' => 'required|string',
                'vehicle_group_id' => 'nullable|uuid|exists:vehicle_groups,id',
                'include_unavailable' => 'boolean',
                'page' => 'nullable|integer|min:1',
                'per_page' => 'nullable|integer|min:1|max:100',
                'exclude_booking_id' => 'nullable|string',
                'force_refresh' => 'nullable|boolean',
            ];

            if ($usesDropoffTime) {
                $rules['to_date'] = 'required|date|after_or_equal:from_date';
                $rules['to_time'] = 'required|string';
            } else {
                $rules['to_date'] = 'nullable|date|after_or_equal:from_date';
                $rules['to_time'] = 'nullable|string';
            }

            \Illuminate\Support\Facades\Validator::make($params, $rules)->validate();

            $params['page'] = $request->get('page', 1);
            $params['per_page'] = $request->get('per_page', 20);

            if (!$usesDropoffTime) {
                $params['to_date'] = $params['to_date'] ?? $params['from_date'];
                $params['to_time'] = $params['to_time'] ?? $params['from_time'];
            }

            $drivers = $this->bookingFlowService->searchSpecificDrivers($params);

            return response()->json([
                'status' => 'success',
                'data' => $drivers['data'] ?? $drivers,
                'pagination' => $drivers['pagination'] ?? null,
                'message' => 'Specific drivers search completed successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error searching specific drivers: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to search specific drivers',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function checkVehicleConflicts(Request $request, string $vehicleId): JsonResponse
    {
        $request->validate([
            'service_type' => 'required|string',
            'from_date' => 'required|date',
            'to_date' => 'nullable|date',
            'from_time' => 'required|string',
            'to_time' => 'nullable|string',
            'exclude_booking_id' => 'nullable|uuid|exists:bookings,id',
        ]);

        try {
            $conflicts = $this->bookingFlowService->checkVehicleConflicts($vehicleId, $request->all());

            return response()->json([
                'status' => 'success',
                'data' => $conflicts,
                'message' => 'Vehicle conflicts checked successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error checking vehicle conflicts: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to check vehicle conflicts',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function checkDriverConflicts(Request $request, string $driverId): JsonResponse
    {
        $request->validate([
            'vehicle_group_id' => 'required|uuid|exists:vehicle_groups,id',
            'from_date' => 'required|date',
            'to_date' => 'nullable|date',
            'from_time' => 'required|string',
            'to_time' => 'nullable|string',
            'exclude_booking_id' => 'nullable|uuid|exists:bookings,id',
        ]);

        try {
            $conflicts = $this->bookingFlowService->checkDriverConflicts($driverId, $request->all());

            return response()->json([
                'status' => 'success',
                'data' => $conflicts,
                'message' => 'Driver conflicts checked successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error checking driver conflicts: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to check driver conflicts',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getAvailableAddons(Request $request): JsonResponse
    {
        $request->validate([
            'service_type' => 'required|string',
            'vehicle_group_id' => 'nullable|string',
            'search' => 'nullable|string|max:255',
            'category_filter' => 'nullable|string',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
            'force_refresh' => 'nullable|boolean',
        ]);

        try {
            $params = $request->all();
            $params['page'] = $request->get('page', 1);
            $params['per_page'] = $request->get('per_page', 50);

            $addons = $this->bookingFlowService->getAvailableAddons($params);

            return response()->json([
                'status' => 'success',
                'data' => $addons['data'] ?? $addons,
                'pagination' => $addons['pagination'] ?? null,
                'message' => 'Available add-ons retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting available add-ons: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get available add-ons',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function validateSelfDrivenEligibility(Request $request, string $customerId): JsonResponse
    {
        try {
            $eligibility = $this->bookingFlowService->validateSelfDrivenEligibility(
                $customerId,
                $request->query('vehicle_id'),
                $request->filled('from_date') ? Carbon::parse($request->query('from_date')) : null
            );

            return response()->json([
                'status' => 'success',
                'data' => $eligibility,
                'message' => 'Self-driven eligibility checked successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error checking self-driven eligibility: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to check self-driven eligibility',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getVehicleSelfDrivenSuitability(string $vehicleId): JsonResponse
    {
        try {
            $suitability = $this->bookingFlowService->getVehicleSelfDrivenSuitability($vehicleId);

            return response()->json([
                'status' => 'success',
                'data' => $suitability,
                'message' => 'Vehicle self-driven suitability retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting vehicle suitability: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get vehicle suitability',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getAlternativeSuggestions(Request $request): JsonResponse
    {
        $request->validate([
            'original_vehicle_group_id' => 'required|uuid|exists:vehicle_groups,id',
            'service_type' => 'required|string',
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
            'preferences' => 'nullable|array',
        ]);

        try {
            $suggestions = $this->bookingFlowService->getAlternativeSuggestions($request->all());

            return response()->json([
                'status' => 'success',
                'data' => $suggestions,
                'message' => 'Alternative suggestions retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting alternative suggestions: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get alternative suggestions',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function processAddonDependencies(Request $request): JsonResponse
    {
        $request->validate([
            'selected_addons' => 'required|array',
            'selected_addons.*' => 'uuid|exists:vehicle_addons,id',
        ]);

        try {
            $dependencies = $this->bookingFlowService->processAddonDependencies($request->input('selected_addons'));

            return response()->json([
                'status' => 'success',
                'data' => $dependencies,
                'message' => 'Add-on dependencies processed successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error processing add-on dependencies: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to process add-on dependencies',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function selectVehicleWithAssignmentDetails(Request $request): JsonResponse
    {
        $request->validate([
            'vehicle_id' => 'required|string|exists:vehicles,id',
            'service_type' => 'required|string',
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
            'exclude_booking_id' => 'nullable|string',
        ]);

        try {
            $result = $this->bookingFlowService->selectVehicleWithAssignmentDetails(
                $request->vehicle_id,
                $request->all()
            );

            return response()->json([
                'status' => 'success',
                'data' => $result,
                'message' => 'Vehicle selection details retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting vehicle assignment details: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get vehicle assignment details',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getAlternativeAssignments(Request $request): JsonResponse
    {
        $request->validate([
            'vehicle_group_id' => 'required|string|exists:vehicle_groups,id',
            'service_type' => 'required|string',
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
            'exclude_booking_id' => 'nullable|string',
        ]);

        try {
            $alternatives = $this->bookingFlowService->getAlternativeAssignments($request->all());

            return response()->json([
                'status' => 'success',
                'data' => $alternatives,
                'message' => 'Alternative assignments retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting alternative assignments: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get alternative assignments',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function processAssignmentConfirmation(Request $request): JsonResponse
    {
        $request->validate([
            'vehicle_id' => 'nullable|string|exists:vehicles,id',
            'driver_id' => 'nullable|string|exists:drivers,id',
            'booking_id' => 'required|string|exists:bookings,id',
            'booking_item_id' => 'nullable|string|exists:booking_items,id',
            'assignment_type' => 'required|string|in:primary,concurrent,override',
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
            'customer_name' => 'required|string',
            'service_type' => 'required|string',
            'override_reasons' => 'nullable|array',
            'overlap_details' => 'nullable|array',
            'overlap_type' => 'nullable|string|in:full,partial,concurrent',
        ]);

        try {
            $result = $this->assignmentService->processAssignmentConfirmation($request->all());

            return response()->json([
                'status' => 'success',
                'data' => $result,
                'message' => 'Assignment confirmation processed successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error processing assignment confirmation: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to process assignment confirmation',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function approveAssignments(Request $request): JsonResponse
    {
        $request->validate([
            'booking_id' => 'required|string|exists:bookings,id',
        ]);

        try {
            $result = $this->assignmentService->approveAssignments(
                $request->booking_id,
                \Illuminate\Support\Facades\Auth::id()
            );

            return response()->json([
                'status' => 'success',
                'data' => $result,
                'message' => 'Assignments approved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error approving assignments: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to approve assignments',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
