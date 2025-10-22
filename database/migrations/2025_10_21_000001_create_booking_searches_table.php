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
        Schema::dropIfExists('booking_searches');
        Schema::create('booking_searches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('session_id')->index();
            $table->string('service_type')->index(); // airport-transfer, drop-pickup, rental-packages, custom-tour
            $table->json('search_data'); // Complete search form data

            // Core search parameters
            $table->dateTime('pickup_date')->index();
            $table->dateTime('dropoff_date')->nullable()->index();
            $table->string('pickup_location')->nullable();
            $table->string('dropoff_location')->nullable();

            // Calculated fields
            $table->decimal('estimated_distance', 10, 2)->nullable();
            $table->integer('duration_hours')->nullable();
            $table->integer('duration_days')->nullable();

            // User tracking
            $table->uuid('customer_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();

            $table->timestamps();
            $table->softDeletes();
            // Indexes for performance
            $table->index(['session_id', 'service_type']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('booking_searches');
    }
};
