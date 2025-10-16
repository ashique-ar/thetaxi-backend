<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Vehicle\VehicleCategory;

class VehicleCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Creating Vehicle Categories...');

        $categories = [
            [
                'name' => 'Sedan',
                'description' => 'Traditional four-door passenger cars with separate trunk',
                'sort_order' => 1
            ],
            [
                'name' => 'Hatchback',
                'description' => 'Compact cars with rear door and combined passenger/cargo area',
                'sort_order' => 2
            ],
            [
                'name' => 'SUV',
                'description' => 'Sport Utility Vehicles with higher ground clearance',
                'sort_order' => 3
            ],
            [
                'name' => 'Crossover',
                'description' => 'Car-based utility vehicles combining features of SUVs and passenger cars',
                'sort_order' => 4
            ],
            [
                'name' => 'Van',
                'description' => 'Large vehicles designed for cargo or passenger transportation',
                'sort_order' => 5
            ],
            [
                'name' => 'Minivan',
                'description' => 'Family-oriented vans with multiple seating rows',
                'sort_order' => 6
            ],
            [
                'name' => 'Pickup',
                'description' => 'Trucks with open cargo bed for hauling',
                'sort_order' => 7
            ],
            [
                'name' => 'Coupe',
                'description' => 'Two-door cars with sporty styling',
                'sort_order' => 8
            ],
            [
                'name' => 'Convertible',
                'description' => 'Cars with retractable or removable roof',
                'sort_order' => 9
            ],
            [
                'name' => 'Wagon',
                'description' => 'Extended sedans with large rear cargo area',
                'sort_order' => 10
            ],
            [
                'name' => 'Bus',
                'description' => 'Large vehicles for public or group transportation',
                'sort_order' => 11
            ],
            [
                'name' => 'Truck',
                'description' => 'Large vehicles designed primarily for cargo transportation',
                'sort_order' => 12
            ]
        ];

        foreach ($categories as $categoryData) {
            $category = VehicleCategory::firstOrCreate(
                ['name' => $categoryData['name']],
                [
                    'description' => $categoryData['description'],
                    'created_user_id' => null,
                    'updated_user_id' => null,
                ]
            );

            $this->command->info("Created vehicle category: {$category->name}");
        }

        $this->command->info('Vehicle Categories seeding completed!');
    }
}
