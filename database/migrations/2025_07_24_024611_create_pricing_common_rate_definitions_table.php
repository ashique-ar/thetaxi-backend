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
        Schema::dropIfExists('vehicle_pricing_common_rate_definitions');
        Schema::create('vehicle_pricing_common_rate_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 100);
            $table->string('code', 100)->nullable();
            $table->uuid('service_type_id')->nullable()->index();
            $table->uuid('vehicle_group_id')->nullable()->index();
            $table->text('description')->nullable();
            $table->string('common_rate_type')->default('fixed_amount');
            $table->boolean('is_mandatory')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->uuid('created_user_id')->nullable()->index();
            $table->uuid('updated_user_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['is_active', 'sort_order']);
            $table->index('common_rate_type');
            $table->index('is_mandatory');

            // Unique name constraint
            $table->unique(['name','service_type_id'], 'unique_common_rate_definition_name');
            $table->unique(['code','service_type_id'], 'unique_common_rate_definition_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicle_pricing_common_rate_definitions');
    }
};
