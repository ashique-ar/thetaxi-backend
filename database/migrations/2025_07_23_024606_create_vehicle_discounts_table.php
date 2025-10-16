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
        Schema::dropIfExists('vehicle_discounts');
        Schema::create('vehicle_discounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 50)->unique()->index();
            $table->string('name', 255);
            $table->text('description')->nullable();
            
            // Scope definition - determines precedence
            $table->uuid('service_type_id')->nullable()->index();
            $table->uuid('vehicle_group_id')->nullable()->index(); // Changed from vehicle_id for consistency
            
            // Discount configuration
            $table->decimal('amount', 10, 2); // Base discount value
            $table->boolean('is_percentage')->default(true);
            $table->enum('applies_to', ['subtotal', 'total', 'addons'])->default('subtotal');
            
            // Validity period
            $table->datetime('valid_from')->nullable();
            $table->datetime('valid_to')->nullable();
            $table->boolean('is_active')->default(true)->index();
            
            // Additional configuration
            $table->decimal('minimum_amount', 10, 2)->nullable(); // Minimum order amount to apply
            $table->decimal('maximum_discount', 10, 2)->nullable(); // Maximum discount cap
            $table->integer('usage_limit')->nullable(); // Total usage limit
            $table->integer('usage_count')->default(0); // Current usage count
            
            // Audit fields
            $table->uuid('created_user_id')->nullable();
            $table->uuid('updated_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes for performance
            $table->index(['service_type_id', 'vehicle_group_id']);
            $table->index(['valid_from', 'valid_to']);
            $table->index(['is_active', 'valid_from', 'valid_to']);
            
            // Ensure logical data consistency
            $table->index(['code', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicle_discounts');
    }
};
