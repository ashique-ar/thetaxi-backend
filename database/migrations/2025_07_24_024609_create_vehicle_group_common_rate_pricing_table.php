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
        Schema::dropIfExists('vehicle_group_common_rate_pricing');
        Schema::create('vehicle_group_common_rate_pricing', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('vehicle_group_id');
            $table->uuid('common_rate_definition_id');
            $table->decimal('value', 10, 2)->nullable(); // Override default addon rate for this group
            // $table->enum('custom_rate_type', ['percentage', 'fixed_amount', 'per_hour', 'per_day'])->default('fixed_amount');
            // $table->boolean('is_enabled')->default(true); // Enable/disable addon for this group
            $table->boolean('is_active')->default(true);
            // $table->boolean('is_mandatory')->default(true);
            // $table->integer('sort_order')->default(true);
            $table->uuid('created_user_id')->nullable()->index();
            $table->uuid('updated_user_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['vehicle_group_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicle_group_common_rate_pricing');
    }
};
