<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/Support/TenantIndexDropper.php';

return new class extends Migration
{
    public function up(): void
    {
        $tableName = 'companies';
        if (! Schema::hasTable($tableName)) {
            return;
        }

        $this->preserveLegacyDefaultFormLocations();

        foreach (Schema::getIndexes($tableName) as $index) {
            if (($index['primary'] ?? false)
                || ($index['name'] !== 'companies_one_active_default_unique'
                    && ! array_intersect($index['columns'], ['is_default', 'active_default_guard']))) {
                continue;
            }
            TenantIndexDropper::drop($tableName, $index);
        }

        if (Schema::hasColumn($tableName, 'active_default_guard')) {
            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('active_default_guard'));
        }
        if (Schema::hasColumn($tableName, 'is_default')) {
            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('is_default'));
        }
    }

    private function preserveLegacyDefaultFormLocations(): void
    {
        if (! Schema::hasTable('service_types') || ! Schema::hasColumn('service_types', 'form_config')) {
            return;
        }

        $defaultLocation = null;
        if (Schema::hasColumn('companies', 'is_default')) {
            $defaultLocation = DB::table('companies')->where('is_active', true)->where('is_default', true)
                ->whereNull('deleted_at')->first(['id', 'name', 'address', 'latitude', 'longitude']);
        }

        foreach (DB::table('service_types')->whereNotNull('form_config')->orderBy('id')->get(['id', 'form_config']) as $service) {
            $config = json_decode((string) $service->form_config, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($config)) {
                throw new RuntimeException("Cannot preserve default booking locations in service type {$service->id}: form config is not an object.");
            }

            $fieldsKey = isset($config['fields']) && is_array($config['fields']) ? 'fields' : null;
            $fields = $fieldsKey ? $config[$fieldsKey] : $config;
            $changed = false;
            foreach ($fields as $fieldName => &$field) {
                if (! is_array($field) || ! is_array($field['options'] ?? null)) {
                    continue;
                }
                foreach ($field['options'] as $optionIndex => &$option) {
                    if (! is_array($option) || ($option['location_source'] ?? null) !== 'default_company') {
                        continue;
                    }
                    $legacyCompanyId = $option['company_id'] ?? null;
                    $existingLocationId = $option['location_id'] ?? null;
                    if (! empty($legacyCompanyId) && ! empty($existingLocationId)
                        && (string) $legacyCompanyId !== (string) $existingLocationId) {
                        throw new RuntimeException("Cannot preserve conflicting location IDs in service type {$service->id}, field {$fieldName}, option {$optionIndex}.");
                    }
                    $locationId = $existingLocationId ?: $legacyCompanyId ?: $defaultLocation?->id;
                    $location = $locationId
                        ? DB::table('companies')->where('id', $locationId)->first(['id', 'name', 'address', 'latitude', 'longitude', 'is_active', 'deleted_at'])
                        : null;
                    if ($locationId && ! $location) {
                        throw new RuntimeException("Cannot preserve missing saved location {$locationId} in service type {$service->id}, field {$fieldName}, option {$optionIndex}.");
                    }
                    if ($location && $location->is_active && $location->deleted_at === null && $location->latitude !== null && $location->longitude !== null) {
                        $option['location_source'] = 'saved_location';
                        $option['location_id'] = $location->id;
                        if (empty($option['address'])) {
                            $option['address'] = $location->address ?? $location->name;
                        }
                        $option['latitude'] = (float) $location->latitude;
                        $option['longitude'] = (float) $location->longitude;
                        unset($option['company_id']);
                    } else {
                        $option['location_source'] = 'configured';
                        if ($location && empty($option['address'])) {
                            $option['address'] = $location->address ?? $location->name;
                        }
                        if ($location && $location->latitude !== null && $location->longitude !== null) {
                            if (! isset($option['latitude']) || $option['latitude'] === '') {
                                $option['latitude'] = (float) $location->latitude;
                            }
                            if (! isset($option['longitude']) || $option['longitude'] === '') {
                                $option['longitude'] = (float) $location->longitude;
                            }
                        }
                        unset($option['company_id'], $option['location_id']);
                    }
                    $changed = true;
                }
                unset($option);
            }
            unset($field);

            if ($changed) {
                if ($fieldsKey) {
                    $config[$fieldsKey] = $fields;
                } else {
                    $config = $fields;
                }
                DB::table('service_types')->where('id', $service->id)->update([
                    'form_config' => json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Companies cannot regain a default-tenant designation.');
    }
};
