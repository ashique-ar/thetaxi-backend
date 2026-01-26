<?php

namespace Database\Seeders;

use App\Models\Service\ServicePackage;
use App\Models\Service\ServicePackageReturnRule;
use App\Models\Service\ServiceType;
use Illuminate\Database\Seeder;

class ServicePackageReturnRuleSeeder extends Seeder
{
    /**
     * Seed the database with default return trip rules.
     *
     * Default pricing:
     * - Same day return: 50% of one-way fare
     * - Next day return: 90% of one-way fare
     * - 2+ days later: 100% of one-way fare (no discount)
     */
    public function run(): void
    {
        $this->command->info('🔄 Starting ServicePackageReturnRule seeder...');

        // Define default return rules
        $defaultRules = [
            [
                'day_offset_min' => 0,
                'day_offset_max' => 0,
                'charge_percentage' => 50.00,
                'label' => 'Same Day Return',
                'description' => 'Return on the same day and save 50% on your return trip!',
                'priority' => 100,
            ],
            [
                'day_offset_min' => 1,
                'day_offset_max' => 1,
                'charge_percentage' => 90.00,
                'label' => 'Next Day Return',
                'description' => 'Return the next day and save 10% on your return trip.',
                'priority' => 90,
            ],
            [
                'day_offset_min' => 2,
                'day_offset_max' => null, // No upper limit
                'charge_percentage' => 100.00,
                'label' => 'Standard Return',
                'description' => 'Return after 2 or more days at standard fare.',
                'priority' => 80,
            ],
        ];

        // Only ride_now service type supports return trips
        $serviceType = ServiceType::where('code', 'ride_now')->first();

        if (!$serviceType) {
            $this->command->error("❌ ride_now service type not found. Cannot proceed.");
            return;
        }

        // Get all active packages for ride_now
        $packages = ServicePackage::where('service_type_id', $serviceType->id)
            ->where('is_active', true)
            ->get();

        // If no packages exist, create a default one
        if ($packages->isEmpty()) {
            $this->command->info("📦 No ride_now packages found. Creating default package...");

            $defaultPackage = ServicePackage::create([
                'service_type_id' => $serviceType->id,
                'name' => 'Standard Drop & Pickup',
                'code' => 'ride_now_standard',
                'description' => 'Standard drop and pickup service with optional return trip',
                'max_km_per_day' => null,
                'max_km_per_package' => null,
                'price_multiplier' => 1.0000,
                'rate_type' => 'flat',
                'default_duration_hours' => null,
                'is_active' => true,
                'sort_order' => 1,
            ]);

            $packages = collect([$defaultPackage]);
            $this->command->info("✅ Created default package: {$defaultPackage->name}");
        }

        $this->command->info("📋 Found {$packages->count()} ride_now package(s)");

        // Create return rules for each package
        foreach ($packages as $package) {
            $this->command->info("  📦 Creating return rules for package: {$package->name}");

            foreach ($defaultRules as $ruleData) {
                // Check if rule already exists for this package and day range
                $existingRule = ServicePackageReturnRule::where('service_package_id', $package->id)
                    ->where('day_offset_min', $ruleData['day_offset_min'])
                    ->where(function ($q) use ($ruleData) {
                        if ($ruleData['day_offset_max'] === null) {
                            $q->whereNull('day_offset_max');
                        } else {
                            $q->where('day_offset_max', $ruleData['day_offset_max']);
                        }
                    })
                    ->whereNull('vehicle_group_id') // Package-level rule
                    ->first();

                if ($existingRule) {
                    $this->command->info("    ⏭️  Skipping existing rule: {$ruleData['label']}");
                    continue;
                }

                ServicePackageReturnRule::create([
                    'service_package_id' => $package->id,
                    'vehicle_group_id' => null, // Package-level rule (applies to all vehicle groups)
                    'day_offset_min' => $ruleData['day_offset_min'],
                    'day_offset_max' => $ruleData['day_offset_max'],
                    'charge_percentage' => $ruleData['charge_percentage'],
                    'label' => $ruleData['label'],
                    'description' => $ruleData['description'],
                    'same_vehicle_required' => false,
                    'same_driver_required' => false,
                    'min_wait_minutes' => null,
                    'max_wait_hours' => null,
                    'is_active' => true,
                    'priority' => $ruleData['priority'],
                    'effective_from' => null,
                    'effective_to' => null,
                ]);

                $discountPercent = 100 - $ruleData['charge_percentage'];
                $this->command->info("    ✅ Created rule: {$ruleData['label']} ({$discountPercent}% discount)");
            }
        }

        $totalRules = ServicePackageReturnRule::count();
        $this->command->info("✅ ServicePackageReturnRule seeder completed! Total rules: {$totalRules}");
    }
}
