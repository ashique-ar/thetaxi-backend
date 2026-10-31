<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Cache;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('service_types')
            || ! Schema::hasColumn('service_types', 'owner_type')
            || ! Schema::hasColumn('service_types', 'owner_id')) {
            return;
        }

        $this->renameRetiredGlobalCollisionsWithActiveCompanyServices();

        $global = DB::table('service_types')->where('owner_type', '')->where('owner_id', '')
            ->get(['id', 'context', 'code', 'slug']);
        $codes = [];
        $slugs = [];
        foreach ($global as $service) {
            $codes[$this->key($service->context, $service->code)] = $service->id;
            if ($service->slug !== null) {
                $slugs[$this->key($service->context, $service->slug)] = true;
            }
        }

        $owned = DB::table('service_types')->where('owner_type', 'company')
            ->orderByRaw('CASE WHEN deleted_at IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('is_active')->orderByDesc('updated_at')->orderBy('id')->get();

        foreach ($owned as $service) {
            $key = $this->key($service->context, $service->code);
            if (isset($codes[$key])) {
                $this->retireDuplicate($service, $codes[$key], $codes, $slugs);
                continue;
            }

            $slug = $service->slug;
            if ($slug !== null && isset($slugs[$this->key($service->context, $slug)])) {
                $slug = $this->uniqueSlug($service, $slugs);
            }

            DB::table('service_types')->where('id', $service->id)->update([
                'owner_type' => '',
                'owner_id' => '',
                'slug' => $slug,
                'updated_at' => now(),
            ]);
            $codes[$key] = $service->id;
            if ($slug !== null) {
                $slugs[$this->key($service->context, $slug)] = true;
            }
        }

        Cache::put('ref.service-types.v', ((int) Cache::get('ref.service-types.v', 0)) + 1, 86400);
        Cache::forget('sitemap');
    }

    private function renameRetiredGlobalCollisionsWithActiveCompanyServices(): void
    {
        $activeCompanyServices = DB::table('service_types')->where('owner_type', 'company')
            ->whereNull('deleted_at')->where('is_active', true)->get(['context', 'code', 'slug']);
        if ($activeCompanyServices->isEmpty()) {
            return;
        }

        $retiredGlobalServices = DB::table('service_types')->where('owner_type', '')
            ->where('owner_id', '')
            ->where(function ($query): void {
                $query->whereNotNull('deleted_at')->orWhere('is_active', false);
            })->get();

        foreach ($retiredGlobalServices as $service) {
            $hasLiveCompanyCodeMatch = $activeCompanyServices->contains(fn (object $companyService): bool =>
                $companyService->context === $service->context && $companyService->code === $service->code
            );
            $hasLiveCompanySlugMatch = $service->slug !== null && $activeCompanyServices->contains(fn (object $companyService): bool =>
                $companyService->context === $service->context && $companyService->slug === $service->slug
            );
            if (! $hasLiveCompanyCodeMatch && ! $hasLiveCompanySlugMatch) {
                continue;
            }

            $values = ['updated_at' => now()];
            if ($hasLiveCompanyCodeMatch) {
                $values['code'] = $this->uniqueCode($service, []);
            }
            if ($hasLiveCompanySlugMatch) {
                $values['slug'] = $this->uniqueSlug($service, []);
            }
            DB::table('service_types')->where('id', $service->id)->update($values);
        }
    }

    private function retireDuplicate(object $service, string $canonicalId, array &$codes, array &$slugs): void
    {
        $legacyCode = $this->uniqueCode($service, $codes);
        $legacySlug = $this->uniqueSlug($service, $slugs);

        $this->mergeServiceTypeConfiguration($service, $canonicalId);
        $this->moveCorporateAssignments($service->id, $canonicalId);
        if (Schema::hasColumn('service_types', 'parent_service_type_id')) {
            DB::table('service_types')->where('parent_service_type_id', $service->id)->update([
                'parent_service_type_id' => $canonicalId,
                'updated_at' => now(),
            ]);
        }
        foreach (['corporate_transport_routes', 'corporate_service_distance_policies', 'inquiry_service_pages'] as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'service_type_id')) {
                DB::table($tableName)->where('service_type_id', $service->id)
                    ->update(['service_type_id' => $canonicalId]);
            }
        }
        $this->moveServiceConfiguration($service->id, $canonicalId);

        $values = [
            'owner_type' => '',
            'owner_id' => '',
            'code' => $legacyCode,
            'slug' => $legacySlug,
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('service_types', 'is_active')) {
            $values['is_active'] = false;
        }
        DB::table('service_types')->where('id', $service->id)->update($values);

        $codes[$this->key($service->context, $legacyCode)] = $service->id;
        $slugs[$this->key($service->context, $legacySlug)] = true;
    }

    private function mergeServiceTypeConfiguration(object $source, string $canonicalId): void
    {
        $columns = array_values(array_filter(['form_config', 'workflow_config'], fn (string $column): bool =>
            Schema::hasColumn('service_types', $column)
        ));
        if ($columns === []) {
            return;
        }

        $canonical = DB::table('service_types')->where('id', $canonicalId)->first($columns);
        if (! $canonical) {
            return;
        }

        $updates = [];
        foreach ($columns as $column) {
            $sourceConfig = $this->decodeConfiguration($source->{$column} ?? null, $column, (string) $source->id);
            $canonicalConfig = $this->decodeConfiguration($canonical->{$column} ?? null, $column, $canonicalId);
            if ($sourceConfig === []) {
                continue;
            }
            if ($canonicalConfig === []) {
                $updates[$column] = json_encode($sourceConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                continue;
            }

            $merged = $this->mergeConfigValues($canonicalConfig, $sourceConfig);
            if ($merged !== $canonicalConfig) {
                $updates[$column] = json_encode($merged, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            }
        }

        if ($updates !== []) {
            $updates['updated_at'] = now();
            DB::table('service_types')->where('id', $canonicalId)->update($updates);
        }
    }

    private function decodeConfiguration(mixed $value, string $column, string $serviceId): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $decoded = json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
        if ($decoded === null) {
            return [];
        }
        if (! is_array($decoded)) {
            throw new RuntimeException("Cannot merge {$column} for service type {$serviceId}: configuration is not an object.");
        }

        return $decoded;
    }

    private function mergeConfigValues(array $canonical, array $source, ?string $key = null): array
    {
        if (array_is_list($canonical) && array_is_list($source)) {
            if ($canonical === []) {
                return $source;
            }
            if ($key !== 'options') {
                return $canonical;
            }
            foreach ($source as $value) {
                if (! in_array($value, $canonical, true)) {
                    $canonical[] = $value;
                }
            }

            return $canonical;
        }

        foreach ($source as $key => $value) {
            if (! array_key_exists($key, $canonical)) {
                $canonical[$key] = $value;
            } elseif (is_array($canonical[$key]) && is_array($value)) {
                $canonical[$key] = $this->mergeConfigValues($canonical[$key], $value, (string) $key);
            }
        }

        return $canonical;
    }

    private function moveServiceConfiguration(string $sourceId, string $canonicalId): void
    {
        foreach ([
            'service_packages', 'terms_and_conditions', 'vehicle_pricing_common_rate_definitions',
            'vehicle_addons', 'vehicle_pricing_slab_definitions', 'vehicle_pricing_slabs',
            'vehicle_pricing_calculation_definitions', 'price_adjustments', 'km_range_pricing_rules',
            'vehicle_discounts', 'district_pricing_adjustments',
        ] as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'service_type_id')) {
                continue;
            }

            if ($tableName === 'vehicle_pricing_slab_definitions') {
                $this->makeScopedNamesUnique($tableName, $sourceId, $canonicalId, ['name']);
            } elseif ($tableName === 'vehicle_pricing_common_rate_definitions') {
                $this->makeScopedNamesUnique($tableName, $sourceId, $canonicalId, ['name', 'code']);
            }

            DB::table($tableName)->where('service_type_id', $sourceId)->update([
                'service_type_id' => $canonicalId,
            ]);
        }

        $this->moveVehicleGroupServiceSettings($sourceId, $canonicalId);
    }

    private function moveVehicleGroupServiceSettings(string $sourceId, string $canonicalId): void
    {
        $tableName = 'vehicle_group_service_pricing_settings';
        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'service_type_id')) {
            return;
        }

        foreach (DB::table($tableName)->where('service_type_id', $sourceId)->whereNull('deleted_at')->orderBy('id')->get() as $setting) {
            $existing = DB::table($tableName)->where('vehicle_group_id', $setting->vehicle_group_id)
                ->where('service_type_id', $canonicalId)->first();
            if (! $existing) {
                DB::table($tableName)->where('id', $setting->id)->update(['service_type_id' => $canonicalId]);
                continue;
            }

            DB::table($tableName)->where('id', $existing->id)->update([
                'is_inquiry_only' => $existing->is_inquiry_only || $setting->is_inquiry_only,
                'is_hidden' => $existing->is_hidden || $setting->is_hidden,
                // This unique key includes soft-deleted rows, so revive the canonical row instead of reassigning into a conflict.
                'deleted_at' => null,
                'updated_at' => now(),
            ]);
            DB::table($tableName)->where('id', $setting->id)->update([
                'deleted_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function makeScopedNamesUnique(string $tableName, string $sourceId, string $canonicalId, array $columns): void
    {
        foreach (DB::table($tableName)->where('service_type_id', $sourceId)->orderBy('id')->get() as $row) {
            $updates = [];
            foreach ($columns as $column) {
                if (! Schema::hasColumn($tableName, $column) || $row->{$column} === null) {
                    continue;
                }
                $value = $row->{$column};
                if (! DB::table($tableName)->where('service_type_id', $canonicalId)->where($column, $value)->exists()) {
                    continue;
                }

                $suffix = ' (legacy '.substr(str_replace('-', '', (string) $row->id), 0, 8).')';
                $candidate = substr($value, 0, max(1, 100 - strlen($suffix))).$suffix;
                $counter = 1;
                while (DB::table($tableName)->where('service_type_id', $canonicalId)->where($column, $candidate)->exists()) {
                    $extra = ' '.$counter++;
                    $candidate = substr($value, 0, max(1, 100 - strlen($suffix) - strlen($extra))).$suffix.$extra;
                }
                $updates[$column] = $candidate;
            }
            if ($updates !== []) {
                DB::table($tableName)->where('id', $row->id)->update($updates);
            }
        }
    }

    private function moveCorporateAssignments(string $sourceId, string $canonicalId): void
    {
        if (! Schema::hasTable('corporate_service_types')) {
            return;
        }

        foreach (DB::table('corporate_service_types')->where('service_type_id', $sourceId)->whereNull('deleted_at')->get() as $assignment) {
            $existing = DB::table('corporate_service_types')->where('corporate_id', $assignment->corporate_id)
                ->where('service_type_id', $canonicalId)->whereNull('deleted_at')->first();
            if (! $existing) {
                DB::table('corporate_service_types')->where('id', $assignment->id)->update([
                    'service_type_id' => $canonicalId,
                    'updated_at' => now(),
                ]);
                continue;
            }

            if ($assignment->is_active && ! $existing->is_active) {
                DB::table('corporate_service_types')->where('id', $existing->id)->update([
                    'is_active' => true,
                    'updated_at' => now(),
                ]);
            }
            DB::table('corporate_service_types')->where('id', $assignment->id)->update([
                'deleted_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function uniqueCode(object $service, array $codes): string
    {
        $prefix = 'legacy_'.substr(str_replace('-', '', (string) $service->id), 0, 12);
        $code = $prefix;
        $suffix = 1;
        while (isset($codes[$this->key($service->context, $code)])
            || DB::table('service_types')->where('context', $service->context)->where('code', $code)->exists()) {
            $code = $prefix.'_'.$suffix++;
        }

        return $code;
    }

    private function uniqueSlug(object $service, array $slugs): string
    {
        $prefix = 'legacy-'.substr(str_replace('-', '', (string) $service->id), 0, 12);
        $slug = $prefix;
        $suffix = 1;
        while (isset($slugs[$this->key($service->context, $slug)])
            || DB::table('service_types')->where('context', $service->context)->where('slug', $slug)->exists()) {
            $slug = $prefix.'-'.$suffix++;
        }

        return $slug;
    }

    private function key(?string $context, ?string $value): string
    {
        return ($context ?? '')."\0".($value ?? '');
    }

    public function down(): void
    {
        throw new RuntimeException('Installation-wide service types cannot be reassigned to company tenants.');
    }
};
