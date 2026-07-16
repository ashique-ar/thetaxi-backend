<?php

namespace App\Services\Pricing;

use App\Models\Vehicle\VehiclePricing\VehicleGroupCommonRatePricing;
use App\Models\Vehicle\VehiclePricing\VehicleGroupPricing;
use App\Models\Vehicle\VehiclePricing\VehiclePricingCalculationDefinition;
use App\Models\Vehicle\VehiclePricing\VehiclePricingCommonRateDefinition;
use App\Models\Vehicle\VehiclePricing\VehiclePricingSlabDefinition;
use App\Services\VehiclePricingSlabConfigurationService;
use Illuminate\Support\Collection;

class PricingCalculationDefinitionHealthService
{
    private const CONDITION_OPERATORS = [
        '=', 'equals', '!=', 'not_equals', '>', 'greater_than', '<', 'less_than',
        '>=', 'greater_than_or_equal', '<=', 'less_than_or_equal',
        'in', 'contains', 'not_in', 'not_contains', 'between',
        'exists', 'not_exists', 'empty', 'not_empty',
        'starts_with', 'ends_with', 'regex',
    ];

    public function __construct(
        private readonly VehiclePricingSlabConfigurationService $slabConfiguration
    ) {}

    public function currentServiceHealth(string $serviceTypeId): array
    {
        $definitions = VehiclePricingCalculationDefinition::query()
            ->where('service_type_id', $serviceTypeId)
            ->where('status', 'active')
            ->orderByDesc('priority')
            ->orderByDesc('created_at')
            ->get();

        return $this->analyzeService($serviceTypeId, $definitions);
    }

    public function definitionReadiness(VehiclePricingCalculationDefinition $definition): array
    {
        $candidate = $definition->toArray();
        $candidate['status'] = 'active';

        return $this->prospectiveServiceHealth(
            (string) $definition->service_type_id,
            [$candidate],
            [(string) $definition->id],
            [(string) $definition->id]
        );
    }

    /** @param array<string, mixed> $candidate */
    public function prospectiveDefinitionHealth(
        array $candidate,
        ?VehiclePricingCalculationDefinition $existing = null
    ): array {
        $candidate['id'] = (string) ($candidate['id'] ?? $existing?->id ?? 'candidate');
        $candidate['status'] = 'active';
        $serviceTypeId = (string) ($candidate['service_type_id'] ?? $existing?->service_type_id ?? '');

        return $this->prospectiveServiceHealth(
            $serviceTypeId,
            [$candidate],
            $existing ? [(string) $existing->id] : [],
            [(string) $candidate['id']]
        );
    }

    /**
     * @param iterable<int, array<string, mixed>|VehiclePricingCalculationDefinition> $candidates
     * @param array<int, string> $excludeIds
     * @param array<int, string> $focusIds
     */
    public function prospectiveServiceHealth(
        string $serviceTypeId,
        iterable $candidates,
        array $excludeIds = [],
        array $focusIds = []
    ): array {
        $definitions = VehiclePricingCalculationDefinition::query()
            ->where('service_type_id', $serviceTypeId)
            ->where('status', 'active')
            ->when($excludeIds !== [], fn ($query) => $query->whereNotIn('id', $excludeIds))
            ->get()
            ->map(fn (VehiclePricingCalculationDefinition $definition) => $definition->toArray());

        foreach ($candidates as $candidate) {
            $candidateData = is_array($candidate) ? $candidate : $candidate->toArray();
            $candidateData['status'] = 'active';
            $candidateData['service_type_id'] = $serviceTypeId;
            $candidateData['id'] = (string) ($candidateData['id'] ?? 'candidate-' . ($definitions->count() + 1));
            $definitions->push($candidateData);
        }

        return $this->analyzeService($serviceTypeId, $definitions, $focusIds);
    }

