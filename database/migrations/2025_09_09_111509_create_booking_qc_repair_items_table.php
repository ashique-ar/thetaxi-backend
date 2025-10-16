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
        Schema::create('booking_qc_repair_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('qc_id');
            $table->string('item_type'); // 'interior', 'exterior', 'mechanical', 'electrical', etc.
            $table->text('description');
            $table->string('location')->nullable(); // Where on the vehicle
            $table->enum('severity', ['minor', 'moderate', 'major', 'critical'])->default('minor');
            
            // Cost tracking
            $table->decimal('estimated_cost', 10, 2)->nullable();
            $table->decimal('actual_cost', 10, 2)->nullable();
            
            // Repair tracking
            $table->enum('repair_status', ['pending', 'in_progress', 'completed', 'cancelled'])->default('pending');
            $table->timestamp('repaired_at')->nullable();
            $table->uuid('repaired_by')->nullable();
            $table->text('repair_notes')->nullable();
            
            // Documentation
            $table->json('photos')->nullable();
            
            $table->uuid('created_user_id')->nullable();
            $table->uuid('updated_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index(['qc_id']);
            $table->index(['repair_status']);
            $table->index(['severity']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('booking_qc_repair_items');
    }
};
