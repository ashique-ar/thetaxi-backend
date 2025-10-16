<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Vehicle\VehicleModel;
use App\Models\Vehicle\VehicleMake;

class VehicleModelSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Creating Vehicle Models...');

        $makes = VehicleMake::all();
        if ($makes->isEmpty()) {
            $this->command->warn('No vehicle makes found. Please run VehicleMakeSeeder first.');
            return;
        }

        $models = [
            // Toyota Models
            'Toyota' => [
                ['name' => 'Camry', 'description' => 'Mid-size sedan known for reliability and comfort'],
                ['name' => 'Corolla', 'description' => 'Compact sedan, bestselling car nameplate worldwide'],  
                ['name' => 'Prius', 'description' => 'Hybrid electric mid-size hatchback'],
                ['name' => 'RAV4', 'description' => 'Compact crossover SUV'],
                ['name' => 'Highlander', 'description' => 'Mid-size crossover SUV'],
                ['name' => 'Land Cruiser', 'description' => 'Large SUV known for off-road capability'],
                ['name' => 'Prado', 'description' => 'Mid-size SUV in the Land Cruiser family'],
                ['name' => 'Hiace', 'description' => 'Light commercial van'],
                ['name' => 'Fortuner', 'description' => 'Mid-size SUV built on Hilux platform'],
                ['name' => 'Hilux', 'description' => 'Mid-size pickup truck'],
                ['name' => 'Axio', 'description' => 'Compact sedan for emerging markets'],
                ['name' => 'Vitz', 'description' => 'Subcompact hatchback (known as Yaris in some markets)'],
                ['name' => 'Allion', 'description' => 'Mid-size sedan for the Japanese market'],
                ['name' => 'Premio', 'description' => 'Mid-size sedan, sister car to Allion'],
            ],

            // Honda Models  
            'Honda' => [
                ['name' => 'Accord', 'description' => 'Mid-size sedan known for quality and performance'],
                ['name' => 'Civic', 'description' => 'Compact car available in sedan, coupe, and hatchback'],
                ['name' => 'CR-V', 'description' => 'Compact crossover SUV'],
                ['name' => 'Pilot', 'description' => 'Mid-size crossover SUV'],
                ['name' => 'Odyssey', 'description' => 'Minivan for family transportation'],
                ['name' => 'Fit', 'description' => 'Subcompact hatchback with spacious interior'],
                ['name' => 'HR-V', 'description' => 'Subcompact crossover SUV'],
                ['name' => 'Ridgeline', 'description' => 'Mid-size pickup truck with unibody construction'],
                ['name' => 'Insight', 'description' => 'Hybrid electric compact sedan'],
                ['name' => 'Passport', 'description' => 'Mid-size crossover SUV'],
            ],

            // Nissan Models
            'Nissan' => [
                ['name' => 'Altima', 'description' => 'Mid-size sedan with advanced technology'],
                ['name' => 'Sentra', 'description' => 'Compact sedan with efficient performance'],
                ['name' => 'Maxima', 'description' => 'Full-size sedan with sporty design'],
                ['name' => 'Rogue', 'description' => 'Compact crossover SUV'],
                ['name' => 'Murano', 'description' => 'Mid-size crossover SUV'],
                ['name' => 'Pathfinder', 'description' => 'Mid-size SUV with three rows of seating'],
                ['name' => 'Armada', 'description' => 'Full-size SUV for large families'],
                ['name' => 'Frontier', 'description' => 'Mid-size pickup truck'],
                ['name' => 'Titan', 'description' => 'Full-size pickup truck'],
                ['name' => 'Caravan', 'description' => 'Commercial van and people mover'],
                ['name' => 'X-Trail', 'description' => 'Compact crossover SUV'],
                ['name' => 'Juke', 'description' => 'Subcompact crossover with unique styling'],
            ],

            // Mitsubishi Models
            'Mitsubishi' => [
                ['name' => 'Outlander', 'description' => 'Compact crossover SUV'],
                ['name' => 'Eclipse Cross', 'description' => 'Compact crossover coupe SUV'],
                ['name' => 'ASX', 'description' => 'Subcompact crossover SUV'],
                ['name' => 'Pajero', 'description' => 'Mid-size SUV with off-road capability'],
                ['name' => 'Triton', 'description' => 'Mid-size pickup truck'],
                ['name' => 'Lancer', 'description' => 'Compact sedan and hatchback'],
                ['name' => 'Mirage', 'description' => 'Subcompact hatchback and sedan'],
            ],

            // Hyundai Models
            'Hyundai' => [
                ['name' => 'Elantra', 'description' => 'Compact sedan with modern design'],
                ['name' => 'Sonata', 'description' => 'Mid-size sedan with advanced features'],
                ['name' => 'Tucson', 'description' => 'Compact crossover SUV'],
                ['name' => 'Santa Fe', 'description' => 'Mid-size crossover SUV'],
                ['name' => 'Palisade', 'description' => 'Large crossover SUV'],
                ['name' => 'Accent', 'description' => 'Subcompact sedan and hatchback'],
                ['name' => 'Kona', 'description' => 'Subcompact crossover SUV'],
                ['name' => 'Veloster', 'description' => 'Sport compact hatchback'],
            ],

            // BMW Models
            'BMW' => [
                ['name' => '3 Series', 'description' => 'Compact executive sedan'],
                ['name' => '5 Series', 'description' => 'Mid-size luxury sedan'],
                ['name' => '7 Series', 'description' => 'Full-size luxury sedan'],
                ['name' => 'X3', 'description' => 'Compact luxury crossover SUV'],
                ['name' => 'X5', 'description' => 'Mid-size luxury crossover SUV'],
                ['name' => 'X7', 'description' => 'Full-size luxury crossover SUV'],
            ],

            // Mercedes-Benz Models
            'Mercedes-Benz' => [
                ['name' => 'C-Class', 'description' => 'Compact executive sedan'],
                ['name' => 'E-Class', 'description' => 'Mid-size luxury sedan'],
                ['name' => 'S-Class', 'description' => 'Full-size luxury sedan'],
                ['name' => 'GLA', 'description' => 'Subcompact luxury crossover SUV'],
                ['name' => 'GLC', 'description' => 'Compact luxury crossover SUV'],
                ['name' => 'GLE', 'description' => 'Mid-size luxury crossover SUV'],
                ['name' => 'GLS', 'description' => 'Full-size luxury crossover SUV'],
            ],
        ];

        foreach ($models as $makeName => $makeModels) {
            $make = $makes->where('name', $makeName)->first();
            if (!$make) {
                $this->command->warn("Make '{$makeName}' not found, skipping models");
                continue;
            }

            foreach ($makeModels as $modelData) {
                $model = VehicleModel::firstOrCreate(
                    [
                        'make_id' => $make->id,
                        'name' => $modelData['name'],
                    ],
                    [
                        'description' => $modelData['description'],
                        'created_user_id' => null,
                        'updated_user_id' => null,
                    ]
                );

                $this->command->info("Created vehicle model: {$make->name} {$model->name}");
            }
        }

        $this->command->info('Vehicle Models seeding completed!');
    }
}
