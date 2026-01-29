<?php

namespace Database\Seeders;

use App\Models\Service\ServiceType;
use Illuminate\Database\Seeder;

class ServiceTypeDefaultsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $mappings = [
            // code => attributes to set
            'day_rental' => [
                'pricing_mode' => 'day',
                'uses_dropoff_time' => true,
                'allow_return_trip' => true,
                'frontend_category' => 'rental',
            ],
            'ride_now' => [
                'pricing_mode' => 'trip',
                'uses_dropoff_time' => false,
                'allow_return_trip' => true,
                'frontend_category' => 'ride',
            ],
            'airport_transfers' => [
                'pricing_mode' => 'trip',
                'uses_dropoff_time' => false,
                'allow_return_trip' => false,
                'frontend_category' => 'transfer',
            ],
            'point_to_point' => [
                'pricing_mode' => 'trip',
                'uses_dropoff_time' => true,
                'allow_return_trip' => true,
                'frontend_category' => 'trip',
            ],
            'self_driven' => [
                'pricing_mode' => 'day',
                'uses_dropoff_time' => true,
                'allow_return_trip' => true,
                'frontend_category' => 'rental',
            ],
        ];

        foreach ($mappings as $code => $attrs) {
            // Try to find by code first, then by name fallback
            $serviceType = ServiceType::where('code', $code)->first();
            if (!$serviceType) {
                $serviceType = ServiceType::where('name', 'like', '%' . str_replace('_', ' ', $code) . '%')->first();
            }

            if ($serviceType) {
                $serviceType->update($attrs);
                $this->command->info("Updated ServiceType: {$serviceType->code} ({$serviceType->name})");
            } else {
                // If not found, create a record so admin can adjust later
                $created = ServiceType::create(array_merge(['code' => $code, 'name' => ucfirst(str_replace('_', ' ', $code))], $attrs));
                $this->command->info("Created ServiceType: {$created->code} ({$created->name}) - please review defaults");
            }
        }

        $this->command->info('ServiceType defaults seeder completed.');
    }
}
