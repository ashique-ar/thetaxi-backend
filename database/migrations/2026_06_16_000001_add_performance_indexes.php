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
            if ($this->hasColumns('bookings', ['status', 'booking_date']) && !$this->indexExists('bookings', 'bookings_status_booking_date_index')) {
                $table->index(['status', 'booking_date'], 'bookings_status_booking_date_index');
            }
            if ($this->hasColumns('bookings', ['customer_id', 'status']) && !$this->indexExists('bookings', 'bookings_customer_id_status_index')) {
                $table->index(['customer_id', 'status'], 'bookings_customer_id_status_index');
            }
            if ($this->hasColumns('bookings', ['corporate_account_id', 'status']) && !$this->indexExists('bookings', 'bookings_corporate_account_id_status_index')) {
                $table->index(['corporate_account_id', 'status'], 'bookings_corporate_account_id_status_index');
            }
        });

        // driver_assignments — list by driver or vehicle with status filter
        Schema::table('driver_assignments', function (Blueprint $table) {
            if ($this->hasColumns('driver_assignments', ['driver_id', 'assignment_status']) && !$this->indexExists('driver_assignments', 'da_driver_status_index')) {
                $table->index(['driver_id', 'assignment_status'], 'da_driver_status_index');
            }
            if ($this->hasColumns('driver_assignments', ['booking_id']) && !$this->indexExists('driver_assignments', 'da_booking_id_index')) {
                $table->index(['booking_id'], 'da_booking_id_index');
            }
        });

        // vehicle_assignments — lookup active assignment for a vehicle
        Schema::table('vehicle_assignments', function (Blueprint $table) {
            if ($this->hasColumns('vehicle_assignments', ['vehicle_id']) && !$this->indexExists('vehicle_assignments', 'va_vehicle_id_index')) {
                $table->index(['vehicle_id'], 'va_vehicle_id_index');
            }
            if ($this->hasColumns('vehicle_assignments', ['booking_id']) && !$this->indexExists('vehicle_assignments', 'va_booking_id_index')) {
                $table->index(['booking_id'], 'va_booking_id_index');
            }
        });

        // route_points — timeline queries for a session ordered by time
        Schema::table('route_points', function (Blueprint $table) {
            if ($this->hasColumns('route_points', ['driver_session_id', 'recorded_at']) && !$this->indexExists('route_points', 'rp_session_recorded_index')) {
                $table->index(['driver_session_id', 'recorded_at'], 'rp_session_recorded_index');
            }
        });
    }

    public function down(): void
    {
        foreach ([
            'bookings_status_booking_date_index',
            'bookings_customer_id_status_index',
            'bookings_corporate_account_id_status_index',
            'da_driver_status_index',
            'da_booking_id_index',
            'va_vehicle_id_index',
            'va_booking_id_index',
            'rp_session_recorded_index',
        ] as $indexName) {
            \DB::statement(sprintf('DROP INDEX IF EXISTS "%s"', $indexName));
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        return Schema::hasIndex($table, $indexName);
    }

    private function hasColumns(string $table, array $columns): bool
    {
        foreach ($columns as $column) {
            if (!Schema::hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }
};
