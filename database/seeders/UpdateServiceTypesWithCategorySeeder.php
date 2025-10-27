<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ServiceType;

class UpdateServiceTypesWithCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $serviceTypeUpdates = [
            // Airport Transfer Category
            'airport_drop' => [
                'category' => 'airport',
                'is_inquiry' => false,
                'is_internal' => false,
                'priority' => 1
            ],
            'airport_pickup' => [
                'category' => 'airport',
                'is_inquiry' => false,
                'is_internal' => false,
                'priority' => 2
            ],
            
            // Transport Category (Drop & Pickup)
            'transfers' => [
                'category' => 'transport',
                'is_inquiry' => false,
                'is_internal' => false,
                'priority' => 3
            ],
            
            // Rental Category
            'chauffeur_driven' => [
                'category' => 'rental',
                'is_inquiry' => false,
                'is_internal' => false,
                'priority' => 4
            ],
            'self_driven' => [
                'category' => 'rental',
                'is_inquiry' => false,
                'is_internal' => false,
                'priority' => 5
            ],
            
            // Corporate Category (Inquiry-based)
            'corporate' => [
                'category' => 'corporate',
                'is_inquiry' => true,
                'is_internal' => false,
                'priority' => 6
            ],
            'corporate_self' => [
                'category' => 'corporate',
                'is_inquiry' => true,
                'is_internal' => false,
                'priority' => 7
            ],
            
            // Special Events Category
            'wedding_hire' => [
                'category' => 'events',
                'is_inquiry' => false,
                'is_internal' => false,
                'priority' => 8
            ],
            
            // Emergency Category
            'break_down_service' => [
                'category' => 'emergency',
                'is_inquiry' => false,
                'is_internal' => true, // This is internal service
                'priority' => 9
            ],
            
            // Custom Tour Service (Special Category)
            'custom_tour' => [
                'category' => 'special',
                'is_inquiry' => false,
                'is_internal' => false,
                'priority' => 10
            ],
        ];

        foreach ($serviceTypeUpdates as $code => $updates) {
            $serviceType = ServiceType::where('code', $code)->first();
            if ($serviceType) {
                $serviceType->update($updates);
                $this->command->info("Updated service type: {$code}");
            } else {
                $this->command->warn("Service type not found: {$code}");
            }
        }
        
        $this->command->info('Service types updated with categories and inquiry flags.');
    }
}
