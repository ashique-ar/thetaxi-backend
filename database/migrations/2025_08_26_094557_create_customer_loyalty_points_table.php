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
        Schema::create('customer_loyalty_points', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('customer_id');
            $table->integer('total_points')->default(0);
            $table->integer('available_points')->default(0);
            $table->integer('pending_points')->default(0);
            $table->integer('redeemed_points')->default(0);
            $table->integer('expired_points')->default(0);
            
            // Tier management
            $table->string('current_tier')->default('bronze'); // bronze, silver, gold, platinum
            $table->integer('tier_progress_points')->default(0);
            $table->string('next_tier')->nullable();
            $table->integer('points_to_next_tier')->default(0);
            
            // Point earning rates based on tier
            $table->decimal('earning_rate_multiplier', 3, 2)->default(1.00);
            $table->decimal('redemption_rate_multiplier', 3, 2)->default(1.00);
            
            // Statistics
            $table->integer('total_bookings')->default(0);
            $table->decimal('total_spent', 12, 2)->default(0);
            $table->date('last_activity_date')->nullable();
            $table->date('tier_upgrade_date')->nullable();
            $table->date('tier_downgrade_date')->nullable();
            
            // Special status
            $table->boolean('is_vip')->default(false);
            $table->json('special_privileges')->nullable();
            $table->text('notes')->nullable();
            
            $table->uuid('created_user_id')->nullable();
            $table->uuid('updated_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index(['customer_id', 'current_tier']);
            $table->index(['available_points', 'current_tier']);
            $table->index('last_activity_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_loyalty_points');
    }
};
