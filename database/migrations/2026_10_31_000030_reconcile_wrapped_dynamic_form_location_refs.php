<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('service_types') || ! Schema::hasColumn('service_types', 'form_config')) {
            return;
        }

        foreach (DB::table('service_types')->whereNotNull('form_config')->orderBy('id')->get(['id', 'form_config']) as $service) {
            $config = json_decode((string) $service->form_config, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($config)) {
                throw new RuntimeException("Cannot reconcile saved locations in service type {$service->id}: form config is not an object.");
            }

            $fieldsKey = isset($config['fields']) && is_array($config['fields']) ? 'fields' : null;
            $fields = $fieldsKey ? $config[$fieldsKey] : $config;
            $changed = false;

            foreach ($fields as $fieldName => &$field) {
                if (! is_array($field) || ! is_array($field['options'] ?? null)) {
                    continue;
                }
                foreach ($field['options'] as $optionIndex => &$option) {
                    if (! is_array($option)) {
                        continue;
                    }

                    $source = $option['location_source'] ?? null;
                    $legacyId = $option['company_id'] ?? null;
                    $locationId = $option['location_id'] ?? null;
                    if (! array_key_exists('company_id', $option) && ! in_array($source, ['company', 'default_company'], true)) {
                        continue;
                    }
                    if (! empty($legacyId) && ! empty($locationId) && (string) $legacyId !== (string) $locationId) {
                        throw new RuntimeException("Cannot reconcile conflicting location IDs in service type {$service->id}, field {$fieldName}, option {$optionIndex}.");
                    }

                    $locationId = $locationId ?: $legacyId;
                    $location = $locationId && Schema::hasTable('companies')
                        ? DB::table('companies')->where('id', $locationId)->first(['id', 'name', 'address', 'latitude', 'longitude', 'is_active', 'deleted_at'])
                        : null;
                    if ($locationId && ! $location) {
                        throw new RuntimeException("Cannot preserve missing saved location {$locationId} in service type {$service->id}, field {$fieldName}, option {$optionIndex}.");
                    }

                    if ($location && $location->is_active && $location->deleted_at === null && $location->latitude !== null && $location->longitude !== null) {
                        $option['location_id'] = $location->id;
                        $option['location_source'] = 'saved_location';
                        $option['latitude'] = (float) $location->latitude;
                        $option['longitude'] = (float) $location->longitude;
                        if (empty($option['address'])) {
                            $option['address'] = $location->address ?? $location->name;
                        }
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
                        unset($option['location_id']);
                    }
                    unset($option['company_id']);
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
        throw new RuntimeException('Dynamic form saved locations cannot be converted back to company scope.');
    }
};
