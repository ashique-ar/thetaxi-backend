<?php

namespace App\Http\Controllers\Api\Vehicle\VehiclePricing;

use App\Http\Controllers\Controller;
use App\Models\Corporate\Corporate;
use App\Models\Vehicle\VehiclePricing\PriceAdjustment;
use App\Models\Service\ServiceType;
use App\Models\Vehicle\VehicleGroup;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;
use App\Services\Pricing\PricingContextPolicyService;

class PriceAdjustmentController extends Controller
{
    public function __construct(private readonly PricingContextPolicyService $pricingContextPolicy)
    {
        $this->middleware('permission:price-adjustments.view')->only(['index', 'show', 'getServiceTypes', 'getVehicleGroups', 'getApplicableAdjustments', 'applyAdjustments', 'getUsageStatistics']);
        $this->middleware('permission:price-adjustments.create')->only(['store']);
        $this->middleware('permission:price-adjustments.edit')->only(['update', 'bulkUpdateStatus', 'toggleStatus']);
        $this->middleware('permission:price-adjustments.delete')->only(['destroy']);
    }

    private function normalizeAdjustmentPayload(Request $request): array
    {
        $payload = $request->all();
        $payload['applies_to'] = $payload['applies_to'] ?? 'total_price';
        $contexts = $payload['applicable_contexts'] ?? PriceAdjustment::DEFAULT_APPLICABLE_CONTEXTS;
        $payload['applicable_contexts'] = is_array($contexts)
            ? array_values($contexts)
            : $contexts;
        if (
            is_array($payload['applicable_contexts'])
            && $this->pricingContextPolicy->internalUsesWebsitePricing()
        ) {
            $payload['applicable_contexts'] = array_values(array_unique(array_map(
                fn(mixed $context) => (string) $context === 'portal' ? 'public' : (string) $context,
                $payload['applicable_contexts']
            )));
        }
        if (($payload['scope'] ?? null) === 'service_vehicle_group') {
            $payload['scope'] = 'vehicle_group';
        }

        if (($payload['scope'] ?? null) === 'global') {
            $payload['service_type_id'] = null;
            $payload['vehicle_group_id'] = null;
        } elseif (($payload['scope'] ?? null) === 'service') {
            $payload['vehicle_group_id'] = null;
        }

        return $payload;
    }

    /**
     * Display a listing of price adjustments
     */
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'page' => 'integer|min:1',
            'per_page' => 'integer|min:1|max:100',
            'search' => 'string|max:255',
            'scope' => 'in:global,service,vehicle_group,service_vehicle_group',
            'service_type_id' => 'uuid|exists:service_types,id',
            'vehicle_group_id' => 'uuid|exists:vehicle_groups,id',
            'owner_type' => 'nullable|string|in:corporate',
            'owner_id' => 'nullable|uuid|exists:corporates,id|required_with:owner_type',
            'adjustment_type' => 'in:percentage,fixed_amount',
            'applies_to' => 'in:base_price,total_price,km_charges',
            'applicable_context' => 'nullable|string|in:portal,public,corporate',
            'is_active' => 'any',
            'is_cumulative' => 'boolean',
            'sort_by' => 'in:name,created_at,priority,valid_from,valid_to',
            'sort_direction' => 'in:asc,desc',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $query = PriceAdjustment::withInactive()->with(['serviceType', 'vehicleGroup']);

