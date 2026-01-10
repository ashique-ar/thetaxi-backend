<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            // RolesAndPermissionsSeeder::class,
            // AdminUserSeeder::class,
            // CountrySeeder::class,
            // CountriesFromJsonSeeder::class,
            // StateSeeder::class,
            // CurrencySeeder::class,
            // VipTypeSeeder::class,
            // RegionSeeder::class,
            // BookingChannelSeeder::class,
            // // Vehicle hierarchy seeders (order matters for relationships)
            // VehicleCategorySeeder::class,
            // VehicleClassSeeder::class,
            // VehicleFuelTypeSeeder::class,
            // VehicleTransmissionSeeder::class,
            // VehicleOwnerTypeSeeder::class,
            // VehicleMakeSeeder::class,
            // VehicleModelSeeder::class,
            // VehicleGradeSeeder::class,
            // VehicleGroupSeeder::class,
            // VehicleSeeder::class,
            // // Other seeders
            // DrivingLiscenceTypeSeeder::class,
            // // Pricing definition seeders (must come before pricing data)
            // ComprehensivePricingSeeder::class,
            // // Vehicle addons and dependencies
            // VehicleAddonSeeder::class,
            // VehicleAddonDependencySeeder::class,
            // // Agent system seeders
            // AgentSeeder::class,
            // AgentApiSeeder::class,
            // AgentApiSessionSeeder::class,
            // AgentCommissionSeeder::class,

            // // User profile seeders (depend on roles and other basic data)
            // CustomerSeeder::class,
            // DriverSeeder::class,
            // StaffSeeder::class,
            // CompanySeeder::class,

            // DiscountAndLoyaltySeeder::class
        ]);

        // Uncomment below to create test users
        // User::factory(10)->create();
    }
}
