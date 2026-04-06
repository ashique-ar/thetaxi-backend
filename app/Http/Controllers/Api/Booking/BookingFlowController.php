<?php

namespace App\Http\Controllers\Api\Booking;

use App\Http\Controllers\Controller;
use App\Services\BookingFlowService;
use App\Services\DiscountService;
use App\Http\Resources\Booking\BookingFlowResource;
use App\Models\Booking\Booking;
use App\Models\Corporate\Corporate;
use App\Models\Corporate\CorporateDepartment;
use App\Models\Corporate\CorporateDivision;
use App\Models\Corporate\CorporateEmployee;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use App\Services\CurrencyService;
use App\Services\AssignmentService;
use App\Services\CorporateService;

class BookingFlowController extends Controller
{
    protected $bookingFlowService;
    protected $currencyService;
    protected $discountService;
    protected $assignmentService;
    protected $corporateService;

    public function __construct(
        BookingFlowService $bookingFlowService,
        CurrencyService $currencyService,
        DiscountService $discountService,
        AssignmentService $assignmentService,
        CorporateService $corporateService
    ) {
        $this->bookingFlowService = $bookingFlowService;
        $this->currencyService = $currencyService;
        $this->discountService = $discountService;
        $this->assignmentService = $assignmentService;
        $this->corporateService = $corporateService;
    }

