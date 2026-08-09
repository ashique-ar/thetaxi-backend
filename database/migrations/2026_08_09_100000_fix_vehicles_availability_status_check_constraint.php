<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The vehicles_availability_status_check constraint was created with a legacy value
 * set (available, booked, long_term, resting, maintenance, offline) that no longer
 * matches App\Enums\VehicleAvailabilityStatus (available, on_hire, unavailable_qc,
 * unavailable_repair, unavailable_maintenance, unavailable_offline), which is what
 * the app actually writes. Every non-"available" write has been rejected by Postgres
 * in production since the enum diverged from the constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql' || !Schema::hasTable('vehicles')) {
            return;
        }

        DB::statement("
            UPDATE vehicles
            SET availability_status = CASE availability_status
                WHEN 'booked' THEN 'on_hire'
                WHEN 'long_term' THEN 'on_hire'
                WHEN 'maintenance' THEN 'unavailable_maintenance'
                WHEN 'resting' THEN 'unavailable_offline'
                WHEN 'offline' THEN 'unavailable_offline'
                ELSE availability_status
            END
            WHERE availability_status IN ('booked', 'long_term', 'maintenance', 'resting', 'offline');
        ");

        DB::statement('
            ALTER TABLE vehicles
            DROP CONSTRAINT IF EXISTS vehicles_availability_status_check;
        ');

        DB::statement("
            ALTER TABLE vehicles
            ADD CONSTRAINT vehicles_availability_status_check
            CHECK (
                availability_status IN (
                    'available',
                    'on_hire',
                    'unavailable_qc',
                    'unavailable_repair',
                    'unavailable_maintenance',
                    'unavailable_offline'
                )
            );
        ");
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql' || !Schema::hasTable('vehicles')) {
            return;
        }

        DB::statement('
            ALTER TABLE vehicles
            DROP CONSTRAINT IF EXISTS vehicles_availability_status_check;
        ');

        DB::statement("
            ALTER TABLE vehicles
            ADD CONSTRAINT vehicles_availability_status_check
            CHECK (
                availability_status IN ('available', 'booked', 'long_term', 'resting', 'maintenance', 'offline')
            );
        ");
    }
};
