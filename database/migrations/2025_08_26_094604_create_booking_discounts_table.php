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
        Schema::create('booking_discounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('booking_id');
 
            // Discount details
            $table->string('discount_name');
            $table->text('description')->nullable();
            $table->enum('type', ['percentage', 'fixed_amount', 'loyalty_points']);
            $table->decimal('value', 10, 2); // Percentage or amount value
            $table->decimal('discount_amount', 10, 2); // Actual discount amount applied
            $table->decimal('original_amount', 10, 2); // Amount before discount
            $table->decimal('final_amount', 10, 2); // Amount after discount
            
            // Loyalty/gamification specific
            $table->integer('loyalty_points_used')->nullable();
            $table->decimal('points_to_amount_rate', 8, 4)->nullable(); // How many LKR per point
            
            // Application details
            $table->enum('application_method', ['automatic', 'manual', 'code', 'loyalty_redemption']);
            $table->string('discount_code')->nullable();
            $table->uuid('applied_by'); // User who applied the discount
            $table->datetime('applied_at');
            
            // Approval workflow
            $table->boolean('requires_approval')->default(false);
            $table->enum('approval_status', ['pending', 'approved', 'rejected', 'not_required'])->default('not_required');
            $table->uuid('approved_by')->nullable();
            $table->datetime('approved_at')->nullable();
            $table->text('approval_notes')->nullable();
            
            // Validity and restrictions
            $table->datetime('valid_from')->nullable();
            $table->datetime('valid_until')->nullable();
            $table->boolean('is_active')->default(true);
            
            // Metadata
            $table->json('conditions_met')->nullable(); // Which conditions were satisfied
            $table->json('calculation_details')->nullable(); // How the discount was calculated
            $table->text('internal_notes')->nullable();
            
            $table->uuid('created_user_id')->nullable();
            $table->uuid('updated_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index(['booking_id', 'is_active']);
            $table->index(['type', 'application_method']);
            $table->index('approval_status');           
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('booking_discounts');
    }
};
