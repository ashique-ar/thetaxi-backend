<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql' || !Schema::hasTable('vehicle_assignments')) {
            return;
        }

        DB::statement('
            ALTER TABLE vehicle_assignments
            DROP CONSTRAINT IF EXISTS vehicle_assignments_overlap_type_check;
        ');

        DB::statement("
            ALTER TABLE vehicle_assignments
            ADD CONSTRAINT vehicle_assignments_overlap_type_check
            CHECK (
                overlap_type IS NULL
                OR overlap_type IN ('rest_window','partial_availability','override','concurrent')
            );
        ");
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql' || !Schema::hasTable('vehicle_assignments')) {
            return;
        }

        DB::statement('
            ALTER TABLE vehicle_assignments
            DROP CONSTRAINT IF EXISTS vehicle_assignments_overlap_type_check;
        ');

        DB::statement("
            ALTER TABLE vehicle_assignments
            ADD CONSTRAINT vehicle_assignments_overlap_type_check
            CHECK (
                overlap_type IS NULL
                OR overlap_type IN ('rest_window','partial_availability','override')
            );
        ");
    }
};
