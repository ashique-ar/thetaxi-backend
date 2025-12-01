<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Price Adjustments Table
     * Manages seasonal and promotional pricing adjustments
     * Can be applied globally, per service, or per vehicle group
     */
    public function up(): void
    {
        Schema::create('price_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->comment('Adjustment name (e.g., "Summer Season 2024", "Holiday Discount")');
            $table->text('description')->nullable()->comment('Detailed description of the adjustment');
            
            // Scope configuration
            $table->enum('scope', ['global', 'service', 'vehicle_group'])
                ->comment('Adjustment application scope');
            $table->uuid('service_type_id')->nullable()->comment('Service type ID when scope is service');
            $table->uuid('vehicle_group_id')->nullable()->comment('Vehicle group ID when scope is vehicle_group');
            
            // Adjustment type and value
            $table->enum('adjustment_type', ['percentage', 'fixed_amount'])
                ->comment('Type of adjustment: percentage or fixed amount');
            $table->decimal('percentage_change', 8, 4)->nullable()
                ->comment('Percentage change (e.g., 0.15 = 15% increase, -0.10 = 10% decrease)');
            $table->decimal('fixed_amount_change', 10, 2)->nullable()
                ->comment('Fixed amount change (positive for increase, negative for decrease)');
            
            // Application rules
            $table->enum('applies_to', ['base_price', 'total_price', 'km_charges'])
                ->default('total_price')
                ->comment('What component of pricing this adjustment applies to');
            $table->decimal('minimum_booking_amount', 10, 2)->nullable()
                ->comment('Minimum booking amount for adjustment to apply');
            $table->decimal('maximum_discount_amount', 10, 2)->nullable()
                ->comment('Maximum discount amount (for negative adjustments)');
            
            // Status and Priority
            $table->boolean('is_active')->default(true);
            $table->integer('priority')->default(0)->comment('Higher priority adjustments take precedence');
            $table->boolean('is_cumulative')->default(false)
                ->comment('Whether this adjustment can be combined with others');
            
            // Effective period
            $table->timestamp('valid_from')->comment('Adjustment becomes valid from this date/time');
            $table->timestamp('valid_to')->comment('Adjustment expires after this date/time');
            
            // Usage limits
            $table->integer('usage_limit')->nullable()
                ->comment('Maximum number of times this adjustment can be used (NULL = unlimited)');
            $table->integer('usage_count')->default(0)
                ->comment('Current usage count');
            
            // Standard tracking
            $table->uuid('created_user_id')->nullable();
            $table->uuid('updated_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['scope', 'is_active']);
            $table->index(['service_type_id', 'is_active']);
            $table->index(['vehicle_group_id', 'is_active']);
            $table->index(['valid_from', 'valid_to']);
            $table->index(['priority', 'is_active']);
            $table->index(['adjustment_type', 'is_active']);
            $table->index(['applies_to', 'is_active']);

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('price_adjustments');
    }
};