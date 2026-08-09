<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The drivers_availability_status_check constraint was created with a legacy value
 * set (available, booked, long_term, resting, on_leave, offline) that no longer
 * matches what CreateDriverRequest/UpdateDriverRequest actually validate and write
 * (available, busy, on_break, offline).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql' || !Schema::hasTable('drivers')) {
            return;
        }

        DB::statement("
            UPDATE drivers
            SET availability_status = CASE availability_status
                WHEN 'booked' THEN 'busy'
                WHEN 'long_term' THEN 'busy'
                WHEN 'resting' THEN 'on_break'
                WHEN 'on_leave' THEN 'on_break'
                ELSE availability_status
            END
            WHERE availability_status IN ('booked', 'long_term', 'resting', 'on_leave');
        ");

        DB::statement('
            ALTER TABLE drivers
            DROP CONSTRAINT IF EXISTS drivers_availability_status_check;
        ');

        DB::statement("
            ALTER TABLE drivers
            ADD CONSTRAINT drivers_availability_status_check
            CHECK (
                availability_status IN ('available', 'busy', 'on_break', 'offline')
            );
        ");
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql' || !Schema::hasTable('drivers')) {
            return;
        }

        DB::statement('
            ALTER TABLE drivers
            DROP CONSTRAINT IF EXISTS drivers_availability_status_check;
        ');

        DB::statement("
            ALTER TABLE drivers
            ADD CONSTRAINT drivers_availability_status_check
            CHECK (
                availability_status IN ('available', 'booked', 'long_term', 'resting', 'on_leave', 'offline')
            );
        ");
    }
};
