<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE vehicle_group_pricing DROP CONSTRAINT IF EXISTS vehicle_group_pricing_rate_type_check");

        // Add per_km to the rate_type enum in vehicle_group_pricing table
        DB::statement("ALTER TABLE vehicle_group_pricing ALTER COLUMN rate_type TYPE VARCHAR(20)");
        DB::statement("ALTER TABLE vehicle_group_pricing ADD CONSTRAINT vehicle_group_pricing_rate_type_check CHECK (rate_type IN ('per_hour', 'per_day', 'flat_rate', 'per_km'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove per_km from the rate_type enum (revert to original)
        DB::statement("ALTER TABLE vehicle_group_pricing DROP CONSTRAINT IF EXISTS vehicle_group_pricing_rate_type_check");
        DB::statement("ALTER TABLE vehicle_group_pricing ADD CONSTRAINT vehicle_group_pricing_rate_type_check CHECK (rate_type IN ('per_hour', 'per_day', 'flat_rate'))");
    }
};
