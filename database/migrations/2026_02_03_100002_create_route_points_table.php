<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Creates the route_points table to store GPS coordinates recorded
     * during driver sessions for route replay and distance calculation.
     */
    public function up(): void
    {
        Schema::create('route_points', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('session_id')->constrained('driver_sessions')->onDelete('cascade');
            
            // GPS coordinates
            $table->decimal('latitude', 10, 8);
            $table->decimal('longitude', 11, 8);
            
            // Additional GPS data
            $table->decimal('altitude', 8, 2)->nullable(); // meters
            $table->decimal('speed', 6, 2)->nullable(); // km/h
            $table->decimal('heading', 5, 2)->nullable(); // degrees 0-360
            $table->decimal('accuracy', 6, 2)->nullable(); // meters
            
            // Timestamp when the point was recorded
            $table->timestamp('recorded_at');
            
            // Only created_at for performance (no soft deletes)
            $table->timestamp('created_at')->useCurrent();
            
            // Indexes for querying route points
            $table->index(['session_id', 'recorded_at']);
            $table->index('recorded_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('route_points');
    }
};
