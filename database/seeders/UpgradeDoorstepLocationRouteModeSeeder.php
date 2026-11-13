<?php

namespace Database\Seeders;

use App\Services\DynamicServiceConfigurationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UpgradeDoorstepLocationRouteModeSeeder extends Seeder
{
    public function run(): void
    {
        $updatedOptions = 0;

        foreach ([
            'service_types' => 'form_config',
            'service_form_configs' => 'config',
        ] as $table => $configColumn) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, $configColumn)) {
                continue;
            }

            foreach (DB::table($table)->orderBy('id')->get(['id', $configColumn]) as $row) {
                $config = $this->decodeConfig($row->{$configColumn});
                if (!$config || !$this->markDoorstepOptionsSeparate($config)) {
                    continue;
                }

                DB::table($table)->where('id', $row->id)->update([
                    $configColumn => json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);
                $updatedOptions++;
            }
        }

        if ($updatedOptions > 0) {
            app(DynamicServiceConfigurationService::class)->clearCache();
        }

        $this->command?->info("Marked {$updatedOptions} My Doorstep location option(s) as separate pickup and drop-off.");
    }

    private function decodeConfig(mixed $rawConfig): ?array
    {
        if (is_array($rawConfig)) {
            return $rawConfig;
        }

        if (!is_string($rawConfig) || trim($rawConfig) === '') {
            return null;
        }

        $config = json_decode($rawConfig, true);
        return is_array($config) ? $config : null;
    }

    private function markDoorstepOptionsSeparate(array &$config): bool
    {
        $fieldsKey = is_array($config['fields'] ?? null) ? 'fields' : null;
        $fields = $fieldsKey ? $config['fields'] : $config;
        $changed = false;

        foreach ($fields as &$field) {
            if (!is_array($field)
                || ($field['type'] ?? null) !== 'location'
                || !is_array($field['options'] ?? null)) {
                continue;
            }

            foreach ($field['options'] as &$option) {
                if (!is_array($option)
                    || stripos((string) ($option['label'] ?? ''), 'my doorstep') === false
                    || ($option['route_mode'] ?? null) === 'separate') {
                    continue;
                }

                $option['route_mode'] = 'separate';
                $changed = true;
            }
            unset($option);
        }
        unset($field);

        if ($changed && $fieldsKey) {
            $config['fields'] = $fields;
        } elseif ($changed) {
            $config = $fields;
        }

        return $changed;
    }
}
