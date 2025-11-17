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
        Schema::dropIfExists('carts');
        Schema::create('carts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('customer_id')->nullable();
            $table->uuid('user_id')->nullable();
            $table->json('items')->default('[]'); // Stores cart items
            $table->json('totals')->nullable(); // Stores subtotal, tax, discount, total
            $table->string('coupon_code')->nullable();
            $table->decimal('coupon_discount', 10, 2)->default(0);
            $table->string('status')->default('active'); // active, abandoned, checked_out
            $table->string('session_id')->nullable(); // For guest tracking
            $table->timestamp('last_activity_at')->useCurrent()->useCurrentOnUpdate();
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index('customer_id');
            $table->index('user_id');
            $table->index('session_id');
            $table->index('status');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('carts');
    }
};
