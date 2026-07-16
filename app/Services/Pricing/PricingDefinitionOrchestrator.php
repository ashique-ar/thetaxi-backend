<?php

namespace App\Services\Pricing;

use Throwable;

/**
 * Canonical calculation-definition candidate runner.
 *
 * Callers remain responsible for building their business context, but runtime,
 * previews and testers all use this class to choose and evaluate a definition.
 * A broken or non-matching high-priority candidate never suppresses the next
 * valid candidate, and failures are returned for health/audit reporting.
 */
class PricingDefinitionOrchestrator
{
    /**
     * @param iterable<object> $definitions Ordered calculation definitions.
     * @param array<string, mixed> $inputs
     * @param array<int|string, mixed> $appliedCustomizations
     * @param array<string, mixed>|null $servicePackageInfo
     * @param array<string, mixed>|null $districtInfo
     * @return array{
     *     matched: bool,
     *     definition: object|null,
     *     result: array<string, mixed>|null,
     *     candidate_failures: array<int, array<string, mixed>>
     * }
     */
    public function resolve(
        iterable $definitions,
        array $inputs,
        array $appliedCustomizations = [],
        ?array $servicePackageInfo = null,
        ?array $districtInfo = null
    ): array {
        $failures = [];

        foreach ($definitions as $definition) {
            $definitionId = $this->definitionId($definition);

            try {
                $result = $definition->calculatePrice(
                    $inputs,
                    $appliedCustomizations,
                    $servicePackageInfo,
                    $districtInfo
                );
            } catch (Throwable $exception) {
                $failures[] = [
                    'definition_id' => $definitionId,
                    'reason' => 'calculation_exception',
                    'message' => $exception->getMessage(),
                    'missing_variables' => [],
                ];
                continue;
            }

            $calculationSucceeded = ($result['calculation_success'] ?? false) === true;
            $conditionsMet = ($result['conditions_met'] ?? false) === true;
            if ($calculationSucceeded && $conditionsMet) {
                return [
                    'matched' => true,
                    'definition' => $definition,
                    'result' => $result,
                    'candidate_failures' => $failures,
                ];
            }

            $failures[] = [
                'definition_id' => $definitionId,
                'reason' => $result['failure_reason']
                    ?? ($conditionsMet ? 'calculation_unsuccessful' : 'conditions_not_met'),
                'message' => $result['message'] ?? null,
                'missing_variables' => array_values($result['missing_variables'] ?? []),
            ];
        }

        return [
            'matched' => false,
            'definition' => null,
            'result' => null,
            'candidate_failures' => $failures,
        ];
    }

    private function definitionId(object $definition): string|int|null
    {
        if (method_exists($definition, 'getKey')) {
            return $definition->getKey();
        }

        return $definition->id ?? null;
    }
}
