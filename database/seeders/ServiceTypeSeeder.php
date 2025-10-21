<?php

namespace Database\Seeders;

use App\Models\ServiceType;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ServiceTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $serviceTypes = [
            [
                'code' => 'AIRPORT_TRANSFER',
                'name' => 'Airport Transfer',
                'description' => 'Reliable airport pickup and drop-off service with professional drivers',
                'type' => 'with_driver',
                'slug' => 'airport-transfer',
                'priority' => 1,
                'is_internal' => false,
                'terms' => 'Fixed rates, meet and greet service, flight monitoring'
            ],
            [
                'code' => 'DROP_PICKUP',
                'name' => 'Drop And Pickup',
                'description' => 'Point-to-point drop and pickup service with return options',
                'type' => 'with_driver',
                'slug' => 'drop-and-pickup',
                'priority' => 2,
                'is_internal' => false,
                'terms' => 'Fixed rates, return trip options, professional service'
            ],
            [
                'code' => 'RENTAL_PACKAGES',
                'name' => 'Rental Packages',
                'description' => 'Hourly and daily rental packages for extended use',
                'type' => 'with_driver',
                'slug' => 'rental-packages',
                'priority' => 3,
                'is_internal' => false,
                'terms' => 'Hourly/daily rates, dedicated driver, multiple destinations'
            ],
            [
                'code' => 'CORPORATE_TRANSPORT',
                'name' => 'Corporate Transport',
                'description' => 'Professional corporate transportation solutions',
                'type' => 'with_driver',
                'slug' => 'corporate-transport',
                'priority' => 4,
                'is_internal' => false,
                'terms' => 'Corporate rates, professional drivers, priority booking'
            ]
        ];

        foreach ($serviceTypes as $serviceType) {
            ServiceType::updateOrCreate(
                ['code' => $serviceType['code']],
                array_merge($serviceType, [
                    'id' => Str::uuid(),
                    'created_user_id' => null,
                    'updated_user_id' => null,
                ])
            );
        }
    }
}