    /**
     * Analyze active candidates and their dependencies. Dependency snapshots
     * are an optional seam for deterministic tests and offline configuration tools.
     *
     * @param iterable<int, array<string, mixed>|VehiclePricingCalculationDefinition> $definitions
     * @param array<int, string> $focusIds
     * @param array<string, array<string, mixed>> $dependencySnapshots
     */
    public function analyzeService(
        string $serviceTypeId,
        iterable $definitions,
        array $focusIds = [],
        array $dependencySnapshots = []
    ): array {
        $definitions = collect($definitions)
            ->filter(fn ($definition) => (string) $this->value($definition, 'status', 'active') === 'active')
            ->values();
        $definitionHealth = [];
        $issues = [];

        if ($definitions->isEmpty()) {
            $issues[] = $this->issue(
                'no_active_calculation_definitions',
                'error',
                'candidate_selection',
                $serviceTypeId,
                [],
                'No active calculation definition is configured for this service.',
                'Create or activate at least one healthy calculation definition.'
            );
        }

        foreach ($definitions as $definition) {
            $id = (string) $this->value($definition, 'id', 'candidate');
            $health = $this->analyzeDefinition(
                $definition,
                $dependencySnapshots[$id] ?? null
            );
            $definitionHealth[$id] = $health;
            array_push($issues, ...$health['issues']);
        }

        $ambiguityIssues = $this->ambiguousCandidateIssues($serviceTypeId, $definitions);
        array_push($issues, ...$ambiguityIssues);
        foreach ($ambiguityIssues as $issue) {
            foreach ($issue['definition_ids'] as $definitionId) {
                if (!isset($definitionHealth[$definitionId])) {
                    continue;
                }
                $definitionHealth[$definitionId]['issues'][] = $issue;
                $definitionHealth[$definitionId]['healthy'] = false;
                $definitionHealth[$definitionId]['ready_for_activation'] = false;
                $definitionHealth[$definitionId]['checklist']['candidate_selection'] = $this->checklistItem(
                    'candidate_selection',
                    'Candidate selection',
                    [$issue]
                );
                $definitionHealth[$definitionId]['summary'] = $this->summary(
                    $definitionHealth[$definitionId]['issues'],
                    $definitionHealth[$definitionId]['checklist']
                );
            }
        }

        $focusIssues = $focusIds === []
            ? $issues
            : collect($issues)->filter(function (array $issue) use ($focusIds) {
                if (($issue['code'] ?? null) === 'no_active_calculation_definitions') {
                    return true;
                }

                return array_intersect($issue['definition_ids'] ?? [], $focusIds) !== [];
            })->values()->all();
        $ready = !$this->containsErrors($focusIssues);

        return [
            'healthy' => !$this->containsErrors($issues),
            'ready_for_activation' => $ready,
            'service_type_id' => $serviceTypeId,
            'focus_definition_ids' => array_values($focusIds),
            'active_candidate_count' => $definitions->count(),
            'selection_order' => ['priority_desc', 'created_at_desc'],
            'definitions' => $definitionHealth,
            'issues' => $issues,
            'focus_issues' => $focusIssues,
            'summary' => $this->summary($issues),
        ];
    }

