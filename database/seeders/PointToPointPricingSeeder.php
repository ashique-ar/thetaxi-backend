<?php

namespace Database\Seeders;

use App\Models\Service\ServiceType;
use App\Models\User;
use App\Models\Vehicle\VehicleGroup;
use App\Models\Vehicle\VehiclePricing\VehicleGroupCommonRatePricing;
use App\Models\Vehicle\VehiclePricing\VehicleGroupPricing;
use App\Models\Vehicle\VehiclePricing\VehiclePricingCalculationDefinition;
use App\Models\Vehicle\VehiclePricing\VehiclePricingCommonRateDefinition;
use App\Models\Vehicle\VehiclePricing\VehiclePricingSlabDefinition;
use Illuminate\Database\Seeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class PointToPointPricingSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Seeding Point-to-Point pricing configuration...');

        $serviceType = $this->seedServiceTypeAndFormConfig();
        $this->resetExistingPointToPointPricing($serviceType);
        $this->seedSlabDefinitions($serviceType);

        $commonRates = $this->seedCommonRateDefinitions($serviceType);
        $this->seedCalculationDefinition($serviceType, $commonRates);

        $this->seedVehicleGroupSlabPricing($serviceType);
        $this->seedVehicleGroupCommonRatePricing($serviceType, $commonRates);

        $this->command->info('Point-to-Point pricing seeding completed.');
    }

    private function resetExistingPointToPointPricing(ServiceType $serviceType): void
    {
        $this->command->info('Resetting existing point-to-point pricing records...');

        $slabIds = VehiclePricingSlabDefinition::query()
            ->withInactive()
            ->withTrashed()
            ->where('service_type_id', $serviceType->id)
            ->pluck('id');

        if ($slabIds->isNotEmpty()) {
            VehicleGroupPricing::query()
                ->withInactive()
                ->withTrashed()
                ->whereIn('slab_definition_id', $slabIds)
                ->forceDelete();
        }

        VehiclePricingSlabDefinition::query()
            ->withInactive()
            ->withTrashed()
            ->where('service_type_id', $serviceType->id)
            ->forceDelete();

        $commonRateIds = VehiclePricingCommonRateDefinition::query()
            ->withInactive()
            ->withTrashed()
            ->where('service_type_id', $serviceType->id)
            ->pluck('id');

        if ($commonRateIds->isNotEmpty()) {
            VehicleGroupCommonRatePricing::query()
                ->withInactive()
                ->withTrashed()
                ->whereIn('common_rate_definition_id', $commonRateIds)
                ->forceDelete();
        }

        VehiclePricingCommonRateDefinition::query()
            ->withInactive()
            ->withTrashed()
            ->where('service_type_id', $serviceType->id)
            ->forceDelete();

        VehiclePricingCalculationDefinition::query()
            ->withTrashed()
            ->where('service_type_id', $serviceType->id)
            ->forceDelete();
    }

    private function seedServiceTypeAndFormConfig(): ServiceType
    {
        /** @var ServiceType $serviceType */
        $serviceType = $this->upsertModel(
            ServiceType::class,
            ['code' => 'point_to_point'],
            [
                'name' => 'Point to Point',
                'description' => 'Point-to-point transportation with optional multiple pickups/drop-offs.',
                'type' => 'with_driver',
                'is_active' => true,
                'priority' => 1,
                'pricing_mode' => 'trip',
                'uses_dropoff_time' => false,
                'allow_return_trip' => false,
                'allow_multiple_pickup_locations' => true,
                'allow_multiple_dropoff_locations' => true,
                'frontend_category' => 'trip',
            ]
        );

        $normalized = $this->extractStoredFormConfig($serviceType->form_config);
        $fields = $normalized['fields'];
        $fieldMappings = $normalized['field_mappings'];

        $defaults = $this->defaultPointToPointFields();
        foreach ($defaults as $fieldName => $config) {
            if (!isset($fields[$fieldName])) {
                $fields[$fieldName] = $config;
            }
        }

        $defaultMappings = $this->defaultPointToPointFieldMappings();
        $fieldMappings = array_replace_recursive($defaultMappings, $fieldMappings);

        $serviceType->form_config = array_merge($fields, ['field_mappings' => $fieldMappings]);
        $serviceType->save();

        return $serviceType;
    }

    private function seedSlabDefinitions(ServiceType $serviceType): void
    {
        $slabs = [
            ['name' => '1-2 Days', 'min_days' => 1, 'max_days' => 2, 'type' => 'per_day', 'sort_order' => 1, 'max_km_per_day' => 100],
            ['name' => '3-4 Days', 'min_days' => 3, 'max_days' => 4, 'type' => 'per_day', 'sort_order' => 2, 'max_km_per_day' => 100],
            ['name' => '5-7 Days', 'min_days' => 5, 'max_days' => 7, 'type' => 'per_day', 'sort_order' => 3, 'max_km_per_day' => 100],
            ['name' => '8-14 Days', 'min_days' => 8, 'max_days' => 14, 'type' => 'per_day', 'sort_order' => 4, 'max_km_per_day' => 100],
            ['name' => '15-21 Days', 'min_days' => 15, 'max_days' => 21, 'type' => 'per_day', 'sort_order' => 5, 'max_km_per_day' => 100],
            ['name' => '22-28 Days', 'min_days' => 22, 'max_days' => 28, 'type' => 'per_day', 'sort_order' => 6, 'max_km_per_day' => 100],
            ['name' => '29-30 Days', 'min_days' => 29, 'max_days' => 30, 'type' => 'per_day', 'sort_order' => 7, 'max_km_per_day' => 100],
            ['name' => '31-366 Days', 'min_days' => 31, 'max_days' => 366, 'type' => 'per_day', 'sort_order' => 8, 'max_km_per_day' => 100],
        ];

        foreach ($slabs as $slab) {
            $this->upsertModel(
                VehiclePricingSlabDefinition::class,
                [
                    'service_type_id' => $serviceType->id,
                    'name' => $slab['name'],
                ],
                [
                    'min_days' => $slab['min_days'],
                    'max_days' => $slab['max_days'],
                    'min_hours' => 0,
                    'max_hours' => 0,
                    'type' => $slab['type'],
                    'sort_order' => $slab['sort_order'],
                    'max_km_per_day' => $slab['max_km_per_day'],
                    'max_km_per_package' => null,
                    'is_active' => true,
                ]
            );
        }
    }

    /**
     * @return array<string, VehiclePricingCommonRateDefinition>
     */
    private function seedCommonRateDefinitions(ServiceType $serviceType): array
    {
        $definitions = [
            ['name' => 'Service Rate Per KM', 'code' => 'service_rate_per_km', 'common_rate_type' => 'per_km', 'sort_order' => 1],
            ['name' => 'Vehicle Pickup Rate Per KM', 'code' => 'vehicle_pickup_rate_per_km', 'common_rate_type' => 'per_km', 'sort_order' => 2],
            ['name' => 'Vehicle Delivery Rate Per KM', 'code' => 'vehicle_delivery_rate_per_km', 'common_rate_type' => 'per_km', 'sort_order' => 3],
            ['name' => 'Stop Charge', 'code' => 'stop_charge', 'common_rate_type' => 'per_stop', 'sort_order' => 4],
            ['name' => 'Extra KM Rate', 'code' => 'extra_km_rate', 'common_rate_type' => 'per_km', 'sort_order' => 5],
        ];

        $created = [];
        foreach ($definitions as $definition) {
            /** @var VehiclePricingCommonRateDefinition $model */
            $model = $this->upsertModel(
                VehiclePricingCommonRateDefinition::class,
                [
                    'service_type_id' => $serviceType->id,
                    'code' => $definition['code'],
                ],
                [
                    'name' => $definition['name'],
                    'common_rate_type' => $definition['common_rate_type'],
                    'description' => $definition['name'] . ' for point-to-point pricing.',
                    'sort_order' => $definition['sort_order'],
                    'is_active' => true,
                    'is_mandatory' => false,
                ]
            );

            $created[$definition['code']] = $model;
        }

        return $created;
    }

    /**
     * @param array<string, VehiclePricingCommonRateDefinition> $commonRates
     */
    private function seedCalculationDefinition(ServiceType $serviceType, array $commonRates): void
    {
        $actorId = User::query()->value('id') ?? '00000000-0000-0000-0000-000000000001';

        $variables = [
            ['name' => 'slab_rate', 'type' => 'slab_rate', 'description' => 'Base slab rate', 'is_required' => true, 'category' => 'base'],
            ['name' => 'journey_distance', 'type' => 'distance', 'description' => 'Journey distance (pickup -> ordered stops -> dropoff)', 'is_required' => false, 'default_value' => 0, 'category' => 'distance'],
            ['name' => 'pickup_distance', 'type' => 'distance', 'description' => 'Garage to pickup distance', 'is_required' => false, 'default_value' => 0, 'category' => 'distance'],
            ['name' => 'delivery_distance', 'type' => 'distance', 'description' => 'Dropoff to garage distance', 'is_required' => false, 'default_value' => 0, 'category' => 'distance'],
            ['name' => 'additional_stops_count', 'type' => 'fixed_value', 'description' => 'Additional stops count based on entered order', 'is_required' => false, 'default_value' => 0, 'category' => 'service'],
            ['name' => 'extra_km', 'type' => 'distance', 'description' => 'Distance above free KM threshold', 'is_required' => false, 'default_value' => 0, 'category' => 'distance'],
        ];

        foreach (['service_rate_per_km', 'vehicle_pickup_rate_per_km', 'vehicle_delivery_rate_per_km', 'stop_charge', 'extra_km_rate'] as $code) {
            $commonRate = Arr::get($commonRates, $code);
            if (!$commonRate) {
                continue;
            }

            $variables[] = [
                'name' => $code,
                'type' => 'common_rate',
                'description' => $commonRate->description ?: $commonRate->name,
                'is_required' => false,
                'default_value' => 0,
                'category' => 'rate',
                'source_id' => $commonRate->id,
                'common_rate_code' => $commonRate->code,
            ];
        }

        $this->upsertModel(
            VehiclePricingCalculationDefinition::class,
            [
                'service_type_id' => $serviceType->id,
                'name' => 'Point to Point Multi-Stop Calculation',
            ],
            [
                'description' => 'Point-to-point calculation with ordered-stop distance and stop-wise charging.',
                'status' => 'active',
                'formula' => 'slab_rate + (journey_distance * service_rate_per_km) + (pickup_distance * vehicle_pickup_rate_per_km) + (delivery_distance * vehicle_delivery_rate_per_km) + (additional_stops_count * stop_charge) + (extra_km * extra_km_rate)',
                'variables' => $variables,
                'conditions' => [],
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]
        );
    }

    private function seedVehicleGroupSlabPricing(ServiceType $serviceType): void
    {
        $slabRatesByTier = [
            'economy' => [8500, 8000, 7500, 7000, 6500, 6000, 5800, 5500],
            'standard' => [12000, 11500, 11000, 10500, 10000, 9500, 9200, 9000],
            'premium' => [18000, 17500, 17000, 16500, 16000, 15500, 15200, 15000],
            'luxury' => [25000, 24000, 23000, 22000, 21000, 20000, 19500, 19000],
            'commercial' => [15000, 14500, 14000, 13500, 13000, 12500, 12200, 12000],
        ];

        $slabs = VehiclePricingSlabDefinition::query()
            ->where('service_type_id', $serviceType->id)
            ->orderBy('sort_order')
            ->get()
            ->values();

        if ($slabs->isEmpty()) {
            return;
        }

        VehicleGroup::query()->where('is_active', true)->get()->each(function (VehicleGroup $group) use ($slabs, $slabRatesByTier): void {
            $tier = $this->resolveVehicleTier($group);
            $rates = $slabRatesByTier[$tier] ?? $slabRatesByTier['standard'];

            foreach ($slabs as $index => $slab) {
                $rate = $rates[$index] ?? end($rates);
                $this->upsertModel(
                    VehicleGroupPricing::class,
                    [
                        'slab_definition_id' => $slab->id,
                        'vehicle_group_id' => $group->id,
                    ],
                    [
                        'rate' => $rate,
                        'rate_type' => 'per_day',
                        'minimum_charge' => round(((float) $rate) * 0.5, 2),
                        'includes_fuel' => false,
                        'includes_driver' => true,
                        'is_active' => true,
                    ]
                );
            }
        });
    }

    /**
     * @param array<string, VehiclePricingCommonRateDefinition> $commonRates
     */
    private function seedVehicleGroupCommonRatePricing(ServiceType $serviceType, array $commonRates): void
    {
        $ratesByTier = [
            'economy' => [
                'service_rate_per_km' => 110.00,
                'vehicle_pickup_rate_per_km' => 50.00,
                'vehicle_delivery_rate_per_km' => 50.00,
                'stop_charge' => 400.00,
                'extra_km_rate' => 70.00,
            ],
            'standard' => [
                'service_rate_per_km' => 140.00,
                'vehicle_pickup_rate_per_km' => 65.00,
                'vehicle_delivery_rate_per_km' => 65.00,
                'stop_charge' => 500.00,
                'extra_km_rate' => 85.00,
            ],
            'premium' => [
                'service_rate_per_km' => 180.00,
                'vehicle_pickup_rate_per_km' => 85.00,
                'vehicle_delivery_rate_per_km' => 85.00,
                'stop_charge' => 650.00,
                'extra_km_rate' => 100.00,
            ],
            'luxury' => [
                'service_rate_per_km' => 260.00,
                'vehicle_pickup_rate_per_km' => 120.00,
                'vehicle_delivery_rate_per_km' => 120.00,
                'stop_charge' => 900.00,
                'extra_km_rate' => 130.00,
            ],
            'commercial' => [
                'service_rate_per_km' => 170.00,
                'vehicle_pickup_rate_per_km' => 75.00,
                'vehicle_delivery_rate_per_km' => 75.00,
                'stop_charge' => 700.00,
                'extra_km_rate' => 95.00,
            ],
        ];

        VehicleGroup::query()->where('is_active', true)->get()->each(function (VehicleGroup $group) use ($ratesByTier, $commonRates, $serviceType): void {
            $tier = $this->resolveVehicleTier($group);
            $rates = $ratesByTier[$tier] ?? $ratesByTier['standard'];

            foreach ($rates as $rateCode => $rateValue) {
                $definition = Arr::get($commonRates, $rateCode)
                    ?: VehiclePricingCommonRateDefinition::query()
                        ->where('service_type_id', $serviceType->id)
                        ->where('code', $rateCode)
                        ->first();

                if (!$definition) {
                    continue;
                }

                $this->upsertModel(
                    VehicleGroupCommonRatePricing::class,
                    [
                        'vehicle_group_id' => $group->id,
                        'common_rate_definition_id' => $definition->id,
                    ],
                    [
                        'value' => $rateValue,
                        'is_active' => true,
                    ]
                );
            }
        });
    }

    /**
     * @return array{fields: array<string, array>, field_mappings: array}
     */
    private function extractStoredFormConfig(?array $storedConfig): array
    {
        if (empty($storedConfig) || !is_array($storedConfig)) {
            return ['fields' => [], 'field_mappings' => []];
        }

        $fieldMappings = [];
        if (isset($storedConfig['field_mappings']) && is_array($storedConfig['field_mappings'])) {
            $fieldMappings = $storedConfig['field_mappings'];
        } elseif (isset($storedConfig['fields']['field_mappings']) && is_array($storedConfig['fields']['field_mappings'])) {
            $fieldMappings = $storedConfig['fields']['field_mappings'];
        }

        $fieldsCandidate = $storedConfig['fields'] ?? $storedConfig;
        unset($fieldsCandidate['field_mappings']);

        $fields = array_filter($fieldsCandidate, function ($fieldConfig) {
            return is_array($fieldConfig)
                && isset($fieldConfig['type'])
                && isset($fieldConfig['label']);
        });

        return [
            'fields' => $fields,
            'field_mappings' => $fieldMappings,
        ];
    }

    private function defaultPointToPointFields(): array
    {
        return [
            'pickup_location' => [
                'type' => 'location',
                'label' => 'Start Location',
                'required' => true,
                'order' => 1,
                'submit_as' => 'pickup',
                'location_mode' => 'autocomplete',
                'placeholder' => 'Enter pickup location',
                'width' => 'full',
                'alignment' => 'left',
                'row' => 1,
            ],
            'dropoff_location' => [
                'type' => 'location',
                'label' => 'End Location',
                'required' => true,
                'order' => 2,
                'submit_as' => 'dropoff',
                'location_mode' => 'autocomplete',
                'placeholder' => 'Enter dropoff location',
                'width' => 'full',
                'alignment' => 'left',
                'row' => 2,
            ],
            'from_date' => [
                'type' => 'date',
                'label' => 'Date',
                'required' => true,
                'order' => 3,
                'submit_as' => 'from_date',
                'width' => 'half',
                'alignment' => 'left',
                'row' => 3,
            ],
            'from_time' => [
                'type' => 'time',
                'label' => 'Time',
                'required' => true,
                'order' => 4,
                'submit_as' => 'from_time',
                'default' => '09:00',
                'width' => 'half',
                'alignment' => 'left',
                'row' => 3,
            ],
        ];
    }

    private function defaultPointToPointFieldMappings(): array
    {
        return [
            'dates' => [
                'from_date' => 'from_date',
                'from_time' => 'from_time',
                'to_date' => null,
                'to_time' => null,
            ],
            'locations' => [
                'pickup_location' => 'pickup_location',
                'dropoff_location' => 'dropoff_location',
            ],
        ];
    }

    private function resolveVehicleTier(VehicleGroup $group): string
    {
        $name = strtolower($group->name ?? '');

        if (str_contains($name, 'luxury') || str_contains($name, 'land cruiser') || str_contains($name, 'bmw') || str_contains($name, 'benz')) {
            return 'luxury';
        }

        if (str_contains($name, 'premium') || str_contains($name, 'executive')) {
            return 'premium';
        }

        if (str_contains($name, 'economy') || str_contains($name, 'fit') || str_contains($name, 'axio')) {
            return 'economy';
        }

        if (str_contains($name, 'van') || str_contains($name, 'bus') || str_contains($name, 'hiace') || str_contains($name, 'caravan')) {
            return 'commercial';
        }

        return 'standard';
    }

    /**
     * Upsert including soft-deleted rows to avoid unique-constraint collisions.
     *
     * @param class-string<Model> $modelClass
     * @return Model
     */
    private function upsertModel(string $modelClass, array $match, array $values): Model
    {
        $usesSoftDeletes = in_array(
            \Illuminate\Database\Eloquent\SoftDeletes::class,
            class_uses_recursive($modelClass),
            true
        );

        $query = $modelClass::query()->withoutGlobalScopes();
        if ($usesSoftDeletes) {
            $query = $query->withTrashed();
        }
        $existing = $query->where($match)->first();

        if ($existing) {
            $existing->fill($values);

            if ($usesSoftDeletes && method_exists($existing, 'trashed') && $existing->trashed()) {
                $existing->restore();
            }

            $existing->save();
            return $existing;
        }

        /** @var Model $created */
        $created = $modelClass::create(array_merge($match, $values));
        return $created;
    }
}
