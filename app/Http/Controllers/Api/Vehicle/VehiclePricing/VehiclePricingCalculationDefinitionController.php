<?php

namespace App\Http\Controllers\Api\Vehicle\VehiclePricing;

use App\Http\Controllers\Controller;
use App\Models\Service\ServiceType;
use App\Models\Corporate\Corporate;
use App\Models\Vehicle\VehiclePricing\VehiclePricingCalculationDefinition;
use App\Models\Vehicle\VehiclePricing\VehiclePricingSlabDefinition;
use App\Models\Vehicle\VehiclePricing\VehicleGroupPricing;
use App\Models\Vehicle\VehiclePricing\VehicleGroupCommonRatePricing;
use App\Models\Vehicle\VehiclePricing\VehiclePricingCommonRateDefinition;
use App\Models\Vehicle\VehicleGroup;
use App\Services\Pricing\PricingCalculationDefinitionHealthService;
use App\Services\Pricing\PricingDefinitionOrchestrator;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

class VehiclePricingCalculationDefinitionController extends Controller
{
    public function __construct(
        private readonly PricingCalculationDefinitionHealthService $configurationHealth
    )
    {
        $this->middleware('permission:vehicle-pricing-calculations.view')->only(['index', 'show', 'health', 'definitionHealth', 'getServiceTypes', 'getVehicleGroups', 'getAvailableVariables', 'testCalculation', 'testDefinitionCalculation', 'calculatePrice', 'getSlabRates']);
        $this->middleware('permission:vehicle-pricing-calculations.create')->only(['store']);
        $this->middleware('permission:vehicle-pricing-calculations.edit')->only(['update', 'bulkUpdateStatus']);
        $this->middleware('permission:vehicle-pricing-calculations.delete')->only(['destroy']);
    }

    /**
     * Display a listing of calculation definitions with pagination and filtering.
     */
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'page' => 'integer|min:1',
            'per_page' => 'integer|min:1|max:100',
            'search' => 'string|max:255',
            'service_type_id' => 'uuid|exists:service_types,id',
            'owner_type' => 'nullable|string|in:corporate',
            'owner_id' => 'nullable|uuid|exists:corporates,id|required_with:owner_type',
            'context' => 'nullable|string|in:public,portal,corporate',
            'status' => 'in:active,inactive,draft',
            'sort_by' => 'in:name,created_at,updated_at,status,priority',
            'sort_direction' => 'in:asc,desc',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $query = VehiclePricingCalculationDefinition::with(['serviceType', 'creator']);

