<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $updated = false;
        DB::table('service_types')
            ->whereNotNull('form_config')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'form_config'])
            ->each(function ($service) use (&$updated): void {
                $config = json_decode((string) $service->form_config, true) ?: [];
                $fields = array_filter($config, 'is_array');
                $mappings = $config['field_mappings']['dates'] ?? [];
                $findDateField = static function ($mapping) use ($fields): ?string {
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

                $sourceField = $findDateField($mappings['from_date'] ?? null);
                $endField = $findDateField($mappings['to_date'] ?? null);
                if (!$sourceField || !$endField || !isset($config[$endField]['default_date_offset_days'])) {
                    return;
                }

                if (filled($config[$endField]['default_date_from'] ?? null)) {
                    return;
                }

                $config[$endField]['default_date_from'] = $sourceField;
                DB::table('service_types')->where('id', $service->id)->update([
                    'form_config' => json_encode($config, JSON_UNESCAPED_SLASHES),
                    'updated_at' => now(),
                ]);
                $updated = true;
            });

        if ($updated) {
            app(\App\Services\DynamicServiceConfigurationService::class)->clearCache();
        }
    }

    public function down(): void
    {
        // Keep admin-managed form settings when rolling back application code.
    }
};
