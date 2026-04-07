<?php

namespace App\Services;

use App\Models\Service\ServiceType;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ServiceTypeCloneService
{
    public function clone(ServiceType $source, array $targetAttributes, ?string $userId = null): ServiceType
    {
        return DB::transaction(function () use ($source, $targetAttributes, $userId) {
            $target = ServiceType::create(array_merge(
                Arr::except($source->toArray(), [
                    'id',
                    'created_at',
                    'updated_at',
                    'deleted_at',
                    'created_user_id',
                    'updated_user_id',
                ]),
                [
                    'code' => $targetAttributes['code'],
                    'name' => $targetAttributes['name'] ?? ($source->name . ' Copy'),
                    'slug' => $targetAttributes['slug'] ?? $this->makeUniqueSlug(
                        $targetAttributes['code'],
                        $targetAttributes['context'] ?? $source->context,
                        $targetAttributes['owner_type'] ?? $source->owner_type,
                        $targetAttributes['owner_id'] ?? $source->owner_id
                    ),
                    'context' => $targetAttributes['context'] ?? $source->context,
                    'owner_type' => $targetAttributes['owner_type'] ?? $source->owner_type,
                    'owner_id' => $targetAttributes['owner_id'] ?? $source->owner_id,
                    'parent_service_type_id' => $source->id,
                    'created_user_id' => $userId,
                    'updated_user_id' => $userId,
                ]
            ));

            $packageMap = $this->cloneTableWithServiceType('service_packages', $source->id, $target->id, $userId, [
                'code' => fn (string $value) => $this->makeUniqueCode('service_packages', 'code', $value, $target->code),
            ]);

            $this->cloneTableWithServiceType(
                'service_package_rates',
                $source->id,
                $target->id,
                $userId,
                [],
                ['service_package_id' => $packageMap],
                'service_packages',
                'service_package_id'
            );

            $this->cloneTableWithServiceType(
                'service_package_return_rules',
                $source->id,
                $target->id,
                $userId,
                [],
                ['service_package_id' => $packageMap],
                'service_packages',
                'service_package_id'
            );

            $this->cloneTableWithServiceType('terms_and_conditions', $source->id, $target->id, $userId, [
                'slug' => fn (?string $value) => $value ? $this->makeUniqueCode('terms_and_conditions', 'slug', $value, $target->code) : null,
            ]);

            $commonRateMap = $this->cloneTableWithServiceType('vehicle_pricing_common_rate_definitions', $source->id, $target->id, $userId, [
                'name' => fn (string $value) => $this->makeScopedDuplicateLabel($value, $target->name),
                'code' => fn (string $value) => $this->makeUniqueCommonRateCode($value, $target->id),
            ]);

            $this->cloneTableWithServiceType('vehicle_group_common_rate_pricing', $source->id, $target->id, $userId, [], [
                'common_rate_definition_id' => $commonRateMap,
            ], 'vehicle_pricing_common_rate_definitions', 'common_rate_definition_id');

            $addonMap = $this->cloneTableWithServiceType('vehicle_addons', $source->id, $target->id, $userId);

            $this->cloneAddonDependencies($addonMap, $userId);

            $slabDefinitionMap = $this->cloneTableWithServiceType('vehicle_pricing_slab_definitions', $source->id, $target->id, $userId, [
                'name' => fn (string $value) => $this->makeScopedDuplicateLabel($value, $target->name),
            ]);
            $this->cloneTableWithServiceType('vehicle_group_pricing', $source->id, $target->id, $userId, [], [
                'slab_definition_id' => $slabDefinitionMap,
            ], 'vehicle_pricing_slab_definitions', 'slab_definition_id');
            $this->cloneTableWithServiceType('vehicle_pricing_slabs', $source->id, $target->id, $userId);
            $this->cloneTableWithServiceType('vehicle_pricing_calculation_definitions', $source->id, $target->id, $userId, [
                'name' => fn (string $value) => $this->makeScopedDuplicateLabel($value, $target->name),
            ], [], null, null, 'created_by', 'updated_by');
            $this->cloneTableWithServiceType('price_adjustments', $source->id, $target->id, $userId, [
                'name' => fn (string $value) => $this->makeScopedDuplicateLabel($value, $target->name),
            ]);
            $this->cloneTableWithServiceType('km_range_pricing_rules', $source->id, $target->id, $userId, [
                'name' => fn (string $value) => $this->makeScopedDuplicateLabel($value, $target->name),
            ]);
            $this->cloneTableWithServiceType('vehicle_discounts', $source->id, $target->id, $userId, [
                'code' => fn (string $value) => $this->makeUniqueCode('vehicle_discounts', 'code', $value, $target->code),
                'name' => fn (string $value) => $this->makeScopedDuplicateLabel($value, $target->name),
            ]);
            $this->cloneTableWithServiceType('district_pricing_adjustments', $source->id, $target->id, $userId, [], [
                'service_package_id' => $packageMap,
            ]);
            $this->cloneTableWithServiceType('inquiry_service_pages', $source->id, $target->id, $userId, [
                'slug' => fn (?string $value) => $value ? $this->makeUniqueCode('inquiry_service_pages', 'slug', $value, $target->code) : null,
                'code' => fn (?string $value) => $value ? $this->makeUniqueCode('inquiry_service_pages', 'code', $value, $target->code) : null,
            ]);

            return $target->fresh();
        });
    }

    private function cloneTableWithServiceType(
        string $table,
        string $sourceServiceTypeId,
        string $targetServiceTypeId,
        ?string $userId,
        array $transformers = [],
        array $foreignKeyMaps = [],
        ?string $joinTable = null,
        ?string $joinForeignKey = null,
        string $createdByColumn = 'created_user_id',
        string $updatedByColumn = 'updated_user_id'
    ): array {
        if (!Schema::hasTable($table)) {
            return [];
        }

        $query = DB::table($table);

        if ($joinTable && $joinForeignKey) {
            if (!Schema::hasTable($joinTable)) {
                return [];
            }

            $query->join($joinTable, "{$table}.{$joinForeignKey}", '=', "{$joinTable}.id")
                ->where("{$joinTable}.service_type_id", $sourceServiceTypeId)
                ->select("{$table}.*");
        } else {
            if (!Schema::hasColumn($table, 'service_type_id')) {
                return [];
            }

            $query->where('service_type_id', $sourceServiceTypeId);
        }

        $rows = $query->get();
        $idMap = [];

        foreach ($rows as $row) {
            $payload = (array) $row;
            $oldId = $payload['id'] ?? null;
            $payload['id'] = (string) Str::uuid();

            if (array_key_exists('service_type_id', $payload)) {
                $payload['service_type_id'] = $targetServiceTypeId;
            }

            foreach ($foreignKeyMaps as $column => $map) {
                if (!array_key_exists($column, $payload)) {
                    continue;
                }

                $originalValue = $payload[$column];
                if ($originalValue !== null && $originalValue !== '' && isset($map[$originalValue])) {
                    $payload[$column] = $map[$originalValue];
                }
            }

            foreach ($transformers as $column => $transformer) {
                if (array_key_exists($column, $payload)) {
                    $payload[$column] = $transformer($payload[$column]);
                }
            }

            if (Schema::hasColumn($table, 'created_at')) {
                $payload['created_at'] = now();
            }

            if (Schema::hasColumn($table, 'updated_at')) {
                $payload['updated_at'] = now();
            }

            if ($userId !== null && array_key_exists($createdByColumn, $payload)) {
                $payload[$createdByColumn] = $userId;
            }

            if ($userId !== null && array_key_exists($updatedByColumn, $payload)) {
                $payload[$updatedByColumn] = $userId;
            }

            unset($payload['deleted_at']);

            DB::table($table)->insert($payload);

            if ($oldId) {
                $idMap[$oldId] = $payload['id'];
            }
        }

        return $idMap;
    }

    private function cloneAddonDependencies(array $addonMap, ?string $userId): void
    {
        if (empty($addonMap)) {
            return;
        }

        $rows = DB::table('vehicle_addon_dependencies')
            ->whereIn('parent_addon_id', array_keys($addonMap))
            ->orWhereIn('required_addon_id', array_keys($addonMap))
            ->get();

        foreach ($rows as $row) {
            $payload = (array) $row;
            $payload['id'] = (string) Str::uuid();
            $payload['parent_addon_id'] = $addonMap[$payload['parent_addon_id']] ?? $payload['parent_addon_id'];
            $payload['required_addon_id'] = $addonMap[$payload['required_addon_id']] ?? $payload['required_addon_id'];
            $payload['created_user_id'] = $userId;
            $payload['updated_user_id'] = $userId;
            $payload['created_at'] = now();
            $payload['updated_at'] = now();
            unset($payload['deleted_at']);

            DB::table('vehicle_addon_dependencies')->insert($payload);
        }
    }

    private function makeUniqueSlug(string $code, string $context, string $ownerType, string $ownerId): string
    {
        $slug = Str::slug($code, '-');
        $candidate = $slug;
        $counter = 1;

        while (
            ServiceType::where('slug', $candidate)
                ->where('context', $context)
                ->where('owner_type', $ownerType)
                ->where('owner_id', $ownerId)
                ->exists()
        ) {
            $candidate = Str::limit($slug, 240, '') . '-' . $counter;
            $counter++;
        }

        return $candidate;
    }

    private function makeUniqueCode(string $table, string $column, string $value, string $suffix): string
    {
        $base = Str::limit($value . '_' . Str::slug($suffix, '_'), 95, '');
        $candidate = $base;
        $counter = 1;

        while (DB::table($table)->where($column, $candidate)->exists()) {
            $candidate = Str::limit($base, 90, '') . '_' . $counter;
            $counter++;
        }

        return $candidate;
    }

    private function makeUniqueCommonRateCode(string $value, string $serviceTypeId): string
    {
        $base = Str::limit($value . '_' . Str::substr(str_replace('-', '', $serviceTypeId), 0, 8), 95, '');
        $candidate = $base;
        $counter = 1;

        while (DB::table('vehicle_pricing_common_rate_definitions')->where('code', $candidate)->exists()) {
            $candidate = Str::limit($base, 90, '') . '_' . $counter;
            $counter++;
        }

        return $candidate;
    }

    private function makeScopedDuplicateLabel(string $value, string $targetName): string
    {
        return Str::limit(trim($value . ' - ' . $targetName), 255, '');
    }
}
