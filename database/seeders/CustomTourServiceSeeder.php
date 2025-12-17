<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Service\ServiceType;

class CustomTourServiceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Check if custom_tour service exists, if not create it
        $customTour = ServiceType::where('code', 'custom_tour')->first();
        
        if (!$customTour) {
            ServiceType::create([
                'code' => 'custom_tour',
                'name' => 'Custom Tour',
                'description' => 'Design your own custom tour itinerary with multiple destinations',
                'type' => 'standard',
                'category' => 'special',
                'is_active' => true,
                'is_inquiry' => false,
                'is_internal' => false,
                'priority' => 10
            ]);
            
            $this->command->info('Created custom_tour service type');
        } else {
            $this->command->info('custom_tour service type already exists');
        }
    }
}