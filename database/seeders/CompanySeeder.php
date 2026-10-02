<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Company;

class CompanySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // If any company already exists, this is a real/populated database, not a
        // fresh install — don't inject demo companies alongside real ones. The HR
        // seeders that depend on "a default company" (HrOrganizationDefaultsSeeder,
        // StaffSeeder, etc.) simply attach to whichever company already has
        // is_default=true.
        if (Company::query()->exists()) {
            $this->command->info('CompanySeeder skipped: companies already exist in this database.');
            return;
        }

        $companies = [
            [
                'name' => 'Company - Head Office',
                'address' => '181, Gothami Gardens, Gothami Road, Rajagiriya, Sri Lanka.',
                'latitude' => 6.9186278,
                'longitude' => 79.8854714,
                'is_default' => true,
                'city' => 'Colombo',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Company - Mattale Airport Branch',
                'address' => 'Mattala Rajapaksa International Airport',
                'latitude' => 6.2913906,
                'longitude' => 81.1213571,
                'is_default' => false,
                'city' => 'Kandy',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Company - BIA Branch',
                'address' => 'Colombo Bandaranaike International Airport',
                'latitude' => 7.1801596,
                'longitude' => 79.8816746,
                'is_default' => false,
                'city' => 'Galle',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];
        // NOTE: this used to call Company::truncate() before seeding, and generated a
        // fresh random 'id' on every run (even for rows updateOrCreate() matched and
        // updated rather than created). Both make the seeder unsafe to re-run once any
        // other table holds a company_id foreign key (staff, hr_organization_units,
        // hr_payroll_groups, ...): TRUNCATE is refused by Postgres while a referencing
        // FK constraint exists (regardless of row count), and overwriting 'id' on an
        // UPDATE orphans/violates those same FKs. updateOrCreate() by 'name' alone,
        // leaving 'id' untouched on updates (HasUuids assigns it once, on create),
        // keeps this idempotent and safe to re-run.
        foreach ($companies as $company) {
            Company::updateOrCreate(
                ['name' => $company['name']],
                $company
            );
        }

        $this->command->info('Companies seeded successfully!');
    }
}
