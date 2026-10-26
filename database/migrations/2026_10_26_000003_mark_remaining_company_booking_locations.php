<?php

use App\Models\Service\ServiceType;
use App\Models\Company;
use App\Models\PredefinedLocation;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $company = Company::getDefaultCompany();
        if (!$company || $company->latitude === null || $company->longitude === null) {
            return;
        }

        ServiceType::query()->each(function (ServiceType $serviceType) use ($company): void {
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

                $fieldUsesCompany = false;
                foreach ($field['options'] as &$option) {
                    if (($option['value'] ?? null) === 'CASONS_HQ') {
                        $option['location_source'] = 'default_company';
                    }
                    if (($option['location_source'] ?? null) !== 'default_company') {
                        continue;
                    }

                    $option['latitude'] = (float) $company->latitude;
                    $option['longitude'] = (float) $company->longitude;
                    $option['address'] = $company->address ?? $option['address'] ?? $option['label'] ?? '';
                    $fieldUsesCompany = true;
                    $changed = true;
                }
                unset($option);

                if ($fieldUsesCompany) {
                    $defaultCompanyOption = collect($field['options'])->firstWhere('location_source', 'default_company');
                    if (empty($field['default_option_value']) && $defaultCompanyOption) {
                        $field['default_option_value'] = $defaultCompanyOption['value'];
                    }
                }

                if ($fieldUsesCompany && !empty($field['default_option_value'])) {
                    $defaultOption = collect($field['options'])->firstWhere('value', $field['default_option_value']);
                    if ($defaultOption) {
                        $field['default'] = $defaultOption['address'] ?? $field['default'] ?? '';
                        $field['default_lat'] = (string) ($defaultOption['latitude'] ?? '');
                        $field['default_lng'] = (string) ($defaultOption['longitude'] ?? '');
                    }
                } elseif ($fieldUsesCompany && $defaultCompanyOption) {
                    $field['default'] = $company->address ?? $field['default'] ?? '';
                    $field['default_lat'] = (string) $company->latitude;
                    $field['default_lng'] = (string) $company->longitude;
                }
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

        PredefinedLocation::query()->where('code', 'CASONS_HQ')->update([
            'address' => $company->address,
            'latitude' => $company->latitude,
            'longitude' => $company->longitude,
        ]);
    }

    public function down(): void
    {
        ServiceType::query()
            ->where('code', 'wedding_hire')
            ->each(function (ServiceType $serviceType): void {
                $config = $serviceType->form_config;
                if (!is_array($config)) {
                    return;
                }

                $fieldsKey = is_array($config['fields'] ?? null) ? 'fields' : null;
                $fields = $fieldsKey ? $config[$fieldsKey] : $config;
                $changed = false;

                foreach ($fields as &$field) {
                    if (!is_array($field['options'] ?? null)) {
                        continue;
                    }

                    foreach ($field['options'] as &$option) {
                        if (($option['value'] ?? null) !== 'CASONS_HQ') {
                            continue;
                        }

                        unset($option['location_source']);
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
