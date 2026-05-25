<?php

namespace Database\Seeders;

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

        foreach ($staffMembers as $staffData) {
            $roleName = $staffData['role'];
            unset($staffData['role']);

            // Create user
            $user = User::firstOrCreate($staffData);

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
        }

        $this->command->info('Staff data seeded successfully!');
        $this->command->info('Created 13 staff members with different roles');
        $this->command->info('All staff have password: staff123');
    }
}
