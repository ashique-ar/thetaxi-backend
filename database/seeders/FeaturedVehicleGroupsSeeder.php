<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Vehicle\VehicleGroup;

class FeaturedVehicleGroupsSeeder extends Seeder
{
    /**
     * Run the database seeder.
     */
    public function run(): void
    {
        // Mark the first 8 active vehicle groups as featured for testing
        VehicleGroup::where('is_active', true)
            ->orderBy('name')
            ->limit(8)
            ->update(['is_featured' => true]);

        $this->command->info('Marked 8 vehicle groups as featured');
    }
}
