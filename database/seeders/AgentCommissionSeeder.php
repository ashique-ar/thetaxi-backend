<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Agent\Agent;
use App\Models\Agent\AgentCommission;
use App\Models\Booking\Booking;
use Carbon\Carbon;
use Illuminate\Support\Str;

class AgentCommissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->seedAgentCommissions();
    }

    /**
     * Seed agent commissions based on existing bookings or create mock data
     */
    private function seedAgentCommissions(): void
    {
        $agents = Agent::all();
        
        // For now, create mock commissions since booking structure may not be ready
        $this->seedMockCommissions($agents);

        $this->command->info('Agent commissions seeded successfully!');
    }

    /**
     * Seed commissions from existing bookings
     */
    private function seedFromExistingBookings($bookings): void
    {
        foreach ($bookings as $booking) {
            $bookingTotal = $booking->total_actual ?? $booking->total_estimated ?? 0;

            if ($booking->agent_id && $bookingTotal > 0) {
                $agent = Agent::find($booking->agent_id);
                if ($agent) {
                    $commissionAmount = $bookingTotal * ($agent->commission_rate / 100);
                    
                    AgentCommission::updateOrCreate(
                        [
                            'agent_id' => $agent->id,
                            'booking_id' => $booking->id,
                        ],
                        [
                            'amount' => $commissionAmount,
                            'paid' => rand(0, 1) === 1, // Random paid status
                            'paid_at' => rand(0, 1) === 1 ? Carbon::now()->subDays(rand(1, 30)) : null,
                        ]
                    );
                }
            }
        }
    }

    /**
     * Seed mock commissions for demonstration
     */
    private function seedMockCommissions($agents): void
    {
        foreach ($agents as $agent) {
            // Create 5-15 mock commission records per agent
            $commissionCount = rand(5, 15);
            
            for ($i = 0; $i < $commissionCount; $i++) {
                $mockBookingAmount = $this->generateRealisticBookingAmount();
                $commissionAmount = $mockBookingAmount * ($agent->commission_rate / 100);
                $isPaid = rand(0, 100) < 70; // 70% chance of being paid
                
                AgentCommission::create([
                    'agent_id' => $agent->id,
                    'booking_id' => Str::uuid(), // Generate mock booking UUID
                    'amount' => $commissionAmount,
                    'paid' => $isPaid,
                    'paid_at' => $isPaid ? $this->generateRealisticPaymentDate() : null,
                    'created_at' => $this->generateRealisticCommissionDate(),
                    'updated_at' => Carbon::now(),
                ]);
            }
        }
    }

    /**
     * Generate realistic booking amounts based on service types
     */
    private function generateRealisticBookingAmount(): float
    {
        $serviceTypes = [
            'chauffeur_driven' => [5000, 25000],
            'wedding_hire' => [15000, 75000],
            'airport_transfers' => [3000, 12000],
            'corporate' => [8000, 45000],
            'self_driven' => [4000, 20000],
        ];

        $serviceType = array_rand($serviceTypes);
        [$min, $max] = $serviceTypes[$serviceType];
        
        return rand($min, $max);
    }

    /**
     * Generate realistic commission creation dates
     */
    private function generateRealisticCommissionDate(): Carbon
    {
        return Carbon::now()->subDays(rand(1, 90));
    }

    /**
     * Generate realistic payment dates
     */
    private function generateRealisticPaymentDate(): Carbon
    {
        // Payments typically happen 7-45 days after commission creation
        return Carbon::now()->subDays(rand(7, 45));
    }
}
