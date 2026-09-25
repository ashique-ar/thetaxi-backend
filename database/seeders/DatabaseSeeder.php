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
            // Safe on new and existing client databases. Creates only missing
            // permissions/roles and adds baseline grants without removing any.
            AllPermissionsSeeder::class,
            CorporateRoleStarterSeeder::class,
            CorporateStaffTransportStarterSeeder::class,
            CorporateReferenceSequenceSeeder::class,

            // CustomerCodeSettingsSeeder::class,
            // StaffCodeSettingsSeeder::class,
            // DriverCodeSettingsSeeder::class,

                // Basic/core seeders (uncomment as needed)
                // CorporatePermissionsSeeder::class,
                // AdminUserSeeder::class,
                // SmsManagementPermissionsSeeder::class,

                // Service type defaults for frontend behavior and duration handling
            // ServiceTypeDefaultsSeeder::class,
            // ClonePublicServiceTypesToPortalSeeder::class,

            // Admin/other seeders can remain commented; enable as needed for local dev
            // CountrySeeder::class,
            // CountriesFromJsonSeeder::class,
            // StateSeeder::class,
            // CurrencySeeder::class,
            // VipTypeSeeder::class,
            // RegionSeeder::class,
            // BookingChannelSeeder::class,
            // AirportSeeder::class,
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
            // DrivingLiscenceTypeSeeder::class,
            // ComprehensivePricingSeeder::class,
            // CorporatePricingDemoSeeder::class,
            // PointToPointPricingSeeder::class,
            // VehicleAddonSeeder::class,
            // VehicleAddonDependencySeeder::class,
            // AgentSeeder::class,
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
