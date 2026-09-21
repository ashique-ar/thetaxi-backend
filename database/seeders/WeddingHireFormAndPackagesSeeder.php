<?php

namespace Database\Seeders;

use App\Models\Service\ServicePackage;
use App\Models\Service\ServiceType;
use App\Services\DefaultFormConfigService;
use Illuminate\Database\Seeder;

class WeddingHireFormAndPackagesSeeder extends Seeder
{
    public function run(): void
    {
        $service = ServiceType::query()->where('code', 'wedding_hire')->where('context', 'public')->first()
            ?? ServiceType::query()->where('code', 'wedding_hire')->first();
        if (!$service) {
            $this->command?->warn('Wedding Hire service type is missing.');
            return;
        }

        $config = $service->form_config ?: [];
        $wrapped = isset($config['fields']) && is_array($config['fields']);
        $fields = $wrapped ? $config['fields'] : $config;
        $pickup = DefaultFormConfigService::getDefaults('self_drive')['pickup_location'];
        $pickup['options'] = array_values(array_filter(
            $pickup['options'],
            static fn (array $option): bool => in_array($option['value'], ['CASONS_HQ', 'custom'], true)
        ));
        $pickup['options'][1]['label'] = 'I need the car at my doorstep';
        $fields['pickup_location'] = array_merge($fields['pickup_location'] ?? [], $pickup);
        unset($fields['duration_hours'], $fields['package_hours']);
        $fields['service_package_id'] = [
            'type' => 'package_select', 'label' => 'Wedding Package',
            'required' => true, 'order' => 4, 'submit_as' => 'package_id',
        ];
        $service->form_config = $wrapped ? array_merge($config, ['fields' => $fields]) : $fields;
        $service->save();

        foreach ([4 => 0.75, 8 => 1.00] as $hours => $multiplier) {
            ServicePackage::query()->updateOrCreate(
                ['service_type_id' => $service->id, 'code' => "wedding_{$hours}h"],
                [
                    'name' => "{$hours} Hours",
                    'description' => "Wedding car hire for {$hours} hours",
                    'default_duration_hours' => $hours,
                    'price_multiplier' => $multiplier,
                    'rate_type' => 'flat',
                    'is_active' => true,
                    'sort_order' => $hours,
                ]
            );
        }
    }
}
