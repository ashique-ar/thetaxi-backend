<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // FK: booking_discounts.booking_id → bookings.id
        Schema::table('booking_discounts', function (Blueprint $table) {
            $table->foreign('booking_id')
                ->references('id')->on('bookings')
                ->onDelete('cascade');

            // Composite index for common query pattern (filter by type + method for a booking)
            $table->index(['booking_id', 'type', 'application_method'], 'bd_booking_type_method_idx');
        });

        // FKs: booking_pricings.booking_id and slab_definition_id
        Schema::table('booking_pricings', function (Blueprint $table) {
            $table->foreign('booking_id')
                ->references('id')->on('bookings')
                ->onDelete('cascade');

            $table->foreign('slab_definition_id')
                ->references('id')->on('vehicle_pricing_slab_definitions')
                ->onDelete('restrict');

            $table->foreign('vehicle_group_pricing_id')
                ->references('id')->on('vehicle_group_pricing')
                ->onDelete('restrict');

            // Composite index for pricing lookups by vehicle group + service type
            $table->index(['booking_id', 'rate_type'], 'bp_booking_rate_type_idx');
        });

        // Composite index on bookings for date-range queries
        Schema::table('bookings', function (Blueprint $table) {
            $table->index(['from_date', 'to_date'], 'bookings_date_range_idx');
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
            $table->dropForeign(['vehicle_group_pricing_id']);
            $table->dropIndex('bp_booking_rate_type_idx');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('bookings_date_range_idx');
        });
    }
};
