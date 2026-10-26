<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $services = DB::table('service_types')->whereNotNull('form_config')->whereNull('deleted_at')->get([
            'id', 'code', 'context', 'owner_type', 'owner_id', 'parent_service_type_id', 'form_config',
        ]);
        $publicServices = $services->filter(static fn ($service) =>
            $service->context === 'public' && empty($service->owner_type) && empty($service->owner_id)
        )->keyBy('code');
        $updated = false;
        $widthKeys = ['width', 'tablet_width', 'mobile_width'];

        $fieldsFor = static function (array $config): array {
            $fields = is_array($config['fields'] ?? null) ? $config['fields'] : $config;
            return array_filter($fields, 'is_array');
        };

        foreach ($services as $service) {
            // Linked service types retain their explicit responsive overrides.
            if ($service->owner_type !== 'company' || !empty($service->parent_service_type_id)) {
                continue;
            }

            $parent = $publicServices->get($service->code);
            if (!$parent) {
                continue;
            }

            $config = json_decode((string) $service->form_config, true) ?: [];
            $parentConfig = json_decode((string) $parent->form_config, true) ?: [];
            $fieldsKey = is_array($config['fields'] ?? null) ? 'fields' : null;
            $fields = $fieldsFor($config);
            $parentFields = $fieldsFor($parentConfig);
            $changed = false;

            foreach ($fields as $name => &$field) {
                if (!isset($field['type'])) {
                    continue;
                }

                $reference = $parentFields[$name] ?? null;
                if (($reference['type'] ?? null) !== $field['type']) {
                    $reference = null;
                    foreach ($parentFields as $parentField) {
                        if (($parentField['type'] ?? null) === $field['type']
                            && !empty($field['submit_as'])
                            && ($parentField['submit_as'] ?? null) === $field['submit_as']) {
                            $reference = $parentField;
                            break;
                        }
                    }
                }
                if (!$reference) {
                    foreach ($parentFields as $parentField) {
                        if (($parentField['type'] ?? null) === $field['type']
                            && isset($field['order'], $parentField['order'])
                            && (int) $field['order'] === (int) $parentField['order']) {
                            $reference = $parentField;
                            break;
                        }
                    }
                }
                if (!$reference) {
                    continue;
                }

                foreach ($widthKeys as $widthKey) {
                    if (isset($reference[$widthKey]) && ($field[$widthKey] ?? null) !== $reference[$widthKey]) {
                        $field[$widthKey] = $reference[$widthKey];
                        $changed = true;
                    }
                }
            }
            unset($field);

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
        // Keep synced widths because administrators may have changed them since migration.
    }
};