            // Apply filters
            if ($request->filled('search')) {
                $search = trim((string) $request->search);
                $normalizedSearch = mb_strtolower($search);

                $query->where(function (Builder $q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                })->where(function (Builder $q) use ($search, $normalizedSearch) {
                    $q->where(function (Builder $sub) use ($search) {
                        $sub->where('name', 'like', "%{$search}%")
                            ->orWhere('description', 'like', "%{$search}%")
                            ->orWhereHas('serviceType', function (Builder $serviceQuery) use ($search) {
                                $serviceQuery->where('name', 'like', "%{$search}%")
                                    ->orWhere('code', 'like', "%{$search}%");
                            })
                            ->orWhereHas('vehicleGroup', function (Builder $groupQuery) use ($search) {
                                $groupQuery->where('name', 'like', "%{$search}%")
                                    ->orWhere('description', 'like', "%{$search}%");
                            });
                    });

                    if (
                        str_contains($normalizedSearch, 'global') ||
                        str_contains($normalizedSearch, 'all services') ||
                        $normalizedSearch === 'all'
                    ) {
                        $q->orWhere(function (Builder $scopeQuery) {
                            $scopeQuery->whereNull('service_type_id')
                                ->whereNull('vehicle_group_id');
                        });
                    }

                    if (
                        str_contains($normalizedSearch, 'service') &&
                        !str_contains($normalizedSearch, 'vehicle')
                    ) {
                        $q->orWhere(function (Builder $scopeQuery) {
                            $scopeQuery->whereNotNull('service_type_id')
                                ->whereNull('vehicle_group_id');
                        });
                    }

                    if (
                        str_contains($normalizedSearch, 'vehicle group') &&
                        !str_contains($normalizedSearch, 'service')
                    ) {
                        $q->orWhere(function (Builder $scopeQuery) {
                            $scopeQuery->whereNull('service_type_id')
                                ->whereNotNull('vehicle_group_id');
                        });
                    }

                    if (
                        str_contains('service vehicle group service + vehicle group vehicle group in service type', $normalizedSearch) ||
                        str_contains($normalizedSearch, 'service vehicle') ||
                        str_contains($normalizedSearch, 'vehicle group in')
                    ) {
                        $q->orWhere(function (Builder $scopeQuery) {
                            $scopeQuery->whereNotNull('service_type_id')
                                ->whereNotNull('vehicle_group_id');
                        });
                    }
                });
            }

            if ($request->filled('scope')) {
                if ($request->scope === 'service_vehicle_group') {
                    $query->where('scope', 'vehicle_group')
                        ->whereNotNull('service_type_id')
                        ->whereNotNull('vehicle_group_id');
                } else {
                    $query->where('scope', $request->scope);
                }
            }

            if ($request->filled('service_type_id')) {
                $query->where('service_type_id', $request->service_type_id);
            }

            if ($request->filled('vehicle_group_id')) {
                $query->where('vehicle_group_id', $request->vehicle_group_id);
            }

            if ($request->filled('owner_type')) {
                $query->where('owner_type', $request->owner_type)
                    ->where('owner_id', $request->owner_id);
            } elseif ($request->boolean('global_only', false)) {
                $query->whereNull('owner_type')->whereNull('owner_id');
            }

            if ($request->filled('adjustment_type')) {
                $query->where('adjustment_type', $request->adjustment_type);
            }

            if ($request->filled('applies_to')) {
                $query->where('applies_to', $request->applies_to);
            }

            if ($request->filled('applicable_context')) {
                $query->forPricingContext((string) $request->applicable_context);
            }

            // Only apply is_active filter if explicitly set (not empty or 'all')
            if ($request->filled('is_active') && $request->is_active !== '' && $request->is_active !== 'all') {
                $query->where('is_active', $request->boolean('is_active'));
            }

            if ($request->has('is_cumulative')) {
                $query->where('is_cumulative', $request->boolean('is_cumulative'));
            }

            // Apply sorting
            $sortBy = $request->input('sort_by', 'priority');
            $sortDirection = $request->input('sort_direction', 'desc');
            $query->orderBy($sortBy, $sortDirection);

            // Paginate results
            $perPage = $request->input('per_page', 15);
            $adjustments = $query->paginate($perPage);

