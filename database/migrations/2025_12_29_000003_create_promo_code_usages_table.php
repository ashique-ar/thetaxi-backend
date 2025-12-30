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
        Schema::create('promo_code_usages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('promo_code_id');
            $table->uuid('customer_id')->nullable();
            $table->uuid('booking_id')->nullable();
            $table->decimal('discount_amount', 10, 2);
            $table->decimal('order_amount', 10, 2);
            $table->timestamp('used_at')->useCurrent();
            $table->timestamps();

            // Foreign key constraints
            $table->foreign('promo_code_id')
                ->references('id')
                ->on('promo_codes')
                ->onDelete('cascade');
            
            $table->foreign('customer_id')
                ->references('id')
                ->on('customers')
                ->onDelete('set null');
            
            $table->foreign('booking_id')
                ->references('id')
                ->on('bookings')
                ->onDelete('set null');

            // Indexes for performance
            $table->index('promo_code_id', 'idx_promo_code_usages_code');
            $table->index('customer_id', 'idx_promo_code_usages_customer');
            $table->index('booking_id', 'idx_promo_code_usages_booking');
            $table->index('used_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promo_code_usages');
    }
};
