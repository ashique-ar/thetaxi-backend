<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Service\ServiceType;
use App\Models\Service\ServicePackage;
use App\Models\Vehicle\VehicleGroup;

class ServicePackageSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('🚀 Starting ServicePackage seeder...');

        // Get service types
        $rideNowServiceType = ServiceType::where('code', 'ride_now')->first();
        $pointToPointServiceType = ServiceType::where('code', 'point_to_point')->first();

        if (!$rideNowServiceType) {
            $this->command->error('⚠️  ride_now service type not found. Run ComprehensivePricingSeeder first.');
            return;
        }

        if (!$pointToPointServiceType) {
            $this->command->error('⚠️  point_to_point service type not found. Run ComprehensivePricingSeeder first.');
            return;
        }

        // Get vehicle groups for pricing
        $vehicleGroups = VehicleGroup::select('id', 'name')->get();
        if ($vehicleGroups->isEmpty()) {
            $this->command->error('⚠️  No vehicle groups found. Create vehicle groups first.');
            return;
        }

        $this->command->info("📋 Found {$vehicleGroups->count()} vehicle groups");

        ServicePackage::truncate();

        // Ride Now Service Packages
        $this->seedRideNowPackages($rideNowServiceType, $vehicleGroups);

        // Point to Point Service Packages  
        $this->seedPointToPointPackages($pointToPointServiceType, $vehicleGroups);

        $this->command->info('✅ ServicePackage seeder completed successfully!');
    }

    /**
     * Seed Ride Now (Self-Driven) service packages
     */
    private function seedRideNowPackages(ServiceType $serviceType, $vehicleGroups): void
    {
        $this->command->info('📦 Creating Ride Now packages...');

        $packages = [
            [
                'code' => 'ride_now_4h',
                'name' => '4 Hours Package',
                'description' => 'Self-driven rental for 4 hours with fuel included',
                'max_km_per_package' => null,
                'max_km_per_day' => 100.00,
                'price_multiplier' => 1.0,
                'rate_type' => 'flat',
                'default_duration_hours' => 4,
                'sort_order' => 1,
            ],
            [
                'code' => 'ride_now_8h',
                'name' => '8 Hours Package',
                'description' => 'Self-driven rental for 8 hours with fuel included',
                'max_km_per_package' => null,
                'max_km_per_day' => 180.00,
                'price_multiplier' => 1.0,
                'rate_type' => 'flat',
                'default_duration_hours' => 8,
                'sort_order' => 2,
            ],
            [
                'code' => 'ride_now_12h',
                'name' => '12 Hours Package',
                'description' => 'Self-driven rental for 12 hours with fuel included',
                'max_km_per_package' => null,
                'max_km_per_day' => 250.00,
                'price_multiplier' => 1.0,
                'rate_type' => 'flat',
                'default_duration_hours' => 12,
                'sort_order' => 3,
            ],
            [
                'code' => 'ride_now_1d',
                'name' => '1 Day Package',
                'description' => 'Self-driven rental for 24 hours with fuel included',
                'max_km_per_package' => null,
                'max_km_per_day' => 350.00,
                'price_multiplier' => 1.0,
                'rate_type' => 'flat',
                'default_duration_hours' => 24,
                'sort_order' => 4,
            ],
            [
                'code' => 'ride_now_3d',
                'name' => '3 Days Package',
                'description' => 'Self-driven rental for 3 days with fuel included',
                'max_km_per_package' => null,
                'max_km_per_day' => 900.00,
                'price_multiplier' => 0.85, // Discount for longer rentals
                'rate_type' => 'flat',
                'default_duration_hours' => 72,
                'sort_order' => 5,
            ],
        ];

        $this->createPackages($serviceType, $packages);
    }

    /**
     * Seed Point to Point (Chauffeur-Driven) service packages
     */
    private function seedPointToPointPackages(ServiceType $serviceType, $vehicleGroups): void
    {
        $this->command->info('📦 Creating Point to Point packages...');

        $packages = [
            [
                'code' => 'point_to_point_city',
                'name' => 'City Transfer',
                'description' => 'Point-to-point transfers within city limits',
                'max_km_per_package' => null,
                'max_km_per_day' => 50.00,
                'price_multiplier' => 1.0,
                'rate_type' => 'flat',
                'default_duration_hours' => 2,
                'sort_order' => 1,
            ],
            [
                'code' => 'point_to_point_outstation',
                'name' => 'Outstation Transfer',
                'description' => 'Point-to-point transfers to other cities',
                'max_km_per_package' => null,
                'max_km_per_day' => 150.00,
                'price_multiplier' => 1.0,
                'rate_type' => 'flat',
                'default_duration_hours' => 4,
                'sort_order' => 2,
            ],
            [
                'code' => 'point_to_point_premium',
                'name' => 'Premium Service',
                'description' => 'Premium point-to-point with waiting time included',
                'max_km_per_package' => null,
                'max_km_per_day' => 80.00,
                'price_multiplier' => 1.25, // Premium service multiplier
                'rate_type' => 'flat',
                'default_duration_hours' => 3,
                'sort_order' => 3,
            ],
        ];

        $this->createPackages($serviceType, $packages);
    }

    /**
     * Create packages for all vehicle groups
     */
    private function createPackages(ServiceType $serviceType, array $packages): void
    {
        foreach ($packages as $packageData) {
            // Create the service package
            $package = ServicePackage::create([
                'service_type_id' => $serviceType->id,
                'name' => $packageData['name'],
                'code' => $packageData['code'],
                'description' => $packageData['description'],
                'max_km_per_package' => $packageData['max_km_per_package'],
                'max_km_per_day' => $packageData['max_km_per_day'],
                'price_multiplier' => $packageData['price_multiplier'],
                'rate_type' => $packageData['rate_type'],
                'default_duration_hours' => $packageData['default_duration_hours'],
                'sort_order' => $packageData['sort_order'],
                'is_active' => true,
                'created_user_id' => null,
                'updated_user_id' => null,
            ]);

            $this->command->info("  ✅ Created package: {$package->name}");
        }
    }
}