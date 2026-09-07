<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('vehicle_pricing_common_rate_definitions')
            ->where(function ($query): void {
                $query->where('code', 'like', '%rate%')
                    ->orWhere('code', 'like', '%charge%')
                    ->orWhere('code', 'like', '%fare%')
                    ->orWhere('code', 'like', '%price%')
                    ->orWhere('code', 'like', '%cost%');
            })
            ->update([
                'display_unit' => DB::raw("CASE common_rate_type
                    WHEN 'per_km' THEN 'LKR/km'
                    WHEN 'per_minute' THEN 'LKR/min'
                    WHEN 'per_hour' THEN 'LKR/hr'
                    WHEN 'per_day' THEN 'LKR/day'
                    WHEN 'percentage' THEN '%'
                    ELSE 'LKR'
                END"),
            ]);
    }

    public function down(): void
    {
        // Display units remain valid metadata when this corrective migration is rolled back.
    }
};
