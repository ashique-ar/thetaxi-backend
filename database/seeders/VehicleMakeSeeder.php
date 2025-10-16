<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Vehicle\VehicleMake;

class VehicleMakeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Creating Vehicle Makes...');

        $makes = [
            [
                'name' => 'Toyota',
                'description' => 'Japanese multinational automotive manufacturer headquartered in Toyota City, Aichi, Japan.',
            ],
            [
                'name' => 'Honda',
                'description' => 'Japanese public multinational conglomerate manufacturer of automobiles, motorcycles, and power equipment.',
            ],
            [
                'name' => 'Nissan',
                'description' => 'Japanese multinational automobile manufacturer headquartered in Nishi-ku, Yokohama, Japan.',
            ],
            [
                'name' => 'Mitsubishi',
                'description' => 'Japanese multinational automotive manufacturer part of the Mitsubishi keiretsu.',
            ],
            [
                'name' => 'Mazda',
                'description' => 'Japanese multinational automotive manufacturer based in Fuchū, Hiroshima, Japan.',
            ],
            [
                'name' => 'Suzuki',
                'description' => 'Japanese multinational corporation headquartered in Minami-ku, Hamamatsu, Japan.',
            ],
            [
                'name' => 'Subaru',
                'description' => 'Japanese automobile manufacturer and the automotive division of Subaru Corporation.',
            ],
            [
                'name' => 'Hyundai',
                'description' => 'South Korean multinational automotive manufacturer headquartered in Seoul, South Korea.',
            ],
            [
                'name' => 'Kia',
                'description' => 'South Korean multinational automotive manufacturer headquartered in Seoul, South Korea.',
            ],
            [
                'name' => 'BMW',
                'description' => 'German multinational automotive, motorcycle, and engine manufacturing company.',
            ],
            [
                'name' => 'Mercedes-Benz',
                'description' => 'German global automobile marque and a division of Daimler AG.',
            ],
            [
                'name' => 'Audi',
                'description' => 'German automobile manufacturer that designs, engineers, produces, markets and distributes luxury vehicles.',
            ],
            [
                'name' => 'Volkswagen',
                'description' => 'German motor vehicle manufacturer headquartered in Wolfsburg, Lower Saxony, Germany.',
            ],
            [
                'name' => 'Ford',
                'description' => 'American multinational automobile manufacturer headquartered in Dearborn, Michigan, United States.',
            ],
            [
                'name' => 'Chevrolet',
                'description' => 'American automobile division of the American manufacturer General Motors.',
            ],
            [
                'name' => 'Peugeot',
                'description' => 'French automotive manufacturer, part of Stellantis and previously PSA Group.',
            ],
            [
                'name' => 'Renault',
                'description' => 'French multinational automobile manufacturer established in 1899.',
            ],
            [
                'name' => 'Citroen',
                'description' => 'French automobile manufacturer, part of Stellantis and previously PSA Group.',
            ],
            [
                'name' => 'Fiat',
                'description' => 'Italian automobile manufacturer, part of Stellantis.',
            ],
            [
                'name' => 'Alfa Romeo',
                'description' => 'Italian luxury car manufacturer and a subsidiary of Stellantis.',
            ]
        ];

        foreach ($makes as $makeData) {
            $make = VehicleMake::create([
                'name' => $makeData['name'],
                'description' => $makeData['description'],
                'created_user_id' => null,
                'updated_user_id' => null,
            ]);

            $this->command->info("Created vehicle make: {$make->name}");
        }

        $this->command->info('Vehicle Makes seeding completed!');
    }
}
