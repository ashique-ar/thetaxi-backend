<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ServiceType;
use App\Models\Vehicle\VehicleGroup;
use App\Models\Vehicle\VehiclePricing\VehiclePricingSlabDefinition;
use App\Models\Vehicle\VehiclePricing\VehiclePricingCommonRateDefinition;
use App\Models\Vehicle\VehiclePricing\VehicleGroupCommonRatePricing;
use App\Models\Vehicle\VehiclePricing\VehiclePricingCalculationDefinition;
use Illuminate\Support\Str;
use App\Models\Vehicle\VehiclePricing\VehicleGroupPricing;

class ComprehensivePricingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->seedServiceTypes();
        $this->seedServiceTypesWithSlabs();
        $this->seedCommonRateDefinitions();
        $this->seedCalculationDefinitions();

        $this->call([
            VehicleGroupPricingSeeder::class,
            VehicleGroupCommonRatePricingSeeder::class,
        ]);
    }

    /**
     * Seed service types with their corresponding slab definitions
     */
    private function seedServiceTypes(): void
    {
        $types = [
            ['code' => 'chauffeur_driven', 'name' => 'Chauffeur Driven', 'description' => 'A service where a driver is provided with the vehicle.', 'type' => 'with_driver', 'priority' => 1],
            ['code' => 'wedding_hire', 'name' => 'Wedding Hire', 'description' => 'A service for hiring vehicles for weddings.', 'type' => 'with_driver', 'priority' => 2],
            ['code' => 'airport_drop', 'name' => 'Airport Drop', 'description' => 'A service for dropping off passengers at the airport.', 'type' => 'with_driver', 'priority' => 3],
            ['code' => 'airport_pickup', 'name' => 'Airport Pickup', 'description' => 'A service for picking up passengers from the airport.', 'type' => 'with_driver', 'priority' => 4],
            ['code' => 'transfers', 'name' => 'Transfers', 'description' => 'A service for transferring passengers between locations.', 'type' => 'with_driver', 'priority' => 5],
            ['code' => 'break_down_service', 'name' => 'Break Down Service', 'description' => 'A service for assisting vehicles that have broken down.', 'type' => 'with_driver', 'priority' => 6],
            ['code' => 'corporate', 'name' => 'Corporate Hires', 'description' => 'A service for corporate vehicle hires.', 'type' => 'with_driver', 'priority' => 7],
            ['code' => 'corporate_self', 'name' => 'Corporate Self Drive', 'description' => 'A service for corporate self-drive vehicle hires.', 'type' => 'self_driven', 'priority' => 8],
            ['code' => 'self_driven', 'name' => 'Self Driven', 'description' => 'A service for self-driven vehicle hires.', 'type' => 'self_driven', 'priority' => 9],
             ['name' => 'Custom Tour', 'code' => 'custom_tour', 'category' => 'special', 'is_active' => 1, 'is_internal' => 0, 'priority' => 4],
        ];

        ServiceType::truncate();
        foreach ($types as $data) {
            ServiceType::firstOrCreate(
                ['code' => $data['code']],
                $data
            );
        }
    }
    /**
     * Seed service types with their corresponding slab definitions
     */
    private function seedServiceTypesWithSlabs(): void
    {
        $serviceTypesWithSlabs = [
            'chauffeur_driven' => [
                ['name' => '1-2 Days', 'min_days' => 1, 'min_hours' => 0, 'max_days' => 2, 'max_hours' => 0, 'type' => 'per_day', 'sort_order' => 1, 'max_km_per_day' => 100, 'max_km_per_package' => null],
                ['name' => '3-4 Days', 'min_days' => 3, 'min_hours' => 0, 'max_days' => 4, 'max_hours' => 0, 'type' => 'per_day', 'sort_order' => 2, 'max_km_per_day' => 100, 'max_km_per_package' => null],
                ['name' => '5-7 Days', 'min_days' => 5, 'min_hours' => 0, 'max_days' => 7, 'max_hours' => 0, 'type' => 'per_day', 'sort_order' => 3, 'max_km_per_day' => 100, 'max_km_per_package' => null],
                ['name' => '8-14 Days', 'min_days' => 8, 'min_hours' => 0, 'max_days' => 14, 'max_hours' => 0, 'type' => 'per_day', 'sort_order' => 4, 'max_km_per_day' => 100, 'max_km_per_package' => null],
                ['name' => '15-21 Days', 'min_days' => 15, 'min_hours' => 0, 'max_days' => 21, 'max_hours' => 0, 'type' => 'per_day', 'sort_order' => 5, 'max_km_per_day' => 100, 'max_km_per_package' => null],
                ['name' => '22-28 Days', 'min_days' => 22, 'min_hours' => 0, 'max_days' => 28, 'max_hours' => 0, 'type' => 'per_day', 'sort_order' => 6, 'max_km_per_day' => 100, 'max_km_per_package' => null],
                ['name' => '29-30 Days', 'min_days' => 29, 'min_hours' => 0, 'max_days' => 30, 'max_hours' => 0, 'type' => 'per_day', 'sort_order' => 7, 'max_km_per_day' => 100, 'max_km_per_package' => null],
                ['name' => '31-366 Days', 'min_days' => 31, 'min_hours' => 0, 'max_days' => 366, 'max_hours' => 0, 'type' => 'per_day', 'sort_order' => 8, 'max_km_per_day' => 100, 'max_km_per_package' => null],
            ],
            'self_driven' => [
                ['name' => '1-2 Days', 'min_days' => 1, 'min_hours' => 0, 'max_days' => 2, 'max_hours' => 0, 'type' => 'per_day', 'sort_order' => 1, 'max_km_per_day' => 100, 'max_km_per_package' => null],
                ['name' => '3-4 Days', 'min_days' => 3, 'min_hours' => 0, 'max_days' => 4, 'max_hours' => 0, 'type' => 'per_day', 'sort_order' => 2, 'max_km_per_day' => 100, 'max_km_per_package' => null],
                ['name' => '5-7 Days', 'min_days' => 5, 'min_hours' => 0, 'max_days' => 7, 'max_hours' => 0, 'type' => 'per_day', 'sort_order' => 3, 'max_km_per_day' => 100, 'max_km_per_package' => null],
                ['name' => '8-14 Days', 'min_days' => 8, 'min_hours' => 0, 'max_days' => 14, 'max_hours' => 0, 'type' => 'per_day', 'sort_order' => 4, 'max_km_per_day' => 100, 'max_km_per_package' => null],
                ['name' => '15-21 Days', 'min_days' => 15, 'min_hours' => 0, 'max_days' => 21, 'max_hours' => 0, 'type' => 'per_day', 'sort_order' => 5, 'max_km_per_day' => 100, 'max_km_per_package' => null],
                ['name' => '22-28 Days', 'min_days' => 22, 'min_hours' => 0, 'max_days' => 28, 'max_hours' => 0, 'type' => 'per_day', 'sort_order' => 6, 'max_km_per_day' => 100, 'max_km_per_package' => null],
                ['name' => '29-30 Days', 'min_days' => 29, 'min_hours' => 0, 'max_days' => 30, 'max_hours' => 0, 'type' => 'per_day', 'sort_order' => 7, 'max_km_per_day' => 100, 'max_km_per_package' => null],
                ['name' => '31-366 Days', 'min_days' => 31, 'min_hours' => 0, 'max_days' => 366, 'max_hours' => 0, 'type' => 'per_day', 'sort_order' => 8, 'max_km_per_day' => 100, 'max_km_per_package' => null],
            ],
            'wedding_hire' => [
                ['name' => '4 Hours', 'min_hours' => 4, 'max_hours' => 4, 'type' => 'flat_rate', 'sort_order' => 1, 'max_km_per_package' => 40],
                ['name' => '8 Hours', 'min_hours' => 8, 'max_hours' => 8, 'type' => 'flat_rate', 'sort_order' => 2, 'max_km_per_package' => 80],
                ['name' => '12 Hours', 'min_hours' => 12, 'max_hours' => 12, 'type' => 'flat_rate', 'sort_order' => 3, 'max_km_per_package' => 120],
            ],
            'airport_drop' => [
                ['name' => 'One Way Drop', 'min_hours' => 1, 'max_hours' => 4, 'type' => 'per_km', 'sort_order' => 1, 'max_km_per_package' => null],
            ],
            'airport_pickup' => [
                ['name' => 'One Way Pickup', 'min_hours' => 1, 'max_hours' => 4, 'type' => 'per_km', 'sort_order' => 1, 'max_km_per_package' => null],
            ],
            'transfers' => [
                ['name' => 'Point to Point', 'min_hours' => 1, 'max_hours' => 8, 'type' => 'per_km', 'sort_order' => 1, 'max_km_per_package' => null],
                ['name' => 'Multi-Stop Transfer', 'min_hours' => 2, 'max_hours' => 12, 'type' => 'per_km', 'sort_order' => 2, 'max_km_per_package' => null],
            ],
            'break_down_service' => [
                ['name' => 'Emergency Towing', 'min_hours' => 1, 'max_hours' => 4, 'type' => 'per_km', 'sort_order' => 1, 'max_km_per_package' => null],
                ['name' => 'Extended Recovery', 'min_hours' => 4, 'max_hours' => 12, 'type' => 'per_km', 'sort_order' => 2, 'max_km_per_package' => null],
            ],
            'corporate' => [
                ['name' => 'Weekly Contract', 'min_days' => 7, 'min_hours' => 0, 'max_days' => 7, 'max_hours' => 0, 'type' => 'per_day', 'sort_order' => 1, 'max_km_per_day' => 100, 'max_km_per_package' => null],
                ['name' => 'Monthly Contract', 'min_days' => 30, 'min_hours' => 0, 'max_days' => 31, 'max_hours' => 0, 'type' => 'per_day', 'sort_order' => 2, 'max_km_per_day' => 100, 'max_km_per_package' => null],
                ['name' => 'Quarterly Contract', 'min_days' => 90, 'min_hours' => 0, 'max_days' => 93, 'max_hours' => 0, 'type' => 'per_day', 'sort_order' => 3, 'max_km_per_day' => 100, 'max_km_per_package' => null],
                ['name' => 'Semi-Annual Contract', 'min_days' => 180, 'min_hours' => 0, 'max_days' => 183, 'max_hours' => 0, 'type' => 'per_day', 'sort_order' => 4, 'max_km_per_day' => 100, 'max_km_per_package' => null],
                ['name' => 'Annual Contract', 'min_days' => 365, 'min_hours' => 0, 'max_days' => 366, 'max_hours' => 0, 'type' => 'per_day', 'sort_order' => 5, 'max_km_per_day' => 100, 'max_km_per_package' => null],
            ],
            'corporate_self' => [
                ['name' => 'Weekly Contract', 'min_days' => 7, 'min_hours' => 0, 'max_days' => 7, 'max_hours' => 0, 'type' => 'per_day', 'sort_order' => 1, 'max_km_per_day' => 100, 'max_km_per_package' => null],
                ['name' => 'Monthly Contract', 'min_days' => 30, 'min_hours' => 0, 'max_days' => 31, 'max_hours' => 0, 'type' => 'per_day', 'sort_order' => 2, 'max_km_per_day' => 100, 'max_km_per_package' => null],
                ['name' => 'Quarterly Contract', 'min_days' => 90, 'min_hours' => 0, 'max_days' => 93, 'max_hours' => 0, 'type' => 'per_day', 'sort_order' => 3, 'max_km_per_day' => 100, 'max_km_per_package' => null],
                ['name' => 'Semi-Annual Contract', 'min_days' => 180, 'min_hours' => 0, 'max_days' => 183, 'max_hours' => 0, 'type' => 'per_day', 'sort_order' => 4, 'max_km_per_day' => 100, 'max_km_per_package' => null],
                ['name' => 'Annual Contract', 'min_days' => 365, 'min_hours' => 0, 'max_days' => 366, 'max_hours' => 0, 'type' => 'per_day', 'sort_order' => 5, 'max_km_per_day' => 100, 'max_km_per_package' => null],
            ],
        ];

        foreach ($serviceTypesWithSlabs as $serviceCode => $slabs) {
            $serviceType = ServiceType::where('code', $serviceCode)->first();

            if (!$serviceType) {
                continue;
            }

            foreach ($slabs as $slabData) {
                VehiclePricingSlabDefinition::updateOrCreate(
                    [
                        'service_type_id' => $serviceType->id,
                        'name' => $slabData['name'],
                    ],
                    [
                        'min_hours' => $slabData['min_hours'] ?? null,
                        'max_hours' => $slabData['max_hours'] ?? null,
                        'min_days' => $slabData['min_days'] ?? null,
                        'max_days' => $slabData['max_days'] ?? null,
                        'type' => $slabData['type'],
                        'sort_order' => $slabData['sort_order'],
                        'max_km_per_day' => $slabData['max_km_per_day'] ?? null,
                        'max_km_per_package' => $slabData['max_km_per_package'] ?? null,
                        'is_active' => true,
                    ]
                );
            }
        }
    }


    /**
     * Seed common rate definitions for each service type
     */
    private function seedCommonRateDefinitions(): void
    {
        $serviceTypesWithRates = [
            'chauffeur_driven' => [
                // Vehicle delivery and pickup rates
                ['name' => 'Vehicle Delivery Rate Per KM', 'code' => 'vehicle_delivery_rate_per_km', 'common_rate_type' => 'per_km', 'rate' => 50.00, 'sort_order' => 1],
                ['name' => 'Vehicle Pickup Rate Per KM', 'code' => 'vehicle_pickup_rate_per_km', 'common_rate_type' => 'per_km', 'rate' => 50.00, 'sort_order' => 2],
                // Extra KM charges
                ['name' => 'Extra KM Rate', 'code' => 'extra_km_rate', 'common_rate_type' => 'per_km', 'rate' => 60.00, 'sort_order' => 3],
                ['name' => 'Driver Allowance', 'code' => 'driver_allowance', 'common_rate_type' => 'per_day', 'rate' => 500.00, 'sort_order' => 4],
            ],
            'self_driven' => [
                // Vehicle delivery and pickup rates  
                ['name' => 'Vehicle Delivery Rate Per KM', 'code' => 'vehicle_delivery_rate_per_km', 'common_rate_type' => 'per_km', 'rate' => 35.00, 'sort_order' => 1],
                ['name' => 'Vehicle Pickup Rate Per KM', 'code' => 'vehicle_pickup_rate_per_km', 'common_rate_type' => 'per_km', 'rate' => 35.00, 'sort_order' => 2],
                // Extra KM charges
                ['name' => 'Extra KM Rate', 'code' => 'extra_km_rate', 'common_rate_type' => 'per_km', 'rate' => 45.00, 'sort_order' => 3],
            ],
            'wedding_hire' => [
                // Vehicle delivery and pickup rates
                ['name' => 'Vehicle Delivery Rate Per KM', 'code' => 'vehicle_delivery_rate_per_km', 'common_rate_type' => 'per_km', 'rate' => 60.00, 'sort_order' => 1],
                ['name' => 'Vehicle Pickup Rate Per KM', 'code' => 'vehicle_pickup_rate_per_km', 'common_rate_type' => 'per_km', 'rate' => 60.00, 'sort_order' => 2],
                // Extra charges
                ['name' => 'Extra KM Rate', 'code' => 'extra_km_rate', 'common_rate_type' => 'per_km', 'rate' => 70.00, 'sort_order' => 3],
                ['name' => 'Extra Hour Rate', 'code' => 'extra_hour_rate', 'common_rate_type' => 'per_hour', 'rate' => 1500.00, 'sort_order' => 4],
                ['name' => 'Decoration Charge', 'code' => 'decoration_charge', 'common_rate_type' => 'flat_rate', 'rate' => 2500.00, 'sort_order' => 5],
               
            ],
            'airport_drop' => [
                // Vehicle delivery and pickup rates
                ['name' => 'Vehicle Delivery Rate Per KM', 'code' => 'vehicle_delivery_rate_per_km', 'common_rate_type' => 'per_km', 'rate' => 55.00, 'sort_order' => 1],
                ['name' => 'Vehicle Pickup Rate Per KM', 'code' => 'vehicle_pickup_rate_per_km', 'common_rate_type' => 'per_km', 'rate' => 55.00, 'sort_order' => 2],
                // Base rate per KM for the service
                ['name' => 'Service Rate Per KM', 'code' => 'service_rate_per_km', 'common_rate_type' => 'per_km', 'rate' => 80.00, 'sort_order' => 3],
                ['name' => 'Stop Charge', 'code' => 'stop_charge', 'common_rate_type' => 'per_stop', 'rate' => 500.00, 'sort_order' => 4],
            ],
            'airport_pickup' => [
                // Vehicle delivery and pickup rates
                ['name' => 'Vehicle Delivery Rate Per KM', 'code' => 'vehicle_delivery_rate_per_km', 'common_rate_type' => 'per_km', 'rate' => 55.00, 'sort_order' => 1],
                ['name' => 'Vehicle Pickup Rate Per KM', 'code' => 'vehicle_pickup_rate_per_km', 'common_rate_type' => 'per_km', 'rate' => 55.00, 'sort_order' => 2],
                // Base rate per KM for the service
                ['name' => 'Service Rate Per KM', 'code' => 'service_rate_per_km', 'common_rate_type' => 'per_km', 'rate' => 80.00, 'sort_order' => 3],
                ['name' => 'Waiting Charge Per Hour', 'code' => 'waiting_charge_per_hour', 'common_rate_type' => 'per_hour', 'rate' => 800.00, 'sort_order' => 4],
            ],
            'transfers' => [
                // Vehicle delivery and pickup rates
                ['name' => 'Vehicle Delivery Rate Per KM', 'code' => 'vehicle_delivery_rate_per_km', 'common_rate_type' => 'per_km', 'rate' => 50.00, 'sort_order' => 1],
                ['name' => 'Vehicle Pickup Rate Per KM', 'code' => 'vehicle_pickup_rate_per_km', 'common_rate_type' => 'per_km', 'rate' => 50.00, 'sort_order' => 2],
                // Base rate per KM for the service
                ['name' => 'Service Rate Per KM', 'code' => 'service_rate_per_km', 'common_rate_type' => 'per_km', 'rate' => 75.00, 'sort_order' => 3],
                ['name' => 'Stop Charge', 'code' => 'stop_charge', 'common_rate_type' => 'per_stop', 'rate' => 300.00, 'sort_order' => 4],
            ],
            'break_down_service' => [
                // Vehicle delivery and pickup rates (to get to the breakdown location)
                ['name' => 'Vehicle Delivery Rate Per KM', 'code' => 'vehicle_delivery_rate_per_km', 'common_rate_type' => 'per_km', 'rate' => 100.00, 'sort_order' => 1],
                ['name' => 'Vehicle Pickup Rate Per KM', 'code' => 'vehicle_pickup_rate_per_km', 'common_rate_type' => 'per_km', 'rate' => 100.00, 'sort_order' => 2],
                // Service rates
                ['name' => 'Emergency Base Rate', 'code' => 'emergency_base_rate', 'common_rate_type' => 'flat_rate', 'rate' => 5000.00, 'sort_order' => 3],
                ['name' => 'Service Rate Per KM', 'code' => 'service_rate_per_km', 'common_rate_type' => 'per_km', 'rate' => 120.00, 'sort_order' => 4],
                ['name' => 'Hourly Rate', 'code' => 'hourly_rate', 'common_rate_type' => 'per_hour', 'rate' => 2000.00, 'sort_order' => 5],
            ],
            'corporate' => [
                // Vehicle delivery and pickup rates
                ['name' => 'Vehicle Delivery Rate Per KM', 'code' => 'vehicle_delivery_rate_per_km', 'common_rate_type' => 'per_km', 'rate' => 40.00, 'sort_order' => 1],
                ['name' => 'Vehicle Pickup Rate Per KM', 'code' => 'vehicle_pickup_rate_per_km', 'common_rate_type' => 'per_km', 'rate' => 40.00, 'sort_order' => 2],
                // Extra KM charges
                ['name' => 'Extra KM Rate', 'code' => 'extra_km_rate', 'common_rate_type' => 'per_km', 'rate' => 50.00, 'sort_order' => 3],
                ['name' => 'Overtime Rate Per Hour', 'code' => 'overtime_rate_per_hour', 'common_rate_type' => 'per_hour', 'rate' => 1000.00, 'sort_order' => 4],
                 ['name' => 'Driver Allowance', 'code' => 'driver_allowance', 'common_rate_type' => 'per_day', 'rate' => 500.00, 'sort_order' => 5],
            ],
            'corporate_self' => [
                // Vehicle delivery and pickup rates
                ['name' => 'Vehicle Delivery Rate Per KM', 'code' => 'vehicle_delivery_rate_per_km', 'common_rate_type' => 'per_km', 'rate' => 40.00, 'sort_order' => 1],
                ['name' => 'Vehicle Pickup Rate Per KM', 'code' => 'vehicle_pickup_rate_per_km', 'common_rate_type' => 'per_km', 'rate' => 40.00, 'sort_order' => 2],
                // Extra KM charges
                ['name' => 'Extra KM Rate', 'code' => 'extra_km_rate', 'common_rate_type' => 'per_km', 'rate' => 45.00, 'sort_order' => 3],
                ['name' => 'Overtime Rate Per Hour', 'code' => 'overtime_rate_per_hour', 'common_rate_type' => 'per_hour', 'rate' => 1000.00, 'sort_order' => 4],
            ]
        ];

        // Get all vehicle groups for pricing assignments
        $vehicleGroups = VehicleGroup::all();

        foreach ($serviceTypesWithRates as $serviceCode => $rates) {
            $serviceType = ServiceType::where('code', $serviceCode)->first();

            if ($serviceType) {
                foreach ($rates as $rateData) {
                    // Try to find existing common rate definition with simple name
                    $commonRateDefinition = VehiclePricingCommonRateDefinition::where('service_type_id', $serviceType->id)
                        ->where('name', $rateData['name'])
                        ->first();

                    // If it doesn't exist, create it
                    if (!$commonRateDefinition) {
                        $commonRateDefinition = VehiclePricingCommonRateDefinition::create([
                            'service_type_id' => $serviceType->id,
                            'name' => $rateData['name'],
                            'code' => $rateData['code'],
                            'common_rate_type' => $rateData['common_rate_type'],
                            'sort_order' => $rateData['sort_order'],
                            'is_active' => true,
                            'description' => "Common rate for {$rateData['name']} in {$serviceType->name} service"
                        ]);
                    } else {
                        // Update existing definition with any missing details
                        $commonRateDefinition->update([
                            'code' => $rateData['code'],
                            'common_rate_type' => $rateData['common_rate_type'],
                            'sort_order' => $rateData['sort_order'],
                            'is_active' => true,
                            'description' => "Common rate for {$rateData['name']} in {$serviceType->name} service"
                        ]);
                    }

                    // Create vehicle group pricing entries for all vehicle groups
                    foreach ($vehicleGroups as $vehicleGroup) {
                        VehicleGroupCommonRatePricing::updateOrCreate(
                            [
                                'vehicle_group_id' => $vehicleGroup->id,
                                'common_rate_definition_id' => $commonRateDefinition->id
                            ],
                            [
                                'value' => $rateData['rate'], // Store the rate value here
                                'is_active' => true
                            ]
                        );
                    }
                }
            }
        }
    }

    /**
     * Get predefined system variables for each service type
     * These variables are system-managed and cannot be manually created/modified by users
     */
    private function getPredefinedSystemVariables(): array
    {
        return [
            // Core system variables available across all service types
            'core_system' => [
                ['name' => 'slab_rate', 'type' => 'slab_rate', 'description' => 'Base rate from slab definition based on duration/package', 'is_required' => true, 'category' => 'base'],
                ['name' => 'duration_hours', 'type' => 'duration', 'description' => 'Service duration in hours', 'is_required' => false, 'default_value' => 0, 'category' => 'duration'],
                ['name' => 'duration_days', 'type' => 'duration', 'description' => 'Service duration in days', 'is_required' => false, 'default_value' => 0, 'category' => 'duration'],
                ['name' => 'total_distance', 'type' => 'distance', 'description' => 'Total service distance in KM', 'is_required' => false, 'default_value' => 0, 'category' => 'distance'],
                ['name' => 'delivery_distance', 'type' => 'distance', 'description' => 'Vehicle delivery distance in KM', 'is_required' => false, 'default_value' => 0, 'category' => 'distance'],
                ['name' => 'pickup_distance', 'type' => 'distance', 'description' => 'Vehicle pickup distance in KM (return distance)', 'is_required' => false, 'default_value' => 0, 'category' => 'distance'],
                ['name' => 'extra_km', 'type' => 'distance', 'description' => 'Extra KM beyond package/daily limit', 'is_required' => false, 'default_value' => 0, 'category' => 'distance'],
                ['name' => 'extra_hours', 'type' => 'duration', 'description' => 'Extra hours beyond package limit', 'is_required' => false, 'default_value' => 0, 'category' => 'duration'],
                ['name' => 'number_of_days', 'type' => 'duration', 'description' => 'Number of days for the booking', 'is_required' => false, 'default_value' => 0, 'category' => 'duration'],
            ],
            // Special calculation variables
            'special' => [
                ['name' => 'discount_percentage', 'type' => 'fixed_value', 'description' => 'Discount percentage (0.1 = 10%)', 'is_required' => false, 'default_value' => 0, 'category' => 'adjustment'],
                ['name' => 'additional_stops', 'type' => 'fixed_value', 'description' => 'Number of additional stops', 'is_required' => false, 'default_value' => 0, 'category' => 'service'],
                ['name' => 'waiting_hours', 'type' => 'duration', 'description' => 'Additional waiting time in hours', 'is_required' => false, 'default_value' => 0, 'category' => 'duration'],
                ['name' => 'recovery_hours', 'type' => 'duration', 'description' => 'Hours spent on recovery operation', 'is_required' => false, 'default_value' => 0, 'category' => 'duration'],
                ['name' => 'stops', 'type' => 'fixed_value', 'description' => 'Number of stops in transfer', 'is_required' => false, 'default_value' => 0, 'category' => 'service'],
                ['name' => 'overtime_hours', 'type' => 'duration', 'description' => 'Overtime hours beyond contract', 'is_required' => false, 'default_value' => 0, 'category' => 'duration'],
            ]
        ];
    }

    /**
     * Build variables array for a specific service type using predefined system variables
     * and dynamically loading common rate variables from existing common rate definitions
     */
    private function buildServiceTypeVariables(string $serviceCode, array $variableNames): array
    {
        $predefinedVars = $this->getPredefinedSystemVariables();
        $variables = [];
        
        // Always include core system variables
        foreach ($predefinedVars['core_system'] as $var) {
            if (in_array($var['name'], $variableNames)) {
                $variables[] = $var;
            }
        }
        
        // Dynamically add service-specific common rate variables from existing definitions
        $serviceType = ServiceType::where('code', $serviceCode)->first();
        if ($serviceType) {
            $commonRates = VehiclePricingCommonRateDefinition::where('service_type_id', $serviceType->id)
                ->where('is_active', true)
                ->get();
            
            foreach ($commonRates as $commonRate) {
                // Only include if this common rate is requested in the variable names
                if (in_array($commonRate->code, $variableNames)) {
                    $variables[] = [
                        'name' => $commonRate->code,
                        'type' => 'common_rate',
                        'description' => $commonRate->description ?: $commonRate->name,
                        'is_required' => false,
                        'default_value' => 0,
                        'category' => $this->categorizeCommonRate($commonRate->code),
                        'source_id' => $commonRate->id, // Reference to the common rate definition
                        'common_rate_code' => $commonRate->code
                    ];
                }
            }
        }
        
        // Add special variables if needed
        foreach ($predefinedVars['special'] as $var) {
            if (in_array($var['name'], $variableNames)) {
                $variables[] = $var;
            }
        }
        
        return $variables;
    }

    /**
     * Categorize common rate by its code for better organization
     */
    private function categorizeCommonRate(string $code): string
    {
        $categories = [
            'vehicle_delivery_rate_per_km' => 'logistics',
            'vehicle_pickup_rate_per_km' => 'logistics',
            'extra_km_rate' => 'overage',
            'extra_hour_rate' => 'overage',
            'service_rate_per_km' => 'service',
            'stop_charge' => 'service',
            'waiting_charge_per_hour' => 'service',
            'decoration_charge' => 'special',
            'emergency_base_rate' => 'special',
            'hourly_rate' => 'service',
            'overtime_rate_per_hour' => 'overage',
        ];

        return $categories[$code] ?? 'rate';
    }

    /**
     * Get available variable names for a service type based on existing common rates
     * This helps ensure only valid variables are referenced in formulas
     */
    private function getAvailableVariableNames(string $serviceCode): array
    {
        $coreVariables = ['slab_rate', 'duration_hours', 'duration_days', 'total_distance', 'delivery_distance', 'pickup_distance', 'extra_km', 'extra_hours', 'number_of_days'];
        $specialVariables = ['discount_percentage', 'additional_stops', 'waiting_hours', 'recovery_hours', 'stops', 'overtime_hours'];
        
        // Get available common rate variables from database
        $serviceType = ServiceType::where('code', $serviceCode)->first();
        $commonRateVariables = [];
        
        if ($serviceType) {
            $commonRates = VehiclePricingCommonRateDefinition::where('service_type_id', $serviceType->id)
                ->where('is_active', true)
                ->pluck('code')
                ->toArray();
            $commonRateVariables = $commonRates;
        }
        
        return array_merge($coreVariables, $specialVariables, $commonRateVariables);
    }

    /**
     * Validate that all variables in a formula are available for the service type
     */
    private function validateFormulaVariables(string $serviceCode, string $formula, array $variableNames): array
    {
        $availableVariables = $this->getAvailableVariableNames($serviceCode);
        $missingVariables = array_diff($variableNames, $availableVariables);
        
        if (!empty($missingVariables)) {
            $this->command->warn("⚠ Service '{$serviceCode}' formula references missing variables: " . implode(', ', $missingVariables));
            $this->command->info("Available variables: " . implode(', ', $availableVariables));
        }
        
        return $missingVariables;
    }

    /**
     * Seed calculation definitions for each service type
     */
    private function seedCalculationDefinitions(): void
    {
        $calculationDefinitions = [
            [
                'service_code' => 'chauffeur_driven',
                'name' => 'Standard Chauffeur Driven Calculation',
                'description' => 'Standard pricing calculation for chauffeur driven services with slab rate, delivery/pickup charges, and extra KM',
                'formula' => 'slab_rate + (delivery_distance * vehicle_delivery_rate_per_km) + (pickup_distance * vehicle_pickup_rate_per_km) + (extra_km * extra_km_rate) + (driver_allowance * number_of_days)',
                'variable_names' => ['slab_rate', 'delivery_distance', 'pickup_distance', 'extra_km', 'vehicle_delivery_rate_per_km', 'vehicle_pickup_rate_per_km', 'extra_km_rate', 'driver_allowance', 'number_of_days'],
                'conditions' => []
            ],
            [
                'service_code' => 'self_driven',
                'name' => 'Standard Self Driven Calculation',
                'description' => 'Standard pricing calculation for self driven services with slab rate, delivery/pickup charges, and extra KM',
                'formula' => 'slab_rate + (delivery_distance * vehicle_delivery_rate_per_km) + (pickup_distance * vehicle_pickup_rate_per_km) + (extra_km * extra_km_rate)',
                'variable_names' => ['slab_rate', 'delivery_distance', 'pickup_distance', 'extra_km', 'vehicle_delivery_rate_per_km', 'vehicle_pickup_rate_per_km', 'extra_km_rate'],
                'conditions' => []
            ],
            [
                'service_code' => 'wedding_hire',
                'name' => 'Wedding Service Calculation',
                'description' => 'Pricing calculation for wedding hire services with package rate, delivery/pickup, decoration, extra hours and extra KM',
                'formula' => 'slab_rate + (delivery_distance * vehicle_delivery_rate_per_km) + (pickup_distance * vehicle_pickup_rate_per_km) + decoration_charge + (extra_hours * extra_hour_rate) + (extra_km * extra_km_rate)',
                'variable_names' => ['slab_rate', 'delivery_distance', 'pickup_distance', 'extra_hours', 'extra_km', 'vehicle_delivery_rate_per_km', 'vehicle_pickup_rate_per_km', 'decoration_charge', 'extra_hour_rate', 'extra_km_rate'],
                'conditions' => []
            ],
            [
                'service_code' => 'airport_drop',
                'name' => 'Airport Drop Calculation',
                'description' => 'KM-based calculation for airport drop service with delivery/pickup charges and additional stops',
                'formula' => '(total_distance * service_rate_per_km) + (delivery_distance * vehicle_delivery_rate_per_km) + (pickup_distance * vehicle_pickup_rate_per_km) + (additional_stops * stop_charge)',
                'variable_names' => ['total_distance', 'delivery_distance', 'pickup_distance', 'additional_stops', 'service_rate_per_km', 'vehicle_delivery_rate_per_km', 'vehicle_pickup_rate_per_km', 'stop_charge'],
                'conditions' => []
            ],
            [
                'service_code' => 'airport_pickup',
                'name' => 'Airport Pickup Calculation',
                'description' => 'KM-based calculation for airport pickup service with delivery/pickup charges and waiting time',
                'formula' => '(total_distance * service_rate_per_km) + (delivery_distance * vehicle_delivery_rate_per_km) + (pickup_distance * vehicle_pickup_rate_per_km) + (waiting_hours * waiting_charge_per_hour)',
                'variable_names' => ['total_distance', 'delivery_distance', 'pickup_distance', 'waiting_hours', 'service_rate_per_km', 'vehicle_delivery_rate_per_km', 'vehicle_pickup_rate_per_km', 'waiting_charge_per_hour'],
                'conditions' => []
            ],
            [
                'service_code' => 'transfers',
                'name' => 'Transfer Service Calculation',
                'description' => 'KM-based calculation for transfer services with delivery/pickup charges and stop charges',
                'formula' => '(total_distance * service_rate_per_km) + (delivery_distance * vehicle_delivery_rate_per_km) + (pickup_distance * vehicle_pickup_rate_per_km) + (stops * stop_charge)',
                'variable_names' => ['total_distance', 'delivery_distance', 'pickup_distance', 'stops', 'service_rate_per_km', 'vehicle_delivery_rate_per_km', 'vehicle_pickup_rate_per_km', 'stop_charge'],
                'conditions' => []
            ],
            [
                'service_code' => 'break_down_service',
                'name' => 'Breakdown Service Calculation',
                'description' => 'Emergency breakdown service calculation with base rate, KM-based charges, and hourly rates',
                'formula' => 'emergency_base_rate + (total_distance * service_rate_per_km) + (delivery_distance * vehicle_delivery_rate_per_km) + (pickup_distance * vehicle_pickup_rate_per_km) + (recovery_hours * hourly_rate)',
                'variable_names' => ['total_distance', 'delivery_distance', 'pickup_distance', 'recovery_hours', 'emergency_base_rate', 'service_rate_per_km', 'vehicle_delivery_rate_per_km', 'vehicle_pickup_rate_per_km', 'hourly_rate'],
                'conditions' => []
            ],
            [
                'service_code' => 'corporate',
                'name' => 'Corporate Contract Calculation',
                'description' => 'Corporate service calculation with contract rates, delivery/pickup charges, extra KM and overtime',
                'formula' => '(slab_rate * (1 - discount_percentage)) + (delivery_distance * vehicle_delivery_rate_per_km) + (pickup_distance * vehicle_pickup_rate_per_km) + (extra_km * extra_km_rate) + (overtime_hours * overtime_rate_per_hour)',
                'variable_names' => ['slab_rate', 'discount_percentage', 'delivery_distance', 'pickup_distance', 'extra_km', 'overtime_hours', 'vehicle_delivery_rate_per_km', 'vehicle_pickup_rate_per_km', 'extra_km_rate', 'overtime_rate_per_hour'],
                'conditions' => [
                    ['field' => 'customer_type', 'operator' => 'equals', 'value' => 'corporate']
                ]
            ],
            [
                'service_code' => 'corporate_self',
                'name' => 'Corporate Self Drive Calculation',
                'description' => 'Corporate self drive calculation with contract rates, delivery/pickup charges, extra KM and overtime',
                'formula' => '(slab_rate * (1 - discount_percentage)) + (delivery_distance * vehicle_delivery_rate_per_km) + (pickup_distance * vehicle_pickup_rate_per_km) + (extra_km * extra_km_rate) + (overtime_hours * overtime_rate_per_hour)',
                'variable_names' => ['slab_rate', 'discount_percentage', 'delivery_distance', 'pickup_distance', 'extra_km', 'overtime_hours', 'vehicle_delivery_rate_per_km', 'vehicle_pickup_rate_per_km', 'extra_km_rate', 'overtime_rate_per_hour'],
                'conditions' => [
                    ['field' => 'customer_type', 'operator' => 'equals', 'value' => 'corporate']
                ]
            ]
        ];

        foreach ($calculationDefinitions as $definition) {
            $serviceType = ServiceType::where('code', $definition['service_code'])->first();

            if ($serviceType) {
                // Validate that all referenced variables are available
                $missingVariables = $this->validateFormulaVariables(
                    $definition['service_code'], 
                    $definition['formula'], 
                    $definition['variable_names']
                );

                // Build variables dynamically from available common rates and system variables
                $variables = $this->buildServiceTypeVariables($definition['service_code'], $definition['variable_names']);

                $result = VehiclePricingCalculationDefinition::updateOrCreate(
                    [
                        'service_type_id' => $serviceType->id,
                        'name' => $definition['name']
                    ],
                    [
                        'description' => $definition['description'],
                        'formula' => $definition['formula'],
                        'variables' => $variables,
                        'conditions' => $definition['conditions'],
                        'status' => 'active',
                        'created_by' => '8b878d30-1444-4692-b6e8-ca2740c3eab4', // Admin user UUID
                        'updated_by' => '8b878d30-1444-4692-b6e8-ca2740c3eab4'
                    ]
                );

                if ($result->wasRecentlyCreated) {
                    $this->command->info("✓ Created calculation definition: {$definition['name']}");
                } else {
                    $this->command->info("✓ Updated calculation definition: {$definition['name']}");
                }

                // Show variable count for debugging
                $this->command->info("  → Variables defined: " . count($variables) . " (" . implode(', ', array_column($variables, 'name')) . ")");
                
                if (!empty($missingVariables)) {
                    $this->command->warn("  → Missing variables will be skipped: " . implode(', ', $missingVariables));
                }

            } else {
                $this->command->warn("✗ Service type '{$definition['service_code']}' not found. Skipping: {$definition['name']}");
            }
        }
    }
}
