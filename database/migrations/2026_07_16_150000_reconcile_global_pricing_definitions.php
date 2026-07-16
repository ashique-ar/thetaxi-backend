<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SLAB_TABLE = 'vehicle_pricing_slab_definitions';
    private const COMMON_RATE_TABLE = 'vehicle_pricing_common_rate_definitions';
    private const CALCULATION_TABLE = 'vehicle_pricing_calculation_definitions';

    public function up(): void
    {
        DB::transaction(function (): void {
            $this->dropLegacyOwnerIndexes();

            $referenceMap = array_merge(
                $this->reconcileCommonRateDefinitions(),
                $this->reconcileSlabDefinitions(),
            );

            $this->replaceCalculationDefinitionReferences($referenceMap);
            $this->reconcileCalculationDefinitions();
            $this->normalizeDeletedDefinitionOwners();
            $this->assertDefinitionsAreGlobal();
            $this->createCanonicalScopeIndexes();
        });
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Global pricing-definition reconciliation is intentionally irreversible; restore a database backup to undo it.'
        );
    }

    /**
     * @return array<string, string> old definition ID => canonical definition ID
     */
    private function reconcileCommonRateDefinitions(): array
    {
        if (!Schema::hasTable(self::COMMON_RATE_TABLE)) {
            return [];
        }

        $rows = DB::table(self::COMMON_RATE_TABLE)
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $referenceMap = [];

        foreach ($rows->groupBy(fn (object $row): string => $this->commonRateIdentity($row)) as $identity => $group) {
            /** @var Collection<int, object> $group */
            $canonical = $group->first(fn (object $row): bool => $this->isGlobal($row)) ?? $group->first();

            foreach ($group as $row) {
                if ($row->id === $canonical->id) {
                    continue;
                }

                if (!$this->commonRatesAreCompatible($canonical, $row)) {
                    throw new RuntimeException(
                        "Unsafe common-rate definition conflict for {$identity}: {$canonical->id} and {$row->id} use different rate semantics."
                    );
                }

                $this->repointCommonRatePricing($row->id, $canonical->id);
                $this->repointColumn('booking_common_rate_pricings', 'common_rate_definition_id', $row->id, $canonical->id);
                $this->repointColumn('vehicle_pricing_history', 'common_rate_definition_id', $row->id, $canonical->id);

                DB::table(self::COMMON_RATE_TABLE)->where('id', $row->id)->update([
                    'owner_type' => null,
                    'owner_id' => null,
                    'deleted_at' => now(),
                    'updated_at' => now(),
                ]);

                $referenceMap[$row->id] = $canonical->id;
            }

            DB::table(self::COMMON_RATE_TABLE)->where('id', $canonical->id)->update([
                'owner_type' => null,
                'owner_id' => null,
                'is_active' => $group->contains(fn (object $row): bool => (bool) $row->is_active),
                'priority' => (int) $group->max('priority'),
                'sort_order' => (int) $group->min('sort_order'),
                'updated_at' => now(),
            ]);
        }

        return $referenceMap;
    }

    /**
     * @return array<string, string> old definition ID => canonical definition ID
     */
    private function reconcileSlabDefinitions(): array
    {
        if (!Schema::hasTable(self::SLAB_TABLE)) {
            return [];
        }

        $rows = DB::table(self::SLAB_TABLE)
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $referenceMap = [];

        foreach ($rows->groupBy(fn (object $row): string => $this->slabIdentity($row)) as $identity => $group) {
            /** @var Collection<int, object> $group */
            $canonical = $group->first(fn (object $row): bool => $this->isGlobal($row)) ?? $group->first();

            foreach ($group as $row) {
                if ($row->id === $canonical->id) {
                    continue;
                }

                if (!$this->slabsAreCompatible($canonical, $row)) {
                    throw new RuntimeException(
                        "Unsafe slab definition conflict for {$identity}: {$canonical->id} and {$row->id} have different boundaries or limits."
                    );
                }

                $this->repointSlabPricing($row->id, $canonical->id);
                $this->repointColumn('booking_pricings', 'slab_definition_id', $row->id, $canonical->id);
                $this->repointColumn('pricing_calculations', 'slab_definition_id', $row->id, $canonical->id);
                $this->repointColumn('vehicle_pricing_history', 'pricing_slab_definition_id', $row->id, $canonical->id);

                DB::table(self::SLAB_TABLE)->where('id', $row->id)->update([
                    'owner_type' => null,
                    'owner_id' => null,
                    'deleted_at' => now(),
                    'updated_at' => now(),
                ]);

                $referenceMap[$row->id] = $canonical->id;
            }

            DB::table(self::SLAB_TABLE)->where('id', $canonical->id)->update([
                'owner_type' => null,
                'owner_id' => null,
                'is_active' => $group->contains(fn (object $row): bool => (bool) $row->is_active),
                'priority' => (int) $group->max('priority'),
                'sort_order' => (int) $group->min('sort_order'),
                'updated_at' => now(),
            ]);
        }

        return $referenceMap;
    }

    private function reconcileCalculationDefinitions(): void
    {
        if (!Schema::hasTable(self::CALCULATION_TABLE)) {
            return;
        }

        $rows = DB::table(self::CALCULATION_TABLE)
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        foreach ($rows->groupBy(fn (object $row): string => $this->calculationIdentity($row)) as $identity => $group) {
            /** @var Collection<int, object> $group */
            $canonical = $group->first(fn (object $row): bool => $this->isGlobal($row)) ?? $group->first();

            foreach ($group as $row) {
                if ($row->id === $canonical->id) {
                    continue;
                }

                if (!$this->calculationsAreCompatible($canonical, $row)) {
                    throw new RuntimeException(
                        "Unsafe calculation definition conflict for {$identity}: {$canonical->id} and {$row->id} have different formula, variables, or conditions."
                    );
                }

                DB::table(self::CALCULATION_TABLE)->where('id', $row->id)->update([
                    'owner_type' => null,
                    'owner_id' => null,
                    'deleted_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table(self::CALCULATION_TABLE)->where('id', $canonical->id)->update([
                'owner_type' => null,
                'owner_id' => null,
                'status' => $this->mergedCalculationStatus($group),
                'priority' => (int) $group->max('priority'),
                'updated_at' => now(),
            ]);
        }
    }

    private function repointSlabPricing(string $fromDefinitionId, string $toDefinitionId): void
    {
        if (!Schema::hasTable('vehicle_group_pricing')) {
            return;
        }

        $rows = DB::table('vehicle_group_pricing')
            ->where('slab_definition_id', $fromDefinitionId)
            ->orderBy('created_at')
            ->get();

        foreach ($rows as $row) {
            if ($row->deleted_at !== null) {
                DB::table('vehicle_group_pricing')->where('id', $row->id)->update([
                    'slab_definition_id' => $toDefinitionId,
                    'updated_at' => now(),
                ]);
                continue;
            }

            $existing = $this->findScopedPricingRow(
                'vehicle_group_pricing',
                'slab_definition_id',
                $toDefinitionId,
                $row,
            );

            if ($existing && !$this->slabPricesAreCompatible($existing, $row)) {
                throw new RuntimeException(
                    "Unsafe vehicle-group slab price conflict for definition {$toDefinitionId}, vehicle group {$row->vehicle_group_id}, and owner {$this->ownerLabel($row)}."
                );
            }

            DB::table('vehicle_group_pricing')->where('id', $row->id)->update([
                'slab_definition_id' => $toDefinitionId,
                'deleted_at' => $existing ? now() : null,
                'updated_at' => now(),
            ]);
        }
    }

    private function repointCommonRatePricing(string $fromDefinitionId, string $toDefinitionId): void
    {
        if (!Schema::hasTable('vehicle_group_common_rate_pricing')) {
            return;
        }

        $rows = DB::table('vehicle_group_common_rate_pricing')
            ->where('common_rate_definition_id', $fromDefinitionId)
            ->orderBy('created_at')
            ->get();

        foreach ($rows as $row) {
            if ($row->deleted_at !== null) {
                DB::table('vehicle_group_common_rate_pricing')->where('id', $row->id)->update([
                    'common_rate_definition_id' => $toDefinitionId,
                    'updated_at' => now(),
                ]);
                continue;
            }

            $existing = $this->findScopedPricingRow(
                'vehicle_group_common_rate_pricing',
                'common_rate_definition_id',
                $toDefinitionId,
                $row,
            );

            if ($existing && !$this->commonRatePricesAreCompatible($existing, $row)) {
                throw new RuntimeException(
                    "Unsafe vehicle-group common-rate price conflict for definition {$toDefinitionId}, vehicle group {$row->vehicle_group_id}, and owner {$this->ownerLabel($row)}."
                );
            }

            DB::table('vehicle_group_common_rate_pricing')->where('id', $row->id)->update([
                'common_rate_definition_id' => $toDefinitionId,
                'deleted_at' => $existing ? now() : null,
                'updated_at' => now(),
            ]);
        }
    }

    private function findScopedPricingRow(
        string $table,
        string $definitionColumn,
        string $definitionId,
        object $source,
    ): ?object {
        $query = DB::table($table)
            ->where($definitionColumn, $definitionId)
            ->where('vehicle_group_id', $source->vehicle_group_id)
            ->whereNull('deleted_at')
            ->where('id', '!=', $source->id);

        if ($source->owner_type === null && $source->owner_id === null) {
            $query->whereNull('owner_type')->whereNull('owner_id');
        } else {
            $query->where('owner_type', $source->owner_type)
                ->where('owner_id', $source->owner_id);
        }

        return $query->first();
    }

    /**
     * Replace source IDs in calculation variables/conditions without changing
     * formula variable names.
     *
     * @param array<string, string> $referenceMap
     */
    private function replaceCalculationDefinitionReferences(array $referenceMap): void
    {
        if ($referenceMap === [] || !Schema::hasTable(self::CALCULATION_TABLE)) {
            return;
        }

        foreach (DB::table(self::CALCULATION_TABLE)->get() as $definition) {
            $changes = [];

            foreach (['variables', 'conditions'] as $column) {
                if ($definition->{$column} === null) {
                    continue;
                }

                $decoded = $this->decodeJson($definition->{$column}, $definition->id, $column);
                $replaced = $this->replaceIdsRecursively($decoded, $referenceMap);

                if ($replaced !== $decoded) {
                    $changes[$column] = json_encode($replaced, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                }
            }

            if ($changes !== []) {
                $changes['updated_at'] = now();
                DB::table(self::CALCULATION_TABLE)->where('id', $definition->id)->update($changes);
            }
        }
    }

    private function replaceIdsRecursively(mixed $value, array $referenceMap): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->replaceIdsRecursively($item, $referenceMap);
            }

            return $value;
        }

        return is_string($value) && isset($referenceMap[$value])
            ? $referenceMap[$value]
            : $value;
    }

    private function decodeJson(mixed $value, string $definitionId, string $column): mixed
    {
        if (is_array($value)) {
            return $value;
        }

        try {
            return json_decode((string) $value, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                "Cannot reconcile calculation definition {$definitionId}: {$column} contains invalid JSON.",
                previous: $exception,
            );
        }
    }

    private function normalizeDeletedDefinitionOwners(): void
    {
        foreach ([self::SLAB_TABLE, self::COMMON_RATE_TABLE, self::CALCULATION_TABLE] as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            DB::table($table)
                ->where(function ($query) {
                    $query->whereNotNull('owner_type')->orWhereNotNull('owner_id');
                })
                ->update([
                    'owner_type' => null,
                    'owner_id' => null,
                    'updated_at' => now(),
                ]);
        }
    }

    private function assertDefinitionsAreGlobal(): void
    {
        foreach ([self::SLAB_TABLE, self::COMMON_RATE_TABLE, self::CALCULATION_TABLE] as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            $remaining = DB::table($table)
                ->where(function ($query) {
                    $query->whereNotNull('owner_type')->orWhereNotNull('owner_id');
                })
                ->count();

            if ($remaining > 0) {
                throw new RuntimeException("{$table} still contains {$remaining} owned definition rows after reconciliation.");
            }
        }
    }

    private function commonRateIdentity(object $row): string
    {
        $identifier = trim((string) ($row->code ?? ''));
        if ($identifier === '') {
            $identifier = trim((string) $row->name);
        }

        return implode('|', [
            strtolower((string) ($row->service_type_id ?? 'global')),
            strtolower((string) ($row->vehicle_group_id ?? 'all-groups')),
            strtolower($identifier),
        ]);
    }

    private function slabIdentity(object $row): string
    {
        return strtolower((string) $row->service_type_id . '|' . trim((string) $row->name));
    }

    private function calculationIdentity(object $row): string
    {
        return strtolower((string) $row->service_type_id . '|' . trim((string) $row->name));
    }

    private function commonRatesAreCompatible(object $left, object $right): bool
    {
        return $this->same($left->common_rate_type, $right->common_rate_type)
            && $this->same($left->is_mandatory, $right->is_mandatory);
    }

    private function slabsAreCompatible(object $left, object $right): bool
    {
        foreach ([
            'type',
            'min_minutes',
            'max_minutes',
            'min_hours',
            'max_hours',
            'min_days',
            'max_days',
            'max_km_per_day',
            'max_km_per_package',
        ] as $column) {
            if (!$this->same($left->{$column} ?? null, $right->{$column} ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function calculationsAreCompatible(object $left, object $right): bool
    {
        return trim((string) $left->formula) === trim((string) $right->formula)
            && $this->normalizedJson($left->variables, $left->id, 'variables') === $this->normalizedJson($right->variables, $right->id, 'variables')
            && $this->normalizedJson($left->conditions, $left->id, 'conditions') === $this->normalizedJson($right->conditions, $right->id, 'conditions');
    }

    private function slabPricesAreCompatible(object $left, object $right): bool
    {
        foreach (['rate', 'rate_type', 'minimum_charge', 'includes_fuel', 'includes_driver'] as $column) {
            if (!$this->same($left->{$column} ?? null, $right->{$column} ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function commonRatePricesAreCompatible(object $left, object $right): bool
    {
        return $this->same($left->value ?? null, $right->value ?? null);
    }

    private function normalizedJson(mixed $value, string $definitionId, string $column): string
    {
        $decoded = $value === null ? null : $this->decodeJson($value, $definitionId, $column);
        $this->sortJsonRecursively($decoded);

        return json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function sortJsonRecursively(mixed &$value): void
    {
        if (!is_array($value)) {
            return;
        }

        foreach ($value as &$item) {
            $this->sortJsonRecursively($item);
        }
        unset($item);

        if (!array_is_list($value)) {
            ksort($value);
        }
    }

    private function same(mixed $left, mixed $right): bool
    {
        if ($left === null || $right === null) {
            return $left === $right;
        }

        if (is_numeric($left) && is_numeric($right)) {
            return (float) $left === (float) $right;
        }

        return (string) $left === (string) $right;
    }

    private function mergedCalculationStatus(Collection $group): string
    {
        if ($group->contains(fn (object $row): bool => $row->status === 'active')) {
            return 'active';
        }

        if ($group->contains(fn (object $row): bool => $row->status === 'draft')) {
            return 'draft';
        }

        return 'inactive';
    }

    private function isGlobal(object $row): bool
    {
        return $row->owner_type === null && $row->owner_id === null;
    }

    private function ownerLabel(object $row): string
    {
        return $this->isGlobal($row)
            ? 'global'
            : (string) $row->owner_type . ':' . (string) $row->owner_id;
    }

    private function repointColumn(string $table, string $column, string $fromId, string $toId): void
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
            return;
        }

        DB::table($table)->where($column, $fromId)->update([$column => $toId]);
    }

    private function dropLegacyOwnerIndexes(): void
    {
        $this->dropIndexIfExists('vehicle_group_pricing', 'unique_group_slab_pricing');
        $this->dropIndexIfExists(self::COMMON_RATE_TABLE, 'unique_common_rate_definition_name_owner');
        $this->dropIndexIfExists(self::COMMON_RATE_TABLE, 'unique_common_rate_definition_code_owner');
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        if (!Schema::hasTable($table)) {
            return;
        }

        $index = collect(Schema::getIndexes($table))
            ->first(fn (array $index): bool => ($index['name'] ?? null) === $indexName);

        if ($index !== null) {
            Schema::table($table, function (Blueprint $blueprint) use ($index, $indexName): void {
                if (($index['unique'] ?? false) === true) {
                    $blueprint->dropUnique($indexName);
                } else {
                    $blueprint->dropIndex($indexName);
                }
            });
        }
    }

    private function createCanonicalScopeIndexes(): void
    {
        $driver = DB::getDriverName();

        if (Schema::hasTable('vehicle_group_pricing')) {
            if (in_array($driver, ['pgsql', 'sqlite'], true)) {
                DB::statement(<<<'SQL'
                    CREATE UNIQUE INDEX IF NOT EXISTS vehicle_group_pricing_definition_group_owner_unique
                    ON vehicle_group_pricing (
                        slab_definition_id,
                        vehicle_group_id,
                        COALESCE(owner_type, ''),
                        COALESCE(CAST(owner_id AS TEXT), '')
                    )
                    WHERE deleted_at IS NULL
                SQL);
            } else {
                Schema::table('vehicle_group_pricing', function (Blueprint $table) {
                    $table->unique(
                        ['slab_definition_id', 'vehicle_group_id', 'owner_type', 'owner_id'],
                        'vehicle_group_pricing_definition_group_owner_unique'
                    );
                });
            }
        }

        if (Schema::hasTable('vehicle_group_common_rate_pricing')) {
            if (in_array($driver, ['pgsql', 'sqlite'], true)) {
                DB::statement(<<<'SQL'
                    CREATE UNIQUE INDEX IF NOT EXISTS vehicle_group_common_rate_definition_group_owner_unique
                    ON vehicle_group_common_rate_pricing (
                        common_rate_definition_id,
                        vehicle_group_id,
                        COALESCE(owner_type, ''),
                        COALESCE(CAST(owner_id AS TEXT), '')
                    )
                    WHERE deleted_at IS NULL
                SQL);
            } else {
                Schema::table('vehicle_group_common_rate_pricing', function (Blueprint $table) {
                    $table->unique(
                        ['common_rate_definition_id', 'vehicle_group_id', 'owner_type', 'owner_id'],
                        'vehicle_group_common_rate_definition_group_owner_unique'
                    );
                });
            }
        }

        if (Schema::hasTable(self::COMMON_RATE_TABLE)) {
            if (in_array($driver, ['pgsql', 'sqlite'], true)) {
                DB::statement(<<<'SQL'
                    CREATE UNIQUE INDEX IF NOT EXISTS common_rate_definition_global_identity_unique
                    ON vehicle_pricing_common_rate_definitions (
                        COALESCE(CAST(service_type_id AS TEXT), ''),
                        COALESCE(CAST(vehicle_group_id AS TEXT), ''),
                        LOWER(COALESCE(NULLIF(code, ''), name))
                    )
                    WHERE deleted_at IS NULL
                SQL);
            } else {
                Schema::table(self::COMMON_RATE_TABLE, function (Blueprint $table) {
                    $table->index(
                        ['service_type_id', 'vehicle_group_id', 'code', 'name'],
                        'common_rate_definition_global_identity_index'
                    );
                });
            }
        }
    }
};
