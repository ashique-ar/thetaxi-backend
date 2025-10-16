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
        Schema::create('loyalty_tiers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->unique();
            $table->string('display_name');
            $table->text('description')->nullable();
            $table->string('color_code')->nullable();
            $table->string('icon')->nullable();

            // Tier requirements
            $table->integer('min_points')->default(0);
            $table->integer('max_points')->nullable(); // null for highest tier
            $table->integer('min_bookings')->default(0);
            $table->decimal('min_total_spent', 12, 2)->default(0);
            $table->integer('months_to_maintain')->default(12); // How long to stay in tier

            // Benefits and multipliers
            $table->decimal('points_earning_multiplier', 3, 2)->default(1.00);
            $table->decimal('points_redemption_multiplier', 3, 2)->default(1.00);
            $table->decimal('discount_multiplier', 3, 2)->default(1.00);
            $table->integer('bonus_points_on_upgrade')->default(0);

            // Special privileges
            $table->json('privileges')->nullable(); // Array of privilege codes
            $table->json('exclusive_discounts')->nullable();
            $table->boolean('priority_booking')->default(false);
            $table->boolean('free_cancellation')->default(false);
            $table->boolean('priority_support')->default(false);

            // Configuration
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->boolean('auto_upgrade')->default(true);
            $table->boolean('auto_downgrade')->default(true);

            // Downgrade prevention
            $table->integer('grace_period_months')->default(3); // Grace period before downgrade
            $table->integer('min_points_to_maintain')->nullable(); // Different from min_points

            $table->uuid('created_user_id')->nullable();
            $table->uuid('updated_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['min_points', 'max_points']);
            $table->index(['is_active', 'sort_order']);
            $table->index('is_default');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loyalty_tiers');
    }
};
