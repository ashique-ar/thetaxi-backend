<?php

use App\Models\Service\ServiceType;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $this->markCompanyLocationOptions(true);
    }

    public function down(): void
    {
        $this->markCompanyLocationOptions(false);
    }

    private function markCompanyLocationOptions(bool $mark): void
    {
        ServiceType::query()
            ->whereIn('code', ['self_drive', 'with_driver'])
            ->each(function (ServiceType $serviceType) use ($mark): void {
                $config = $serviceType->form_config;
                if (!is_array($config)) {
                    return;
                }

                $fieldsKey = is_array($config['fields'] ?? null) ? 'fields' : null;
                $fields = $fieldsKey ? $config[$fieldsKey] : $config;
                $changed = false;

                foreach ($fields as &$field) {
                    if (!is_array($field) || !is_array($field['options'] ?? null)) {
                        continue;
                    }

                    foreach ($field['options'] as &$option) {
                        if (($option['value'] ?? null) !== 'CASONS_HQ') {
                            continue;
                        }

                        if ($mark) {
                            $option['location_source'] = 'default_company';
                        } else {
                            unset($option['location_source']);
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

                    $serviceType->form_config = $config;
                    $serviceType->saveQuietly();
                }
            });
    }
};
