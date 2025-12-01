<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Booking Price Adjustment History Table
     * Tracks which price adjustments were applied to specific bookings
     */
    public function up(): void
    {
        Schema::create('booking_price_adjustment_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('booking_id')->comment('Booking that received the adjustment');
            $table->uuid('price_adjustment_id')->comment('Price adjustment that was applied');
            $table->uuid('km_range_pricing_rule_id')->nullable()->comment('KM range rule that was applied (if any)');
            
            // Adjustment details at time of application
            $table->decimal('adjustment_amount', 10, 2)->comment('Actual adjustment amount applied');
            $table->decimal('original_amount', 10, 2)->comment('Original amount before adjustment');
            $table->decimal('final_amount', 10, 2)->comment('Final amount after adjustment');
            
            // Metadata
            $table->json('adjustment_breakdown')->nullable()
                ->comment('Detailed breakdown of how adjustment was calculated');
            $table->text('adjustment_reason')->nullable()
                ->comment('Reason for the adjustment application');
            
            // Standard tracking
            $table->uuid('applied_by_user_id')->nullable()->comment('User who applied the adjustment');
            $table->timestamp('applied_at')->comment('When the adjustment was applied');
            $table->timestamps();

            // Indexes
            $table->index(['booking_id']);
            $table->index(['price_adjustment_id']);
            $table->index(['km_range_pricing_rule_id']);
            $table->index(['applied_at']);

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('booking_price_adjustment_history');
    }
};