<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Add fields to track return trip distances separately for better visibility
     * throughout the booking flow (search results, cart, emails, booking details)
     */
    public function up(): void
    {
        Schema::table('booking_searches', function (Blueprint $table) {
            // Return trip support
            $table->boolean('is_return_trip')->default(false)->after('estimated_distance');
            $table->decimal('outbound_distance_km', 10, 2)->nullable()->after('is_return_trip')
                ->comment('Distance for outbound journey (pickup to dropoff)');
            $table->decimal('return_distance_km', 10, 2)->nullable()->after('outbound_distance_km')
                ->comment('Distance for return journey (dropoff back to pickup)');
            $table->integer('outbound_duration_seconds')->nullable()->after('return_distance_km')
                ->comment('Duration for outbound journey in seconds');
            $table->integer('return_duration_seconds')->nullable()->after('outbound_duration_seconds')
                ->comment('Duration for return journey in seconds');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('booking_searches', function (Blueprint $table) {
            $table->dropColumn([
                'is_return_trip',
                'outbound_distance_km',
                'return_distance_km',
                'outbound_duration_seconds',
                'return_duration_seconds'
            ]);
        });
    }
};
