<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const UNSAFE_FORMULA = 'Hourly_Package+((total_distance-Minimum_KM)*extra_km_rate)+((duration_hours-Minimum_Hours)*extra_hour_rate)';

    private const SAFE_FORMULA = 'Hourly_Package + (max(0, total_distance - Minimum_KM) * extra_km_rate) + (max(0, duration_hours - Minimum_Hours) * extra_hour_rate)';

    public function up(): void
    {
        if (
            !Schema::hasTable('service_types')
            || !Schema::hasTable('vehicle_pricing_calculation_definitions')
        ) {
            return;
        }

        $hourlyServiceIds = DB::table('service_types')
            ->whereRaw('LOWER(code) = ?', ['hourly_package'])
            ->pluck('id');

        if ($hourlyServiceIds->isEmpty()) {
            return;
        }

        $definitions = DB::table('vehicle_pricing_calculation_definitions')
            ->whereIn('service_type_id', $hourlyServiceIds)
            ->where('status', 'active')
            ->when(
                Schema::hasColumn('vehicle_pricing_calculation_definitions', 'deleted_at'),
                fn ($query) => $query->whereNull('deleted_at')
            )
            ->get(['id', 'formula']);

        foreach ($definitions as $definition) {
            $normalized = preg_replace('/\s+/', '', (string) $definition->formula);
            if ($normalized !== self::UNSAFE_FORMULA) {
                continue;
            }

            DB::table('vehicle_pricing_calculation_definitions')
                ->where('id', $definition->id)
                ->update([
                    'formula' => self::SAFE_FORMULA,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Intentionally irreversible: restoring a formula that subtracts
        // included distance/time without a zero floor can undercharge trips.
    }
};
