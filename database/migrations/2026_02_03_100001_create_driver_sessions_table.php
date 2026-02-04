<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Creates the driver_sessions table to track driver online periods
     * including start/end times, locations, and device information.
     */
    public function up(): void
    {
        Schema::create('driver_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('driver_id')->constrained('drivers')->onDelete('cascade');
            $table->string('device_uuid');
            $table->string('status')->default('active'); // active, completed, auto_closed
            $table->timestamp('start_time');
            $table->timestamp('end_time')->nullable();
            
            // Start location coordinates
            $table->decimal('start_latitude', 10, 8)->nullable();
            $table->decimal('start_longitude', 11, 8)->nullable();
            
            // End location coordinates
            $table->decimal('end_latitude', 10, 8)->nullable();
            $table->decimal('end_longitude', 11, 8)->nullable();
            
            // Distance traveled during session
            $table->decimal('total_distance_km', 10, 2)->nullable();
            
            // Optional link to assignment (for future use)
            $table->uuid('assignment_id')->nullable();
            
            // Additional metadata as JSON
            $table->json('metadata')->nullable();
            
            $table->uuid('created_user_id')->nullable();
            $table->uuid('updated_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            // Composite indexes for common queries
            $table->index(['driver_id', 'status']);
            $table->index(['driver_id', 'start_time']);
            $table->index('device_uuid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('driver_sessions');
    }
};
