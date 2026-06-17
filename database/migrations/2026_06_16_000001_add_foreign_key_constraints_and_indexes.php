<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // FK constraints: booking_discounts
        Schema::table('booking_discounts', function (Blueprint $table) {
            $table->foreign('booking_id')
                ->references('id')
                ->on('bookings')
                ->onDelete('cascade');

            // Composite index for fast lookup by booking + type + method
            $table->index(['booking_id', 'type', 'application_method'], 'bd_booking_type_method_idx');
        });

        // FK constraints: booking_pricings
        Schema::table('booking_pricings', function (Blueprint $table) {
            $table->foreign('booking_id')
                ->references('id')
                ->on('bookings')
                ->onDelete('cascade');

            $table->foreign('slab_definition_id')
                ->references('id')
                ->on('vehicle_pricing_slab_definitions')
                ->onDelete('restrict');
        });

        // Composite index for date-range queries on bookings (very common filter)
        Schema::table('bookings', function (Blueprint $table) {
            $table->index(['from_date', 'to_date'], 'bookings_date_range_idx');
            $table->index(['status', 'from_date'], 'bookings_status_date_idx');
        });
    }

    public function down(): void
    {
        Schema::table('booking_discounts', function (Blueprint $table) {
            $table->dropForeign(['booking_id']);
            $table->dropIndex('bd_booking_type_method_idx');
        });

        Schema::table('booking_pricings', function (Blueprint $table) {
            $table->dropForeign(['booking_id']);
            $table->dropForeign(['slab_definition_id']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('bookings_date_range_idx');
            $table->dropIndex('bookings_status_date_idx');
        });
    }
};
