<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Driver\Driver;
use App\Models\Country;
use App\Models\State;
use App\Models\DrivingLicenseType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class DriverSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get driver role
        $driverRole = Role::where('name', 'driver')->where('guard_name', 'api')->first();
        
        // Get Sri Lanka and its states for realistic data
        $sriLanka = Country::where('name', 'Sri Lanka')->first();
        $states = $sriLanka ? State::where('country_id', $sriLanka->id)->get() : collect();
        
        // Get driving license types
        $heavyVehicleType = DrivingLicenseType::where('name', 'Heavy Vehicle')->first();
        $normalType = DrivingLicenseType::where('name', 'Normal')->first();
        
        $drivers = [
            [
                'user_data' => [
                    'first_name' => 'Nimal',
                    'last_name' => 'Bandara',
                    'email' => 'nimal.driver@casons.lk',
                    'phone' => fake()->e164PhoneNumber(),
                    'password' => Hash::make('driver123'),
                    'email_verified_at' => now(),
                    'is_active' => true,
                ],
                'driver_data' => [
                    'license_no' => 'DL001234',
                    'license_type' => $heavyVehicleType ? $heavyVehicleType->id : null,
                    'license_expiry' => now()->addYears(3),
                    'nic' => '197856789012',
                    'address' => '123 Driver Lane, Colombo 05',
                    'postal_code' => '00500',
                    'dob' => now()->subYears(45),
                    'remarks' => 'Experienced driver with clean record. Languages: Sinhala, English. Vehicle types: car, van, bus. Rating: 4.8/5',
                    'is_active' => true,
                ]
            ],
            [
                'user_data' => [
                    'first_name' => 'Sunil',
                    'last_name' => 'Jayasuriya',
                    'email' => 'sunil.driver@casons.lk',
                    'phone' => fake()->e164PhoneNumber(),
                    'password' => Hash::make('driver123'),
                    'email_verified_at' => now(),
                    'is_active' => true,
                ],
                'driver_data' => [
                    'license_no' => 'DL002345',
                    'license_type' => $normalType ? $normalType->id : null,
                    'license_expiry' => now()->addYears(2),
                    'nic' => '198267890123',
                    'address' => '456 Temple Road, Kandy',
                    'postal_code' => '20000',
                    'dob' => now()->subYears(41),
                    'remarks' => 'Reliable driver with good customer service. Languages: Sinhala, English, Tamil. Vehicle types: car, suv. Rating: 4.5/5',
                    'is_active' => true,
                ]
            ],
            [
                'user_data' => [
                    'first_name' => 'Ruwan',
                    'last_name' => 'Wijesinghe',
                    'email' => 'ruwan.driver@casons.lk',
                    'phone' => fake()->e164PhoneNumber(),
                    'password' => Hash::make('driver123'),
                    'email_verified_at' => now(),
                    'is_active' => true,
                ],
                'driver_data' => [
                    'license_no' => 'DL003456',
                    'license_type' => $heavyVehicleType ? $heavyVehicleType->id : null,
                    'license_expiry' => now()->addYears(4),
                    'nic' => '197445678901',
                    'address' => '789 Beach Road, Galle',
                    'postal_code' => '80000',
                    'dob' => now()->subYears(49),
                    'remarks' => 'Senior driver with extensive experience. Languages: Sinhala, English. Vehicle types: car, van, bus, truck. Rating: 4.9/5',
                    'is_active' => true,
                ]
            ],
            [
                'user_data' => [
                    'first_name' => 'Asanka',
                    'last_name' => 'Rajapaksa',
                    'email' => 'asanka.driver@casons.lk',
                    'phone' => fake()->e164PhoneNumber(),
                    'password' => Hash::make('driver123'),
                    'email_verified_at' => now(),
                    'is_active' => true,
                ],
                'driver_data' => [
                    'license_no' => 'DL004567',
                    'license_type' => $normalType ? $normalType->id : null,
                    'license_expiry' => now()->addYears(1),
                    'nic' => '199078901234',
                    'address' => '321 Hill Street, Nuwara Eliya',
                    'postal_code' => '22200',
                    'dob' => now()->subYears(33),
                    'remarks' => 'Young and energetic driver. Languages: Sinhala, English. Vehicle types: car, suv. Rating: 4.3/5',
                    'is_active' => true,
                ]
            ],
            [
                'user_data' => [
                    'first_name' => 'Mahinda',
                    'last_name' => 'Gunawardena',
                    'email' => 'mahinda.driver@casons.lk',
                    'phone' => fake()->e164PhoneNumber(),
                    'password' => Hash::make('driver123'),
                    'email_verified_at' => now(),
                    'is_active' => true,
                ],
                'driver_data' => [
                    'license_no' => 'DL005678',
                    'license_type' => $heavyVehicleType ? $heavyVehicleType->id : null,
                    'license_expiry' => now()->addYears(5),
                    'nic' => '198189012345',
                    'address' => '654 Lake Road, Anuradhapura',
                    'postal_code' => '50000',
                    'dob' => now()->subYears(42),
                    'remarks' => 'Dedicated and punctual driver. Languages: Sinhala. Vehicle types: car, van, bus. Rating: 4.7/5',
                    'is_active' => true,
                ]
            ],
            [
                'user_data' => [
                    'first_name' => 'Kumari',
                    'last_name' => 'Seneviratne',
                    'email' => 'kumari.driver@casons.lk',
                    'phone' => fake()->e164PhoneNumber(),
                    'password' => Hash::make('driver123'),
                    'email_verified_at' => now(),
                    'is_active' => true,
                ],
                'driver_data' => [
                    'license_no' => 'DL006789',
                    'license_type' => $normalType ? $normalType->id : null,
                    'license_expiry' => now()->addYears(3),
                    'nic' => '198890123456',
                    'address' => '987 Main Street, Matara',
                    'postal_code' => '81000',
                    'dob' => now()->subYears(35),
                    'remarks' => 'Professional female driver with excellent safety record. Languages: Sinhala, English. Vehicle types: car. Rating: 4.6/5',
                    'is_active' => true,
                ]
            ]
        ];

        foreach ($drivers as $driverInfo) {
            // Create or find user first
            $user = User::firstOrCreate(
                ['email' => $driverInfo['user_data']['email']],
                $driverInfo['user_data']
            );
            
            // Assign driver role
            if ($driverRole) {
                $user->assignRole($driverRole);
            }

            // Create or update driver profile
            $driverData = $driverInfo['driver_data'];
            $driverData['user_id'] = $user->id;
            
            // Assign random state if available
            if ($states->count() > 0) {
                $driverData['country_id'] = $sriLanka->id;
                $driverData['state_id'] = $states->random()->id;
            }
            
            Driver::firstOrCreate(
                ['user_id' => $user->id],
                $driverData
            );
        }

        $this->command->info('Driver data seeded successfully!');
        $this->command->info('Created 6 drivers with different profiles and availability statuses');
        $this->command->info('All drivers have password: driver123');
    }
}
