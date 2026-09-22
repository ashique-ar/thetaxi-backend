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
            TenantDecisionDefinitionSeeder::class,

            // Core data the HR module (and much of the rest of the app) depends
            // on: a default Company, then Staff-linked User accounts. Order
            // matters — StaffSeeder attaches each User's Staff row to the
            // default Company created by CompanySeeder.
            CompanySeeder::class,
            StaffSeeder::class,

            // HR module defaults. Each is idempotent and safe to re-run; order
            // matters because later seeders look up rows created by earlier
            // ones (positions before employment assignments; Staff/Company
            // before everything).
            HrOrganizationDefaultsSeeder::class,
            HrPeopleCoreDefaultsSeeder::class,
            HrLeaveTypesSeeder::class,
            HrPayrollDefaultsSeeder::class,
            HrAttendanceDeviceDefaultsSeeder::class,

            // Must run after CompanySeeder/StaffSeeder: it only seeds work
            // calendars/shifts/policies/rosters once a default company with an
            // active Staff user exists.
            SriLankaAttendanceDefaultsSeeder::class,
            CustomerCodeSettingsSeeder::class,
            StaffCodeSettingsSeeder::class,
            DriverCodeSettingsSeeder::class,

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