    /**
     * Get available vehicle groups based on service type and time requirements
     */
    public function getAvailableVehicleGroups(Request $request): JsonResponse
    {
        try {
            $params = $this->bookingFlowService->normalizeDynamicCalculationParams($request->all());
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

            Validator::make($params, $rules)->validate();

            $params['page'] = $request->get('page', 1);
            $params['per_page'] = $request->get('per_page', 20);

            // If service doesn't use dropoff time, set to_date = from_date and to_time = from_time
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

    /**
     * Search for specific vehicles by name, license plate, or ID
     */
    public function getAvailableVehiclesInGroup(Request $request): JsonResponse
    {
        try {
            $params = $this->bookingFlowService->normalizeDynamicCalculationParams($request->all());
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

            Validator::make($params, $rules)->validate();

            $params['page'] = $request->get('page', 1);
            $params['per_page'] = $request->get('per_page', 20);

            // If service doesn't use dropoff time, set to_date = from_date and to_time = from_time
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
            throw $e;
            Log::error('Error searching specific vehicles: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to search specific vehicles',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Search for specific drivers by name, license, or ID
     */
    public function getAvailableDrivers(Request $request): JsonResponse
    {
        try {
            $params = $this->bookingFlowService->normalizeDynamicCalculationParams($request->all());
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

            Validator::make($params, $rules)->validate();

            $params['page'] = $request->get('page', 1);
            $params['per_page'] = $request->get('per_page', 20);

            // If service doesn't use dropoff time, set to_date = from_date and to_time = from_time
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


    /**
     * Check for conflicts when selecting a specific vehicle
     */
    public function checkVehicleConflicts(Request $request, string $vehicleId): JsonResponse
    {
        $request->validate([
            'service_type' => 'required|string',
            'from_date' => 'required|date',
            'to_date' => 'nullable|date',
            'from_time' => 'required|string',
            'to_time' => 'nullable|string',
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

    /**
     * Check for conflicts when selecting a specific driver
     */
    public function checkDriverConflicts(Request $request, string $driverId): JsonResponse
    {
        $request->validate([
            'vehicle_group_id' => 'required|uuid|exists:vehicle_groups,id',
            'from_date' => 'required|date',
            'to_date' => 'nullable|date',
            'from_time' => 'required|string',
            'to_time' => 'nullable|string',
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


    /**
     * Calculate enhanced pricing with currency conversion and improved addon handling
     * Now supports multi-vehicle group selection
     */
    public function calculatePricing(Request $request): JsonResponse
    {
        try {
            $params = $this->bookingFlowService->normalizeDynamicCalculationParams($request->all());
            $requirements = $this->bookingFlowService->getDynamicCalculationRequirements($params);
            $usesDropoffTime = (bool) ($requirements['uses_dropoff_time'] ?? true);
            $pickupRequired = (bool) ($requirements['pickup_location_required'] ?? true);
            $dropoffRequired = (bool) ($requirements['dropoff_location_required'] ?? true);

            // Build validation rules dynamically
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

                // Multi-selection support - accept both single and multiple
                'vehicle_group_id' => 'sometimes|string', // For backward compatibility
                'vehicle_groups' => 'sometimes|array', // New multi-selection format
                'vehicle_groups.*.id' => 'required|string',
                'vehicle_groups.*.quantity' => 'required|integer|min:1',

                'vehicles' => 'sometimes|array', // Selected specific vehicles
                'vehicles.*.id' => 'required|string',
                'vehicles.*.group_id' => 'required|string',

                'drivers' => 'sometimes|array', // Selected drivers
                'drivers.*.id' => 'required|string',

                'vehicle_driver_assignments' => 'sometimes|array', // Vehicle-driver assignments
                'vehicle_driver_assignments.*.vehicle_id' => 'required|string',
                'vehicle_driver_assignments.*.driver_id' => 'nullable|string',

                'booking_items' => 'sometimes|array',

                'from_date' => 'required_without:booking_items|date',
                'from_time' => 'required_without:booking_items|string',
                
                // Relaxed validation to support multi-trip (booking_items) where top-level location is optional
                'pickup_location' => $pickupRequired
                    ? 'required_without:booking_items|array'
                    : 'nullable|array',
                'pickup_location.latitude' => $pickupRequired
                    ? 'required_without:booking_items|numeric'
                    : 'required_with:pickup_location|numeric',
                'pickup_location.longitude' => $pickupRequired
                    ? 'required_without:booking_items|numeric'
                    : 'required_with:pickup_location|numeric',
                'dropoff_location' => $dropoffRequired
                    ? 'required_without:booking_items|array'
                    : 'nullable|array',
                'dropoff_location.latitude' => $dropoffRequired
                    ? 'required_without:booking_items|numeric'
                    : 'required_with:dropoff_location|numeric',
                'dropoff_location.longitude' => $dropoffRequired
                    ? 'required_without:booking_items|numeric'
                    : 'required_with:dropoff_location|numeric',
                'additional_pickup_locations' => 'sometimes|array',
                'additional_dropoff_locations' => 'sometimes|array',
                'ordered_additional_stops' => 'sometimes|array',

                'selected_addons' => 'sometimes|array',
                'selected_addons.*.id' => 'required|string',
                'selected_addons.*.quantity' => 'sometimes|integer|min:1',
                'selected_addons.*.custom_price' => 'sometimes',
                'selected_addons.*.group_id' => 'sometimes|string', // Group-specific addons

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
                'variable_customizations.*.vehicle_group_id' => 'sometimes|string', // Vehicle group specific customizations

                'has_variable_customizations' => 'sometimes|boolean',
                'session_id' => 'sometimes|string',
                'is_preview_calculation' => 'sometimes|boolean',
                'currency' => 'sometimes|string|size:3',
                'base_currency' => 'sometimes|string|size:3',
                'booking_id' => 'nullable|string',
                'preserve_custom_pricing' => 'sometimes|boolean',
                'preserve_custom_addon_prices' => 'sometimes|boolean',
                'force_recalculation' => 'sometimes|boolean',
                'applied_discounts' => 'sometimes|array', // Include applied discounts
            ];

            // Only require to_date if service type uses dropoff time
            if ($usesDropoffTime) {
                $rules['to_date'] = 'required_without:booking_items|date|after_or_equal:from_date';
                $rules['to_time'] = 'required_without:booking_items|string';
            } else {
                $rules['to_date'] = 'nullable|date|after_or_equal:from_date';
                $rules['to_time'] = 'nullable|string';
            }

            Validator::make($params, $rules)->validate();

            // If service doesn't use dropoff time, set to_date = from_date
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

    /**
     * Get comprehensive booking summary for review
     */
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


    public function submitBookingForApproval(Request $request): JsonResponse
    {
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

            // Multi-selection support - accept both single and multiple
            'vehicle_group_id' => 'sometimes|string', // For backward compatibility
            'vehicle_groups' => 'sometimes|array', // New multi-selection format
            'vehicle_groups.*.id' => 'required|string',
            'vehicle_groups.*.quantity' => 'required|integer|min:1',

            'vehicles' => 'sometimes|array', // Selected specific vehicles
            'vehicles.*.id' => 'required|string',
            'vehicles.*.group_id' => 'required|string',

            'drivers' => 'sometimes|array', // Selected drivers
            'drivers.*.id' => 'required|string',

            'vehicle_driver_assignments' => 'sometimes|array', // Vehicle-driver assignments
            'vehicle_driver_assignments.*.vehicle_id' => 'required|string',
            'vehicle_driver_assignments.*.driver_id' => 'nullable|string',

            'from_date' => 'required_without:booking_items|date',
            'from_time' => 'required_without:booking_items|string',
            'pickup_location' => $pickupRequired
                ? 'required_without:booking_items|array'
                : 'nullable|array',
            'pickup_location.latitude' => $pickupRequired
                ? 'required_without:booking_items|numeric'
                : 'required_with:pickup_location|numeric',
            'pickup_location.longitude' => $pickupRequired
                ? 'required_without:booking_items|numeric'
                : 'required_with:pickup_location|numeric',
            'dropoff_location' => $dropoffRequired
                ? 'required_without:booking_items|array'
                : 'nullable|array',
            'dropoff_location.latitude' => $dropoffRequired
                ? 'required_without:booking_items|numeric'
                : 'required_with:dropoff_location|numeric',
            'dropoff_location.longitude' => $dropoffRequired
                ? 'required_without:booking_items|numeric'
                : 'required_with:dropoff_location|numeric',

            'selected_addons' => 'sometimes|array',
            'selected_addons.*.id' => 'required|string',
            'selected_addons.*.quantity' => 'sometimes|integer|min:1',
            'selected_addons.*.custom_price' => 'sometimes',
            'selected_addons.*.group_id' => 'sometimes|string', // Group-specific addons

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
            'variable_customizations.*.group_id' => 'sometimes|string', // Group-specific customizations

            'has_variable_customizations' => 'sometimes|boolean',
            'session_id' => 'sometimes|string',
            'is_preview_calculation' => 'sometimes|boolean',
            'currency' => 'sometimes|string|size:3',
            'base_currency' => 'sometimes|string|size:3',
            'booking_id' => 'nullable|string',
            'preserve_custom_pricing' => 'sometimes|boolean',
            'preserve_custom_addon_prices' => 'sometimes|boolean',
            'force_recalculation' => 'sometimes|boolean',
            'applied_discounts' => 'sometimes|array', // Include applied discounts
        ];

        if ($usesDropoffTime) {
            $rules['to_date'] = 'required_without:booking_items|date|after_or_equal:from_date';
            $rules['to_time'] = 'required_without:booking_items|string';
        } else {
            $rules['to_date'] = 'nullable|date|after_or_equal:from_date';
            $rules['to_time'] = 'nullable|string';
            $params['to_date'] = $params['to_date'] ?? ($params['from_date'] ?? null);
            $params['to_time'] = $params['to_time'] ?? ($params['from_time'] ?? null);
        }

        Validator::make($params, $rules)->validate();

        $booking = $this->bookingFlowService->submitBookingForApproval($params);

        return response()->json([
            'status' => 'success',
            'data' => new BookingFlowResource($booking),
            'requires_approval' => $booking->requires_approval,
            'message' => 'Booking submitted for approval successfully'
        ], 201);
    }

    public function confirmBooking(Request $request): JsonResponse
    {
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

            // Multi-selection support - accept both single and multiple
            'vehicle_group_id' => 'sometimes|string', // For backward compatibility
            'vehicle_groups' => 'sometimes|array', // New multi-selection format
            'vehicle_groups.*.id' => 'required|string',
            'vehicle_groups.*.quantity' => 'required|integer|min:1',

            'vehicles' => 'sometimes|array', // Selected specific vehicles
            'vehicles.*.id' => 'required|string',
            'vehicles.*.group_id' => 'required|string',

            'drivers' => 'sometimes|array', // Selected drivers
            'drivers.*.id' => 'required|string',

            'vehicle_driver_assignments' => 'sometimes|array', // Vehicle-driver assignments
            'vehicle_driver_assignments.*.vehicle_id' => 'required|string',
            'vehicle_driver_assignments.*.driver_id' => 'nullable|string',

            'from_date' => 'required_without:booking_items|date',
            'from_time' => 'required_without:booking_items|string',
            'pickup_location' => $pickupRequired
                ? 'required_without:booking_items|array'
                : 'nullable|array',
            'pickup_location.latitude' => $pickupRequired
                ? 'required_without:booking_items|numeric'
                : 'required_with:pickup_location|numeric',
            'pickup_location.longitude' => $pickupRequired
                ? 'required_without:booking_items|numeric'
                : 'required_with:pickup_location|numeric',
            'dropoff_location' => $dropoffRequired
                ? 'required_without:booking_items|array'
                : 'nullable|array',
            'dropoff_location.latitude' => $dropoffRequired
                ? 'required_without:booking_items|numeric'
                : 'required_with:dropoff_location|numeric',
            'dropoff_location.longitude' => $dropoffRequired
                ? 'required_without:booking_items|numeric'
                : 'required_with:dropoff_location|numeric',

            'selected_addons' => 'sometimes|array',
            'selected_addons.*.id' => 'required|string',
            'selected_addons.*.quantity' => 'sometimes|integer|min:1',
            'selected_addons.*.custom_price' => 'sometimes',
            'selected_addons.*.group_id' => 'sometimes|string', // Group-specific addons

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
            'variable_customizations.*.group_id' => 'sometimes|string', // Group-specific customizations

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
            $params['to_date'] = $params['to_date'] ?? ($params['from_date'] ?? null);
            $params['to_time'] = $params['to_time'] ?? ($params['from_time'] ?? null);
        }

        Validator::make($params, $rules)->validate();

        $booking = $this->bookingFlowService->confirmBooking($params);

        return response()->json([
            'status' => 'success',
            'data' => new BookingFlowResource($booking),
            'message' => 'Booking confirmed successfully'
        ], 201);
    }

    public function updateBooking(Request $request, string $bookingId): JsonResponse
    {
        try {
            $params = $this->bookingFlowService->normalizeDynamicCalculationParams($request->all());
            $requirements = $this->bookingFlowService->getDynamicCalculationRequirements($params);
            $usesDropoffTime = (bool) ($requirements['uses_dropoff_time'] ?? true);

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
                'service_type' => 'sometimes|string',
                'service_type_id' => 'sometimes|string',
                
                // Multi-selection support
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

                'from_date' => 'sometimes|nullable|date',
                'from_time' => 'sometimes|nullable|string',
                'to_date' => 'sometimes|nullable|date|after_or_equal:from_date',
                'to_time' => 'sometimes|nullable|string',
                
                'pickup_location' => 'sometimes|array',
                'pickup_location.latitude' => 'required_with:pickup_location|numeric',
                'pickup_location.longitude' => 'required_with:pickup_location|numeric',
                'dropoff_location' => 'sometimes|array',
                'dropoff_location.latitude' => 'required_with:dropoff_location|numeric',
                'dropoff_location.longitude' => 'required_with:dropoff_location|numeric',
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
                'variable_customizations.*.group_id' => 'sometimes|string',
                
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

            if (!$usesDropoffTime) {
                // Services without drop-off datetime should always mirror pickup datetime
                // to avoid validation failures from stale/invalid to_date/to_time payload values.
                if (array_key_exists('from_date', $params) && !empty($params['from_date'])) {
                    $params['to_date'] = $params['from_date'];
                } else {
                    unset($params['to_date']);
                }

                if (array_key_exists('from_time', $params) && !empty($params['from_time'])) {
                    $params['to_time'] = $params['from_time'];
                } else {
                    unset($params['to_time']);
                }
            }

            Validator::make($params, $rules)->validate();

            $booking = $this->bookingFlowService->updateBooking($bookingId, $params, []);

            return response()->json([
                'status' => 'success',
                'data' => new BookingFlowResource($booking->load([
                    'customer',
                    'vehicle',
                    'driver',
                    'serviceType',
                    'vehicleGroup'
                ])),
                'requires_re_approval' => $booking->status === 'pending_approval',
                'message' => $booking->status === 'pending_approval'
                    ? 'Booking updated and submitted for re-approval'
                    : 'Booking updated successfully',
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Booking not found',
            ], 404);
        } catch (\Throwable $e) {
            Log::error('Error updating booking: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update booking',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get booking for edit - retrieve comprehensive booking data
     */
    public function getBookingForEdit(string $bookingId): JsonResponse
    {
        try {
            // Validate booking exists and user has permission
            $booking = Booking::with([
                'customer',
                'vehicle',
                'driver',
                'serviceType',
                'vehicleGroup',
                'bookingAddons.addon',
                'approvals',
                'bookingItems',
            ])->findOrFail($bookingId);

            // Check permissions
            // if (!Gate::allows('update', $booking)) {
            //     return response()->json([
            //         'status' => 'error',
            //         'message' => 'Unauthorized to edit this booking'
            //     ], 403);
            // }

            // Get comprehensive edit data
            $editData = $this->bookingFlowService->getComprehensiveBookingData($bookingId);

            return response()->json([
                'status' => 'success',
                'data' => $editData,
                'permissions' => [
                    'can_edit_basic' => Gate::allows('update', $booking),
                    'can_edit_pricing' => Gate::allows('update', $booking),
                    'can_edit_approval' => Gate::allows('update', $booking),
                    'can_cancel' => Gate::allows('delete', $booking),
                    'can_override' => Gate::allows('update', $booking)
                ],
                'message' => 'Booking data retrieved for editing'
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Booking not found'
            ], 404);
        } catch (\Exception $e) {
            throw $e;
            Log::error('Error getting booking for edit: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve booking for editing',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Apply gamify points and discount
     */
    public function applyGamifyDiscount(Request $request): JsonResponse
    {
        $request->validate([
            'booking_id' => 'required|uuid|exists:bookings,id',
            'points_to_redeem' => 'required|integer|min:1',
        ]);

        try {
            $result = $this->bookingFlowService->applyGamifyDiscount(
                $request->booking_id,
                $request->points_to_redeem,
                Auth::id()
            );

            return response()->json([
                'status' => 'success',
                'data' => $result,
                'message' => 'Gamify discount applied successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error applying gamify discount: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to apply gamify discount',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get customer loyalty information
     */
    public function getCustomerLoyaltyInfo(Request $request, string $customerId): JsonResponse
    {
        try {
            $loyaltyInfo = $this->bookingFlowService->getCustomerLoyaltyInfo($customerId);

            return response()->json([
                'status' => 'success',
                'data' => $loyaltyInfo,
                'message' => 'Customer loyalty information retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting customer loyalty info: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get customer loyalty information',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove discount from booking
     */
    public function removeDiscount(Request $request): JsonResponse
    {
        $request->validate([
            'discount_id' => 'required|uuid|exists:booking_discounts,id',
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            $result = $this->bookingFlowService->removeDiscount(
                $request->discount_id,
                $request->reason
            );

            return response()->json([
                'status' => 'success',
                'data' => $result,
                'message' => 'Discount removed successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error removing discount: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to remove discount',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Get comprehensive discount summary for booking
     */
    public function getBookingDiscountSummary(Request $request, string $bookingId): JsonResponse
    {
        try {
            $summary = $this->bookingFlowService->getBookingDiscountSummary($bookingId);

            return response()->json([
                'status' => 'success',
                'data' => $summary,
                'message' => 'Booking discount summary retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting booking discount summary: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get booking discount summary',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Process loyalty points earning for completed booking
     */
    public function processLoyaltyPointsEarning(Request $request): JsonResponse
    {
        $request->validate([
            'booking_id' => 'required|uuid|exists:bookings,id',
        ]);

        try {
            $result = $this->bookingFlowService->processLoyaltyPointsEarning($request->booking_id);

            return response()->json([
                'status' => 'success',
                'data' => $result,
                'message' => 'Loyalty points processed successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error processing loyalty points: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to process loyalty points',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Get booking approval details
     */
    public function getBookingApprovalDetails(Request $request, string $bookingId): JsonResponse
    {
        try {
            $details = $this->bookingFlowService->getApprovalDetails($bookingId);

            return response()->json([
                'status' => 'success',
                'data' => $details,
                'message' => 'Approval details retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting approval details: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get approval details',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Approve or reject booking
     */
    public function processBookingApproval(Request $request): JsonResponse
    {
        $request->validate([
            'booking_id' => 'required|uuid|exists:bookings,id',
            'action' => 'required|in:approve,reject',
            'note' => 'nullable|string|max:1000',
        ]);

        try {
            $result = $this->bookingFlowService->processApproval(
                $request->booking_id,
                $request->action,
                $request->note,
                Auth::id()
            );

            return response()->json([
                'status' => 'success',
                'data' => $result,
                'message' => ucfirst($request->action) . 'd successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error processing approval: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to process approval',
                'error' => $e->getMessage()
            ], 500);
        }
    }



    /**
     * Get available add-ons for a specific vehicle group and service type
     */
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

    /**
     * Validate self-driven eligibility for a customer
     */
    public function validateSelfDrivenEligibility(string $customerId): JsonResponse
    {
        try {
            $eligibility = $this->bookingFlowService->validateSelfDrivenEligibility($customerId);

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

    /**
     * Get vehicle features and suitability for self-driven service
     */
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



    /**
     * Get approval workflow status
     */
    public function getApprovalStatus(string $bookingId): JsonResponse
    {
        try {
            $status = $this->bookingFlowService->getApprovalStatus($bookingId);

            return response()->json([
                'status' => 'success',
                'data' => $status,
                'message' => 'Approval status retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting approval status: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get approval status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Send booking for manager approval
     */
    public function requestManagerApproval(Request $request, string $bookingId): JsonResponse
    {
        $request->validate([
            'manager_id' => 'nullable|uuid|exists:users,id',
            'priority' => 'required|in:normal,high,urgent',
            'override_reasons' => 'required|array',
            'justification' => 'required|string|max:1000',
        ]);

        try {
            $approval = $this->bookingFlowService->requestManagerApproval($bookingId, $request->all());

            return response()->json([
                'status' => 'success',
                'data' => $approval,
                'message' => 'Manager approval requested successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error requesting manager approval: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to request manager approval',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Get dynamic pricing adjustments based on demand
     */
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

    /**
     * Validate booking rules and constraints
     */
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

    /**
     * Get alternative suggestions when primary choices are unavailable
     */
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

    /**
     * Process add-on dependencies and conflicts
     */
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

    /**
     * Generate booking confirmation document
     */
    public function generateBookingConfirmation(Request $request, string $bookingId): JsonResponse
    {
        $request->validate([
            'format' => 'required|in:pdf,email',
        ]);

        try {
            $confirmation = $this->bookingFlowService->generateBookingConfirmation(
                $bookingId,
                $request->input('format', 'pdf')
            );

            return response()->json([
                'status' => 'success',
                'data' => $confirmation,
                'message' => 'Booking confirmation generated successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error generating booking confirmation: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to generate booking confirmation',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Save booking as a draft for later completion
     */
    public function saveBookingDraft(Request $request): JsonResponse
    {
        try {
            $draft = $this->bookingFlowService->saveBookingDraft($request->all());

            return response()->json([
                'status' => 'success',
                'data' => new BookingFlowResource($draft),
                'message' => 'Booking draft saved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Draft saving failed: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to save booking draft',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Load an existing booking draft
     */
    public function loadBookingDraft(string $draftId): JsonResponse
    {
        try {
            $draft = $this->bookingFlowService->loadBookingDraft($draftId);

            return response()->json([
                'success' => true,
                'data' => new BookingFlowResource($draft)
            ]);
        } catch (\Exception $e) {
            Log::error('Loading draft failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'error' => 'Failed to load booking draft',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function getCorporates(Request $request): JsonResponse
    {
        $perPage = min((int) $request->get('per_page', 100), 200);

        $query = Corporate::query()->where('is_active', true)->orderBy('name');

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        $corporates = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => [
                'corporates' => $corporates->getCollection()->map(fn (Corporate $corporate) => [
                    'id' => (string) $corporate->id,
                    'name' => $corporate->name,
                    'contact_email' => $corporate->contact_email,
                    'contact_phone' => $corporate->contact_phone,
                    'approval_required' => (bool) $corporate->approval_required,
                ])->values(),
                'pagination' => [
                    'current_page' => $corporates->currentPage(),
                    'last_page' => $corporates->lastPage(),
                    'per_page' => $corporates->perPage(),
                    'total' => $corporates->total(),
                ],
            ],
        ]);
    }

    public function getCorporateDepartments(Request $request, string $corporateId): JsonResponse
    {
        $departments = CorporateDepartment::query()
            ->where('corporate_id', $corporateId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (CorporateDepartment $department) => [
                'id' => (string) $department->id,
                'name' => $department->name,
                'description' => $department->description,
            ])
            ->values();

        return response()->json([
            'status' => 'success',
            'data' => ['departments' => $departments],
        ]);
    }

    public function createCorporateDepartment(Request $request, string $corporateId): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $corporate = Corporate::findOrFail($corporateId);
        $department = $this->corporateService->createDepartment($corporate, $validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Department created successfully',
            'data' => [
                'department' => [
                    'id' => (string) $department->id,
                    'name' => $department->name,
                    'description' => $department->description,
                ],
            ],
        ], 201);
    }

    public function getCorporateEmployees(Request $request, string $corporateId): JsonResponse
    {
        $query = CorporateEmployee::query()
            ->where('corporate_id', $corporateId)
            ->where('is_active', true)
            ->with(['user', 'department', 'division'])
            ->orderBy('created_at');

        if ($request->filled('search')) {
            $query->whereHas('user', function ($userQuery) use ($request) {
                $userQuery->where('first_name', 'like', '%' . $request->search . '%')
                    ->orWhere('last_name', 'like', '%' . $request->search . '%')
                    ->orWhere('email', 'like', '%' . $request->search . '%')
                    ->orWhere('phone', 'like', '%' . $request->search . '%');
            });
        }

        $employees = $query->get();
        $customerIdsByUserId = Customer::query()
            ->whereIn('user_id', $employees->pluck('user_id')->filter()->values())
            ->pluck('id', 'user_id');

        return response()->json([
            'status' => 'success',
            'data' => [
                'employees' => $employees->map(function (CorporateEmployee $employee) use ($customerIdsByUserId) {
                    $user = $employee->user;

                    return [
                        'id' => (string) $employee->id,
                        'user_id' => (string) $employee->user_id,
                        'customer_id' => $customerIdsByUserId[$employee->user_id] ?? null,
                        'name' => trim(($user?->first_name ?? '') . ' ' . ($user?->last_name ?? '')),
                        'email' => $user?->email,
                        'phone' => $user?->phone,
                        'employee_code' => $employee->employee_code,
                        'department_id' => $employee->department_id ? (string) $employee->department_id : null,
                        'department' => $employee->department?->name,
                        'division_id' => $employee->division_id ? (string) $employee->division_id : null,
                        'division' => $employee->division?->name,
                        'user' => $user ? [
                            'id' => (string) $user->id,
                            'first_name' => $user->first_name,
                            'last_name' => $user->last_name,
                            'email' => $user->email,
                            'phone' => $user->phone,
                        ] : null,
                    ];
                })->values(),
            ],
        ]);
    }

    public function getCorporateDivisions(Request $request, string $corporateId, string $departmentId): JsonResponse
    {
        $department = CorporateDepartment::query()
            ->where('corporate_id', $corporateId)
            ->findOrFail($departmentId);

        $divisions = CorporateDivision::query()
            ->where('department_id', $department->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (CorporateDivision $division) => [
                'id' => (string) $division->id,
                'name' => $division->name,
                'description' => $division->description,
                'department_id' => (string) $division->department_id,
            ])
            ->values();

        return response()->json([
            'status' => 'success',
            'data' => ['divisions' => $divisions],
        ]);
    }

    public function createCorporateDivision(Request $request, string $corporateId, string $departmentId): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $department = CorporateDepartment::query()
            ->where('corporate_id', $corporateId)
            ->findOrFail($departmentId);

        $division = $this->corporateService->createDivision($department, $validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Division created successfully',
            'data' => [
                'division' => [
                    'id' => (string) $division->id,
                    'name' => $division->name,
                    'description' => $division->description,
                    'department_id' => (string) $division->department_id,
                ],
            ],
        ], 201);
    }

    public function createCorporateEmployee(Request $request, string $corporateId): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'department_id' => [
                'required',
                'uuid',
                Rule::exists('corporate_departments', 'id')->where(function ($query) use ($corporateId) {
                    $query->where('corporate_id', $corporateId)->where('is_active', true);
                }),
            ],
            'division_id' => [
                'nullable',
                'uuid',
                Rule::exists('corporate_divisions', 'id')->where(function ($query) use ($request) {
                    $query->where('department_id', $request->input('department_id'))->where('is_active', true);
                }),
            ],
            'employee_code' => ['nullable', 'string', 'max:50'],
            'role' => ['nullable', 'string', 'max:255'],
        ]);

        $corporate = Corporate::findOrFail($corporateId);

        $employee = $this->corporateService->addEmployee($corporate, [
            ...$validated,
            'role' => $validated['role'] ?? 'Corporate_Employee',
        ]);

        $employee->load(['user', 'department', 'division']);
        $customer = Customer::query()->where('user_id', $employee->user_id)->first();

        return response()->json([
            'status' => 'success',
            'message' => 'Employee created successfully',
            'data' => [
                'employee' => [
                    'id' => (string) $employee->id,
                    'user_id' => (string) $employee->user_id,
                    'customer_id' => $customer?->id ? (string) $customer->id : null,
                    'name' => trim(($employee->user?->first_name ?? '') . ' ' . ($employee->user?->last_name ?? '')),
                    'email' => $employee->user?->email,
                    'phone' => $employee->user?->phone,
                    'employee_code' => $employee->employee_code,
                    'department_id' => $employee->department_id ? (string) $employee->department_id : null,
                    'department' => $employee->department?->name,
                    'division_id' => $employee->division_id ? (string) $employee->division_id : null,
                    'division' => $employee->division?->name,
                    'user' => $employee->user ? [
                        'id' => (string) $employee->user->id,
                        'first_name' => $employee->user->first_name,
                        'last_name' => $employee->user->last_name,
                        'email' => $employee->user->email,
                        'phone' => $employee->user->phone,
                    ] : null,
                ],
            ],
        ], 201);
    }


    /**
     * Get all company locations for dropdown selection
     */
    public function getCompanyLocations(): JsonResponse
    {
        try {
            $locations = $this->bookingFlowService->getCompanyLocations();

            return response()->json([
                'status' => 'success',
                'data' => $locations
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting company locations: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get company locations',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Calculate optimal route through multiple waypoints
     */
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

            // Use Google Maps Service to calculate route
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

    /**
     * Get booking edit history and change log
     */
    public function getBookingEditHistory(string $bookingId): JsonResponse
    {
        try {
            $history = $this->bookingFlowService->getBookingEditHistory($bookingId);

            return response()->json([
                'status' => 'success',
                'data' => $history,
                'message' => 'Booking edit history retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting booking edit history: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve booking edit history',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Clone booking for creating similar booking
     */
    public function cloneBooking(string $bookingId): JsonResponse
    {
        try {
            $clonedBooking = $this->bookingFlowService->cloneBooking($bookingId, Auth::id());

            // Track analytics
            $this->bookingFlowService->trackAnalytics('booking_cloned', [
                'original_booking_id' => $bookingId,
                'cloned_booking_id' => $clonedBooking->id,
                'cloned_by' => Auth::id(),
                'clone_time' => now()
            ]);

            return response()->json([
                'status' => 'success',
                'data' => new BookingFlowResource($clonedBooking),
                'message' => 'Booking cloned successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error cloning booking: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to clone booking',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get booking analytics data
     */
    public function getBookingAnalytics(string $bookingId): JsonResponse
    {
        try {
            $analytics = $this->bookingFlowService->getBookingAnalytics($bookingId);

            return response()->json([
                'status' => 'success',
                'data' => $analytics,
                'message' => 'Booking analytics retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting booking analytics: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve booking analytics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // ========================
    // BOOKING LIST MANAGEMENT
    // ========================

    /**
     * Get paginated booking list with advanced filtering
     */
    public function getBookingsList(Request $request): JsonResponse
    {
        $request->validate([
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
            'search' => 'nullable|string|max:255',
            'status' => 'nullable',
            'status.*' => 'string|in:draft,pending_approval,approved,confirmed,allocated,in_progress,completed,cancelled',
            'item_status' => 'nullable',
            'item_status.*' => 'string|in:pending,confirmed,cancelled,completed',
            'service_type' => 'nullable|string',
            'customer_id' => 'nullable|uuid|exists:customers,id',
            'vehicle_group_id' => 'nullable|uuid|exists:vehicle_groups,id',
            'vehicle_id' => 'nullable|uuid|exists:vehicles,id',
            'driver_id' => 'nullable|uuid|exists:drivers,id',
            'assignment_status' => 'nullable',
            'assignment_status.*' => 'string|in:active,pending_approval,approved,completed,cancelled',
            'assignment_type' => 'nullable',
            'assignment_type.*' => 'string|in:primary,concurrent,override',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'sort_by' => 'nullable|string|in:created_at,booking_date,from_date,to_date,total_amount,total_actual,status,booking_status,priority',
            'sort_direction' => 'nullable|string|in:asc,desc',
            'sort_order' => 'nullable|string|in:asc,desc',
            'requires_approval' => 'nullable|boolean',
            'has_overrides' => 'nullable|boolean',
            'priority' => 'nullable|string|in:normal,high,urgent',
            'assigned_to' => 'nullable|uuid|exists:users,id',
            'created_by' => 'nullable|uuid|exists:users,id',
            'item_type' => 'nullable|string|max:100',
            'is_self_driven' => 'nullable|boolean',
            'corporate_id' => 'nullable|uuid',
        ]);

        try {
            $bookings = $this->bookingFlowService->getFilteredBookings($request->all());

            return response()->json([
                'status' => 'success',
                'data' => $bookings,
                'message' => 'Bookings retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting bookings list: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve bookings',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get single booking details for list view
     */
    public function getBookingDetails(string $bookingId): JsonResponse
    {
        try {
            $booking = $this->bookingFlowService->getBookingDetails($bookingId);

            return response()->json([
                'status' => 'success',
                'data' => $booking,
                'message' => 'Booking details retrieved successfully'
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Booking not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error getting booking details: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve booking details',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete/Cancel booking
     */
    public function deleteBooking(string $bookingId): JsonResponse
    {
        try {
            $result = $this->bookingFlowService->deleteBooking($bookingId, Auth::id());

            return response()->json([
                'status' => 'success',
                'data' => $result,
                'message' => $result['cancelled'] ? 'Booking cancelled successfully' : 'Booking deleted successfully'
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Booking not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error deleting booking: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete booking',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk operations on bookings
     */
    public function bulkOperations(Request $request): JsonResponse
    {
        $request->validate([
            'operation' => 'required|string|in:delete,approve,reject,assign_vehicle,assign_driver,change_status',
            'booking_ids' => 'required|array|min:1',
            'booking_ids.*' => 'uuid|exists:bookings,id',
            'data' => 'nullable|array',
            'reason' => 'nullable|string|max:500'
        ]);

        try {
            $result = $this->bookingFlowService->bulkOperations(
                $request->operation,
                $request->booking_ids,
                $request->data ?? [],
                Auth::id(),
                $request->reason
            );

            return response()->json([
                'status' => 'success',
                'data' => $result,
                'message' => 'Bulk operation completed successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error performing bulk operation: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to perform bulk operation',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // ========================
    // DASHBOARD & ANALYTICS
    // ========================

    /**
     * Get comprehensive dashboard statistics
     */
    public function getDashboardStats(Request $request): JsonResponse
    {
        $request->validate([
            'period' => 'nullable|string|in:today,week,month,quarter,year,custom',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'filters' => 'nullable|array'
        ]);

        try {
            $stats = $this->bookingFlowService->getDashboardStats($request->all());

            return response()->json([
                'status' => 'success',
                'data' => $stats,
                'message' => 'Dashboard statistics retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting dashboard stats: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve dashboard statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get booking trends and analytics
     */
    public function getBookingTrends(Request $request): JsonResponse
    {
        $request->validate([
            'period' => 'required|string|in:daily,weekly,monthly,quarterly',
            'metric' => 'required|string|in:count,revenue,average_value,completion_rate',
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
            'group_by' => 'nullable|string|in:service_type,vehicle_group,customer_type,status'
        ]);

        try {
            $trends = $this->bookingFlowService->getBookingTrends($request->all());

            return response()->json([
                'status' => 'success',
                'data' => $trends,
                'message' => 'Booking trends retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting booking trends: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve booking trends',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get revenue analytics
     */
    public function getRevenueAnalytics(Request $request): JsonResponse
    {
        $request->validate([
            'period' => 'required|string|in:daily,weekly,monthly,quarterly,yearly',
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
            'breakdown' => 'nullable|string|in:service_type,vehicle_group,customer_segment',
            'include_projections' => 'nullable|boolean'
        ]);

        try {
            $analytics = $this->bookingFlowService->getRevenueAnalytics($request->all());

            return response()->json([
                'status' => 'success',
                'data' => $analytics,
                'message' => 'Revenue analytics retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting revenue analytics: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve revenue analytics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get utilization reports
     */
    public function getUtilizationReports(Request $request): JsonResponse
    {
        $request->validate([
            'type' => 'required|string|in:vehicle,driver,service_type,time_based',
            'period' => 'required|string|in:daily,weekly,monthly',
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
            'vehicle_group_id' => 'nullable|uuid|exists:vehicle_groups,id',
            'include_idle_time' => 'nullable|boolean'
        ]);

        try {
            $reports = $this->bookingFlowService->getUtilizationReports($request->all());

            return response()->json([
                'status' => 'success',
                'data' => $reports,
                'message' => 'Utilization reports retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting utilization reports: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve utilization reports',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get customer analytics
     */
    public function getCustomerAnalytics(Request $request): JsonResponse
    {
        $request->validate([
            'metric' => 'required|string|in:acquisition,retention,lifetime_value,booking_frequency',
            'period' => 'required|string|in:monthly,quarterly,yearly',
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
            'customer_segment' => 'nullable|string|in:corporate,individual,vip'
        ]);

        try {
            $analytics = $this->bookingFlowService->getCustomerAnalytics($request->all());

            return response()->json([
                'status' => 'success',
                'data' => $analytics,
                'message' => 'Customer analytics retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting customer analytics: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve customer analytics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate comprehensive reports
     */
    public function generateReport(Request $request): JsonResponse
    {
        $request->validate([
            'report_type' => 'required|string|in:financial,operational,customer,vehicle_performance,driver_performance',
            'format' => 'nullable|string|in:pdf,excel,csv',
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
            'filters' => 'nullable|array',
            'include_charts' => 'nullable|boolean',
            'email_to' => 'nullable|email'
        ]);

        try {
            $report = $this->bookingFlowService->generateReport($request->all());

            return response()->json([
                'status' => 'success',
                'data' => $report,
                'message' => 'Report generated successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error generating report: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to generate report',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export bookings data
     */
    public function exportBookings(Request $request): JsonResponse
    {
        $request->validate([
            'format' => 'required|string|in:excel,csv,pdf',
            'filters' => 'nullable|array',
            'columns' => 'nullable|array',
            'include_relations' => 'nullable|boolean'
        ]);

        try {
            $export = $this->bookingFlowService->exportBookings($request->all());

            return response()->json([
                'status' => 'success',
                'data' => $export,
                'message' => 'Bookings exported successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error exporting bookings: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to export bookings',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get available currencies
     */
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

    /**
     * Convert amount between currencies
     */
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

    /**
     * Get booking addons for debugging
     */
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

    /**
     * Update addon pricing without triggering full recalculation
     */
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
            // For now, just return the pricing data as this method needs to be implemented in service
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

    /**
     * Get customizable variables for base pricing
     */
    public function getCustomizableVariables(Request $request): JsonResponse
    {
        $request->validate([
            'service_type_id' => 'required|string',
            'vehicle_group_id' => 'sometimes|string',
            'vehicle_group_ids' => 'sometimes|array',
            'vehicle_group_ids.*' => 'string',
            'booking_id' => 'nullable|string',
        ]);

        // Support both single and multiple vehicle groups
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

    /**
     * Store variable customizations
     */
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

    /**
     * Get existing variable customizations
     */
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

    /**
     * Select vehicle with enhanced assignment details
     */
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

    /**
     * Get alternative assignments when conflicts exist
     */
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

    /**
     * Process assignment confirmation (for concurrent/override scenarios)
     */
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

    /**
     * Approve vehicle and driver assignments
     */
    public function approveAssignments(Request $request): JsonResponse
    {
        $request->validate([
            'booking_id' => 'required|string|exists:bookings,id',
        ]);

        try {
            $result = $this->assignmentService->approveAssignments(
                $request->booking_id,
                Auth::id()
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
