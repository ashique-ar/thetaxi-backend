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

        // Filter by active status if provided
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $slabDefinitions = $query->orderBy('sort_order')->get();

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
        $slabDefinition = VehiclePricingSlabDefinition::create($request->validated() + ['created_user_id' => $request->user()->id]);

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
        $slabDefinition = VehiclePricingSlabDefinition::with('serviceType')->find($id);

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
        $slabDefinition = VehiclePricingSlabDefinition::find($id);
        $slabDefinition->update($request->validated());
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
        $slabDefinition = VehiclePricingSlabDefinition::find($id);

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
            'slab_definitions.*.type' => 'required|in:hours,days',
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
                        if ($definitionData['type'] === 'hours') {
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
}
