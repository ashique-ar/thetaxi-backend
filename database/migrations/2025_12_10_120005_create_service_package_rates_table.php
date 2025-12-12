<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
    * Run the migrations.
    *
    * Links packages to vehicle groups with base rates and optional extra km rate.
    */
    public function up(): void
    {
        Schema::create('service_package_rates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('service_package_id')->index();
            $table->uuid('vehicle_group_id')->index();
            $table->decimal('base_rate', 10, 2)->nullable();
            $table->enum('rate_type', ['per_package', 'per_day', 'per_hour'])->default('per_package');
            $table->decimal('extra_km_rate', 10, 2)->nullable()->comment('Optional per km rate when exceeding package allowance');
            $table->boolean('is_active')->default(true);
            $table->uuid('created_user_id')->nullable();
            $table->uuid('updated_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['service_package_id', 'vehicle_group_id'], 'unique_package_group_rate');
            $table->index(['vehicle_group_id', 'is_active']);
        });
    }

    /**
    * Reverse the migrations.
    */
    public function down(): void
    {
        Schema::dropIfExists('service_package_rates');
    }
};
