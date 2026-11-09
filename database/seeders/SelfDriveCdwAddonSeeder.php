<?php

namespace Database\Seeders;

use App\Models\Service\ServiceType;
use App\Models\Vehicle\VehicleAddon;
use Illuminate\Database\Seeder;

class SelfDriveCdwAddonSeeder extends Seeder
{
    public function run(): void
    {
        $serviceType = ServiceType::query()
            ->whereIn('type', ['self_drive', 'self_driven'])
            ->orderBy('name')
            ->first();

        if (!$serviceType) {
            return;
        }

        $addon = VehicleAddon::firstOrCreate(
            ['service_type_id' => $serviceType->id, 'name' => 'Collision Damage Waiver (CDW)'],
            [
                'addon_type' => 'insurance',
                'pricing_type' => 'fixed',
                'quantity_unit' => 'pieces',
                'amount' => 0,
                'rate_type' => 'flat',
                'billing_type' => 'per_package',
                'allow_quantity_selection' => false,
                'min_qty' => 1,
                'max_qty' => 1,
                'description' => 'CDW cover for self-drive rentals, using a ten-day minimum at the first-day vehicle slab rate.',
                'is_active' => true,
                'is_mandatory' => false,
                'is_optional' => true,
                'availability_type' => 'service_specific',
                'sort_order' => 10,
                'tags' => ['self-drive', 'cdw', 'insurance'],
                'integration_settings' => [
                    'dynamic_pricing' => [
                        'formula' => 'slab_rate * max(10, duration_days)',
                        'service_type' => 'self_drive',
                        'slab_scope' => 'first_day',
                    ],
                ],
            ]
        );

        if (data_get($addon->integration_settings, 'dynamic_pricing.formula') === 'first_day_slab_rate * 10') {
            $settings = $addon->integration_settings;
            $settings['dynamic_pricing'] = array_merge($settings['dynamic_pricing'], [
                'formula' => 'slab_rate * max(10, duration_days)',
                'slab_scope' => 'first_day',
            ]);
            $addon->update(['integration_settings' => $settings]);
        }
    }
}
