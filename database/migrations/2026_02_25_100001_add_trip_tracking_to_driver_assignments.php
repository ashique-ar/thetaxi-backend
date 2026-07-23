<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds trip tracking columns to driver_assignments for the mobile booking API.
     * Also extends the status enum to include 'confirmed' and 'declined'.
     */
    public function up(): void
    {
        // Extend the status enum to include 'confirmed' and 'declined'
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE driver_assignments DROP CONSTRAINT IF EXISTS driver_assignments_status_check');
            DB::statement("ALTER TABLE driver_assignments ALTER COLUMN status SET DEFAULT 'active'");
            DB::statement("ALTER TABLE driver_assignments ADD CONSTRAINT driver_assignments_status_check CHECK (status IN ('active', 'completed', 'cancelled', 'pending_approval', 'confirmed', 'declined'))");
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE driver_assignments MODIFY COLUMN status ENUM('active', 'completed', 'cancelled', 'pending_approval', 'confirmed', 'declined') DEFAULT 'active'");
        }

        Schema::table('driver_assignments', function (Blueprint $table) {
            // Trip lifecycle phase
            $table->enum('trip_phase', [
                'active',
                'confirmed',
                'accepted',
                'pickup_arrived',
                'in_progress',
                'completed',
                'declined',
            ])->default('active')->after('status');

            // Trip timestamps
            $table->timestamp('trip_started_at')->nullable()->after('trip_phase');
            $table->timestamp('trip_completed_at')->nullable()->after('trip_started_at');
            $table->timestamp('pickup_arrived_at')->nullable()->after('trip_completed_at');

            // Pickup arrival coordinates (actual, separate from planned)
            $table->decimal('pickup_arrival_latitude', 10, 8)->nullable()->after('pickup_arrived_at');
            $table->decimal('pickup_arrival_longitude', 11, 8)->nullable()->after('pickup_arrival_latitude');

            // Final dropoff coordinates
            $table->decimal('final_latitude', 10, 8)->nullable()->after('pickup_arrival_longitude');
            $table->decimal('final_longitude', 11, 8)->nullable()->after('final_latitude');

            // Trip metrics
            $table->decimal('total_distance_km', 8, 2)->nullable()->after('final_longitude');
            $table->integer('total_waiting_time_seconds')->nullable()->after('total_distance_km');

            // Decline reason
            $table->text('decline_reason')->nullable()->after('total_waiting_time_seconds');

            // Link to specific booking item
            $table->uuid('booking_item_id')->nullable()->after('decline_reason');

            // Indexes
            $table->index(['driver_id', 'trip_phase']);
            $table->index('booking_item_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('driver_assignments', function (Blueprint $table) {
            $table->dropForeign(['booking_item_id']);
            $table->dropIndex(['driver_id', 'trip_phase']);
            $table->dropIndex(['booking_item_id']);

            $table->dropColumn([
                'trip_phase',
                'trip_started_at',
                'trip_completed_at',
                'pickup_arrived_at',
                'pickup_arrival_latitude',
                'pickup_arrival_longitude',
                'final_latitude',
                'final_longitude',
                'total_distance_km',
                'total_waiting_time_seconds',
                'decline_reason',
                'booking_item_id',
            ]);
        });

        // Revert status enum
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE driver_assignments DROP CONSTRAINT IF EXISTS driver_assignments_status_check');
            DB::statement("ALTER TABLE driver_assignments ADD CONSTRAINT driver_assignments_status_check CHECK (status IN ('active', 'completed', 'cancelled', 'pending_approval'))");
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE driver_assignments MODIFY COLUMN status ENUM('active', 'completed', 'cancelled', 'pending_approval') DEFAULT 'active'");
        }
    }
};
