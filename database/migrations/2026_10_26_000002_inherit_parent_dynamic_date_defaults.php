<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $services = DB::table('service_types')->whereNotNull('form_config')->whereNull('deleted_at')->get([
            'id', 'parent_service_type_id', 'form_config',
        ])->keyBy('id');
        $updated = false;

        foreach ($services as $service) {
            $parent = $services->get($service->parent_service_type_id);
            if (!$parent) {
                continue;
            }

            $config = json_decode((string) $service->form_config, true) ?: [];
            $parentConfig = json_decode((string) $parent->form_config, true) ?: [];
            $fieldsKey = is_array($config['fields'] ?? null) ? 'fields' : null;
            $parentFieldsKey = is_array($parentConfig['fields'] ?? null) ? 'fields' : null;
            $fields = $fieldsKey ? $config[$fieldsKey] : $config;
            $parentFields = $parentFieldsKey ? $parentConfig[$parentFieldsKey] : $parentConfig;
            $mappings = $config['field_mappings']['dates'] ?? [];
            $parentMappings = $parentConfig['field_mappings']['dates'] ?? [];
            $findDateField = static function (array $fields, $mapping): ?string {
                if (!is_string($mapping) || $mapping === '') {
                    return null;
                }
                if (isset($fields[$mapping]) && ($fields[$mapping]['type'] ?? null) === 'date') {
                    return $mapping;
                }
                foreach ($fields as $name => $field) {
                    if (($field['type'] ?? null) === 'date' && ($field['submit_as'] ?? null) === $mapping) {
                        return (string) $name;
                    }
                }

                return null;
            };

            $endField = $findDateField($fields, $mappings['to_date'] ?? null);
            $parentEndField = $findDateField($parentFields, $parentMappings['to_date'] ?? null);
            if (!$endField || !$parentEndField
                || !is_numeric($parentFields[$parentEndField]['default_date_offset_days'] ?? null)) {
                continue;
            }

            $changed = false;
            if (!is_numeric($fields[$endField]['default_date_offset_days'] ?? null)) {
                $fields[$endField]['default_date_offset_days'] = $parentFields[$parentEndField]['default_date_offset_days'];
                $changed = true;
            }

            $sourceField = $findDateField($fields, $fields[$endField]['default_date_from'] ?? null);
            if (!$sourceField) {
                $sourceField = $findDateField($fields, $mappings['from_date'] ?? null);
                if ($sourceField) {
                    $fields[$endField]['default_date_from'] = $sourceField;
                    $changed = true;
                }
            }
            if (!$changed || !$sourceField) {
                continue;
            }

            if ($fieldsKey) {
                $config[$fieldsKey] = $fields;
            } else {
                foreach ($fields as $name => $field) {
                    $config[$name] = $field;
                }
            }

            DB::table('service_types')->where('id', $service->id)->update([
                'form_config' => json_encode($config, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
            $updated = true;
        }

        if ($updated) {
            app(\App\Services\DynamicServiceConfigurationService::class)->clearCache();
        }
    }

    public function down(): void
    {
        // Keep inherited values; administrators may have edited these fields after deployment.
    }
};
