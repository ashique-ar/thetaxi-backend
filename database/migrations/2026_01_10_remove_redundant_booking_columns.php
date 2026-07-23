<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Remove redundant columns from bookings table that are now stored in booking_items.
     * These columns were used for single-item bookings but are now handled by the booking_items table
     * for multi-item bookings.
     */
    public function up(): void
    {
        // SQLite rebuilds the table for DROP COLUMN and refuses to retain an
        // index that references a removed column. PostgreSQL drops dependent
        // single-column indexes with the columns themselves.
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            foreach (['service_type_id', 'vehicle_group_id'] as $indexedColumn) {
                if (Schema::hasColumn('bookings', $indexedColumn)) {
                    Schema::table('bookings', fn (Blueprint $table) => $table->dropIndex([$indexedColumn]));
                }
            }
        }

        // pickup_location/dropoff_location were commented out in the original
        // fresh schema but existed in some deployed databases. Drop only the
        // redundant columns actually present in this database.
        foreach ([
            'from_date',
            'to_date',
            'from_time',
            'to_time',
            'service_type_id',
            'vehicle_group_id',
            'pickup_location',
            'dropoff_location',
        ] as $column) {
            if (Schema::hasColumn('bookings', $column)) {
                Schema::table('bookings', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            // Restore date/time columns
            $table->dateTime('from_date')->nullable()->after('booking_date');
            $table->dateTime('to_date')->nullable()->after('from_date');
            $table->time('from_time')->nullable()->after('to_date');
            $table->time('to_time')->nullable()->after('from_time');
            
            // Restore foreign keys
            $table->uuid('service_type_id')->index()->after('log_code');
            $table->uuid('vehicle_group_id')->index()->after('service_type_id');
            
            // Restore location columns
            $table->jsonb('pickup_location')->nullable()->after('to_time');
            $table->jsonb('dropoff_location')->nullable()->after('pickup_location');
        });
    }
};
