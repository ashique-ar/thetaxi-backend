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
         Schema::dropIfExists('service_package_rates');
        Schema::create('service_package_rates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('service_package_id');
            $table->uuid('vehicle_group_id');
            $table->decimal('base_rate', 10, 2);
            $table->enum('rate_type', ['flat', 'hourly', 'daily'])->default('flat');
            $table->decimal('extra_km_rate', 8, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->uuid('created_user_id')->nullable();
            $table->uuid('updated_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('service_package_id')->references('id')->on('service_packages')->onDelete('cascade');
            $table->foreign('vehicle_group_id')->references('id')->on('vehicle_groups')->onDelete('cascade');
            $table->index(['service_package_id', 'vehicle_group_id', 'is_active'], 'service_package_rates_composite');
            $table->unique(['service_package_id', 'vehicle_group_id'], 'service_package_vehicle_group_unique');
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
