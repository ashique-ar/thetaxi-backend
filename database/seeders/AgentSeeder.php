<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Agent\Agent;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AgentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->seedAgents();
    }

    /**
     * Seed agents with their user accounts
     */
    private function seedAgents(): void
    {
        $agentsData = [
            [
                'name' => 'Travel Lanka Agency',
                'email' => 'contact@travellanka.lk',
                'phone' => fake()->e164PhoneNumber(),
                'code' => 'TLA001',
                'commission_rate' => 15.00,
                'branding_config' => [
                    'logo_url' => 'https://example.com/logos/travel-lanka.png',
                    'primary_color' => '#1E40AF',
                    'secondary_color' => '#F59E0B',
                    'company_name' => 'Travel Lanka Agency',
                    'website' => 'https://travellanka.lk',
                    'contact_phone' => fake()->e164PhoneNumber(),
                    'contact_email' => 'support@travellanka.lk',
                    'terms_url' => 'https://travellanka.lk/terms',
                    'privacy_url' => 'https://travellanka.lk/privacy'
                ]
            ],
            [
                'name' => 'Colombo Car Rentals',
                'email' => 'info@colombocarrentals.com',
                'phone' => fake()->e164PhoneNumber(),
                'code' => 'CCR002',
                'commission_rate' => 12.50,
                'branding_config' => [
                    'logo_url' => 'https://example.com/logos/colombo-car-rentals.png',
                    'primary_color' => '#DC2626',
                    'secondary_color' => '#059669',
                    'company_name' => 'Colombo Car Rentals',
                    'website' => 'https://colombocarrentals.com',
                    'contact_phone' => fake()->e164PhoneNumber(),
                    'contact_email' => 'bookings@colombocarrentals.com',
                    'terms_url' => 'https://colombocarrentals.com/terms',
                    'privacy_url' => 'https://colombocarrentals.com/privacy'
                ]
            ],
            [
                'name' => 'Island Tours & Travels',
                'email' => 'bookings@islandtours.lk',
                'phone' => fake()->e164PhoneNumber(),
                'code' => 'ITT003',
                'commission_rate' => 18.00,
                'branding_config' => [
                    'logo_url' => 'https://example.com/logos/island-tours.png',
                    'primary_color' => '#7C3AED',
                    'secondary_color' => '#F97316',
                    'company_name' => 'Island Tours & Travels',
                    'website' => 'https://islandtours.lk',
                    'contact_phone' => fake()->e164PhoneNumber(),
                    'contact_email' => 'hello@islandtours.lk',
                    'terms_url' => 'https://islandtours.lk/terms',
                    'privacy_url' => 'https://islandtours.lk/privacy'
                ]
            ],
            [
                'name' => 'Premium Vehicle Services',
                'email' => 'admin@premiumvehicles.lk',
                'phone' => fake()->e164PhoneNumber(),
                'code' => 'PVS004',
                'commission_rate' => 20.00,
                'branding_config' => [
                    'logo_url' => 'https://example.com/logos/premium-vehicles.png',
                    'primary_color' => '#0F172A',
                    'secondary_color' => '#FCD34D',
                    'company_name' => 'Premium Vehicle Services',
                    'website' => 'https://premiumvehicles.lk',
                    'contact_phone' => fake()->e164PhoneNumber(),
                    'contact_email' => 'luxury@premiumvehicles.lk',
                    'terms_url' => 'https://premiumvehicles.lk/terms',
                    'privacy_url' => 'https://premiumvehicles.lk/privacy'
                ]
            ],
            [
                'name' => 'Quick Cab Solutions',
                'email' => 'support@quickcab.lk',
                'phone' => fake()->e164PhoneNumber(),
                'code' => 'QCS005',
                'commission_rate' => 10.00,
                'branding_config' => [
                    'logo_url' => 'https://example.com/logos/quick-cab.png',
                    'primary_color' => '#065F46',
                    'secondary_color' => '#FB7185',
                    'company_name' => 'Quick Cab Solutions',
                    'website' => 'https://quickcab.lk',
                    'contact_phone' => fake()->e164PhoneNumber(),
                    'contact_email' => 'dispatch@quickcab.lk',
                    'terms_url' => 'https://quickcab.lk/terms',
                    'privacy_url' => 'https://quickcab.lk/privacy'
                ]
            ],
            [
                'name' => 'Corporate Fleet Management',
                'email' => 'corporate@fleetmanage.lk',
                'phone' => fake()->e164PhoneNumber(),
                'code' => 'CFM006',
                'commission_rate' => 25.00,
                'branding_config' => [
                    'logo_url' => 'https://example.com/logos/corporate-fleet.png',
                    'primary_color' => '#1F2937',
                    'secondary_color' => '#10B981',
                    'company_name' => 'Corporate Fleet Management',
                    'website' => 'https://fleetmanage.lk',
                    'contact_phone' => fake()->e164PhoneNumber(),
                    'contact_email' => 'enterprise@fleetmanage.lk',
                    'terms_url' => 'https://fleetmanage.lk/terms',
                    'privacy_url' => 'https://fleetmanage.lk/privacy'
                ]
            ],
            [
                'name' => 'Wedding Car Specialists',
                'email' => 'weddings@weddingcars.lk',
                'phone' => fake()->e164PhoneNumber(),
                'code' => 'WCS007',
                'commission_rate' => 22.00,
                'branding_config' => [
                    'logo_url' => 'https://example.com/logos/wedding-cars.png',
                    'primary_color' => '#BE185D',
                    'secondary_color' => '#FDE68A',
                    'company_name' => 'Wedding Car Specialists',
                    'website' => 'https://weddingcars.lk',
                    'contact_phone' => fake()->e164PhoneNumber(),
                    'contact_email' => 'bridal@weddingcars.lk',
                    'terms_url' => 'https://weddingcars.lk/terms',
                    'privacy_url' => 'https://weddingcars.lk/privacy'
                ]
            ],
            [
                'name' => 'Airport Express Service',
                'email' => 'airport@expressservice.lk',
                'phone' => fake()->e164PhoneNumber(),
                'code' => 'AES008',
                'commission_rate' => 8.00,
                'branding_config' => [
                    'logo_url' => 'https://example.com/logos/airport-express.png',
                    'primary_color' => '#0369A1',
                    'secondary_color' => '#F472B6',
                    'company_name' => 'Airport Express Service',
                    'website' => 'https://expressservice.lk',
                    'contact_phone' => fake()->e164PhoneNumber(),
                    'contact_email' => 'transfers@expressservice.lk',
                    'terms_url' => 'https://expressservice.lk/terms',
                    'privacy_url' => 'https://expressservice.lk/privacy'
                ]
            ]
        ];

        foreach ($agentsData as $agentData) {
            // Create or get user account
            $user = User::updateOrCreate(
                ['email' => $agentData['email']],
                [
                    'first_name' => explode(' ', $agentData['name'])[0], // First word as first name
                    'last_name' => substr($agentData['name'], strpos($agentData['name'], ' ') + 1) ?: '', // Rest as last name
                    'phone' => $agentData['phone'],
                    'password' => Hash::make('password123'), // Default password
                    'email_verified_at' => now(),
                    'is_active' => true,
                ]
            );

            // Create agent record
            Agent::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'code' => $agentData['code'],
                    'commission_rate' => $agentData['commission_rate'],
                    'branding_config' => $agentData['branding_config'],
                ]
            );
        }

        $this->command->info('Agents seeded successfully!');
    }
}
