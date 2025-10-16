<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Vehicle\VehicleAddon;
use App\Models\Vehicle\VehicleAddonDependency;

class VehicleAddonDependencySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->seedAddonDependencies();
    }

    /**
     * Seed vehicle addon dependencies and relationships
     */
    private function seedAddonDependencies(): void
    {
        // Get addons by name for easy reference
        $addons = VehicleAddon::all()->keyBy('name');

        $dependencies = [
            // Insurance dependencies for self-driven vehicles
            [
                'parent_addon' => 'Comprehensive Insurance',
                'required_addon' => 'Emergency Roadside Assistance',
                'dependency_type' => 'recommended',
                'description' => 'Roadside assistance is recommended with comprehensive insurance',
                'is_automatic' => false,
                'minimum_quantity' => 1,
                'maximum_quantity' => 1,
                'discount_percentage' => 10.00,
                'conditions' => [
                    [
                        'field' => 'service_type',
                        'operator' => 'equals',
                        'value' => 'self_driven'
                    ]
                ]
            ],
            [
                'parent_addon' => 'Basic Insurance',
                'required_addon' => 'Emergency Roadside Assistance',
                'dependency_type' => 'required',
                'description' => 'Roadside assistance is mandatory with basic insurance',
                'is_automatic' => true,
                'minimum_quantity' => 1,
                'maximum_quantity' => 1,
                'discount_percentage' => 5.00,
                'conditions' => [
                    [
                        'field' => 'service_type',
                        'operator' => 'equals',
                        'value' => 'self_driven'
                    ]
                ]
            ],

            // Wedding decoration dependencies
            [
                'parent_addon' => 'Wedding Decoration Package',
                'required_addon' => 'Fresh Flower Arrangement',
                'dependency_type' => 'mutually_exclusive',
                'description' => 'Wedding decoration package already includes flower arrangements',
                'is_automatic' => false,
                'minimum_quantity' => 0,
                'maximum_quantity' => 0,
                'conditions' => [
                    [
                        'field' => 'service_type',
                        'operator' => 'equals',
                        'value' => 'wedding_hire'
                    ]
                ]
            ],
            [
                'parent_addon' => 'Wedding Decoration Package',
                'required_addon' => 'Bridal Car Ribbon Set',
                'dependency_type' => 'mutually_exclusive',
                'description' => 'Wedding decoration package already includes ribbon decoration',
                'is_automatic' => false,
                'minimum_quantity' => 0,
                'maximum_quantity' => 0,
                'conditions' => [
                    [
                        'field' => 'service_type',
                        'operator' => 'equals',
                        'value' => 'wedding_hire'
                    ]
                ]
            ],

            // Child safety dependencies
            [
                'parent_addon' => 'Baby Car Seat',
                'required_addon' => 'Child Safety Seat',
                'dependency_type' => 'mutually_exclusive',
                'description' => 'Baby car seat and child safety seat serve different age groups',
                'is_automatic' => false,
                'minimum_quantity' => 0,
                'maximum_quantity' => 0,
                'conditions' => []
            ],

            // Corporate service dependencies
            [
                'parent_addon' => 'VIP Escort Service',
                'required_addon' => 'Corporate Branding',
                'dependency_type' => 'recommended',
                'description' => 'Corporate branding enhances VIP service presentation',
                'is_automatic' => false,
                'minimum_quantity' => 1,
                'maximum_quantity' => 1,
                'discount_percentage' => 15.00,
                'conditions' => [
                    [
                        'field' => 'service_type',
                        'operator' => 'equals',
                        'value' => 'corporate'
                    ]
                ]
            ],
            [
                'parent_addon' => 'Business Meeting Setup',
                'required_addon' => 'Wi-Fi Hotspot',
                'dependency_type' => 'recommended',
                'description' => 'Wi-Fi connectivity enhances business meeting setup',
                'is_automatic' => false,
                'minimum_quantity' => 1,
                'maximum_quantity' => 1,
                'discount_percentage' => 20.00,
                'conditions' => [
                    [
                        'field' => 'service_type',
                        'operator' => 'equals',
                        'value' => 'corporate'
                    ]
                ]
            ],

            // Airport service dependencies
            [
                'parent_addon' => 'Meet & Greet Service',
                'required_addon' => 'Flight Tracking',
                'dependency_type' => 'required',
                'description' => 'Flight tracking is essential for meet & greet service',
                'is_automatic' => true,
                'minimum_quantity' => 1,
                'maximum_quantity' => 1,
                'discount_amount' => 200.00,
                'conditions' => [
                    [
                        'field' => 'service_type',
                        'operator' => 'in',
                        'value' => ['airport_pickup', 'airport_drop']
                    ]
                ]
            ],
            [
                'parent_addon' => 'Luggage Assistance',
                'required_addon' => 'Extra Driver',
                'dependency_type' => 'recommended',
                'description' => 'Extra driver recommended for heavy luggage assistance',
                'is_automatic' => false,
                'minimum_quantity' => 1,
                'maximum_quantity' => 1,
                'conditions' => [
                    [
                        'field' => 'luggage_count',
                        'operator' => 'greater_than',
                        'value' => 5
                    ]
                ]
            ],

            // Transfer service dependencies
            [
                'parent_addon' => 'Multi-Stop Package',
                'required_addon' => 'GPS Navigation System',
                'dependency_type' => 'recommended',
                'description' => 'GPS navigation helps with multiple stop coordination',
                'is_automatic' => false,
                'minimum_quantity' => 1,
                'maximum_quantity' => 1,
                'discount_percentage' => 10.00,
                'conditions' => [
                    [
                        'field' => 'service_type',
                        'operator' => 'equals',
                        'value' => 'transfers'
                    ],
                    [
                        'field' => 'stop_count',
                        'operator' => 'greater_than',
                        'value' => 2
                    ]
                ]
            ],
            [
                'parent_addon' => 'Express Transfer',
                'required_addon' => 'GPS Navigation System',
                'dependency_type' => 'required',
                'description' => 'GPS navigation is mandatory for express transfers',
                'is_automatic' => true,
                'minimum_quantity' => 1,
                'maximum_quantity' => 1,
                'discount_amount' => 100.00,
                'conditions' => [
                    [
                        'field' => 'service_type',
                        'operator' => 'equals',
                        'value' => 'transfers'
                    ]
                ]
            ],

            // Break down service dependencies
            [
                'parent_addon' => 'Emergency Towing',
                'required_addon' => 'On-Site Repair',
                'dependency_type' => 'upgrade_path',
                'description' => 'Consider on-site repair before towing',
                'is_automatic' => false,
                'minimum_quantity' => 1,
                'maximum_quantity' => 1,
                'conditions' => [
                    [
                        'field' => 'service_type',
                        'operator' => 'equals',
                        'value' => 'break_down_service'
                    ]
                ]
            ],
            [
                'parent_addon' => 'On-Site Repair',
                'required_addon' => 'Jump Start Service',
                'dependency_type' => 'mutually_exclusive',
                'description' => 'On-site repair includes jump start service',
                'is_automatic' => false,
                'minimum_quantity' => 0,
                'maximum_quantity' => 0,
                'conditions' => [
                    [
                        'field' => 'service_type',
                        'operator' => 'equals',
                        'value' => 'break_down_service'
                    ]
                ]
            ],
            [
                'parent_addon' => 'On-Site Repair',
                'required_addon' => 'Tire Change Service',
                'dependency_type' => 'mutually_exclusive',
                'description' => 'On-site repair includes tire change service',
                'is_automatic' => false,
                'minimum_quantity' => 0,
                'maximum_quantity' => 0,
                'conditions' => [
                    [
                        'field' => 'service_type',
                        'operator' => 'equals',
                        'value' => 'break_down_service'
                    ]
                ]
            ],

            // Universal addon dependencies
            [
                'parent_addon' => 'Extra Driver',
                'required_addon' => 'Mobile Charger',
                'dependency_type' => 'recommended',
                'description' => 'Mobile charger recommended for long journeys with extra driver',
                'is_automatic' => false,
                'minimum_quantity' => 1,
                'maximum_quantity' => 2,
                'discount_percentage' => 5.00,
                'conditions' => [
                    [
                        'field' => 'duration_days',
                        'operator' => 'greater_than',
                        'value' => 1
                    ]
                ]
            ],
            [
                'parent_addon' => 'Wi-Fi Hotspot',
                'required_addon' => 'Mobile Charger',
                'dependency_type' => 'recommended',
                'description' => 'Mobile charger recommended to keep devices powered with Wi-Fi usage',
                'is_automatic' => false,
                'minimum_quantity' => 1,
                'maximum_quantity' => 1,
                'discount_amount' => 50.00,
                'conditions' => []
            ],
        ];

        // Create dependencies
        foreach ($dependencies as $depData) {
            $parentAddon = $addons->get($depData['parent_addon']);
            $requiredAddon = $addons->get($depData['required_addon']);

            if ($parentAddon && $requiredAddon) {
                VehicleAddonDependency::updateOrCreate(
                    [
                        'parent_addon_id' => $parentAddon->id,
                        'required_addon_id' => $requiredAddon->id,
                    ],
                    [
                        'dependency_type' => $depData['dependency_type'],
                        'description' => $depData['description'],
                        'is_automatic' => $depData['is_automatic'],
                        'conditions' => $depData['conditions'] ?? null,
                        'minimum_quantity' => $depData['minimum_quantity'],
                        'maximum_quantity' => $depData['maximum_quantity'],
                        'discount_percentage' => $depData['discount_percentage'] ?? null,
                        'discount_amount' => $depData['discount_amount'] ?? null,
                        'is_active' => true,
                    ]
                );
            } else {
                $this->command->warn("Could not find addons: {$depData['parent_addon']} -> {$depData['required_addon']}");
            }
        }

        $this->command->info('Vehicle addon dependencies seeded successfully!');
    }
}