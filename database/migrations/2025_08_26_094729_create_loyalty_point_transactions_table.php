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
        Schema::create('loyalty_point_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('customer_id');
            $table->uuid('booking_id')->nullable(); // null for non-booking related transactions
            $table->uuid('discount_id')->nullable(); // Reference to booking_discounts for redemptions
            
            // Transaction details
            $table->enum('type', ['earned', 'redeemed', 'expired', 'adjusted', 'bonus', 'refunded']);
            $table->integer('points'); // Positive for earned/bonus, negative for redeemed/expired
            $table->integer('balance_before');
            $table->integer('balance_after');
            
            // Earning details
            $table->decimal('amount_spent', 12, 2)->nullable(); // For earned points
            $table->decimal('earning_rate', 8, 4)->nullable(); // Points per LKR spent
            $table->string('earning_reason')->nullable();
            
            // Redemption details
            $table->decimal('redemption_value', 10, 2)->nullable(); // LKR value of redeemed points
            $table->decimal('redemption_rate', 8, 4)->nullable(); // LKR per point redeemed
            $table->string('redemption_reason')->nullable();
            
            // Expiry details
            $table->date('expires_at')->nullable(); // When these points expire
            $table->date('expired_at')->nullable(); // When these points actually expired
            
            // Additional metadata
            $table->string('reference_number')->unique(); // Unique transaction reference
            $table->json('metadata')->nullable(); // Additional data (tier multipliers, etc.)
            $table->text('description');
            $table->text('internal_notes')->nullable();
            
            // Tracking
            $table->uuid('processed_by')->nullable(); // Staff member who processed
            $table->datetime('processed_at');
            $table->enum('status', ['pending', 'completed', 'cancelled', 'failed'])->default('completed');
            
            $table->uuid('created_user_id')->nullable();
            $table->uuid('updated_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index(['customer_id', 'type', 'created_at']);
            $table->index(['booking_id', 'type']);
            $table->index(['expires_at', 'status']);
            $table->index('reference_number');            
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loyalty_point_transactions');
    }
};
