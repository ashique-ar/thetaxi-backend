<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PredefinedLocation;
use Illuminate\Support\Str;

class PredefinedLocationsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $locations = [
            [
                'name' => 'Casons Head Office',
                'code' => 'CASONS_HQ',
                'type' => 'office',
                'address' => 'Casons Head Office, 181, Gothami Gardens, Gothami Road, Rajagiriya, Sri Lanka.',
                'city' => 'Colombo',
                'latitude' => 6.9271,
                'longitude' => 79.8612,
                'sort_order' => 1,
                'description' => 'Casons Head Office - Main Branch',
            ],
            [
                'name' => 'Mattala Airport',
                'code' => 'MATTALA_AIRPORT',
                'type' => 'airport',
                'address' => 'Mattala Rajapaksa International Airport, Hambantota',
                'city' => 'Hambantota',
                'latitude' => 6.2847,
                'longitude' => 81.1242,
                'sort_order' => 2,
                'description' => 'Mattala Rajapaksa International Airport',
            ],
            [
                'name' => 'BIA Airport',
                'code' => 'BIA_AIRPORT',
                'type' => 'airport',
                'address' => 'Bandaranaike International Airport, Katunayake',
                'city' => 'Colombo',
                'latitude' => 7.1808,
                'longitude' => 79.8841,
                'sort_order' => 3,
                'description' => 'Bandaranaike International Airport - Main International Airport',
            ],
            [
                'name' => 'Jaffna Airport',
                'code' => 'JAFFNA_AIRPORT',
                'type' => 'airport',
                'address' => 'Jaffna International Airport, Palaly',
                'city' => 'Jaffna',
                'latitude' => 9.7923,
                'longitude' => 80.0701,
                'sort_order' => 4,
                'description' => 'Jaffna International Airport',
            ],
        ];

        foreach ($locations as $location) {
            PredefinedLocation::updateOrCreate(
                ['code' => $location['code']],
                array_merge($location, [
                    'id' => Str::uuid()->toString(),
                    'is_active' => true,
                    'country' => 'Sri Lanka',
                ])
            );
        }

        $this->command->info('Predefined locations seeded successfully!');
    }
}
