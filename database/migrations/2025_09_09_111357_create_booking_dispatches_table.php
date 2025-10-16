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
        Schema::create('booking_dispatches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('booking_id');
            $table->uuid('vehicle_id');
            $table->uuid('driver_id')->nullable();
            $table->enum('dispatch_status', ['not_dispatched', 'ready_for_dispatch', 'dispatched', 'in_progress', 'returned'])->default('not_dispatched');
            
            // Dispatch details
            $table->timestamp('dispatched_at')->nullable();
            $table->uuid('dispatched_by')->nullable();
            $table->timestamp('expected_return_at')->nullable();
            $table->timestamp('actual_return_at')->nullable();
            $table->uuid('returned_by')->nullable();
            
            // Notes and documentation
            $table->text('dispatch_notes')->nullable();
            $table->text('return_notes')->nullable();
            
            // Vehicle condition tracking
            $table->decimal('fuel_level_out', 3, 1)->nullable(); // 0.0 to 10.0 (full tank)
            $table->decimal('fuel_level_in', 3, 1)->nullable();
            $table->integer('mileage_out')->nullable();
            $table->integer('mileage_in')->nullable();
            $table->json('vehicle_condition_out')->nullable(); // Checklist data
            $table->json('vehicle_condition_in')->nullable();
            $table->json('damages_reported')->nullable();
            
            // Financial tracking
            $table->json('additional_charges')->nullable();
            $table->decimal('late_return_fee', 10, 2)->default(0);
            
            // Documentation
            $table->json('documents_generated')->nullable(); // List of generated documents
            $table->boolean('agreements_signed')->default(false);
            $table->boolean('is_self_driven')->default(false);
            
            $table->uuid('created_user_id')->nullable();
            $table->uuid('updated_user_id')->nullable();
            $table->softDeletes();
            $table->timestamps();
            
            // Indexes
            $table->index(['booking_id']);
            $table->index(['vehicle_id']);
            $table->index(['dispatch_status']);
            $table->index(['dispatched_at']);
            $table->index(['expected_return_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('booking_dispatches');
    }
};
