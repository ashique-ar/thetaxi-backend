<?php

namespace Database\Seeders;

use App\Models\Service\ServiceType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * Creates self_drive, with_driver, and day_rental as separate service types.
 * Each gets its own pricing, packages, and form configuration.
 *
 * Run: php artisan db:seed --class=SeparateRentalServiceTypesSeeder
 */
class SeparateRentalServiceTypesSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            [
                'code' => 'self_drive',
                'name' => 'Self Drive',
                'description' => 'Customer drives the vehicle themselves. No driver provided.',
                'type' => 'self_driven',
                'priority' => 6,
                'is_active' => true,
                'pricing_mode' => 'day',
                'uses_dropoff_time' => true,
            ],
            [
                'code' => 'with_driver',
                'name' => 'With Driver',
                'description' => 'Vehicle rental with a professional driver provided.',
                'type' => 'with_driver',
                'priority' => 7,
                'is_active' => true,
                'pricing_mode' => 'day',
                'uses_dropoff_time' => true,
            ],
        ];

        foreach ($types as $data) {
            $serviceType = ServiceType::updateOrCreate(
                ['code' => $data['code']],
                $data
            );

            Log::info("Service type '{$data['code']}' created/updated", [
                'id' => $serviceType->id,
                'code' => $serviceType->code,
            ]);

            $this->command->info("Service type '{$data['code']}' (ID: {$serviceType->id}) created/updated.");
        }

        // Update booking form tabs to point to their own service types
        $this->updateBookingFormTabs();
    }

    private function updateBookingFormTabs(): void
    {
        $tabMappings = [
            'self_drive' => 'self_drive',
            'with_driver' => 'with_driver',
        ];

        foreach ($tabMappings as $tabCode => $serviceTypeCode) {
            $updated = \App\Models\BookingFormTab::where('code', $tabCode)
                ->update(['service_type_code' => $serviceTypeCode]);

            if ($updated) {
                $this->command->info("Tab '{$tabCode}' now points to service type '{$serviceTypeCode}'.");
            }
        }
    }
}
