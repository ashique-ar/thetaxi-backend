<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $serviceId = DB::table('service_types')
            ->where('code', 'hourly_package')
            ->whereNull('deleted_at')
            ->value('id');
        if (!$serviceId) {
            return;
        }

        DB::table('vehicle_pricing_common_rate_definitions')
            ->where('service_type_id', $serviceId)
            ->whereNotIn('code', ['extra_km_rate', 'extra_hour_rate'])
            ->update(['is_active' => false, 'deleted_at' => now(), 'updated_at' => now()]);

        DB::table('vehicle_pricing_calculation_definitions')
            ->where('service_type_id', $serviceId)
            ->where('name', '!=', 'Hourly Package')
            ->update(['status' => 'inactive', 'deleted_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Forward cleanup only; old pricing inputs must not become active again.
    }
};