    /**
     * @param array<string, mixed>|VehiclePricingCalculationDefinition $definition
     * @param array<string, mixed>|null $dependencySnapshot
     */
    public function analyzeDefinition(
        array|VehiclePricingCalculationDefinition $definition,
        ?array $dependencySnapshot = null
    ): array {
        $id = (string) $this->value($definition, 'id', 'candidate');
        $serviceTypeId = (string) $this->value($definition, 'service_type_id', '');
        $formula = (string) $this->value($definition, 'formula', '');
        $variables = $this->arrayValue($this->value($definition, 'variables', []));
        $conditions = $this->arrayValue($this->value($definition, 'conditions', []));
        $issues = [];

        foreach (VehiclePricingCalculationDefinition::validateFormulaConfiguration($formula, $variables) as $message) {
            $issues[] = $this->issue(
                'invalid_formula',
                'error',
                'formula',
                $serviceTypeId,
                [$id],
                $message,
                'Correct the formula and declared variables before activation.'
            );
        }

        $referencedNames = $this->referencedVariableNames($formula);
        $variablesByName = collect($variables)
            ->filter(fn ($variable) => is_array($variable) && isset($variable['name']))
            ->keyBy(fn (array $variable) => (string) $variable['name']);
        $unusedVariables = $variablesByName->keys()->diff($referencedNames)->values()->all();
        if ($unusedVariables !== []) {
            $issues[] = $this->issue(
                'unused_variables',
                'warning',
                'formula',
                $serviceTypeId,
                [$id],
                'Declared variables are not used by the formula: ' . implode(', ', $unusedVariables) . '.',
                'Remove unused variables or reference them in the formula so the configuration stays clear.'
            );
        }

        $conditionIssues = $this->conditionIssues($serviceTypeId, $id, $conditions);
        array_push($issues, ...$conditionIssues);
        $conditionFields = collect($conditions)
            ->filter(fn ($condition) => is_array($condition))
            ->pluck('field')
            ->filter()
            ->map(fn ($field) => (string) $field)
            ->unique()
            ->values();

        $referencedVariables = collect($referencedNames)
            ->map(fn (string $name) => $variablesByName->get($name))
            ->filter(fn ($variable) => is_array($variable))
            ->values();
        $conditionOnlyReferences = $referencedVariables
            ->filter(fn (array $variable) => ($variable['condition_only'] ?? false)
                || ($variable['formula_allowed'] ?? true) === false
                || in_array($variable['name'] ?? null, [
                    'customer_type', 'from_date', 'from_time',
                    'owner_type', 'owner_id', 'corporate_id', 'package_id',
                ], true))
            ->pluck('name')
            ->filter()
            ->values()
            ->all();
        if ($conditionOnlyReferences !== []) {
            $issues[] = $this->issue(
                'condition_only_variable_in_formula',
                'error',
                'formula',
                $serviceTypeId,
                [$id],
                'Condition-only context cannot be used as numeric formula input: '
                    . implode(', ', $conditionOnlyReferences) . '.',
                'Move these fields into definition conditions and keep arithmetic variables numeric.'
            );
        }
        $requiresSlab = $referencedVariables->contains(
            fn (array $variable) => ($variable['type'] ?? null) === 'slab_rate'
        );
        $commonRateKeys = $referencedVariables
            ->filter(fn (array $variable) => ($variable['type'] ?? null) === 'common_rate')
            ->map(function (array $variable) {
                $name = (string) ($variable['name'] ?? '');
                return str_starts_with($name, 'common_rate_') ? substr($name, 12) : $name;
            })
            ->filter()
            ->unique()
            ->values();

        $slabDependency = $requiresSlab
            ? ($dependencySnapshot['slab_rate'] ?? $this->slabDependencyHealth($serviceTypeId, $id))
            : [
                'required' => false,
                'status' => 'skipped',
                'issues' => [],
                'details' => ['reason' => 'The formula does not reference a slab_rate variable.'],
            ];
        array_push($issues, ...($slabDependency['issues'] ?? []));

        $commonDependencies = [];
        foreach ($commonRateKeys as $rateKey) {
            $dependency = $dependencySnapshot['common_rates'][$rateKey]
                ?? $this->commonRateDependencyHealth($serviceTypeId, $id, $rateKey);
            $commonDependencies[$rateKey] = $dependency;
            array_push($issues, ...($dependency['issues'] ?? []));
        }

        $commonIssues = collect($commonDependencies)
            ->flatMap(fn (array $dependency) => $dependency['issues'] ?? [])
            ->values()
            ->all();
        $checklist = [
            'formula' => $this->checklistItem(
                'formula',
                'Formula and variables',
                collect($issues)->where('category', 'formula')->values()->all()
            ),
            'conditions' => $this->checklistItem('conditions', 'Conditions', $conditionIssues),
            'slab_dependency' => $requiresSlab
                ? $this->checklistItem('slab_dependency', 'Slab dependency', $slabDependency['issues'] ?? [])
                : $this->skippedChecklistItem(
                    'slab_dependency',
                    'Slab dependency',
                    'Skipped because slab_rate is not referenced.'
                ),
            'common_rate_dependencies' => $commonRateKeys->isNotEmpty()
                ? $this->checklistItem('common_rate_dependencies', 'Common-rate dependencies', $commonIssues)
                : $this->skippedChecklistItem(
                    'common_rate_dependencies',
                    'Common-rate dependencies',
                    'Skipped because no common_rate variable is referenced.'
                ),
            'candidate_selection' => $this->checklistItem(
                'candidate_selection',
                'Candidate selection',
                []
            ),
        ];
        $expectedRuntimeInputs = $referencedVariables
            ->filter(fn (array $variable) => in_array(
                $variable['type'] ?? null,
                ['duration', 'distance', 'number'],
                true
            ) && ($variable['is_required'] ?? true) && ($variable['default_value'] ?? null) === null)
            ->pluck('name')
            ->filter()
            ->values()
            ->all();

        return [
            'healthy' => !$this->containsErrors($issues),
            'ready_for_activation' => !$this->containsErrors($issues),
            'definition_id' => $id,
            'definition_name' => (string) $this->value($definition, 'name', 'Unnamed definition'),
            'service_type_id' => $serviceTypeId,
            'status' => (string) $this->value($definition, 'status', 'draft'),
            'priority' => (int) $this->value($definition, 'priority', 0),
            'referenced_variables' => $referencedNames,
            'expected_runtime_inputs' => $expectedRuntimeInputs,
            'scenario_context' => [
                'condition_fields' => $conditionFields->all(),
                'temporal_fields' => $conditionFields->intersect([
                    'from_date', 'from_time', 'is_weekend', 'is_holiday', 'month', 'day_of_week',
                ])->values()->all(),
                'customer_fields' => $conditionFields->intersect([
                    'customer_type', 'customer_tier',
                ])->values()->all(),
                'package_fields' => $conditionFields->intersect([
                    'package_id', 'package_included_km',
                ])->values()->all(),
                'ownership_fields' => $conditionFields->intersect([
                    'owner_type', 'owner_id', 'corporate_id',
                ])->values()->all(),
                'identifier_fields_are_condition_only' => true,
            ],
            'dependencies' => [
                'slab_rate' => $slabDependency,
                'common_rates' => $commonDependencies,
            ],
            'checklist' => $checklist,
            'issues' => $issues,
            'summary' => $this->summary($issues, $checklist),
        ];
    }

