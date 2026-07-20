<?php

namespace App\Http\Controllers\Api\Vehicle\VehiclePricing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vehicle\VehiclePricingSlabDefinition\CreateVehiclePricingSlabDefinitionRequest;
use App\Http\Requests\Vehicle\VehiclePricingSlabDefinition\UpdateVehiclePricingSlabDefinitionRequest;
use App\Models\Service\ServiceType;
use App\Models\Vehicle\VehiclePricing\VehiclePricingSlabDefinition;
use App\Services\VehiclePricingSlabConfigurationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class VehiclePricingSlabDefinitionController extends Controller
{
    public function __construct(
        private readonly VehiclePricingSlabConfigurationService $slabConfiguration
    )
    {
        $this->middleware('permission:vehicle-pricing-slabs.view')->only(['index', 'show', 'health', 'findForHours', 'getServiceTypes']);
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

        $slabDefinitions = $query
            ->orderBy('service_type_id')
            ->orderBy('sort_order')
            ->orderByDesc('priority')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $slabDefinitions,
            'message' => 'Slab definitions retrieved successfully'
        ]);
    }

    /**
     * Return the service types available to the shared slab-definition editor.
     *
     * This lookup intentionally belongs to the slab-definition permission
     * boundary. Pricing administrators should not also need the unrelated
     * service-types.view permission just to select a service while managing
     * slabs.
     */
    public function getServiceTypes(Request $request): JsonResponse
    {
        $context = (string) $request->input('context', 'public');
        if (!in_array($context, ['public', 'portal', 'corporate'], true)) {
            return response()->json([
                'message' => 'The selected pricing context is invalid.',
            ], 422);
        }

        $serviceTypes = ServiceType::withInactive()
            ->forContext($context, '', '')
            ->where('is_active', true)
            ->select([
                'id',
                'name',
                'description',
                'code',
                'context',
                'owner_type',
                'owner_id',
                'is_active',
            ])
            ->orderBy('priority')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $serviceTypes,
            'message' => 'Service types retrieved successfully',
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
        if ($nameConflict = $this->findNameConflict($data['service_type_id'], $data['name'])) {
            return $this->duplicateNameResponse($nameConflict);
        }
        if ($overlap = $this->findOverlappingSlab($data)) {
            return response()->json([
                'success' => false,
                'message' => "Duration range overlaps with '{$overlap->name}'",
                'errors' => ['range' => ['Slab duration ranges must not overlap for the same service and unit.']],
            ], 422);
        }

        $data['is_active'] = $data['is_active'] ?? true;
        if ($data['is_active']) {
            $health = $this->slabConfiguration->prospectiveHealth($data);
            $scope = [[
                'service_type_id' => $data['service_type_id'],
                'type' => $data['type'],
            ]];
            if ($this->slabConfiguration->hasBlockingIssues($health, $scope)) {
                return $this->invalidConfigurationResponse($health);
            }
        }

        $slabDefinition = VehiclePricingSlabDefinition::create($data + ['created_user_id' => $request->user()->id]);
        $slabDefinition->load('serviceType');

        return response()->json([
            'success' => true,
            'data' => $slabDefinition,
            'health' => $this->currentHealth($slabDefinition->service_type_id),
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
        if ($nameConflict = $this->findNameConflict(
            $candidate['service_type_id'],
            $candidate['name'],
            $slabDefinition->id
        )) {
            return $this->duplicateNameResponse($nameConflict);
        }
        $candidateActive = array_key_exists('is_active', $candidate)
            ? filter_var($candidate['is_active'], FILTER_VALIDATE_BOOL)
            : $slabDefinition->is_active;
        if ($candidateActive) {
            $health = $this->slabConfiguration->prospectiveHealth($candidate, $slabDefinition);
            $scopes = collect([
                [
                    'service_type_id' => $slabDefinition->service_type_id,
                    'type' => $slabDefinition->type ?: 'hours',
                ],
                [
                    'service_type_id' => $candidate['service_type_id'],
                    'type' => $candidate['type'] ?: 'hours',
                ],
            ])->unique(fn (array $scope) => $scope['service_type_id'] . ':' . $scope['type'])->values()->all();
            if ($this->slabConfiguration->hasBlockingIssues($health, $scopes)) {
                $currentBlockingIssueCount = collect($scopes)
                    ->pluck('service_type_id')
                    ->unique()
                    ->sum(fn (string $serviceTypeId) => $this->blockingIssueCount(
                        $this->currentHealth($serviceTypeId),
                        $scopes
                    ));
                $prospectiveBlockingIssueCount = $this->blockingIssueCount($health, $scopes);

                // Permit one-at-a-time repairs of legacy-invalid configurations
                // only when this edit strictly reduces the affected errors.
                if ($prospectiveBlockingIssueCount >= $currentBlockingIssueCount) {
                    return $this->invalidConfigurationResponse($health);
                }
            }
        }

        $slabDefinition->update($data);
        $slabDefinition->load('serviceType');
        return response()->json([
            'success' => true,
            'data' => $slabDefinition,
            'health' => $this->currentHealth($slabDefinition->service_type_id),
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

        $retiredPricingCount = DB::transaction(function () use ($slabDefinition): int {
            // Slab deletion is soft. Retire its current rate rows as well so an
            // active price cannot continue pointing at a deleted definition.
            // Historical calculation and booking references remain intact.
            $pricingRows = $slabDefinition->vehicleGroupPricing()
                ->withInactive()
                ->get();

            $pricingRows->each->delete();
            $slabDefinition->delete();

            return $pricingRows->count();
        });

        return response()->json([
            'success' => true,
            'data' => [
                'retired_pricing_records' => $retiredPricingCount,
            ],
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

        if (!$definition->is_active) {
            $candidate = $definition->toArray();
            $candidate['is_active'] = true;
            $health = $this->slabConfiguration->prospectiveHealth($candidate, $definition);
            $scope = [[
                'service_type_id' => $definition->service_type_id,
                'type' => $definition->type ?: 'hours',
            ]];
            if ($this->slabConfiguration->hasBlockingIssues($health, $scope)) {
                return $this->invalidConfigurationResponse($health, 'This slab cannot be activated because its duration configuration is invalid.');
            }
        }

        $definition->update(['is_active' => !$definition->is_active]);
        $definition->load('serviceType');

        return response()->json([
            'success' => true,
            'status'  => 'success',
            'message' => 'Slab definition status updated',
            'data'    => $definition,
            'health'  => $this->currentHealth($definition->service_type_id),
        ]);
    }

    public function health(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'service_type_id' => ['nullable', 'uuid', 'exists:service_types,id'],
            'context' => ['nullable', 'string', 'in:public,portal,corporate'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $this->slabConfiguration->currentHealth(
                $request->filled('service_type_id') ? (string) $request->input('service_type_id') : null,
                $request->filled('context') ? (string) $request->input('context') : null
            ),
            'message' => 'Slab configuration health retrieved successfully',
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
        $baseQuery = VehiclePricingSlabDefinition::active();
        if ($request->filled('service_type_id')) {
            $baseQuery->forServiceType($request->input('service_type_id'));
        }

        $slab = $this->slabConfiguration->resolve($baseQuery, $minutes);

        return response()->json([
            'status' => 'success',
            'data'   => $slab,
            'resolution' => [
                'duration_minutes' => $minutes,
                'matched_type' => $slab?->type ?: ($slab ? 'hours' : null),
                'precedence' => VehiclePricingSlabConfigurationService::RESOLUTION_PRECEDENCE,
            ],
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

    private function findNameConflict(
        string $serviceTypeId,
        string $name,
        ?string $excludeId = null
    ): ?VehiclePricingSlabDefinition {
        return VehiclePricingSlabDefinition::withInactive()
            ->where('service_type_id', $serviceTypeId)
            ->where('name', trim($name))
            ->when($excludeId, fn ($query) => $query->where('id', '!=', $excludeId))
            ->first();
    }

    private function duplicateNameResponse(VehiclePricingSlabDefinition $conflict): JsonResponse
    {
        $status = $conflict->is_active ? 'active' : 'inactive';

        return response()->json([
            'success' => false,
            'message' => "A {$status} slab named '{$conflict->name}' already exists for this service type.",
            'errors' => [
                'name' => [
                    $conflict->is_active
                        ? 'Use a different name or edit the existing slab.'
                        : 'Reactivate or edit the existing inactive slab instead of creating a duplicate.',
                ],
            ],
            'conflict' => [
                'id' => $conflict->id,
                'is_active' => (bool) $conflict->is_active,
            ],
        ], 422);
    }

    private function currentHealth(string $serviceTypeId): array
    {
        return $this->slabConfiguration->currentHealth($serviceTypeId);
    }

    /** @param array<int, array{service_type_id: string, type: string}> $scopes */
    private function blockingIssueCount(array $health, array $scopes): int
    {
        return collect($health['issues'] ?? [])->filter(function (array $issue) use ($scopes) {
            if (($issue['severity'] ?? null) !== 'error') {
                return false;
            }

            return collect($scopes)->contains(function (array $scope) use ($issue) {
                if (($issue['service_type_id'] ?? null) !== $scope['service_type_id']) {
                    return false;
                }

                return ($issue['type'] ?? null) === null
                    || (string) $issue['type'] === (string) $scope['type'];
            });
        })->count();
    }

    private function invalidConfigurationResponse(
        array $health,
        string $message = 'Slab duration ranges must not contain gaps, overlaps, or multiple open-ended ranges.'
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => [
                'configuration' => collect($health['issues'] ?? [])
                    ->where('severity', 'error')
                    ->pluck('message')
                    ->values()
                    ->all(),
            ],
            'health' => $health,
        ], 422);
    }
}
