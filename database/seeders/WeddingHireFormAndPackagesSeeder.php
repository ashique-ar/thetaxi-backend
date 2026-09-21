<?php

namespace Database\Seeders;

use App\Models\Service\ServicePackage;
use App\Models\Service\ServiceType;
use App\Models\Vehicle\VehiclePricing\VehicleGroupCommonRatePricing;
use App\Models\Vehicle\VehiclePricing\VehiclePricingCalculationDefinition;
use App\Models\Vehicle\VehiclePricing\VehiclePricingCommonRateDefinition;
use App\Models\Vehicle\VehiclePricing\VehiclePricingSlabDefinition;
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
            'width' => 'full', 'tablet_width' => 'full', 'mobile_width' => 'full',
        ];
        $service->form_config = $wrapped ? array_merge($config, ['fields' => $fields]) : $fields;
        $service->save();

        foreach ([4 => 0.75, 8 => 1.00] as $hours => $multiplier) {
            ServicePackage::query()->firstOrCreate(
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

        $slabs = VehiclePricingSlabDefinition::query()->where('service_type_id', $service->id)->get();
        $baseRateSlab = $slabs->firstWhere('name', '8 Hour Wedding Rate')
            ?? $slabs->first(fn ($slab) => (int) $slab->min_hours === 8 && (int) $slab->max_hours === 8)
            ?? new VehiclePricingSlabDefinition(['service_type_id' => $service->id]);
        $baseRateSlab->fill([
            'service_package_id' => null,
            'name' => '8 Hour Wedding Rate',
            'type' => 'flat_rate',
            'min_hours' => 4,
            'max_hours' => 8,
            'min_days' => null,
            'max_days' => null,
            'sort_order' => 1,
            'is_active' => true,
        ])->save();
        VehiclePricingSlabDefinition::query()
            ->where('service_type_id', $service->id)
            ->whereKeyNot($baseRateSlab->id)
            ->update(['is_active' => false]);

        $commonRateIds = VehiclePricingCommonRateDefinition::query()
            ->where('service_type_id', $service->id)
            ->pluck('id');
        VehicleGroupCommonRatePricing::withTrashed()
            ->whereIn('common_rate_definition_id', $commonRateIds)
            ->forceDelete();
        VehiclePricingCommonRateDefinition::query()
            ->whereIn('id', $commonRateIds)
            ->delete();

        VehiclePricingCalculationDefinition::query()
            ->where('service_type_id', $service->id)
            ->update([
                'description' => 'Wedding package price from the managed vehicle group rate and selected package multiplier',
                'formula' => 'slab_rate',
                'variables' => [[
                    'name' => 'slab_rate',
                    'type' => 'slab_rate',
                    'description' => 'Managed vehicle group rate adjusted by the selected package multiplier',
                    'is_required' => true,
                    'category' => 'base',
                ]],
            ]);
    }
}
