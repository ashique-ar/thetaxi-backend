<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Adds mobile app related fields to the drivers table for tracking
     * online status, location, and device information.
     */
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            // Online status flag
            $table->boolean('is_online')->default(false)->after('default_vehicle_id');
            
            // Last activity timestamp for heartbeat tracking
            $table->timestamp('last_active_at')->nullable()->after('is_online');
            
            // Current GPS coordinates
            $table->decimal('current_latitude', 10, 8)->nullable()->after('last_active_at');
            $table->decimal('current_longitude', 11, 8)->nullable()->after('current_latitude');
            
            // Device identifier for the mobile app
            $table->string('current_device_uuid')->nullable()->after('current_longitude');
            
            // Indexes for common queries
            $table->index('is_online');
            $table->index('last_active_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->dropIndex(['is_online']);
            $table->dropIndex(['last_active_at']);
            
            $table->dropColumn([
                'is_online',
                'last_active_at',
                'current_latitude',
                'current_longitude',
                'current_device_uuid',
            ]);
        });
    }
};
