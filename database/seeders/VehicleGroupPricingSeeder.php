<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ServiceType;
use App\Models\Vehicle\VehicleGroup;
use App\Models\Vehicle\VehiclePricing\VehiclePricingSlabDefinition;
use App\Models\Vehicle\VehiclePricing\VehicleGroupPricing;

class VehicleGroupPricingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Creating Vehicle Group Slab Pricing...');

        // Get existing data
        $vehicleGroups = VehicleGroup::all()->keyBy('name');
        $slabDefinitions = VehiclePricingSlabDefinition::with('serviceType')->get();

        if ($vehicleGroups->isEmpty()) {
            $this->command->warn('No vehicle groups found. Please run VehicleGroupSeeder first.');
            return;
        }

        if ($slabDefinitions->isEmpty()) {
            $this->command->warn('No slab definitions found. Please run ComprehensivePricingSeeder first.');
            return;
        }

        // Define pricing structure by service type and vehicle group category
        $pricingMatrix = [
            // Point to Point (formerly chauffeur_driven) - WITH DRIVER rates
            'point_to_point' => [
                'economy' => [
                    '1-2 Days' => ['rate' => 8500, 'rate_type' => 'per_day'],
                    '3-4 Days' => ['rate' => 8000, 'rate_type' => 'per_day'],
                    '5-7 Days' => ['rate' => 7500, 'rate_type' => 'per_day'],
                    '8-14 Days' => ['rate' => 7000, 'rate_type' => 'per_day'],
                    '15-21 Days' => ['rate' => 6500, 'rate_type' => 'per_day'],
                    '22-28 Days' => ['rate' => 6000, 'rate_type' => 'per_day'],
                    '29-30 Days' => ['rate' => 5800, 'rate_type' => 'per_day'],
                    '31-366 Days' => ['rate' => 5500, 'rate_type' => 'per_day']
                ],
                'standard' => [
                    '1-2 Days' => ['rate' => 12000, 'rate_type' => 'per_day'],
                    '3-4 Days' => ['rate' => 11500, 'rate_type' => 'per_day'],
                    '5-7 Days' => ['rate' => 11000, 'rate_type' => 'per_day'],
                    '8-14 Days' => ['rate' => 10500, 'rate_type' => 'per_day'],
                    '15-21 Days' => ['rate' => 10000, 'rate_type' => 'per_day'],
                    '22-28 Days' => ['rate' => 9500, 'rate_type' => 'per_day'],
                    '29-30 Days' => ['rate' => 9200, 'rate_type' => 'per_day'],
                    '31-366 Days' => ['rate' => 9000, 'rate_type' => 'per_day']
                ],
                'premium' => [
                    '1-2 Days' => ['rate' => 18000, 'rate_type' => 'per_day'],
                    '3-4 Days' => ['rate' => 17500, 'rate_type' => 'per_day'],
                    '5-7 Days' => ['rate' => 17000, 'rate_type' => 'per_day'],
                    '8-14 Days' => ['rate' => 16500, 'rate_type' => 'per_day'],
                    '15-21 Days' => ['rate' => 16000, 'rate_type' => 'per_day'],
                    '22-28 Days' => ['rate' => 15500, 'rate_type' => 'per_day'],
                    '29-30 Days' => ['rate' => 15200, 'rate_type' => 'per_day'],
                    '31-366 Days' => ['rate' => 15000, 'rate_type' => 'per_day']
                ],
                'luxury' => [
                    '1-2 Days' => ['rate' => 25000, 'rate_type' => 'per_day'],
                    '3-4 Days' => ['rate' => 24000, 'rate_type' => 'per_day'],
                    '5-7 Days' => ['rate' => 23000, 'rate_type' => 'per_day'],
                    '8-14 Days' => ['rate' => 22000, 'rate_type' => 'per_day'],
                    '15-21 Days' => ['rate' => 21000, 'rate_type' => 'per_day'],
                    '22-28 Days' => ['rate' => 20000, 'rate_type' => 'per_day'],
                    '29-30 Days' => ['rate' => 19500, 'rate_type' => 'per_day'],
                    '31-366 Days' => ['rate' => 19000, 'rate_type' => 'per_day']
                ],
                'commercial' => [
                    '1-2 Days' => ['rate' => 15000, 'rate_type' => 'per_day'],
                    '3-4 Days' => ['rate' => 14500, 'rate_type' => 'per_day'],
                    '5-7 Days' => ['rate' => 14000, 'rate_type' => 'per_day'],
                    '8-14 Days' => ['rate' => 13500, 'rate_type' => 'per_day'],
                    '15-21 Days' => ['rate' => 13000, 'rate_type' => 'per_day'],
                    '22-28 Days' => ['rate' => 12500, 'rate_type' => 'per_day'],
                    '29-30 Days' => ['rate' => 12200, 'rate_type' => 'per_day'],
                    '31-366 Days' => ['rate' => 12000, 'rate_type' => 'per_day']
                ]
            ],
            // Rental Package (formerly self_driven) - SELF DRIVEN rates
            'ride_now' => [
                'economy' => [
                    '1-2 Days' => ['rate' => 6500, 'rate_type' => 'per_day'],
                    '3-4 Days' => ['rate' => 6000, 'rate_type' => 'per_day'],
                    '5-7 Days' => ['rate' => 5500, 'rate_type' => 'per_day'],
                    '8-14 Days' => ['rate' => 5000, 'rate_type' => 'per_day'],
                    '15-21 Days' => ['rate' => 4500, 'rate_type' => 'per_day'],
                    '22-28 Days' => ['rate' => 4200, 'rate_type' => 'per_day'],
                    '29-30 Days' => ['rate' => 4000, 'rate_type' => 'per_day'],
                    '31-366 Days' => ['rate' => 3800, 'rate_type' => 'per_day']
                ],
                'standard' => [
                    '1-2 Days' => ['rate' => 9000, 'rate_type' => 'per_day'],
                    '3-4 Days' => ['rate' => 8500, 'rate_type' => 'per_day'],
                    '5-7 Days' => ['rate' => 8000, 'rate_type' => 'per_day'],
                    '8-14 Days' => ['rate' => 7500, 'rate_type' => 'per_day'],
                    '15-21 Days' => ['rate' => 7000, 'rate_type' => 'per_day'],
                    '22-28 Days' => ['rate' => 6700, 'rate_type' => 'per_day'],
                    '29-30 Days' => ['rate' => 6500, 'rate_type' => 'per_day'],
                    '31-366 Days' => ['rate' => 6200, 'rate_type' => 'per_day']
                ],
                'premium' => [
                    '1-2 Days' => ['rate' => 13000, 'rate_type' => 'per_day'],
                    '3-4 Days' => ['rate' => 12500, 'rate_type' => 'per_day'],
                    '5-7 Days' => ['rate' => 12000, 'rate_type' => 'per_day'],
                    '8-14 Days' => ['rate' => 11500, 'rate_type' => 'per_day'],
                    '15-21 Days' => ['rate' => 11000, 'rate_type' => 'per_day'],
                    '22-28 Days' => ['rate' => 10700, 'rate_type' => 'per_day'],
                    '29-30 Days' => ['rate' => 10500, 'rate_type' => 'per_day'],
                    '31-366 Days' => ['rate' => 10200, 'rate_type' => 'per_day']
                ],
                'commercial' => [
                    '1-2 Days' => ['rate' => 11000, 'rate_type' => 'per_day'],
                    '3-4 Days' => ['rate' => 10500, 'rate_type' => 'per_day'],
                    '5-7 Days' => ['rate' => 10000, 'rate_type' => 'per_day'],
                    '8-14 Days' => ['rate' => 9500, 'rate_type' => 'per_day'],
                    '15-21 Days' => ['rate' => 9000, 'rate_type' => 'per_day'],
                    '22-28 Days' => ['rate' => 8700, 'rate_type' => 'per_day'],
                    '29-30 Days' => ['rate' => 8500, 'rate_type' => 'per_day'],
                    '31-366 Days' => ['rate' => 8200, 'rate_type' => 'per_day']
                ]
            ],
            'wedding_hire' => [
                'economy' => [
                    '6 Hours Package' => ['rate' => 18000, 'rate_type' => 'flat_rate'],
                    '8 Hours Package' => ['rate' => 22000, 'rate_type' => 'flat_rate'],
                    '12 Hours Package' => ['rate' => 24000, 'rate_type' => 'flat_rate']
                ],
                'standard' => [
                    '6 Hours Package' => ['rate' => 28000, 'rate_type' => 'flat_rate'],
                    '8 Hours Package' => ['rate' => 35000, 'rate_type' => 'flat_rate'],
                    '12 Hours Package' => ['rate' => 38000, 'rate_type' => 'flat_rate']
                ],
                'premium' => [
                    '6 Hours Package' => ['rate' => 45000, 'rate_type' => 'flat_rate'],
                    '8 Hours Package' => ['rate' => 52000, 'rate_type' => 'flat_rate'],
                    '12 Hours Package' => ['rate' => 55000, 'rate_type' => 'flat_rate']
                ],
                'luxury' => [
                    '6 Hours Package' => ['rate' => 65000, 'rate_type' => 'flat_rate'],
                    '8 Hours Package' => ['rate' => 75000, 'rate_type' => 'flat_rate'],
                    '12 Hours Package' => ['rate' => 85000, 'rate_type' => 'flat_rate']
                ],
            ],
            // Airport Transfers (replaces airport_drop/pickup) - per KM rates
            'airport_transfers' => [
                'economy' => [
                    'One Way Transfer' => ['rate' => 65, 'rate_type' => 'per_km'],
                    'Round Trip Transfer' => ['rate' => 60, 'rate_type' => 'per_km'],
                ],
                'standard' => [
                    'One Way Transfer' => ['rate' => 85, 'rate_type' => 'per_km'],
                    'Round Trip Transfer' => ['rate' => 80, 'rate_type' => 'per_km'],
                ],
                'premium' => [
                    'One Way Transfer' => ['rate' => 120, 'rate_type' => 'per_km'],
                    'Round Trip Transfer' => ['rate' => 115, 'rate_type' => 'per_km'],
                ],
                'luxury' => [
                    'One Way Transfer' => ['rate' => 150, 'rate_type' => 'per_km'],
                    'Round Trip Transfer' => ['rate' => 145, 'rate_type' => 'per_km'],
                ],
                'commercial' => [
                    'One Way Transfer' => ['rate' => 95, 'rate_type' => 'per_km'],
                    'Round Trip Transfer' => ['rate' => 90, 'rate_type' => 'per_km'],
                ]
            ],
            'corporate' => [
                'economy' => [
                    'Weekly Contract' => ['rate' => 4500, 'rate_type' => 'per_day'],
                    'Monthly Contract' => ['rate' => 4200, 'rate_type' => 'per_day'],
                    'Quarterly Contract' => ['rate' => 4000, 'rate_type' => 'per_day'],
                    'Semi-Annual Contract' => ['rate' => 3800, 'rate_type' => 'per_day'],
                    'Annual Contract' => ['rate' => 3500, 'rate_type' => 'per_day']
                ],
                'standard' => [
                    'Weekly Contract' => ['rate' => 7000, 'rate_type' => 'per_day'],
                    'Monthly Contract' => ['rate' => 6500, 'rate_type' => 'per_day'],
                    'Quarterly Contract' => ['rate' => 6200, 'rate_type' => 'per_day'],
                    'Semi-Annual Contract' => ['rate' => 6000, 'rate_type' => 'per_day'],
                    'Annual Contract' => ['rate' => 5800, 'rate_type' => 'per_day']
                ],
                'premium' => [
                    'Weekly Contract' => ['rate' => 12000, 'rate_type' => 'per_day'],
                    'Monthly Contract' => ['rate' => 11500, 'rate_type' => 'per_day'],
                    'Quarterly Contract' => ['rate' => 11000, 'rate_type' => 'per_day'],
                    'Semi-Annual Contract' => ['rate' => 10500, 'rate_type' => 'per_day'],
                    'Annual Contract' => ['rate' => 10000, 'rate_type' => 'per_day']
                ],
                'luxury' => [
                    'Weekly Contract' => ['rate' => 16000, 'rate_type' => 'per_day'],
                    'Monthly Contract' => ['rate' => 15500, 'rate_type' => 'per_day'],
                    'Quarterly Contract' => ['rate' => 15000, 'rate_type' => 'per_day'],
                    'Semi-Annual Contract' => ['rate' => 14500, 'rate_type' => 'per_day'],
                    'Annual Contract' => ['rate' => 14000, 'rate_type' => 'per_day']
                ],
                'commercial' => [
                    'Weekly Contract' => ['rate' => 9500, 'rate_type' => 'per_day'],
                    'Monthly Contract' => ['rate' => 9000, 'rate_type' => 'per_day'],
                    'Quarterly Contract' => ['rate' => 8500, 'rate_type' => 'per_day'],
                    'Semi-Annual Contract' => ['rate' => 8200, 'rate_type' => 'per_day'],
                    'Annual Contract' => ['rate' => 8000, 'rate_type' => 'per_day']
                ]
            ]
        ];

        // Vehicle group to category mapping
        $groupCategoryMapping = [
            'Toyota Axio Economy' => 'economy',
            'Honda Fit Hybrid' => 'economy',
            'Toyota Camry Standard' => 'standard',
            'Honda CR-V Premium' => 'premium',
            'Honda Accord Executive' => 'premium',
            'Toyota Land Cruiser VX' => 'luxury',
            'Toyota Hiace Standard' => 'commercial',
            'Nissan Caravan Executive' => 'commercial'
        ];

        $createdCount = 0;
        $skippedCount = 0;

        foreach ($slabDefinitions as $slabDefinition) {
            if (!$slabDefinition->serviceType) {
                $this->command->warn("Slab definition {$slabDefinition->id} has no service type, skipping...");
                $skippedCount++;
                continue;
            }
            
            $serviceCode = $slabDefinition->serviceType->code;
            
            if (!isset($pricingMatrix[$serviceCode])) {
                continue;
            }

            foreach ($vehicleGroups as $groupName => $vehicleGroup) {
                $category = $groupCategoryMapping[$groupName] ?? 'standard';
                
                if (!isset($pricingMatrix[$serviceCode][$category])) {
                    continue;
                }

                $slabName = $slabDefinition->name;
                if (!isset($pricingMatrix[$serviceCode][$category][$slabName])) {
                    continue;
                }

                $pricingData = $pricingMatrix[$serviceCode][$category][$slabName];

                // Check if pricing already exists
                $existingPricing = VehicleGroupPricing::where([
                    'slab_definition_id' => $slabDefinition->id,
                    'vehicle_group_id' => $vehicleGroup->id
                ])->first();

                if ($existingPricing) {
                    $skippedCount++;
                    continue;
                }

                VehicleGroupPricing::create([
                    'slab_definition_id' => $slabDefinition->id,
                    'vehicle_group_id' => $vehicleGroup->id,
                    'rate' => $pricingData['rate'],
                    'rate_type' => $pricingData['rate_type'],
                    'minimum_charge' => $pricingData['rate'] * 0.5, // 50% of rate as minimum
                    'includes_fuel' => $serviceCode === 'ride_now' || $serviceCode === 'wedding_hire' || $serviceCode === 'corporate',
                    'includes_driver' => $serviceCode === 'ride_now' || $serviceCode === 'wedding_hire' || $serviceCode === 'corporate' || $serviceCode === 'airport_transfers',
                ]);

                $createdCount++;
                $this->command->info("Created pricing: {$vehicleGroup->name} - {$slabDefinition->serviceType->name} - {$slabDefinition->name} - LKR {$pricingData['rate']}");
            }
        }

        $this->command->info("Vehicle Group Slab Pricing seeding completed! Created: {$createdCount}, Skipped: {$skippedCount}");
    }
}
