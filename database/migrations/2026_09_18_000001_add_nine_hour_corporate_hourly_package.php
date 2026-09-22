<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $serviceId = DB::table('service_types')->where('code', 'hourly_package')->whereNull('deleted_at')->value('id');
        if (!$serviceId) {
            return;
        }

        DB::transaction(function () use ($serviceId): void {
            $package = DB::table('service_packages')->where('code', 'hourly_9h_100km')->first();
            $packageId = $package?->id ?? (string) Str::uuid();
            if (!$package) {
                DB::table('service_packages')->insert([
                    'id' => $packageId, 'service_type_id' => $serviceId,
                    'code' => 'hourly_9h_100km', 'name' => '9 Hours / 100 KM',
                    'description' => 'Corporate open package including 9 hours and 100 KM.',
                    'max_km_per_day' => null, 'max_km_per_package' => 100,
                    'price_multiplier' => 1, 'rate_type' => 'hourly',
                    'default_duration_hours' => 9, 'default_duration_minutes' => 0,
                    'is_active' => true, 'sort_order' => 5,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            if (!DB::table('vehicle_pricing_slab_definitions')->where('service_package_id', $packageId)->exists()) {
                DB::table('vehicle_pricing_slab_definitions')->insert([
                    'id' => (string) Str::uuid(), 'service_type_id' => $serviceId,
                    'service_package_id' => $packageId, 'name' => 'Package - 9 Hours / 100 KM',
                    'type' => 'flat_rate', 'max_km_per_package' => 100,
                    'sort_order' => 5, 'is_active' => true, 'priority' => 500,
                    'owner_type' => null, 'owner_id' => null,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Retain package references and pricing history used by existing bookings.
    }
};
