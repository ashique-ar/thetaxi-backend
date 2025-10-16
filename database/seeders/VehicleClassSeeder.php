<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Vehicle\VehicleClass;

class VehicleClassSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Creating Vehicle Classes...');

        $classes = [
            [
                'name' => 'Economy',
                'description' => 'Basic, affordable vehicles with minimal features',
                'sort_order' => 1
            ],
            [
                'name' => 'Compact',
                'description' => 'Small, fuel-efficient vehicles suitable for city driving',
                'sort_order' => 2
            ],
            [
                'name' => 'Mid-size',
                'description' => 'Moderately sized vehicles balancing comfort and efficiency',
                'sort_order' => 3
            ],
            [
                'name' => 'Full-size',
                'description' => 'Large vehicles with spacious interiors and powerful engines',
                'sort_order' => 4
            ],
            [
                'name' => 'Premium',
                'description' => 'High-end vehicles with advanced features and superior comfort',
                'sort_order' => 5
            ],
            [
                'name' => 'Luxury',
                'description' => 'Top-tier vehicles with premium materials and cutting-edge technology',
                'sort_order' => 6
            ],
            [
                'name' => 'Sport',
                'description' => 'Performance-oriented vehicles designed for speed and handling',
                'sort_order' => 7
            ],
            [
                'name' => 'Commercial',
                'description' => 'Vehicles designed for business and commercial use',
                'sort_order' => 8
            ],
            [
                'name' => 'Heavy Duty',
                'description' => 'Robust vehicles built for heavy work and transportation',
                'sort_order' => 9
            ],
            [
                'name' => 'Electric',
                'description' => 'Environmentally friendly vehicles powered by electric motors',
                'sort_order' => 10
            ]
        ];

        foreach ($classes as $classData) {
            $class = VehicleClass::firstOrCreate(
                ['name' => $classData['name']],
                [
                    'description' => $classData['description'],
                    'created_user_id' => null,
                    'updated_user_id' => null,
                ]
            );

            $this->command->info("Created vehicle class: {$class->name}");
        }

        $this->command->info('Vehicle Classes seeding completed!');
    }
}
