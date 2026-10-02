<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class StaffSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // The default Company (created by CompanySeeder) that seeded Staff rows are
        // attached to. Without it we still create the User accounts below, but skip
        // the Staff linkage so HR modules (which require staff.company_id) stay empty
        // rather than pointing at the wrong legal entity.
        $company = Company::where('is_default', true)->whereNull('deleted_at')->first();
        if (!$company) {
            $this->command->warn('StaffSeeder: no default Company found. User accounts will be created, but Staff records (and HR linkage) will be skipped. Run CompanySeeder first.');
        }

        // If Staff records already exist for the default company, this is a
        // real/populated database — don't inject 13 fake demo employees alongside
        // real staff. The HR seeders that need "an active staff user" for audit
        // ownership (HrOrganizationDefaultsSeeder, HrLeaveTypesSeeder, etc.) attach
        // to whichever real Staff rows already exist.
        if ($company && Staff::where('company_id', $company->id)->whereNull('deleted_at')->exists()) {
            $this->command->info('StaffSeeder skipped: Staff records already exist for the default company.');
            $this->printSystemUserHint();
            return;
        }

        $staffMembers = [
            [
                'first_name' => 'Thilini',
                'last_name' => 'Karunaratne',
                'email' => 'thilini.manager@casons.lk',
                'phone' => fake()->e164PhoneNumber(),
                'password' => Hash::make('staff123'),
                'email_verified_at' => now(),
                'is_active' => true,
                'role' => 'manager'
            ],
            [
                'first_name' => 'Namal',
                'last_name' => 'Dissanayake',
                'email' => 'namal.coordinator@casons.lk',
                'phone' => fake()->e164PhoneNumber(),
                'password' => Hash::make('staff123'),
                'email_verified_at' => now(),
                'is_active' => true,
                'role' => 'driver-coordinator'
            ],
            [
                'first_name' => 'Saman',
                'last_name' => 'Wickramasinghe',
                'email' => 'saman.operator@casons.lk',
                'phone' => fake()->e164PhoneNumber(),
                'password' => Hash::make('staff123'),
                'email_verified_at' => now(),
                'is_active' => true,
                'role' => 'operator'
            ],
            [
                'first_name' => 'Dilani',
                'last_name' => 'Perera',
                'email' => 'dilani.supervisor@casons.lk',
                'phone' => fake()->e164PhoneNumber(),
                'password' => Hash::make('staff123'),
                'email_verified_at' => now(),
                'is_active' => true,
                'role' => 'supervisor'
            ],
            [
                'first_name' => 'Ravi',
                'last_name' => 'Mendis',
                'email' => 'ravi.agent@casons.lk',
                'phone' => fake()->e164PhoneNumber(),
                'password' => Hash::make('staff123'),
                'email_verified_at' => now(),
                'is_active' => true,
                'role' => 'agent'
            ],
            [
                'first_name' => 'Yamuna',
                'last_name' => 'Ratnayake',
                'email' => 'yamuna.sales@casons.lk',
                'phone' => fake()->e164PhoneNumber(),
                'password' => Hash::make('staff123'),
                'email_verified_at' => now(),
                'is_active' => true,
                'role' => 'sales-executive'
            ],
            [
                'first_name' => 'Udaya',
                'last_name' => 'Gunasekera',
                'email' => 'udaya.maintenance@casons.lk',
                'phone' => fake()->e164PhoneNumber(),
                'password' => Hash::make('staff123'),
                'email_verified_at' => now(),
                'is_active' => true,
                'role' => 'maintenance-supervisor'
            ],
            [
                'first_name' => 'Madhavi',
                'last_name' => 'Fernando',
                'email' => 'madhavi.accounts@casons.lk',
                'phone' => fake()->e164PhoneNumber(),
                'password' => Hash::make('staff123'),
                'email_verified_at' => now(),
                'is_active' => true,
                'role' => 'accountant'
            ],
            [
                'first_name' => 'Rohan',
                'last_name' => 'Jayawardena',
                'email' => 'rohan.fleet@casons.lk',
                'phone' => fake()->e164PhoneNumber(),
                'password' => Hash::make('staff123'),
                'email_verified_at' => now(),
                'is_active' => true,
                'role' => 'fleet-manager'
            ],
            [
                'first_name' => 'Shanti',
                'last_name' => 'Silva',
                'email' => 'shanti.reception@casons.lk',
                'phone' => fake()->e164PhoneNumber(),
                'password' => Hash::make('staff123'),
                'email_verified_at' => now(),
                'is_active' => true,
                'role' => 'receptionist'
            ],
            [
                'first_name' => 'Kamal',
                'last_name' => 'Perera',
                'email' => 'kamal.qc@casons.lk',
                'phone' => fake()->e164PhoneNumber(),
                'password' => Hash::make('staff123'),
                'email_verified_at' => now(),
                'is_active' => true,
                'role' => 'qc-inspector'
            ],
            [
                'first_name' => 'Nimal',
                'last_name' => 'Rajapaksa',
                'email' => 'nimal.admin@casons.lk',
                'phone' => fake()->e164PhoneNumber(),
                'password' => Hash::make('staff123'),
                'email_verified_at' => now(),
                'is_active' => true,
                'role' => 'admin'
            ],
            [
                'first_name' => 'Saman',
                'last_name' => 'Perera',
                'email' => 'saman.operations@casons.lk',
                'phone' => fake()->e164PhoneNumber(),
                'password' => Hash::make('staff123'),
                'email_verified_at' => now(),
                'is_active' => true,
                'role' => 'operations-manager'
            ]
        ];

        $sequence = 0;
        foreach ($staffMembers as $staffData) {
            $sequence++;
            $roleName = $staffData['role'];
            unset($staffData['role']);

            // Create user. Matched on email alone (not the whole row): password is
            // Hash::make()'d above with a fresh random salt on every seeder run, so
            // matching on the full attribute set would never find the existing row
            // on a re-run and would instead try (and fail, on the unique email
            // index) to insert a duplicate.
            $user = User::firstOrCreate(['email' => $staffData['email']], $staffData);

            // Assign role if it exists
            $role = Role::where('name', $roleName)->where('guard_name', 'api')->first();
            if ($role) {
                $user->assignRole($role);
                $this->command->info("Assigned role '{$roleName}' to {$user->first_name} {$user->last_name}");
            } else {
                $this->command->warn("Role '{$roleName}' not found for {$user->first_name} {$user->last_name}");
                // Assign a default operator role if the specific role doesn't exist
                $operatorRole = Role::where('name', 'operator')->where('guard_name', 'api')->first();
                if ($operatorRole) {
                    $user->assignRole($operatorRole);
                    $this->command->info("Assigned default 'operator' role to {$user->first_name} {$user->last_name}");
                }
            }

            // Every staff user also needs a Staff record: the HR module, the legacy
            // driver/booking screens and Spatie role checks all key off `staff`, not
            // `users`, once a person is a company employee.
            if ($company) {
                $staff = Staff::firstOrCreate(
                    ['user_id' => $user->id],
                    [
                        'company_id' => $company->id,
                        'staff_type' => $roleName,
                        'code' => sprintf('STF-%04d', $sequence),
                        'employment_ended_at' => null,
                    ]
                );
                $this->command->info("Staff record ready for {$user->first_name} {$user->last_name} ({$staff->code})");
            }
        }

        $this->command->info('Staff data seeded successfully!');
        $this->command->info('Created 13 staff members with different roles');
        $this->command->info('All staff have password: staff123');

        $this->printSystemUserHint();
    }

    /**
     * config/hr.php's `system_user_id` (env: HR_SYSTEM_USER_ID) attributes
     * automated HR actions — e.g. the Hikvision sync commands — to a real
     * User rather than leaving actor_user_id null. It has no default because
     * the right user id differs per install, so print the seeded admin
     * user's UUID here as a copy-pasteable hint rather than hardcoding it
     * into config.
     */
    private function printSystemUserHint(): void
    {
        $admin = User::where('email', 'nimal.admin@casons.lk')->first()
            ?? User::role('admin', 'api')->first();

        if ($admin) {
            $this->command->info("Set HR_SYSTEM_USER_ID={$admin->id} in your .env for automated HR actor attribution (e.g. the Hikvision sync commands).");
        }
    }
}
