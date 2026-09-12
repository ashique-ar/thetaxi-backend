<?php

namespace App\Http\Controllers\Api\Vehicle\VehiclePricing;

use App\Http\Controllers\Controller;
use App\Models\Corporate\Corporate;
use App\Models\Vehicle\VehiclePricing\KmRangePricingRule;
use App\Models\Service\ServiceType;
use App\Models\Vehicle\VehicleGroup;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

class KmRangePricingController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:km-range-pricing.view')->only(['index', 'show', 'getServiceTypes', 'getVehicleGroups', 'getApplicableRules', 'calculatePricing']);
        $this->middleware('permission:km-range-pricing.create')->only(['store']);
        $this->middleware('permission:km-range-pricing.edit')->only(['update', 'bulkUpdateStatus', 'toggleStatus']);
        $this->middleware('permission:km-range-pricing.delete')->only(['destroy']);
    }

    /**
     * Display a listing of KM-range pricing rules
     */
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'page' => 'integer|min:1',
            'per_page' => 'integer|min:1|max:100',
            'search' => 'string|max:255',
            'scope' => 'in:global,service,vehicle_group',
            'service_type_id' => 'uuid|exists:service_types,id',
            'vehicle_group_id' => 'uuid|exists:vehicle_groups,id',
            'distance_type' => 'in:journey_distance,pickup_distance,delivery_distance',
            'pricing_context' => 'nullable|string|in:portal,public,corporate',
            'owner_type' => 'nullable|string|in:corporate',
            'owner_id' => 'nullable|uuid|exists:corporates,id|required_with:owner_type',
            'is_active' => 'boolean',
            'sort_by' => 'in:name,created_at,priority,from_km,to_km',
            'sort_direction' => 'in:asc,desc',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $query = KmRangePricingRule::withInactive()->with(['serviceType', 'vehicleGroup']);

            // Apply filters
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function (Builder $q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%");
                });
            }

            if ($request->filled('scope')) {
                $query->where('scope', $request->scope);
            }

            if ($request->filled('service_type_id')) {
                $query->where('service_type_id', $request->service_type_id);
            }

            if ($request->filled('vehicle_group_id')) {
                $query->where('vehicle_group_id', $request->vehicle_group_id);
            }

            if ($request->filled('distance_type')) {
                $query->whereJsonContains('distance_types', $request->distance_type);
            }

            if ($request->filled('pricing_context')) {
                $query->forPricingContext($request->input('pricing_context'));
            }

            if ($request->filled('owner_type')) {
                $query->where('owner_type', $request->owner_type)
                    ->where('owner_id', $request->owner_id);
            } elseif ($request->boolean('global_only', false)) {
                $query->whereNull('owner_type')->whereNull('owner_id');
            }

            // Only apply is_active filter if explicitly set (not empty or 'all')
            if ($request->filled('is_active') && $request->is_active !== '' && $request->is_active !== 'all') {
                $query->where('is_active', $request->boolean('is_active'));
            }

            // Apply sorting
            $sortBy = $request->input('sort_by', 'priority');
            $sortDirection = $request->input('sort_direction', 'desc');
            $query->orderBy($sortBy, $sortDirection);

            // Paginate results
            $perPage = $request->input('per_page', 15);
            $rules = $query->paginate($perPage);

            return response()->json([
                'message' => 'KM-range pricing rules retrieved successfully',
                'data' => $rules->items(),
                'meta' => [
                    'current_page' => $rules->currentPage(),
                    'per_page' => $rules->perPage(),
                    'total' => $rules->total(),
                    'last_page' => $rules->lastPage(),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to retrieve KM-range pricing rules: " . $e->getMessage());
            return response()->json([
                'message' => 'Failed to retrieve KM-range pricing rules',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created KM-range pricing rule
     */
    public function store(Request $request): JsonResponse
    {
        if (!$request->has('applicable_contexts')) {
            $request->merge(['applicable_contexts' => KmRangePricingRule::DEFAULT_APPLICABLE_CONTEXTS]);
        }
        $validator = Validator::make($request->all(), KmRangePricingRule::validationRules());

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $rule = KmRangePricingRule::create($validator->validated());
            $rule->load(['serviceType', 'vehicleGroup']);


            return response()->json([
                'message' => 'KM-range pricing rule created successfully',
                'data' => $rule
            ], 201);

        } catch (\Exception $e) {
            Log::error("Failed to create KM-range pricing rule: " . $e->getMessage(), [
                'request_data' => $request->all()
            ]);
            return response()->json([
                'message' => 'Failed to create KM-range pricing rule',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified KM-range pricing rule
     */
    public function show(string $id): JsonResponse
    {
        try {
            $rule = KmRangePricingRule::with(['serviceType', 'vehicleGroup'])
                ->findOrFail($id);

            return response()->json([
                'message' => 'KM-range pricing rule retrieved successfully',
                'data' => $rule
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'KM-range pricing rule not found',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Update the specified KM-range pricing rule
     */
    public function update(Request $request, string $id): JsonResponse
    {
        if (!$request->has('applicable_contexts')) {
            $existingContexts = KmRangePricingRule::withInactive()->findOrFail($id)->applicable_contexts;
            $request->merge([
                'applicable_contexts' => $existingContexts ?: KmRangePricingRule::DEFAULT_APPLICABLE_CONTEXTS,
            ]);
        }
        $validator = Validator::make($request->all(), KmRangePricingRule::validationRules());

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $rule = KmRangePricingRule::findOrFail($id);
            $rule->update($validator->validated());
            $rule->load(['serviceType', 'vehicleGroup']);


            return response()->json([
                'message' => 'KM-range pricing rule updated successfully',
                'data' => $rule
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to update KM-range pricing rule: " . $e->getMessage(), [
                'rule_id' => $id,
                'request_data' => $request->all()
            ]);
            return response()->json([
                'message' => 'Failed to update KM-range pricing rule',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified KM-range pricing rule
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $rule = KmRangePricingRule::findOrFail($id);
            $ruleName = $rule->name;
            $rule->delete();


            return response()->json([
                'message' => 'KM-range pricing rule deleted successfully'
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to delete KM-range pricing rule: " . $e->getMessage(), [
                'rule_id' => $id
            ]);
            return response()->json([
                'message' => 'Failed to delete KM-range pricing rule',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Toggle the active status of a KM-range pricing rule
     */
    public function toggleStatus(string $id): JsonResponse
    {
        try {
            $rule = KmRangePricingRule::findOrFail($id);
            $rule->is_active = !$rule->is_active;
            $rule->save();


            return response()->json([
                'message' => 'KM-range pricing rule status updated successfully',
                'data' => $rule
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to toggle KM-range pricing rule status: " . $e->getMessage(), [
                'rule_id' => $id
            ]);
            return response()->json([
                'message' => 'Failed to toggle KM-range pricing rule status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get applicable KM-range pricing rules for given parameters
     */
    public function getApplicableRules(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'distance' => 'required|numeric|min:0',
            'service_type_id' => 'nullable|uuid|exists:service_types,id',
            'vehicle_group_id' => 'nullable|uuid|exists:vehicle_groups,id',
            'distance_type' => 'sometimes|in:journey_distance,pickup_distance,delivery_distance',
            'pricing_context' => 'sometimes|string|in:portal,public,corporate',
            'date' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $distance = $request->distance;
            $serviceTypeId = $request->service_type_id;
            $vehicleGroupId = $request->vehicle_group_id;
            $date = $request->date ? Carbon::parse($request->date) : now();

            $applicableRules = KmRangePricingRule::getApplicableRules(
                $distance,
                $serviceTypeId,
                $vehicleGroupId,
                $date,
                null,
                null,
                $request->input('distance_type', 'journey_distance'),
                $request->input('pricing_context', 'public')
            );

            return response()->json([
                'message' => 'Applicable KM-range pricing rules retrieved successfully',
                'data' => $applicableRules,
                'count' => $applicableRules->count(),
                'parameters' => [
                    'distance' => $distance,
                    'service_type_id' => $serviceTypeId,
                    'vehicle_group_id' => $vehicleGroupId,
                    'distance_type' => $request->input('distance_type', 'journey_distance'),
                    'pricing_context' => $request->input('pricing_context', 'public'),
                    'date' => $date->toISOString(),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to get applicable KM-range pricing rules: " . $e->getMessage(), [
                'request_data' => $request->all()
            ]);
            return response()->json([
                'message' => 'Failed to get applicable KM-range pricing rules',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Calculate pricing for given distance and rules
     */
    public function calculatePricing(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'distance' => 'required|numeric|min:0',
            'base_amount' => 'required|numeric|min:0',
            'service_type_id' => 'nullable|uuid|exists:service_types,id',
            'vehicle_group_id' => 'nullable|uuid|exists:vehicle_groups,id',
            'distance_type' => 'sometimes|in:journey_distance,pickup_distance,delivery_distance',
            'pricing_context' => 'sometimes|string|in:portal,public,corporate',
            'date' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $distance = $request->distance;
            $baseAmount = $request->base_amount;
            $serviceTypeId = $request->service_type_id;
            $vehicleGroupId = $request->vehicle_group_id;
            $date = $request->date ? Carbon::parse($request->date) : now();

            $pricingResult = KmRangePricingRule::calculateBestPricing(
                $distance,
                $baseAmount,
                $serviceTypeId,
                $vehicleGroupId,
                $date,
                null,
                null,
                $request->input('distance_type', 'journey_distance'),
                $request->input('pricing_context', 'public')
            );

            return response()->json([
                'message' => 'KM-range pricing calculated successfully',
                'data' => $pricingResult,
                'parameters' => [
                    'distance' => $distance,
                    'base_amount' => $baseAmount,
                    'service_type_id' => $serviceTypeId,
                    'vehicle_group_id' => $vehicleGroupId,
                    'distance_type' => $request->input('distance_type', 'journey_distance'),
                    'pricing_context' => $request->input('pricing_context', 'public'),
                    'date' => $date->toISOString(),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to calculate KM-range pricing: " . $e->getMessage(), [
                'request_data' => $request->all()
            ]);
            return response()->json([
                'message' => 'Failed to calculate KM-range pricing',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get service types for dropdown
     */
    public function getServiceTypes(Request $request): JsonResponse
    {
        try {
            $context = (string) $request->input('context', 'public');
            $ownerType = (string) $request->input('owner_type', ($context === 'corporate' ? 'corporate' : ''));
            $ownerId = (string) $request->input('owner_id', '');

            if ($context === 'corporate' && $ownerType === 'corporate' && $ownerId !== '') {
                $serviceTypes = Corporate::findOrFail($ownerId)
                    ->serviceTypes()
                    ->where('service_types.context', 'corporate')
                    ->where('service_types.owner_type', '')
                    ->where('service_types.owner_id', '')
                    ->select('service_types.id', 'service_types.name', 'service_types.code', 'service_types.context', 'service_types.owner_type', 'service_types.owner_id')
                    ->where('service_types.is_active', true)
                    ->orderBy('service_types.name')
                    ->get();
            } else {
                $serviceTypes = ServiceType::forContext($context, '', '')
                    ->select('id', 'name', 'code', 'context', 'owner_type', 'owner_id')
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->get();
            }

            return response()->json([
                'message' => 'Service types retrieved successfully',
                'data' => $serviceTypes
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve service types',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get vehicle groups for dropdown
     */
    public function getVehicleGroups(): JsonResponse
    {
        try {
            $vehicleGroups = VehicleGroup::select('id', 'name', 'description')
                ->where('is_active', true)
                ->orderBy('name')
                ->get();

            return response()->json([
                'message' => 'Vehicle groups retrieved successfully',
                'data' => $vehicleGroups
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve vehicle groups',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk update status of multiple rules
     */
    public function bulkUpdateStatus(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'rule_ids' => 'required|array|min:1',
            'rule_ids.*' => 'uuid|exists:km_range_pricing_rules,id',
            'is_active' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $updated = KmRangePricingRule::whereIn('id', $request->rule_ids)
                ->update(['is_active' => $request->is_active]);


            return response()->json([
                'message' => "Successfully updated {$updated} KM-range pricing rules",
                'updated_count' => $updated
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to bulk update KM-range pricing rules: " . $e->getMessage(), [
                'request_data' => $request->all()
            ]);
            return response()->json([
                'message' => 'Failed to bulk update KM-range pricing rules',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
