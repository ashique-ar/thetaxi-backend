<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("
            ALTER TABLE vehicle_assignments
            DROP CONSTRAINT IF EXISTS vehicle_assignments_overlap_type_check;
        ");

        DB::statement("
            ALTER TABLE vehicle_assignments
            ADD CONSTRAINT vehicle_assignments_overlap_type_check
            CHECK (
                overlap_type IS NULL
                OR overlap_type IN ('rest_window','partial_availability','override','concurrent')
            );
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vehicle_assignments', function (Blueprint $table) {
            $table->dropColumn([
                'maintenance_window',
                'fuel_level',
                'mileage_start',
                'mileage_end',
                'special_requirements'
            ]);
        });
    }
};
