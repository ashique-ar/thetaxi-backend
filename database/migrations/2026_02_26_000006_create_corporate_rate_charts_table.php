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
        Schema::create('corporate_rate_charts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('corporate_id');
            $table->string('name', 255);
            $table->uuid('vehicle_group_id')->nullable();
            $table->uuid('service_type_id')->nullable();
            $table->decimal('per_km_rate', 10, 2)->nullable();
            $table->decimal('per_hour_rate', 10, 2)->nullable();
            $table->json('fixed_route_pricing')->nullable();
            $table->boolean('is_active')->default(true);
            $table->uuid('created_user_id')->nullable();
            $table->uuid('updated_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('corporate_rate_charts');
    }
};
