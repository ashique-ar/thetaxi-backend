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
        Schema::table('vehicle_addons', function (Blueprint $table) {
            // Category for grouping addons
            $table->uuid('category_id')->nullable()->after('service_type_id');
            
            // Addon classification
            $table->enum('addon_type', ['service', 'item', 'insurance', 'fee', 'discount'])
                ->default('item')->after('name');
            
            // Extended pricing options - pricing_type for frontend compatibility
            $table->enum('pricing_type', ['fixed', 'per_day', 'per_hour', 'per_km', 'percentage', 'tiered'])
                ->default('fixed')->after('addon_type');
            
            // Quantity management
            $table->enum('quantity_unit', ['pieces', 'km', 'hours', 'days', 'passengers'])
                ->default('pieces')->after('pricing_type');
            $table->boolean('allow_quantity_selection')->default(true)->after('max_qty');
            
            // Threshold-based pricing
            $table->integer('threshold_quantity')->nullable()->after('allow_quantity_selection');
            $table->decimal('threshold_price', 12, 2)->nullable()->after('threshold_quantity');
            
            // Tax configuration
            $table->boolean('is_taxable')->default(false)->after('threshold_price');
            $table->decimal('tax_rate', 5, 2)->default(0)->after('is_taxable');
            
            // Status and availability (is_active already exists from BaseModel)
            $table->boolean('is_mandatory')->default(false)->after('is_active');
            $table->boolean('is_optional')->default(true)->after('is_mandatory');
            $table->enum('availability_type', ['always', 'conditional', 'seasonal', 'service_specific'])
                ->default('always')->after('is_optional');
            $table->json('availability_conditions')->nullable()->after('availability_type');
            
            // Vehicle compatibility
            $table->json('compatible_vehicle_types')->nullable()->after('availability_conditions');
            
            // Display & Organization
            $table->integer('sort_order')->default(0)->after('compatible_vehicle_types');
            $table->string('icon')->nullable()->after('sort_order');
            $table->json('tags')->nullable()->after('icon');
            $table->text('internal_notes')->nullable()->after('tags');
            $table->json('integration_settings')->nullable()->after('internal_notes');
            
            // Index for performance
            $table->index('addon_type');
            $table->index('pricing_type');
            $table->index('availability_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vehicle_addons', function (Blueprint $table) {
            // Drop indexes first
            $table->dropIndex(['addon_type']);
            $table->dropIndex(['pricing_type']);
            $table->dropIndex(['availability_type']);
            
            $table->dropColumn([
                'category_id',
                'addon_type',
                'pricing_type',
                'quantity_unit',
                'allow_quantity_selection',
                'threshold_quantity',
                'threshold_price',
                'is_taxable',
                'tax_rate',
                'is_mandatory',
                'is_optional',
                'availability_type',
                'availability_conditions',
                'compatible_vehicle_types',
                'sort_order',
                'icon',
                'tags',
                'internal_notes',
                'integration_settings',
            ]);
        });
    }
};
