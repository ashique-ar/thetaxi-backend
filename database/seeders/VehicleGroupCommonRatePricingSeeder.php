<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ServiceType;
use App\Models\Vehicle\VehicleGroup;
use App\Models\Vehicle\VehiclePricing\VehiclePricingCommonRateDefinition;
use App\Models\Vehicle\VehiclePricing\VehicleGroupCommonRatePricing;

class VehicleGroupCommonRatePricingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Creating Vehicle Group Common Rate Pricing...');

        // Get existing data
        $vehicleGroups = VehicleGroup::all()->keyBy('name');
        $commonRateDefinitions = VehiclePricingCommonRateDefinition::with('serviceType')->get();

        if ($vehicleGroups->isEmpty()) {
            $this->command->warn('No vehicle groups found. Please run VehicleGroupSeeder first.');
            return;
        }

        if ($commonRateDefinitions->isEmpty()) {
            $this->command->warn('No common rate definitions found. Please run ComprehensivePricingSeeder first.');
            return;
        }

        // Define common rate pricing by service type and vehicle group category
        $commonRatePricingMatrix = [
            'point_to_point' => [
                'economy' => [
                    'vehicle_delivery_rate_per_km' => 50.00,
                    'vehicle_pickup_rate_per_km' => 50.00,
                    'driver_allowance' => 500.00,
                ],
                'standard' => [
                    'vehicle_delivery_rate_per_km' => 65.00,
                    'vehicle_pickup_rate_per_km' => 50.00,
                    'driver_allowance' => 750.00,
                ],
                'premium' => [
                    'vehicle_delivery_rate_per_km' => 85.00,
                    'vehicle_pickup_rate_per_km' => 70.00,
                    'driver_allowance' => 1000.00,
                ],
                'luxury' => [
                    'vehicle_pickup_rate_per_km' => 120.00,
                    'vehicle_delivery_rate_per_km' => 100.00,
                    'driver_allowance' => 1500.00,
                ],
                'commercial' => [
                    'vehicle_pickup_rate_per_km' => 75.00,
                    'vehicle_delivery_rate_per_km' => 60.00,
                    'driver_allowance' => 1000.00,
                ]
            ],
            'rental_package' => [
                'economy' => [
                    'vehicle_pickup_rate_per_km' => 35.00,
                    'vehicle_return_rate_per_km' => 25.00,
                ],
                'standard' => [
                    'vehicle_delivery_rate_per_km' => 55.00,
                    'vehicle_pickup_rate_per_km' => 55.00
                ],
                'premium' => [
                    'vehicle_delivery_rate_per_km' => 75.00,
                    'vehicle_pickup_rate_per_km' => 75.00
                ],
                'commercial' => [
                    'vehicle_delivery_rate_per_km' => 65.00,
                    'vehicle_pickup_rate_per_km' => 65.00
                ]
            ],
            'airport_transfers' => [
                'economy' => [
                    'taxi_rate' => 2500.00,
                    'vehicle_pickup_rate_per_km' => 45.00,
                    'vehicle_return_rate_per_km' => 35.00
                ],
                'standard' => [
                    'taxi_rate' => 3500.00,
                    'vehicle_pickup_rate_per_km' => 65.00,
                    'vehicle_return_rate_per_km' => 50.00
                ],
                'premium' => [
                    'taxi_rate' => 5000.00,
                    'vehicle_pickup_rate_per_km' => 85.00,
                    'vehicle_return_rate_per_km' => 70.00
                ],
                'luxury' => [
                    'taxi_rate' => 8000.00,
                    'vehicle_pickup_rate_per_km' => 120.00,
                    'vehicle_return_rate_per_km' => 100.00
                ],
                'commercial' => [
                    'taxi_rate' => 4500.00,
                    'vehicle_pickup_rate_per_km' => 75.00,
                    'vehicle_return_rate_per_km' => 60.00
                ]
            ],
            
            'corporate' => [
                'standard' => [
                    'contract_base_rate' => 8000.00,
                    'vehicle_pickup_rate_per_km' => 65.00,
                    'vehicle_return_rate_per_km' => 50.00,
                    'corporate_discount_rate' => 10.00, // 10% discount
                    'overtime_rate_per_hour' => 1500.00,
                    'driver_allowance' => 1000.00,
                ],
                'premium' => [
                    'contract_base_rate' => 12000.00,
                    'vehicle_pickup_rate_per_km' => 85.00,
                    'vehicle_return_rate_per_km' => 70.00,
                    'corporate_discount_rate' => 15.00, // 15% discount
                    'overtime_rate_per_hour' => 2200.00,
                    'driver_allowance' => 1000.00,
                ],
                'luxury' => [
                    'contract_base_rate' => 18000.00,
                    'vehicle_pickup_rate_per_km' => 120.00,
                    'vehicle_return_rate_per_km' => 100.00,
                    'corporate_discount_rate' => 20.00, // 20% discount
                    'overtime_rate_per_hour' => 3000.00,
                    'driver_allowance' => 1000.00,
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

        foreach ($commonRateDefinitions as $commonRateDefinition) {
            $serviceCode = $commonRateDefinition->serviceType->code;
            
            if (!isset($commonRatePricingMatrix[$serviceCode])) {
                continue;
            }

            foreach ($vehicleGroups as $groupName => $vehicleGroup) {
                $category = $groupCategoryMapping[$groupName] ?? 'standard';
                
                if (!isset($commonRatePricingMatrix[$serviceCode][$category])) {
                    continue;
                }

                // Extract the base rate code from the unique code (remove service prefix)
                $rateCode = str_replace("{$serviceCode}_", '', $commonRateDefinition->code);
                if (!isset($commonRatePricingMatrix[$serviceCode][$category][$rateCode])) {
                    continue;
                }

                $rateValue = $commonRatePricingMatrix[$serviceCode][$category][$rateCode];

                // Check if pricing already exists
                $existingPricing = VehicleGroupCommonRatePricing::where([
                    'common_rate_definition_id' => $commonRateDefinition->id,
                    'vehicle_group_id' => $vehicleGroup->id
                ])->first();

                if ($existingPricing) {
                    $skippedCount++;
                    continue;
                }

                VehicleGroupCommonRatePricing::create([
                    'common_rate_definition_id' => $commonRateDefinition->id,
                    'vehicle_group_id' => $vehicleGroup->id,
                    'value' => $rateValue,
                    'created_user_id' => null,
                    'updated_user_id' => null
                ]);

                $createdCount++;
                $this->command->info("Created common rate pricing: {$vehicleGroup->name} - {$commonRateDefinition->serviceType->name} - {$commonRateDefinition->name} - LKR {$rateValue}");
            }
        }

        $this->command->info("Vehicle Group Common Rate Pricing seeding completed! Created: {$createdCount}, Skipped: {$skippedCount}");
    }
}