        // Apply filters
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function (Builder $q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhere('formula', 'like', "%{$search}%");
            });
        }

        if ($request->filled('context')) {
            $query->whereHas('serviceType', function ($serviceQuery) use ($request) {
                $serviceQuery->where('context', $request->input('context'));
            });
        }

        if ($request->filled('service_type_id')) {
            $query->where('service_type_id', $request->service_type_id);
        }

        $query->whereNull('owner_type')->whereNull('owner_id');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Apply sorting
        $sortBy = $request->get('sort_by', 'created_at');
        $sortDirection = $request->get('sort_direction', 'desc');
        $query->orderBy($sortBy, $sortDirection);

        // Paginate results
        $perPage = $request->get('per_page', 15);
        $definitions = $query->paginate($perPage);

        return response()->json([
            'data' => collect($definitions->items())->map(fn ($definition) => array_merge(
                $definition->toArray(),
                ['calculation_example' => $definition->getCalculationExample()]
            ))->values(),
            'pagination' => [
                'current_page' => $definitions->currentPage(),
                'last_page' => $definitions->lastPage(),
                'per_page' => $definitions->perPage(),
                'total' => $definitions->total(),
                'from' => $definitions->firstItem(),
                'to' => $definitions->lastItem(),
            ]
        ]);
    }

    /**
     * Store a newly created calculation definition.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'service_type_id' => 'required|uuid|exists:service_types,id',
            'formula' => 'required|string|max:2000',
            'variables' => 'nullable|array',
            'variables.*.name' => ['required', 'string', 'max:255', 'distinct', 'regex:/^[A-Za-z_][A-Za-z0-9_]*$/'],
            'variables.*.type' => ['required', Rule::in(array_keys(VehiclePricingCalculationDefinition::getSupportedVariableTypes()))],
            'variables.*.default_value' => 'nullable',
            'variables.*.is_required' => 'nullable|boolean',
            'variables.*.description' => 'nullable|string',
            'conditions' => 'nullable|array',
            'conditions.*.field' => ['required', 'string', 'regex:/^[A-Za-z_][A-Za-z0-9_]*$/'],
            'conditions.*.operator' => ['required', Rule::in(array_merge(
                array_keys(VehiclePricingCalculationDefinition::getSupportedConditionOperators()),
                ['=', '!=', '>', '<', '>=', '<=']
            ))],
            'conditions.*.value' => 'required',
            'status' => 'in:active,inactive,draft',
            'owner_type' => 'nullable|string|in:corporate',
            'owner_id' => 'nullable|uuid|exists:corporates,id|required_with:owner_type',
            'priority' => 'nullable|integer|min:0',
        ]);

        $validator->after(function ($validator) use ($request) {
            $formula = $request->input('formula');
            $variables = $request->input('variables', []);
            if (!is_string($formula) || !is_array($variables)) {
                return;
            }

            foreach (VehiclePricingCalculationDefinition::validateFormulaConfiguration(
                $formula,
                $variables
            ) as $error) {
                $validator->errors()->add('formula', $error);
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        if (!$this->serviceTypeIsAvailableInScope($request, (string) $request->service_type_id)) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => [
                    'service_type_id' => ['The selected service type is not available in this pricing context.'],
                ],
            ], 422);
        }

        $status = (string) $request->get('status', 'draft');
        $candidateId = (string) Str::uuid();
        $activationHealth = null;
        if ($status === 'active') {
            $activationHealth = $this->configurationHealth->prospectiveDefinitionHealth([
                'id' => $candidateId,
                'name' => $request->name,
                'description' => $request->description,
                'service_type_id' => $request->service_type_id,
                'formula' => $request->formula,
                'variables' => $request->variables ?? [],
                'conditions' => $request->conditions ?? [],
                'status' => 'active',
                'priority' => $request->input('priority', 0),
            ]);
            if (!$activationHealth['ready_for_activation']) {
                return $this->activationBlockedResponse($activationHealth);
            }
        }

        try {
            $definition = new VehiclePricingCalculationDefinition();
            $definition->id = $candidateId;
            $definition->name = $request->name;
            $definition->description = $request->description;
            $definition->service_type_id = $request->service_type_id;
            $definition->formula = $request->formula;
            $definition->variables = $request->variables ?? [];
            $definition->conditions = $request->conditions ?? [];
            $definition->status = $status;
            $definition->owner_type = null;
            $definition->owner_id = null;
            $definition->priority = $request->input('priority', 0);
            $definition->created_by = Auth::id();
            $definition->save();

            $definition->load(['serviceType', 'creator']);

            return response()->json([
                'message' => 'Calculation definition created successfully',
                'data' => array_merge($definition->toArray(), [
                    'calculation_example' => $definition->getCalculationExample(),
                ]),
                'configuration_health' => $activationHealth,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create calculation definition',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified calculation definition.
     */
    public function show(string $id): JsonResponse
    {
        try {
            $definition = VehiclePricingCalculationDefinition::with(['serviceType', 'creator'])
                ->findOrFail($id);

            return response()->json([
                'data' => array_merge($definition->toArray(), [
                    'calculation_example' => $definition->getCalculationExample(),
                ])
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Calculation definition not found',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    public function health(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'service_type_id' => ['required', 'uuid', 'exists:service_types,id'],
            'context' => ['nullable', 'string', 'in:public,portal,corporate'],
            'owner_type' => ['nullable', 'required_if:context,corporate', 'string', 'in:corporate'],
            'owner_id' => ['nullable', 'required_if:context,corporate', 'uuid', 'exists:corporates,id'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        if ($request->input('context') === 'corporate') {
            $assigned = Corporate::findOrFail($request->input('owner_id'))
                ->serviceTypes()
                ->where('service_types.id', $request->input('service_type_id'))
                ->exists();
            if (!$assigned) {
                return response()->json([
                    'success' => false,
                    'message' => 'The selected service is not assigned to this corporate.',
                ], 422);
            }
        }

        return response()->json([
            'success' => true,
            'data' => $this->configurationHealth->currentServiceHealth(
                (string) $request->input('service_type_id'),
                $request->input('context') === 'corporate' ? 'corporate' : null,
                $request->input('context') === 'corporate' ? (string) $request->input('owner_id') : null
            ),
            'message' => 'Calculation-definition health retrieved successfully',
        ]);
    }

    public function definitionHealth(Request $request, string $id): JsonResponse
    {
        $definition = VehiclePricingCalculationDefinition::findOrFail($id);
        $ownerType = $request->input('context') === 'corporate' ? 'corporate' : null;
        $ownerId = $ownerType ? (string) $request->input('owner_id') : null;
        if ($ownerType && !$ownerId) {
            return response()->json([
                'success' => false,
                'message' => 'Select a corporate before checking definition readiness.',
            ], 422);
        }
        $serviceHealth = $this->configurationHealth->definitionReadiness($definition, $ownerType, $ownerId);
        $definitionHealth = $serviceHealth['definitions'][$definition->id] ?? null;

        return response()->json([
            'success' => true,
            'data' => array_merge($definitionHealth ?? [
                'healthy' => false,
                'ready_for_activation' => false,
                'definition_id' => $definition->id,
                'service_type_id' => $definition->service_type_id,
                'checklist' => [],
                'dependencies' => [],
                'scenario_context' => [],
                'issues' => $serviceHealth['focus_issues'] ?? [],
                'summary' => ['errors' => 1, 'warnings' => 0, 'passed_checks' => 0, 'skipped_checks' => 0],
            ], [
                'ready_for_activation' => $serviceHealth['ready_for_activation'],
                'service_health' => $serviceHealth,
            ]),
            'message' => 'Calculation-definition readiness retrieved successfully',
        ]);
    }

    /**
     * Update the specified calculation definition.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'service_type_id' => 'required|uuid|exists:service_types,id',
            'formula' => 'required|string|max:2000',
            'variables' => 'nullable|array',
            'variables.*.name' => ['required', 'string', 'max:255', 'distinct', 'regex:/^[A-Za-z_][A-Za-z0-9_]*$/'],
            'variables.*.type' => ['required', Rule::in(array_keys(VehiclePricingCalculationDefinition::getSupportedVariableTypes()))],
            'variables.*.default_value' => 'nullable',
            'variables.*.is_required' => 'nullable|boolean',
            'variables.*.description' => 'nullable|string',
            'conditions' => 'nullable|array',
            'conditions.*.field' => ['required', 'string', 'regex:/^[A-Za-z_][A-Za-z0-9_]*$/'],
            'conditions.*.operator' => ['required', Rule::in(array_merge(
                array_keys(VehiclePricingCalculationDefinition::getSupportedConditionOperators()),
                ['=', '!=', '>', '<', '>=', '<=']
            ))],
            'conditions.*.value' => 'required',
            'status' => 'in:active,inactive,draft',
            'owner_type' => 'nullable|string|in:corporate',
            'owner_id' => 'nullable|uuid|exists:corporates,id|required_with:owner_type',
            'priority' => 'nullable|integer|min:0',
        ]);

        $validator->after(function ($validator) use ($request) {
            $formula = $request->input('formula');
            $variables = $request->input('variables', []);
            if (!is_string($formula) || !is_array($variables)) {
                return;
            }

            foreach (VehiclePricingCalculationDefinition::validateFormulaConfiguration(
                $formula,
                $variables
            ) as $error) {
                $validator->errors()->add('formula', $error);
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        if (!$this->serviceTypeIsAvailableInScope($request, (string) $request->service_type_id)) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => [
                    'service_type_id' => ['The selected service type is not available in this pricing context.'],
                ],
            ], 422);
        }

        try {
            $definition = VehiclePricingCalculationDefinition::findOrFail($id);
            $status = (string) $request->get('status', $definition->status);
            $activationHealth = null;
            if ($status === 'active') {
                $activationHealth = $this->configurationHealth->prospectiveDefinitionHealth([
                    'id' => $definition->id,
                    'name' => $request->name,
                    'description' => $request->description,
                    'service_type_id' => $request->service_type_id,
                    'formula' => $request->formula,
                    'variables' => $request->variables ?? [],
                    'conditions' => $request->conditions ?? [],
                    'status' => 'active',
                    'priority' => $request->input('priority', $definition->priority ?? 0),
                ], $definition);
                if (!$activationHealth['ready_for_activation']) {
                    return $this->activationBlockedResponse($activationHealth);
                }
            }

            $definition->name = $request->name;
            $definition->description = $request->description;
            $definition->service_type_id = $request->service_type_id;
            $definition->formula = $request->formula;
            $definition->variables = $request->variables ?? [];
            $definition->conditions = $request->conditions ?? [];
            $definition->status = $status;
            $definition->owner_type = null;
            $definition->owner_id = null;
            $definition->priority = $request->input('priority', $definition->priority ?? 0);
            $definition->updated_by = Auth::id();
            $definition->save();

            $definition->load(['serviceType', 'creator']);

            return response()->json([
                'message' => 'Calculation definition updated successfully',
                'data' => array_merge($definition->toArray(), [
                    'calculation_example' => $definition->getCalculationExample(),
                ]),
                'configuration_health' => $activationHealth,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update calculation definition',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified calculation definition.
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $definition = VehiclePricingCalculationDefinition::findOrFail($id);
            $definition->delete();

            return response()->json([
                'message' => 'Calculation definition deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to delete calculation definition',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Test a calculation formula with provided inputs.
     */
    public function testCalculation(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'formula' => 'required|string',
            'variables' => 'nullable|array',
            'conditions' => 'nullable|array',
            'test_inputs' => 'required|array',
            'test_inputs.vehicle_group_id' => 'nullable|uuid',
            'test_inputs.service_type_id' => 'nullable|uuid',
            'test_inputs.duration_hours' => 'nullable|numeric|min:0',
            'test_inputs.duration_minutes' => 'nullable|numeric|min:0',
            'test_inputs.extra_minutes' => 'nullable|numeric|min:0',
            'test_inputs.waiting_minutes' => 'nullable|numeric|min:0',
            'test_inputs.recovery_minutes' => 'nullable|numeric|min:0',
            'test_inputs.overtime_minutes' => 'nullable|numeric|min:0',
            'test_inputs.slab_rate' => 'nullable|numeric|min:0',
            'test_inputs.rate_type' => 'nullable|in:per_hour,per_day,flat_rate,per_km',
            'context' => 'nullable|string|in:public,portal,corporate',
            'owner_type' => 'nullable|required_if:context,corporate|string|in:corporate',
            'owner_id' => 'nullable|required_if:context,corporate|uuid|exists:corporates,id|required_with:owner_type',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Create a temporary definition for testing
            $tempDefinition = new VehiclePricingCalculationDefinition();
            $tempDefinition->formula = $request->formula;
            $tempDefinition->variables = $request->variables ?? [];
            $tempDefinition->conditions = $request->conditions ?? [];

            $testInputs = $this->normalizeDurationInputs($request->test_inputs);
            $testInputs['owner_type'] = $request->input('owner_type');
            $testInputs['owner_id'] = $request->filled('owner_type')
                ? $request->input('owner_id')
                : null;
            $tempDefinition->service_type_id = $testInputs['service_type_id'] ?? null;
            $testInputs = $this->prepareTrustedCalculationInputs(
                [$tempDefinition],
                $testInputs,
                $testInputs['service_type_id'] ?? null,
                $testInputs['vehicle_group_id'] ?? null,
                $testInputs['owner_type'],
                $testInputs['owner_id']
            );
    
            $orchestration = app(PricingDefinitionOrchestrator::class)->resolve(
                [$tempDefinition],
                $testInputs
            );
            $result = $orchestration['result'];
            if (!$orchestration['matched'] || !$result) {
                $failure = $orchestration['candidate_failures'][0] ?? [];
                $reason = ($failure['reason'] ?? null) === 'missing_required_variables'
                    ? 'Missing required inputs: ' . implode(', ', $failure['missing_variables'] ?? [])
                    : ($failure['message'] ?? 'The configured conditions do not match these test inputs.');
                throw new \InvalidArgumentException($reason);
            }

            return response()->json([
                'result' => $result,
                'formula' => $request->formula,
                'inputs' => $testInputs,
                'breakdown' => $this->getCalculationBreakdown($tempDefinition, $result)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Calculation test failed',
                'error' => $e->getMessage(),
                'result' => null
            ], 400);
        }
    }

    /**
     * Get common rates for a vehicle group and service type.
     */
    private function getCommonRates(
        string $vehicleGroupId,
        string $serviceTypeId,
        ?string $ownerType = null,
        ?string $ownerId = null
    ): array
    {
        try {
            $commonRates = [];
            
            // Get vehicle group specific common rate pricing
            $vehicleGroupCommonRates = VehicleGroupCommonRatePricing::where('vehicle_group_id', $vehicleGroupId)
                ->where('is_active', true)
                ->whereHas('commonRateDefinition', function ($query) use ($serviceTypeId) {
                    $query->where('is_active', true)
                          ->where(function ($serviceQuery) use ($serviceTypeId) {
                              $serviceQuery->where('service_type_id', $serviceTypeId)
                                  ->orWhereNull('service_type_id');
                          });
                })
                ->tap(fn ($query) => $this->applyOwnerScope($query, $ownerType, $ownerId))
                ->tap(fn ($query) => $this->applyOwnerPriorityOrder($query, $ownerType, $ownerId))
                ->orderByDesc('priority')
                ->with('commonRateDefinition')
                ->get()
                ->sort(function ($left, $right) use ($serviceTypeId, $ownerType, $ownerId) {
                    $rank = static function ($pricing) use ($serviceTypeId, $ownerType, $ownerId): array {
                        $ownerExact = $ownerType && $ownerId
                            && $pricing->owner_type === $ownerType
                            && (string) $pricing->owner_id === (string) $ownerId;

                        return [
                            (string) $pricing->commonRateDefinition?->service_type_id === (string) $serviceTypeId ? 0 : 1,
                            $ownerExact ? 0 : 1,
                            -((int) $pricing->priority),
                            -((int) ($pricing->commonRateDefinition?->priority ?? 0)),
                            (string) $pricing->id,
                        ];
                    };

                    return $rank($left) <=> $rank($right);
                });

            foreach ($vehicleGroupCommonRates as $commonRate) {
                // Use a clean variable name based on the rate definition code
                $rateName = $commonRate->commonRateDefinition->code ?? 
                           strtolower(str_replace(' ', '_', $commonRate->commonRateDefinition->name));
                $commonRates[$rateName] ??= $commonRate->value;
            }

            return $commonRates;
        } catch (\Exception $e) {
            Log::error("Error getting common rates: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get calculation breakdown for debugging.
     */
    private function getCalculationBreakdown(VehiclePricingCalculationDefinition $definition, array $result): array
    {
        $breakdown = [];
        $resolvedVariables = is_array($result['resolved_variables'] ?? null)
            ? $result['resolved_variables']
            : [];
        
        foreach ($definition->variables ?? [] as $variable) {
            $varName = $variable['name'];
            $value = $resolvedVariables[$varName] ?? null;
            
            $breakdown[$varName] = [
                'value' => $value,
                'type' => $variable['type'] ?? 'number',
                'description' => $variable['description'] ?? $varName
            ];
        }
        
        return $breakdown;
    }

    private function normalizeDurationInputs(array $inputs): array
    {
        foreach (['duration', 'extra', 'waiting', 'recovery', 'overtime'] as $prefix) {
            $hoursKey = "{$prefix}_hours";
            $minutesKey = "{$prefix}_minutes";

            if (array_key_exists($minutesKey, $inputs) && !array_key_exists($hoursKey, $inputs)) {
                $inputs[$hoursKey] = (float) $inputs[$minutesKey] / 60;
            } elseif (array_key_exists($hoursKey, $inputs) && !array_key_exists($minutesKey, $inputs)) {
                $inputs[$minutesKey] = (float) $inputs[$hoursKey] * 60;
            }
        }

        if (array_key_exists('hours', $inputs) && !array_key_exists('duration_hours', $inputs)) {
            $inputs['duration_hours'] = (float) $inputs['hours'];
            $inputs['duration_minutes'] ??= (float) $inputs['hours'] * 60;
        }

        if (!array_key_exists('duration_days', $inputs) && isset($inputs['duration_minutes'])) {
            $minutes = (float) $inputs['duration_minutes'];
            $inputs['duration_days'] = $minutes >= 1440 ? (int) ceil($minutes / 1440) : 0;
        }

        return $inputs;
    }

    /**
     * Remove caller-supplied rates and reload them from Pricing Management so
     * every tester/preview endpoint uses the same trusted inputs as runtime.
     *
     * @param iterable<VehiclePricingCalculationDefinition> $definitions
     */
    private function prepareTrustedCalculationInputs(
        iterable $definitions,
        array $inputs,
        ?string $serviceTypeId,
        ?string $vehicleGroupId,
        ?string $ownerType,
        ?string $ownerId
    ): array {
        $inputs = $this->normalizeDurationInputs($inputs);
        $inputs['owner_type'] = $ownerType;
        $inputs['owner_id'] = $ownerType && $ownerId ? $ownerId : null;
        if ($serviceTypeId) {
            $inputs['service_type_id'] = $serviceTypeId;
        }
        if ($vehicleGroupId) {
            $inputs['vehicle_group_id'] = $vehicleGroupId;
        }

        unset($inputs['slab_rate'], $inputs['rate_type']);
        $commonRateNames = collect($definitions)
            ->flatMap(fn ($definition) => collect($definition->variables ?? [])
                ->filter(fn ($variable) => ($variable['type'] ?? null) === 'common_rate')
                ->pluck('name'))
            ->filter()
            ->unique()
            ->values();
        foreach ($commonRateNames as $name) {
            unset($inputs[$name]);
            if (str_starts_with((string) $name, 'common_rate_')) {
                unset($inputs[substr((string) $name, 12)]);
            }
        }

        if ($serviceTypeId && $vehicleGroupId && $commonRateNames->isNotEmpty()) {
            $inputs = array_merge($inputs, $this->getCommonRates(
                $vehicleGroupId,
                $serviceTypeId,
                $ownerType,
                $ownerId
            ));
        }

        return $inputs;
    }

    private function applyOwnerScope($query, ?string $ownerType, ?string $ownerId): void
    {
        if ($ownerType && $ownerId) {
            $query->where(function ($scope) use ($ownerType, $ownerId) {
                $scope->where(function ($owned) use ($ownerType, $ownerId) {
                    $owned->where('owner_type', $ownerType)->where('owner_id', $ownerId);
                })->orWhereNull('owner_type');
            });

            return;
        }

        $query->whereNull('owner_type')->whereNull('owner_id');
    }

    private function applyOwnerPriorityOrder($query, ?string $ownerType, ?string $ownerId): void
    {
        if ($ownerType && $ownerId) {
            $query->orderByRaw(
                'CASE WHEN owner_type = ? AND owner_id = ? THEN 0 ELSE 1 END',
                [$ownerType, $ownerId]
            );
        }
    }

    /**
     * Get available service types for dropdown.
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
                    ->where('service_types.is_active', true)
                    ->select('service_types.id', 'service_types.name', 'service_types.description', 'service_types.code', 'service_types.context', 'service_types.owner_type', 'service_types.owner_id')
                    ->orderBy('service_types.name')
                    ->get();
            } else {
                $serviceTypes = ServiceType::forContext($context, '', '')
                    ->where('is_active', true)
                    ->select('id', 'name', 'description', 'code', 'context', 'owner_type', 'owner_id')
                    ->orderBy('name')
                    ->get();
            }

            return response()->json([
                'data' => $serviceTypes
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to fetch service types',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function serviceTypeIsAvailableInScope(Request $request, string $serviceTypeId): bool
    {
        $context = (string) $request->input('context', 'public');

        return ServiceType::forContext($context, '', '')
            ->whereKey($serviceTypeId)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * Get vehicle groups available to the formula tester.
     */
    public function getVehicleGroups(Request $request): JsonResponse
    {
        $context = (string) $request->input('context', 'public');
        $ownerId = (string) $request->input('owner_id', '');

        $vehicleGroups = VehicleGroup::query()
            ->when($context === 'corporate' && $ownerId !== '', function ($query) use ($ownerId) {
                $query->whereIn('id', function ($subQuery) use ($ownerId) {
                    $subQuery->select('vehicle_group_id')
                        ->from('corporate_vehicle_groups')
                        ->where('corporate_id', $ownerId);
                });
            })
            ->select(['id', 'name', 'description'])
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $vehicleGroups]);
    }

    /**
     * Bulk update status of multiple calculation definitions.
     */
    public function bulkUpdateStatus(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ids' => 'required|array|min:1',
            'ids.*' => 'uuid|exists:vehicle_pricing_calculation_definitions,id',
            'status' => 'required|in:active,inactive,draft',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $activationHealth = [];
            if ($request->status === 'active') {
                $definitions = VehiclePricingCalculationDefinition::whereIn('id', $request->ids)->get();
                foreach ($definitions->groupBy('service_type_id') as $serviceTypeId => $serviceDefinitions) {
                    $ids = $serviceDefinitions->pluck('id')->map(fn ($id) => (string) $id)->values()->all();
                    $health = $this->configurationHealth->prospectiveServiceHealth(
                        (string) $serviceTypeId,
                        $serviceDefinitions,
                        $ids,
                        $ids
                    );
                    $activationHealth[(string) $serviceTypeId] = $health;

                    if (!$health['ready_for_activation']) {
                        return $this->activationBlockedResponse($health);
                    }
                }
            }

            $updatedCount = VehiclePricingCalculationDefinition::whereIn('id', $request->ids)
                ->update([
                    'status' => $request->status,
                    'updated_by' => Auth::id(),
                    'updated_at' => now()
                ]);

            return response()->json([
                'message' => "Successfully updated {$updatedCount} calculation definitions",
                'updated_count' => $updatedCount,
                'configuration_health' => $activationHealth,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update calculation definitions',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Test a specific calculation definition with comprehensive breakdown.
     */
    public function testDefinitionCalculation(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'definition_id' => 'required|uuid|exists:vehicle_pricing_calculation_definitions,id',
            'vehicle_group_id' => 'required|uuid',
            'duration_hours' => 'nullable|required_without:duration_minutes|numeric|min:0.0001',
            'duration_minutes' => 'nullable|required_without:duration_hours|numeric|min:1',
            'distance_km' => 'nullable|numeric|min:0',
            'additional_inputs' => 'nullable|array',
            'context' => 'nullable|string|in:public,portal,corporate',
            'owner_type' => 'nullable|required_if:context,corporate|string|in:corporate',
            'owner_id' => 'nullable|required_if:context,corporate|uuid|exists:corporates,id|required_with:owner_type',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $definition = VehiclePricingCalculationDefinition::findOrFail($request->definition_id);
            
            // Prepare inputs for calculation
            $durationHours = $request->filled('duration_hours')
                ? (float) $request->duration_hours
                : (float) $request->duration_minutes / 60;
            $durationMinutes = $request->filled('duration_minutes')
                ? (float) $request->duration_minutes
                : $durationHours * 60;

            $inputs = array_merge([
                'duration_hours' => $durationHours,
                'duration_minutes' => $durationMinutes,
                'hours' => $durationHours, // Alias for backward compatibility
            ], $request->additional_inputs ?? []);
            if ($request->filled('distance_km')) {
                $inputs += [
                    'distance_km' => (float) $request->distance_km,
                    'distance' => (float) $request->distance_km,
                    'journey_distance' => (float) $request->distance_km,
                    'total_distance' => (float) $request->distance_km,
                ];
            }
            $inputs = $this->prepareTrustedCalculationInputs(
                [$definition],
                $inputs,
                (string) $definition->service_type_id,
                (string) $request->vehicle_group_id,
                $request->input('owner_type'),
                $request->filled('owner_type') ? $request->input('owner_id') : null
            );

            $orchestration = app(PricingDefinitionOrchestrator::class)->resolve([$definition], $inputs);
            $calculationResult = $orchestration['result'];
            if (!$orchestration['matched'] || !$calculationResult) {
                $failure = $orchestration['candidate_failures'][0] ?? [];
                return response()->json([
                    'success' => false,
                    'message' => ($failure['reason'] ?? null) === 'missing_required_variables'
                        ? 'Missing required inputs: ' . implode(', ', $failure['missing_variables'] ?? [])
                        : ($failure['message'] ?? 'The calculation definition conditions do not match these test inputs.'),
                    'data' => [
                        'calculation_result' => null,
                        'candidate_failures' => $orchestration['candidate_failures'],
                    ],
                ], 422);
            }
            $totalPrice = (float) ($calculationResult['total_amount'] ?? 0);

            return response()->json([
                'success' => true,
                'data' => [
                    'definition' => [
                        'id' => $definition->id,
                        'name' => $definition->name,
                        'description' => $definition->description,
                        'formula' => $definition->formula,
                        'formula_description' => $definition->getFormulaDescription(),
                        'variables' => $definition->variables,
                        'conditions' => $definition->conditions,
                    ],
                    'inputs' => $inputs,
                    'breakdown' => $calculationResult,
                    'calculation_result' => $calculationResult,
                    'total_price' => $totalPrice,
                    'currency' => 'LKR',
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Definition calculation test error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to test calculation: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get available slab rates for a vehicle group and service type.
     */
    public function getSlabRates(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'vehicle_group_id' => 'required|uuid',
            'service_type_id' => 'required|uuid',
            'context' => 'nullable|string|in:public,portal,corporate',
            'owner_type' => 'nullable|required_if:context,corporate|string|in:corporate',
            'owner_id' => 'nullable|required_if:context,corporate|uuid|exists:corporates,id|required_with:owner_type',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $ownerType = $request->input('owner_type');
            $ownerId = $request->filled('owner_type') ? $request->input('owner_id') : null;

            // Get all slab definitions for the service type
            $slabDefinitions = VehiclePricingSlabDefinition::where('service_type_id', $request->service_type_id)
                ->where('is_active', true)
                ->orderByRaw("CASE WHEN type = 'minutes' THEN 0 ELSE 1 END")
                ->orderBy('min_minutes')
                ->orderBy('min_hours')
                ->get();

            $slabRates = [];
            foreach ($slabDefinitions as $slabDefinition) {
                // Get pricing for this vehicle group and slab
                $vehicleGroupPricing = VehicleGroupPricing::where('vehicle_group_id', $request->vehicle_group_id)
                    ->where('slab_definition_id', $slabDefinition->id)
                    ->where('is_active', true)
                    ->forOwner($ownerType, $ownerId)
                    ->tap(fn ($query) => $this->applyOwnerPriorityOrder($query, $ownerType, $ownerId))
                    ->orderByDesc('priority')
                    ->first();

                $slabRates[] = [
                    'slab_definition' => [
                        'id' => $slabDefinition->id,
                        'name' => $slabDefinition->name,
                        'min_hours' => $slabDefinition->min_hours,
                        'max_hours' => $slabDefinition->max_hours,
                        'min_minutes' => $slabDefinition->min_minutes,
                        'max_minutes' => $slabDefinition->max_minutes,
                        'min_days' => $slabDefinition->min_days,
                        'max_days' => $slabDefinition->max_days,
                        'type' => $slabDefinition->type,
                        'duration_description' => $slabDefinition->duration_description,
                    ],
                    'pricing' => $vehicleGroupPricing ? [
                        'id' => $vehicleGroupPricing->id,
                        'rate' => (float) $vehicleGroupPricing->rate,
                        'rate_type' => $vehicleGroupPricing->rate_type,
                        'rate_description' => $vehicleGroupPricing->rate_description,
                        'minimum_charge' => $vehicleGroupPricing->minimum_charge ? (float) $vehicleGroupPricing->minimum_charge : null,
                        'includes_fuel' => $vehicleGroupPricing->includes_fuel,
                        'includes_driver' => $vehicleGroupPricing->includes_driver,
                    ] : null,
                ];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'vehicle_group_id' => $request->vehicle_group_id,
                    'service_type_id' => $request->service_type_id,
                    'slab_rates' => $slabRates,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Get slab rates error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to get slab rates: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Calculate price dynamically using calculation definitions.
     */
    public function calculatePrice(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'service_type_id' => 'required|uuid|exists:service_types,id',
            'vehicle_group_id' => 'required|uuid',
            'inputs' => 'required|array',
            'calculation_definition_id' => 'nullable|uuid|exists:vehicle_pricing_calculation_definitions,id',
            'return_breakdown' => 'boolean',
            'context' => 'nullable|string|in:public,portal,corporate',
            'owner_type' => 'nullable|required_if:context,corporate|string|in:corporate',
            'owner_id' => 'nullable|required_if:context,corporate|uuid|exists:corporates,id|required_with:owner_type',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $ownerType = $request->input('owner_type');
            $ownerId = $request->filled('owner_type') ? $request->input('owner_id') : null;
            $definitionQuery = VehiclePricingCalculationDefinition::query()
                ->where('service_type_id', $request->service_type_id)
                ->when(
                    $request->filled('calculation_definition_id'),
                    fn ($query) => $query->whereKey($request->input('calculation_definition_id')),
                    fn ($query) => $query->where('status', 'active')
                )
                ->orderByDesc('priority')
                ->orderByDesc('created_at');
            $calculationDefinitions = $definitionQuery->get();

            if ($calculationDefinitions->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No active calculation definition found for this service type',
                ], 404);
            }

            // Merge inputs with essential calculation parameters
            $calculationInputs = $this->prepareTrustedCalculationInputs(
                $calculationDefinitions,
                $request->inputs,
                (string) $request->service_type_id,
                (string) $request->vehicle_group_id,
                $ownerType,
                $ownerId
            );

            $orchestration = app(PricingDefinitionOrchestrator::class)->resolve(
                $calculationDefinitions,
                $calculationInputs
            );
            $calculationDefinition = $orchestration['definition'];
            $calculationResult = $orchestration['result'];
            $candidateFailures = $orchestration['candidate_failures'];

            if (!$calculationDefinition || !$calculationResult) {
                return response()->json([
                    'success' => false,
                    'message' => 'No active calculation definition matched these scenario inputs.',
                    'candidate_failures' => $candidateFailures,
                ], 422);
            }

            $calculatedPrice = (float) ($calculationResult['total_amount']
                ?? $calculationResult['total']
                ?? $calculationResult['final_amount']
                ?? 0);

            $response = [
                'success' => true,
                'data' => [
                    'calculation_definition_id' => $calculationDefinition->id,
                    'calculation_definition_name' => $calculationDefinition->name,
                    'formula' => $calculationDefinition->formula,
                    'total_price' => $calculatedPrice,
                    'calculation_result' => $calculationResult,
                    'currency' => config('booking.base_currency', 'LKR'),
                ]
            ];

            // Include breakdown if requested
            if ($request->boolean('return_breakdown', false)) {
                $response['data']['breakdown'] = $calculationResult;
            }

            return response()->json($response);

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid input parameters',
                'error' => $e->getMessage(),
            ], 400);

        } catch (\Exception $e) {
            Log::error('Price calculation error: ' . $e->getMessage(), [
                'service_type_id' => $request->service_type_id,
                'vehicle_group_id' => $request->vehicle_group_id,
                'inputs' => $request->inputs
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Price calculation failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get all available calculation definitions for a service type.
     */
    public function getAvailableCalculations(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'service_type_id' => 'required|uuid|exists:service_types,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $definitions = VehiclePricingCalculationDefinition::where('service_type_id', $request->service_type_id)
                ->where('status', 'active')
                ->with('serviceType')
                ->orderBy('name')
                ->get()
                ->map(function ($definition) {
                    return [
                        'id' => $definition->id,
                        'name' => $definition->name,
                        'description' => $definition->description,
                        'formula' => $definition->formula,
                        'formula_description' => $definition->getFormulaDescription(),
                        'variables' => $definition->variables,
                        'conditions' => $definition->conditions,
                        'is_formula_valid' => $definition->validateFormula(),
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $definitions,
            ]);

        } catch (\Exception $e) {
            Log::error('Get available calculations error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to get available calculations: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Test a calculation definition with sample inputs.
     */
    public function testFormula(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'calculation_definition_id' => 'required|uuid|exists:vehicle_pricing_calculation_definitions,id',
            'test_inputs' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $definition = VehiclePricingCalculationDefinition::find($request->calculation_definition_id);

            $testInputs = $request->input('test_inputs');

            // Validate the formula first
            $isValid = $definition->validateFormula();

            $response = [
                'success' => true,
                'data' => [
                    'definition_id' => $definition->id,
                    'definition_name' => $definition->name,
                    'formula' => $definition->formula,
                    'is_valid' => $isValid,
                    'test_inputs' => $testInputs,
                    'configuration_example' => $definition->getCalculationExample(),
                ]
            ];

            if ($isValid && $testInputs !== null) {
                try {
                    $testInputs = $this->prepareTrustedCalculationInputs(
                        [$definition],
                        $testInputs,
                        (string) $definition->service_type_id,
                        isset($testInputs['vehicle_group_id'])
                            ? (string) $testInputs['vehicle_group_id']
                            : null,
                        null,
                        null
                    );
                    $response['data']['test_inputs'] = $testInputs;
                    $orchestration = app(PricingDefinitionOrchestrator::class)->resolve(
                        [$definition],
                        $testInputs
                    );
                    $calculationResult = $orchestration['result'];
                    if (!$orchestration['matched'] || !$calculationResult) {
                        $failure = $orchestration['candidate_failures'][0] ?? [];
                        $reason = ($failure['reason'] ?? null) === 'missing_required_variables'
                            ? 'Missing required inputs: ' . implode(', ', $failure['missing_variables'] ?? [])
                            : ($failure['message'] ?? 'The definition did not match the supplied scenario.');
                        throw new \InvalidArgumentException($reason);
                    }

                    $response['data']['test_result'] = [
                        'calculated_price' => (float) ($calculationResult['total_amount'] ?? 0),
                        'breakdown' => $calculationResult['breakdown'] ?? [],
                        'calculation' => $calculationResult,
                        'candidate_failures' => $orchestration['candidate_failures'],
                    ];
                } catch (\Exception $e) {
                    $response['data']['test_result'] = [
                        'error' => $e->getMessage(),
                    ];
                }
            } elseif ($isValid) {
                $response['data']['test_result'] = [
                    'status' => 'example_only',
                    'message' => 'Provide test_inputs to run this definition against Pricing Management values.',
                ];
            }

            return response()->json($response);

        } catch (\Throwable $e) {
            Log::error('Test formula error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to test formula: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get available variables for a specific service type
     * Includes core system variables and service-specific common rate variables
     */
    public function getAvailableVariables(string $serviceTypeId): JsonResponse
    {
        try {
            // Validate service type exists
            $serviceType = ServiceType::findOrFail($serviceTypeId);
            
            // Core system variables (always available)
            $coreVariables = [
                ['name' => 'slab_rate', 'type' => 'slab_rate', 'description' => 'Base rate from slab definition based on duration/package', 'is_required' => true, 'category' => 'base'],
                ['name' => 'duration_hours', 'type' => 'duration', 'description' => 'Service duration in hours', 'is_required' => false, 'default_value' => 0, 'category' => 'duration'],
                ['name' => 'duration_minutes', 'type' => 'duration', 'description' => 'Service duration in minutes', 'is_required' => false, 'default_value' => 0, 'category' => 'duration'],
                ['name' => 'duration_days', 'type' => 'duration', 'description' => 'Service duration in days', 'is_required' => false, 'default_value' => 0, 'category' => 'duration'],
                ['name' => 'number_of_days', 'type' => 'duration', 'description' => 'Billable whole-day count derived from exact booking minutes', 'is_required' => false, 'default_value' => 1, 'category' => 'duration'],
                ['name' => 'total_distance', 'type' => 'distance', 'description' => 'Total service distance in KM', 'is_required' => false, 'default_value' => 0, 'category' => 'distance'],
                ['name' => 'journey_distance', 'type' => 'distance', 'description' => 'Contractual journey distance in KM used by pricing', 'is_required' => false, 'default_value' => 0, 'category' => 'distance'],
                ['name' => 'actual_distance', 'type' => 'distance', 'description' => 'Measured final journey distance in KM when available', 'is_required' => false, 'default_value' => 0, 'category' => 'distance'],
                ['name' => 'package_included_km', 'type' => 'distance', 'description' => 'Distance included by the selected service package', 'is_required' => false, 'default_value' => 0, 'category' => 'distance'],
                ['name' => 'delivery_distance', 'type' => 'distance', 'description' => 'Vehicle delivery distance in KM', 'is_required' => false, 'default_value' => 0, 'category' => 'distance'],
                ['name' => 'pickup_distance', 'type' => 'distance', 'description' => 'Vehicle pickup distance in KM (return distance)', 'is_required' => false, 'default_value' => 0, 'category' => 'distance'],
                ['name' => 'extra_km', 'type' => 'distance', 'description' => 'Extra KM beyond package/daily limit', 'is_required' => false, 'default_value' => 0, 'category' => 'distance'],
                ['name' => 'extra_hours', 'type' => 'duration', 'description' => 'Extra hours beyond package limit', 'is_required' => false, 'default_value' => 0, 'category' => 'duration'],
                ['name' => 'extra_minutes', 'type' => 'duration', 'description' => 'Extra minutes beyond package limit', 'is_required' => false, 'default_value' => 0, 'category' => 'duration']
            ];

            // Special variables (context-dependent)
            $specialVariables = [
                ['name' => 'discount_percentage', 'type' => 'fixed_value', 'description' => 'Discount percentage (0.1 = 10%)', 'is_required' => false, 'default_value' => 0, 'category' => 'adjustment'],
                ['name' => 'additional_stops', 'type' => 'fixed_value', 'description' => 'Number of additional stops', 'is_required' => false, 'default_value' => 0, 'category' => 'service'],
                ['name' => 'waiting_hours', 'type' => 'duration', 'description' => 'Additional waiting time in hours', 'is_required' => false, 'default_value' => 0, 'category' => 'duration'],
                ['name' => 'waiting_minutes', 'type' => 'duration', 'description' => 'Additional waiting time in minutes', 'is_required' => false, 'default_value' => 0, 'category' => 'duration'],
                ['name' => 'recovery_hours', 'type' => 'duration', 'description' => 'Hours spent on recovery operation', 'is_required' => false, 'default_value' => 0, 'category' => 'duration'],
                ['name' => 'recovery_minutes', 'type' => 'duration', 'description' => 'Minutes spent on recovery operation', 'is_required' => false, 'default_value' => 0, 'category' => 'duration'],
                ['name' => 'stops', 'type' => 'fixed_value', 'description' => 'Number of stops in transfer', 'is_required' => false, 'default_value' => 0, 'category' => 'service'],
                ['name' => 'overtime_hours', 'type' => 'duration', 'description' => 'Overtime hours beyond contract', 'is_required' => false, 'default_value' => 0, 'category' => 'duration'],
                ['name' => 'overtime_minutes', 'type' => 'duration', 'description' => 'Overtime minutes beyond contract', 'is_required' => false, 'default_value' => 0, 'category' => 'duration'],
                ['name' => 'manual_additional_charge', 'type' => 'fixed_value', 'description' => 'Approved manual charge included in final pricing', 'is_required' => false, 'default_value' => 0, 'category' => 'adjustment'],
                ['name' => 'late_return_fee', 'type' => 'fixed_value', 'description' => 'Late-return charge captured by the return workflow', 'is_required' => false, 'default_value' => 0, 'category' => 'adjustment'],
                ['name' => 'is_weekend', 'type' => 'number', 'description' => 'Weekend flag supplied as 1 for weekend and 0 otherwise', 'is_required' => false, 'default_value' => 0, 'category' => 'context'],
                ['name' => 'is_holiday', 'type' => 'number', 'description' => 'Holiday flag supplied as 1 for a holiday and 0 otherwise', 'is_required' => false, 'default_value' => 0, 'category' => 'context'],
                ['name' => 'month', 'type' => 'number', 'description' => 'Booking start month number from 1 to 12', 'is_required' => false, 'default_value' => 1, 'category' => 'context'],
                ['name' => 'day_of_week', 'type' => 'number', 'description' => 'Booking start weekday number where Sunday is 0', 'is_required' => false, 'default_value' => 0, 'category' => 'context'],
                ['name' => 'from_date', 'type' => 'fixed_value', 'description' => 'Booking start date for condition matching only', 'is_required' => false, 'default_value' => null, 'category' => 'context', 'condition_only' => true, 'formula_allowed' => false],
                ['name' => 'from_time', 'type' => 'fixed_value', 'description' => 'Booking start time for condition matching only', 'is_required' => false, 'default_value' => null, 'category' => 'context', 'condition_only' => true, 'formula_allowed' => false],
                ['name' => 'customer_type', 'type' => 'fixed_value', 'description' => 'Customer segment for condition matching only', 'is_required' => false, 'default_value' => null, 'category' => 'context', 'condition_only' => true, 'formula_allowed' => false],
                ['name' => 'booking_type', 'type' => 'fixed_value', 'description' => 'Booking mode (self_drive or with_driver) for condition matching only', 'is_required' => false, 'default_value' => null, 'category' => 'context', 'condition_only' => true, 'formula_allowed' => false],
                ['name' => 'is_self_driven', 'type' => 'number', 'description' => 'Self-drive flag supplied as 1 for self-drive and 0 for chauffeur bookings', 'is_required' => false, 'default_value' => 0, 'category' => 'context']
            ];

            // Get common rate variables for this service type
            $commonRates = VehiclePricingCommonRateDefinition::where(function ($query) use ($serviceTypeId) {
                    $query->where('service_type_id', $serviceTypeId)
                        ->orWhereNull('service_type_id');
                })
                ->where('is_active', true)
                ->orderByDesc('priority')
                ->get()
                ->sortByDesc(fn ($rate) => (string) $rate->service_type_id === (string) $serviceTypeId)
                ->unique('code')
                ->values();

                Log::info('Common Rates Found: ', ['count' => $commonRates->count()]);
                Log::info('Common Rates Details: ', $commonRates->toArray());
            $commonRateVariables = $commonRates->map(function ($rate) {
                return [
                    'name' => $rate->code,
                    'type' => 'common_rate',
                    'description' => $rate->description ?: $rate->name,
                    'is_required' => true,
                    'default_value' => null,
                    'category' => $this->categorizeCommonRate($rate->code),
                    'source_id' => $rate->id,
                    'common_rate_code' => $rate->code
                ];
            });

            $allVariables = array_merge($coreVariables, $specialVariables, $commonRateVariables->toArray());

            return response()->json([
                'success' => true,
                'data' => $allVariables
            ]);

        } catch (\Exception $e) {
            Log::error('Get available variables error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to get available variables: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Categorize common rate by its code for better organization
     */
    private function categorizeCommonRate(string $code): string
    {
        $categories = [
            'vehicle_delivery_rate_per_km' => 'logistics',
            'vehicle_pickup_rate_per_km' => 'logistics',
            'extra_km_rate' => 'overage',
            'extra_hour_rate' => 'overage',
            'service_rate_per_km' => 'service',
            'stop_charge' => 'service',
            'waiting_charge_per_hour' => 'service',
            'waiting_charge_per_minute' => 'service',
            'decoration_charge' => 'special',
            'emergency_base_rate' => 'special',
            'hourly_rate' => 'service',
            'overtime_rate_per_hour' => 'overage',
            'overtime_rate_per_minute' => 'overage',
            'extra_minute_rate' => 'overage',
        ];

        return $categories[$code] ?? 'rate';
    }

    /**
     * Get supported variable types and operators for the frontend.
     */
    public function getMetadata(): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data' => [
                    'variable_types' => VehiclePricingCalculationDefinition::getSupportedVariableTypes(),
                    'condition_operators' => VehiclePricingCalculationDefinition::getSupportedConditionOperators(),
                    'sample_formulas' => [
                        'Simple slab rate' => 'slab_rate',
                        'Slab rate with pickup' => 'slab_rate + (pickup_distance * pickup_rate_per_km)',
                        'Full calculation' => 'slab_rate + (pickup_distance * pickup_rate_per_km) + (return_distance * return_rate_per_km)',
                        'With discount' => 'slab_rate * (1 - discount_percentage)',
                        'Hourly with distance' => '(duration_hours * hourly_rate) + (total_distance * distance_rate)',
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Get metadata error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to get metadata: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function activationBlockedResponse(array $health): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Calculation definition cannot be activated until its configuration health errors are resolved.',
            'errors' => [
                'configuration' => collect($health['focus_issues'] ?? $health['issues'] ?? [])
                    ->where('severity', 'error')
                    ->pluck('message')
                    ->values()
                    ->all(),
            ],
            'health' => $health,
        ], 422);
    }
}
