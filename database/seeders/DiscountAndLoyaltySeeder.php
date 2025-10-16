<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\LoyaltyTier;

class DiscountAndLoyaltySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create Loyalty Tiers
        $loyaltyTiers = [
            [
                'name' => 'bronze',
                'display_name' => 'Bronze',
                'description' => 'Entry level with basic benefits',
                'color_code' => '#CD7F32',
                'min_points' => 0,
                'max_points' => 999,
                'points_earning_multiplier' => 1.0,
                'points_redemption_multiplier' => 1.0,
                'discount_multiplier' => 1.05, // 5% discount
                'privileges' => json_encode([
                    'free_cancellation_hours' => 24,
                ]),
                'sort_order' => 1,
                'is_active' => true,
                'is_default' => true,
            ],
            [
                'name' => 'silver',
                'display_name' => 'Silver',
                'description' => 'Enhanced benefits and priority service',
                'color_code' => '#C0C0C0',
                'min_points' => 1000,
                'max_points' => 4999,
                'points_earning_multiplier' => 1.2,
                'points_redemption_multiplier' => 1.1,
                'discount_multiplier' => 1.10, // 10% discount
                'privileges' => json_encode([
                    'free_cancellation_hours' => 48,
                    'priority_support' => true,
                ]),
                'priority_support' => true,
                'sort_order' => 2,
                'is_active' => true,
            ],
            [
                'name' => 'gold',
                'display_name' => 'Gold',
                'description' => 'Premium tier with exclusive perks',
                'color_code' => '#FFD700',
                'min_points' => 5000,
                'max_points' => 14999,
                'points_earning_multiplier' => 1.5,
                'points_redemption_multiplier' => 1.2,
                'discount_multiplier' => 1.15, // 15% discount
                'privileges' => json_encode([
                    'free_cancellation_hours' => 72,
                    'priority_support' => true,
                    'complimentary_upgrades' => true,
                ]),
                'priority_booking' => true,
                'priority_support' => true,
                'sort_order' => 3,
                'is_active' => true,
            ],
            [
                'name' => 'platinum',
                'display_name' => 'Platinum',
                'description' => 'Elite status with maximum benefits',
                'color_code' => '#E5E4E2',
                'min_points' => 15000,
                'max_points' => null,
                'points_earning_multiplier' => 2.0,
                'points_redemption_multiplier' => 1.5,
                'discount_multiplier' => 1.20, // 20% discount
                'privileges' => json_encode([
                    'free_cancellation_hours' => 96,
                    'priority_support' => true,
                    'complimentary_upgrades' => true,
                    'concierge_service' => true,
                    'airport_lounge_access' => true,
                ]),
                'priority_booking' => true,
                'free_cancellation' => true,
                'priority_support' => true,
                'sort_order' => 4,
                'is_active' => true,
            ],
        ];

        foreach ($loyaltyTiers as $tierData) {
            LoyaltyTier::firstOrCreate(['name' => $tierData['name']], $tierData);
        }

        $this->command->info('Discount loyalty tiers created successfully!');
    }
}
