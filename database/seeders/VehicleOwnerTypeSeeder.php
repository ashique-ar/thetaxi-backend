<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Vehicle\VehicleOwnerType;

class VehicleOwnerTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Creating Vehicle Owner Types...');

        $ownerTypes = [
            [
                'name' => 'Individual',
                'description' => 'Private individual vehicle owner',
                'sort_order' => 1
            ],
            [
                'name' => 'Company',
                'description' => 'Corporate or business entity vehicle owner',
                'sort_order' => 2
            ],
            [
                'name' => 'Government',
                'description' => 'Government organization vehicle owner',
                'sort_order' => 3
            ],
            [
                'name' => 'Rental Agency',
                'description' => 'Vehicle rental or leasing company',
                'sort_order' => 4
            ],
            [
                'name' => 'Taxi Service',
                'description' => 'Taxi or ride-hailing service provider',
                'sort_order' => 5
            ],
            [
                'name' => 'Fleet Operator',
                'description' => 'Commercial fleet management company',
                'sort_order' => 6
            ],
            [
                'name' => 'NGO',
                'description' => 'Non-governmental organization',
                'sort_order' => 7
            ],
            [
                'name' => 'Educational Institution',
                'description' => 'School, college, or university',
                'sort_order' => 8
            ]
        ];

        foreach ($ownerTypes as $ownerTypeData) {
            $ownerType = VehicleOwnerType::firstOrCreate(
                ['name' => $ownerTypeData['name']],
                [
                    'description' => $ownerTypeData['description'],
                    'created_user_id' => null,
                    'updated_user_id' => null,
                ]
            );

            $this->command->info("Created vehicle owner type: {$ownerType->name}");
        }

        $this->command->info('Vehicle Owner Types seeding completed!');
    }
}
