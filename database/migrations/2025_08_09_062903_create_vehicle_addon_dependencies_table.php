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
        Schema::create('vehicle_addon_dependencies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('parent_addon_id')->comment('The addon that requires the dependency');
            $table->uuid('required_addon_id')->comment('The required dependency addon');
            
            // Dependency configuration
            $table->enum('dependency_type', ['required', 'recommended', 'mutually_exclusive', 'upgrade_path'])->default('required');
            $table->text('description')->nullable()->comment('Human readable description of the dependency');
            $table->boolean('is_automatic')->default(false)->comment('Whether this dependency is added automatically');
            
            // Conditional dependencies
            $table->json('conditions')->nullable()->comment('Conditions under which this dependency applies');
            $table->integer('minimum_quantity')->default(1)->comment('Minimum quantity of required addon');
            $table->integer('maximum_quantity')->nullable()->comment('Maximum quantity of required addon');
            
            // Pricing impact
            $table->decimal('discount_percentage', 5, 2)->nullable()->comment('Discount when both addons are selected');
            $table->decimal('discount_amount', 10, 2)->nullable()->comment('Fixed discount amount');
            
            // Enhanced categorization
            $table->string('category')->nullable()->comment('Category for grouping addons');
            $table->string('subcategory')->nullable()->comment('Subcategory for detailed grouping');
            $table->integer('display_order')->default(0)->comment('Order for display in UI');
            
            // Addon characteristics
            $table->boolean('is_featured')->default(false)->comment('Whether to highlight this addon');
            $table->boolean('is_premium')->default(false)->comment('Premium addon requiring special handling');
            $table->boolean('requires_approval')->default(false)->comment('Requires admin approval');
            $table->boolean('affects_vehicle_selection')->default(false)->comment('Whether this addon affects available vehicles');
            
            // Booking constraints
            $table->integer('minimum_hours')->nullable()->comment('Minimum booking duration required');
            $table->integer('maximum_hours')->nullable()->comment('Maximum booking duration allowed');
            $table->json('time_restrictions')->nullable()->comment('Time-based restrictions');
            $table->json('date_restrictions')->nullable()->comment('Date-based restrictions');
            
            // Pricing enhancements
            $table->enum('pricing_type', ['fixed', 'hourly', 'daily', 'per_km', 'percentage'])->default('fixed');
            $table->decimal('base_price', 10, 2)->default(0)->comment('Base price for the addon');
            $table->decimal('hourly_rate', 8, 2)->nullable()->comment('Hourly rate if applicable');
            $table->decimal('daily_rate', 10, 2)->nullable()->comment('Daily rate if applicable');
            $table->decimal('per_km_rate', 8, 2)->nullable()->comment('Per kilometer rate if applicable');
            $table->decimal('percentage_rate', 5, 2)->nullable()->comment('Percentage of base booking cost');
            
            // Quantity and availability
            $table->integer('available_quantity')->nullable()->comment('Available quantity in inventory');
            $table->boolean('unlimited_quantity')->default(true)->comment('Whether quantity is unlimited');
            
            // UI and display
            $table->text('short_description')->nullable()->comment('Brief description for cards');
            $table->json('features')->nullable()->comment('List of features/benefits');
            $table->string('icon')->nullable()->comment('Icon class or URL');
            $table->string('image_url')->nullable()->comment('Image URL for the addon');
            $table->json('metadata')->nullable()->comment('Additional metadata');
            
            // Indexing for performance
            $table->index(['category', 'subcategory']);
            $table->index(['is_featured']);
            $table->index(['requires_approval']);
            $table->index(['display_order']);
            $table->index(['pricing_type']);
            
            $table->boolean('is_active')->default(true);
            $table->uuid('created_user_id')->nullable();
            $table->uuid('updated_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            // Unique constraint to prevent duplicate dependencies
            $table->unique(['parent_addon_id', 'required_addon_id']);
            
            // Indexes
            $table->index(['parent_addon_id']);
            $table->index(['required_addon_id']);
            $table->index(['dependency_type']);
            $table->index(['is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('addon_dependencies');
    }
};