    /** @return array<string, mixed> */
    private function slabDependencyHealth(string $serviceTypeId, string $definitionId): array
    {
        $health = $this->slabConfiguration->currentHealth($serviceTypeId);
        $issues = collect($health['issues'] ?? [])->map(function (array $issue) use ($definitionId) {
            return $this->issue(
                'slab_' . ($issue['code'] ?? 'configuration_error'),
                (string) ($issue['severity'] ?? 'error'),
                'slab_dependency',
                (string) ($issue['service_type_id'] ?? ''),
                [$definitionId],
                (string) ($issue['message'] ?? 'Slab configuration is invalid.'),
                (string) ($issue['recommendation'] ?? 'Correct the slab configuration before activation.')
            );
        })->values()->all();
        $slabs = VehiclePricingSlabDefinition::withInactive()
            ->where('service_type_id', $serviceTypeId)
            ->whereNull('owner_type')
            ->whereNull('owner_id')
            ->where('is_active', true)
            ->get();
        $pricing = VehicleGroupPricing::withInactive()
            ->whereIn('slab_definition_id', $slabs->pluck('id'))
            ->where('is_active', true)
            ->get();
        $pricedSlabIds = $pricing->pluck('slab_definition_id')->unique();
        $unpricedSlabs = $slabs->reject(fn ($slab) => $pricedSlabIds->contains($slab->id))->values();

        foreach ($unpricedSlabs as $slab) {
            $issues[] = $this->issue(
                'missing_slab_pricing_values',
                'error',
                'slab_dependency',
                $serviceTypeId,
                [$definitionId],
                "Slab {$slab->name} has no active vehicle-group price value.",
                'Configure at least one active vehicle-group price for every active slab.'
            );
        }

        return [
            'required' => true,
            'status' => $this->statusForIssues($issues),
            'issues' => $issues,
            'details' => [
                'configuration_health' => $health,
                'active_slab_count' => $slabs->count(),
                'priced_slab_count' => $pricedSlabIds->count(),
                'pricing_value_count' => $pricing->count(),
                'unpriced_slab_ids' => $unpricedSlabs->pluck('id')->values()->all(),
                'public_value_count' => $pricing->whereNull('owner_type')->count(),
                'corporate_value_count' => $pricing->where('owner_type', 'corporate')->count(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function commonRateDependencyHealth(
        string $serviceTypeId,
        string $definitionId,
        string $rateKey
    ): array {
        $definitions = VehiclePricingCommonRateDefinition::withInactive()
            ->where('service_type_id', $serviceTypeId)
            ->where('is_active', true)
            ->where(function ($query) use ($rateKey) {
                $query->where('code', $rateKey)->orWhere('name', $rateKey);
            })
            ->get();
        $issues = [];

        if ($definitions->isEmpty()) {
            $issues[] = $this->issue(
                'missing_common_rate_definition',
                'error',
                'common_rate_dependency',
                $serviceTypeId,
                [$definitionId],
                "No active common-rate definition matches {$rateKey}.",
                'Create or activate a shared common-rate definition with this code.'
            );
        } elseif ($definitions->count() > 1) {
            $issues[] = $this->issue(
                'ambiguous_common_rate_definition',
                'error',
                'common_rate_dependency',
                $serviceTypeId,
                [$definitionId],
                "More than one active common-rate definition matches {$rateKey}.",
                'Keep one active definition for each common-rate code/name.'
            );
        }

        $values = VehicleGroupCommonRatePricing::withInactive()
            ->whereIn('common_rate_definition_id', $definitions->pluck('id'))
            ->where('is_active', true)
            ->get();
        if ($definitions->isNotEmpty() && $values->isEmpty()) {
            $issues[] = $this->issue(
                'missing_common_rate_values',
                'error',
                'common_rate_dependency',
                $serviceTypeId,
                [$definitionId],
                "Common rate {$rateKey} has no active vehicle-group value.",
                'Configure at least one public or corporate vehicle-group value for this common rate.'
            );
        }

        return [
            'required' => true,
            'rate_key' => $rateKey,
            'status' => $this->statusForIssues($issues),
            'issues' => $issues,
            'details' => [
                'definition_ids' => $definitions->pluck('id')->values()->all(),
                'definition_count' => $definitions->count(),
                'pricing_value_count' => $values->count(),
                'public_value_count' => $values->whereNull('owner_type')->count(),
                'corporate_value_count' => $values->where('owner_type', 'corporate')->count(),
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function conditionIssues(string $serviceTypeId, string $definitionId, array $conditions): array
    {
        $issues = [];

        foreach ($conditions as $index => $condition) {
            if (!is_array($condition)) {
                $issues[] = $this->issue(
                    'invalid_condition', 'error', 'conditions', $serviceTypeId, [$definitionId],
                    "Condition " . ($index + 1) . ' must be an object.',
                    'Recreate the condition with a field, operator, and value.'
                );
                continue;
            }

            $field = trim((string) ($condition['field'] ?? ''));
            $operator = (string) ($condition['operator'] ?? '');
            if ($field === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $field)) {
                $issues[] = $this->issue(
                    'invalid_condition_field', 'error', 'conditions', $serviceTypeId, [$definitionId],
                    "Condition " . ($index + 1) . ' has an invalid field name.',
                    'Use a runtime input name containing only letters, numbers, and underscores.'
                );
            }
            if (!in_array($operator, self::CONDITION_OPERATORS, true)) {
                $issues[] = $this->issue(
                    'unsupported_condition_operator', 'error', 'conditions', $serviceTypeId, [$definitionId],
                    "Condition " . ($index + 1) . " uses unsupported operator {$operator}.",
                    'Choose an operator supported by the runtime condition evaluator.'
                );
            }
            if (!array_key_exists('value', $condition)) {
                $issues[] = $this->issue(
                    'missing_condition_value', 'error', 'conditions', $serviceTypeId, [$definitionId],
                    "Condition " . ($index + 1) . ' has no comparison value.',
                    'Set an explicit condition value.'
                );
            }
            if (in_array($operator, ['in', 'contains', 'not_in', 'not_contains', 'between'], true)
                && !is_array($condition['value'] ?? null)) {
                $issues[] = $this->issue(
                    'invalid_condition_value', 'error', 'conditions', $serviceTypeId, [$definitionId],
                    "Condition " . ($index + 1) . " requires an array value for {$operator}.",
                    'Provide the expected values as a list.'
                );
            }
            if ($operator === 'between' && (!is_array($condition['value'] ?? null)
                || count($condition['value']) !== 2
                || !is_numeric($condition['value'][0] ?? null)
                || !is_numeric($condition['value'][1] ?? null))) {
                $issues[] = $this->issue(
                    'invalid_between_condition', 'error', 'conditions', $serviceTypeId, [$definitionId],
                    "Condition " . ($index + 1) . ' requires two numeric boundary values.',
                    'Provide a numeric minimum and maximum.'
                );
            }
            if ($operator === 'regex' && (!is_string($condition['value'] ?? null)
                || @preg_match($condition['value'], '') === false)) {
                $issues[] = $this->issue(
                    'invalid_condition_regex', 'error', 'conditions', $serviceTypeId, [$definitionId],
                    "Condition " . ($index + 1) . ' contains an invalid regular expression.',
                    'Correct the regular-expression delimiters and syntax.'
                );
            }
        }

        if (!$this->conditionsAreSatisfiable($conditions)) {
            $issues[] = $this->issue(
                'unreachable_conditions', 'error', 'conditions', $serviceTypeId, [$definitionId],
                'The configured conditions contradict each other, so this definition can never match.',
                'Remove or adjust the conflicting conditions.'
            );
        }

        return $issues;
    }

    /** @return array<int, array<string, mixed>> */
    private function ambiguousCandidateIssues(string $serviceTypeId, Collection $definitions): array
    {
        $issues = [];

        foreach ($definitions->values() as $leftIndex => $left) {
            foreach ($definitions->slice($leftIndex + 1) as $right) {
                $leftPriority = (int) $this->value($left, 'priority', 0);
                $rightPriority = (int) $this->value($right, 'priority', 0);
                if ($leftPriority !== $rightPriority) {
                    continue;
                }

                $leftConditions = $this->arrayValue($this->value($left, 'conditions', []));
                $rightConditions = $this->arrayValue($this->value($right, 'conditions', []));
                if (!$this->conditionsAreSatisfiable(array_merge($leftConditions, $rightConditions))) {
                    continue;
                }

                $leftId = (string) $this->value($left, 'id', 'candidate');
                $rightId = (string) $this->value($right, 'id', 'candidate');
                $issues[] = $this->issue(
                    'ambiguous_active_candidates',
                    'error',
                    'candidate_selection',
                    $serviceTypeId,
                    [$leftId, $rightId],
                    'Active definitions ' . $this->value($left, 'name', $leftId)
                        . ' and ' . $this->value($right, 'name', $rightId)
                        . " can match the same booking at priority {$leftPriority}.",
                    'Give them different priorities or make their conditions mutually exclusive.'
                );
            }
        }

        return $issues;
    }

    private function conditionsAreSatisfiable(array $conditions): bool
    {
        $byField = collect($conditions)
            ->filter(fn ($condition) => is_array($condition) && isset($condition['field'], $condition['operator']))
            ->groupBy(fn (array $condition) => (string) $condition['field']);

        foreach ($byField as $fieldConditions) {
            $equals = null;
            $excluded = [];
            $allowed = null;
            $minimum = null;
            $minimumInclusive = true;
            $maximum = null;
            $maximumInclusive = true;
            $existence = null;
            $emptiness = null;

            foreach ($fieldConditions as $condition) {
                $operator = (string) $condition['operator'];
                $value = $condition['value'] ?? null;
                if (in_array($operator, ['=', 'equals'], true)) {
                    if ($equals !== null && $equals != $value) {
                        return false;
                    }
                    $equals = $value;
                } elseif (in_array($operator, ['!=', 'not_equals'], true)) {
                    $excluded[] = $value;
                } elseif (in_array($operator, ['in', 'contains'], true) && is_array($value)) {
                    $allowed = $allowed === null
                        ? array_values($value)
                        : array_values(array_uintersect($allowed, $value, fn ($a, $b) => $a <=> $b));
                } elseif (in_array($operator, ['not_in', 'not_contains'], true) && is_array($value)) {
                    array_push($excluded, ...$value);
                } elseif (in_array($operator, ['>', 'greater_than', '>=', 'greater_than_or_equal'], true)
                    && is_numeric($value)) {
                    $candidate = (float) $value;
                    $inclusive = in_array($operator, ['>=', 'greater_than_or_equal'], true);
                    if ($minimum === null || $candidate > $minimum || ($candidate === $minimum && !$inclusive)) {
                        $minimum = $candidate;
                        $minimumInclusive = $inclusive;
                    }
                } elseif (in_array($operator, ['<', 'less_than', '<=', 'less_than_or_equal'], true)
                    && is_numeric($value)) {
                    $candidate = (float) $value;
                    $inclusive = in_array($operator, ['<=', 'less_than_or_equal'], true);
                    if ($maximum === null || $candidate < $maximum || ($candidate === $maximum && !$inclusive)) {
                        $maximum = $candidate;
                        $maximumInclusive = $inclusive;
                    }
                } elseif ($operator === 'between' && is_array($value) && count($value) === 2
                    && is_numeric($value[0]) && is_numeric($value[1])) {
                    $minimum = $minimum === null ? (float) $value[0] : max($minimum, (float) $value[0]);
                    $maximum = $maximum === null ? (float) $value[1] : min($maximum, (float) $value[1]);
                } elseif ($operator === 'exists' || $operator === 'not_exists') {
                    $requiredExistence = $operator === 'exists';
                    if ($existence !== null && $existence !== $requiredExistence) {
                        return false;
                    }
                    $existence = $requiredExistence;
                } elseif ($operator === 'empty' || $operator === 'not_empty') {
                    $requiredEmpty = $operator === 'empty';
                    if ($emptiness !== null && $emptiness !== $requiredEmpty) {
                        return false;
                    }
                    $emptiness = $requiredEmpty;
                }
            }

            if ($minimum !== null && $maximum !== null
                && ($minimum > $maximum
                    || ($minimum === $maximum && (!$minimumInclusive || !$maximumInclusive)))) {
                return false;
            }
            if ($equals !== null) {
                if (in_array($equals, $excluded, false)) {
                    return false;
                }
                if ($allowed !== null && !in_array($equals, $allowed, false)) {
                    return false;
                }
                if (is_numeric($equals) && !$this->numberWithinBounds(
                    (float) $equals,
                    $minimum,
                    $minimumInclusive,
                    $maximum,
                    $maximumInclusive
                )) {
                    return false;
                }
                if ($emptiness === true && !empty($equals)) {
                    return false;
                }
                if ($emptiness === false && empty($equals)) {
                    return false;
                }
            }
            if ($allowed !== null) {
                $allowed = array_values(array_filter($allowed, function ($value) use (
                    $excluded,
                    $minimum,
                    $minimumInclusive,
                    $maximum,
                    $maximumInclusive
                ) {
                    if (in_array($value, $excluded, false)) {
                        return false;
                    }
                    return !is_numeric($value) || $this->numberWithinBounds(
                        (float) $value,
                        $minimum,
                        $minimumInclusive,
                        $maximum,
                        $maximumInclusive
                    );
                }));
                if ($allowed === []) {
                    return false;
                }
            }
            if ($existence === false && ($equals !== null || $minimum !== null || $maximum !== null || $allowed !== null)) {
                return false;
            }
        }

        return true;
    }

    private function numberWithinBounds(
        float $value,
        ?float $minimum,
        bool $minimumInclusive,
        ?float $maximum,
        bool $maximumInclusive
    ): bool {
        if ($minimum !== null && ($value < $minimum || ($value === $minimum && !$minimumInclusive))) {
            return false;
        }
        if ($maximum !== null && ($value > $maximum || ($value === $maximum && !$maximumInclusive))) {
            return false;
        }
        return true;
    }

    /** @return array<int, string> */
    private function referencedVariableNames(string $formula): array
    {
        preg_match_all(
            '/\{([A-Za-z_][A-Za-z0-9_]*)\}|(?<![A-Za-z0-9_])([A-Za-z_][A-Za-z0-9_]*)(?![A-Za-z0-9_])/',
            $formula,
            $matches,
            PREG_SET_ORDER
        );

        return collect($matches)
            ->map(fn (array $match) => (string) (($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? '')))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function checklistItem(string $key, string $label, array $issues): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'status' => $this->statusForIssues($issues),
            'passed' => !$this->containsErrors($issues),
            'issues' => $issues,
        ];
    }

    /** @return array<string, mixed> */
    private function skippedChecklistItem(string $key, string $label, string $reason): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'status' => 'skipped',
            'passed' => true,
            'reason' => $reason,
            'issues' => [],
        ];
    }

    private function statusForIssues(array $issues): string
    {
        if ($this->containsErrors($issues)) {
            return 'error';
        }
        return collect($issues)->contains(fn (array $issue) => ($issue['severity'] ?? null) === 'warning')
            ? 'warning'
            : 'pass';
    }

    /** @param array<string, array<string, mixed>> $checklist */
    private function summary(array $issues, array $checklist = []): array
    {
        return [
            'errors' => collect($issues)->where('severity', 'error')->count(),
            'warnings' => collect($issues)->where('severity', 'warning')->count(),
            'passed_checks' => collect($checklist)->where('status', 'pass')->count(),
            'skipped_checks' => collect($checklist)->where('status', 'skipped')->count(),
        ];
    }

    private function containsErrors(array $issues): bool
    {
        return collect($issues)->contains(fn (array $issue) => ($issue['severity'] ?? null) === 'error');
    }

    /** @return array<string, mixed> */
    private function issue(
        string $code,
        string $severity,
        string $category,
        string $serviceTypeId,
        array $definitionIds,
        string $message,
        string $recommendation
    ): array {
        return [
            'code' => $code,
            'severity' => $severity,
            'category' => $category,
            'service_type_id' => $serviceTypeId,
            'definition_ids' => array_values($definitionIds),
            'message' => $message,
            'recommendation' => $recommendation,
        ];
    }

    private function value(array|object $definition, string $key, mixed $default = null): mixed
    {
        if (is_array($definition)) {
            return $definition[$key] ?? $default;
        }
        return $definition->{$key} ?? $default;
    }

    private function arrayValue(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($value) ? $value : [];
    }
}
