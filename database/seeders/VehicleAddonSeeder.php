<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Vehicle\VehicleAddon;
use App\Models\ServiceType;
use Carbon\Carbon;

class VehicleAddonSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->seedVehicleAddons();
    }

    /**
     * Seed vehicle addons for different service types
     */
    private function seedVehicleAddons(): void
    {
        // Get service types
        $serviceTypes = ServiceType::all()->keyBy('code');

        $addons = [
            // Universal addons (available for all service types)
            [
                'service_type_id' => null, // Universal
                'name' => 'GPS Navigation System',
                'description' => 'Professional GPS navigation system for easy route guidance',
                'amount' => 500.00,
                'rate_type' => 'flat',
                'billing_type' => 'per_day',
                'min_qty' => 1,
                'max_qty' => 1,
                'valid_from' => Carbon::now(),
                'valid_to' => null,
            ],
            [
                'service_type_id' => null,
                'name' => 'Child Safety Seat',
                'description' => 'Safety-certified child seat for children under 12 years',
                'amount' => 750.00,
                'rate_type' => 'flat',
                'billing_type' => 'per_day',
                'min_qty' => 1,
                'max_qty' => 3,
                'valid_from' => Carbon::now(),
                'valid_to' => null,
            ],
            [
                'service_type_id' => null,
                'name' => 'Baby Car Seat',
                'description' => 'Infant car seat for babies under 2 years',
                'amount' => 1000.00,
                'rate_type' => 'flat',
                'billing_type' => 'per_day',
                'min_qty' => 1,
                'max_qty' => 2,
                'valid_from' => Carbon::now(),
                'valid_to' => null,
            ],
            [
                'service_type_id' => null,
                'name' => 'Mobile Charger',
                'description' => 'Universal mobile phone charger with multiple ports',
                'amount' => 250.00,
                'rate_type' => 'flat',
                'billing_type' => 'per_day',
                'min_qty' => 1,
                'max_qty' => 2,
                'valid_from' => Carbon::now(),
                'valid_to' => null,
            ],
            [
                'service_type_id' => null,
                'name' => 'Wi-Fi Hotspot',
                'description' => 'Mobile Wi-Fi hotspot for internet connectivity during travel',
                'amount' => 800.00,
                'rate_type' => 'flat',
                'billing_type' => 'per_day',
                'min_qty' => 1,
                'max_qty' => 1,
                'valid_from' => Carbon::now(),
                'valid_to' => null,
            ],
            [
                'service_type_id' => null,
                'name' => 'Extra Driver',
                'description' => 'Additional qualified driver for long journeys',
                'amount' => 2500.00,
                'rate_type' => 'flat',
                'billing_type' => 'per_day',
                'min_qty' => 1,
                'max_qty' => 1,
                'valid_from' => Carbon::now(),
                'valid_to' => null,
            ],

            // Wedding specific addons
            [
                'service_type_id' => $serviceTypes['wedding_hire']->id ?? null,
                'name' => 'Wedding Decoration Package',
                'description' => 'Complete vehicle decoration with flowers, ribbons, and wedding themes',
                'amount' => 5000.00,
                'rate_type' => 'flat',
                'billing_type' => 'per_package', // One-time charge
                'min_qty' => 1,
                'max_qty' => 1,
                'valid_from' => Carbon::now(),
                'valid_to' => null,
            ],
            [
                'service_type_id' => $serviceTypes['wedding_hire']->id ?? null,
                'name' => 'Bridal Car Ribbon Set',
                'description' => 'Elegant ribbon decoration set for bridal car',
                'amount' => 1500.00,
                'rate_type' => 'flat',
                'billing_type' => 'per_package', // One-time charge
                'min_qty' => 1,
                'max_qty' => 2,
                'valid_from' => Carbon::now(),
                'valid_to' => null,
            ],
            [
                'service_type_id' => $serviceTypes['wedding_hire']->id ?? null,
                'name' => 'Fresh Flower Arrangement',
                'description' => 'Beautiful fresh flower arrangements for wedding vehicles',
                'amount' => 3500.00,
                'rate_type' => 'flat',
                'billing_type' => 'per_package', // One-time charge
                'min_qty' => 1,
                'max_qty' => 3,
                'valid_from' => Carbon::now(),
                'valid_to' => null,
            ],
            [
                'service_type_id' => $serviceTypes['wedding_hire']->id ?? null,
                'name' => 'Wedding Photographer Seat',
                'description' => 'Reserved comfortable seat for wedding photographer in vehicle',
                'amount' => 1000.00,
                'rate_type' => 'flat',
                'billing_type' => 'per_hour', // Hourly charge for photographer time
                'min_qty' => 1,
                'max_qty' => 1,
                'valid_from' => Carbon::now(),
                'valid_to' => null,
            ],

            // Self-driven specific addons
            [
                'service_type_id' => $serviceTypes['ride_now']->id ?? null,
                'name' => 'Comprehensive Insurance',
                'description' => 'Full coverage insurance for self-driven vehicles',
                'amount' => 15.00,
                'rate_type' => 'percentage',
                'billing_type' => 'per_day',
                'min_qty' => 1,
                'max_qty' => 1,
                'valid_from' => Carbon::now(),
                'valid_to' => null,
            ],
            [
                'service_type_id' => $serviceTypes['ride_now']->id ?? null,
                'name' => 'Basic Insurance',
                'description' => 'Basic insurance coverage for self-driven vehicles',
                'amount' => 8.00,
                'rate_type' => 'percentage',
                'billing_type' => 'per_day',
                'min_qty' => 1,
                'max_qty' => 1,
                'valid_from' => Carbon::now(),
                'valid_to' => null,
            ],
            [
                'service_type_id' => $serviceTypes['ride_now']->id ?? null,
                'name' => 'Emergency Roadside Assistance',
                'description' => '24/7 emergency roadside assistance and breakdown support',
                'amount' => 1500.00,
                'rate_type' => 'flat',
                'billing_type' => 'per_package', // One-time charge for the entire booking
                'min_qty' => 1,
                'max_qty' => 1,
                'valid_from' => Carbon::now(),
                'valid_to' => null,
            ],
            [
                'service_type_id' => $serviceTypes['ride_now']->id ?? null,
                'name' => 'Fuel Package',
                'description' => 'Pre-paid fuel package for convenience',
                'amount' => 5000.00,
                'rate_type' => 'flat',
                'billing_type' => 'per_package', // One-time charge for the entire booking
                'min_qty' => 1,
                'max_qty' => 1,
                'valid_from' => Carbon::now(),
                'valid_to' => null,
            ],

            // Corporate service addons
            [
                'service_type_id' => $serviceTypes['corporate']->id ?? null,
                'name' => 'Corporate Branding',
                'description' => 'Custom corporate branding and signage on vehicle',
                'amount' => 2000.00,
                'rate_type' => 'flat',
                'min_qty' => 1,
                'max_qty' => 1,
                'valid_from' => Carbon::now(),
                'valid_to' => null,
            ],
            [
                'service_type_id' => $serviceTypes['corporate']->id ?? null,
                'name' => 'Business Meeting Setup',
                'description' => 'Mobile office setup with table and power outlets',
                'amount' => 1500.00,
                'rate_type' => 'flat',
                'min_qty' => 1,
                'max_qty' => 1,
                'valid_from' => Carbon::now(),
                'valid_to' => null,
            ],
            [
                'service_type_id' => $serviceTypes['corporate']->id ?? null,
                'name' => 'VIP Escort Service',
                'description' => 'Professional escort and security service for VIP clients',
                'amount' => 5000.00,
                'rate_type' => 'flat',
                'min_qty' => 1,
                'max_qty' => 2,
                'valid_from' => Carbon::now(),
                'valid_to' => null,
            ],

            // Airport service addons
            [
                'service_type_id' => $serviceTypes['airport_transfer']->id ?? null,
                'name' => 'Meet & Greet Service',
                'description' => 'Professional meet and greet service at airport arrival',
                'amount' => 1500.00,
                'rate_type' => 'flat',
                'min_qty' => 1,
                'max_qty' => 1,
                'valid_from' => Carbon::now(),
                'valid_to' => null,
            ],
            [
                'service_type_id' => $serviceTypes['airport_transfer']->id ?? null,
                'name' => 'Flight Tracking',
                'description' => 'Real-time flight tracking and schedule adjustment',
                'amount' => 500.00,
                'rate_type' => 'flat',
                'min_qty' => 1,
                'max_qty' => 1,
                'valid_from' => Carbon::now(),
                'valid_to' => null,
            ],
            [
                'service_type_id' => $serviceTypes['airport_transfer']->id ?? null,
                'name' => 'Luggage Assistance',
                'description' => 'Professional luggage handling and assistance',
                'amount' => 800.00,
                'rate_type' => 'flat',
                'min_qty' => 1,
                'max_qty' => 1,
                'valid_from' => Carbon::now(),
                'valid_to' => null,
            ],

            // Transfer service addons
            [
                'service_type_id' => $serviceTypes['airport_transfer']->id ?? null,
                'name' => 'Multi-Stop Package',
                'description' => 'Additional charge for multiple stop transfers',
                'amount' => 500.00,
                'rate_type' => 'flat',
                'min_qty' => 1,
                'max_qty' => 5,
                'valid_from' => Carbon::now(),
                'valid_to' => null,
            ],
            [
                'service_type_id' => $serviceTypes['airport_transfer']->id ?? null,
                'name' => 'Express Transfer',
                'description' => 'Premium express transfer service with priority routing',
                'amount' => 1000.00,
                'rate_type' => 'flat',
                'min_qty' => 1,
                'max_qty' => 1,
                'valid_from' => Carbon::now(),
                'valid_to' => null,
            ],

            // Break down service addons
            [
                'service_type_id' => $serviceTypes['break_down_service']->id ?? null,
                'name' => 'Emergency Towing',
                'description' => 'Emergency vehicle towing service',
                'amount' => 150.00,
                'rate_type' => 'flat', // per km
                'min_qty' => 1,
                'max_qty' => 1,
                'valid_from' => Carbon::now(),
                'valid_to' => null,
            ],
            [
                'service_type_id' => $serviceTypes['break_down_service']->id ?? null,
                'name' => 'On-Site Repair',
                'description' => 'Basic on-site vehicle repair service',
                'amount' => 2000.00,
                'rate_type' => 'flat',
                'min_qty' => 1,
                'max_qty' => 1,
                'valid_from' => Carbon::now(),
                'valid_to' => null,
            ],
            [
                'service_type_id' => $serviceTypes['break_down_service']->id ?? null,
                'name' => 'Jump Start Service',
                'description' => 'Battery jump start service',
                'amount' => 1000.00,
                'rate_type' => 'flat',
                'min_qty' => 1,
                'max_qty' => 1,
                'valid_from' => Carbon::now(),
                'valid_to' => null,
            ],
            [
                'service_type_id' => $serviceTypes['break_down_service']->id ?? null,
                'name' => 'Tire Change Service',
                'description' => 'Professional tire changing service',
                'amount' => 1500.00,
                'rate_type' => 'flat',
                'min_qty' => 1,
                'max_qty' => 1,
                'valid_from' => Carbon::now(),
                'valid_to' => null,
            ],
        ];

        // Create or update addons
        foreach ($addons as $addonData) {
            VehicleAddon::updateOrCreate(
                [
                    'service_type_id' => $addonData['service_type_id'],
                    'name' => $addonData['name'],
                ],
                $addonData
            );
        }

        $this->command->info('Vehicle addons seeded successfully!');
    }
}