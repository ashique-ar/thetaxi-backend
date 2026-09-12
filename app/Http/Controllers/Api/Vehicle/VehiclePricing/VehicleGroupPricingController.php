<?php

namespace App\Http\Controllers\Api\Vehicle\VehiclePricing;

use App\Http\Controllers\Controller;
use App\Models\Vehicle\VehiclePricing\VehicleGroupPricing;
use App\Models\Vehicle\VehiclePricing\VehiclePricingSlabDefinition;
use App\Models\Vehicle\VehiclePricing\VehicleGroupCommonRatePricing;
use App\Models\Vehicle\VehiclePricing\VehicleGroupServicePricingSetting;
use App\Models\Vehicle\VehiclePricing\VehiclePricingCommonRateDefinition;
use App\Models\Vehicle\VehiclePricing\VehiclePricingBulkOperation;
use App\Models\Vehicle\VehicleGroup;
use App\Models\Service\ServiceType;
use App\Models\Corporate\Corporate;
use App\Models\Vehicle\VehiclePricingHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;

class VehicleGroupPricingController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:vehicle-group-pricing.view')->only(['index', 'show', 'matrix', 'unifiedPricing', 'getBulkOperations', 'getBulkOperationStatus', 'downloadBulkOperationReport']);
        $this->middleware('permission:vehicle-group-pricing.create')->only(['store', 'bulkStore']);
        $this->middleware('permission:vehicle-group-pricing.edit')->only(['update', 'toggleStatus', 'bulkUpdate', 'copyRates', 'saveVehicleGroupPricing', 'cancelBulkOperation', 'retryBulkOperation']);
        $this->middleware('permission:vehicle-group-pricing.delete')->only(['destroy', 'bulkDelete']);
        $this->middleware('permission:vehicle-group-pricing.manage')->only(['syncCommonRates', 'exportPricing', 'importPricing']);
    }

    public function index(Request $request): JsonResponse
    {
        $query = VehicleGroupPricing::with(['slabDefinition.serviceType', 'vehicleGroup']);

        if ($request->filled('service_type_id')) {
            $query->whereHas('slabDefinition', fn ($q) => $q->where('service_type_id', $request->service_type_id));
        }

        if ($request->filled('vehicle_group_id')) {
            $query->where('vehicle_group_id', $request->vehicle_group_id);
        }

        if ($request->filled('context')) {
            $query->whereHas('slabDefinition.serviceType', fn ($q) => $q->where('context', $request->context));
        }

        if ($request->filled('owner_type')) {
            $query->where('owner_type', $request->owner_type)->where('owner_id', $request->owner_id);
        } elseif ($request->boolean('global_only', false)) {
            $query->whereNull('owner_type')->whereNull('owner_id');
        }

        return response()->json([
            'success' => true,
            'data' => $query->orderByDesc('priority')->paginate($request->input('per_page', 25)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateVehicleGroupPricing($request);
        $pricing = VehicleGroupPricing::create($data);

        return response()->json([
            'success' => true,
            'data' => $pricing->load(['slabDefinition.serviceType', 'vehicleGroup']),
            'message' => 'Vehicle group pricing created successfully',
        ], 201);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => VehicleGroupPricing::with(['slabDefinition.serviceType', 'vehicleGroup'])->findOrFail($id),
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $pricing = VehicleGroupPricing::findOrFail($id);
        $pricing->update($this->validateVehicleGroupPricing($request, true));

        return response()->json([
            'success' => true,
            'data' => $pricing->fresh(['slabDefinition.serviceType', 'vehicleGroup']),
            'message' => 'Vehicle group pricing updated successfully',
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        VehicleGroupPricing::findOrFail($id)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Vehicle group pricing deleted successfully',
        ]);
    }

    public function bulkStore(Request $request): JsonResponse
    {
        return $this->bulkSavePricing($request);
    }

    public function matrix(Request $request): JsonResponse
    {
        return $this->getPricingMatrix($request);
    }

    public function bulkUpdate(Request $request): JsonResponse
    {
        return $this->bulkSavePricing($request);
    }

    /**
     * Get unified pricing data for the frontend matrix view with pagination support.
     * Optimized version with better performance, caching, and pagination for vehicle groups.
     */
    public function unifiedPricing(Request $request)
    {
        // Get filters and pagination params
        $serviceTypeId = $request->input('service_type_id');
        $vehicleGroupId = $request->input('vehicle_group_id');
        $context = (string) $request->input('context', 'public');
        $ownerType = (string) $request->input('owner_type', ($context === 'corporate' ? 'corporate' : ''));
        $ownerId = (string) $request->input('owner_id', '');
        $includeInactive = $request->boolean('include_inactive', false);
        $page = $request->input('page', 1);
        $perPage = $request->input('per_page', 50);
        $search = $request->input('search');
        $vehicleFilters = array_filter($request->only([
            'grade_id', 'class_id', 'fuel_type_id', 'transmission_id', 'category_id',
            'make_id', 'model_id', 'ownership_type', 'payment_model',
        ]), fn ($value) => filled($value));

        // Build cache key for frequent queries
        $cacheKey = 'unified_pricing_' . md5(serialize([
            'service_type_id' => $serviceTypeId,
            'vehicle_group_id' => $vehicleGroupId,
            'context' => $context,
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'include_inactive' => $includeInactive,
            'page' => $page,
            'per_page' => $perPage,
            'search' => $search,
            'vehicle_filters' => $vehicleFilters,
        ]));

        // Try to get from cache first (cache for 5 minutes for frequently accessed data)
        if (!$request->boolean('refresh', false)) {
            $cached = cache()->get($cacheKey);
            if ($cached) {
                return response()->json($cached);
            }
        }

        try {
            // Get all service types with optimized query (no pagination for service types)
            if ($context === 'corporate' && $ownerType === 'corporate' && $ownerId !== '') {
                $serviceTypes = Corporate::findOrFail($ownerId)
                    ->serviceTypes()
                    ->when($serviceTypeId, fn($q) => $q->where('service_types.id', $serviceTypeId))
                    ->when(!$includeInactive, fn($q) => $q->where('service_types.is_active', true))
                    ->where('service_types.context', 'corporate')
                    ->where('service_types.owner_type', '')
                    ->where('service_types.owner_id', '')
                    ->select(['service_types.id', 'service_types.name', 'service_types.description', 'service_types.code', 'service_types.context', 'service_types.owner_type', 'service_types.owner_id', 'service_types.is_active'])
                    ->orderBy('service_types.priority')
                    ->get();
            } else {
                $serviceTypes = ServiceType::forContext($context, '', '')
                    ->when($serviceTypeId, fn($q) => $q->where('id', $serviceTypeId))
                    ->when(!$includeInactive, fn($q) => $q->where('is_active', true))
                    ->select(['id', 'name', 'description', 'code', 'context', 'owner_type', 'owner_id', 'is_active'])
                    ->orderBy('priority')
                    ->get();
            }

            // Get paginated vehicle groups with optimized query
            $vehicleGroupQuery = VehicleGroup::query()
                ->with(['grade:id,name', 'make:id,name', 'model:id,name'])
                ->when($context === 'corporate' && $ownerType === 'corporate' && $ownerId !== '', function ($q) use ($ownerId) {
                    $q->whereIn('id', function ($subQuery) use ($ownerId) {
                        $subQuery->select('vehicle_group_id')
                            ->from('corporate_vehicle_groups')
                            ->where('corporate_id', $ownerId);
                    });
                })
                ->when($vehicleGroupId, fn($q) => $q->where('id', $vehicleGroupId))
                ->when($vehicleFilters, function ($q) use ($vehicleFilters) {
                    foreach (['grade_id', 'class_id', 'fuel_type_id', 'transmission_id', 'category_id', 'make_id', 'model_id'] as $field) {
                        if (isset($vehicleFilters[$field])) {
                            $q->where($field, $vehicleFilters[$field]);
                        }
                    }
                    foreach (['ownership_type', 'payment_model'] as $field) {
                        if (isset($vehicleFilters[$field])) {
                            $q->whereHas('vehicles', fn ($vehicles) => $vehicles->where($field, $vehicleFilters[$field]));
                        }
                    }
                })
                ->when(!$includeInactive, fn($q) => $q->where('is_active', true))
                ->when($search, function ($q) use ($search) {
                    $q->where(function ($query) use ($search) {
                        $query->where('name', 'like', "%{$search}%")
                            ->orWhere('description', 'like', "%{$search}%");
                    });
                })
                ->select(['id', 'name', 'description', 'is_active', 'grade_id', 'make_id', 'model_id'])
                ->orderBy('name');

            $vehicleGroupsPaginated = $vehicleGroupQuery->paginate($perPage, ['*'], 'page', $page);
            $vehicleGroups = $vehicleGroupsPaginated->getCollection();

            // Early return if no data
            if ($serviceTypes->isEmpty() || $vehicleGroups->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'pagination' => [
                        'current_page' => $vehicleGroupsPaginated->currentPage(),
                        'last_page' => $vehicleGroupsPaginated->lastPage(),
                        'per_page' => $vehicleGroupsPaginated->perPage(),
                        'total' => $vehicleGroupsPaginated->total(),
                        'from' => $vehicleGroupsPaginated->firstItem(),
                        'to' => $vehicleGroupsPaginated->lastItem(),
                    ],
                    'meta' => [
                        'total_vehicle_groups' => $vehicleGroupsPaginated->total(),
                        'total_service_types' => $serviceTypes->count(),
                        'filters_applied' => [
                            'service_type_id' => $serviceTypeId,
                            'vehicle_group_id' => $vehicleGroupId,
                            'context' => $context,
                            'owner_type' => $ownerType,
                            'owner_id' => $ownerId,
                            'include_inactive' => $includeInactive,
                            'search' => $search
                        ]
                    ],
                    'message' => 'No data found for the given filters'
                ]);
            }

            $serviceTypeIds = $serviceTypes->pluck('id');
            $vehicleGroupIds = $vehicleGroups->pluck('id');

            // Bulk load all slab definitions
            $allSlabDefinitions = VehiclePricingSlabDefinition::query()
                ->with(['serviceType:id,name'])
                ->whereIn('service_type_id', $serviceTypeIds)
                ->when($ownerType && $ownerId, function ($q) use ($ownerType, $ownerId) {
                    $q->forOwner($ownerType, $ownerId);
                }, fn ($q) => $q->whereNull('owner_type')->whereNull('owner_id'))
                ->when(!$includeInactive, fn($q) => $q->where('is_active', true))
                ->select(['id', 'service_type_id', 'name', 'min_hours', 'max_hours', 'type', 'is_active', 'sort_order', 'owner_type', 'owner_id', 'priority'])
                ->tap(fn ($q) => $this->applyOwnerPriorityOrder($q, $ownerType, $ownerId))
                ->orderByDesc('priority')
                ->orderBy('service_type_id')
                ->orderBy('sort_order')
                ->get()
                ->groupBy('service_type_id');

            // Bulk load all common rate definitions
            $allCommonRateDefinitions = VehiclePricingCommonRateDefinition::query()
                ->with(['serviceType:id,name'])
                ->where(function ($q) use ($serviceTypeIds) {
                    $q->whereIn('service_type_id', $serviceTypeIds)
                        ->orWhereNull('service_type_id'); // Global rates
                })
                ->when($ownerType && $ownerId, function ($q) use ($ownerType, $ownerId) {
                    $q->where(function ($scope) use ($ownerType, $ownerId) {
                        $scope->where(function ($scoped) use ($ownerType, $ownerId) {
                            $scoped->where('owner_type', $ownerType)->where('owner_id', $ownerId);
                        })->orWhereNull('owner_type');
                    });
                }, fn ($q) => $q->whereNull('owner_type')->whereNull('owner_id'))
                ->when(!$includeInactive, fn($q) => $q->where('is_active', true))
                ->select([
                    'id',
                    'service_type_id',
                    'name',
                    'description',
                    'common_rate_type',
                    'display_unit',
                    'is_mandatory',
                    'is_active',
                    'sort_order',
                    'owner_type',
                    'owner_id',
                    'priority',
                ])
                ->tap(fn ($q) => $this->applyOwnerPriorityOrder($q, $ownerType, $ownerId))
                ->orderByDesc('priority')
                ->get()
                ->groupBy(function ($item) {
                    return $item->service_type_id ?? 'global';
                });

            // Bulk load all existing slab pricing
            $allSlabPricing = VehicleGroupPricing::query()
                ->with(['slabDefinition:id,name,service_type_id'])
                ->whereIn('slab_definition_id', $allSlabDefinitions->flatten()->pluck('id'))
                ->whereIn('vehicle_group_id', $vehicleGroupIds)
                ->when($ownerType && $ownerId, function ($q) use ($ownerType, $ownerId) {
                    $q->forOwner($ownerType, $ownerId);
                }, fn ($q) => $q->whereNull('owner_type')->whereNull('owner_id'))
                ->select(['id', 'vehicle_group_id', 'slab_definition_id', 'rate', 'rate_type', 'minimum_charge', 'includes_fuel', 'includes_driver', 'is_active', 'owner_type', 'owner_id', 'priority'])
                ->tap(fn ($q) => $this->applyOwnerPriorityOrder($q, $ownerType, $ownerId))
                ->orderByDesc('priority')
                ->get()
                ->groupBy(function ($item) {
                    return $item->vehicle_group_id . '_' . $item->slab_definition_id;
                });

            // Bulk load all existing common rate pricing
            $allCommonRatePricing = VehicleGroupCommonRatePricing::query()
                ->with(['commonRateDefinition:id,name,service_type_id'])
                ->whereIn('common_rate_definition_id', $allCommonRateDefinitions->flatten()->pluck('id'))
                ->whereIn('vehicle_group_id', $vehicleGroupIds)
                ->when($ownerType && $ownerId, function ($q) use ($ownerType, $ownerId) {
                    $q->where(function ($scope) use ($ownerType, $ownerId) {
                        $scope->where(function ($scoped) use ($ownerType, $ownerId) {
                            $scoped->where('owner_type', $ownerType)->where('owner_id', $ownerId);
                        })->orWhereNull('owner_type');
                    });
                }, fn ($q) => $q->whereNull('owner_type')->whereNull('owner_id'))
                ->select(['id', 'vehicle_group_id', 'common_rate_definition_id', 'value', 'is_active', 'owner_type', 'owner_id', 'priority'])
                ->tap(fn ($q) => $this->applyOwnerPriorityOrder($q, $ownerType, $ownerId))
                ->orderByDesc('priority')
                ->get()
                ->groupBy(function ($item) {
                    return $item->vehicle_group_id . '_' . $item->common_rate_definition_id;
                });

            $serviceSettings = VehicleGroupServicePricingSetting::query()
                ->whereIn('vehicle_group_id', $vehicleGroupIds)
                ->whereIn('service_type_id', $serviceTypeIds)
                ->get()
                ->keyBy(fn($item) => $item->vehicle_group_id . '_' . $item->service_type_id);

            $result = [];

            foreach ($vehicleGroups as $group) {
                $groupData = [
                    'vehicle_group' => $group,
                    'services' => []
                ];

                foreach ($serviceTypes as $serviceType) {
                    // Get slab definitions for this service type
                    $slabDefinitions = $allSlabDefinitions->get($serviceType->id, collect());

                    // Get common rate definitions for this service type
                    $serviceCommonRates = $allCommonRateDefinitions->get($serviceType->id, collect());
                    $globalCommonRates = $allCommonRateDefinitions->has('global') ? $allCommonRateDefinitions['global'] : collect();
                    $commonRateDefinitions = $serviceCommonRates->merge($globalCommonRates);

                    $serviceData = [
                        'service_type' => $serviceType,
                        'settings' => $serviceSettings->get($group->id . '_' . $serviceType->id) ?: [
                            'vehicle_group_id' => $group->id,
                            'service_type_id' => $serviceType->id,
                            'is_inquiry_only' => false,
                            'is_hidden' => false,
                        ],
                        'slab_definitions' => $slabDefinitions->map(function ($slab) use ($group, $allSlabPricing) {
                            $key = $group->id . '_' . $slab->id;
                            $pricing = $allSlabPricing->get($key)?->first();

                            return [
                                'definition' => $slab,
                                'pricing' => $pricing,
                                'has_pricing' => $pricing !== null
                            ];
                        }),
                        'common_rate_definitions' => $commonRateDefinitions->map(function ($commonRate) use ($group, $allCommonRatePricing) {
                            $key = $group->id . '_' . $commonRate->id;
                            $pricing = $allCommonRatePricing->get($key)?->first();

                            return [
                                'definition' => $commonRate,
                                'pricing' => $pricing,
                                'has_pricing' => $pricing !== null
                            ];
                        }),
                        'completion_percentage' => $this->calculateOptimizedCompletionPercentage(
                            $slabDefinitions,
                            $group->id,
                            $allSlabPricing,
                            $commonRateDefinitions,
                            $allCommonRatePricing
                        )
                    ];

                    $groupData['services'][] = $serviceData;
                }

                $result[] = $groupData;
            }

            $response = [
                'success' => true,
                'data' => $result,
                'pagination' => [
                    'current_page' => $vehicleGroupsPaginated->currentPage(),
                    'last_page' => $vehicleGroupsPaginated->lastPage(),
                    'per_page' => $vehicleGroupsPaginated->perPage(),
                    'total' => $vehicleGroupsPaginated->total(),
                    'from' => $vehicleGroupsPaginated->firstItem(),
                    'to' => $vehicleGroupsPaginated->lastItem(),
                ],
                'meta' => [
                    'total_vehicle_groups' => $vehicleGroupsPaginated->total(),
                    'total_service_types' => $serviceTypes->count(),
                    'total_slab_definitions' => $allSlabDefinitions->flatten()->count(),
                    'total_common_rate_definitions' => $allCommonRateDefinitions->flatten()->count(),
                    'total_configured_slab_pricing' => $allSlabPricing->count(),
                    'total_configured_common_rate_pricing' => $allCommonRatePricing->count(),
                    'filters_applied' => [
                        'service_type_id' => $serviceTypeId,
                        'vehicle_group_id' => $vehicleGroupId,
                        'include_inactive' => $includeInactive,
                        'search' => $search
                    ],
                    'cache_info' => [
                        'cached_at' => now()->toISOString(),
                        'cache_key' => $cacheKey
                    ]
                ],
                'message' => 'Unified pricing data retrieved successfully'
            ];

            // Cache the result for 5 minutes
            cache()->put($cacheKey, $response, 300);

            return response()->json($response);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve unified pricing data', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'filters' => [
                    'service_type_id' => $serviceTypeId,
                    'vehicle_group_id' => $vehicleGroupId,
                    'include_inactive' => $includeInactive,
                    'page' => $page,
                    'per_page' => $perPage,
                    'search' => $search
                ]
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve unified pricing data',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Save pricing for a specific vehicle group (unified method for both slab and common rate pricing).
     * Enhanced version with better validation and history tracking.
     */
    public function saveVehicleGroupPricing(Request $request, string $vehicleGroupId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            // Allow service_pricing to be nullable so single-rate saves or common-rate-only saves succeed.
            // We enforce presence of at least one of 'service_pricing' or 'common_rates' below.
            'service_pricing' => 'nullable|array',
            'service_pricing.*.service_type_id' => 'required_with:service_pricing|uuid|exists:service_types,id',
            'service_pricing.*.slabs' => 'required_with:service_pricing|array',
            'service_pricing.*.slabs.*.slab_definition_id' => 'required_with:service_pricing|uuid|exists:vehicle_pricing_slab_definitions,id',
            'service_pricing.*.slabs.*.rate' => 'required_with:service_pricing|numeric|min:0',
            'service_pricing.*.slabs.*.rate_type' => 'nullable|in:per_hour,per_day,flat_rate,per_km',
            'service_pricing.*.slabs.*.minimum_charge' => 'nullable|numeric|min:0',
            'service_pricing.*.slabs.*.includes_fuel' => 'boolean',
            'service_pricing.*.slabs.*.includes_driver' => 'boolean',
            'service_pricing.*.slabs.*.is_active' => 'boolean',
            'common_rates' => 'nullable|array',
            'common_rates.*.common_rate_definition_id' => 'required_with:common_rates|uuid|exists:vehicle_pricing_common_rate_definitions,id',
            'common_rates.*.value' => 'nullable|numeric|min:0',
            'common_rates.*.is_active' => 'boolean',
            'common_rates.*.service_type_id' => 'nullable|uuid|exists:service_types,id',
            'service_settings' => 'nullable|array',
            'service_settings.*.service_type_id' => 'required_with:service_settings|uuid|exists:service_types,id',
            'service_settings.*.is_inquiry_only' => 'boolean',
            'service_settings.*.is_hidden' => 'boolean',
            'context' => 'nullable|string|in:public,portal,corporate',
            'owner_type' => 'nullable|required_if:context,corporate|string|in:corporate',
            'owner_id' => 'nullable|required_if:context,corporate|uuid|exists:corporates,id|required_with:owner_type',
            'priority' => 'nullable|integer|min:0',
            'change_reason' => 'nullable|string|max:500',
        ]);

        // Require at least one of the two arrays to be present and non-empty
        $hasServicePricing = (is_array($request->service_pricing) && count($request->service_pricing) > 0);
        $hasCommonRates = (is_array($request->common_rates) && count($request->common_rates) > 0);
        $hasServiceSettings = (is_array($request->service_settings) && count($request->service_settings) > 0);

        // If service_pricing exists, ensure at least one entry has slabs with at least one slab
        $servicePricingHasSlabs = false;
        if ($hasServicePricing) {
            foreach ($request->service_pricing as $sp) {
                if (isset($sp['slabs']) && is_array($sp['slabs']) && count($sp['slabs']) > 0) {
                    $servicePricingHasSlabs = true;
                    break;
                }
            }
        }

        if ((!$hasServicePricing || !$servicePricingHasSlabs) && !$hasCommonRates && !$hasServiceSettings) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => ['service_pricing' => ['Either service_pricing with at least one slab, common_rates, or service_settings must be provided.']]
            ], 422);
        }

        if ($validator->fails()) {

            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Verify vehicle group exists
        $vehicleGroup = VehicleGroup::findOrFail($vehicleGroupId);

        DB::beginTransaction();
        try {
            $results = [
                'slab_pricing' => ['created' => [], 'updated' => []],
                'common_rates' => ['created' => [], 'updated' => []],
                'history_records' => []
            ];

            $changeReason = $request->input('change_reason', "Pricing update for vehicle group: {$vehicleGroup->name}");
            $userId = auth()->id();
            $ownerType = $request->input('owner_type');
            $ownerId = $ownerType ? $request->input('owner_id') : null;
            $priority = (int) $request->input('priority', 0);

            // Process slab pricing
            foreach ($request->input('service_pricing', []) as $serviceData) {
                foreach ($serviceData['slabs'] as $slabData) {
                    $existingPricing = VehicleGroupPricing::forVehicleGroup($vehicleGroupId)
                        ->forSlabDefinition($slabData['slab_definition_id'])
                        ->where('owner_type', $ownerType)
                        ->where('owner_id', $ownerId)
                        ->first();

                    // An amount-only save must retain the row's calculation
                    // semantics. Defaults apply only when this is a new row.
                    $data = array_merge([
                        'is_active' => $existingPricing?->is_active ?? true,
                        'includes_fuel' => $existingPricing?->includes_fuel ?? false,
                        'includes_driver' => $existingPricing?->includes_driver ?? false,
                        'rate_type' => $existingPricing?->rate_type
                            ?? $this->defaultRateTypeForSlab($slabData['slab_definition_id']),
                        'minimum_charge' => $existingPricing?->minimum_charge,
                    ], $slabData, [
                        'vehicle_group_id' => $vehicleGroupId,
                        'owner_type' => $ownerType,
                        'owner_id' => $ownerId,
                        'priority' => $request->has('priority')
                            ? $priority
                            : ($existingPricing?->priority ?? 0),
                    ]);

                    if ($existingPricing) {
                        $oldRate = $existingPricing->rate;
                        $newRate = $data['rate'];

                        $oldData = $existingPricing->toArray();
                        $existingPricing->fill($data);

                        if ($existingPricing->isDirty()) {
                            $existingPricing->save();
                            $newData = $existingPricing->fresh()->toArray();

                            // Create history record
                            $historyRecord = VehiclePricingHistory::createHistoryRecord([
                                'vehicle_group_id' => $vehicleGroupId,
                                'service_type_id' => $serviceData['service_type_id'],
                                'record_type' => 'slab_pricing',
                                'pricing_slab_definition_id' => $slabData['slab_definition_id'],
                                'old_rate' => $oldRate,
                                'new_rate' => $newRate,
                                'change_reason' => $changeReason,
                                'old_pricing_data' => $oldData,
                                'new_pricing_data' => $newData,
                                'changed_by' => $userId,
                                'changed_at' => now()
                            ]);

                            $results['history_records'][] = $historyRecord;
                        }

                        $existingPricing->load(['slabDefinition.serviceType', 'vehicleGroup']);
                        $results['slab_pricing']['updated'][] = $existingPricing;
                    } else {
                        $newPricing = VehicleGroupPricing::create($data);

                        // Create history record for new pricing
                        $historyRecord = VehiclePricingHistory::createHistoryRecord([
                            'vehicle_group_id' => $vehicleGroupId,
                            'service_type_id' => $serviceData['service_type_id'],
                            'record_type' => 'slab_pricing',
                            'pricing_slab_definition_id' => $slabData['slab_definition_id'],
                            'old_rate' => 0,
                            'new_rate' => $data['rate'],
                            'change_reason' => $changeReason,
                            'old_pricing_data' => null,
                            'new_pricing_data' => $newPricing->toArray(),
                            'changed_by' => $userId,
                            'changed_at' => now()
                        ]);

                        $results['history_records'][] = $historyRecord;

                        $newPricing->load(['slabDefinition.serviceType', 'vehicleGroup']);
                        $results['slab_pricing']['created'][] = $newPricing;
                    }
                }
            }

            // Process common rates if provided
            if ($request->has('common_rates') && is_array($request->common_rates)) {
                foreach ($request->common_rates as $commonRateData) {
                    $commonRateServiceTypeId = $commonRateData['service_type_id'] ?? null;
                    $data = array_merge($commonRateData, [
                        'vehicle_group_id' => $vehicleGroupId,
                        'is_active' => $commonRateData['is_active'] ?? true,
                        'owner_type' => $ownerType,
                        'owner_id' => $ownerId,
                        'priority' => $priority,
                    ]);
                    unset($data['service_type_id']);

                    $existingCommonRate = VehicleGroupCommonRatePricing::forVehicleGroup($vehicleGroupId)
                        ->forCommonRate($commonRateData['common_rate_definition_id'])
                        ->where('owner_type', $ownerType)
                        ->where('owner_id', $ownerId)
                        ->first();

                    if ($existingCommonRate) {
                        $oldValue = $existingCommonRate->value;
                        $newValue = $data['value'];

                        if ($oldValue != $newValue) {
                            $oldData = $existingCommonRate->toArray();
                            $existingCommonRate->update($data);
                            $newData = $existingCommonRate->fresh()->toArray();

                            // Create history record for common rate change
                            $historyRecord = VehiclePricingHistory::createHistoryRecord([
                                'vehicle_group_id' => $vehicleGroupId,
                                'service_type_id' => $commonRateServiceTypeId,
                                'record_type' => 'common_rate_pricing',
                                'common_rate_definition_id' => $commonRateData['common_rate_definition_id'],
                                'old_rate' => $oldValue ?? 0,
                                'new_rate' => $newValue ?? 0,
                                'change_reason' => $changeReason,
                                'old_pricing_data' => $oldData,
                                'new_pricing_data' => $newData,
                                'changed_by' => $userId,
                                'changed_at' => now()
                            ]);

                            $results['history_records'][] = $historyRecord;
                        }

                        $existingCommonRate->load(['commonRateDefinition', 'vehicleGroup']);
                        $results['common_rates']['updated'][] = $existingCommonRate;
                    } else {
                        $newCommonRate = VehicleGroupCommonRatePricing::create($data);

                        // Create history record for new common rate
                        $historyRecord = VehiclePricingHistory::createHistoryRecord([
                            'vehicle_group_id' => $vehicleGroupId,
                            'service_type_id' => $commonRateServiceTypeId,
                            'record_type' => 'common_rate_pricing',
                            'common_rate_definition_id' => $commonRateData['common_rate_definition_id'],
                            'old_rate' => 0,
                            'new_rate' => $data['value'] ?? 0,
                            'change_reason' => $changeReason,
                            'old_pricing_data' => null,
                            'new_pricing_data' => $newCommonRate->toArray(),
                            'changed_by' => $userId,
                            'changed_at' => now()
                        ]);

                        $results['history_records'][] = $historyRecord;

                        $newCommonRate->load(['commonRateDefinition', 'vehicleGroup']);
                        $results['common_rates']['created'][] = $newCommonRate;
                    }
                }
            }

            // Process service-level settings for this vehicle group
            foreach ($request->input('service_settings', []) as $settingData) {
                VehicleGroupServicePricingSetting::updateOrCreate(
                    [
                        'vehicle_group_id' => $vehicleGroupId,
                        'service_type_id' => $settingData['service_type_id'],
                    ],
                    [
                        'is_inquiry_only' => $settingData['is_inquiry_only'] ?? false,
                        'is_hidden' => $settingData['is_hidden'] ?? false,
                        'updated_user_id' => $userId,
                    ]
                );
            }

            DB::commit();

            // Clear unified pricing caches after saving vehicle group pricing
            $this->clearUnifiedPricingCache();

            return response()->json([
                'success' => true,
                'data' => $results,
                'summary' => [
                    'slab_pricing_created' => count($results['slab_pricing']['created']),
                    'slab_pricing_updated' => count($results['slab_pricing']['updated']),
                    'common_rates_created' => count($results['common_rates']['created']),
                    'common_rates_updated' => count($results['common_rates']['updated']),
                    'history_records_created' => count($results['history_records'])
                ],
                'message' => "Pricing for vehicle group '{$vehicleGroup->name}' saved successfully"
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Vehicle group pricing save failed', [
                'vehicle_group_id' => $vehicleGroupId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to save vehicle group pricing',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }



    /**
     * Optimized completion percentage calculation
     */
    private function calculateOptimizedCompletionPercentage($slabDefinitions, $vehicleGroupId, $allSlabPricing, $commonRateDefinitions, $allCommonRatePricing): float
    {
        $totalItems = $slabDefinitions->count() + $commonRateDefinitions->count();
        if ($totalItems === 0) {
            return 100.0;
        }

        $completedSlabs = 0;
        foreach ($slabDefinitions as $slab) {
            $key = $vehicleGroupId . '_' . $slab->id;
            if ($allSlabPricing->has($key)) {
                $completedSlabs++;
            }
        }

        $completedCommonRates = 0;
        foreach ($commonRateDefinitions as $commonRate) {
            $key = $vehicleGroupId . '_' . $commonRate->id;
            if ($allCommonRatePricing->has($key)) {
                $completedCommonRates++;
            }
        }

        $completedItems = $completedSlabs + $completedCommonRates;
        return round(($completedItems / $totalItems) * 100, 2);
    }

    /**
     * Clear all unified pricing caches.
     * This should be called whenever pricing data is updated to ensure fresh data is served.
     */
    private function clearUnifiedPricingCache(): void
    {
        try {
            // Try to get Redis instance if available
            if (config('cache.default') === 'redis' && extension_loaded('redis')) {
                $redis = \Illuminate\Support\Facades\Redis::connection();
                $keys = $redis->keys('*unified_pricing_*');

                if (!empty($keys)) {
                    $redis->del($keys);
                }
            } else {
                cache()->flush();
            }
        } catch (\Exception $e) {
            Log::error('Failed to clear unified pricing caches', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Last resort - try to clear individual cache entries
            try {
                cache()->forget('unified_pricing');
                cache()->forget('vehicle_pricing_matrix');
                cache()->forget('pricing_completion');
            } catch (\Exception $lastResortError) {
                Log::error('Last resort cache clearing also failed', [
                    'error' => $lastResortError->getMessage()
                ]);
            }
        }
    }

    private function validateVehicleGroupPricing(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $data = $request->validate([
            'slab_definition_id' => [$required, 'uuid', 'exists:vehicle_pricing_slab_definitions,id'],
            'vehicle_group_id' => [$required, 'uuid', 'exists:vehicle_groups,id'],
            'rate' => [$required, 'numeric', 'min:0'],
            'rate_type' => ['nullable', 'in:per_hour,per_day,flat_rate,per_km'],
            'minimum_charge' => ['nullable', 'numeric', 'min:0'],
            'includes_fuel' => ['nullable', 'boolean'],
            'includes_driver' => ['nullable', 'boolean'],
            'owner_type' => ['nullable', 'string', 'in:corporate'],
            'owner_id' => ['nullable', 'uuid', 'exists:corporates,id', 'required_with:owner_type'],
            'priority' => ['nullable', 'integer', 'min:0'],
        ]);

        if (array_key_exists('owner_type', $data) && !$data['owner_type']) {
            $data['owner_id'] = null;
        }

        if (!$partial) {
            $data['rate_type'] = $data['rate_type']
                ?? $this->defaultRateTypeForSlab($data['slab_definition_id']);
            $data['includes_fuel'] = $data['includes_fuel'] ?? false;
            $data['includes_driver'] = $data['includes_driver'] ?? false;
        }

        return $data;
    }

    private function defaultRateTypeForSlab(string $slabDefinitionId): string
    {
        $slabType = VehiclePricingSlabDefinition::whereKey($slabDefinitionId)->value('type');

        return match ($slabType) {
            'hours' => 'per_hour',
            'per_km' => 'per_km',
            'minutes', 'flat_rate' => 'flat_rate',
            default => 'per_day',
        };
    }
    /**
     * Copy pricing from one vehicle group to another.
     */
    public function copyPricing(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'source_vehicle_group_id' => 'required|uuid|exists:vehicle_groups,id',
            'target_vehicle_group_ids' => 'required|array',
            'target_vehicle_group_ids.*' => 'uuid|exists:vehicle_groups,id',
            'copy_slab_pricing' => 'boolean',
            'copy_common_rates' => 'boolean',
            'overwrite_existing' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $sourceVehicleGroupId = $request->source_vehicle_group_id;
        $targetVehicleGroupIds = $request->target_vehicle_group_ids;
        $copySlabPricing = $request->boolean('copy_slab_pricing', true);
        $copyCommonRates = $request->boolean('copy_common_rates', true);
        $overwriteExisting = $request->boolean('overwrite_existing', false);

        DB::beginTransaction();
        try {
            $results = [
                'slab_pricing' => ['created' => 0, 'updated' => 0, 'skipped' => 0],
                'common_rates' => ['created' => 0, 'updated' => 0, 'skipped' => 0]
            ];

            if ($copySlabPricing) {
                // Copy slab pricing
                $sourceSlabPricing = VehicleGroupPricing::forVehicleGroup($sourceVehicleGroupId)->get();

                foreach ($targetVehicleGroupIds as $targetVehicleGroupId) {
                    foreach ($sourceSlabPricing as $sourcePricing) {
                        $existingPricing = VehicleGroupPricing::forVehicleGroup($targetVehicleGroupId)
                            ->forSlabDefinition($sourcePricing->slab_definition_id)
                            ->first();

                        if ($existingPricing) {
                            if ($overwriteExisting) {
                                $existingPricing->update([
                                    'rate' => $sourcePricing->rate,
                                    'rate_type' => $sourcePricing->rate_type,
                                    'minimum_charge' => $sourcePricing->minimum_charge,
                                    'includes_fuel' => $sourcePricing->includes_fuel,
                                    'includes_driver' => $sourcePricing->includes_driver,
                                    'is_active' => $sourcePricing->is_active,
                                ]);
                                $results['slab_pricing']['updated']++;
                            } else {
                                $results['slab_pricing']['skipped']++;
                            }
                        } else {
                            VehicleGroupPricing::create([
                                'vehicle_group_id' => $targetVehicleGroupId,
                                'slab_definition_id' => $sourcePricing->slab_definition_id,
                                'rate' => $sourcePricing->rate,
                                'rate_type' => $sourcePricing->rate_type,
                                'minimum_charge' => $sourcePricing->minimum_charge,
                                'includes_fuel' => $sourcePricing->includes_fuel,
                                'includes_driver' => $sourcePricing->includes_driver,
                                'is_active' => $sourcePricing->is_active,
                            ]);
                            $results['slab_pricing']['created']++;
                        }
                    }
                }
            }

            if ($copyCommonRates) {
                // Copy common rate pricing
                $sourceCommonRatePricing = VehicleGroupCommonRatePricing::forVehicleGroup($sourceVehicleGroupId)->get();

                foreach ($targetVehicleGroupIds as $targetVehicleGroupId) {
                    foreach ($sourceCommonRatePricing as $sourceCommonRate) {
                        $existingCommonRate = VehicleGroupCommonRatePricing::forVehicleGroup($targetVehicleGroupId)
                            ->forCommonRate($sourceCommonRate->common_rate_definition_id)
                            ->first();

                        if ($existingCommonRate) {
                            if ($overwriteExisting) {
                                $existingCommonRate->update([
                                    'value' => $sourceCommonRate->value,
                                    'is_active' => $sourceCommonRate->is_active,
                                ]);
                                $results['common_rates']['updated']++;
                            } else {
                                $results['common_rates']['skipped']++;
                            }
                        } else {
                            VehicleGroupCommonRatePricing::create([
                                'vehicle_group_id' => $targetVehicleGroupId,
                                'common_rate_definition_id' => $sourceCommonRate->common_rate_definition_id,
                                'value' => $sourceCommonRate->value,
                                'is_active' => $sourceCommonRate->is_active,
                            ]);
                            $results['common_rates']['created']++;
                        }
                    }
                }
            }

            DB::commit();

            // Clear unified pricing caches after copying pricing
            $this->clearUnifiedPricingCache();

            return response()->json([
                'success' => true,
                'data' => $results,
                'message' => 'Pricing copied successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to copy pricing',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function bulkSavePricing(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'slab_pricing' => 'nullable|array',
            'slab_pricing.*.vehicle_group_id' => 'required|uuid|exists:vehicle_groups,id',
            'slab_pricing.*.slab_definition_id' => 'required|uuid|exists:vehicle_pricing_slab_definitions,id',
            'slab_pricing.*.rate' => 'required|numeric|min:0',
            'slab_pricing.*.rate_type' => 'nullable|in:per_hour,per_day,flat_rate,per_km',
            'slab_pricing.*.minimum_charge' => 'nullable|numeric|min:0',
            'slab_pricing.*.includes_fuel' => 'boolean',
            'slab_pricing.*.includes_driver' => 'boolean',
            'slab_pricing.*.is_active' => 'boolean',
            'slab_pricing.*.effective_from' => 'nullable|date',
            'common_rate_pricing' => 'nullable|array',
            'common_rate_pricing.*.vehicle_group_id' => 'required|uuid|exists:vehicle_groups,id',
            'common_rate_pricing.*.common_rate_definition_id' => 'required|uuid|exists:vehicle_pricing_common_rate_definitions,id',
            'common_rate_pricing.*.value' => 'nullable|numeric|min:0',
            'common_rate_pricing.*.is_active' => 'boolean',
            'common_rate_pricing.*.service_type_id' => 'nullable|uuid|exists:service_types,id',
            'context' => 'nullable|string|in:public,portal,corporate',
            'owner_type' => 'nullable|required_if:context,corporate|string|in:corporate',
            'owner_id' => 'nullable|required_if:context,corporate|uuid|exists:corporates,id|required_with:owner_type',
            'priority' => 'nullable|integer|min:0',
            'change_reason' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $operation = VehiclePricingBulkOperation::create([
            'operation_type' => 'bulk_update',
            'operation_name' => $request->input('operation_name', 'Pricing matrix bulk update'),
            'description' => $request->input('change_reason'),
            'operation_data' => $request->all(),
            'affected_records' => [],
            'status' => 'pending',
            'total_records' => count($request->input('slab_pricing', [])) + count($request->input('common_rate_pricing', [])),
            'processed_records' => 0,
            'successful_records' => 0,
            'failed_records' => 0,
            'initiated_by' => auth()->id(),
        ]);
        $operation->markAsStarted();

        DB::beginTransaction();
        try {
            $results = [
                'slab_pricing' => ['created' => 0, 'updated' => 0],
                'common_rate_pricing' => ['created' => 0, 'updated' => 0],
                'history_records' => []
            ];

            $changeReason = $request->input('change_reason', 'Bulk pricing update via unified interface');
            $userId = auth()->id();
            $ownerType = $request->input('owner_type');
            $ownerId = $ownerType ? $request->input('owner_id') : null;
            $priority = (int) $request->input('priority', 0);

            // Process slab pricing with history tracking
            if ($request->has('slab_pricing') && is_array($request->slab_pricing)) {
                foreach ($request->slab_pricing as $slabData) {
                    $existingPricing = VehicleGroupPricing::forVehicleGroup($slabData['vehicle_group_id'])
                        ->forSlabDefinition($slabData['slab_definition_id'])
                        ->where('owner_type', $ownerType)
                        ->where('owner_id', $ownerId)
                        ->first();

                    $slabData = array_merge([
                        'is_active' => $existingPricing?->is_active ?? true,
                        'includes_fuel' => $existingPricing?->includes_fuel ?? false,
                        'includes_driver' => $existingPricing?->includes_driver ?? false,
                        'rate_type' => $existingPricing?->rate_type
                            ?? $this->defaultRateTypeForSlab($slabData['slab_definition_id']),
                        'minimum_charge' => $existingPricing?->minimum_charge,
                    ], $slabData, [
                        'owner_type' => $ownerType,
                        'owner_id' => $ownerId,
                        'priority' => $request->has('priority')
                            ? $priority
                            : ($existingPricing?->priority ?? 0),
                    ]);

                    if ($existingPricing) {
                        $oldRate = $existingPricing->rate;
                        $newRate = $slabData['rate'];

                        $oldData = $existingPricing->toArray();
                        $existingPricing->fill($slabData);

                        if ($existingPricing->isDirty()) {
                            $existingPricing->save();
                            $newData = $existingPricing->fresh()->toArray();

                            // Create history record
                            $historyRecord = VehiclePricingHistory::createHistoryRecord([
                                'vehicle_group_id' => $slabData['vehicle_group_id'],
                                'service_type_id' => $existingPricing->slabDefinition->service_type_id,
                                'pricing_slab_definition_id' => $slabData['slab_definition_id'],
                                'old_rate' => $oldRate,
                                'new_rate' => $newRate,
                                'change_reason' => $changeReason,
                                'old_pricing_data' => $oldData,
                                'new_pricing_data' => $newData,
                                'changed_by' => $userId,
                                'changed_at' => now()
                            ]);

                            $results['history_records'][] = $historyRecord;
                        }

                        $results['slab_pricing']['updated']++;
                    } else {
                        $newPricing = VehicleGroupPricing::create($slabData);

                        // Create history record for new pricing
                        $slabDefinition = VehiclePricingSlabDefinition::find($slabData['slab_definition_id']);
                        if ($slabDefinition) {
                            $historyRecord = VehiclePricingHistory::createHistoryRecord([
                                'vehicle_group_id' => $slabData['vehicle_group_id'],
                                'service_type_id' => $slabDefinition->service_type_id,
                                'pricing_slab_definition_id' => $slabData['slab_definition_id'],
                                'old_rate' => 0,
                                'new_rate' => $slabData['rate'],
                                'change_reason' => $changeReason,
                                'old_pricing_data' => null,
                                'new_pricing_data' => $newPricing->toArray(),
                                'changed_by' => $userId,
                                'changed_at' => now()
                            ]);

                            $results['history_records'][] = $historyRecord;
                        }

                        $results['slab_pricing']['created']++;
                    }
                }
            }

            // Process common rate pricing with history tracking
            if ($request->has('common_rate_pricing') && is_array($request->common_rate_pricing)) {
                foreach ($request->common_rate_pricing as $commonRateData) {
                    // Set defaults
                    $commonRateData['is_active'] = $commonRateData['is_active'] ?? true;
                    $commonRateData['owner_type'] = $ownerType;
                    $commonRateData['owner_id'] = $ownerId;
                    $commonRateData['priority'] = $priority;
                    unset($commonRateData['service_type_id']);

                    $existingCommonRate = VehicleGroupCommonRatePricing::forVehicleGroup($commonRateData['vehicle_group_id'])
                        ->forCommonRate($commonRateData['common_rate_definition_id'])
                        ->where('owner_type', $ownerType)
                        ->where('owner_id', $ownerId)
                        ->first();

                    if ($existingCommonRate) {
                        $oldValue = $existingCommonRate->value ?? 0;
                        $newValue = $commonRateData['value'] ?? 0;

                        // Only update if value has changed
                        if ($oldValue != $newValue) {
                            $oldData = $existingCommonRate->toArray();
                            $existingCommonRate->update($commonRateData);
                            $newData = $existingCommonRate->fresh()->toArray();

                            // Get common rate definition for service type
                            $commonRateDefinition = \App\Models\Vehicle\VehiclePricing\VehiclePricingCommonRateDefinition::find($commonRateData['common_rate_definition_id']);
                            $serviceTypeId = $commonRateDefinition ? $commonRateDefinition->service_type_id : null;

                            // Create history record for common rate change
                            $historyRecord = \App\Models\Vehicle\VehiclePricingHistory::createHistoryRecord([
                                'vehicle_group_id' => $commonRateData['vehicle_group_id'],
                                'service_type_id' => $serviceTypeId,
                                'record_type' => 'common_rate_pricing',
                                'common_rate_definition_id' => $commonRateData['common_rate_definition_id'],
                                'old_rate' => $oldValue,
                                'new_rate' => $newValue,
                                'change_reason' => $changeReason,
                                'old_pricing_data' => $oldData,
                                'new_pricing_data' => $newData,
                                'changed_by' => $userId,
                                'changed_at' => now()
                            ]);

                            $results['history_records'][] = $historyRecord;
                        }

                        $results['common_rate_pricing']['updated']++;
                    } else {
                        $newCommonRate = VehicleGroupCommonRatePricing::create($commonRateData);

                        // Get common rate definition for service type
                        $commonRateDefinition = VehiclePricingCommonRateDefinition::find($commonRateData['common_rate_definition_id']);
                        $serviceTypeId = $commonRateDefinition ? $commonRateDefinition->service_type_id : null;

                        // Create history record for new common rate
                        $historyRecord = VehiclePricingHistory::createHistoryRecord([
                            'vehicle_group_id' => $commonRateData['vehicle_group_id'],
                            'service_type_id' => $serviceTypeId,
                            'record_type' => 'common_rate_pricing',
                            'common_rate_definition_id' => $commonRateData['common_rate_definition_id'],
                            'old_rate' => 0,
                            'new_rate' => $commonRateData['value'] ?? 0,
                            'change_reason' => $changeReason,
                            'old_pricing_data' => null,
                            'new_pricing_data' => $newCommonRate->toArray(),
                            'changed_by' => $userId,
                            'changed_at' => now()
                        ]);

                        $results['history_records'][] = $historyRecord;
                        $results['common_rate_pricing']['created']++;
                    }
                }
            }

            DB::commit();

            $processed = $results['slab_pricing']['created'] + $results['slab_pricing']['updated']
                + $results['common_rate_pricing']['created'] + $results['common_rate_pricing']['updated'];
            $operation->update([
                'status' => 'completed',
                'processed_records' => $processed,
                'successful_records' => $processed,
                'affected_records' => collect($results['history_records'])->pluck('id')->filter()->values()->all(),
                'completed_at' => now(),
            ]);

            // Clear unified pricing caches after bulk save
            $this->clearUnifiedPricingCache();

            return response()->json([
                'success' => true,
                'data' => [
                    ...$results,
                    'summary' => [
                        'total_slab_pricing_processed' => $results['slab_pricing']['created'] + $results['slab_pricing']['updated'],
                        'total_common_rate_pricing_processed' => $results['common_rate_pricing']['created'] + $results['common_rate_pricing']['updated'],
                        'history_records_created' => count($results['history_records'])
                    ],
                    'operation_id' => $operation->id,
                ],

                'message' => 'Bulk pricing changes saved successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            $operation->markAsFailed([$e->getMessage()]);

            Log::error('Bulk pricing save failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to save bulk pricing changes',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get pricing matrix data in a format optimized for the Angular frontend.
     * Expected response format for the unified-pricing component:
     */
    public function getPricingMatrix(Request $request): JsonResponse
    {
        $serviceTypeId = $request->input('service_type_id');
        $vehicleGroupId = $request->input('vehicle_group_id');

        // Get service types
        $serviceTypes = ServiceType::query()
            ->when($serviceTypeId, fn($q) => $q->where('id', $serviceTypeId))
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        // Get vehicle groups
        $vehicleGroups = VehicleGroup::with(['grade', 'make', 'model'])
            ->when($vehicleGroupId, fn($q) => $q->where('id', $vehicleGroupId))
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        // Get all slab definitions
        $slabDefinitions = VehiclePricingSlabDefinition::with('serviceType')
            ->when($serviceTypeId, fn($q) => $q->where('service_type_id', $serviceTypeId))
            ->where('is_active', true)
            ->orderBy('service_type_id')
            ->orderBy('sort_order')
            ->get();

        // Get all common rate definitions
        $commonRateDefinitions = VehiclePricingCommonRateDefinition::with('serviceType')
            ->when($serviceTypeId, fn($q) => $q->where(function ($query) use ($serviceTypeId) {
                $query->where('service_type_id', $serviceTypeId)
                    ->orWhereNull('service_type_id');
            }))
            ->where('is_active', true)
            // ->orderBy('sort_order')
            ->get();

        // Get existing pricing data
        $existingSlabPricing = VehicleGroupPricing::with(['slabDefinition', 'vehicleGroup'])
            ->whereIn('slab_definition_id', $slabDefinitions->pluck('id'))
            ->whereIn('vehicle_group_id', $vehicleGroups->pluck('id'))
            ->get();

        $existingCommonRatePricing = VehicleGroupCommonRatePricing::with(['commonRateDefinition', 'vehicleGroup'])
            ->whereIn('common_rate_definition_id', $commonRateDefinitions->pluck('id'))
            ->whereIn('vehicle_group_id', $vehicleGroups->pluck('id'))
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'service_types' => $serviceTypes,
                'vehicle_groups' => $vehicleGroups,
                'slab_definitions' => $slabDefinitions->groupBy('service_type_id'),
                'common_rate_definitions' => $commonRateDefinitions->groupBy(function ($item) {
                    return $item->service_type_id ?? 'global';
                }),
                'existing_slab_pricing' => $existingSlabPricing->groupBy(function ($item) {
                    return $item->vehicle_group_id . '_' . $item->slab_definition_id;
                }),
                'existing_common_rate_pricing' => $existingCommonRatePricing->groupBy(function ($item) {
                    return $item->vehicle_group_id . '_' . $item->common_rate_definition_id;
                })
            ],
            'message' => 'Pricing matrix data retrieved successfully'
        ]);
    }

    /**
     * Get pricing history for a specific vehicle group
     */
    public function getVehicleGroupPricingHistory(Request $request, string $vehicleGroupId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'service_type_id' => 'nullable|uuid|exists:service_types,id',
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date|after_or_equal:from_date',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Verify vehicle group exists
        $vehicleGroup = VehicleGroup::findOrFail($vehicleGroupId);

        try {
            $query = \App\Models\Vehicle\VehiclePricingHistory::with([
                'vehicleGroup:id,name',
                'serviceType:id,name',
                'slabDefinition:id,name',
                'commonRateDefinition:id,name',
                'changedBy:id,first_name,last_name,email'
            ])->where('vehicle_group_id', $vehicleGroupId);

            // Apply filters
            if ($request->has('service_type_id')) {
                $query->where('service_type_id', $request->service_type_id);
            }

            if ($request->has('from_date')) {
                $query->where('changed_at', '>=', $request->from_date);
            }

            if ($request->has('to_date')) {
                $query->where('changed_at', '<=', $request->to_date . ' 23:59:59');
            }

            // Order by most recent first
            $query->orderBy('changed_at', 'desc');

            // Paginate results
            $perPage = $request->input('per_page', 20);
            $history = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $history->items(),
                'pagination' => [
                    'current_page' => $history->currentPage(),
                    'last_page' => $history->lastPage(),
                    'per_page' => $history->perPage(),
                    'total' => $history->total(),
                    'from' => $history->firstItem(),
                    'to' => $history->lastItem(),
                ],
                'meta' => [
                    'vehicle_group' => $vehicleGroup,
                    'filters_applied' => [
                        'service_type_id' => $request->service_type_id,
                        'from_date' => $request->from_date,
                        'to_date' => $request->to_date,
                    ]
                ],
                'message' => "Pricing history for '{$vehicleGroup->name}' retrieved successfully"
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve pricing history', [
                'vehicle_group_id' => $vehicleGroupId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve pricing history',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    public function getPriceAnalytics(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'period' => 'nullable|string|in:week,month,quarter,year',
            'vehicle_group_id' => 'nullable|uuid|exists:vehicle_groups,id',
            'service_type_id' => 'nullable|uuid|exists:service_types,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $period = $request->input('period', 'month');
        $fromDate = match ($period) {
            'week' => now()->subWeek(),
            'quarter' => now()->subQuarter(),
            'year' => now()->subYear(),
            default => now()->subMonth(),
        };

        $query = VehiclePricingHistory::with([
            'vehicleGroup:id,name',
            'serviceType:id,name',
            'changedBy:id,first_name,last_name,email',
        ])
            ->where('changed_at', '>=', $fromDate)
            ->when($request->filled('vehicle_group_id'), fn ($builder) => $builder->where('vehicle_group_id', $request->input('vehicle_group_id')))
            ->when($request->filled('service_type_id'), fn ($builder) => $builder->where('service_type_id', $request->input('service_type_id')))
            ->orderBy('changed_at');

        $changes = $query->get();
        $rateChanges = $changes->map(fn (VehiclePricingHistory $history) => (float) ($history->rate_change ?? 0));
        $percentageChanges = $changes->map(fn (VehiclePricingHistory $history) => (float) ($history->percentage_change ?? 0));

        $summary = [
            'total_changes' => $changes->count(),
            'price_increases' => $changes->where('change_type', 'increase')->count(),
            'price_decreases' => $changes->where('change_type', 'decrease')->count(),
            'average_change' => round($rateChanges->avg() ?? 0, 2),
            'average_change_percentage' => round($percentageChanges->avg() ?? 0, 2),
            'largest_increase' => round($rateChanges->max() ?? 0, 2),
            'largest_decrease' => round($rateChanges->min() ?? 0, 2),
            'total_value_impact' => round($rateChanges->sum(), 2),
        ];

        $dailyChanges = $changes
            ->groupBy(fn (VehiclePricingHistory $history) => Carbon::parse($history->changed_at ?? $history->created_at)->toDateString())
            ->map(fn ($items) => [
                'total_changes' => $items->count(),
                'increases' => $items->where('change_type', 'increase')->count(),
                'decreases' => $items->where('change_type', 'decrease')->count(),
                'average_change' => round($items->avg(fn (VehiclePricingHistory $history) => (float) ($history->rate_change ?? 0)) ?? 0, 2),
            ])
            ->toArray();

        $byVehicleGroup = $changes
            ->groupBy(fn (VehiclePricingHistory $history) => $history->vehicleGroup?->name ?? 'Unassigned vehicle group')
            ->map(fn ($items) => $this->pricingAnalyticsBucket($items))
            ->toArray();

        $byServiceType = $changes
            ->groupBy(fn (VehiclePricingHistory $history) => $history->serviceType?->name ?? 'Unassigned service type')
            ->map(fn ($items) => $this->pricingAnalyticsBucket($items))
            ->toArray();

        $mostActiveUsers = $changes
            ->groupBy(fn (VehiclePricingHistory $history) => trim((string) ($history->changedBy?->first_name . ' ' . $history->changedBy?->last_name)) ?: ($history->changedBy?->email ?? 'System'))
            ->map(fn ($items) => $items->count())
            ->sortDesc()
            ->take(10)
            ->toArray();

        return response()->json([
            'status' => 'success',
            'data' => [
                'summary' => $summary,
                'trends' => [
                    'daily_changes' => $dailyChanges,
                    'weekly_average' => round($changes->count() / max(1, $fromDate->diffInWeeks(now()) ?: 1), 2),
                ],
                'by_vehicle_group' => $byVehicleGroup,
                'by_service_type' => $byServiceType,
                'most_active_users' => $mostActiveUsers,
            ],
        ]);
    }

    private function applyOwnerPriorityOrder($query, ?string $ownerType, ?string $ownerId): void
    {
        if ($ownerType && $ownerId) {
            $query->orderByRaw(
                'CASE WHEN owner_type = ? AND owner_id = ? THEN 0 ELSE 1 END',
                [$ownerType, $ownerId]
            );

            return;
        }

        $query->orderByRaw('1');
    }

    public function getBulkOperations(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $operations = VehiclePricingBulkOperation::query()
            ->with('initiatedBy:id,name')
            ->when($request->filled('operation_type'), fn ($query) => $query->where('operation_type', $request->input('operation_type')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->input('status')))
            ->when($request->filled('from_date'), fn ($query) => $query->whereDate('created_at', '>=', $request->input('from_date')))
            ->when($request->filled('to_date'), fn ($query) => $query->whereDate('created_at', '<=', $request->input('to_date')))
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->input('search');
                $query->where(fn ($nested) => $nested->where('operation_name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%"));
            })
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        $operations->getCollection()->each->append(['progress_percentage', 'success_rate', 'operation_type_label', 'status_label', 'duration']);

        return response()->json(['status' => 'success', 'data' => $operations]);
    }

    private function pricingAnalyticsBucket($items): array
    {
        return [
            'total_changes' => $items->count(),
            'increases' => $items->where('change_type', 'increase')->count(),
            'decreases' => $items->where('change_type', 'decrease')->count(),
            'average_change' => round($items->avg(fn (VehiclePricingHistory $history) => (float) ($history->rate_change ?? 0)) ?? 0, 2),
        ];
    }

    public function getBulkOperationStatus(\Illuminate\Http\Request $request, string $operationId): \Illuminate\Http\JsonResponse
    {
        $operation = VehiclePricingBulkOperation::with('initiatedBy:id,name')->findOrFail($operationId);
        $operation->append(['progress_percentage', 'success_rate', 'operation_type_label', 'status_label', 'duration']);

        return response()->json(['status' => 'success', 'data' => $operation]);
    }

    public function cancelBulkOperation(string $operationId): JsonResponse
    {
        $operation = VehiclePricingBulkOperation::findOrFail($operationId);
        abort_unless($operation->isRunning(), 409, 'Only pending or in-progress operations can be cancelled.');
        $operation->update(['status' => 'cancelled', 'completed_at' => now()]);

        return response()->json(['status' => 'success', 'message' => 'Bulk operation cancelled', 'data' => $operation->fresh()]);
    }

    public function retryBulkOperation(string $operationId): JsonResponse
    {
        $operation = VehiclePricingBulkOperation::findOrFail($operationId);
        abort_unless(in_array($operation->status, ['failed', 'cancelled'], true), 409, 'Only failed or cancelled operations can be retried.');

        $retryRequest = Request::create('', 'POST', $operation->operation_data ?? []);
        return $this->bulkSavePricing($retryRequest);
    }

    public function downloadBulkOperationReport(string $operationId)
    {
        $operation = VehiclePricingBulkOperation::with('initiatedBy:id,name')->findOrFail($operationId);
        $rows = [
            ['Operation ID', $operation->id], ['Name', $operation->operation_name],
            ['Type', $operation->operation_type], ['Status', $operation->status],
            ['Total records', $operation->total_records], ['Processed records', $operation->processed_records],
            ['Successful records', $operation->successful_records], ['Failed records', $operation->failed_records],
            ['Initiated by', $operation->initiatedBy?->name ?? ''], ['Started at', $operation->started_at?->toIso8601String() ?? ''],
            ['Completed at', $operation->completed_at?->toIso8601String() ?? ''],
            ['Errors', implode(' | ', $operation->errors ?? [])],
        ];
        $csv = collect($rows)->map(fn ($row) => collect($row)->map(fn ($value) => '"' . str_replace('"', '""', (string) $value) . '"')->implode(','))->implode("\n");

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="pricing-bulk-operation-' . $operation->id . '.csv"',
        ]);
    }

    public function importPricing(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'file'             => 'required|file|mimes:csv,txt|max:5120',
            'vehicle_group_id' => 'required|uuid|exists:vehicle_groups,id',
        ]);

        $lines        = file($request->file('file')->getRealPath());
        $headers      = array_map('trim', str_getcsv(array_shift($lines)));
        $vehicleGroup = VehicleGroup::findOrFail($request->input('vehicle_group_id'));
        $imported     = 0;

        \Illuminate\Support\Facades\DB::beginTransaction();
        try {
            foreach ($lines as $line) {
                $row = array_combine($headers, array_map('trim', str_getcsv($line)));
                VehicleGroupPricing::updateOrCreate(
                    [
                        'vehicle_group_id' => $vehicleGroup->id,
                        'service_type_id'  => $row['service_type_id'] ?? null,
                    ],
                    [
                        'base_price'    => (float) ($row['base_price'] ?? 0),
                        'price_per_km'  => (float) ($row['price_per_km'] ?? 0),
                        'price_per_hour'=> (float) ($row['price_per_hour'] ?? 0),
                        'is_active'     => strtolower($row['is_active'] ?? 'yes') === 'yes',
                    ]
                );
                $imported++;
            }
            \Illuminate\Support\Facades\DB::commit();
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            return response()->json(['status' => 'error', 'message' => 'Import failed: ' . $e->getMessage()], 422);
        }

        return response()->json(['status' => 'success', 'data' => ['imported' => $imported]]);
    }

    public function exportPricing(\Illuminate\Http\Request $request)
    {
        $query = VehicleGroupPricing::with(['vehicleGroup', 'serviceType']);

        if ($request->filled('vehicle_group_id')) {
            $query->where('vehicle_group_id', $request->input('vehicle_group_id'));
        }

        $rows   = $query->get()->map(fn($p) => [
            '"' . $p->vehicle_group_id . '"',
            '"' . str_replace('"', '""', $p->vehicleGroup?->name ?? '') . '"',
            '"' . ($p->service_type_id ?? '') . '"',
            '"' . str_replace('"', '""', $p->serviceType?->name ?? '') . '"',
            $p->base_price,
            $p->price_per_km,
            $p->price_per_hour,
            $p->is_active ? '"yes"' : '"no"',
        ]);
        $header = '"vehicle_group_id","vehicle_group","service_type_id","service_type","base_price","price_per_km","price_per_hour","is_active"';
        $csv    = $header . "\n" . $rows->map(fn($r) => implode(',', $r))->implode("\n");

        return response($csv, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="vehicle-group-pricing.csv"',
        ]);
    }

    public function downloadTemplate()
    {
        $csv = "vehicle_group_id,service_type_id,base_price,price_per_km,price_per_hour,is_active\n"
             . "\"\",\"\",\"0.00\",\"0.00\",\"0.00\",\"yes\"\n";

        return response($csv, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="vehicle-group-pricing-template.csv"',
        ]);
    }
}
