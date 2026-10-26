<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $services = DB::table('service_types')->whereNotNull('form_config')->whereNull('deleted_at')->get([
            'id', 'code', 'context', 'owner_type', 'owner_id', 'parent_service_type_id', 'form_config',
        ])->keyBy('id');
        $publicServices = $services->filter(static fn ($service) =>
            $service->context === 'public' && empty($service->owner_type) && empty($service->owner_id)
        )->keyBy('code');
        $updated = false;

        $fieldsFor = static function (array $config): array {
            $fields = is_array($config['fields'] ?? null) ? $config['fields'] : $config;
            return array_filter($fields, 'is_array');
        };
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
        $matchDateField = static function (array $referenceFields, ?string $referenceName, array $targetFields): ?string {
            if (!$referenceName || !isset($referenceFields[$referenceName])) {
                return null;
            }
            $reference = $referenceFields[$referenceName];
            if (($targetFields[$referenceName]['type'] ?? null) === 'date') {
                return $referenceName;
            }
            $submitAs = $reference['submit_as'] ?? null;
            foreach ($targetFields as $name => $field) {
                if (is_string($submitAs) && $submitAs !== ''
                    && ($field['type'] ?? null) === 'date'
                    && ($field['submit_as'] ?? null) === $submitAs) {
                    return (string) $name;
                }
            }
            foreach ($targetFields as $name => $field) {
                if (($field['type'] ?? null) === 'date'
                    && isset($reference['order'], $field['order'])
                    && (int) $field['order'] === (int) $reference['order']) {
                    return (string) $name;
                }
            }

            return null;
        };

        foreach ($services as $service) {
            if (empty($service->owner_type) && empty($service->owner_id)) {
                continue;
            }

            $parent = $services->get($service->parent_service_type_id) ?? $publicServices->get($service->code);
            if (!$parent) {
                continue;
            }
            $config = json_decode((string) $service->form_config, true) ?: [];
            $parentConfig = json_decode((string) $parent->form_config, true) ?: [];
            $fieldsKey = is_array($config['fields'] ?? null) ? 'fields' : null;
            $fields = $fieldsFor($config);
            $parentFields = $fieldsFor($parentConfig);
            $mappings = $config['field_mappings']['dates'] ?? [];
            $parentMappings = $parentConfig['field_mappings']['dates'] ?? [];

            $parentEnd = $findDateField($parentFields, $parentMappings['to_date'] ?? null);
            $end = $findDateField($fields, $mappings['to_date'] ?? null)
                ?? $matchDateField($parentFields, $parentEnd, $fields);
            if (!$parentEnd || !$end || !is_numeric($parentFields[$parentEnd]['default_date_offset_days'] ?? null)) {
                continue;
            }

            $changed = false;
            if (!is_numeric($fields[$end]['default_date_offset_days'] ?? null)) {
                $fields[$end]['default_date_offset_days'] = $parentFields[$parentEnd]['default_date_offset_days'];
                $changed = true;
            }

            $source = $findDateField($fields, $fields[$end]['default_date_from'] ?? null);
            if (!$source) {
                $source = $findDateField($fields, $mappings['from_date'] ?? null);
            }
            if (!$source) {
                $parentSource = $findDateField(
                    $parentFields,
                    $parentFields[$parentEnd]['default_date_from'] ?? ($parentMappings['from_date'] ?? null)
                );
                $source = $matchDateField($parentFields, $parentSource, $fields);
            }
            if (!$source) {
                continue;
            }
            if (($fields[$end]['default_date_from'] ?? null) !== $source) {
                $fields[$end]['default_date_from'] = $source;
                $changed = true;
            }
            if (!$changed) {
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
        // Retain copied form defaults; admins may have changed them since migration.
    }
};
