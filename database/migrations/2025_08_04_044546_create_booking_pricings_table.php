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
        Schema::create('booking_pricings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('booking_id');
            $table->uuid('slab_definition_id');
            $table->uuid('vehicle_group_pricing_id');
            $table->decimal('calculated_amount', 15, 2);
            $table->enum('rate_type', ['per_hour', 'per_day', 'flat_rate']);
            $table->decimal('applied_rate', 15, 2);
            $table->integer('hours_calculated');
            $table->integer('days_calculated');
            $table->boolean('minimum_charge_applied')->default(false);
            $table->boolean('includes_fuel')->default(false);
            $table->boolean('includes_driver')->default(false);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            // Indexes
            $table->index(['booking_id']);
            $table->index(['slab_definition_id']);
            $table->index(['vehicle_group_pricing_id']);
            $table->index(['rate_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('booking_pricings');
    }
};