            return response()->json([
                'message' => 'Price adjustments retrieved successfully',
                'data' => $adjustments->items(),
                'meta' => [
                    'current_page' => $adjustments->currentPage(),
                    'per_page' => $adjustments->perPage(),
                    'total' => $adjustments->total(),
                    'last_page' => $adjustments->lastPage(),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to retrieve price adjustments: " . $e->getMessage());
            return response()->json([
                'message' => 'Failed to retrieve price adjustments',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created price adjustment
     */
    public function store(Request $request): JsonResponse
    {
        $payload = $this->normalizeAdjustmentPayload($request);
        $validator = Validator::make($payload, PriceAdjustment::validationRules());

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $adjustment = PriceAdjustment::create($validator->validated());
            $adjustment->load(['serviceType', 'vehicleGroup']);

            Log::info("Price adjustment created", [
                'adjustment_id' => $adjustment->id,
                'name' => $adjustment->name,
                'scope' => $adjustment->scope,
                'created_by' => auth()->id()
            ]);

            return response()->json([
                'message' => 'Price adjustment created successfully',
                'data' => $adjustment
            ], 201);

        } catch (\Exception $e) {
            Log::error("Failed to create price adjustment: " . $e->getMessage(), [
                'request_data' => $request->all()
            ]);
            return response()->json([
                'message' => 'Failed to create price adjustment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified price adjustment
     */
    public function show(string $id): JsonResponse
    {
        try {
            $adjustment = PriceAdjustment::withInactive()->with(['serviceType', 'vehicleGroup'])
                ->findOrFail($id);

            return response()->json([
                'message' => 'Price adjustment retrieved successfully',
                'data' => $adjustment
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Price adjustment not found',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Update the specified price adjustment
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $payload = $this->normalizeAdjustmentPayload($request);
        $validator = Validator::make($payload, PriceAdjustment::validationRules());

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $adjustment = PriceAdjustment::withInactive()->findOrFail($id);
            $adjustment->update($validator->validated());
            $adjustment->load(['serviceType', 'vehicleGroup']);

            Log::info("Price adjustment updated", [
                'adjustment_id' => $adjustment->id,
                'name' => $adjustment->name,
                'updated_by' => auth()->id()
            ]);

            return response()->json([
                'message' => 'Price adjustment updated successfully',
                'data' => $adjustment
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to update price adjustment: " . $e->getMessage(), [
                'adjustment_id' => $id,
                'request_data' => $request->all()
            ]);
            return response()->json([
                'message' => 'Failed to update price adjustment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified price adjustment
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $adjustment = PriceAdjustment::withInactive()->findOrFail($id);
            $adjustmentName = $adjustment->name;
            $adjustment->delete();

            Log::info("Price adjustment deleted", [
                'adjustment_id' => $id,
                'name' => $adjustmentName,
                'deleted_by' => auth()->id()
            ]);

            return response()->json([
                'message' => 'Price adjustment deleted successfully'
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to delete price adjustment: " . $e->getMessage(), [
                'adjustment_id' => $id
            ]);
            return response()->json([
                'message' => 'Failed to delete price adjustment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Toggle the active status of a price adjustment
     */
    public function toggleStatus(string $id): JsonResponse
    {
        try {
            $adjustment = PriceAdjustment::withInactive()->findOrFail($id);
            $adjustment->is_active = !$adjustment->is_active;
            $adjustment->save();

            Log::info("Price adjustment status toggled", [
                'adjustment_id' => $id,
                'new_status' => $adjustment->is_active ? 'active' : 'inactive',
                'updated_by' => auth()->id()
            ]);

            return response()->json([
                'message' => 'Price adjustment status updated successfully',
                'data' => $adjustment
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to toggle price adjustment status: " . $e->getMessage(), [
                'adjustment_id' => $id
            ]);
            return response()->json([
                'message' => 'Failed to toggle price adjustment status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get applicable price adjustments for given parameters
     */
    public function getApplicableAdjustments(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0',
            'service_type_id' => 'nullable|uuid|exists:service_types,id',
            'vehicle_group_id' => 'nullable|uuid|exists:vehicle_groups,id',
            'price_component' => 'string|in:base_price,total_price,km_charges',
            'date' => 'nullable|date',
            'pricing_context' => 'nullable|string|in:portal,public,corporate',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $amount = $request->amount;
            $serviceTypeId = $request->service_type_id;
            $vehicleGroupId = $request->vehicle_group_id;
            $priceComponent = $request->input('price_component', 'total_price');
            $date = $request->date ? Carbon::parse($request->date) : now();

            $applicableAdjustments = PriceAdjustment::getApplicableAdjustments(
                $amount,
                $serviceTypeId,
                $vehicleGroupId,
                $priceComponent,
                $date,
                null,
                null,
                null,
                $request->input('pricing_context', 'public')
            );

            return response()->json([
                'message' => 'Applicable price adjustments retrieved successfully',
                'data' => $applicableAdjustments,
                'count' => $applicableAdjustments->count(),
                'parameters' => [
                    'amount' => $amount,
                    'service_type_id' => $serviceTypeId,
                    'vehicle_group_id' => $vehicleGroupId,
                    'price_component' => $priceComponent,
                    'date' => $date->toISOString(),
                    'pricing_context' => $request->input('pricing_context', 'public'),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to get applicable price adjustments: " . $e->getMessage(), [
                'request_data' => $request->all()
            ]);
            return response()->json([
                'message' => 'Failed to get applicable price adjustments',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Apply price adjustments to given amount
     */
    public function applyAdjustments(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0',
            'service_type_id' => 'nullable|uuid|exists:service_types,id',
            'vehicle_group_id' => 'nullable|uuid|exists:vehicle_groups,id',
            'price_component' => 'string|in:base_price,total_price,km_charges',
            'date' => 'nullable|date',
            'pricing_context' => 'nullable|string|in:portal,public,corporate',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $amount = $request->amount;
            $serviceTypeId = $request->service_type_id;
            $vehicleGroupId = $request->vehicle_group_id;
            $priceComponent = $request->input('price_component', 'total_price');
            $date = $request->date ? Carbon::parse($request->date) : now();

            $adjustmentResult = PriceAdjustment::applyAdjustments(
                $amount,
                $serviceTypeId,
                $vehicleGroupId,
                $priceComponent,
                $date,
                null,
                null,
                null,
                $request->input('pricing_context', 'public')
            );

            return response()->json([
                'message' => 'Price adjustments applied successfully',
                'data' => $adjustmentResult,
                'parameters' => [
                    'amount' => $amount,
                    'service_type_id' => $serviceTypeId,
                    'vehicle_group_id' => $vehicleGroupId,
                    'price_component' => $priceComponent,
                    'date' => $date->toISOString(),
                    'pricing_context' => $request->input('pricing_context', 'public'),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to apply price adjustments: " . $e->getMessage(), [
                'request_data' => $request->all()
            ]);
            return response()->json([
                'message' => 'Failed to apply price adjustments',
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
     * Bulk update status of multiple adjustments
     */
    public function bulkUpdateStatus(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'adjustment_ids' => 'required|array|min:1',
            'adjustment_ids.*' => 'uuid|exists:price_adjustments,id',
            'is_active' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $updated = PriceAdjustment::whereIn('id', $request->adjustment_ids)
                ->update(['is_active' => $request->is_active]);

            Log::info("Bulk updated price adjustments status", [
                'adjustment_ids' => $request->adjustment_ids,
                'new_status' => $request->is_active ? 'active' : 'inactive',
                'updated_count' => $updated,
                'updated_by' => auth()->id()
            ]);

            return response()->json([
                'message' => "Successfully updated {$updated} price adjustments",
                'updated_count' => $updated
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to bulk update price adjustments: " . $e->getMessage(), [
                'request_data' => $request->all()
            ]);
            return response()->json([
                'message' => 'Failed to bulk update price adjustments',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get adjustment usage statistics
     */
    public function getUsageStatistics(string $id): JsonResponse
    {
        try {
            $adjustment = PriceAdjustment::with(['bookingAdjustmentHistory'])
                ->findOrFail($id);

            $stats = [
                'adjustment_id' => $adjustment->id,
                'name' => $adjustment->name,
                'usage_count' => $adjustment->usage_count,
                'usage_limit' => $adjustment->usage_limit,
                'usage_percentage' => $adjustment->usage_limit
                    ? round(($adjustment->usage_count / $adjustment->usage_limit) * 100, 2)
                    : null,
                'remaining_uses' => $adjustment->usage_limit
                    ? max(0, $adjustment->usage_limit - $adjustment->usage_count)
                    : null,
                'is_unlimited' => $adjustment->usage_limit === null,
                'is_exhausted' => $adjustment->usage_limit !== null && $adjustment->usage_count >= $adjustment->usage_limit,
                'total_bookings_affected' => $adjustment->bookingAdjustmentHistory->count(),
                'valid_from' => $adjustment->valid_from->toISOString(),
                'valid_to' => $adjustment->valid_to->toISOString(),
                'is_currently_valid' => $adjustment->isValid(),
            ];

            return response()->json([
                'message' => 'Price adjustment usage statistics retrieved successfully',
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Price adjustment not found',
                'error' => $e->getMessage()
            ], 404);
        }
    }
}
