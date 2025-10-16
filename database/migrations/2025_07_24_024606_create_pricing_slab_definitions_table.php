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
        Schema::dropIfExists('vehicle_pricing_slab_definitions');
        Schema::create('vehicle_pricing_slab_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('service_type_id');
            $table->string('name');
            $table->string('type')->nullable(); 
            $table->integer('min_hours')->nullable();
            $table->integer('max_hours')->nullable();
            $table->integer('min_days')->nullable(); 
            $table->integer('max_days')->nullable();
            $table->integer('sort_order')->default(1);
            $table->boolean('is_active')->default(true);
            $table->uuid('created_user_id')->nullable()->index();
            $table->uuid('updated_user_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['service_type_id', 'is_active']);
            $table->index('sort_order');
            
            // Unique constraint to prevent duplicate slab names per service type
            $table->unique(['service_type_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicle_pricing_slab_definitions');
    }
};
