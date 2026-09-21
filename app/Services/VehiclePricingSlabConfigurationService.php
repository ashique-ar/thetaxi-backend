<?php

namespace App\Services;

use App\Models\Service\ServicePackage;
use App\Models\Vehicle\VehiclePricing\VehiclePricingSlabDefinition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class VehiclePricingSlabConfigurationService
{
    public const DURATION_PRECEDENCE = ['minutes', 'hours', 'days', 'per_day'];
    public const LEGACY_PRICING_PRECEDENCE = ['flat_rate', 'per_km'];
    public const RESOLUTION_PRECEDENCE = [
        'minutes', 'hours', 'days', 'per_day', 'flat_rate', 'per_km',
    ];

    private const UNIT_FACTORS = [
        'minutes' => 1,
        'hours' => 60,
        'days' => 1440,
        'per_day' => 1440,
    ];

    public function resolveOnlyPricedPackageSlab(
        string $serviceTypeId,
        string $vehicleGroupId,
        ?string $ownerType,
        ?string $ownerId
    ): ?VehiclePricingSlabDefinition {
        $slabs = VehiclePricingSlabDefinition::query()
            ->where('service_type_id', $serviceTypeId)
            ->whereNotNull('service_package_id')
            ->where('is_active', true)
            ->whereHas('vehicleGroupPricing', function (Builder $query) use ($vehicleGroupId, $ownerType, $ownerId) {
                $query->where('vehicle_group_id', $vehicleGroupId)
                    ->where('is_active', true)
                    ->where('rate', '>', 0)
                    ->when($ownerType && $ownerId, fn (Builder $query) => $query->where(function (Builder $scope) use ($ownerType, $ownerId) {
                        $scope->where(fn (Builder $exact) => $exact
                            ->where('owner_type', $ownerType)
                            ->where('owner_id', $ownerId))
                            ->orWhere(fn (Builder $global) => $global
                                ->whereNull('owner_type')
                                ->whereNull('owner_id'));
                    }), fn (Builder $query) => $query->whereNull('owner_type')->whereNull('owner_id'));
            })
            ->limit(2)
            ->get();

        return $slabs->count() === 1 ? $slabs->first() : null;
    }

    /** @return array{package: ?ServicePackage, slab: ?VehiclePricingSlabDefinition} */
    public function resolvePackageSlab(
        string $serviceTypeId,
        ?string $packageId,
        ?string $slabId,
        float $durationMinutes = 0,
        ?float $durationDays = null
    ): array {
        $slab = $slabId ? VehiclePricingSlabDefinition::query()
            ->whereKey($slabId)->where('service_type_id', $serviceTypeId)
            ->where('is_active', true)->first() : null;
        if ($slabId && !$slab) {
            throw new \InvalidArgumentException('The selected pricing slab is unavailable for this service.');
        }
        if (!$packageId && !$slab) {
            $slab = $this->resolve(VehiclePricingSlabDefinition::query()
                ->where('service_type_id', $serviceTypeId)
                ->whereNotNull('service_package_id')
                ->where('is_active', true), $durationMinutes, $durationDays);
        }
        if ($slab && $packageId && (string) $slab->service_package_id !== $packageId) {
            throw new \InvalidArgumentException('The selected slab and service package do not match.');
        }
        $packageId ??= $slab?->service_package_id;
        $package = $packageId ? ServicePackage::query()
            ->whereKey($packageId)->where('service_type_id', $serviceTypeId)
            ->where('is_active', true)->first() : null;
        if ($packageId && !$package) {
            throw new \InvalidArgumentException('The selected service package is unavailable for this service.');
        }
        if ($package && !$slab) {
            $slab = $this->resolve(VehiclePricingSlabDefinition::query()
                ->where('service_type_id', $serviceTypeId)
                ->where('service_package_id', $package->id)
                ->where('is_active', true), $durationMinutes, $durationDays);
            if (!$slab) {
                $activeSlabs = VehiclePricingSlabDefinition::query()
                    ->where('service_type_id', $serviceTypeId)
                    ->where('is_active', true);
                $hasPackageSlabs = (clone $activeSlabs)->whereNotNull('service_package_id')->exists();
                $slab = !$hasPackageSlabs ? $this->resolve(VehiclePricingSlabDefinition::query()
                    ->where('service_type_id', $serviceTypeId)
                    ->whereNull('service_package_id')
                    ->where('is_active', true), $durationMinutes, $durationDays) : null;
                if (!$slab && (clone $activeSlabs)->exists()) {
                    throw new \InvalidArgumentException('No active pricing slab is linked to this service package.');
                }
            }
        }

        return ['package' => $package, 'slab' => $slab];
    }

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

        // Legacy flat-rate and per-km slabs can be either hour-bounded package
        // rows (as seeded historically) or one duration-independent fallback.
        // Bounded rows win within their type; the fallback handles durations
        // outside those explicit packages. Health validation guarantees that
        // this deterministic order never silently hides another active type.
        foreach (self::LEGACY_PRICING_PRECEDENCE as $type) {
            foreach (['minutes', 'hours', 'days'] as $basis) {
                [$minimumKey, $maximumKey] = $this->rangeKeys($basis);
                $value = $this->durationValue($basis, $durationMinutes, $durationDays);
                $definition = (clone $baseQuery)
                    ->where('type', $type)
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

            $fallback = (clone $baseQuery)
                ->where('type', $type)
                ->whereNull('min_minutes')
                ->whereNull('max_minutes')
                ->whereNull('min_hours')
                ->whereNull('max_hours')
                ->whereNull('min_days')
                ->whereNull('max_days')
                ->orderByDesc('priority')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->first();

            if ($fallback) {
                return $fallback;
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
     * Reusable activation/readiness check for calculation definitions and other
     * pricing configuration workflows.
     */
    public function currentHealth(?string $serviceTypeId = null, ?string $context = null): array
    {
        $query = VehiclePricingSlabDefinition::withInactive()
            ->with('serviceType')
            ->whereNull('owner_type')
            ->whereNull('owner_id')
            ->where('is_active', true);

        if ($serviceTypeId) {
            $query->where('service_type_id', $serviceTypeId);
        }
        if ($context) {
            $query->whereHas('serviceType', fn ($serviceQuery) => $serviceQuery->where('context', $context));
        }

        return $this->analyze(
            $query->get(),
            $serviceTypeId ? [$serviceTypeId] : []
        );
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

            $unsupportedDefinitions = $serviceDefinitions->filter(function ($definition) {
                $type = $this->normalizedType((string) $this->value($definition, 'type', 'hours'));
                return !in_array($type, self::RESOLUTION_PRECEDENCE, true);
            });
            foreach ($unsupportedDefinitions as $definition) {
                $serviceIssues[] = $this->issue(
                    'unsupported_slab_type',
                    'error',
                    $serviceId,
                    null,
                    [(string) $this->value($definition, 'id', '')],
                    'Slab ' . $this->value($definition, 'name', 'Unnamed slab')
                        . ' uses an unsupported pricing type.',
                    'Change it to a supported minute, hour, day, flat-rate, or per-km type.'
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
            foreach (self::LEGACY_PRICING_PRECEDENCE as $type) {
                $unitDefinitions = $serviceDefinitions
                    ->filter(fn ($definition) => $this->normalizedType(
                        (string) $this->value($definition, 'type', '')
                    ) === $type)
                    ->values();
                $unitHealth = $this->analyzeLegacyUnit($serviceId, $type, $unitDefinitions);
                $units[$type] = $unitHealth;
                array_push($serviceIssues, ...$unitHealth['issues']);
            }

            $resolutionIssues = $this->resolutionIntegrityIssues($serviceId, $units, $serviceDefinitions);
            array_push($serviceIssues, ...$resolutionIssues);

            $serviceResult = [
                'service_type_id' => $serviceId,
                'service_name' => $this->serviceName($serviceDefinitions),
                'healthy' => !$this->containsErrors($serviceIssues),
                'active_definitions' => $serviceDefinitions->count(),
                'units' => $units,
                'resolution_precedence' => self::RESOLUTION_PRECEDENCE,
                'issues' => $serviceIssues,
            ];
            $services[$serviceId] = $serviceResult;
            array_push($allIssues, ...$serviceIssues);
        }

        return [
            'healthy' => !$this->containsErrors($allIssues),
            'canonical_unit' => 'minutes',
            'precedence' => self::DURATION_PRECEDENCE,
            'legacy_pricing_precedence' => self::LEGACY_PRICING_PRECEDENCE,
            'resolution_precedence' => self::RESOLUTION_PRECEDENCE,
            'matching_rules' => [
                'minutes' => 'Exact elapsed minutes',
                'hours' => 'Elapsed minutes rounded up to the next whole hour',
                'days' => 'Calendar days when supplied; otherwise elapsed minutes rounded up to whole days',
                'per_day' => 'Calendar days when supplied; otherwise elapsed minutes rounded up to whole days',
                'flat_rate' => 'Explicit legacy minute/hour/day package range, then one duration-independent fallback',
                'per_km' => 'Explicit legacy minute/hour/day range, then one duration-independent fallback',
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
     * Legacy flat-rate/per-km definitions historically stored optional hour
     * package ranges. Range gaps are intentional for discrete packages, while
     * overlaps are ambiguous. A completely range-less row is an explicit
     * duration-independent fallback; only one fallback per type is valid.
     *
     * @param Collection<int, array<string, mixed>|object> $definitions
     * @return array<string, mixed>
     */
    private function analyzeLegacyUnit(string $serviceId, string $type, Collection $definitions): array
    {
        $scopes = $definitions->groupBy(
            fn ($definition) => (string) ($this->value($definition, 'service_package_id') ?: '__generic__')
        );
        if ($scopes->isEmpty()) {
            return $this->analyzeLegacyUnitScope($serviceId, $type, $definitions);
        }

        $results = $scopes->map(
            fn (Collection $scopeDefinitions) => $this->analyzeLegacyUnitScope($serviceId, $type, $scopeDefinitions)
        );

        return [
            'type' => $type,
            'configured' => $definitions->isNotEmpty(),
            'healthy' => $results->every(fn (array $result) => $result['healthy']),
            'definition_count' => $definitions->count(),
            'fallback_count' => $results->sum('fallback_count'),
            'ranges' => $results->flatMap(fn (array $result) => $result['ranges'])->values()->all(),
            'issues' => $results->flatMap(fn (array $result) => $result['issues'])->values()->all(),
        ];
    }

    private function analyzeLegacyUnitScope(string $serviceId, string $type, Collection $definitions): array
    {
        $issues = [];
        $boundedRanges = collect();
        $fallbackRanges = collect();

        foreach ($definitions as $definition) {
            $bases = $this->configuredRangeBases($definition);
            if ($bases === []) {
                $fallbackRanges->push($this->fallbackRange($definition, $type));
                continue;
            }

            if (count($bases) > 1) {
                $issues[] = $this->issue(
                    'mixed_legacy_range_units',
                    'error',
                    $serviceId,
                    $type,
                    [(string) $this->value($definition, 'id', '')],
                    $this->value($definition, 'name', 'Legacy slab')
                        . ' mixes minute, hour, or day boundaries.',
                    'Keep one range unit on this legacy slab; hours are supported for existing package rows.'
                );
            }

            $basis = $bases[0];
            $range = $this->canonicalRangeForBasis($definition, $type, $basis);
            if ($range['raw_min'] === null) {
                $issues[] = $this->issue(
                    'missing_minimum',
                    'error',
                    $serviceId,
                    $type,
                    [$range['id']],
                    "{$range['name']} has a maximum but no minimum {$basis} value.",
                    'Add the matching minimum or clear every range field to make this the fallback.'
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
            $boundedRanges->push($range);
        }

        if ($fallbackRanges->count() > 1) {
            $issues[] = $this->issue(
                'duplicate_duration_independent_fallback',
                'error',
                $serviceId,
                $type,
                $fallbackRanges->pluck('id')->all(),
                'More than one duration-independent ' . str_replace('_', ' ', $type) . ' slab is active.',
                'Keep one fallback for this type, or add explicit legacy hour ranges to the others.'
            );
        }

        $validRanges = $boundedRanges
            ->filter(fn (array $range) => $range['canonical_min_minutes'] !== null
                && ($range['canonical_max_minutes'] === null
                    || $range['canonical_max_minutes'] >= $range['canonical_min_minutes']))
            ->sortBy([
                ['canonical_min_minutes', 'asc'],
                ['canonical_max_minutes', 'asc'],
                ['id', 'asc'],
            ])
            ->values();
        $coverage = null;
        foreach ($validRanges as $range) {
            if ($coverage === null) {
                $coverage = $range;
                continue;
            }
            if ($coverage['canonical_max_minutes'] === null
                || $range['canonical_min_minutes'] <= $coverage['canonical_max_minutes']) {
                $issues[] = $this->issue(
                    'overlap',
                    'error',
                    $serviceId,
                    $type,
                    [$coverage['id'], $range['id']],
                    "{$coverage['name']} overlaps {$range['name']}.",
                    'Make legacy package ranges mutually exclusive; use priority on calculation definitions, not overlapping slabs.'
                );
                if ($coverage['canonical_max_minutes'] !== null
                    && ($range['canonical_max_minutes'] === null
                        || $range['canonical_max_minutes'] > $coverage['canonical_max_minutes'])) {
                    $coverage = $range;
                }
                continue;
            }
            $coverage = $range;
        }

        $ranges = $validRanges
            ->concat($fallbackRanges)
            ->sortBy([
                ['fallback', 'asc'],
                ['canonical_min_minutes', 'asc'],
                ['id', 'asc'],
            ])
            ->values();

        return [
            'type' => $type,
            'configured' => $definitions->isNotEmpty(),
            'healthy' => !$this->containsErrors($issues),
            'definition_count' => $definitions->count(),
            'fallback_count' => $fallbackRanges->count(),
            'ranges' => $ranges->all(),
            'issues' => $issues,
        ];
    }

    /**
     * Ensure the ordered resolver has a safe first billable minute and does
     * not contain lower-precedence rows that can never be selected.
     *
     * @param array<string, array<string, mixed>> $units
     * @param Collection<int, array<string, mixed>|object> $definitions
     * @return array<int, array<string, mixed>>
     */
    private function resolutionIntegrityIssues(
        string $serviceId,
        array $units,
        Collection $definitions
    ): array {
        $issues = [];
        $ranges = collect($units)
            ->flatMap(fn (array $unit) => $unit['ranges'] ?? [])
            ->filter(fn (array $range) => $range['canonical_min_minutes'] !== null
                && ($range['canonical_max_minutes'] === null
                    || $range['canonical_max_minutes'] >= $range['canonical_min_minutes']))
            ->map(function (array $range) {
                $range['resolution_rank'] = array_search(
                    $range['type'],
                    self::RESOLUTION_PRECEDENCE,
                    true
                );
                return $range;
            })
            ->filter(fn (array $range) => $range['resolution_rank'] !== false)
            ->values();
        $hasDurationDefinitions = $definitions->contains(fn ($definition) => in_array(
            $this->normalizedType((string) $this->value($definition, 'type', 'hours')),
            self::DURATION_PRECEDENCE,
            true
        ));

        if ($hasDurationDefinitions && !$ranges->contains(
            fn (array $range) => $this->rangeContainsMinute($range, 1)
        )) {
            $first = $ranges->sortBy('canonical_min_minutes')->first();
            $issues[] = $this->issue(
                'unsafe_leading_gap',
                'error',
                $serviceId,
                null,
                $first ? [$first['id']] : [],
                'No active slab can resolve the first billable minute.',
                'Start one duration range at the first billable unit or add one duration-independent fallback.'
            );
        }

        foreach ($ranges as $range) {
            if ($range['canonical_max_minutes'] !== null
                && $range['canonical_max_minutes'] < 1) {
                $issues[] = $this->issue(
                    'no_positive_duration_coverage',
                    'error',
                    $serviceId,
                    null,
                    [$range['id']],
                    "{$range['name']} cannot resolve any positive booking duration.",
                    'Use a range that includes at least one billable minute.'
                );
                continue;
            }

            $effectiveStart = max(1, (int) $range['canonical_min_minutes']);
            $higherRanges = $ranges->filter(fn (array $higher) => $higher['resolution_rank'] < $range['resolution_rank']);
            if ($higherRanges->isEmpty()
                || !$this->rangesCover($higherRanges, $effectiveStart, $range['canonical_max_minutes'])) {
                continue;
            }

            $coveringIds = $higherRanges
                ->filter(fn (array $higher) => $this->rangesIntersect(
                    $higher,
                    $effectiveStart,
                    $range['canonical_max_minutes']
                ))
                ->pluck('id')
                ->values()
                ->all();
            $issues[] = $this->issue(
                'cross_unit_fully_shadowed',
                'error',
                $serviceId,
                null,
                array_values(array_unique(array_merge([$range['id']], $coveringIds))),
                "{$range['name']} can never resolve because higher-precedence slab types cover its entire range.",
                'Remove the shadowed slab, narrow the higher-precedence ranges, or use mutually exclusive calculation conditions.'
            );
        }

        return $issues;
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
        $basis = match ($this->normalizedType($type)) {
            'minutes' => 'minutes',
            'days', 'per_day' => 'days',
            default => 'hours',
        };

        return $this->canonicalRangeForBasis($definition, $type, $basis);
    }

    /** @return array<string, mixed> */
    private function canonicalRangeForBasis(array|object $definition, string $type, string $basis): array
    {
        [$minimumKey, $maximumKey] = $this->rangeKeys($basis);
        $minimum = $this->nullableInteger($this->value($definition, $minimumKey));
        $maximum = $this->nullableInteger($this->value($definition, $maximumKey));
        $factor = self::UNIT_FACTORS[$basis];

        return [
            'id' => (string) $this->value($definition, 'id', ''),
            'name' => (string) $this->value($definition, 'name', 'Unnamed slab'),
            'type' => $type,
            'resolution_basis' => $basis,
            'raw_min' => $minimum,
            'raw_max' => $maximum,
            'canonical_min_minutes' => $minimum === null
                ? null
                : ($basis === 'minutes' || $minimum === 0 ? $minimum * $factor : (($minimum - 1) * $factor) + 1),
            'canonical_max_minutes' => $maximum === null ? null : $maximum * $factor,
            'open_ended' => $maximum === null,
            'fallback' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function fallbackRange(array|object $definition, string $type): array
    {
        return [
            'id' => (string) $this->value($definition, 'id', ''),
            'name' => (string) $this->value($definition, 'name', 'Unnamed slab'),
            'type' => $type,
            'resolution_basis' => 'fallback',
            'raw_min' => null,
            'raw_max' => null,
            'canonical_min_minutes' => 0,
            'canonical_max_minutes' => null,
            'open_ended' => true,
            'fallback' => true,
        ];
    }

    /** @return array<int, string> */
    private function configuredRangeBases(array|object $definition): array
    {
        return collect(['minutes', 'hours', 'days'])
            ->filter(function (string $basis) use ($definition) {
                [$minimumKey, $maximumKey] = $this->rangeKeys($basis);
                return $this->value($definition, $minimumKey) !== null
                    || $this->value($definition, $maximumKey) !== null;
            })
            ->values()
            ->all();
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

    private function rangeContainsMinute(array $range, int $minute): bool
    {
        return $range['canonical_min_minutes'] <= $minute
            && ($range['canonical_max_minutes'] === null
                || $range['canonical_max_minutes'] >= $minute);
    }

    /** @param Collection<int, array<string, mixed>> $ranges */
    private function rangesCover(Collection $ranges, int $minimum, ?int $maximum): bool
    {
        $cursor = $minimum;
        $ordered = $ranges->sortBy([
            ['canonical_min_minutes', 'asc'],
            ['canonical_max_minutes', 'asc'],
        ]);

        foreach ($ordered as $range) {
            $rangeMaximum = $range['canonical_max_minutes'];
            if ($rangeMaximum !== null && $rangeMaximum < $cursor) {
                continue;
            }
            if ($range['canonical_min_minutes'] > $cursor) {
                return false;
            }
            if ($rangeMaximum === null) {
                return true;
            }
            $cursor = max($cursor, (int) $rangeMaximum + 1);
            if ($maximum !== null && $cursor > $maximum) {
                return true;
            }
        }

        return $maximum !== null && $cursor > $maximum;
    }

    private function rangesIntersect(array $range, int $minimum, ?int $maximum): bool
    {
        $rangeMaximum = $range['canonical_max_minutes'];
        if ($rangeMaximum !== null && $rangeMaximum < $minimum) {
            return false;
        }
        return $maximum === null || $range['canonical_min_minutes'] <= $maximum;
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
