<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // bookings — queries filter by status + date, or list by customer
        Schema::table('bookings', function (Blueprint $table) {
            if (!$this->indexExists('bookings', 'bookings_status_booking_date_index')) {
                $table->index(['status', 'booking_date'], 'bookings_status_booking_date_index');
            }
            if (!$this->indexExists('bookings', 'bookings_customer_id_status_index')) {
                $table->index(['customer_id', 'status'], 'bookings_customer_id_status_index');
            }
            if (!$this->indexExists('bookings', 'bookings_company_id_status_index')) {
                $table->index(['company_id', 'status'], 'bookings_company_id_status_index');
            }
        });

        // driver_assignments — list by driver or vehicle with status filter
        Schema::table('driver_assignments', function (Blueprint $table) {
            if (!$this->indexExists('driver_assignments', 'da_driver_status_index')) {
                $table->index(['driver_id', 'assignment_status'], 'da_driver_status_index');
            }
            if (!$this->indexExists('driver_assignments', 'da_booking_id_index')) {
                $table->index(['booking_id'], 'da_booking_id_index');
            }
        });

        // vehicle_assignments — lookup active assignment for a vehicle
        Schema::table('vehicle_assignments', function (Blueprint $table) {
            if (!$this->indexExists('vehicle_assignments', 'va_vehicle_id_index')) {
                $table->index(['vehicle_id'], 'va_vehicle_id_index');
            }
            if (!$this->indexExists('vehicle_assignments', 'va_booking_id_index')) {
                $table->index(['booking_id'], 'va_booking_id_index');
            }
        });

        // route_points — timeline queries for a session ordered by time
        Schema::table('route_points', function (Blueprint $table) {
            if (!$this->indexExists('route_points', 'rp_session_recorded_index')) {
                $table->index(['driver_session_id', 'recorded_at'], 'rp_session_recorded_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('bookings_status_booking_date_index');
            $table->dropIndex('bookings_customer_id_status_index');
            $table->dropIndex('bookings_company_id_status_index');
        });

        Schema::table('driver_assignments', function (Blueprint $table) {
            $table->dropIndex('da_driver_status_index');
            $table->dropIndex('da_booking_id_index');
        });

        Schema::table('vehicle_assignments', function (Blueprint $table) {
            $table->dropIndex('va_vehicle_id_index');
            $table->dropIndex('va_booking_id_index');
        });

        Schema::table('route_points', function (Blueprint $table) {
            $table->dropIndex('rp_session_recorded_index');
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        return collect(\DB::select("SELECT indexname FROM pg_indexes WHERE tablename = ? AND indexname = ?", [$table, $indexName]))->isNotEmpty();
    }
};
