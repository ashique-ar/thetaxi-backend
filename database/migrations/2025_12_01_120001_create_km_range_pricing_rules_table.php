<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * KM-Range Pricing Rules Table
     * Allows setting different pricing based on distance ranges
     * Can be applied globally, per service, or per vehicle group
     */
    public function up(): void
    {
        Schema::create('km_range_pricing_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->comment('Descriptive name for the rule');
            $table->text('description')->nullable()->comment('Detailed description');
            
            // Scope configuration
            $table->enum('scope', ['global', 'service', 'vehicle_group'])
                ->comment('Rule application scope: global for all, service-specific, or vehicle_group specific');
            $table->uuid('service_type_id')->nullable()->comment('Service type ID when scope is service');
            $table->uuid('vehicle_group_id')->nullable()->comment('Vehicle group ID when scope is vehicle_group');
            
            // KM Range Configuration
            $table->decimal('from_km', 10, 2)->comment('Starting KM range (inclusive)');
            $table->decimal('to_km', 10, 2)->nullable()->comment('Ending KM range (inclusive). NULL means unlimited');
            
            // Pricing Configuration
            $table->enum('price_type', ['fixed_rate', 'percentage_multiplier', 'flat_addition'])
                ->comment('How the price adjustment is applied');
            $table->decimal('rate_per_km', 10, 4)->nullable()
                ->comment('Rate per KM when price_type is fixed_rate');
            $table->decimal('percentage', 8, 4)->nullable()
                ->comment('Percentage multiplier when price_type is percentage_multiplier (e.g., 1.2 = 20% increase)');
            $table->decimal('flat_amount', 10, 2)->nullable()
                ->comment('Flat amount to add when price_type is flat_addition');
            
            // Status and Priority
            $table->boolean('is_active')->default(true);
            $table->integer('priority')->default(0)->comment('Higher priority rules take precedence');
            
            // Effective dates
            $table->timestamp('effective_from')->nullable()->comment('Rule becomes effective from this date');
            $table->timestamp('effective_to')->nullable()->comment('Rule expires after this date');
            
            // Standard tracking
            $table->uuid('created_user_id')->nullable();
            $table->uuid('updated_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['scope', 'is_active']);
            $table->index(['service_type_id', 'is_active']);
            $table->index(['vehicle_group_id', 'is_active']);
            $table->index(['from_km', 'to_km']);
            $table->index(['effective_from', 'effective_to']);
            $table->index(['priority', 'is_active']);

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('km_range_pricing_rules');
    }
};