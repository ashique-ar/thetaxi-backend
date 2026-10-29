<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['self_drive', 'with_driver'] as $serviceCode) {
            if (Schema::hasTable('service_types')) {
                DB::table('service_types')->where('code', $serviceCode)->whereNull('deleted_at')
                    ->orderBy('id')->get(['id', 'form_config'])->each(function ($service): void {
                        $config = json_decode((string) $service->form_config, true) ?: [];
                        if ($this->addDurationField($config)) {
                            DB::table('service_types')->where('id', $service->id)->update([
                                'form_config' => json_encode($config, JSON_UNESCAPED_SLASHES),
                                'updated_at' => now(),
                            ]);
                        }
                    });
            }

            if (Schema::hasTable('service_form_configs')) {
                $row = DB::table('service_form_configs')->where('service_code', $serviceCode)->first(['id', 'config']);
                if ($row) {
                    $config = json_decode((string) $row->config, true) ?: [];
                    if ($this->addDurationField($config)) {
                        DB::table('service_form_configs')->where('id', $row->id)->update([
                            'config' => json_encode($config, JSON_UNESCAPED_SLASHES),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }
        }

        app(\App\Services\DynamicServiceConfigurationService::class)->clearCache();
    }

    private function addDurationField(array &$config): bool
    {
        $wrapped = isset($config['fields']) && is_array($config['fields']);
        $fields = $wrapped ? $config['fields'] : $config;
        $startField = ($fields['date']['type'] ?? null) === 'date' ? 'date' : null;
        $endField = ($fields['dropoff_date']['type'] ?? null) === 'date' ? 'dropoff_date' : null;
        if (!$startField || !$endField || isset($fields['duration_days'])) {
            return false;
        }

        $fields['duration_days'] = [
            'type' => 'number',
            'label' => 'Rental Days',
            'required' => true,
            'order' => max(array_map(static fn ($field) => (int) ($field['order'] ?? 0), array_filter($fields, 'is_array'))) + 1,
            'submit_as' => 'duration_days',
            'sync_from' => $startField,
            'sync_to' => $endField,
            'validation' => ['min' => 1],
        ];

        if ($wrapped) {
            $config['fields'] = $fields;
        } else {
            $config = $fields;
        }

        return true;
    }

    public function down(): void
    {
        // Preserve service form configuration managed by administrators.
    }
};
