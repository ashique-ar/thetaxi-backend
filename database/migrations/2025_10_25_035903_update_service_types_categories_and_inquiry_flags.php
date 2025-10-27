<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\ServiceType;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Update service types with proper categories and inquiry flags based on old form structure
        $serviceUpdates = [
            // Airport Transfer category - includes pickup and drop (is_internal = false)
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
            
            // Transport category - regular transport services
            'chauffeur_driven' => [
                'category' => 'transport',
                'is_inquiry' => false,
                'is_internal' => false,
                'priority' => 3
            ],
            'transfers' => [
                'category' => 'transport',
                'is_inquiry' => false,
                'is_internal' => false, 
                'priority' => 4
            ],
            'self_driven' => [
                'category' => 'transport',
                'is_inquiry' => false,
                'is_internal' => false,
                'priority' => 5
            ],
            
            // Special Events category
            'wedding_hire' => [
                'category' => 'special',
                'is_inquiry' => false,
                'is_internal' => false,
                'priority' => 6
            ],
            
            // Corporate category - inquiry based
            'corporate' => [
                'category' => 'corporate',
                'is_inquiry' => true,  // Corporate requires inquiry form
                'is_internal' => false,
                'priority' => 7
            ],
            'corporate_self' => [
                'category' => 'corporate', 
                'is_inquiry' => true,  // Corporate Self Drive requires inquiry form
                'is_internal' => false,
                'priority' => 8
            ],
            
            // Emergency services
            'break_down_service' => [
                'category' => 'emergency',
                'is_inquiry' => false,
                'is_internal' => false,
                'priority' => 9
            ]
        ];

        foreach ($serviceUpdates as $code => $updates) {
            ServiceType::where('code', $code)->update($updates);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reset the category and is_inquiry fields
        ServiceType::whereNotNull('category')->update([
            'category' => null,
            'is_inquiry' => false
        ]);
    }
};
