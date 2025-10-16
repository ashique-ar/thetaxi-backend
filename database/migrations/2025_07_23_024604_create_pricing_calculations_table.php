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
        Schema::dropIfExists('vehicle_pricing_calculations');
        Schema::create('vehicle_pricing_calculations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('booking_id'); // Reference to the booking
            $table->uuid('slab_definition_id');
            $table->uuid('vehicle_group_id');
            $table->integer('hours_used')->default(0)->nullable(); // Actual hours booked
            $table->decimal('base_rate', 10, 2)->nullable(); // Rate from vehicle_group_pricing
            $table->decimal('base_amount', 10, 2)->nullable(); // Calculated base amount
            $table->json('addons_applied')->nullable(); // JSON array of applied addons with amounts
            $table->decimal('addons_total', 10, 2)->default(0); // Total of all addons
            $table->decimal('discount_percentage', 5, 2)->default(0); // Applied discount percentage
            $table->decimal('discount_amount', 10, 2)->default(0); // Calculated discount amount
            $table->decimal('subtotal', 10, 2)->nullable(); // Before tax amount
            $table->decimal('tax_percentage', 5, 2)->default(0); // Tax percentage applied
            $table->decimal('tax_amount', 10, 2)->default(0); // Calculated tax amount
            $table->decimal('total_amount', 10, 2)->nullable(); // Final total amount
            $table->text('calculation_notes')->nullable(); // Any notes about the calculation
            $table->boolean('is_active')->default(true);
            $table->uuid('created_user_id')->nullable()->index();
            $table->uuid('updated_user_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('booking_id');
            $table->index(['slab_definition_id', 'vehicle_group_id']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicle_pricing_calculations');
    }
};
