<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\PredefinedLocation;
use App\Models\Service\ServiceFormConfig;
use App\Models\Service\ServiceType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class CasonsCompanyLocationDefaultsUpdateSeeder extends Seeder
{
    public function run(): void
    {
        $casons = Company::query()->where('domain', 'casons.lk')->first();
        $company = Company::getDefaultCompany();

        if (!$casons || !$company || $casons->id !== $company->id || $company->latitude === null || $company->longitude === null) {
            $this->command?->warn('Casons location update skipped: Casons and its default company map location must exist.');
            return;
        }

        if (str_starts_with(mb_strtolower(trim((string) $company->address)), 'pinned location')) {
            $company->address = $company->name;
            $company->saveQuietly();
        }

        $updated = 0;
        foreach (['self_drive', 'with_driver', 'wedding_hire', 'ride_now', 'airport_transfers'] as $code) {
            $casonsTypes = ServiceType::query()
                ->where('code', $code)
                ->where('context', 'public')
                ->where('owner_type', 'company')
                ->where('owner_id', $casons->id)
                ->get();

            if ($casonsTypes->isNotEmpty()) {
                foreach ($casonsTypes as $serviceType) {
                    $updated += $this->updateServiceType($serviceType, $company) ? 1 : 0;
                }
            } elseif ($casons->id === $company->id) {
                ServiceType::query()
                    ->where('code', $code)
                    ->where('context', 'public')
                    ->where(function ($query): void {
                        $query->whereNull('owner_type')->orWhere('owner_type', '');
                    })
                    ->where(function ($query): void {
                        $query->whereNull('owner_id')->orWhere('owner_id', '');
                    })
                    ->get()
                    ->each(function (ServiceType $serviceType) use ($company, &$updated): void {
                        $updated += $this->updateServiceType($serviceType, $company) ? 1 : 0;
                    });
            }

            if (Schema::hasTable('service_form_configs')) {
                $formConfig = ServiceFormConfig::query()->where('service_code', $code)->first();
                $config = $formConfig?->config ?? [];
                if ($formConfig && $this->updateFields($config, $company)) {
                    $formConfig->config = $config;
                    $formConfig->saveQuietly();
                    $updated++;
                }
            }
        }

        PredefinedLocation::query()->where('code', 'CASONS_HQ')->update([
            'name' => $company->name,
            'address' => $company->address ?? $company->name,
            'city' => $company->city,
            'latitude' => $company->latitude,
            'longitude' => $company->longitude,
        ]);

        app(\App\Services\DynamicServiceConfigurationService::class)->clearCache();
        $this->command?->info("Casons company-location defaults updated ({$updated} existing form configs).");
    }

    private function updateServiceType(ServiceType $serviceType, Company $company): bool
    {
        $config = $serviceType->form_config;
        if (!is_array($config)) {
            return false;
        }

        if (!$this->updateFields($config, $company)) {
            return false;
        }

        $serviceType->form_config = $config;
        $serviceType->saveQuietly();

        return true;
    }

    private function updateFields(array &$config, Company $company): bool
    {
        $fieldsKey = is_array($config['fields'] ?? null) ? 'fields' : null;
        $fields = $fieldsKey ? $config[$fieldsKey] : $config;
        $changed = false;

        foreach ($fields as $fieldName => &$field) {
            if (!is_array($field) || ($field['type'] ?? null) !== 'location') {
                continue;
            }

            $options = is_array($field['options'] ?? null) ? $field['options'] : [];
            $optionIndex = collect($options)->search(static fn ($option): bool =>
                ($option['value'] ?? null) === 'CASONS_HQ'
                || (($option['location_source'] ?? null) === 'company'
                    && ($option['company_id'] ?? null) === $company->id)
                || ($option['location_source'] ?? null) === 'default_company'
            );
            if ($optionIndex !== false) {
                $option = array_merge($options[$optionIndex], [
                    'location_source' => 'company',
                    'company_id' => $company->id,
                    'address' => $company->address ?? $company->name,
                    'latitude' => (float) $company->latitude,
                    'longitude' => (float) $company->longitude,
                ]);
                $options[$optionIndex] = $option;
                $field['options'] = $options;
                $field['default_option_value'] = $option['value'];
            } else {
                $conditions = $field['conditions'] ?? [];
                $hasAirportVariant = collect($conditions)->contains(static fn ($condition): bool =>
                    ($condition['type'] ?? null) === 'airport'
                );
                $hasLocationVariant = collect($conditions)->contains(static fn ($condition): bool =>
                    in_array($condition['type'] ?? null, ['location', 'any'], true)
                );
                $isAutocomplete = ($field['location_mode'] ?? null) === 'autocomplete';
                if (!$isAutocomplete && !$hasLocationVariant) {
                    continue;
                }

                if ($hasAirportVariant) {
                    foreach ($field['conditions'] as &$condition) {
                        if (in_array($condition['type'] ?? null, ['location', 'any'], true)) {
                            $condition['default'] = $company->address ?? $company->name;
                            $condition['default_lat'] = (string) $company->latitude;
                            $condition['default_lng'] = (string) $company->longitude;
                            $changed = true;
                        }
                    }
                    unset($condition);
                    continue;
                }
            }

            $field['default'] = $company->address ?? $company->name;
            $field['default_lat'] = (string) $company->latitude;
            $field['default_lng'] = (string) $company->longitude;
            $changed = true;
        }
        unset($field);

        if ($changed && $fieldsKey) {
            $config[$fieldsKey] = $fields;
        } elseif ($changed) {
            $config = $fields;
        }

        return $changed;
    }
}
