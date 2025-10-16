<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Vehicle\VehicleFuelType;

class VehicleFuelTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Creating Vehicle Fuel Types...');

        $fuelTypes = [
            [
                'name' => 'Petrol',
                'description' => 'Gasoline-powered vehicles with internal combustion engine',
                'sort_order' => 1
            ],
            [
                'name' => 'Diesel',
                'description' => 'Diesel-powered vehicles with compression ignition engine',
                'sort_order' => 2
            ],
            [
                'name' => 'Hybrid',
                'description' => 'Hybrid vehicles combining gasoline engine with electric motor',
                'sort_order' => 3
            ],
            [
                'name' => 'Electric',
                'description' => 'Fully electric vehicles powered by battery',
                'sort_order' => 4
            ],
            [
                'name' => 'Plug-in Hybrid',
                'description' => 'Hybrid vehicles with rechargeable battery for extended electric range',
                'sort_order' => 5
            ],
            [
                'name' => 'CNG',
                'description' => 'Compressed Natural Gas powered vehicles',
                'sort_order' => 6
            ],
            [
                'name' => 'LPG',
                'description' => 'Liquefied Petroleum Gas powered vehicles',
                'sort_order' => 7
            ],
            [
                'name' => 'Hydrogen',
                'description' => 'Hydrogen fuel cell powered vehicles',
                'sort_order' => 8
            ]
        ];

        foreach ($fuelTypes as $fuelTypeData) {
            $fuelType = VehicleFuelType::firstOrCreate(
                ['name' => $fuelTypeData['name']],
                [
                    'description' => $fuelTypeData['description'],
                    'created_user_id' => null,
                    'updated_user_id' => null,
                ]
            );

            $this->command->info("Created vehicle fuel type: {$fuelType->name}");
        }

        $this->command->info('Vehicle Fuel Types seeding completed!');
    }
}
