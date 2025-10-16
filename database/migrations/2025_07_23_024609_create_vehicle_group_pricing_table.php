<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('vehicle_group_pricing');
        Schema::create('vehicle_group_pricing', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('slab_definition_id');
            $table->uuid('vehicle_group_id')->nullable();
            $table->decimal('rate', 10, 2)->nullable(); // Base rate for this group/slab combination
            $table->enum('rate_type', ['per_hour', 'per_day', 'flat_rate'])->default('per_day');
            $table->decimal('minimum_charge', 10, 2)->nullable(); // Minimum charge regardless of duration
            $table->boolean('includes_fuel')->default(false);
            $table->boolean('includes_driver')->default(false);
            $table->boolean('is_active')->default(true);
            $table->uuid('created_user_id')->nullable()->index();
            $table->uuid('updated_user_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['slab_definition_id', 'vehicle_group_id']);
            $table->index(['vehicle_group_id', 'is_active']);
            $table->index('is_active');

            // Unique constraint to prevent duplicate rates per group/slab
            $table->unique(['slab_definition_id', 'vehicle_group_id'], 'unique_group_slab_pricing');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicle_group_pricing');
    }
};
