<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Customer;
use App\Models\Country;
use App\Models\State;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class CustomerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get customer role
        $customerRole = Role::where('name', 'customer')->where('guard_name', 'api')->first();
        
        // Get Sri Lanka and its states for realistic data
        $sriLanka = Country::where('name', 'Sri Lanka')->first();
        $states = $sriLanka ? State::where('country_id', $sriLanka->id)->get() : collect();
        
        $customers = [
            [
                'user_data' => [
                    'first_name' => 'Kasun',
                    'last_name' => 'Perera',
                    'email' => 'kasun.perera@gmail.com',
                    'phone' => fake()->e164PhoneNumber(),
                    'password' => Hash::make('password123'),
                    'email_verified_at' => now(),
                    'is_active' => true,
                ],
                'customer_data' => [
                    'code' => 'CUST001',
                    'type' => 'individual',
                    'sub_type' => 'regular',
                    'category' => 'standard',
                    'nic' => '199123456789',
                    'license_no' => 'B1234567',
                    'license_type' => 'B',
                    'license_expiry' => now()->addYears(3),
                    'dob' => now()->subYears(32),
                    'address' => '123 Galle Road, Colombo 03',
                    'postal_code' => '00300',
                    'gender' => 'male',
                    'city' => 'Colombo',
                ]
            ],
            [
                'user_data' => [
                    'first_name' => 'Sanduni',
                    'last_name' => 'Fernando',
                    'email' => 'sanduni.fernando@yahoo.com',
                    'phone' => fake()->e164PhoneNumber(),
                    'password' => Hash::make('password123'),
                    'email_verified_at' => now(),
                    'is_active' => true,
                ],
                'customer_data' => [
                    'code' => 'CUST002',
                    'type' => 'individual',
                    'sub_type' => 'premium',
                    'category' => 'vip',
                    'nic' => '198856789123',
                    'license_no' => 'B2345678',
                    'license_type' => 'B',
                    'license_expiry' => now()->addYears(2),
                    'dob' => now()->subYears(35),
                    'address' => '456 Kandy Road, Peradeniya',
                    'postal_code' => '20400',
                    'gender' => 'female',
                    'city' => 'Kandy',
                ]
            ],
            [
                'user_data' => [
                    'first_name' => 'Rajesh',
                    'last_name' => 'Silva',
                    'email' => 'rajesh.silva@hotmail.com',
                    'phone' => fake()->e164PhoneNumber(),
                    'password' => Hash::make('password123'),
                    'email_verified_at' => now(),
                    'is_active' => true,
                ],
                'customer_data' => [
                    'code' => 'CUST003',
                    'type' => 'corporate',
                    'sub_type' => 'business',
                    'category' => 'enterprise',
                    'nic' => '197734567890',
                    'license_no' => 'B3456789',
                    'license_type' => 'B',
                    'license_expiry' => now()->addYears(4),
                    'dob' => now()->subYears(46),
                    'address' => '789 Negombo Road, Negombo',
                    'postal_code' => '11500',
                    'gender' => 'male',
                    'city' => 'Negombo',
                ]
            ],
            [
                'user_data' => [
                    'first_name' => 'Priya',
                    'last_name' => 'Jayawardena',
                    'email' => 'priya.j@gmail.com',
                    'phone' => fake()->e164PhoneNumber(),
                    'password' => Hash::make('password123'),
                    'email_verified_at' => now(),
                    'is_active' => true,
                ],
                'customer_data' => [
                    'code' => 'CUST004',
                    'type' => 'individual',
                    'sub_type' => 'regular',
                    'category' => 'standard',
                    'nic' => '199445678901',
                    'license_no' => 'B4567890',
                    'license_type' => 'B',
                    'license_expiry' => now()->addYears(1),
                    'dob' => now()->subYears(29),
                    'address' => '321 Matara Road, Galle',
                    'postal_code' => '80000',
                    'gender' => 'female',
                    'city' => 'Galle',
                ]
            ],
            [
                'user_data' => [
                    'first_name' => 'Chamara',
                    'last_name' => 'Rathnayake',
                    'email' => 'chamara.r@outlook.com',
                    'phone' => fake()->e164PhoneNumber(),
                    'password' => Hash::make('password123'),
                    'email_verified_at' => now(),
                    'is_active' => true,
                ],
                'customer_data' => [
                    'code' => 'CUST005',
                    'type' => 'individual',
                    'sub_type' => 'premium',
                    'category' => 'gold',
                    'nic' => '198567890123',
                    'license_no' => 'B5678901',
                    'license_type' => 'B',
                    'license_expiry' => now()->addYears(5),
                    'dob' => now()->subYears(38),
                    'address' => '654 Anuradhapura Road, Dambulla',
                    'postal_code' => '21100',
                    'gender' => 'male',
                    'city' => 'Dambulla',
                ]
            ]
        ];

        foreach ($customers as $customerInfo) {
            // Create user first
            $user = User::create($customerInfo['user_data']);
            
            // Assign customer role
            if ($customerRole) {
                $user->assignRole($customerRole);
            }

            // Create customer profile
            $customerData = $customerInfo['customer_data'];
            $customerData['user_id'] = $user->id;
            
            // Assign random state if available
            if ($states->count() > 0) {
                $customerData['country_id'] = $sriLanka->id;
                $customerData['state_id'] = $states->random()->id;
            }
            
            Customer::create($customerData);
        }

        $this->command->info('Customer data seeded successfully!');
        $this->command->info('Created 5 customers with different profiles');
        $this->command->info('All customers have password: password123');
    }
}
