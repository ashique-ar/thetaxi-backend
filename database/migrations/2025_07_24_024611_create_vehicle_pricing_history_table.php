<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('vehicle_pricing_history');
        Schema::create('vehicle_pricing_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('vehicle_group_id');
            $table->uuid('service_type_id')->nullable();
            $table->uuid('pricing_slab_definition_id')->nullable();
            
            $table->uuid('common_rate_definition_id')->nullable();
            
            $table->enum('record_type', ['slab_pricing', 'common_rate_pricing'])->default('slab_pricing');
            
            
            $table->decimal('old_rate',      10, 2);
            $table->decimal('new_rate',      10, 2);
            $table->decimal('rate_change',   10, 2);
            $table->decimal('percentage_change', 10, 2);
            $table->enum('change_type', ['increase', 'decrease', 'no_change']);
            $table->text('change_reason')->nullable();
            $table->json('old_pricing_data')->nullable(); // Store complete old pricing structure
            $table->json('new_pricing_data')->nullable(); // Store complete new pricing structure
            $table->uuid('changed_by')->nullable(); // User who made the change
            $table->timestamp('changed_at');
            $table->uuid('created_user_id')->nullable()->index();
            $table->uuid('updated_user_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            // Indexes for better performance
            $table->index(['vehicle_group_id', 'service_type_id']);
            $table->index(['changed_at']);
            $table->index(['change_type']);
            $table->index(['changed_by']);
            $table->index(['common_rate_definition_id']);
            $table->index(['record_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicle_pricing_history');
    }
};
