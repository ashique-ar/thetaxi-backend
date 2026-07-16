<?php

namespace App\Http\Controllers\Api\Vehicle\VehiclePricing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vehicle\VehiclePricingSlabDefinition\CreateVehiclePricingSlabDefinitionRequest;
use App\Http\Requests\Vehicle\VehiclePricingSlabDefinition\UpdateVehiclePricingSlabDefinitionRequest;
use App\Models\Vehicle\VehiclePricing\VehiclePricingSlabDefinition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class VehiclePricingSlabDefinitionController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:vehicle-pricing-slabs.view')->only(['index', 'show', 'findForHours']);
        $this->middleware('permission:vehicle-pricing-slabs.create')->only(['store']);
        $this->middleware('permission:vehicle-pricing-slabs.edit')->only(['update', 'toggleStatus']);
        $this->middleware('permission:vehicle-pricing-slabs.delete')->only(['destroy']);
    }

    /**
     * Display a listing of the slab definitions.
     */
    public function index(Request $request): JsonResponse
    {
        $query = VehiclePricingSlabDefinition::withInactive()->with(['serviceType']);

        // Filter by service type if provided
        if ($request->has('service_type_id')) {
            $query->forServiceType($request->service_type_id);
        }

        if ($request->filled('context')) {
            $query->whereHas('serviceType', function ($serviceQuery) use ($request) {
                $serviceQuery->where('context', $request->input('context'));
            });
        }

        $query->whereNull('owner_type')->whereNull('owner_id');

        // Filter by active status if provided
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $slabDefinitions = $query->orderByDesc('priority')->orderBy('sort_order')->get();

        return response()->json([
            'success' => true,
            'data' => $slabDefinitions,
            'message' => 'Slab definitions retrieved successfully'
        ]);
    }

    /**
     * Store a newly created slab definition.
     */
    public function store(CreateVehiclePricingSlabDefinitionRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['owner_type'] = null;
        $data['owner_id'] = null;
        if ($overlap = $this->findOverlappingSlab($data)) {
            return response()->json([
                'success' => false,
                'message' => "Duration range overlaps with '{$overlap->name}'",
                'errors' => ['range' => ['Slab duration ranges must not overlap for the same service and unit.']],
            ], 422);
        }
        $slabDefinition = VehiclePricingSlabDefinition::create($data + ['created_user_id' => $request->user()->id]);

        return response()->json([
            'success' => true,
            'data' => $slabDefinition,
            'message' => 'Slab definition created successfully'
        ], 201);
    }

    /**
     * Display the specified slab definition.
     */
    public function show(string $id): JsonResponse
    {
        $slabDefinition = VehiclePricingSlabDefinition::withInactive()->with('serviceType')->find($id);

        if (!$slabDefinition) {
            return response()->json([
                'success' => false,
                'message' => 'Slab definition not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $slabDefinition,
            'message' => 'Slab definition retrieved successfully'
        ]);
    }

    /**
     * Update the specified slab definition.
     */
    public function update(UpdateVehiclePricingSlabDefinitionRequest $request, string $id): JsonResponse
    {
        $slabDefinition = VehiclePricingSlabDefinition::withInactive()->findOrFail($id);
        $data = $request->validated();
        $data['owner_type'] = null;
        $data['owner_id'] = null;
        $candidate = array_merge($slabDefinition->toArray(), $data);
        if ($overlap = $this->findOverlappingSlab($candidate, $slabDefinition->id)) {
            return response()->json([
                'success' => false,
                'message' => "Duration range overlaps with '{$overlap->name}'",
                'errors' => ['range' => ['Slab duration ranges must not overlap for the same service and unit.']],
            ], 422);
        }
        $slabDefinition->update($data);
        $slabDefinition->load('serviceType');
        return response()->json([
            'success' => true,
            'data' => $slabDefinition,
            'message' => 'Slab definition updated successfully'
        ]);
    }

    /**
     * Remove the specified slab definition.
     */
    public function destroy(string $id): JsonResponse
    {
        $slabDefinition = VehiclePricingSlabDefinition::withInactive()->find($id);

        if (!$slabDefinition) {
            return response()->json([
                'success' => false,
                'message' => 'Slab definition not found'
            ], 404);
        }

        // Check if slab definition is being used in pricing records
        $pricingCount = $slabDefinition->vehicleGroupPricing()->count();
        if ($pricingCount > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete slab definition. It is being used in pricing records.'
            ], 422);
        }

        $slabDefinition->delete();

        return response()->json([
            'success' => true,
            'message' => 'Slab definition deleted successfully'
        ]);
    }

    /**
     * Bulk create slab definitions.
     */
    public function bulkStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'slab_definitions' => 'required|array|min:1',
            'slab_definitions.*.service_type_id' => 'required|uuid|exists:service_types,id',
            'slab_definitions.*.name' => 'required|string|max:255',
            'slab_definitions.*.type' => 'required|in:minutes,hours,days',
            'slab_definitions.*.min_minutes' => 'nullable|integer|min:0',
            'slab_definitions.*.max_minutes' => 'nullable|integer|gte:slab_definitions.*.min_minutes',
            'slab_definitions.*.min_hours' => 'nullable|integer|min:0',
            'slab_definitions.*.max_hours' => 'nullable|integer|gte:slab_definitions.*.min_hours',
            'slab_definitions.*.min_days' => 'nullable|integer|min:0',
            'slab_definitions.*.max_days' => 'nullable|integer|gte:slab_definitions.*.min_days',
            'slab_definitions.*.max_km_per_day' => 'nullable|integer|min:0',
            'slab_definitions.*.max_km_per_package' => 'nullable|integer|min:0',
            'slab_definitions.*.sort_order' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $slabDefinitions = $request->slab_definitions;
        $createdDefinitions = [];
        $errors = [];

        DB::beginTransaction();

        try {
            foreach ($slabDefinitions as $index => $definitionData) {
                // Check for overlapping ranges based on type
                $existingOverlap = VehiclePricingSlabDefinition::forServiceType($definitionData['service_type_id'])
                    ->where('type', $definitionData['type'])
                    ->where(function ($query) use ($definitionData) {
                        if ($definitionData['type'] === 'minutes') {
                            $minMinutes = $definitionData['min_minutes'] ?? 0;
                            $maxMinutes = $definitionData['max_minutes'] ?? 999999;
                            $query->where(function ($q) use ($minMinutes, $maxMinutes) {
                                $q->whereBetween('min_minutes', [$minMinutes, $maxMinutes])
                                  ->orWhereBetween('max_minutes', [$minMinutes, $maxMinutes])
                                  ->orWhere(function ($subQ) use ($minMinutes, $maxMinutes) {
                                      $subQ->where('min_minutes', '<=', $minMinutes)
                                           ->where('max_minutes', '>=', $maxMinutes);
                                  });
                            });
                        } elseif ($definitionData['type'] === 'hours') {
                            $minHours = $definitionData['min_hours'] ?? 0;
                            $maxHours = $definitionData['max_hours'] ?? 999999;
                            $query->where(function ($q) use ($minHours, $maxHours) {
                                $q->whereBetween('min_hours', [$minHours, $maxHours])
                                  ->orWhereBetween('max_hours', [$minHours, $maxHours])
                                  ->orWhere(function ($subQ) use ($minHours, $maxHours) {
                                      $subQ->where('min_hours', '<=', $minHours)
                                           ->where('max_hours', '>=', $maxHours);
                                  });
                            });
                        } else {
                            $minDays = $definitionData['min_days'] ?? 0;
                            $maxDays = $definitionData['max_days'] ?? 999;
                            $query->where(function ($q) use ($minDays, $maxDays) {
                                $q->whereBetween('min_days', [$minDays, $maxDays])
                                  ->orWhereBetween('max_days', [$minDays, $maxDays])
                                  ->orWhere(function ($subQ) use ($minDays, $maxDays) {
                                      $subQ->where('min_days', '<=', $minDays)
                                           ->where('max_days', '>=', $maxDays);
                                  });
                            });
                        }
                    })
                    ->first();

                if ($existingOverlap) {
                    $errors["slab_definitions.{$index}"] = 'Date range overlaps with existing slab definition';
                    continue;
                }

                $slabDefinition = VehiclePricingSlabDefinition::create($definitionData);
                $slabDefinition->load('serviceType');
                $createdDefinitions[] = $slabDefinition;
            }

            if (!empty($errors)) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Some slab definitions could not be created',
                    'errors' => $errors
                ], 422);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => $createdDefinitions,
                'message' => 'Bulk slab definitions created successfully'
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create bulk slab definitions',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reorder slab definitions.
     */
    public function reorder(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'slab_definitions' => 'required|array|min:1',
            'slab_definitions.*.id' => 'required|uuid|exists:vehicle_pricing_slab_definitions,id',
            'slab_definitions.*.sort_order' => 'required|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();

        try {
            foreach ($request->slab_definitions as $definition) {
                VehiclePricingSlabDefinition::where('id', $definition['id'])
                    ->update(['sort_order' => $definition['sort_order']]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Slab definitions reordered successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to reorder slab definitions',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function toggleStatus(Request $request, string $id): JsonResponse
    {
        $definition = VehiclePricingSlabDefinition::withInactive()->findOrFail($id);
        $definition->update(['is_active' => !$definition->is_active]);
        $definition->load('serviceType');

        return response()->json([
            'status'  => 'success',
            'message' => 'Slab definition status updated',
            'data'    => $definition,
        ]);
    }

    public function findForHours(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'hours'           => 'nullable|required_without:minutes|numeric|min:0',
            'minutes'         => 'nullable|required_without:hours|numeric|min:0',
            'service_type_id' => 'nullable|uuid|exists:service_types,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        $minutes = $request->filled('minutes')
            ? (float) $request->input('minutes')
            : (float) $request->input('hours') * 60;
        $hours = $minutes / 60;

        $baseQuery = VehiclePricingSlabDefinition::active();
        if ($request->filled('service_type_id')) {
            $baseQuery->forServiceType($request->input('service_type_id'));
        }

        $slab = (clone $baseQuery)
            ->where('type', 'minutes')
            ->where('min_minutes', '<=', $minutes)
            ->where(function ($q) use ($minutes) {
                $q->whereNull('max_minutes')->orWhere('max_minutes', '>=', $minutes);
            })
            ->orderByDesc('priority')
            ->orderByDesc('min_minutes')
            ->first();

        $query = (clone $baseQuery)
            ->where(function ($q) {
                $q->whereNull('type')->orWhere('type', '!=', 'minutes');
            })
            ->where('min_hours', '<=', $hours)
            ->where(function ($q) use ($hours) {
                $q->whereNull('max_hours')->orWhere('max_hours', '>=', $hours);
            })
            ->orderByDesc('priority')
            ->orderBy('min_hours', 'desc');

        $slab ??= $query->first();

        return response()->json([
            'status' => 'success',
            'data'   => $slab,
        ]);
    }

    private function findOverlappingSlab(array $data, ?string $excludeId = null): ?VehiclePricingSlabDefinition
    {
        $type = $data['type'] ?? 'hours';
        [$minKey, $maxKey] = match ($type) {
            'minutes' => ['min_minutes', 'max_minutes'],
            'days', 'per_day' => ['min_days', 'max_days'],
            default => ['min_hours', 'max_hours'],
        };
        $minimum = $data[$minKey] ?? null;
        $maximum = $data[$maxKey] ?? null;
        if ($minimum === null) {
            return null;
        }

        return VehiclePricingSlabDefinition::withInactive()
            ->where('service_type_id', $data['service_type_id'])
            ->where('type', $type)
            ->when($excludeId, fn ($query) => $query->where('id', '!=', $excludeId))
            ->where($minKey, '<=', $maximum ?? PHP_INT_MAX)
            ->where(function ($query) use ($maxKey, $minimum) {
                $query->whereNull($maxKey)->orWhere($maxKey, '>=', $minimum);
            })
            ->orderByDesc('priority')
            ->first();
    }
}
