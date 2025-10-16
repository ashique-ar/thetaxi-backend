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
        Schema::create('booking_qcs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('booking_id');
            $table->uuid('vehicle_id');
            $table->uuid('dispatch_id')->nullable();
            $table->enum('qc_status', ['pending', 'in_progress', 'completed', 'issues_found', 'repair_required'])->default('pending');
            
            // Inspector details
            $table->uuid('inspector_id')->nullable();
            $table->timestamp('inspection_started_at')->nullable();
            $table->timestamp('inspection_completed_at')->nullable();
            
            // Condition assessments
            $table->json('interior_condition')->nullable(); // Checklist data
            $table->json('exterior_condition')->nullable();
            $table->json('mechanical_condition')->nullable();
            $table->integer('cleanliness_rating')->nullable(); // 1-5 scale
            
            // Vehicle state
            $table->decimal('fuel_level', 3, 1)->nullable();
            $table->integer('mileage')->nullable();
            
            // Issues and damages
            $table->json('damages_found')->nullable();
            $table->json('issues_reported')->nullable();
            $table->boolean('repair_required')->default(false);
            $table->decimal('estimated_repair_cost', 10, 2)->nullable();
            $table->text('repair_notes')->nullable();
            $table->text('qc_notes')->nullable();
            
            // Documentation
            $table->json('photos')->nullable(); // Array of photo URLs/paths
            $table->boolean('passed_inspection')->default(false);
            
            // Maintenance tracking
            $table->boolean('requires_maintenance')->default(false);
            $table->date('next_maintenance_due')->nullable();
            
            $table->uuid('created_user_id')->nullable();
            $table->uuid('updated_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            

            // Indexes
            $table->index(['booking_id']);
            $table->index(['vehicle_id']);
            $table->index(['qc_status']);
            $table->index(['inspector_id']);
            $table->index(['inspection_started_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('booking_qcs');
    }
};
