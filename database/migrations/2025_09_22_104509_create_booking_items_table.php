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
        Schema::create('booking_items', function (Blueprint $table) {
            $table->id();
            
            // Foreign key to main booking
            $table->uuid('booking_id');
  
            // Vehicle group and vehicle information
            $table->uuid('vehicle_group_id');
            $table->uuid('vehicle_id')->nullable(); // Specific vehicle if assigned
     
            // Driver assignment
            $table->uuid('driver_id')->nullable();
            
            // Quantity and pricing for this group
            $table->integer('quantity')->default(1);
            $table->decimal('unit_price', 12, 2)->default(0); // Price per unit for this group
            $table->decimal('total_price', 12, 2)->default(0); // Total price for this item (unit_price * quantity)
            
            // Pricing breakdown (JSON)
            $table->json('pricing_breakdown')->nullable(); // Stores detailed pricing calculation
            $table->json('addons')->nullable(); // Group-specific addons
            $table->json('customizations')->nullable(); // Group-specific customizations
            $table->json('discounts')->nullable(); // Group-specific discounts
            
            // Dates and duration
            $table->datetime('from_date');
            $table->datetime('to_date');
            $table->integer('duration_days')->default(0);
            $table->integer('duration_hours')->default(0);
            
            // Currency and exchange rate at time of booking
            $table->string('currency', 3)->default('LKR');
            $table->decimal('exchange_rate', 10, 6)->default(1.000000);
            
            // Status and approval
            $table->enum('status', ['pending', 'confirmed', 'cancelled', 'completed'])->default('pending');
            $table->boolean('requires_approval')->default(false);
            $table->timestamp('approved_at')->nullable();
            $table->uuid('approved_by')->nullable();
            
            // Metadata
            $table->string('item_type')->default('vehicle_group'); // For future extensibility
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable(); // Additional item-specific data
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes for performance
            $table->index(['booking_id', 'vehicle_group_id']);
            $table->index(['booking_id', 'status']);
            $table->index(['vehicle_group_id', 'from_date', 'to_date']);
            $table->index(['driver_id', 'from_date', 'to_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('booking_items');
    }
};
