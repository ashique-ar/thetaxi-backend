<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Vehicle\VehicleTransmission;

class VehicleTransmissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Creating Vehicle Transmissions...');

        $transmissions = [
            [
                'name' => 'Manual',
                'description' => 'Manual transmission requiring driver to shift gears',
                'sort_order' => 1
            ],
            [
                'name' => 'Automatic',
                'description' => 'Automatic transmission that shifts gears automatically',
                'sort_order' => 2
            ],
            [
                'name' => 'CVT',
                'description' => 'Continuously Variable Transmission for smooth acceleration',
                'sort_order' => 3
            ],
            [
                'name' => 'Semi-Automatic',
                'description' => 'Semi-automatic transmission with manual control option',
                'sort_order' => 4
            ],
            [
                'name' => 'Dual-Clutch',
                'description' => 'Dual-clutch automatic transmission for performance',
                'sort_order' => 5
            ],
            [
                'name' => 'Tiptronic',
                'description' => 'Automatic transmission with manual override capability',
                'sort_order' => 6
            ]
        ];

        foreach ($transmissions as $transmissionData) {
            $transmission = VehicleTransmission::firstOrCreate(
                ['name' => $transmissionData['name']],
                [
                    'description' => $transmissionData['description'],
                    'created_user_id' => null,
                    'updated_user_id' => null,
                ]
            );

            $this->command->info("Created vehicle transmission: {$transmission->name}");
        }

        $this->command->info('Vehicle Transmissions seeding completed!');
    }
}
