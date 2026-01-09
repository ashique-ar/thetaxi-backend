<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('booking_items', function (Blueprint $table) {
            // Add denormalized names for quick display without joins
            $table->string('vehicle_group_name')->nullable()->after('vehicle_group_id');
            $table->string('service_type_name')->nullable()->after('vehicle_group_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('booking_items', function (Blueprint $table) {
            $table->dropColumn([
                'from_time',
                'to_time',
                'pickup_location',
                'dropoff_location',
                'vehicle_group_name',
                'service_type_name'
            ]);
        });
    }
};
