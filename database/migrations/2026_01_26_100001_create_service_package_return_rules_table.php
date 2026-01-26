<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates return trip pricing rules linked to service packages.
     * Rules define charge percentages based on day offset from outbound trip.
     *
     * Example rules:
     * - Same day return (day_offset 0-0): 50% of one-way charge
     * - Next day return (day_offset 1-1): 90% of one-way charge
     * - 2+ days later (day_offset 2-null): 100% of one-way charge
     */
    public function up(): void
    {
        Schema::create('service_package_return_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('service_package_id')->index();
            $table->uuid('vehicle_group_id')->nullable()->index()
                ->comment('Optional: vehicle-specific rules override package-level rules');

            // Day offset range (days between outbound and return trip)
            $table->unsignedTinyInteger('day_offset_min')->default(0)
                ->comment('Minimum days difference (inclusive). 0 = same day');
            $table->unsignedTinyInteger('day_offset_max')->nullable()
                ->comment('Maximum days difference (inclusive). NULL = no upper limit');

            // Pricing
            $table->decimal('charge_percentage', 5, 2)->default(100.00)
                ->comment('Percentage of one-way fare to charge for return. 50 = 50% discount');

            // Additional configuration
            $table->string('label')->nullable()
                ->comment('Display label, e.g., "Same Day Return", "Next Day Return"');
            $table->text('description')->nullable();

            // Constraints
            $table->boolean('same_vehicle_required')->default(false)
                ->comment('If true, return trip must use same vehicle');
            $table->boolean('same_driver_required')->default(false)
                ->comment('If true, return trip must use same driver');
            $table->unsignedInteger('min_wait_minutes')->nullable()
                ->comment('Minimum wait time between drop-off and return pickup');
            $table->unsignedInteger('max_wait_hours')->nullable()
                ->comment('Maximum hours between outbound and return trip');

            // Status & ordering
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('priority')->default(0)
                ->comment('Higher priority rules are evaluated first');

            // Validity period
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();

            // Audit fields
            $table->uuid('created_user_id')->nullable();
            $table->uuid('updated_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes for efficient lookups
            $table->index(['service_package_id', 'is_active', 'priority'], 'idx_package_active_priority');
            $table->index(['vehicle_group_id', 'is_active'], 'idx_vehicle_group_active');
            $table->index(['day_offset_min', 'day_offset_max'], 'idx_day_offset_range');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_package_return_rules');
    }
};
