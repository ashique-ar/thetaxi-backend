<?php

namespace App\Services;

use App\Models\Vehicle\VehiclePricing\VehiclePricingSlabDefinition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class VehiclePricingSlabConfigurationService
{
    public const DURATION_PRECEDENCE = ['minutes', 'hours', 'days', 'per_day'];

    private const UNIT_FACTORS = [
        'minutes' => 1,
        'hours' => 60,
        'days' => 1440,
        'per_day' => 1440,
    ];

    /**
     * Resolve one duration slab using one deterministic unit precedence.
     * Minute slabs use exact elapsed minutes. Hour and day slabs use rounded-up
     * whole units so an integer range cannot leave fractional-duration holes.
     */
    public function resolve(
        Builder $baseQuery,
        float $durationMinutes,
        ?float $durationDays = null
    ): ?VehiclePricingSlabDefinition {
        $durationMinutes = max(0, $durationMinutes);

        foreach (self::DURATION_PRECEDENCE as $type) {
            $value = $this->durationValue($type, $durationMinutes, $durationDays);
            [$minimumKey, $maximumKey] = $this->rangeKeys($type);
            $query = clone $baseQuery;

            if ($type === 'hours') {
                $query->where(function (Builder $typeQuery) {
                    $typeQuery->where('type', 'hours')->orWhereNull('type');
                });
            } else {
                $query->where('type', $type);
            }

            $definition = $query
                ->whereNotNull($minimumKey)
                ->where($minimumKey, '<=', $value)
                ->where(function (Builder $rangeQuery) use ($maximumKey, $value) {
                    $rangeQuery->whereNull($maximumKey)->orWhere($maximumKey, '>=', $value);
                })
                ->orderByDesc('priority')
                ->orderByDesc($minimumKey)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->first();

            if ($definition) {
                return $definition;
            }
        }

        return null;
    }

    public function durationValue(string $type, float $durationMinutes, ?float $durationDays = null): float
    {
        $durationMinutes = max(0, $durationMinutes);

        return match ($this->normalizedType($type)) {
            'minutes' => $durationMinutes,
            'hours' => $this->roundedUpUnit($durationMinutes, 60),
            'days', 'per_day' => $durationDays !== null && $durationDays > 0
                ? ceil($durationDays)
                : $this->roundedUpUnit($durationMinutes, 1440),
            default => $durationMinutes,
        };
    }

    /**
     * Produce configuration health for active definitions. Cross-unit overlap
     * is intentional and is resolved by DURATION_PRECEDENCE; integrity is
     * checked independently inside each service/type scope.
     *
     * @param iterable<int, array<string, mixed>|object> $definitions
     * @param array<int, string> $expectedServiceIds
     * @return array<string, mixed>
     */
    public function analyze(iterable $definitions, array $expectedServiceIds = []): array
    {
        $definitions = collect($definitions)->values();
        $serviceIds = $definitions
            ->map(fn ($definition) => (string) $this->value($definition, 'service_type_id', ''))
            ->filter()
            ->merge($expectedServiceIds)
            ->unique()
            ->values();

        $services = [];
        $allIssues = [];

        foreach ($serviceIds as $serviceId) {
            $serviceDefinitions = $definitions->filter(
                fn ($definition) => (string) $this->value($definition, 'service_type_id') === $serviceId
            )->values();
            $serviceIssues = [];
            $units = [];

            if ($serviceDefinitions->isEmpty()) {
                $serviceIssues[] = $this->issue(
                    'no_active_slabs',
                    'error',
                    $serviceId,
                    null,
                    [],
                    'No active slab definitions are configured for this service.',
                    'Create or activate at least one valid slab before using slab-based pricing.'
                );
            }

            foreach (self::DURATION_PRECEDENCE as $type) {
                $unitDefinitions = $serviceDefinitions
                    ->filter(fn ($definition) => $this->normalizedType(
                        (string) $this->value($definition, 'type', 'hours')
                    ) === $type)
                    ->values();
                $unitHealth = $this->analyzeUnit($serviceId, $type, $unitDefinitions);
                $units[$type] = $unitHealth;
                array_push($serviceIssues, ...$unitHealth['issues']);
            }

            $serviceResult = [
                'service_type_id' => $serviceId,
                'service_name' => $this->serviceName($serviceDefinitions),
                'healthy' => !$this->containsErrors($serviceIssues),
                'active_definitions' => $serviceDefinitions->count(),
                'units' => $units,
                'issues' => $serviceIssues,
            ];
            $services[$serviceId] = $serviceResult;
            array_push($allIssues, ...$serviceIssues);
        }

        return [
            'healthy' => !$this->containsErrors($allIssues),
            'canonical_unit' => 'minutes',
            'precedence' => self::DURATION_PRECEDENCE,
            'matching_rules' => [
                'minutes' => 'Exact elapsed minutes',
                'hours' => 'Elapsed minutes rounded up to the next whole hour',
                'days' => 'Calendar days when supplied; otherwise elapsed minutes rounded up to whole days',
                'per_day' => 'Calendar days when supplied; otherwise elapsed minutes rounded up to whole days',
            ],
            'summary' => [
                'services' => count($services),
                'errors' => collect($allIssues)->where('severity', 'error')->count(),
                'warnings' => collect($allIssues)->where('severity', 'warning')->count(),
            ],
            'services' => $services,
            'issues' => $allIssues,
        ];
    }

    /**
     * Return the active configuration as it would look after saving a candidate.
     *
     * @param array<string, mixed> $candidate
     */
    public function prospectiveHealth(array $candidate, ?VehiclePricingSlabDefinition $existing = null): array
    {
        $serviceIds = collect([
            $candidate['service_type_id'] ?? null,
            $existing?->service_type_id,
        ])->filter()->unique()->values();

        $definitions = VehiclePricingSlabDefinition::withInactive()
            ->whereIn('service_type_id', $serviceIds)
            ->whereNull('owner_type')
            ->whereNull('owner_id')
            ->where('is_active', true)
            ->get()
            ->reject(fn (VehiclePricingSlabDefinition $definition) => $existing && $definition->id === $existing->id)
            ->map(fn (VehiclePricingSlabDefinition $definition) => $definition->toArray())
            ->values();

        $candidateActive = array_key_exists('is_active', $candidate)
            ? filter_var($candidate['is_active'], FILTER_VALIDATE_BOOL)
            : ($existing?->is_active ?? true);

        if ($candidateActive) {
            $candidate['id'] = $existing?->id ?? ($candidate['id'] ?? 'candidate');
            $candidate['is_active'] = true;
            $definitions->push($candidate);
        }

        return $this->analyze($definitions, $serviceIds->all());
    }

    /**
     * @param array<int, array{service_type_id: string, type: string}> $scopes
     */
    public function hasBlockingIssues(array $health, array $scopes): bool
    {
        return collect($health['issues'] ?? [])->contains(function (array $issue) use ($scopes) {
            if (($issue['severity'] ?? null) !== 'error') {
                return false;
            }

            return collect($scopes)->contains(function (array $scope) use ($issue) {
                $sameService = ($issue['service_type_id'] ?? null) === $scope['service_type_id'];
                $sameType = ($issue['type'] ?? null) === null
                    || $this->normalizedType((string) $issue['type']) === $this->normalizedType($scope['type']);

                return $sameService && $sameType;
            });
        });
    }

    /**
     * @param Collection<int, array<string, mixed>|object> $definitions
     * @return array<string, mixed>
     */
    private function analyzeUnit(string $serviceId, string $type, Collection $definitions): array
    {
        $ranges = $definitions
            ->map(fn ($definition) => $this->canonicalRange($definition, $type))
            ->sortBy([
                ['canonical_min_minutes', 'asc'],
                ['canonical_max_minutes', 'asc'],
                ['id', 'asc'],
            ])
            ->values();
        $issues = [];

        foreach ($ranges as $range) {
            if ($range['raw_min'] === null) {
                $issues[] = $this->issue(
                    'missing_minimum',
                    'error',
                    $serviceId,
                    $type,
                    [$range['id']],
                    "{$range['name']} has no minimum {$this->unitLabel($type)}.",
                    'Enter a minimum value for every duration slab.'
                );
            } elseif ($range['raw_max'] !== null && $range['raw_max'] < $range['raw_min']) {
                $issues[] = $this->issue(
                    'maximum_below_minimum',
                    'error',
                    $serviceId,
                    $type,
                    [$range['id']],
                    "{$range['name']} ends before it starts.",
                    'Set the maximum equal to or greater than the minimum.'
                );
            }
        }

        $validRanges = $ranges
            ->filter(fn (array $range) => $range['canonical_min_minutes'] !== null
                && ($range['canonical_max_minutes'] === null
                    || $range['canonical_max_minutes'] >= $range['canonical_min_minutes']))
            ->values();

        if ($validRanges->isNotEmpty()) {
            $first = $validRanges->first();
            $expectedMinimum = in_array($type, ['days', 'per_day'], true) ? 1 : 0;
            if ($first['raw_min'] > $expectedMinimum) {
                $issues[] = $this->issue(
                    'starts_after_minimum',
                    'warning',
                    $serviceId,
                    $type,
                    [$first['id']],
                    ucfirst($this->unitLabel($type)) . " below {$first['raw_min']} are not covered by this unit.",
                    'Confirm that a higher-precedence unit covers the earlier duration or add the missing starting slab.'
                );
            }
        }

        $coverage = null;
        foreach ($validRanges as $index => $range) {
            if ($index === 0) {
                $coverage = $range;
                continue;
            }

            if ($coverage['canonical_max_minutes'] === null) {
                $code = $range['canonical_max_minutes'] === null
                    ? 'duplicate_open_ended'
                    : 'range_after_open_ended';
                $issues[] = $this->issue(
                    $code,
                    'error',
                    $serviceId,
                    $type,
                    [$coverage['id'], $range['id']],
                    "{$range['name']} conflicts with open-ended slab {$coverage['name']}.",
                    'Keep only the final slab open-ended, or add a finite maximum before the next slab begins.'
                );
                continue;
            }

            if ($range['canonical_min_minutes'] <= $coverage['canonical_max_minutes']) {
                $issues[] = $this->issue(
                    'overlap',
                    'error',
                    $serviceId,
                    $type,
                    [$coverage['id'], $range['id']],
                    "{$coverage['name']} overlaps {$range['name']}.",
                    'Adjust the limits so each duration can match only one slab in this unit.'
                );
                if ($range['canonical_max_minutes'] === null
                    || $range['canonical_max_minutes'] > $coverage['canonical_max_minutes']) {
                    $coverage = $range;
                }
                continue;
            }

            if ($range['canonical_min_minutes'] > $coverage['canonical_max_minutes'] + 1) {
                $gapStart = $coverage['canonical_max_minutes'] + 1;
                $gapEnd = $range['canonical_min_minutes'] - 1;
                $issues[] = $this->issue(
                    'gap',
                    'error',
                    $serviceId,
                    $type,
                    [$coverage['id'], $range['id']],
                    "There is no {$this->unitLabel($type)} slab between {$coverage['name']} and {$range['name']} ({$gapStart}-{$gapEnd} canonical minutes).",
                    'Make the next minimum immediately follow the previous maximum.'
                );
            }

            $coverage = $range;
        }

        return [
            'type' => $type,
            'configured' => $definitions->isNotEmpty(),
            'healthy' => !$this->containsErrors($issues),
            'definition_count' => $definitions->count(),
            'ranges' => $ranges->all(),
            'issues' => $issues,
        ];
    }

    /** @return array<string, mixed> */
    private function canonicalRange(array|object $definition, string $type): array
    {
        [$minimumKey, $maximumKey] = $this->rangeKeys($type);
        $minimum = $this->nullableInteger($this->value($definition, $minimumKey));
        $maximum = $this->nullableInteger($this->value($definition, $maximumKey));
        $factor = self::UNIT_FACTORS[$type];

        return [
            'id' => (string) $this->value($definition, 'id', ''),
            'name' => (string) $this->value($definition, 'name', 'Unnamed slab'),
            'type' => $type,
            'raw_min' => $minimum,
            'raw_max' => $maximum,
            'canonical_min_minutes' => $minimum === null
                ? null
                : ($type === 'minutes' || $minimum === 0 ? $minimum * $factor : (($minimum - 1) * $factor) + 1),
            'canonical_max_minutes' => $maximum === null ? null : $maximum * $factor,
            'open_ended' => $maximum === null,
        ];
    }

    /** @return array{0: string, 1: string} */
    private function rangeKeys(string $type): array
    {
        return match ($this->normalizedType($type)) {
            'minutes' => ['min_minutes', 'max_minutes'],
            'days', 'per_day' => ['min_days', 'max_days'],
            default => ['min_hours', 'max_hours'],
        };
    }

    private function normalizedType(string $type): string
    {
        return $type === '' ? 'hours' : $type;
    }

    private function roundedUpUnit(float $minutes, int $factor): int
    {
        return $minutes <= 0 ? 0 : (int) ceil($minutes / $factor);
    }

    private function nullableInteger(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function value(array|object $definition, string $key, mixed $default = null): mixed
    {
        if (is_array($definition)) {
            return $definition[$key] ?? $default;
        }

        return $definition->{$key} ?? $default;
    }

    private function serviceName(Collection $definitions): ?string
    {
        $definition = $definitions->first();
        if (!$definition) {
            return null;
        }

        $serviceType = $this->value($definition, 'service_type');
        if (is_array($serviceType)) {
            return $serviceType['name'] ?? null;
        }

        return is_object($serviceType) ? ($serviceType->name ?? null) : null;
    }

    /** @param array<int, array<string, mixed>> $issues */
    private function containsErrors(array $issues): bool
    {
        return collect($issues)->contains(fn (array $issue) => $issue['severity'] === 'error');
    }

    /** @return array<string, mixed> */
    private function issue(
        string $code,
        string $severity,
        string $serviceId,
        ?string $type,
        array $definitionIds,
        string $message,
        string $recommendation
    ): array {
        return [
            'code' => $code,
            'severity' => $severity,
            'service_type_id' => $serviceId,
            'type' => $type,
            'definition_ids' => $definitionIds,
            'message' => $message,
            'recommendation' => $recommendation,
        ];
    }

    private function unitLabel(string $type): string
    {
        return match ($type) {
            'minutes' => 'minute',
            'hours' => 'hour',
            default => 'day',
        };
    }
}
